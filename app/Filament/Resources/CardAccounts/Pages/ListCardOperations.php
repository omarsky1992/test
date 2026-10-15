<?php

namespace App\Filament\Resources\CardAccounts\Pages;

use App\Filament\Resources\CardAccounts\CardOperationsResource;
use App\Filament\Widgets\CardResets;
use Filament\Resources\Pages\ListRecords;

class ListCardOperations extends ListRecords
{
    protected static string $resource = CardOperationsResource::class;

    protected function getFooterWidgets(): array
    {
        return [CardResets::class];
    }
}
