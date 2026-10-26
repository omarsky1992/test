<?php

namespace App\Support;

use App\Models\Account;
use Carbon\CarbonImmutable;

/**
 * Reading «القدرة المستلمة» (fibre RX power, dBm): -17 to -25 is fine, down to -28 weak, below that
 * a cable or splice problem, and -39 or a status other than Up means the device is off or cut.
 */
class OpticalSignal
{
    public const LEVELS = [
        'good' => ['تمام', 'success'],
        'weak' => ['ضعيفة', 'warning'],
        'bad' => ['مشكلة بالكيبل', 'danger'],
        'off' => ['مطفي / مقطوع', 'gray'],
    ];

    public static function level(?float $rx, ?string $status = null): ?string
    {
        if ($rx === null && $status === null) {
            return null;
        }
        if (($status !== null && mb_strtolower($status) !== 'up') || $rx === null || $rx <= -39) {
            return 'off';
        }

        return match (true) {
            $rx < -28 => 'bad',
            $rx < -25 => 'weak',
            default => 'good',
        };
    }

    public static function ofAccount(Account $account): ?string
    {
        return self::level($account->rx_power !== null ? (float) $account->rx_power : null, $account->ont_status);
    }

    /** «-23.47 · تمام» */
    public static function label(Account $account): ?string
    {
        $level = self::ofAccount($account);
        if ($level === null) {
            return null;
        }
        $value = $account->rx_power !== null ? number_format((float) $account->rx_power, 2) : '—';

        return $value.' · '.self::LEVELS[$level][0];
    }

    public static function color(Account $account): ?string
    {
        return ($level = self::ofAccount($account)) ? self::LEVELS[$level][1] : null;
    }

    public static function readAt(Account $account): ?string
    {
        return $account->rx_read_at ? 'قراءة '.CarbonImmutable::parse($account->rx_read_at)->diffForHumans() : null;
    }
}
