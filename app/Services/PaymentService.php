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
use App\Models\ActivationPeriod;
use App\Models\Debt;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\MoneyAccount;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentLine;
use App\Models\PaymentMethodType;
use App\WhatsApp\Notifier;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
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
        private ActivationDueService $dues,
    ) {}

    /**
     * Records a receipt. Debt payments go to the chosen debts in order, else oldest first; a cash
     * activation («تفعيل نقدي») first pays any open debt (primary or secondary, oldest first). Anything
     * left over is the cash price of an activation (`payments.activation_amount`, credited to
     * RENEWALS_CLEARING), never advance credit: no receipt creates credit any more, except the old
     * `Advance` type kept for the balances that already exist. A 7-day debt paid in full completes its
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

            $plan = $this->allocationPlan($account, $amount, $type === PaymentType::DebtPayment ? $debtIds : null);
            $allocated = array_sum(array_column($plan, 'amount'));
            // What is left after the debts is the cash price of an activation («تفعيل نقدي»), never advance
            // credit: the subscriber is activated on the company site as soon as the money is taken. Only an
            // old-style advance (no screen records one any more) is still kept as credit.
            $credit = $type === PaymentType::Advance ? $amount - $allocated : 0;
            $activation = $amount - $allocated - $credit;
            if ($activation > 0) {
                $payment->update(['activation_amount' => $activation]);
            }

            $tag = ['subscriber_id' => $account->subscriber_id, 'account_id' => $account->id];
            $entries = [];
            foreach ($lines as $line) {
                $entries[] = ['account' => $line['money_account']->ledger_account_id, 'debit' => $line['amount'], ...$tag];
            }
            foreach ($plan as $row) {
                $entries[] = ['account' => $this->activations->arCode($row['debt']->bucket), 'credit' => $row['amount'], ...$tag, 'debt_id' => $row['debt']->id];
            }
            $entries[] = ['account' => Ledger::CUSTOMER_CREDIT, 'credit' => $credit, ...$tag];
            // The same account a renewal's debt is charged against: an activation paid at once.
            $entries[] = ['account' => Ledger::RENEWALS_CLEARING, 'credit' => $activation, ...$tag];
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
                'allocations' => array_map(fn ($r) => [$r['debt']->number => $r['amount']], $plan), 'credit' => $credit, 'activation' => $activation,
            ], subscriberId: $account->subscriber_id);

            // The subscriber is told only once the payment is committed; a rollback sends nothing.
            DB::afterCommit(fn () => app(Notifier::class)->payment($payment->fresh()));

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
     * Accounts that still hold old advance credit: account id => amount, largest first.
     *
     * @return Collection<int, int>
     */
    public function creditAccounts(): Collection
    {
        return LedgerEntry::where('ledger_account_id', $this->ledger->accountId(Ledger::CUSTOMER_CREDIT))
            ->whereNotNull('account_id')->groupBy('account_id')
            ->selectRaw('account_id, sum(credit - debit) as credit')->havingRaw('sum(credit - debit) > 0')
            ->orderByDesc('credit')->pluck('credit', 'account_id')->map(fn ($v) => (int) $v);
    }

    /**
     * «حذف الرصيد المقدم»: the old advance credit of an account becomes what it really was, the cash
     * price of an activation already given. One posting moves it from CUSTOMER_CREDIT to
     * RENEWALS_CLEARING (where a cash activation is credited); no box, custody, debt or receipt amount
     * changes, and no debt is paid from it (a debt stays owed). The receipts that carried the
     * credit remember it as their activation part, so a later void still balances. Admin only.
     */
    public function clearCredit(Account $account, ?string $reason = null): int
    {
        // A signed-in user must be the admin; the server command (no user) is the owner's own.
        if (Auth::user() !== null && ! Auth::user()->isAdmin()) {
            throw new AuthorizationException('حذف الرصيد المقدم للمدير فقط.');
        }

        return DB::transaction(function () use ($account, $reason) {
            $account = Account::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $credit = $this->creditBalance($account);
            if ($credit <= 0) {
                return 0;
            }
            $tag = ['subscriber_id' => $account->subscriber_id, 'account_id' => $account->id];
            $this->ledger->post('credit_cleared', CarbonImmutable::now(), [
                ['account' => Ledger::CUSTOMER_CREDIT, 'debit' => $credit, ...$tag],
                ['account' => Ledger::RENEWALS_CLEARING, 'credit' => $credit, ...$tag],
            ], $account, 'حذف الرصيد المقدم (تحويله إلى تفعيل نقدي)', $account->branch_id);

            $left = $credit;
            foreach ($this->paymentsWithUnusedCredit($account) as [$payment, $unused]) {
                $take = min($left, $unused);
                $payment->update(['activation_amount' => (int) $payment->activation_amount + $take]);
                $left -= $take;
                if ($left === 0) {
                    break;
                }
            }
            $this->audit->log('credit.cleared', $account, ['credit' => $credit], ['credit' => 0, 'activation' => $credit], $reason, $account->subscriber_id);

            return $credit;
        });
    }

    /**
     * The credit applications (old advance credit paying a debt) still standing, oldest first:
     * transactions of type `credit_application` on that debt with no reversal.
     *
     * @return Collection<int, FinancialTransaction>
     */
    public function creditApplications(Debt $debt): Collection
    {
        return FinancialTransaction::where('txn_type', 'credit_application')
            ->where('source_type', $debt->getMorphClass())->where('source_id', $debt->id)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('financial_transactions as r')->whereColumn('r.reverses_txn_id', 'financial_transactions.id'))
            ->orderBy('id')->get();
    }

    /**
     * «إرجاع الدين»: a debt that old advance credit paid without the owner wanting it is owed again.
     * Each credit application is reversed (a reversal posting, nothing edited), its allocations are
     * marked reversed and the debt reopens by as much; the credit that comes back is then cleared into
     * a cash activation (`clearCredit`). Refused when that payment completed a short activation.
     * Admin only. Returns the amount owed again.
     */
    public function undoCreditApplications(Debt $debt, ?string $reason = null): int
    {
        if (Auth::user() !== null && ! Auth::user()->isAdmin()) {
            throw new AuthorizationException('إرجاع الدين للمدير فقط.');
        }

        return DB::transaction(function () use ($debt, $reason) {
            $debt = Debt::whereKey($debt->id)->lockForUpdate()->firstOrFail();
            $txns = $this->creditApplications($debt);
            if ($txns->isEmpty()) {
                return 0;
            }
            if (ActivationPeriod::where('source_type', 'credit')->whereIn('source_id', $txns->pluck('id'))->exists()) {
                throw new BusinessRuleException("تسديد الدين {$debt->number} من الرصيد أكمل تفعيلاً. لا يُرجع تلقائياً.");
            }
            $now = CarbonImmutable::now();
            $owed = 0;
            foreach ($txns as $txn) {
                $allocations = PaymentAllocation::where('txn_id', $txn->id)->where('debt_id', $debt->id)->where('is_reversed', false)->get();
                $amount = (int) $allocations->sum('amount');
                $this->ledger->reverse($txn, $now, "إرجاع الدين {$debt->number}: إلغاء تسديده من الرصيد المقدم");
                foreach ($allocations as $allocation) {
                    $allocation->update(['is_reversed' => true]);
                }
                if ($amount > 0) {
                    $this->applyToDebt($debt, -$amount);
                    $debt->refresh();
                }
                $owed += $amount;
            }
            $this->audit->log('credit.unapplied', $debt, ['paid' => $owed], [
                'number' => $debt->number, 'amount' => $owed, 'balance' => $debt->balance,
            ], $reason, $debt->subscriber_id);
            $this->clearCredit(Account::findOrFail($debt->account_id), 'إرجاع الدين '.$debt->number);

            return $owed;
        });
    }

    /**
     * «تصحيح الصندوق»: the money of one line of a receipt went to the wrong box (e.g. an employee's
     * custody instead of the Super Key card) or was entered with the wrong method. The ledger gets a
     * correcting posting (out of the old box, into the new one; nothing is edited), the receipt line
     * shows the new box and method, and the change is kept in `payment_box_changes`. Debts, credit
     * and the subscriber are untouched; a later void reverses from the new box.
     */
    public function changeBox(Payment $payment, PaymentLine $line, MoneyAccount $to, ?PaymentMethodType $method, string $reason): Payment
    {
        if (! Auth::user()?->can('payments.change_box')) {
            throw new AuthorizationException('لا تملك صلاحية تصحيح صندوق السند.');
        }
        if (trim($reason) === '') {
            throw new BusinessRuleException('اكتب سبب التصحيح.');
        }

        return DB::transaction(function () use ($payment, $line, $to, $method, $reason) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $line = PaymentLine::whereKey($line->id)->where('payment_id', $payment->id)->lockForUpdate()->first()
                ?? throw new BusinessRuleException('هذا السطر لا يخص السند.');
            if ($payment->status !== DocumentStatus::Posted) {
                throw new BusinessRuleException('لا يُصحَّح سند ملغى.');
            }
            if (! $to->is_active) {
                throw new BusinessRuleException('الصندوق المختار غير فعّال.');
            }
            $from = MoneyAccount::findOrFail($line->money_account_id);
            $method ??= $line->method;
            if ($from->id === $to->id && $method->id === $line->payment_method_id) {
                throw new BusinessRuleException('لم يتغيّر الصندوق ولا طريقة الدفع.');
            }

            $txn = null;
            if ($from->id !== $to->id) {
                $tag = ['subscriber_id' => $payment->subscriber_id, 'account_id' => $payment->account_id];
                $txn = $this->ledger->post('payment_box_change', CarbonImmutable::now(), [
                    ['account' => $to->ledger_account_id, 'debit' => $line->amount, ...$tag],
                    ['account' => $from->ledger_account_id, 'credit' => $line->amount, ...$tag],
                ], $payment, "تصحيح صندوق السند {$payment->receipt_number}: من {$from->name} إلى {$to->name}", $payment->branch_id);
            }
            DB::table('payment_box_changes')->insert([
                'payment_id' => $payment->id, 'payment_line_id' => $line->id,
                'from_money_account_id' => $from->id, 'to_money_account_id' => $to->id,
                'from_method_id' => $line->payment_method_id, 'to_method_id' => $method->id,
                'amount' => $line->amount, 'txn_id' => $txn?->id, 'reason' => mb_substr($reason, 0, 300),
                'created_by' => Auth::id(), 'created_at' => now(),
            ]);
            $oldMethod = $line->method->name_ar;
            $line->update(['money_account_id' => $to->id, 'payment_method_id' => $method->id]);

            // The receipt's own summary follows its lines.
            $lines = $payment->lines()->with('method')->orderBy('id')->get();
            $categories = $lines->map(fn (PaymentLine $l) => $l->method->category)->unique(fn ($c) => $c->value)->values();
            $payment->update([
                'money_account_id' => $lines->first()->money_account_id,
                'method' => $categories->count() === 1 ? $categories->first() : PaymentMethod::Mixed,
            ]);

            $this->audit->log('payment.box_changed', $payment, ['money_account' => $from->name, 'method' => $oldMethod],
                ['money_account' => $to->name, 'method' => $method->name_ar, 'amount' => $line->amount], $reason, $payment->subscriber_id);

            return $payment->fresh();
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
            // The part recorded as a cash activation goes back where it was credited; only the rest was credit.
            $activation = (int) $payment->activation_amount;
            $credit = $payment->amount - $allocated - $activation;
            $lines[] = ['account' => Ledger::RENEWALS_CLEARING, 'debit' => $activation, ...$tag];
            $lines[] = ['account' => Ledger::CUSTOMER_CREDIT, 'debit' => $credit, ...$tag];

            if ($credit > $this->creditBalance($payment->account)) {
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
            // The part recorded as a cash activation is spent: it is not credit.
            ->map(fn (Payment $p) => [$p, $p->amount - (int) $p->activation_amount - (int) $p->allocations()->where('is_reversed', false)->sum('amount')])
            ->filter(fn (array $row) => $row[1] > 0)
            ->values();
    }

    private function applyToDebt(Debt $debt, int $amount): void
    {
        $before = $debt->status;
        $paid = $debt->paid_amount + $amount;
        $debt->update([
            'paid_amount' => $paid,
            'balance' => $debt->original_amount - $paid,
            'status' => DebtService::statusFor($debt->original_amount, $paid),
        ]);
        $this->dues->debtChanged($debt, $before);
    }
}
