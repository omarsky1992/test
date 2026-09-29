<?php

namespace App\Services;

use App\Enums\CompletedVia;
use App\Enums\CompletionStatus;
use App\Enums\DebtBucket;
use App\Enums\DebtSource;
use App\Enums\DebtStatus;
use App\Enums\DocumentStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Account;
use App\Models\Debt;
use App\Models\DebtTransfer;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\PaymentAllocation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DebtService
{
    public function __construct(
        private Ledger $ledger,
        private Sequencer $sequencer,
        private Audit $audit,
        private ActivationService $activations,
    ) {
    }

    /**
     * A debt entered by hand: a manual charge (installation fee…) or an opening debt from before the system.
     */
    public function create(
        Account $account,
        int $amount,
        DebtBucket $bucket = DebtBucket::Primary,
        DebtSource $source = DebtSource::Manual,
        ?CarbonImmutable $debtDate = null,
        ?string $dueDate = null,
        ?string $notes = null,
    ): Debt {
        if (! in_array($source, [DebtSource::Manual, DebtSource::Opening], true)) {
            throw new BusinessRuleException('هذا المصدر يُنشأ تلقائياً من عمليته.');
        }
        if ($amount <= 0) {
            throw new BusinessRuleException('مبلغ الدين يجب أن يكون أكبر من صفر.');
        }

        return DB::transaction(function () use ($account, $amount, $bucket, $source, $debtDate, $dueDate, $notes) {
            $now = CarbonImmutable::now();
            $debt = Debt::create([
                'number' => $this->sequencer->next('debt', $now),
                'account_id' => $account->id,
                'subscriber_id' => $account->subscriber_id,
                'branch_id' => $account->branch_id,
                'source' => $source,
                'bucket' => $bucket,
                'original_amount' => $amount,
                'paid_amount' => 0,
                'balance' => $amount,
                'debt_date' => $debtDate ?? $now,
                'due_date' => $dueDate,
                'status' => DebtStatus::Open,
                'notes' => $notes,
                'created_by' => Auth::id(),
            ]);

            $this->ledger->post($source === DebtSource::Opening ? 'opening_debt' : 'manual_debt', $now, [
                ['account' => $this->activations->arCode($bucket), 'debit' => $amount, 'subscriber_id' => $account->subscriber_id, 'account_id' => $account->id, 'debt_id' => $debt->id],
                ['account' => $source === DebtSource::Opening ? Ledger::OPENING_EQUITY : Ledger::REV_OTHER, 'credit' => $amount],
            ], $debt, "دين {$debt->number}", $account->branch_id);

            $this->audit->log('debt.created', $debt, null, [
                'number' => $debt->number, 'amount' => $amount, 'bucket' => $bucket->value, 'source' => $source->value,
            ], subscriberId: $account->subscriber_id);

            return $debt;
        });
    }

    /**
     * Moves what is left of a secondary debt to the primary debts. When the debt belongs to a
     * pending 7-day activation, this completes it (+23 days).
     */
    public function transfer(Debt $debt, ?string $reason = null, ?CarbonImmutable $at = null): DebtTransfer
    {
        return DB::transaction(function () use ($debt, $reason, $at) {
            $at ??= CarbonImmutable::now();
            $debt = Debt::whereKey($debt->id)->lockForUpdate()->firstOrFail();

            if ($debt->bucket !== DebtBucket::Secondary) {
                throw new BusinessRuleException('الدين ليس في الديون الثانوية.');
            }
            if (! in_array($debt->status, [DebtStatus::Open, DebtStatus::Partial], true) || $debt->balance <= 0) {
                throw new BusinessRuleException('لا يوجد مبلغ متبقٍ لنقله.');
            }

            $transfer = DebtTransfer::create([
                'number' => $this->sequencer->next('transfer', $at),
                'debt_id' => $debt->id,
                'account_id' => $debt->account_id,
                'subscriber_id' => $debt->subscriber_id,
                'activation_id' => $debt->activation_id,
                'from_bucket' => DebtBucket::Secondary,
                'to_bucket' => DebtBucket::Primary,
                'amount' => $debt->balance,
                'days_added' => 0,
                'reason' => $reason,
                'performed_at' => $at,
                'performed_by' => Auth::id(),
                'status' => DocumentStatus::Posted,
            ]);

            $tag = ['subscriber_id' => $debt->subscriber_id, 'account_id' => $debt->account_id, 'debt_id' => $debt->id];
            $txn = $this->ledger->post('debt_transfer', $at, [
                ['account' => Ledger::AR_PRIMARY, 'debit' => $debt->balance, ...$tag],
                ['account' => Ledger::AR_SECONDARY, 'credit' => $debt->balance, ...$tag],
            ], $transfer, "مناقلة {$transfer->number}", $debt->branch_id);

            $debt->update(['bucket' => DebtBucket::Primary]);

            $period = null;
            if ($debt->activation?->completion_status === CompletionStatus::Pending) {
                $period = $this->activations->complete($debt->activation, CompletedVia::Transfer, $at, 'transfer', $transfer->id);
            }
            $transfer->update(['txn_id' => $txn->id, 'days_added' => $period?->days ?? 0, 'period_id' => $period?->id]);

            $this->audit->log('transfer.created', $transfer, null, [
                'number' => $transfer->number, 'debt' => $debt->number, 'amount' => $transfer->amount, 'days_added' => $transfer->days_added,
            ], $reason, $debt->subscriber_id);

            return $transfer->fresh();
        });
    }

    /**
     * «Deleting» a debt: voids it with a reason and reverses its postings. Refused while payments
     * are still allocated to it; an activation debt is voided through its activation.
     */
    public function void(Debt $debt, string $reason): Debt
    {
        return DB::transaction(function () use ($debt, $reason) {
            $debt = Debt::whereKey($debt->id)->lockForUpdate()->firstOrFail();
            if ($debt->activation_id !== null) {
                $this->activations->void($debt->activation, $reason);

                return $debt->fresh();
            }
            if ($debt->source === DebtSource::DeviceSale) {
                throw new BusinessRuleException('ألغِ عملية البيع نفسها لإلغاء دينها.');
            }

            return $this->reverseAndVoid($debt, $reason);
        });
    }

    /**
     * @internal Used by void() and by ActivationService::void().
     */
    public function reverseAndVoid(Debt $debt, string $reason): Debt
    {
        if ($debt->status === DebtStatus::Voided) {
            throw new BusinessRuleException('الدين ملغى مسبقاً.');
        }
        if (PaymentAllocation::where('debt_id', $debt->id)->where('is_reversed', false)->exists()) {
            throw new BusinessRuleException('على الدين تسديدات. ألغِ سندات القبض أولاً ثم ألغِ الدين.');
        }

        $now = CarbonImmutable::now();
        $txnIds = LedgerEntry::where('debt_id', $debt->id)->distinct()->pluck('txn_id');
        $txns = FinancialTransaction::whereIn('id', $txnIds)->whereNull('reverses_txn_id')->orderByDesc('id')->get();
        foreach ($txns as $txn) {
            if (! $this->ledger->isReversed($txn)) {
                $this->ledger->reverse($txn, $now, "إلغاء الدين {$debt->number}");
            }
        }

        DebtTransfer::where('debt_id', $debt->id)->where('status', DocumentStatus::Posted)->update([
            'status' => DocumentStatus::Voided, 'voided_at' => $now, 'voided_by' => Auth::id(), 'void_reason' => $reason,
        ]);

        $old = ['status' => $debt->status->value, 'balance' => $debt->balance];
        $debt->update(['status' => DebtStatus::Voided, 'voided_at' => $now, 'voided_by' => Auth::id(), 'void_reason' => $reason]);
        $this->audit->log('debt.voided', $debt, $old, ['status' => 'voided'], $reason, $debt->subscriber_id);

        return $debt;
    }

    public static function statusFor(int $original, int $paid): DebtStatus
    {
        return match (true) {
            $paid <= 0 => DebtStatus::Open,
            $paid >= $original => DebtStatus::Paid,
            default => DebtStatus::Partial,
        };
    }
}
