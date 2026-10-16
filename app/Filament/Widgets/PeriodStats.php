<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Activations\ActivationResource;
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
        if (($this->pageFilters['period'] ?? null) === 'open') {
            // Since the last «تصفير» of each: sales and purchases from their own exact moment.
            $periods = app(AccountingPeriods::class);
            $now = CarbonImmutable::now();
            $p = $periods->totals($periods->currentStart('purchases'), $now, exact: true);
            $s = $periods->totals($periods->currentStart('sales'), $now, exact: true);
            $d = array_merge($d, [
                'sales' => $s['sales'], 'activation_sales' => $s['breakdown']['activations']['total'], 'activations_count' => $s['breakdown']['activations']['count'],
                'device_sales' => $s['breakdown']['device_sales']['total'],
                'purchases' => $p['purchases'], 'expenses' => $p['breakdown']['expenses']['total'], 'company_topups' => $p['breakdown']['company_topups']['total'],
            ]);
        }

        return [
            Stat::make('المقبوضات', Money::format($d['collections']))
                ->description("{$d['receipts_count']} سند قبض")
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success')
                ->url(BalanceOverview::url(PaymentResource::class)),
            Stat::make('المبيعات', Money::format($d['sales']))
                ->description("تفعيلات {$d['activations_count']} بقيمة ".Money::format($d['activation_sales'], false).' · أجهزة '.Money::format($d['device_sales'], false))
                ->descriptionIcon('heroicon-m-bolt')
                ->url(BalanceOverview::url(ActivationResource::class)),
            Stat::make('المشتريات', Money::format($d['purchases']))
                ->description('مصروفات '.Money::format($d['expenses'], false).' · شحن رصيد الشركة '.Money::format($d['company_topups'], false))
                ->descriptionIcon('heroicon-m-shopping-cart')
                ->url(BalanceOverview::url(ExpenseResource::class)),
        ];
    }
}
