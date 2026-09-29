<?php

namespace App\Filament\Resources\DebtTransfers;

use App\Filament\Resources\DebtTransfers\Pages\ListDebtTransfers;
use App\Filament\Resources\Subscribers\SubscriberResource;
use App\Models\DebtTransfer;
use App\Support\Money;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class DebtTransferResource extends Resource
{
    protected static ?string $model = DebtTransfer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'المالية';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'مناقلة';

    protected static ?string $pluralModelLabel = 'المناقلات';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['subscriber', 'account', 'debt', 'performer']))
            ->defaultSort('performed_at', 'desc')
            ->columns([
                TextColumn::make('number')->label('الرقم')->searchable()->weight('bold'),
                TextColumn::make('performed_at')->label('الوقت')->dateTime('Y/m/d H:i')->sortable(),
                TextColumn::make('subscriber.full_name')->label('المشترك')
                    ->url(fn (DebtTransfer $r) => SubscriberResource::getUrl('view', ['record' => $r->subscriber_id])),
                TextColumn::make('account.username')->label('اليوزر')->searchable()->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('debt.number')->label('الدين'),
                TextColumn::make('amount')->label('المبلغ')->formatStateUsing(fn ($state) => Money::format($state, false))
                    ->summarize(Sum::make()->label('المجموع')->formatStateUsing(fn ($state) => Money::format((int) $state))),
                TextColumn::make('days_added')->label('الأيام المضافة'),
                TextColumn::make('reason')->label('السبب')->placeholder('—')->limit(40),
                TextColumn::make('performer.name')->label('الموظف'),
                TextColumn::make('status')->label('الحالة')->badge(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListDebtTransfers::route('/')];
    }
}
