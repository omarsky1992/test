<?php

namespace App\Filament\Widgets;

use App\Filament\Actions\Operations;
use App\Filament\Resources\Activations\ActivationResource;
use App\Filament\Resources\FollowUps\FollowUpResource;
use App\Filament\Resources\Subscribers\SubscriberResource;
use App\Models\Activation;
use App\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class PendingFollowUps extends TableWidget
{
    protected static ?int $sort = 6;

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
                TextColumn::make('subscriber.full_name')->label('المشترك')->weight('bold')
                    ->url(fn (Activation $r) => SubscriberResource::getUrl('view', ['record' => $r->subscriber_id])),
                TextColumn::make('account.username')->label('اليوزر')->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('plan.name_ar')->label('الفئة'),
                TextColumn::make('debt.balance')->label('المبلغ')->formatStateUsing(fn ($state) => Money::format((int) $state, false)),
                TextColumn::make('ends_at')->label('ينتهي')->dateTime('Y/m/d H:i'),
                TextColumn::make('time_status')->label('الوضع')->badge()
                    ->state(fn (Activation $r) => ActivationResource::timeStatus($r)[0])
                    ->color(fn (Activation $r) => ActivationResource::timeStatus($r)[1]),
            ])
            ->recordActions([Operations::followUp(), Operations::pay(), Operations::transfer()]);
    }
}
