<?php

namespace App\Filament\Resources\MoneyAccounts;

use App\Enums\MoneyAccountKind;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\MoneyAccounts\Pages\ListMoneyAccounts;
use App\Models\MoneyAccount;
use App\Services\TreasuryService;
use App\Support\Money;
use BackedEnum;
use Closure;
use App\Support\Options;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class MoneyAccountResource extends Resource
{
    protected static ?string $model = MoneyAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected static string|UnitEnum|null $navigationGroup = 'المالية';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'الصناديق ورصيد الشركة';

    protected static ?string $modelLabel = 'صندوق';

    protected static ?string $pluralModelLabel = 'الصناديق ورصيد الشركة';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('الصندوق')->weight('bold'),
                TextColumn::make('kind')->label('النوع')->badge(),
                TextColumn::make('holder_name')->label('المستلم')->placeholder('—'),
                TextColumn::make('balance')->label('الرصيد الحالي')->weight('bold')
                    ->state(fn (MoneyAccount $r) => app(TreasuryService::class)->balance($r))
                    ->formatStateUsing(fn ($state) => Money::format((int) $state))
                    ->color(fn ($state) => $state < 0 ? 'danger' : 'success'),
                TextColumn::make('is_active')->label('فعّال')->badge()->formatStateUsing(fn ($state) => $state ? 'نعم' : 'لا'),
            ])
            ->recordActions([
                Action::make('statement')->label('كشف الصندوق')->icon(Heroicon::OutlinedDocumentText)->color('gray')
                    ->visible(fn () => \App\Filament\Pages\MoneyAccountStatement::canAccess())
                    ->url(fn (MoneyAccount $record) => \App\Filament\Pages\MoneyAccountStatement::getUrl(['box' => $record->id])),
                Action::make('opening')->label('رصيد ابتدائي')->icon(Heroicon::OutlinedFlag)->color('gray')
                    ->authorize('cash.opening_balance')
                    ->schema([TextInput::make('amount')->label('المبلغ')->integer()->minValue(1)->suffix('د.ع')->required()])
                    ->action(fn (array $data, MoneyAccount $record, Action $action) => self::run($action, fn () => app(TreasuryService::class)->openingBalance($record, (int) $data['amount']))),
            ]);
    }

    public static function newBoxAction(): Action
    {
        return Action::make('newBox')->label('صندوق أو محفظة جديدة')->icon(Heroicon::OutlinedPlus)
            ->authorize('money_accounts.manage')
            ->schema([
                ToggleButtons::make('kind')->label('النوع')->options(Options::of(MoneyAccountKind::class))->inline()->required(),
                TextInput::make('name')->label('الاسم')->required()->placeholder('مثال: زين كاش – أحمد'),
                TextInput::make('holder_name')->label('اسم المستلم'),
            ])
            ->action(fn (array $data, Action $action) => self::run($action, fn () => app(TreasuryService::class)->createMoneyAccount(
                auth()->user()->branch_id, MoneyAccountKind::from($data['kind']), $data['name'], $data['holder_name'] ?? null,
            )));
    }

    public static function transferAction(): Action
    {
        $boxes = fn () => MoneyAccount::where('is_active', true)->pluck('name', 'id');

        return Action::make('transferFunds')->label('تحويل / شحن رصيد الشركة')->icon(Heroicon::OutlinedArrowsRightLeft)->color('info')
            ->authorize('funds.transfer')
            ->fillForm(fn () => [
                'from' => MoneyAccount::where('kind', MoneyAccountKind::Cash)->value('id'),
                'to' => MoneyAccount::where('kind', MoneyAccountKind::Company)->value('id'),
            ])
            ->schema([
                Select::make('from')->label('من')->options($boxes)->required(),
                Select::make('to')->label('إلى')->options($boxes)->required()->different('from'),
                TextInput::make('amount')->label('المبلغ')->integer()->minValue(1)->suffix('د.ع')->required(),
                TextInput::make('reference')->label('رقم عملية الشحن'),
                Textarea::make('notes')->label('ملاحظات')->rows(2),
            ])
            ->action(fn (array $data, Action $action) => self::run($action, fn () => app(TreasuryService::class)->transfer(
                MoneyAccount::findOrFail($data['from']), MoneyAccount::findOrFail($data['to']), (int) $data['amount'], $data['reference'] ?? null, $data['notes'] ?? null,
            )));
    }

    public static function run(Action $action, Closure $callback): void
    {
        try {
            $callback();
            Notification::make()->success()->title('تم الحفظ')->send();
        } catch (BusinessRuleException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $action->halt();
        }
    }

    public static function getPages(): array
    {
        return ['index' => ListMoneyAccounts::route('/')];
    }
}
