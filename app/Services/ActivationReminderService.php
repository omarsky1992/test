<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Account;
use App\Models\ActivationReminder;
use App\Models\WhatsappNumber;
use App\Support\SubscriberStatus;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * «تذكير تفعيل 7 أيام»: set on an account, it waits until the subscription ends (its end date as it
 * is then, so a renewal in between moves it) and tells the chosen staff numbers to activate it again.
 * One pending reminder per account; it never changes a subscription, a debt or any money.
 */
class ActivationReminderService
{
    public function __construct(private Audit $audit) {}

    public function set(Account $account, ?array $numberIds = null, int $days = 7): ActivationReminder
    {
        $this->authorize();
        $numberIds = array_values(array_unique(array_map('intval', array_filter((array) $numberIds)))) ?: null;
        if ($numberIds !== null && WhatsappNumber::whereIn('id', $numberIds)->where('is_active', true)->count() !== count($numberIds)) {
            throw new BusinessRuleException('أحد الأرقام المختارة غير مفعّل.');
        }
        if (! WhatsappNumber::where('is_active', true)->exists()) {
            throw new BusinessRuleException('لا توجد أرقام مخولة مفعّلة يصلها التذكير.');
        }

        return DB::transaction(function () use ($account, $numberIds, $days) {
            Account::whereKey($account->id)->lockForUpdate()->first();
            // A new reminder replaces the one still waiting on this account.
            ActivationReminder::where('account_id', $account->id)->whereNull('sent_at')->whereNull('cancelled_at')
                ->update(['cancelled_at' => now(), 'cancelled_by' => Auth::id()]);
            $ends = SubscriberStatus::endsAt($account);
            $reminder = ActivationReminder::create([
                'account_id' => $account->id, 'subscriber_id' => $account->subscriber_id, 'days' => $days, 'number_ids' => $numberIds,
                'ends_at_when_set' => $ends, 'created_by' => Auth::id(),
            ]);
            $this->audit->log('activation_reminder.set', $reminder, null, [
                'account' => $account->username, 'days' => $days, 'numbers' => $numberIds, 'ends_at' => $ends ? CarbonImmutable::instance($ends)->toIso8601String() : null,
            ], subscriberId: $account->subscriber_id);

            return $reminder;
        });
    }

    public function cancel(ActivationReminder $reminder): void
    {
        $this->authorize();
        if (! $reminder->isPending()) {
            throw new BusinessRuleException('التذكير أُرسل أو أُلغي مسبقاً.');
        }
        $reminder->update(['cancelled_at' => now(), 'cancelled_by' => Auth::id()]);
        $this->audit->log('activation_reminder.cancelled', $reminder, null, null, subscriberId: $reminder->subscriber_id);
    }

    private function authorize(): void
    {
        if (! Auth::user()?->can('reminders.activation')) {
            throw new AuthorizationException('ليست لديك صلاحية تذكير التفعيل.');
        }
    }
}
