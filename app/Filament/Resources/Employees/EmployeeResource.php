<?php

namespace App\Filament\Resources\Employees;

use App\Enums\MoneyAccountKind;
use App\Filament\Actions\EmployeeActions;
use App\Filament\Pages\EmployeeStatement;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Models\User;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use UnitEnum;

class EmployeeResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'employees';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'الموظفون';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'موظف';

    protected static ?string $pluralModelLabel = 'الموظفون';

    public static function canViewAny(): bool
    {
        return auth()->user()->can('employees.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->addSelect([
                // Custody = balance of the employee's custody box; advances = what is still owed.
                'custody_balance' => DB::table('ledger_entries')
                    ->join('money_accounts', 'money_accounts.ledger_account_id', '=', 'ledger_entries.ledger_account_id')
                    ->whereColumn('money_accounts.user_id', 'users.id')
                    ->where('money_accounts.kind', MoneyAccountKind::Custody->value)
                    ->selectRaw('coalesce(sum(ledger_entries.debit - ledger_entries.credit), 0)'),
                'advances_balance' => DB::table('employee_advances')->whereColumn('employee_advances.user_id', 'users.id')->selectRaw('coalesce(sum(balance), 0)'),
            ]))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('الموظف')->weight('bold')->searchable(),
                TextColumn::make('username')->label('اسم المستخدم')->extraAttributes(['dir' => 'ltr'])->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('custody_balance')->label('عهدة التحصيل الحالية')->formatStateUsing(fn ($state) => Money::format((int) $state))
                    ->color(fn ($state) => (int) $state > 0 ? 'warning' : null)->sortable(),
                TextColumn::make('advances_balance')->label('السلف المتبقية')->formatStateUsing(fn ($state) => Money::format((int) $state))
                    ->color(fn ($state) => (int) $state > 0 ? 'danger' : null)->sortable(),
                IconColumn::make('collects_to_custody')->label('يستلم في عهدته')->boolean(),
                IconColumn::make('is_active')->label('فعّال')->boolean(),
            ])
            ->recordActions([
                Action::make('statement')->label('كشف الحساب')->icon(Heroicon::OutlinedDocumentText)->color('gray')
                    ->url(fn (User $record) => EmployeeStatement::getUrl(['user' => $record->id])),
                EmployeeActions::handOver(),
                EmployeeActions::giveAdvance(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListEmployees::route('/')];
    }
}
