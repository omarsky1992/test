<?php

namespace App\Filament\Resources\SecondarySales\Pages;

use App\Filament\Resources\SecondarySales\SecondarySaleResource;
use Filament\Resources\Pages\ListRecords;

class ListSecondarySales extends ListRecords
{
    protected static string $resource = SecondarySaleResource::class;

    protected function getHeaderActions(): array
    {
        return [SecondarySaleResource::recordAction(), SecondarySaleResource::resetAction()];
    }
}
