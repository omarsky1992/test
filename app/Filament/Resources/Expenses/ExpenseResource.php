<?php

namespace App\Filament\Resources\Expenses;

use App\Enums\DocumentStatus;
use App\Enums\MoneyAccountKind;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\MoneyAccounts\MoneyAccountResource;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\MoneyAccount;
use App\Services\TreasuryService;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static string|UnitEnum|null $navigationGroup = 'المالية';

    protected static ?int $navigationSort = 6;

    protected static ?string $modelLabel = 'مصروف';

    protected static ?string $pluralModelLabel = 'المشتريات والمصروفات';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['category', 'moneyAccount', 'creator']))
            ->defaultSort('spent_at', 'desc')
            ->columns([
                TextColumn::make('number')->label('الرقم')->weight('bold'),
                TextColumn::make('spent_at')->label('التاريخ')->dateTime('Y/m/d H:i'),
                TextColumn::make('category.name_ar')->label('الفئة')->badge(),
                TextColumn::make('description')->label('الوصف')->wrap(),
                TextColumn::make('paid_to')->label('الجهة')->placeholder('—'),
                TextColumn::make('moneyAccount.name')->label('من صندوق'),
                TextColumn::make('amount')->label('المبلغ')->formatStateUsing(fn ($state) => Money::format($state, false))
                    ->summarize(Sum::make()->label('المجموع')->query(fn ($query) => $query->where('status', DocumentStatus::Posted))->formatStateUsing(fn ($state) => Money::format((int) $state))),
                TextColumn::make('creator.name')->label('الموظف'),
                TextColumn::make('status')->label('الحالة')->badge(),
            ])
            ->filters([
                SelectFilter::make('category_id')->label('الفئة')->options(fn () => ExpenseCategory::pluck('name_ar', 'id')),
            ])
            ->recordActions([
                Action::make('void')->label('إلغاء')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                    ->authorize('expenses.void')
                    ->visible(fn (Expense $r) => $r->status === DocumentStatus::Posted)
                    ->requiresConfirmation()
                    ->schema([Textarea::make('reason')->label('السبب')->required()])
                    ->action(fn (array $data, Expense $record, Action $action) => MoneyAccountResource::run($action, fn () => app(TreasuryService::class)->voidExpense($record, $data['reason']))),
            ]);
    }

    public static function recordAction(): Action
    {
        return Action::make('newExpense')->label('تسجيل مصروف')->icon(Heroicon::OutlinedPlus)
            ->authorize('expenses.create')
            ->fillForm(fn () => ['money_account_id' => MoneyAccount::where('kind', MoneyAccountKind::Cash)->value('id')])
            ->schema([
                Select::make('category_id')->label('الفئة')->options(fn () => ExpenseCategory::where('is_active', true)->pluck('name_ar', 'id'))->required(),
                TextInput::make('description')->label('الوصف')->required()->placeholder('مثال: شراء راوتر TP-Link'),
                TextInput::make('amount')->label('المبلغ')->integer()->minValue(1)->suffix('د.ع')->required(),
                Select::make('money_account_id')->label('دُفع من')->options(fn () => MoneyAccount::where('is_active', true)->pluck('name', 'id'))->required(),
                TextInput::make('paid_to')->label('الجهة المستلمة'),
                Textarea::make('notes')->label('ملاحظات')->rows(2),
            ])
            ->action(fn (array $data, Action $action) => MoneyAccountResource::run($action, fn () => app(TreasuryService::class)->recordExpense(
                ExpenseCategory::findOrFail($data['category_id']), $data['description'], (int) $data['amount'], MoneyAccount::findOrFail($data['money_account_id']),
                $data['paid_to'] ?? null, $data['notes'] ?? null, auth()->user()->can('cash.allow_negative'),
            )));
    }

    public static function getPages(): array
    {
        return ['index' => ListExpenses::route('/')];
    }
}
