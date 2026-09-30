<?php

namespace App\Services;

use App\Enums\CompletedVia;
use App\Enums\CompletionStatus;
use App\Enums\DebtBucket;
use App\Enums\DebtStatus;
use App\Enums\DocumentStatus;
use App\Enums\MoneyAccountKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use App\Exceptions\BusinessRuleException;
use App\Models\Account;
use App\Models\Debt;
use App\Models\MoneyAccount;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentLine;
use App\Models\PaymentMethodType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function __construct(
        private Ledger $ledger,
        private Sequencer $sequencer,
        private Audit $audit,
        private ActivationService $activations,
        private DebtService $debts,
    ) {
    }

    /**
     * Records a receipt. Debt payments go to the chosen debts in order, else oldest first; anything
     * left over becomes advance credit on the account. A 7-day debt paid in full completes its
     * activation; with $transferRemainder, a 7-day debt left partly unpaid moves to primary debts.
     *
     * The money can arrive through several payment methods at once ($lines); the single-method
     * parameters ($amount, $method, $moneyAccount…) remain as a shortcut for one line.
     *
     * @param  array<int, int>|null  $debtIds
     * @param  array<int, array{method: PaymentMethodType|int, money_account?: MoneyAccount|int|null, amount: int, receiver?: ?string, reference?: ?string}>|null  $lines
     */
    public function record(
        Account $account,
        int $amount = 0,
        ?PaymentMethod $method = null,
        ?MoneyAccount $moneyAccount = null,
        PaymentType $type = PaymentType::DebtPayment,
        ?string $receiverName = null,
        ?string $reference = null,
        ?array $debtIds = null,
        ?CarbonImmutable $receivedAt = null,
        ?string $notes = null,
        bool $transferRemainder = false,
        ?string $idempotencyKey = null,
        ?array $lines = null,
    ): Payment {
        if ($idempotencyKey !== null && ($existing = Payment::where('idempotency_key', $idempotencyKey)->first())) {
            return $existing;
        }

        $lines = $this->normalizeLines($lines ?? [[
            'method' => $this->defaultMethod($method ?? PaymentMethod::Cash, $moneyAccount),
            'money_account' => $moneyAccount,
            'amount' => $amount,
            'receiver' => $receiverName,
            'reference' => $reference,
        ]]);
        $total = array_sum(array_column($lines, 'amount'));
        if ($amount > 0 && $amount !== $total) {
            throw new BusinessRuleException('مجموع طرق الدفع لا يساوي المبلغ.');
        }
        $amount = $total;

        return DB::transaction(function () use ($account, $amount, $lines, $type, $debtIds, $receivedAt, $notes, $transferRemainder, $idempotencyKey) {
            $now = CarbonImmutable::now();
            $receivedAt ??= $now;
            $account = Account::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $categories = array_unique(array_map(fn ($l) => $l['method']->category, $lines), SORT_REGULAR);

            $payment = Payment::create([
                'receipt_number' => $this->sequencer->next('receipt', $receivedAt),
                'subscriber_id' => $account->subscriber_id,
                'account_id' => $account->id,
                'branch_id' => $account->branch_id,
                'payment_type' => $type,
                'method' => count($categories) === 1 ? $categories[0] : PaymentMethod::Mixed,
                'money_account_id' => $lines[0]['money_account']->id,
                'receiver_name' => collect($lines)->pluck('receiver')->filter()->first(),
                'external_reference' => collect($lines)->pluck('reference')->filter()->first(),
                'amount' => $amount,
                'received_at' => $receivedAt,
                'status' => DocumentStatus::Posted,
                'idempotency_key' => $idempotencyKey,
                'notes' => $notes,
                'created_by' => Auth::id(),
            ]);
            foreach ($lines as $line) {
                PaymentLine::create([
                    'payment_id' => $payment->id,
                    'payment_method_id' => $line['method']->id,
                    'money_account_id' => $line['money_account']->id,
                    'amount' => $line['amount'],
                    'receiver_name' => $line['receiver'],
                    'reference' => $line['reference'],
                ]);
            }

            $plan = $type === PaymentType::DebtPayment ? $this->allocationPlan($account, $amount, $debtIds) : [];
            $allocated = array_sum(array_column($plan, 'amount'));
            $credit = $amount - $allocated;

            $tag = ['subscriber_id' => $account->subscriber_id, 'account_id' => $account->id];
            $entries = [];
            foreach ($lines as $line) {
                $entries[] = ['account' => $line['money_account']->ledger_account_id, 'debit' => $line['amount'], ...$tag];
            }
            foreach ($plan as $row) {
                $entries[] = ['account' => $this->activations->arCode($row['debt']->bucket), 'credit' => $row['amount'], ...$tag, 'debt_id' => $row['debt']->id];
            }
            $entries[] = ['account' => Ledger::CUSTOMER_CREDIT, 'credit' => $credit, ...$tag];
            $txn = $this->ledger->post('payment', $receivedAt, $entries, $payment, "سند قبض {$payment->receipt_number}", $account->branch_id);
            $payment->update(['txn_id' => $txn->id]);

            foreach ($plan as $row) {
                PaymentAllocation::create([
                    'payment_id' => $payment->id, 'debt_id' => $row['debt']->id, 'amount' => $row['amount'],
                    'txn_id' => $txn->id, 'created_by' => Auth::id(),
                ]);
                $this->applyToDebt($row['debt'], $row['amount']);
            }

            $this->audit->log('payment.created', $payment, null, [
                'receipt' => $payment->receipt_number, 'amount' => $amount, 'type' => $type->value,
                'methods' => array_map(fn ($l) => [$l['method']->name_ar => $l['amount']], $lines),
                'allocations' => array_map(fn ($r) => [$r['debt']->number => $r['amount']], $plan), 'credit' => $credit,
            ], subscriberId: $account->subscriber_id);

            foreach ($plan as $row) {
                $debt = $row['debt']->fresh();
                $activation = $debt->activation;
                if ($activation?->completion_status !== CompletionStatus::Pending) {
                    continue;
                }
                if ($debt->status === DebtStatus::Paid) {
                    $this->activations->complete($activation, CompletedVia::Payment, $receivedAt, 'payment', $payment->id);
                } elseif ($transferRemainder && $debt->bucket === DebtBucket::Secondary) {
                    $this->debts->transfer($debt, "قبض جزئي بالسند {$payment->receipt_number} ونقل الباقي", $receivedAt);
                }
            }

            return $payment->fresh();
        });
    }

    /**
     * The active method used when a caller gives only a category (cash, electronic…). For
     * electronic money, the method attached to the chosen wallet wins.
     */
    public function defaultMethod(PaymentMethod $category, ?MoneyAccount $box = null): PaymentMethodType
    {
        $query = PaymentMethodType::where('is_active', true)->where('category', $category)->orderBy('sort_order');
        if ($box !== null && ($linked = (clone $query)->where('money_account_id', $box->id)->first())) {
            return $linked;
        }

        return $query->first() ?? throw new BusinessRuleException("لا توجد طريقة دفع فعّالة من نوع «{$category->getLabel()}». أضفها من «طرق الدفع».");
    }

    /**
     * @return array<int, array{method: PaymentMethodType, money_account: MoneyAccount, amount: int, receiver: ?string, reference: ?string}>
     */
    private function normalizeLines(array $lines): array
    {
        $lines = array_values(array_filter($lines, fn ($l) => (int) ($l['amount'] ?? 0) !== 0 || count($lines) === 1));
        if ($lines === []) {
            throw new BusinessRuleException('أضف طريقة دفع واحدة على الأقل.');
        }

        return array_map(function (array $line) {
            $method = $line['method'] instanceof PaymentMethodType ? $line['method'] : PaymentMethodType::find($line['method']);
            if (! $method || ! $method->is_active) {
                throw new BusinessRuleException('طريقة الدفع غير موجودة أو موقوفة.');
            }
            $box = $line['money_account'] ?? null;
            $box = $box instanceof MoneyAccount ? $box : ($box ? MoneyAccount::find($box) : $method->moneyAccount);
            if (! $box) {
                throw new BusinessRuleException("اختر الصندوق أو المحفظة لطريقة «{$method->name_ar}».");
            }
            if ($box->kind === MoneyAccountKind::Company) {
                throw new BusinessRuleException('لا يُستلم القبض في رصيد الشركة. اختر القاصة أو محفظة.');
            }
            $amount = (int) ($line['amount'] ?? 0);
            if ($amount <= 0) {
                throw new BusinessRuleException('المبلغ يجب أن يكون أكبر من صفر.');
            }
            $receiver = filled($line['receiver'] ?? null) ? trim($line['receiver']) : null;
            $reference = filled($line['reference'] ?? null) ? trim($line['reference']) : null;
            if ($method->requires_receiver && ! $receiver) {
                throw new BusinessRuleException("اكتب اسم المستلم لطريقة «{$method->name_ar}».");
            }
            if ($method->requires_reference && ! $reference) {
                throw new BusinessRuleException("اكتب رقم العملية لطريقة «{$method->name_ar}».");
            }

            return ['method' => $method, 'money_account' => $box, 'amount' => $amount, 'receiver' => $receiver, 'reference' => $reference];
        }, $lines);
    }

    /**
     * Shows how an amount would be spread, without saving anything.
     *
     * @return array<int, array{debt: Debt, amount: int}>
     */
    public function allocationPlan(Account $account, int $amount, ?array $debtIds = null): array
    {
        $open = Debt::where('account_id', $account->id)->whereIn('status', [DebtStatus::Open, DebtStatus::Partial])->where('balance', '>', 0);
        if ($debtIds) {
            $debts = $open->whereIn('id', $debtIds)->get()->sortBy(fn (Debt $d) => array_search($d->id, $debtIds))->values();
            if ($debts->count() !== count(array_unique($debtIds))) {
                throw new BusinessRuleException('أحد الديون المختارة غير مفتوح أو لا يخص هذا الحساب.');
            }
        } else {
            $debts = $open->orderBy('debt_date')->orderBy('id')->get();
        }

        $plan = [];
        $left = $amount;
        foreach ($debts as $debt) {
            if ($left <= 0) {
                break;
            }
            $take = min($left, $debt->balance);
            $plan[] = ['debt' => $debt, 'amount' => $take];
            $left -= $take;
        }

        return $plan;
    }

    /**
     * Uses the account's advance credit to pay a debt, oldest advance first.
     */
    public function applyCredit(Account $account, Debt $debt, ?int $amount = null): int
    {
        return DB::transaction(function () use ($account, $debt, $amount) {
            $now = CarbonImmutable::now();
            $debt = Debt::whereKey($debt->id)->lockForUpdate()->firstOrFail();
            $available = $this->creditBalance($account);
            $amount = min($amount ?? PHP_INT_MAX, $available, $debt->balance);
            if ($amount <= 0) {
                return 0;
            }

            $tag = ['subscriber_id' => $account->subscriber_id, 'account_id' => $account->id];
            $txn = $this->ledger->post('credit_application', $now, [
                ['account' => Ledger::CUSTOMER_CREDIT, 'debit' => $amount, ...$tag],
                ['account' => $this->activations->arCode($debt->bucket), 'credit' => $amount, ...$tag, 'debt_id' => $debt->id],
            ], $debt, "استخدام الرصيد المقدم للدين {$debt->number}", $account->branch_id);

            $left = $amount;
            foreach ($this->paymentsWithUnusedCredit($account) as [$payment, $unused]) {
                $take = min($left, $unused);
                PaymentAllocation::create(['payment_id' => $payment->id, 'debt_id' => $debt->id, 'amount' => $take, 'txn_id' => $txn->id, 'created_by' => Auth::id()]);
                $left -= $take;
                if ($left === 0) {
                    break;
                }
            }
            $this->applyToDebt($debt, $amount);

            $this->audit->log('credit.applied', $debt, null, ['amount' => $amount], subscriberId: $account->subscriber_id);

            $debt->refresh();
            if ($debt->status === DebtStatus::Paid && $debt->activation?->completion_status === CompletionStatus::Pending) {
                $this->activations->complete($debt->activation, CompletedVia::Payment, $now, 'credit', $txn->id);
            }

            return $amount;
        });
    }

    /**
     * Voids a receipt with a reverse posting. The debts it paid open again in whatever bucket they
     * are now in; days already added to an activation stay, because they were given on the company site.
     */
    public function void(Payment $payment, string $reason): Payment
    {
        return DB::transaction(function () use ($payment, $reason) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->status === DocumentStatus::Voided) {
                throw new BusinessRuleException('السند ملغى مسبقاً.');
            }

            $allocations = $payment->allocations()->where('is_reversed', false)->with('debt')->get();
            if ($allocations->contains(fn (PaymentAllocation $a) => $a->txn_id !== $payment->txn_id)) {
                throw new BusinessRuleException('استُخدم الرصيد المقدم من هذا السند في ديون لاحقة. لا يمكن إلغاؤه.');
            }

            $now = CarbonImmutable::now();
            $tag = ['subscriber_id' => $payment->subscriber_id, 'account_id' => $payment->account_id];
            $allocated = (int) $allocations->sum('amount');
            $paid = $payment->lines()->with('moneyAccount')->get();
            $lines = $paid->isEmpty()
                ? [['account' => $payment->moneyAccount->ledger_account_id, 'credit' => $payment->amount, ...$tag]]
                : $paid->map(fn (PaymentLine $l) => ['account' => $l->moneyAccount->ledger_account_id, 'credit' => $l->amount, ...$tag])->all();
            foreach ($allocations as $allocation) {
                $lines[] = ['account' => $this->activations->arCode($allocation->debt->bucket), 'debit' => $allocation->amount, ...$tag, 'debt_id' => $allocation->debt_id];
            }
            $lines[] = ['account' => Ledger::CUSTOMER_CREDIT, 'debit' => $payment->amount - $allocated, ...$tag];

            if ($payment->amount - $allocated > $this->creditBalance($payment->account)) {
                throw new BusinessRuleException('الرصيد المقدم من هذا السند مستخدم. لا يمكن إلغاؤه.');
            }

            $this->ledger->post('reversal', $now, $lines, $payment, "إلغاء السند {$payment->receipt_number}", $payment->branch_id, $payment->txn_id);

            foreach ($allocations as $allocation) {
                $allocation->update(['is_reversed' => true]);
                $this->applyToDebt($allocation->debt, -$allocation->amount);
            }
            $payment->update(['status' => DocumentStatus::Voided, 'voided_at' => $now, 'voided_by' => Auth::id(), 'void_reason' => $reason]);

            $this->audit->log('payment.voided', $payment, null, ['receipt' => $payment->receipt_number, 'amount' => $payment->amount], $reason, $payment->subscriber_id);

            return $payment;
        });
    }

    public function creditBalance(Account $account): int
    {
        // CUSTOMER_CREDIT is a liability: credits increase it.
        return -$this->ledger->balance(Ledger::CUSTOMER_CREDIT, $account->id);
    }

    /**
     * @return Collection<int, array{0: Payment, 1: int}>
     */
    private function paymentsWithUnusedCredit(Account $account): Collection
    {
        return Payment::where('account_id', $account->id)->where('status', DocumentStatus::Posted)->orderBy('received_at')->get()
            ->map(fn (Payment $p) => [$p, $p->amount - (int) $p->allocations()->where('is_reversed', false)->sum('amount')])
            ->filter(fn (array $row) => $row[1] > 0)
            ->values();
    }

    private function applyToDebt(Debt $debt, int $amount): void
    {
        $paid = $debt->paid_amount + $amount;
        $debt->update([
            'paid_amount' => $paid,
            'balance' => $debt->original_amount - $paid,
            'status' => DebtService::statusFor($debt->original_amount, $paid),
        ]);
    }
}
