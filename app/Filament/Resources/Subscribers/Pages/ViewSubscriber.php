<?php

namespace App\Filament\Resources\Subscribers\Pages;

use App\Filament\Actions\ActivationReminderActions;
use App\Filament\Actions\NoteActions;
use App\Filament\Actions\Operations;
use App\Filament\Resources\Blacklist\BlacklistResource;
use App\Filament\Resources\Debts\DebtResource;
use App\Filament\Resources\Subscribers\SubscriberResource;
use App\Services\SubscriberService;
use App\Support\SubscriberFlags;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewSubscriber extends ViewRecord
{
    protected static string $resource = SubscriberResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->full_name.($this->getRecord()->whatsapp_muted ? ' 🔕' : '');
    }

    /** «إيقاف رسائل الواتساب» on or off for this subscriber. */
    private function muteAction(): Action
    {
        $muted = fn () => (bool) $this->getRecord()->whatsapp_muted;

        return Action::make('whatsappMute')
            ->label(fn () => $muted() ? 'تشغيل رسائل الواتساب' : 'إيقاف رسائل الواتساب')
            ->icon(fn () => $muted() ? Heroicon::OutlinedBellAlert : Heroicon::OutlinedBellSlash)
            ->color(fn () => $muted() ? 'warning' : 'gray')
            ->visible(fn () => auth()->user()->can('subscribers.update'))
            ->requiresConfirmation()
            ->modalHeading(fn () => $muted() ? 'تشغيل رسائل الواتساب لهذا المشترك؟' : 'إيقاف رسائل الواتساب لهذا المشترك؟')
            ->modalDescription(fn () => $muted()
                ? 'ترجع توصله الرسائل التلقائية (التفعيل، الانتهاء، الديون، الدفعات) من الآن.'
                : 'ما توصله أي رسالة واتساب (تفعيل، انتهاء، دين، دفعة، جماعية أو تذكير) حتى تشغّلها، والرسائل المنتظرة له تُلغى. تقدر تعدّل عليه بدون ما يوصله شي. تنبيهات الموظفين تبقى.')
            ->action(function () use ($muted) {
                app(SubscriberService::class)->setWhatsappMuted($this->getRecord(), ! $muted());
                Notification::make()->success()->title($muted() ? 'أوقفت رسائل الواتساب لهذا المشترك' : 'رجعت رسائل الواتساب لهذا المشترك')->send();
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            NoteActions::open('subscriberNotes', $this->getRecord()),
            Operations::activate(),
            ActivationReminderActions::set($this->getRecord()),
            ActivationReminderActions::cancel($this->getRecord()),
            Operations::pay(),
            DebtResource::manualDebtAction($this->getRecord()->accounts()->count() === 1 ? $this->getRecord()->accounts()->first() : null),
            Action::make('call')->label('اتصال')->icon(Heroicon::OutlinedPhone)->color('gray')
                ->url(fn () => 'tel:'.$this->getRecord()->phone),
            Action::make('whatsapp')->label('واتساب')->icon(Heroicon::OutlinedChatBubbleLeftRight)->color('gray')
                ->url(fn () => 'https://wa.me/'.$this->getRecord()->phone_normalized)->openUrlInNewTab(),
            $this->muteAction(),
            ActionGroup::make([
                BlacklistResource::addAction($this->getRecord()),
            ])->label('المزيد')->button()->color('gray')
                ->visible(fn () => auth()->user()->can('blacklist.manage') && ! SubscriberFlags::blacklisted($this->getRecord()->id)),
            EditAction::make(),
        ];
    }
}
