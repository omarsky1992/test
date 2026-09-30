<?php

namespace App\Filament\Resources\DeviceSales\Pages;

use App\Filament\Resources\DeviceSales\DeviceSaleResource;
use Filament\Resources\Pages\ListRecords;

class ListDeviceSales extends ListRecords
{
    protected static string $resource = DeviceSaleResource::class;

    protected function getHeaderActions(): array
    {
        return [DeviceSaleResource::recordAction()];
    }
}
