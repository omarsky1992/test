<?php

namespace App\Filament\Widgets;

use App\Services\ReportService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TodayStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '60s';

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $reports = app(ReportService::class);
        $today = $reports->period(CarbonImmutable::today(), CarbonImmutable::today());
        $balances = $reports->balances();
        $methods = collect($today['collections']['by_method'])->map(fn ($m) => "{$m['label']} ".Money::format($m['total'], false))->join(' · ');

        return [
            Stat::make('مقبوضات اليوم', Money::format($today['collections']['total']))
                ->description($methods ?: "{$today['collections']['count']} عملية")->color('success'),
            Stat::make('تفعيلات اليوم', (string) $today['activations']['count'])
                ->description('بقيمة '.Money::format($today['activations']['value'])),
            Stat::make('رصيد القاصة', Money::format($balances['cash']))
                ->description('المحافظ الإلكترونية '.Money::format($balances['electronic'])),
            Stat::make('الديون الثانوية', Money::format($balances['secondary']))
                ->description("{$balances['secondary_accounts']} حساب")->color('warning'),
            Stat::make('الديون الأولية', Money::format($balances['primary']))
                ->description("{$balances['primary_accounts']} حساب")->color('info'),
            Stat::make('رصيد الشركة', Money::format($balances['company']))
                ->description($balances['company'] < 100000 ? 'منخفض — اشحن الرصيد' : 'متاح للتفعيل')
                ->color($balances['company'] < 100000 ? 'danger' : 'gray'),
        ];
    }
}
