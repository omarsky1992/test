<?php

namespace App\Filament\Widgets;

use App\Filament\Actions\Operations;
use App\Filament\Resources\Activations\ActivationResource;
use App\Filament\Resources\FollowUps\FollowUpResource;
use App\Models\Activation;
use App\Support\Money;
use App\Support\SubscriberFlags;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class PendingFollowUps extends TableWidget
{
    protected static ?int $sort = 6;

    // Render with the page in one request instead of one request per widget.
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'متابعة الـ7 أيام: منتهية أو تنتهي اليوم وغداً';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => FollowUpResource::getEloquentQuery()->with(['subscriber', 'account', 'plan', 'debt'])
                ->where('ends_at', '<', now()->addDay()->endOfDay())->orderBy('ends_at'))
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->columns([
                SubscriberFlags::mark(TextColumn::make('subscriber.full_name')->label('المشترك')->weight('bold')
                    ->url(fn (Activation $r) => SubscriberFlags::subscriberUrl($r->subscriber_id)), fn (Activation $r) => $r->subscriber_id),
                TextColumn::make('account.username')->label('اليوزر')->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('plan.name_ar')->label('الفئة'),
                TextColumn::make('debt.balance')->label('المبلغ')->formatStateUsing(fn ($state) => Money::format((int) $state, false)),
                TextColumn::make('ends_at')->label('ينتهي')->dateTime('Y/m/d H:i'),
                TextColumn::make('time_status')->label('الوضع')->badge()
                    ->state(fn (Activation $r) => ActivationResource::timeStatus($r)[0])
                    ->color(fn (Activation $r) => ActivationResource::timeStatus($r)[1]),
            ])
            ->headerActions([
                Action::make('allFollowUps')->label('عرض قائمة المتابعة')->link()->icon('heroicon-m-arrow-left')
                    ->url(fn () => BalanceOverview::url(FollowUpResource::class))
                    ->visible(fn () => BalanceOverview::url(FollowUpResource::class) !== null),
            ])
            ->recordActions([Operations::followUp(), Operations::pay(), Operations::transfer()]);
    }
}
