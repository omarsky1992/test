<?php

namespace App\Filament\Widgets;

use App\Services\ReportService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

class CollectionsByMethod extends Widget
{
    use InteractsWithPageFilters;

    protected string $view = 'filament.widgets.collections-by-method';

    protected static ?int $sort = 4;

    // Render with the page in one request instead of one request per widget.
    protected static bool $isLazy = false;

    protected function getViewData(): array
    {
        [$from, $to, $label] = ReportService::periodRange($this->pageFilters);
        $rows = app(ReportService::class)->byPaymentMethod($from, $to);

        return ['rows' => $rows, 'total' => array_sum(array_column($rows, 'total')), 'label' => $label];
    }
}
