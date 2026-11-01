<?php

namespace App\Filament\Platform\Widgets;

use App\Platform\Subscription;
use App\Platform\TenantAlert;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** The subscription alerts raised for the clients (7/3/1 days and the end day), newest first. */
class SubscriptionAlertsList extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    protected static ?string $heading = 'تنبيهات الاشتراكات';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => TenantAlert::query()->with('tenant')->where('created_at', '>=', now()->subDays(30)))
            ->defaultSort('created_at', 'desc')
            ->paginated([10])
            ->emptyStateHeading('لا توجد تنبيهات خلال 30 يوماً')
            ->columns([
                TextColumn::make('created_at')->label('التاريخ')->dateTime('Y/m/d H:i'),
                TextColumn::make('tenant.name')->label('العميل')->weight('bold'),
                TextColumn::make('tenant.phone')->label('الهاتف')->placeholder('—'),
                TextColumn::make('days_before')->label('التنبيه')->formatStateUsing(fn ($state, TenantAlert $r) => $r->label()),
                TextColumn::make('ends_at')->label('موعد الانتهاء')->state(fn (TenantAlert $r) => $r->ends_at?->setTimezone(Subscription::tz())->format('Y/m/d')),
            ]);
    }
}
