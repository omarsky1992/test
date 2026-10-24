<?php

namespace App\Filament\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\ActivationReminder;
use App\Models\Subscriber;
use App\Models\WhatsappNumber;
use App\Services\ActivationReminderService;
use App\Support\SubscriberStatus;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;

/**
 * «تذكير تفعيل 7 أيام» on the subscriber page: chosen staff numbers get «فعّل فلان 7 أيام» right
 * after the subscription ends; and the button to cancel one still waiting.
 */
class ActivationReminderActions
{
    public static function set(Subscriber $subscriber): Action
    {
        $accounts = fn () => $subscriber->accounts()->orderBy('id')->get();

        return Action::make('activationReminder')->label('تذكير تفعيل 7 أيام')->icon(Heroicon::OutlinedBellAlert)->color('warning')
            ->visible(fn () => auth()->user()->can('reminders.activation') && $accounts()->isNotEmpty())
            ->modalHeading('تذكير تفعيل 7 أيام: '.$subscriber->full_name)
            ->fillForm(fn () => ['account_id' => $accounts()->first()?->id])
            ->schema([
                Select::make('account_id')->label('الحساب')->required()->selectablePlaceholder(false)
                    ->options(fn () => $accounts()->mapWithKeys(fn ($a) => [$a->id => $a->username.(($e = SubscriberStatus::endsAt($a)) ? ' – ينتهي '.CarbonImmutable::instance($e)->setTimezone(config('app.timezone'))->format('Y/m/d H:i') : '')])->all())
                    ->visible(fn () => $accounts()->count() > 1),
                Select::make('numbers')->label('يصل التنبيه إلى')->multiple()->placeholder('كل الأرقام المخولة')
                    ->helperText('اختر موظفاً أو أكثر، أو اتركه فارغاً ليصل لكل الأرقام المخولة.')
                    ->options(fn () => WhatsappNumber::with('user')->where('is_active', true)->get()
                        ->mapWithKeys(fn (WhatsappNumber $n) => [$n->id => trim(($n->label ?: $n->user?->name ?: 'رقم').' – '.$n->phone)])->all()),
                Text::make(function () use ($accounts, $subscriber) {
                    $account = $accounts()->first();
                    $ends = $account ? SubscriberStatus::endsAt($account) : null;

                    return $ends && $ends->getTimestamp() > now()->getTimestamp()
                        ? 'يصل التنبيه «فعّل '.$subscriber->full_name.' 7 أيام» مباشرة بعد انتهاء الاشتراك ('.CarbonImmutable::instance($ends)->setTimezone(config('app.timezone'))->format('Y/m/d H:i').'). إذا تجدد قبلها ينتظر النهاية الجديدة.'
                        : 'الاشتراك منتهٍ الآن: يصل التنبيه خلال دقيقة.';
                }),
            ])
            ->modalSubmitActionLabel('تفعيل التذكير')
            ->action(function (array $data, Action $action) use ($accounts) {
                $account = $accounts()->firstWhere('id', (int) ($data['account_id'] ?? 0)) ?? $accounts()->first();
                try {
                    $reminder = app(ActivationReminderService::class)->set($account, $data['numbers'] ?? null);
                } catch (BusinessRuleException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();

                    return;
                }
                Notification::make()->success()->title('⏰ فُعّل التذكير')
                    ->body('يصل إلى: '.$reminder->numbersLabel().' بعد انتهاء اشتراك '.$account->username)->send();
            });
    }

    public static function cancel(Subscriber $subscriber): Action
    {
        $pending = fn () => ActivationReminder::with('account')->where('subscriber_id', $subscriber->id)->whereNull('sent_at')->whereNull('cancelled_at')->latest('id')->first();

        return Action::make('cancelActivationReminder')
            ->label(fn () => '⏰ تذكير مفعّل'.(($r = $pending()) ? ' ('.$r->account?->username.')' : '').' – إلغاء')
            ->icon(Heroicon::OutlinedBellSlash)->color('gray')
            ->visible(fn () => auth()->user()->can('reminders.activation') && $pending() !== null)
            ->requiresConfirmation()
            ->modalHeading('إلغاء تذكير التفعيل')
            ->modalDescription(fn () => ($r = $pending()) ? 'يصل إلى: '.$r->numbersLabel().'. ألغيه؟' : null)
            ->action(function () use ($pending) {
                if ($r = $pending()) {
                    app(ActivationReminderService::class)->cancel($r);
                }
                Notification::make()->success()->title('أُلغي التذكير')->send();
            });
    }
}
