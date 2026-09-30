<?php

namespace App\Filament\Widgets;

use App\Services\ReportService;
use App\Support\Money;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PeriodStats extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getHeading(): ?string
    {
        return 'حركة الفترة: '.ReportService::periodRange($this->pageFilters)[2];
    }

    protected function getStats(): array
    {
        [$from, $to] = ReportService::periodRange($this->pageFilters);
        $d = app(ReportService::class)->dashboard($from, $to);

        return [
            Stat::make('المقبوضات', Money::format($d['collections']))
                ->description("{$d['receipts_count']} سند قبض")
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),
            Stat::make('المبيعات', Money::format($d['sales']))
                ->description("تفعيلات {$d['activations_count']} بقيمة ".Money::format($d['activation_sales'], false).' · أجهزة '.Money::format($d['device_sales'], false))
                ->descriptionIcon('heroicon-m-bolt'),
            Stat::make('المشتريات', Money::format($d['purchases']))
                ->description('مصروفات '.Money::format($d['expenses'], false).' · شحن رصيد الشركة '.Money::format($d['company_topups'], false))
                ->descriptionIcon('heroicon-m-shopping-cart'),
        ];
    }
}
