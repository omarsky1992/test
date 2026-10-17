<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\DeviceSales\DeviceSaleResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Services\AccountingPeriods;
use App\Services\ReportService;
use App\Support\Money;
use Carbon\CarbonImmutable;
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
        return 4;
    }

    protected function getHeading(): ?string
    {
        return 'حركة الفترة: '.ReportService::periodRange($this->pageFilters)[2];
    }

    protected function getStats(): array
    {
        [$from, $to] = ReportService::periodRange($this->pageFilters);
        $d = app(ReportService::class)->dashboard($from, $to);
        if (($this->pageFilters['period'] ?? null) === 'open') {
            // Since the last «تصفير» of each: sales and purchases from their own exact moment.
            $periods = app(AccountingPeriods::class);
            $now = CarbonImmutable::now();
            $p = $periods->totals($periods->currentStart('purchases'), $now, exact: true);
            $s = $periods->totals($periods->currentStart('sales'), $now, exact: true);
            $d = array_merge($d, [
                'sales' => $s['sales'], 'device_sales' => $s['sales'], 'purchases' => $p['purchases'], 'expenses' => $p['purchases'],
                'profit' => $s['sales'] - $p['purchases'],
            ]);
        }

        return [
            Stat::make('المقبوضات', Money::format($d['collections']))
                ->description("{$d['receipts_count']} سند قبض")
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success')
                ->url(BalanceOverview::url(PaymentResource::class)),
            Stat::make('المبيعات', Money::format($d['sales']))
                ->description('أجهزة ومواد وكارتات (بدون تفعيلات الإنترنت)')
                ->descriptionIcon('heroicon-m-shopping-bag')
                ->url(BalanceOverview::url(DeviceSaleResource::class)),
            Stat::make('المشتريات', Money::format($d['purchases']))
                ->description('المصروفات والمشتريات المسجلة')
                ->descriptionIcon('heroicon-m-shopping-cart')
                ->url(BalanceOverview::url(ExpenseResource::class)),
            Stat::make('الربح والخسارة', ($d['profit'] > 0 ? '+' : '').Money::format($d['profit']))
                ->description($d['profit'] < 0 ? 'خسارة: المشتريات أكثر من المبيعات' : ($d['profit'] > 0 ? 'ربح: المبيعات أكثر من المشتريات' : 'لا ربح ولا خسارة'))
                ->descriptionIcon($d['profit'] < 0 ? 'heroicon-m-arrow-trending-down' : 'heroicon-m-arrow-trending-up')
                ->color($d['profit'] < 0 ? 'danger' : ($d['profit'] > 0 ? 'success' : 'gray'))
                ->extraAttributes(['data-test' => 'profit']),
        ];
    }
}
