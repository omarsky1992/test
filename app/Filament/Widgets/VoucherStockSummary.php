<?php

namespace App\Filament\Widgets;

use App\Services\VoucherStock;
use Filament\Widgets\Widget;

/** The stock by type and denomination: all, available, activated. */
class VoucherStockSummary extends Widget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.voucher-stock-summary';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        return ['rows' => app(VoucherStock::class)->summary()];
    }
}
