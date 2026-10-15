<?php

namespace App\Filament\Resources\CardAccounts\Pages;

use App\Filament\Resources\CardAccounts\CardAccountResource;
use App\Filament\Resources\CardAccounts\CardOperationsResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListCardAccounts extends ListRecords
{
    protected static string $resource = CardAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CardAccountResource::transferAction(),
            Action::make('operations')->label('سجل المناقلات والتصفير')->color('gray')->icon('heroicon-o-queue-list')
                ->url(CardOperationsResource::getUrl()),
        ];
    }
}
