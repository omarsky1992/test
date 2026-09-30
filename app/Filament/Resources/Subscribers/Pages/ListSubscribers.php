<?php

namespace App\Filament\Resources\Subscribers\Pages;

use App\Filament\Resources\Subscribers\SubscriberResource;
use App\Filament\Pages\ImportSubscribers;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Support\Icons\Heroicon;
use Filament\Resources\Pages\ListRecords;

class ListSubscribers extends ListRecords
{
    protected static string $resource = SubscriberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')->label('استيراد من Excel')->icon(Heroicon::OutlinedArrowUpTray)->color('gray')
                ->url(ImportSubscribers::getUrl())
                ->visible(fn () => ImportSubscribers::canAccess()),
            CreateAction::make()->label('مشترك جديد'),
        ];
    }
}
