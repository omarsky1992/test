<?php

namespace App\Filament\Platform\Widgets;

use App\Platform\Subscription;
use App\Platform\Tenant;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** The platform at a glance: clients by state and everyone's subscribers and staff. */
class PlatformOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $tenants = Tenant::all();
        $states = $tenants->map(fn (Tenant $t) => Subscription::state($t))->countBy();
        $live = $tenants->where('status', '<>', 'archived');

        return [
            Stat::make('إجمالي العملاء', $live->count())->description(($states['archived'] ?? 0).' مؤرشف')->color('primary'),
            Stat::make('العملاء النشطون', ($states['active'] ?? 0) + ($states['permanent'] ?? 0) + ($states['trial'] ?? 0) + ($states['expiring'] ?? 0))
                ->description(($states['trial'] ?? 0).' تجريبي')->color('success'),
            Stat::make('الموقوفون', $states['suspended'] ?? 0)->color('gray'),
            Stat::make('اشتراكات منتهية', ($states['expired'] ?? 0) + ($states['grace'] ?? 0))
                ->description(($states['grace'] ?? 0).' في فترة السماح')->color('danger'),
            Stat::make('تنتهي قريباً', $states['expiring'] ?? 0)->description('خلال '.config('platform.expiring_days').' أيام')->color('warning'),
            Stat::make('مشتركو كل العملاء', number_format((int) $live->sum('subscribers_count')))
                ->description('الموظفون: '.number_format((int) $live->sum('users_count')))->color('info'),
        ];
    }
}
