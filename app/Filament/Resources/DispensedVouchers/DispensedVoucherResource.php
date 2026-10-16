<?php

namespace App\Filament\Resources\DispensedVouchers;

use App\Filament\Resources\DispensedVouchers\Pages\ListDispensedVouchers;
use App\Models\User;
use App\Models\VoucherCard;
use App\Services\VoucherStock;
use App\Support\Money;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * «سجل الكروت المصروفة»: every card taken from stock, when, by whom. The cards stay in the database;
 * only their status changed.
 */
class DispensedVoucherResource extends Resource
{
    protected static ?string $model = VoucherCard::class;

    protected static ?string $slug = 'card-dispense-log';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'العمليات اليومية';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'كارت مصروف';

    protected static ?string $pluralModelLabel = 'سجل الكروت المصروفة';

    public static function canViewAny(): bool
    {
        return auth()->user()->can('stock.history');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('status', 'activated')->with('activator');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('activated_at', 'desc')
            ->searchPlaceholder('رقم الكارت')
            ->columns([
                TextColumn::make('id')->label('المرجع')->formatStateUsing(fn ($state) => "#{$state}"),
                TextColumn::make('code')->label('الكود')->extraAttributes(['dir' => 'ltr'])->fontFamily('mono')
                    ->formatStateUsing(fn (VoucherCard $r) => $r->visibleCode())
                    ->searchable(query: fn (Builder $query, string $search) => $query->where('code', VoucherStock::normalize($search))),
                TextColumn::make('type')->label('النوع')->badge()->formatStateUsing(fn (string $state) => VoucherCard::TYPES[$state] ?? $state),
                TextColumn::make('denomination')->label('الفئة')->formatStateUsing(fn ($state) => Money::format((int) $state))->sortable(),
                TextColumn::make('activated_at')->label('وقت الصرف')->dateTime('Y/m/d H:i')->sortable(),
                TextColumn::make('status')->label('الحالة')->badge()->color('gray')->formatStateUsing(fn (string $state) => VoucherCard::STATUSES[$state] ?? $state),
                TextColumn::make('activator.name')->label('المستخدم')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('type')->label('النوع')->options(VoucherCard::TYPES),
                SelectFilter::make('denomination')->label('الفئة')
                    ->options(fn () => VoucherCard::query()->distinct()->orderBy('denomination')->pluck('denomination')
                        ->mapWithKeys(fn ($d) => [$d => Money::format((int) $d)])->all()),
                SelectFilter::make('activated_by')->label('المستخدم')->options(fn () => User::orderBy('name')->pluck('name', 'id')),
                Filter::make('period')->label('الفترة')->schema([DatePicker::make('from')->label('من'), DatePicker::make('until')->label('إلى')])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->where('activated_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->where('activated_at', '<', now()->parse($d)->addDay()))),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListDispensedVouchers::route('/')];
    }
}
