<?php

namespace App\Filament\Resources\EmployeeAdvances;

use App\Enums\AdvanceStatus;
use App\Filament\Actions\EmployeeActions;
use App\Filament\Resources\EmployeeAdvances\Pages\ListEmployeeAdvances;
use App\Models\EmployeeAdvance;
use App\Support\Money;
use App\Support\Options;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use UnitEnum;

class EmployeeAdvanceResource extends Resource
{
    protected static ?string $model = EmployeeAdvance::class;

    protected static ?string $slug = 'advances';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHandRaised;

    protected static string|UnitEnum|null $navigationGroup = 'الموظفون';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'سلفة';

    protected static ?string $pluralModelLabel = 'السلف والقروض';

    public static function canViewAny(): bool
    {
        return auth()->user()->can('employees.view') || auth()->user()->can('advances.manage');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['user', 'creator', 'moneyAccount']))
            ->defaultSort('advanced_at', 'desc')
            ->columns([
                TextColumn::make('number')->label('الرقم')->searchable(),
                TextColumn::make('user.name')->label('الموظف')->weight('bold')->searchable(),
                TextColumn::make('advanced_at')->label('التاريخ')->dateTime('Y/m/d H:i')->sortable(),
                TextColumn::make('amount')->label('المبلغ')->formatStateUsing(fn ($state) => Money::format($state)),
                TextColumn::make('paid_amount')->label('المسدد')->formatStateUsing(fn ($state) => Money::format($state)),
                TextColumn::make('balance')->label('المتبقي')->formatStateUsing(fn ($state) => Money::format($state))->weight('bold'),
                TextColumn::make('status')->label('الحالة')->badge(),
                TextColumn::make('reason')->label('السبب')->limit(40)->tooltip(fn (EmployeeAdvance $r) => $r->details),
                TextColumn::make('moneyAccount.name')->label('صُرفت من')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('creator.name')->label('سجّلها')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('user_id')->label('الموظف')->relationship('user', 'name'),
                SelectFilter::make('status')->label('الحالة')->options(Options::of(AdvanceStatus::class)),
            ])
            ->recordActions([
                EmployeeActions::repay(),
                Action::make('details')->label('التفاصيل')->icon(Heroicon::OutlinedEye)->color('gray')
                    ->modalHeading(fn (EmployeeAdvance $r) => "السلفة {$r->number}")
                    ->modalSubmitAction(false)->modalCancelActionLabel('إغلاق')
                    ->modalContent(fn (EmployeeAdvance $record): View => view('filament.advances.details', ['advance' => $record->load('repayments.creator', 'repayments.moneyAccount')])),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListEmployeeAdvances::route('/')];
    }
}
