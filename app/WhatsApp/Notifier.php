<?php

namespace App\WhatsApp;

use App\Enums\DebtBucket;
use App\Enums\DebtStatus;
use App\Enums\DocumentStatus;
use App\Filament\Pages\WhatsappReminders;
use App\Models\Account;
use App\Models\AccountRenewal;
use App\Models\Activation;
use App\Models\ActivationDue;
use App\Models\MessageTemplate;
use App\Models\Payment;
use App\Models\SubscriberNote;
use App\Models\WhatsappNumber;
use App\Services\Settings;
use App\Support\Money;
use App\Support\SubscriberStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the system tells by WhatsApp, on its own:
 * - the staff (numbers marked «يستلم التنبيهات»): «يجب التفعيل» and secondary debts about to end;
 * - the subscribers, only when switched on: activation (7 or 30 days), subscription ending or ended
 *   (not after the short 7-day activation), debt reminders.
 */
class Notifier
{
    public function __construct(private Outbox $outbox, private Settings $settings) {}

    // ---- Staff alerts ----

    public function mustActivate(ActivationDue $due): void
    {
        if (! $this->settings->get('alerts.must_activate')) {
            return;
        }
        $account = Account::with('subscriber')->find($due->account_id);
        $text = "🟣 يجب التفعيل\n{$account?->subscriber?->full_name} ({$account?->username})\nسدّد الدين الثانوي ".Money::format($due->amount).'، فعّله على موقع الشركة.';
        foreach ($this->alertNumbers() as $number) {
            $this->outbox->queue($number->phone, $text, 'alert_must_activate', "alert:due:{$due->id}:{$due->paid_at->getTimestamp()}:{$number->id}", $due->account_id);
        }
    }

    /**
     * Note reminders whose time has come: one message per number (the chosen one, or every active
     * authorized number), once. Closed or deleted notes are never reminded.
     */
    public function noteReminders(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $queued = 0;
        $due = SubscriberNote::with(['subscriber', 'creator', 'remindNumber'])->where('status', 'active')
            ->whereNotNull('remind_at')->whereNull('reminded_at')->where('remind_at', '<=', $now)->orderBy('remind_at')->limit(100)->get();
        foreach ($due as $note) {
            DB::transaction(function () use ($note, $now, &$queued) {
                // Marked first, under a lock: two runs never send it twice.
                if (SubscriberNote::whereKey($note->id)->whereNull('reminded_at')->update(['reminded_at' => $now]) === 0) {
                    return;
                }
                $numbers = $note->remind_number_id !== null
                    ? collect([$note->remindNumber])->filter(fn ($n) => $n?->is_active)
                    : WhatsappNumber::with('user')->where('is_active', true)->get()->filter(fn ($n) => $n->user?->is_active ?? true);
                $account = Account::where('subscriber_id', $note->subscriber_id)->orderBy('id')->first();
                $text = "⏰ تذكير: {$note->subscriber?->full_name}".($account?->username ? " ({$account->username})" : '')
                    ."\n{$note->body}\n— ".($note->creator?->name ?? '—').' · '.$note->created_at->setTimezone(config('app.timezone'))->format('Y/m/d H:i');
                foreach ($numbers as $number) {
                    $queued += $this->outbox->queue($number->phone, $text, 'alert_note', "note:remind:{$note->id}:{$number->id}", $account?->id) ? 1 : 0;
                }
            });
        }

        return $queued;
    }

    /**
     * Secondary debts whose subscription ends within the alert window (24 hours by default).
     */
    public function scanSecondaryExpiring(?CarbonImmutable $now = null): int
    {
        if (! $this->settings->get('alerts.secondary_expiring') || ($numbers = $this->alertNumbers())->isEmpty()) {
            return 0;
        }
        $now ??= CarbonImmutable::now();
        $hours = max(1, (int) $this->settings->get('alerts.hours_before'));
        $accounts = $this->withOpen(SubscriberStatus::base(), DebtBucket::Secondary)
            ->whereRaw(SubscriberStatus::ENDS.' > ? and '.SubscriberStatus::ENDS.' <= ?', [$now, $now->addHours($hours)])
            ->with('subscriber')->withSum(['debts as secondary_due' => fn (Builder $d) => $this->open($d, DebtBucket::Secondary)], 'balance')->get();

        $count = 0;
        foreach ($accounts as $account) {
            $ends = SubscriberStatus::endsAt($account);
            $left = max(1, (int) ceil(($ends->getTimestamp() - $now->getTimestamp()) / 3600));
            $text = "⏳ {$account->subscriber?->full_name} ({$account->username}) في الديون الثانوية\nباقي {$left} ساعة وينتهي اشتراكه.\nالمبلغ: ".Money::format((int) $account->secondary_due);
            foreach ($numbers as $number) {
                $count += $this->outbox->queue($number->phone, $text, 'alert_secondary_expiring', "alert:sec:{$account->id}:{$ends->getTimestamp()}:{$number->id}", $account->id) ? 1 : 0;
            }
        }

        return $count;
    }

    // ---- Subscriber messages ----

    /** An activation seen on the company site or sent by WhatsApp (7 or 30 days). */
    public function renewal(AccountRenewal $renewal): void
    {
        if (! $this->subscriberMessage('renewal')) {
            return;
        }
        $account = Account::with(['subscriber', 'currentPlan'])->find($renewal->account_id);
        if ($account) {
            // The account gets its new end date after this; the message already tells it.
            if ($renewal->new_ends_at !== null && ($account->external_ends_at === null || $renewal->new_ends_at->greaterThan($account->external_ends_at))) {
                $account->external_ends_at = $renewal->new_ends_at;
            }
            $this->toSubscriber($account, 'renewal', "sub:renewal:{$renewal->id}");
        }
    }

    /** An activation recorded in the panel. */
    public function activation(Activation $activation): void
    {
        if (! $this->subscriberMessage('renewal')) {
            return;
        }
        if ($account = Account::with(['subscriber', 'currentPlan'])->find($activation->account_id)) {
            $this->toSubscriber($account, 'renewal', "sub:activation:{$activation->id}");
        }
    }

    /**
     * «تم استلام دفعتك»: queued once the payment is saved (never before), once per payment (the
     * reference is unique). A failed send stays in the outbox with its error; the payment is untouched.
     */
    public function payment(Payment $payment): void
    {
        if (! $this->subscriberMessage('payment') || ! self::canSend() || $payment->status !== DocumentStatus::Posted) {
            return;
        }
        $template = MessageTemplate::where('auto_event', 'payment')->where('is_active', true)->first();
        $account = Account::with(['subscriber', 'currentPlan'])->find($payment->account_id);
        if (! $template || ! $account || ! ($phone = WhatsappReminders::phone($account))) {
            return;
        }
        $payment->loadMissing('lines.method');
        $values = WhatsappReminders::values($account) + [
            '{المبلغ_المستلم}' => Money::format($payment->amount),
            '{طريقة_الدفع}' => $payment->lines->map(fn ($l) => $l->method->name_ar)->unique()->implode(' + '),
            '{تاريخ_الدفع}' => $payment->received_at->setTimezone(config('app.timezone'))->format('Y/m/d H:i'),
            '{رقم_الوصل}' => (string) $payment->receipt_number,
            '{رقم_العملية}' => (string) ($payment->lines->pluck('reference')->filter()->implode('، ') ?: '—'),
        ];
        $this->outbox->queue($phone, $template->render($values), 'sub_payment', "sub:payment:{$payment->id}", $account->id, ['payment_id' => $payment->id]);
    }

    /** A WhatsApp connection is set up (keys present) for the subscribers' messages. */
    public static function canSend(): bool
    {
        $settings = app(Settings::class);
        if ($settings->get('whatsapp.driver') === 'meta') {
            return CloudApiGateway::configured();
        }

        return WahaGateway::configured($settings->get('whatsapp.notify_separate') ? WahaGateway::LINES['notify'] : WahaGateway::LINES['main']);
    }

    /**
     * Whether the running subscription is the short activation (7 days): its subscribers get no
     * message before or at the end. Measured from the start of the latest activation to the end,
     * so a 7-day activation later completed to 30 days counts as a full one.
     */
    public function shortActivation(Account $account): bool
    {
        $ends = SubscriberStatus::endsAt($account);
        if ($ends === null) {
            return false;
        }
        $renewal = AccountRenewal::where('account_id', $account->id)->latest('detected_at')->latest('id')->first();
        $activation = Activation::where('account_id', $account->id)->where('status', DocumentStatus::Posted)->latest('starts_at')->first();
        $starts = collect([
            $renewal ? collect([$renewal->detected_at, $renewal->previous_ends_at])->filter()->max() : null,
            $activation?->starts_at,
        ])->filter()->max();
        if ($starts === null) {
            return false;
        }

        return $ends->getTimestamp() - $starts->getTimestamp() <= ($this->settings->partialDays() + 1) * 86400;
    }

    /**
     * Subscriptions ending soon or just ended, and debt reminders every few days.
     */
    public function scanSubscribers(?CarbonImmutable $now = null): int
    {
        if (! $this->settings->get('subscriber_messages.enabled')) {
            return 0;
        }
        $now ??= CarbonImmutable::now();
        $ends = SubscriberStatus::ENDS;
        $count = 0;

        if ($this->subscriberMessage('expiring')) {
            $hours = max(1, (int) $this->settings->get('subscriber_messages.expiring_hours'));
            foreach (SubscriberStatus::base()->whereRaw("{$ends} > ? and {$ends} <= ?", [$now, $now->addHours($hours)])->get() as $account) {
                if ($this->shortActivation($account)) {
                    continue;
                }
                $count += $this->toSubscriber($account, 'expiring', 'sub:expiring:'.$account->id.':'.SubscriberStatus::endsAt($account)->getTimestamp()) ? 1 : 0;
            }
        }
        if ($this->subscriberMessage('expired')) {
            foreach (SubscriberStatus::base()->whereRaw("{$ends} <= ? and {$ends} > ?", [$now, $now->subDay()])->get() as $account) {
                if ($this->shortActivation($account)) {
                    continue;
                }
                $count += $this->toSubscriber($account, 'expired', 'sub:expired:'.$account->id.':'.SubscriberStatus::endsAt($account)->getTimestamp()) ? 1 : 0;
            }
        }
        if ($this->subscriberMessage('debt')) {
            $every = max(1, (int) $this->settings->get('subscriber_messages.debt_every_days'));
            // The same reference for every day of an N-day window: one reminder per window.
            $bucket = intdiv(intdiv($now->getTimestamp() + $now->setTimezone(config('app.timezone'))->getOffset(), 86400), $every);
            $accounts = SubscriberStatus::base()->whereExists(fn ($q) => $q->selectRaw('1')->from('debts')->whereColumn('debts.account_id', 'accounts.id')
                ->whereIn('debts.status', [DebtStatus::Open->value, DebtStatus::Partial->value])->where('debts.debt_date', '<', $now->subDay()))->get();
            foreach ($accounts as $account) {
                $count += $this->toSubscriber($account, 'debt', "sub:debt:{$account->id}:{$bucket}") ? 1 : 0;
            }
        }

        return $count;
    }

    private function toSubscriber(Account $account, string $event, string $reference): bool
    {
        $template = MessageTemplate::where('auto_event', $event)->where('is_active', true)->first();
        $account->loadMissing(['subscriber', 'currentPlan']);
        $account->loadSum(['debts as open_due' => fn (Builder $d) => $d->whereIn('status', [DebtStatus::Open, DebtStatus::Partial])], 'balance');
        $phone = WhatsappReminders::phone($account);
        if (! $template || ! $phone) {
            return false;
        }

        return $this->outbox->queue($phone, $template->render(WhatsappReminders::values($account)), "sub_{$event}", $reference, $account->id) !== null;
    }

    private function subscriberMessage(string $event): bool
    {
        return (bool) $this->settings->get('subscriber_messages.enabled') && (bool) $this->settings->get("subscriber_messages.{$event}");
    }

    /**
     * @return Collection<int, WhatsappNumber>
     */
    private function alertNumbers(): Collection
    {
        return WhatsappNumber::where('is_active', true)->where('receives_alerts', true)->get();
    }

    private function withOpen(Builder $query, DebtBucket $bucket): Builder
    {
        return $query->whereExists(fn ($q) => $q->selectRaw('1')->from('debts')->whereColumn('debts.account_id', 'accounts.id')
            ->where('debts.bucket', $bucket->value)->whereIn('debts.status', [DebtStatus::Open->value, DebtStatus::Partial->value]));
    }

    private function open(Builder $query, DebtBucket $bucket): Builder
    {
        return $query->where('bucket', $bucket)->whereIn('status', [DebtStatus::Open, DebtStatus::Partial]);
    }
}
