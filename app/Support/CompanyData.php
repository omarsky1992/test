<?php

namespace App\Support;

use App\Models\ServicePlan;
use Carbon\CarbonImmutable;

/**
 * Reads values that arrive from the company (an Excel export or the live site) the same way
 * for both: plan names, subscription statuses and dates.
 */
class CompanyData
{
    /**
     * @return array<string, int> normalized plan name => plan id
     */
    public static function planIndex(): array
    {
        $index = [];
        foreach (ServicePlan::all() as $plan) {
            $index[self::planKey($plan->code)] = $plan->id;
            $index[self::planKey($plan->name_ar)] = $plan->id;
            $index[self::planKey('ftth_'.$plan->code)] = $plan->id;
        }

        return $index;
    }

    public static function planKey(string $name): string
    {
        return preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower(Arabic::normalize($name)));
    }

    public static function status(?string $status): ?string
    {
        $status = mb_strtolower(trim((string) $status));

        return match (true) {
            $status === '' => null,
            in_array($status, ['active', 'فعال', 'فعّال', 'نشط'], true) => 'active',
            in_array($status, ['expired', 'منتهي', 'منتهية', 'منتهية الصلاحية'], true) => 'expired',
            default => mb_substr($status, 0, 30),
        };
    }

    public static function isExpiredStatus(?string $status): bool
    {
        return in_array($status, ['expired', 'inactive', 'terminated', 'disabled'], true);
    }

    public static function date(?string $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        return rescue(fn () => CarbonImmutable::parse($value)->setTimezone(config('app.timezone')), null, false);
    }

    /**
     * Iraqi mobile numbers are shown as 07XXXXXXXXX; anything else is kept as written.
     */
    public static function phone(?string $phone): ?string
    {
        if (blank($phone)) {
            return null;
        }
        $normalized = Arabic::phone($phone);

        return preg_match('/^9647\d{9}$/', $normalized) ? '0'.substr($normalized, 3) : trim($phone);
    }
}
