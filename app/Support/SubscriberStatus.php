<?php

namespace App\Support;

use App\Enums\AccountStatus;
use App\Enums\DebtBucket;
use App\Enums\DebtStatus;
use App\Models\Account;
use App\Models\ActivationDue;
use App\Services\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The subscriber groups of the employee screens. A subscription ends at the later of the company
 * end date and the end recorded in the system (PostgreSQL GREATEST ignores a missing one).
 */
class SubscriberStatus
{
    public const ENDS = 'greatest(accounts.external_ends_at, accounts.service_ends_at)';

    public const FILTERS = [
        'all' => 'الكل',
        'must_activate' => 'يجب التفعيل',
        'active' => 'الفعّالون',
        'expiring' => 'ينتهي قريباً',
        'expired' => 'منتهي',
        'active_primary' => 'فعّال وعليه دين',
        'expired_primary' => 'منتهي وعليه دين',
        'secondary' => 'عليه دين ثانوي',
        'primary' => 'عليه دين أولي',
    ];

    public static function base(): Builder
    {
        return Account::query()->where('accounts.status', '<>', AccountStatus::Closed);
    }

    public static function apply(Builder $query, string $filter): Builder
    {
        $ends = self::ENDS;
        $days = app(Settings::class)->expiringDays();

        // The application clock, not the database's, so both always agree.
        $now = now();

        return match ($filter) {
            'must_activate' => $query->whereIn('accounts.id', ActivationDue::where('status', 'pending')->select('account_id')),
            'active' => $query->whereRaw("{$ends} > ?", [$now]),
            // Whole days left ≤ N, counting like Account::daysLeft (the last partial day is 0).
            'expiring' => $query->whereRaw("{$ends} > ? and {$ends} < ?", [$now, $now->copy()->addDays($days + 1)]),
            'expired' => $query->whereRaw("{$ends} <= ?", [$now]),
            'active_primary' => self::withDebt($query->whereRaw("{$ends} > ?", [$now]), DebtBucket::Primary),
            'expired_primary' => self::withDebt($query->whereRaw("({$ends} <= ? or {$ends} is null)", [$now]), DebtBucket::Primary),
            'secondary' => self::withDebt($query, DebtBucket::Secondary),
            'primary' => self::withDebt($query, DebtBucket::Primary),
            default => $query,
        };
    }

    private static function withDebt(Builder $query, DebtBucket $bucket): Builder
    {
        return $query->whereExists(fn ($q) => $q->select(DB::raw(1))->from('debts')
            ->whereColumn('debts.account_id', 'accounts.id')
            ->where('debts.bucket', $bucket->value)
            ->whereIn('debts.status', [DebtStatus::Open->value, DebtStatus::Partial->value]));
    }

    /**
     * @return array<string, int>
     */
    public static function counts(): array
    {
        return collect(array_keys(self::FILTERS))->mapWithKeys(fn (string $f) => [$f => self::apply(self::base(), $f)->count()])->all();
    }

    /** Whole days left (0 when ended), or null when no end date is known. */
    public static function daysLeft(Account $account): ?int
    {
        $ends = collect([$account->external_ends_at, $account->service_ends_at])->filter()->max();

        return Account::daysLeft($ends);
    }

    public static function endsAt(Account $account): ?\DateTimeInterface
    {
        return collect([$account->external_ends_at, $account->service_ends_at])->filter()->max();
    }

    /** green / orange / red / gray for a card. */
    public static function tone(Account $account): string
    {
        $days = self::daysLeft($account);

        return match (true) {
            $days === null => 'gray',
            $days === 0 && self::endsAt($account)->getTimestamp() <= now()->getTimestamp() => 'red',
            $days <= app(Settings::class)->expiringDays() => 'orange',
            default => 'green',
        };
    }
}
