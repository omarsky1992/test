<?php

namespace App\Filament\Resources\DispensedVouchers\Pages;

use App\Filament\Resources\DispensedVouchers\DispensedVoucherResource;
use Filament\Resources\Pages\ListRecords;

class ListDispensedVouchers extends ListRecords
{
    protected static string $resource = DispensedVoucherResource::class;
}
