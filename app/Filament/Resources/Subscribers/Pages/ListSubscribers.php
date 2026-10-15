<?php

namespace App\Filament\Resources\Subscribers\Pages;

use App\Filament\Pages\ImportSubscribers;
use App\Filament\Resources\Subscribers\SubscriberResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListSubscribers extends ListRecords
{
    protected static string $resource = SubscriberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')->label('استيراد من Excel')->icon(Heroicon::OutlinedArrowUpTray)->color('gray')
                ->url(ImportSubscribers::getUrl())
                ->visible(fn () => ImportSubscribers::canAccess()),
            Action::make('export')->label('تصدير Excel')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->url(route('subscribers.export'))
                ->visible(fn () => auth()->user()->can('subscribers.export')),
            CreateAction::make()->label('مشترك جديد'),
        ];
    }
}
