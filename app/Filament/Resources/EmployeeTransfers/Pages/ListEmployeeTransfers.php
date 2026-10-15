<?php

namespace App\Filament\Resources\EmployeeTransfers\Pages;

use App\Filament\Resources\EmployeeTransfers\EmployeeTransferResource;
use Filament\Resources\Pages\ListRecords;

class ListEmployeeTransfers extends ListRecords
{
    protected static string $resource = EmployeeTransferResource::class;

    protected function getHeaderActions(): array
    {
        return [EmployeeTransferResource::createAction()];
    }
}
