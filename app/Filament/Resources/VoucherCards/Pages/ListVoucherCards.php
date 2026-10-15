<?php

namespace App\Filament\Resources\VoucherCards\Pages;

use App\Filament\Resources\VoucherCards\VoucherCardResource;
use App\Filament\Widgets\VoucherStockSummary;
use Filament\Resources\Pages\ListRecords;

class ListVoucherCards extends ListRecords
{
    protected static string $resource = VoucherCardResource::class;

    protected function getHeaderActions(): array
    {
        return [VoucherCardResource::addAction()];
    }

    protected function getHeaderWidgets(): array
    {
        return [VoucherStockSummary::class];
    }
}
