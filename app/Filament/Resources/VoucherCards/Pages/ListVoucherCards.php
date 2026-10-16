<?php

namespace App\Filament\Resources\VoucherCards\Pages;

use App\Filament\Pages\VoucherDispense;
use App\Filament\Resources\VoucherCards\VoucherCardResource;
use App\Filament\Widgets\VoucherStockSummary;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListVoucherCards extends ListRecords
{
    protected static string $resource = VoucherCardResource::class;

    protected function getHeaderActions(): array
    {
        return [
            VoucherCardResource::addAction(),
            Action::make('dispense')->label('صرف الكروت (إنشاء)')->icon('heroicon-o-squares-plus')->color('success')
                ->url(VoucherDispense::getUrl())->visible(fn () => VoucherDispense::canAccess()),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [VoucherStockSummary::class];
    }
}
