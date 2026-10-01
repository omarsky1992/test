<?php

namespace App\Filament\Actions;

use App\Enums\MoneyAccountKind;
use App\Exceptions\BusinessRuleException;
use App\Models\EmployeeAdvance;
use App\Models\MoneyAccount;
use App\Models\User;
use App\Services\EmployeeFinance;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

/**
 * Custody handover, advances and advance repayments, shared by the employee screens.
 */
class EmployeeActions
{
    public static function handOver(): Action
    {
        return Action::make('handOver')
            ->label('تسديد العهدة للصندوق')
            ->icon(Heroicon::OutlinedArrowDownOnSquare)
            ->color('success')
            ->authorize('custody.settle')
            ->modalHeading(fn (?Model $record) => $record instanceof User ? "تسديد عهدة {$record->name} للصندوق" : 'تسديد عهدة موظف للصندوق')
            ->modalDescription('تنخفض عهدة الموظف بالمبلغ ويزيد الصندوق المختار بنفس المبلغ. تُسجَّل باسمك وبالتاريخ والوقت.')
            ->fillForm(fn (?Model $record) => [
                'user_id' => $record instanceof User ? $record->id : null,
                'amount' => $record instanceof User ? (app(EmployeeFinance::class)->custodyBalance($record) ?: null) : null,
                'money_account_id' => MoneyAccount::where('kind', MoneyAccountKind::Cash)->where('is_active', true)->orderBy('id')->value('id'),
            ])
            ->schema(fn (?Model $record) => [
                Select::make('user_id')->label('الموظف')->options(fn () => self::employees())->required()->live()
                    ->hidden($record instanceof User)
                    ->afterStateUpdated(fn ($set, $state) => $set('amount', $state ? app(EmployeeFinance::class)->custodyBalance(User::find($state)) : null)),
                TextInput::make('amount')->label('المبلغ المسلَّم')->integer()->minValue(1)->suffix('د.ع')->required()
                    ->helperText(fn ($get) => ($id = $get('user_id')) ? 'العهدة الحالية: '.Money::format(app(EmployeeFinance::class)->custodyBalance(User::find($id))) : null),
                Select::make('money_account_id')->label('استُلم في')->options(fn () => self::boxes())->required(),
                Textarea::make('notes')->label('ملاحظات')->rows(2),
            ])
            ->action(function (array $data, Action $action, ?Model $record) {
                try {
                    $employee = $record instanceof User ? $record : User::findOrFail($data['user_id']);
                    $t = app(EmployeeFinance::class)->handOver($employee, (int) $data['amount'], MoneyAccount::findOrFail($data['money_account_id']), $data['notes'] ?? null);
                    Notification::make()->success()->title("تم تسديد ".Money::format($t->amount)." من عهدة {$employee->name}")->send();
                } catch (BusinessRuleException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();
                }
            });
    }

    public static function giveAdvance(): Action
    {
        return Action::make('giveAdvance')
            ->label('سلفة جديدة')
            ->icon(Heroicon::OutlinedHandRaised)
            ->color('warning')
            ->authorize('advances.manage')
            ->modalHeading(fn (?Model $record) => $record instanceof User ? "سلفة لـ {$record->name}" : 'تسجيل سلفة لموظف')
            ->fillForm(fn (?Model $record) => [
                'user_id' => $record instanceof User ? $record->id : null,
                'money_account_id' => MoneyAccount::where('kind', MoneyAccountKind::Cash)->where('is_active', true)->orderBy('id')->value('id'),
            ])
            ->schema(fn (?Model $record) => [
                Select::make('user_id')->label('الموظف')->options(fn () => self::employees())->required()->hidden($record instanceof User),
                TextInput::make('amount')->label('مبلغ السلفة')->integer()->minValue(1)->suffix('د.ع')->required(),
                DateTimePicker::make('advanced_at')->label('تاريخ السلفة')->seconds(false)->placeholder('الآن')->maxDate(now()),
                TextInput::make('reason')->label('السبب')->required()->maxLength(200),
                Textarea::make('details')->label('التفاصيل')->rows(2),
                Textarea::make('notes')->label('ملاحظات')->rows(2),
                Select::make('money_account_id')->label('صُرفت من')->options(fn () => self::boxes())->required(),
            ])
            ->action(function (array $data, Action $action, ?Model $record) {
                try {
                    $employee = $record instanceof User ? $record : User::findOrFail($data['user_id']);
                    $a = app(EmployeeFinance::class)->giveAdvance(
                        $employee, (int) $data['amount'], $data['reason'], MoneyAccount::findOrFail($data['money_account_id']),
                        $data['details'] ?? null, $data['notes'] ?? null,
                        filled($data['advanced_at'] ?? null) ? \Carbon\CarbonImmutable::parse($data['advanced_at']) : null,
                    );
                    Notification::make()->success()->title("سُجلت السلفة {$a->number} لـ {$employee->name}")->send();
                } catch (BusinessRuleException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();
                }
            });
    }

    public static function repay(): Action
    {
        return Action::make('repay')
            ->label('تسديد')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->authorize('advances.manage')
            ->visible(fn (EmployeeAdvance $record) => $record->balance > 0)
            ->modalHeading(fn (EmployeeAdvance $record) => "تسديد السلفة {$record->number} – {$record->user->name}")
            ->modalDescription(fn (EmployeeAdvance $record) => 'المتبقي: '.Money::format($record->balance).' من '.Money::format($record->amount))
            ->fillForm(fn (EmployeeAdvance $record) => [
                'amount' => $record->balance,
                'money_account_id' => $record->money_account_id,
            ])
            ->schema([
                TextInput::make('amount')->label('مبلغ التسديد')->integer()->minValue(1)->suffix('د.ع')->required(),
                Select::make('money_account_id')->label('استُلم في')->options(fn () => self::boxes())->required(),
                Textarea::make('notes')->label('ملاحظات')->rows(2),
            ])
            ->action(function (EmployeeAdvance $record, array $data, Action $action) {
                try {
                    $r = app(EmployeeFinance::class)->repay($record, (int) $data['amount'], MoneyAccount::findOrFail($data['money_account_id']), $data['notes'] ?? null);
                    Notification::make()->success()->title("سُجل التسديد {$r->number}")->send();
                } catch (BusinessRuleException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();
                }
            });
    }

    /** @return array<int, string> */
    public static function employees(): array
    {
        return User::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<int, string> Company boxes (cash and wallets). */
    public static function boxes(): array
    {
        return MoneyAccount::where('is_active', true)->whereIn('kind', [MoneyAccountKind::Cash, MoneyAccountKind::Electronic])
            ->orderBy('kind')->orderBy('name')->pluck('name', 'id')->all();
    }
}
