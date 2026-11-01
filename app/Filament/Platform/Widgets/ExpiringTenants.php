<?php

namespace App\Filament\Platform\Widgets;

use App\Platform\Subscription;
use App\Platform\Tenant;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Clients whose subscription ends soon or has ended, nearest first. */
class ExpiringTenants extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'اشتراكات تنتهي قريباً أو انتهت';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Tenant::query()->where('is_permanent', false)->where('status', 'active')
                ->where(fn ($q) => $q->where('subscription_ends_at', '<=', now()->addDays((int) config('platform.expiring_days')))
                    ->orWhere(fn ($q) => $q->whereNull('subscription_ends_at')->where('trial_ends_at', '<=', now()->addDays((int) config('platform.expiring_days'))))))
            ->defaultSort('subscription_ends_at')
            ->paginated([10])
            ->emptyStateHeading('لا يوجد اشتراك ينتهي قريباً')
            ->columns([
                TextColumn::make('name')->label('العميل')->weight('bold'),
                TextColumn::make('phone')->label('الهاتف')->placeholder('—'),
                TextColumn::make('ends')->label('ينتهي')->state(fn (Tenant $t) => Subscription::stopsAt($t)?->setTimezone(Subscription::tz())->format('Y/m/d') ?? '—'),
                TextColumn::make('state')->label('الحالة')->badge()
                    ->state(fn (Tenant $t) => Subscription::state($t))
                    ->formatStateUsing(fn (string $state) => Subscription::STATES[$state] ?? $state)
                    ->color(fn (string $state) => Subscription::COLORS[$state] ?? 'gray'),
            ]);
    }
}
