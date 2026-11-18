<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Account;
use App\Models\AccountRenewal;
use App\Models\ActivationReminder;
use App\Models\WhatsappNumber;
use App\Support\SubscriberStatus;
use App\WhatsApp\CommandExecutor;
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

    /**
     * @param  int  $hoursBefore  0 = once the subscription has ended; more = that many hours before its end
     * @param  string|null  $title  the reminder's own heading (default «تذكير»)
     */
    public function set(Account $account, ?array $numberIds = null, int $days = 7, int $hoursBefore = 0, ?string $title = null): ActivationReminder
    {
        $this->authorize();
        $numberIds = array_values(array_unique(array_map('intval', array_filter((array) $numberIds)))) ?: null;
        if ($numberIds !== null && WhatsappNumber::whereIn('id', $numberIds)->where('is_active', true)->count() !== count($numberIds)) {
            throw new BusinessRuleException('أحد الأرقام المختارة غير مفعّل.');
        }
        if (! WhatsappNumber::where('is_active', true)->exists()) {
            throw new BusinessRuleException('لا توجد أرقام مخولة مفعّلة يصلها التذكير.');
        }

        return DB::transaction(function () use ($account, $numberIds, $days, $hoursBefore, $title) {
            Account::whereKey($account->id)->lockForUpdate()->first();
            // A new reminder replaces the one still waiting on this account.
            ActivationReminder::where('account_id', $account->id)->whereNull('sent_at')->whereNull('cancelled_at')
                ->update(['cancelled_at' => now(), 'cancelled_by' => Auth::id()]);
            $ends = SubscriberStatus::endsAt($account);
            $reminder = ActivationReminder::create([
                'account_id' => $account->id, 'subscriber_id' => $account->subscriber_id, 'days' => $days, 'number_ids' => $numberIds,
                'ends_at_when_set' => $ends, 'created_by' => Auth::id(),
                'hours_before' => max(0, $hoursBefore), 'title' => $title !== null ? mb_substr(trim($title), 0, 100) : null,
            ]);
            $this->audit->log('activation_reminder.set', $reminder, null, [
                'account' => $account->username, 'days' => $days, 'numbers' => $numberIds, 'title' => $title, 'hours_before' => $hoursBefore ?: null, 'ends_at' => $ends ? CarbonImmutable::instance($ends)->toIso8601String() : null,
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

    /** How close (days) a «قرضه» reminder must be to an activation for it to be marked «قرضة فراس». */
    public const LOAN_WINDOW_DAYS = 15;

    /**
     * «قرضه فلان»: the subscriber paid in full, Firas activated the 7 days and took the money as a loan,
     * and he activates the month himself. A renewal on that account near the reminder (its 7 days or the
     * month) is marked so the owner does not record a debt by mistake. A label only: nothing is decided.
     */
    public static function isLoan(AccountRenewal $renewal): bool
    {
        $at = CarbonImmutable::instance($renewal->detected_at ?? $renewal->created_at ?? now());

        return ActivationReminder::where('account_id', $renewal->account_id)
            ->where('title', CommandExecutor::LOAN_TITLE)->whereNull('cancelled_at')
            ->whereBetween('created_at', [$at->subDays(self::LOAN_WINDOW_DAYS), $at->addDays(self::LOAN_WINDOW_DAYS)])
            ->exists();
    }

    /** The line under the subscriber's name in the activation lists: a new subscriber, a «قرضه». */
    public static function renewalNote(AccountRenewal $renewal): ?string
    {
        $notes = array_filter([
            $renewal->is_new ? '🆕 مشترك جديد' : null,
            self::isLoan($renewal) ? '🤝 قرضة فراس (المشترك دافع كامل)' : null,
        ]);

        return $notes ? implode(' · ', $notes) : null;
    }

    private function authorize(): void
    {
        if (! Auth::user()?->can('reminders.activation')) {
            throw new AuthorizationException('ليست لديك صلاحية تذكير التفعيل.');
        }
    }
}
