<?php

namespace App\Sync;

use App\Models\Account;
use App\Models\AccountRenewal;
use App\Models\AuditLog;
use App\Models\ServicePlan;
use App\Services\RenewalService;
use Carbon\CarbonImmutable;

/**
 * Finds renewals that a sync applied without recording, from the audit log: every account sync
 * line keeps the end date before and after. An end date that had already passed, replaced by
 * one still running, is a renewal (0 days → more than 0). Recording them is idempotent: each
 * renewal's reference is the account and its new end date.
 */
class MissedRenewals
{
    public function __construct(private RenewalService $renewals)
    {
    }

    /**
     * @return array<int, array{account: Account, at: CarbonImmutable, old: CarbonImmutable, new: CarbonImmutable, days: int, amount: ?int}>
     */
    public function find(): array
    {
        $found = [];
        $logs = AuditLog::where('action', 'account.synced')
            ->whereRaw("old_values->>'external_ends_at' is not null")
            ->whereRaw("new_values->>'external_ends_at' is not null")
            ->orderBy('occurred_at')->get();
        $accounts = Account::whereIn('id', $logs->pluck('entity_id')->unique())->get()->keyBy('id');
        $prices = ServicePlan::pluck('price', 'id');

        foreach ($logs as $log) {
            $account = $accounts->get($log->entity_id);
            $old = $this->date($log->old_values['external_ends_at'] ?? null);
            $new = $this->date($log->new_values['external_ends_at'] ?? null);
            $at = CarbonImmutable::parse($log->occurred_at);
            if (! $account || ! $old || ! $new) {
                continue;
            }
            $newDays = Account::daysLeft($new, $at);
            if (Account::daysLeft($old, $at) !== 0 || $newDays <= 0) {
                continue;
            }
            if (AccountRenewal::where('reference', RenewalService::reference($account, $new, $at))->exists()) {
                continue;
            }
            $found[] = [
                'account' => $account, 'at' => $at, 'old' => $old, 'new' => $new, 'days' => $newDays,
                'amount' => $account->current_plan_id ? (int) ($prices[$account->current_plan_id] ?? 0) ?: null : null,
            ];
        }

        return $found;
    }

    /**
     * @return array{renewals: int, debts: int, amount: int}
     */
    public function record(): array
    {
        $result = ['renewals' => 0, 'debts' => 0, 'amount' => 0];
        foreach ($this->find() as $item) {
            $renewal = $this->renewals->record($item['account'], 0, $item['days'], $item['old'], $item['new'], $item['account']->external_plan, null, $item['at']);
            if ($renewal->wasRecentlyCreated) {
                $result['renewals']++;
                if ($renewal->debt_id !== null) {
                    $result['debts']++;
                    $result['amount'] += (int) $renewal->amount;
                }
            }
        }

        return $result;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? rescue(fn () => CarbonImmutable::parse($value, config('app.timezone')), null, false) : null;
    }
}
