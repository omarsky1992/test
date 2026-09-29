<?php

namespace App\Filament\Resources\Subscribers\Pages;

use App\Filament\Actions\Operations;
use App\Filament\Resources\Debts\DebtResource;
use App\Filament\Resources\Subscribers\SubscriberResource;
use Filament\Actions\Action;
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
            Operations::activate(),
            Operations::pay(),
            DebtResource::manualDebtAction($this->getRecord()->accounts()->count() === 1 ? $this->getRecord()->accounts()->first() : null),
            Action::make('call')->label('اتصال')->icon(Heroicon::OutlinedPhone)->color('gray')
                ->url(fn () => 'tel:'.$this->getRecord()->phone),
            Action::make('whatsapp')->label('واتساب')->icon(Heroicon::OutlinedChatBubbleLeftRight)->color('gray')
                ->url(fn () => 'https://wa.me/'.$this->getRecord()->phone_normalized)->openUrlInNewTab(),
            EditAction::make(),
        ];
    }
}
