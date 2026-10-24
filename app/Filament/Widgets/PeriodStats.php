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
        return 'المقبوضات: '.ReportService::periodRange($this->pageFilters)[2].' · المبيعات والمشتريات والربح: الكلي منذ آخر تصفير';
    }

    protected function getStats(): array
    {
        [$from, $to] = ReportService::periodRange($this->pageFilters);
        $d = app(ReportService::class)->dashboard($from, $to);
        // Sales, purchases and «الربح والخسارة» are running totals since the last «تصفير» of each
        // (whatever the period filter): they only go back to zero when someone presses «تصفير».
        $periods = app(AccountingPeriods::class);
        // The end is excluded: up to the next second, so what was recorded just now is counted.
        $now = CarbonImmutable::now()->addSecond();
        $p = $periods->totals($periods->lastReset('purchases'), $now, exact: true);
        $s = $periods->totals($periods->lastReset('sales'), $now, exact: true);
        $d = array_merge($d, [
            'sales' => $s['sales'], 'device_sales' => $s['sales'], 'purchases' => $p['purchases'], 'expenses' => $p['purchases'],
            'profit' => $s['sales'] - $p['purchases'],
        ]);

        return [
            Stat::make('المقبوضات', Money::format($d['collections']))
                ->description("{$d['receipts_count']} سند قبض")
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success')
                ->url(BalanceOverview::url(PaymentResource::class)),
            Stat::make('المبيعات', Money::format($d['sales']))
                ->description('الكلي منذ آخر تصفير · أجهزة ومواد وكارتات (بدون تفعيلات الإنترنت)')
                ->descriptionIcon('heroicon-m-shopping-bag')
                ->url(BalanceOverview::url(DeviceSaleResource::class)),
            Stat::make('المشتريات', Money::format($d['purchases']))
                ->description('الكلي منذ آخر تصفير · المشتريات والمصروفات من كل الصناديق والعهد')
                ->descriptionIcon('heroicon-m-shopping-cart')
                ->url(BalanceOverview::url(ExpenseResource::class)),
            Stat::make('الربح والخسارة', ($d['profit'] > 0 ? '+' : '').Money::format($d['profit']))
                ->description('منذ آخر تصفير · '.($d['profit'] < 0 ? 'خسارة: المشتريات أكثر من المبيعات' : ($d['profit'] > 0 ? 'ربح: المبيعات أكثر من المشتريات' : 'لا ربح ولا خسارة')))
                ->descriptionIcon($d['profit'] < 0 ? 'heroicon-m-arrow-trending-down' : 'heroicon-m-arrow-trending-up')
                ->color($d['profit'] < 0 ? 'danger' : ($d['profit'] > 0 ? 'success' : 'gray'))
                ->extraAttributes(['data-test' => 'profit']),
        ];
    }
}
