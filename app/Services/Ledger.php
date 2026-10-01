<?php

namespace App\Services;

use App\Models\FinancialTransaction;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Posts balanced double-entry transactions. Nothing here ever updates or deletes a line;
 * corrections are made with reverse().
 */
class Ledger
{
    public const AR_SECONDARY = 'AR_SECONDARY';
    public const AR_PRIMARY = 'AR_PRIMARY';
    public const CUSTOMER_CREDIT = 'CUSTOMER_CREDIT';
    public const REV_COMMISSION = 'REV_COMMISSION';
    public const REV_DEVICE_SALES = 'REV_DEVICE_SALES';
    public const REV_OTHER = 'REV_OTHER';
    public const EXP_GENERAL = 'EXP_GENERAL';
    public const EXP_PROMO_DISCOUNT = 'EXP_PROMO_DISCOUNT';
    public const DISTRIBUTIONS = 'DISTRIBUTIONS';
    public const OPENING_EQUITY = 'OPENING_EQUITY';
    public const RENEWALS_CLEARING = 'RENEWALS_CLEARING';
    public const EMPLOYEE_ADVANCES = 'EMPLOYEE_ADVANCES';

    /** @var array<string, int> */
    private array $idsByCode = [];

    /**
     * @param  array<int, array{account: string|int, debit?: int, credit?: int, subscriber_id?: ?int, account_id?: ?int, debt_id?: ?int}>  $lines
     */
    public function post(
        string $type,
        DateTimeInterface $occurredAt,
        array $lines,
        ?Model $source = null,
        ?string $memo = null,
        ?int $branchId = null,
        ?int $reversesTxnId = null,
    ): FinancialTransaction {
        $lines = array_values(array_filter($lines, fn (array $l) => ($l['debit'] ?? 0) + ($l['credit'] ?? 0) !== 0));
        if ($lines === []) {
            throw new InvalidArgumentException('A financial transaction needs at least one line.');
        }

        $debits = array_sum(array_map(fn ($l) => $l['debit'] ?? 0, $lines));
        $credits = array_sum(array_map(fn ($l) => $l['credit'] ?? 0, $lines));
        if ($debits !== $credits) {
            throw new InvalidArgumentException("Unbalanced transaction: debit {$debits} ≠ credit {$credits}.");
        }

        return DB::transaction(function () use ($type, $occurredAt, $lines, $source, $memo, $branchId, $reversesTxnId) {
            $txn = FinancialTransaction::create([
                'txn_type' => $type,
                'occurred_at' => $occurredAt,
                'branch_id' => $branchId,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'reverses_txn_id' => $reversesTxnId,
                'memo' => $memo,
                'created_by' => Auth::id(),
            ]);

            LedgerEntry::insert(array_map(fn (array $l) => [
                'txn_id' => $txn->id,
                'ledger_account_id' => $this->accountId($l['account']),
                'debit' => $l['debit'] ?? 0,
                'credit' => $l['credit'] ?? 0,
                'currency_code' => 'IQD',
                'occurred_at' => $occurredAt,
                'subscriber_id' => $l['subscriber_id'] ?? null,
                'account_id' => $l['account_id'] ?? null,
                'debt_id' => $l['debt_id'] ?? null,
            ], $lines));

            // Check the balance trigger now instead of at commit, so a bad posting fails where it happens.
            DB::statement('SET CONSTRAINTS ledger_entries_balanced IMMEDIATE');

            return $txn;
        });
    }

    /**
     * Posts the exact mirror of a transaction. A transaction can be reversed only once.
     */
    public function reverse(FinancialTransaction $txn, DateTimeInterface $at, ?string $memo = null): FinancialTransaction
    {
        $lines = $txn->entries()->get()->map(fn (LedgerEntry $e) => [
            'account' => $e->ledger_account_id,
            'debit' => $e->credit,
            'credit' => $e->debit,
            'subscriber_id' => $e->subscriber_id,
            'account_id' => $e->account_id,
            'debt_id' => $e->debt_id,
        ])->all();

        return $this->post('reversal', $at, $lines, null, $memo, $txn->branch_id, $txn->id);
    }

    public function isReversed(FinancialTransaction $txn): bool
    {
        return FinancialTransaction::where('reverses_txn_id', $txn->id)->exists();
    }

    /**
     * Debit-minus-credit balance of a ledger account, optionally narrowed to one subscriber account.
     */
    public function balance(string|int $account, ?int $accountId = null, ?DateTimeInterface $until = null): int
    {
        $query = LedgerEntry::where('ledger_account_id', $this->accountId($account));
        if ($accountId !== null) {
            $query->where('account_id', $accountId);
        }
        if ($until !== null) {
            $query->where('occurred_at', '<', $until);
        }

        return (int) $query->sum(DB::raw('debit - credit'));
    }

    public function accountId(string|int $account): int
    {
        if (is_int($account)) {
            return $account;
        }

        return $this->idsByCode[$account] ??= LedgerAccount::where('code', $account)->value('id')
            ?? throw new InvalidArgumentException("Unknown ledger account {$account}.");
    }
}
