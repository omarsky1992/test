<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\SecondarySales\SecondarySaleResource;
use App\Services\SecondarySaleService;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * «المبيعات الثانوية» on the home page: their own running total since their last «تصفير», apart from
 * the sales, the purchases, the profit and every balance.
 */
class SecondarySalesStat extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()->can('secondary_sales.view');
    }

    protected function getColumns(): int
    {
        return 1;
    }

    protected function getStats(): array
    {
        $t = app(SecondarySaleService::class)->totals();

        return [
            Stat::make('المبيعات الثانوية', Money::format($t['total']))
                ->description("{$t['count']} بيع منذ آخر تصفير · تُحسب وحدها ولا تدخل أي رصيد")
                ->descriptionIcon('heroicon-m-square-2-stack')
                ->color('info')
                ->url(SecondarySaleResource::getUrl())
                ->extraAttributes(['data-test' => 'secondary-sales']),
        ];
    }
}
