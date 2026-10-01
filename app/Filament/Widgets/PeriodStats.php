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

    // Render with the page in one request instead of one request per widget.
    protected static bool $isLazy = false;

    // No auto-refresh every 5 seconds; it loads the small free server and keeps the database awake.
    protected ?string $pollingInterval = null;

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
                ->color('success')
                ->url(BalanceOverview::url(\App\Filament\Resources\Payments\PaymentResource::class)),
            Stat::make('المبيعات', Money::format($d['sales']))
                ->description("تفعيلات {$d['activations_count']} بقيمة ".Money::format($d['activation_sales'], false).' · أجهزة '.Money::format($d['device_sales'], false))
                ->descriptionIcon('heroicon-m-bolt')
                ->url(BalanceOverview::url(\App\Filament\Resources\Activations\ActivationResource::class)),
            Stat::make('المشتريات', Money::format($d['purchases']))
                ->description('مصروفات '.Money::format($d['expenses'], false).' · شحن رصيد الشركة '.Money::format($d['company_topups'], false))
                ->descriptionIcon('heroicon-m-shopping-cart')
                ->url(BalanceOverview::url(\App\Filament\Resources\Expenses\ExpenseResource::class)),
        ];
    }
}
