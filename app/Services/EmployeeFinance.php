<?php

namespace App\Services;

use App\Enums\AdvanceStatus;
use App\Enums\DocumentStatus;
use App\Enums\MoneyAccountKind;
use App\Exceptions\BusinessRuleException;
use App\Models\CustodyHandoverRequest;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceRepayment;
use App\Models\FundTransfer;
use App\Models\LedgerEntry;
use App\Models\MoneyAccount;
use App\Models\Payment;
use App\Models\PaymentLine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Each employee's money, kept as separate movements that never mix:
 *  - custody (عهدة): cash the employee collected and still holds; it lives in their own custody box;
 *  - handover: custody paid into a company box, which lowers the custody and raises the box;
 *  - advances (سلف) and their repayments, a receivable from the employee.
 */
class EmployeeFinance
{
    public function __construct(
        private Ledger $ledger,
        private Sequencer $sequencer,
        private Audit $audit,
        private TreasuryService $treasury,
    ) {
    }

    public function custodyAccount(User $user): MoneyAccount
    {
        return MoneyAccount::where('kind', MoneyAccountKind::Custody)->where('user_id', $user->id)->first()
            ?? $this->treasury->createMoneyAccount($user->branch_id ?? MoneyAccount::value('branch_id'), MoneyAccountKind::Custody, "عهدة {$user->name}", $user->name, $user->id);
    }

    public function custodyBalance(User $user): int
    {
        $box = MoneyAccount::where('kind', MoneyAccountKind::Custody)->where('user_id', $user->id)->first();

        return $box ? $this->treasury->balance($box) : 0;
    }

    /**
     * «تسديد عهدة الموظف للصندوق»: the employee hands collected cash to the manager.
     */
    public function handOver(User $employee, int $amount, MoneyAccount $into, ?string $notes = null): FundTransfer
    {
        if ($into->kind === MoneyAccountKind::Custody) {
            throw new BusinessRuleException('اختر صندوق الشركة الذي استلم المبلغ، لا عهدة موظف.');
        }
        $custody = $this->custodyAccount($employee);

        return DB::transaction(function () use ($employee, $amount, $into, $notes, $custody) {
            DB::table('money_accounts')->where('id', $custody->id)->lockForUpdate()->first();
            $before = $this->treasury->balance($custody);
            if ($amount <= 0) {
                throw new BusinessRuleException('المبلغ يجب أن يكون أكبر من صفر.');
            }
            if ($amount > $before) {
                throw new BusinessRuleException('المبلغ أكبر من عهدة الموظف الحالية ('.number_format($before).' د.ع).');
            }

            $transfer = $this->treasury->transfer($custody, $into, $amount, null, $notes ?? "تسديد عهدة {$employee->name} للصندوق");
            $this->audit->log('custody.settled', $transfer, ['custody' => $before, 'employee' => $employee->name], [
                'custody' => $before - $amount, 'amount' => $amount, 'into' => $into->name, 'confirmed_by' => Auth::user()?->name,
            ], $notes);

            return $transfer;
        });
    }

    /**
     * The employee says they handed their custody over. Nothing moves until the admin confirms.
     */
    public function requestHandover(User $employee, int $amount, ?string $notes = null): CustodyHandoverRequest
    {
        return DB::transaction(function () use ($employee, $amount, $notes) {
            $custody = $this->custodyBalance($employee);
            if ($amount <= 0) {
                throw new BusinessRuleException('المبلغ يجب أن يكون أكبر من صفر.');
            }
            if ($amount > $custody) {
                throw new BusinessRuleException('المبلغ أكبر من عهدتك الحالية ('.number_format($custody).' د.ع).');
            }
            if (CustodyHandoverRequest::where('user_id', $employee->id)->where('status', 'pending')->lockForUpdate()->exists()) {
                throw new BusinessRuleException('لديك طلب تسليم بانتظار تأكيد المدير.');
            }
            $request = CustodyHandoverRequest::create([
                'user_id' => $employee->id, 'amount' => $amount, 'notes' => $notes, 'status' => 'pending', 'requested_at' => now(),
            ]);
            $this->audit->log('custody.handover_requested', $request, null, ['employee' => $employee->name, 'amount' => $amount, 'custody' => $custody], $notes);

            return $request;
        });
    }

    /**
     * The admin confirms receiving the money: it moves from the custody into the chosen box.
     */
    public function approveHandover(CustodyHandoverRequest $request, MoneyAccount $into, ?int $amount = null): CustodyHandoverRequest
    {
        return DB::transaction(function () use ($request, $into, $amount) {
            $request = CustodyHandoverRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($request->status !== 'pending') {
                throw new BusinessRuleException('هذا الطلب معالج مسبقاً.');
            }
            $amount ??= $request->amount;
            $transfer = $this->handOver($request->user, $amount, $into, "تسليم عهدة {$request->user->name} (طلب رقم {$request->id})");
            $request->update([
                'status' => 'approved', 'resolved_by' => Auth::id(), 'resolved_at' => now(), 'fund_transfer_id' => $transfer->id,
                'resolution_note' => $amount !== $request->amount ? 'استُلم '.number_format($amount).' من '.number_format($request->amount) : null,
            ]);
            $this->audit->log('custody.handover_approved', $request, ['status' => 'pending'], ['status' => 'approved', 'amount' => $amount, 'into' => $into->name]);

            return $request;
        });
    }

    public function rejectHandover(CustodyHandoverRequest $request, string $reason): CustodyHandoverRequest
    {
        return DB::transaction(function () use ($request, $reason) {
            $request = CustodyHandoverRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($request->status !== 'pending') {
                throw new BusinessRuleException('هذا الطلب معالج مسبقاً.');
            }
            $request->update(['status' => 'rejected', 'resolved_by' => Auth::id(), 'resolved_at' => now(), 'resolution_note' => $reason]);
            $this->audit->log('custody.handover_rejected', $request, ['status' => 'pending'], ['status' => 'rejected'], $reason);

            return $request;
        });
    }

    public function giveAdvance(User $employee, int $amount, string $reason, MoneyAccount $from, ?string $details = null, ?string $notes = null, ?CarbonImmutable $at = null): EmployeeAdvance
    {
        if ($amount <= 0) {
            throw new BusinessRuleException('مبلغ السلفة يجب أن يكون أكبر من صفر.');
        }
        if (in_array($from->kind, [MoneyAccountKind::Company, MoneyAccountKind::Custody], true)) {
            throw new BusinessRuleException('تُصرف السلفة من القاصة أو محفظة.');
        }

        return DB::transaction(function () use ($employee, $amount, $reason, $from, $details, $notes, $at) {
            $at ??= CarbonImmutable::now();
            if (! Auth::user()?->can('cash.allow_negative')) {
                $this->treasury->ensureCovers($from, $amount);
            }
            $advance = EmployeeAdvance::create([
                'number' => $this->sequencer->next('advance', $at),
                'user_id' => $employee->id,
                'amount' => $amount,
                'paid_amount' => 0,
                'balance' => $amount,
                'advanced_at' => $at,
                'reason' => trim($reason),
                'details' => $details,
                'notes' => $notes,
                'status' => AdvanceStatus::Unpaid,
                'money_account_id' => $from->id,
                'created_by' => Auth::id(),
            ]);
            $txn = $this->ledger->post('employee_advance', $at, [
                ['account' => Ledger::EMPLOYEE_ADVANCES, 'debit' => $amount],
                ['account' => $from->ledger_account_id, 'credit' => $amount],
            ], $advance, "سلفة {$advance->number} – {$employee->name}", $from->branch_id);
            $advance->update(['txn_id' => $txn->id]);

            $this->audit->log('advance.created', $advance, null, [
                'number' => $advance->number, 'employee' => $employee->name, 'amount' => $amount, 'reason' => $advance->reason, 'from' => $from->name,
            ]);

            return $advance;
        });
    }

    public function repay(EmployeeAdvance $advance, int $amount, MoneyAccount $into, ?string $notes = null, ?CarbonImmutable $at = null): EmployeeAdvanceRepayment
    {
        if ($into->kind === MoneyAccountKind::Company) {
            throw new BusinessRuleException('يُستلم تسديد السلفة في القاصة أو محفظة.');
        }

        return DB::transaction(function () use ($advance, $amount, $into, $notes, $at) {
            $at ??= CarbonImmutable::now();
            $advance = EmployeeAdvance::whereKey($advance->id)->lockForUpdate()->firstOrFail();
            if ($amount <= 0) {
                throw new BusinessRuleException('مبلغ التسديد يجب أن يكون أكبر من صفر.');
            }
            if ($amount > $advance->balance) {
                throw new BusinessRuleException('المبلغ أكبر من المتبقي على السلفة ('.number_format($advance->balance).' د.ع).');
            }

            $repayment = EmployeeAdvanceRepayment::create([
                'number' => $this->sequencer->next('advance_repayment', $at),
                'advance_id' => $advance->id,
                'user_id' => $advance->user_id,
                'amount' => $amount,
                'paid_at' => $at,
                'money_account_id' => $into->id,
                'notes' => $notes,
                'created_by' => Auth::id(),
            ]);
            $txn = $this->ledger->post('advance_repayment', $at, [
                ['account' => $into->ledger_account_id, 'debit' => $amount],
                ['account' => Ledger::EMPLOYEE_ADVANCES, 'credit' => $amount],
            ], $repayment, "تسديد السلفة {$advance->number}", $into->branch_id);
            $repayment->update(['txn_id' => $txn->id]);

            $old = ['paid_amount' => $advance->paid_amount, 'balance' => $advance->balance, 'status' => $advance->status->value];
            $paid = $advance->paid_amount + $amount;
            $advance->update(['paid_amount' => $paid, 'balance' => $advance->amount - $paid, 'status' => AdvanceStatus::for($advance->amount, $paid)]);
            $this->audit->log('advance.repaid', $advance, $old, [
                'paid_amount' => $paid, 'balance' => $advance->balance, 'status' => $advance->status->value, 'repayment' => $repayment->number, 'amount' => $amount, 'into' => $into->name,
            ], $notes);

            return $repayment;
        });
    }

    /**
     * Totals for one employee. Custody and advances are kept apart.
     *
     * @return array{collections: int, handed_over: int, custody: int, advances: int, repaid: int, advances_remaining: int}
     */
    public function summary(User $employee, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $box = MoneyAccount::where('kind', MoneyAccountKind::Custody)->where('user_id', $employee->id)->first();
        $range = fn ($query, string $column) => $query
            ->when($from, fn ($q) => $q->where($column, '>=', $from->startOfDay()))
            ->when($to, fn ($q) => $q->where($column, '<=', $to->endOfDay()));

        $collections = $box ? (int) $range(PaymentLine::query()
            ->join('payments', 'payments.id', '=', 'payment_lines.payment_id')
            ->where('payment_lines.money_account_id', $box->id)
            ->where('payments.status', DocumentStatus::Posted), 'payments.received_at')->sum('payment_lines.amount') : 0;
        $handedOver = $box ? (int) $range(FundTransfer::where('from_money_account_id', $box->id)->where('status', DocumentStatus::Posted), 'transferred_at')->sum('amount') : 0;
        $advances = (int) $range(EmployeeAdvance::where('user_id', $employee->id), 'advanced_at')->sum('amount');
        $repaid = (int) $range(EmployeeAdvanceRepayment::where('user_id', $employee->id), 'paid_at')->sum('amount');

        return [
            'collections' => $collections,
            'handed_over' => $handedOver,
            'custody' => $box ? $this->treasury->balance($box) : 0,
            'advances' => $advances,
            'repaid' => $repaid,
            'advances_remaining' => (int) EmployeeAdvance::where('user_id', $employee->id)->sum('balance'),
        ];
    }

    /**
     * Every movement of one employee, newest first: custody in and out (from the ledger, so voids
     * appear too), advances and their repayments.
     *
     * @return Collection<int, array{at: CarbonImmutable, kind: string, label: string, details: string, custody: int, advance: int, by: ?string, reference: ?string}>
     */
    public function statement(User $employee, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): Collection
    {
        $rows = collect();
        $box = MoneyAccount::where('kind', MoneyAccountKind::Custody)->where('user_id', $employee->id)->first();

        if ($box) {
            $entries = LedgerEntry::query()
                ->join('financial_transactions as t', 't.id', '=', 'ledger_entries.txn_id')
                ->leftJoin('users as u', 'u.id', '=', 't.created_by')
                ->where('ledger_entries.ledger_account_id', $box->ledger_account_id)
                ->when($from, fn ($q) => $q->where('ledger_entries.occurred_at', '>=', $from->startOfDay()))
                ->when($to, fn ($q) => $q->where('ledger_entries.occurred_at', '<=', $to->endOfDay()))
                ->select('ledger_entries.debit', 'ledger_entries.credit', 'ledger_entries.occurred_at', 't.txn_type', 't.source_type', 't.source_id', 't.memo', 'u.name as by_name')
                ->orderByDesc('ledger_entries.occurred_at')->orderByDesc('ledger_entries.id')->limit(2000)->get();

            $payments = Payment::with('subscriber:id,full_name')->whereIn('id', $entries->where('source_type', (new Payment)->getMorphClass())->pluck('source_id'))->get()->keyBy('id');

            foreach ($entries as $e) {
                $amount = (int) $e->debit - (int) $e->credit;
                [$kind, $label] = match (true) {
                    $e->txn_type === 'payment' => ['collection', 'تحصيل من مشترك'],
                    $e->txn_type === 'fund_transfer' && $amount < 0 => ['handover', 'تسديد عهدة للصندوق'],
                    $e->txn_type === 'fund_transfer' => ['custody_in', 'تحويل إلى العهدة'],
                    $e->txn_type === 'reversal' => ['reversal', 'إلغاء عملية'],
                    default => ['custody_other', $e->memo ?? $e->txn_type],
                };
                $payment = $e->txn_type === 'payment' ? $payments->get($e->source_id) : null;
                $rows->push([
                    'at' => CarbonImmutable::parse($e->occurred_at),
                    'kind' => $kind,
                    'label' => $label,
                    'details' => $payment ? "سند {$payment->receipt_number} – {$payment->subscriber?->full_name}" : (string) $e->memo,
                    'custody' => $amount,
                    'advance' => 0,
                    'by' => $e->by_name,
                    'reference' => $payment?->receipt_number,
                ]);
            }
        }

        $advances = EmployeeAdvance::with(['creator:id,name', 'moneyAccount:id,name'])->where('user_id', $employee->id)
            ->when($from, fn ($q) => $q->where('advanced_at', '>=', $from->startOfDay()))
            ->when($to, fn ($q) => $q->where('advanced_at', '<=', $to->endOfDay()))->get();
        foreach ($advances as $a) {
            $rows->push([
                'at' => $a->advanced_at, 'kind' => 'advance', 'label' => 'سلفة',
                'details' => trim("{$a->number} – {$a->reason}".($a->details ? " ({$a->details})" : '')." · من {$a->moneyAccount?->name}"),
                'custody' => 0, 'advance' => $a->amount, 'by' => $a->creator?->name, 'reference' => $a->number,
            ]);
        }

        $repayments = EmployeeAdvanceRepayment::with(['creator:id,name', 'advance:id,number', 'moneyAccount:id,name'])->where('user_id', $employee->id)
            ->when($from, fn ($q) => $q->where('paid_at', '>=', $from->startOfDay()))
            ->when($to, fn ($q) => $q->where('paid_at', '<=', $to->endOfDay()))->get();
        foreach ($repayments as $r) {
            $rows->push([
                'at' => $r->paid_at, 'kind' => 'repayment', 'label' => 'تسديد سلفة',
                'details' => "{$r->number} عن السلفة {$r->advance?->number} · إلى {$r->moneyAccount?->name}".($r->notes ? " – {$r->notes}" : ''),
                'custody' => 0, 'advance' => -$r->amount, 'by' => $r->creator?->name, 'reference' => $r->number,
            ]);
        }

        // Running balances (الرصيد الناتج) after each movement, counted from everything before the period.
        $custody = $box && $from ? (int) LedgerEntry::where('ledger_account_id', $box->ledger_account_id)->where('occurred_at', '<', $from->startOfDay())->sum(DB::raw('debit - credit')) : 0;
        $advance = $from
            ? (int) EmployeeAdvance::where('user_id', $employee->id)->where('advanced_at', '<', $from->startOfDay())->sum('amount')
                - (int) EmployeeAdvanceRepayment::where('user_id', $employee->id)->where('paid_at', '<', $from->startOfDay())->sum('amount')
            : 0;
        $rows = $rows->sortByDesc(fn ($r) => $r['at']->getTimestamp())->reverse()->values()->map(function (array $r) use (&$custody, &$advance) {
            $custody += $r['custody'];
            $advance += $r['advance'];

            return $r + ['custody_balance' => $custody, 'advance_balance' => $advance];
        });

        return $rows->reverse()->values();
    }

    /**
     * The employee reports for a period: collections per employee, custody held now, handovers,
     * advances and repayments.
     */
    public function report(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $range = [$from->startOfDay(), $to->endOfDay()];
        $custodyBoxes = MoneyAccount::where('kind', MoneyAccountKind::Custody)->pluck('id');

        $collections = PaymentLine::query()
            ->join('payments', 'payments.id', '=', 'payment_lines.payment_id')
            ->join('users', 'users.id', '=', 'payments.created_by')
            ->where('payments.status', DocumentStatus::Posted)
            ->whereBetween('payments.received_at', $range)
            ->groupBy('users.id', 'users.name')
            ->orderBy('users.name')
            ->select('users.id', 'users.name',
                DB::raw('count(distinct payments.id) as receipts'),
                DB::raw('sum(payment_lines.amount) as total'),
                DB::raw('sum(case when payment_lines.money_account_id in ('.($custodyBoxes->implode(',') ?: '0').') then payment_lines.amount else 0 end) as to_custody'))
            ->get()
            ->map(fn ($r) => ['user_id' => $r->id, 'name' => $r->name, 'receipts' => (int) $r->receipts, 'total' => (int) $r->total, 'to_custody' => (int) $r->to_custody])
            ->all();

        $custody = MoneyAccount::with('user')->where('kind', MoneyAccountKind::Custody)->get()
            ->map(fn (MoneyAccount $m) => ['user_id' => $m->user_id, 'name' => $m->user?->name ?? $m->name, 'balance' => $this->treasury->balance($m)])
            ->sortByDesc('balance')->values()->all();

        $handovers = FundTransfer::with(['from.user', 'to', 'creator'])
            ->whereIn('from_money_account_id', $custodyBoxes)->where('status', DocumentStatus::Posted)
            ->whereBetween('transferred_at', $range)->orderByDesc('transferred_at')->get()
            ->map(fn (FundTransfer $t) => ['at' => $t->transferred_at, 'number' => $t->number, 'name' => $t->from?->user?->name, 'amount' => (int) $t->amount, 'into' => $t->to?->name, 'by' => $t->creator?->name])
            ->all();

        $advances = EmployeeAdvance::with(['user', 'creator'])->whereBetween('advanced_at', $range)->orderByDesc('advanced_at')->get();
        $repayments = EmployeeAdvanceRepayment::with(['user', 'advance', 'creator', 'moneyAccount'])->whereBetween('paid_at', $range)->orderByDesc('paid_at')->get();

        return [
            'collections' => $collections,
            'custody' => $custody,
            'custody_total' => array_sum(array_column($custody, 'balance')),
            'handovers' => $handovers,
            'advances' => $advances,
            'repayments' => $repayments,
            'advances_outstanding' => (int) EmployeeAdvance::sum('balance'),
        ];
    }
}
