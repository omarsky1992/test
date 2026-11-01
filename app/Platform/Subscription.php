<?php

namespace App\Platform;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A client's subscription: how long it runs and where it stands. Dates are worked out in Baghdad
 * time; months are calendar months that never overflow (31 Jan + 1 month = 28/29 Feb).
 */
class Subscription
{
    public const DURATIONS = ['1m' => 'شهر واحد', '2m' => 'شهران', '3m' => 'ثلاثة أشهر', '6m' => 'ستة أشهر', '12m' => 'سنة', 'days' => 'عدد أيام', 'date' => 'حتى تاريخ محدد'];

    public const STATES = [
        'permanent' => 'دائم', 'trial' => 'تجريبي', 'active' => 'نشط', 'expiring' => 'قريب من الانتهاء',
        'grace' => 'منتهي (فترة سماح)', 'expired' => 'منتهي', 'suspended' => 'موقوف إدارياً', 'archived' => 'مؤرشف',
    ];

    public const COLORS = [
        'permanent' => 'primary', 'trial' => 'info', 'active' => 'success', 'expiring' => 'warning',
        'grace' => 'warning', 'expired' => 'danger', 'suspended' => 'gray', 'archived' => 'gray',
    ];

    /** The end of a subscription that starts at $start. */
    public static function endFrom(CarbonImmutable $start, string $duration, ?int $days = null, ?string $date = null): CarbonImmutable
    {
        $start = $start->setTimezone(self::tz());
        $months = ['1m' => 1, '2m' => 2, '3m' => 3, '6m' => 6, '12m' => 12][$duration] ?? null;

        return match (true) {
            $months !== null => $start->addMonthsNoOverflow($months),
            $duration === 'days' && $days !== null && $days > 0 => $start->addDays($days),
            $duration === 'date' && filled($date) => CarbonImmutable::parse($date, self::tz())->endOfDay(),
            default => throw new InvalidArgumentException('مدة الاشتراك غير صحيحة.'),
        };
    }

    /**
     * A renewal: from the current end (or the trial's end) while it is still ahead, so the client
     * loses no day; otherwise from now (no time is counted back for the days it was stopped).
     */
    public static function extendedEnd(Tenant $tenant, string $duration, ?int $days = null, ?string $date = null, ?CarbonImmutable $now = null): CarbonImmutable
    {
        $now ??= CarbonImmutable::now();
        $current = $tenant->subscription_ends_at ?? $tenant->trial_ends_at;
        $from = $current !== null && $current->greaterThan($now) ? $current : $now;

        return self::endFrom($from, $duration, $days, $date);
    }

    public static function state(Tenant $tenant, ?CarbonImmutable $now = null): string
    {
        $now ??= CarbonImmutable::now();

        return match (true) {
            $tenant->status === 'archived' => 'archived',
            $tenant->status === 'suspended' => 'suspended',
            $tenant->is_permanent => 'permanent',
            // A trial runs until it ends or a paid period is set (then that period counts).
            $tenant->subscription_ends_at === null && $tenant->trial_ends_at !== null && $now->lessThan($tenant->trial_ends_at) => 'trial',
            $tenant->subscription_ends_at === null => $tenant->trial_ends_at !== null ? 'expired' : 'active',
            $now->greaterThanOrEqualTo($tenant->subscription_ends_at->addDays($tenant->grace_days)) => 'expired',
            $now->greaterThanOrEqualTo($tenant->subscription_ends_at) => 'grace',
            $now->diffInDays($tenant->subscription_ends_at) < (int) config('platform.expiring_days') => 'expiring',
            default => 'active',
        };
    }

    /** The date the client stops working (end + grace, or the trial's end), null when never. */
    public static function stopsAt(Tenant $tenant): ?CarbonImmutable
    {
        if ($tenant->is_permanent) {
            return null;
        }
        if ($tenant->subscription_ends_at !== null) {
            return $tenant->subscription_ends_at->addDays($tenant->grace_days);
        }

        return $tenant->trial_ends_at;
    }

    public static function tz(): string
    {
        return (string) config('app.timezone', 'Asia/Baghdad');
    }
}
