<?php

namespace App\Filament\Resources\Subscribers\Pages;

use App\Filament\Actions\ActivationReminderActions;
use App\Filament\Actions\NoteActions;
use App\Filament\Actions\Operations;
use App\Filament\Resources\Blacklist\BlacklistResource;
use App\Filament\Resources\Debts\DebtResource;
use App\Filament\Resources\Subscribers\SubscriberResource;
use App\Support\SubscriberFlags;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewSubscriber extends ViewRecord
{
    protected static string $resource = SubscriberResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->full_name;
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
            ActionGroup::make([
                BlacklistResource::addAction($this->getRecord()),
            ])->label('المزيد')->button()->color('gray')
                ->visible(fn () => auth()->user()->can('blacklist.manage') && ! SubscriberFlags::blacklisted($this->getRecord()->id)),
            EditAction::make(),
        ];
    }
}
