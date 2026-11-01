<?php

namespace App\Platform;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * «تنبيه قرب انتهاء الاشتراك»: 7, 3 and 1 days before a client's subscription (or trial) ends and
 * on the day itself. Each alert is one row in `tenant_alerts`, unique per client, end date and step,
 * so a scheduled run never raises the same alert twice; a renewal (a new end date) starts again.
 * The rows are what the client's banner and the owner's list show. Sending them by WhatsApp or
 * e-mail is not connected yet: channels can be added in `notify()` once one really exists.
 */
class SubscriptionAlerts
{
    public const STEPS = [7, 3, 1, 0];

    /** @return int alerts raised in this run */
    public function scan(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $raised = 0;
        foreach (Tenant::where('is_permanent', false)->where('status', 'active')->get() as $tenant) {
            [$kind, $ends] = $tenant->subscription_ends_at !== null ? ['subscription', $tenant->subscription_ends_at] : ['trial', $tenant->trial_ends_at];
            if ($ends === null || ($step = self::step($ends, $now)) === null) {
                continue;
            }
            $inserted = DB::connection('platform')->table('tenant_alerts')->insertOrIgnore([
                'tenant_id' => $tenant->id, 'kind' => $kind, 'ends_at' => $ends, 'days_before' => $step,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            if ($inserted > 0) {
                $raised++;
                $this->notify($tenant, $kind, $step, $ends);
            }
        }

        return $raised;
    }

    /**
     * The step due now: the smallest of 7/3/1/0 not below the days left (2 days left → 3), so a
     * client added late gets one alert, not all the earlier ones. null when more than 7 days are left.
     */
    public static function step(CarbonImmutable $ends, CarbonImmutable $now): ?int
    {
        $left = self::daysLeft($ends, $now);
        foreach (array_reverse(self::STEPS) as $step) {
            if ($left <= $step) {
                return $step;
            }
        }

        return null;
    }

    /** Whole days left, by Baghdad calendar days (0 on the last day and after). */
    public static function daysLeft(CarbonImmutable $ends, CarbonImmutable $now): int
    {
        $tz = Subscription::tz();

        return max(0, (int) $now->setTimezone($tz)->startOfDay()->diffInDays($ends->setTimezone($tz)->startOfDay(), false));
    }

    /** The banner text for the client, or null when nothing is due. */
    public static function message(Tenant $tenant, ?CarbonImmutable $now = null): ?string
    {
        $now ??= CarbonImmutable::now();
        $state = Subscription::state($tenant, $now);
        $contact = app(PlatformSettings::class)->get('contact.message');
        if ($state === 'grace') {
            $stops = Subscription::stopsAt($tenant)->setTimezone(Subscription::tz())->format('Y/m/d');

            return "تنبيه: انتهى اشتراكك، وسيتوقف النظام في {$stops}. {$contact}";
        }
        $ends = $tenant->subscription_ends_at ?? $tenant->trial_ends_at;
        if ($ends === null || ! in_array($state, ['expiring', 'trial', 'active'], true) || self::step($ends, $now) === null) {
            return null;
        }
        $left = self::daysLeft($ends, $now);
        $what = $state === 'trial' ? 'فترتك التجريبية' : 'اشتراكك';

        return ($left === 0 ? "تنبيه: {$what} ينتهي اليوم." : "تنبيه: {$what} سينتهي بعد {$left} ".($left === 1 ? 'يوم' : 'أيام').'.').' '.$contact;
    }

    /** No channel is connected yet (WhatsApp/e-mail come later); the alert row is the record. */
    private function notify(Tenant $tenant, string $kind, int $step, CarbonImmutable $ends): void {}
}
