<?php

namespace App\Filament\Resources\CompanySettlements;

use App\Enums\DistributionKind;
use App\Enums\MoneyAccountKind;
use App\Filament\Resources\CompanySettlements\Pages\ListCompanySettlements;
use App\Filament\Resources\MoneyAccounts\MoneyAccountResource;
use App\Models\CompanySettlement;
use App\Models\MoneyAccount;
use App\Services\TreasuryService;
use App\Support\Money;
use BackedEnum;
use App\Support\Options;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class CompanySettlementResource extends Resource
{
    protected static ?string $model = CompanySettlement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static string|UnitEnum|null $navigationGroup = 'المالية';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'الراجع من الشركة';

    protected static ?string $modelLabel = 'راجع';

    protected static ?string $pluralModelLabel = 'الراجع من الشركة';

    public static function canViewAny(): bool
    {
        return auth()->user()->can('settlements.manage');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withSum('distributions as distributed', 'amount')->with('moneyAccount'))
            ->defaultSort('received_at', 'desc')
            ->columns([
                TextColumn::make('number')->label('الرقم')->weight('bold'),
                TextColumn::make('period')->label('الفترة')->state(fn (CompanySettlement $r) => $r->period_from->format('Y/m/d').' – '.$r->period_to->format('Y/m/d')),
                TextColumn::make('activations_count')->label('التفعيلات في الفترة'),
                TextColumn::make('amount')->label('المبلغ')->formatStateUsing(fn ($state) => Money::format($state))->weight('bold'),
                TextColumn::make('moneyAccount.name')->label('دخل إلى'),
                TextColumn::make('undistributed')->label('غير موزع')
                    ->state(fn (CompanySettlement $r) => $r->amount - (int) $r->distributed)
                    ->formatStateUsing(fn ($state) => Money::format((int) $state)),
                TextColumn::make('received_at')->label('التاريخ')->dateTime('Y/m/d'),
            ])
            ->recordActions([
                Action::make('distribute')->label('تقسيم')->icon(Heroicon::OutlinedChartPie)
                    ->fillForm(fn (CompanySettlement $record) => ['kind' => DistributionKind::ZoneFund->value, 'from' => $record->received_into_money_account_id])
                    ->schema([
                        ToggleButtons::make('kind')->label('إلى')->options(Options::of(DistributionKind::class))->inline()->required()->live(),
                        TextInput::make('amount')->label('المبلغ')->integer()->minValue(1)->suffix('د.ع')->required(),
                        Select::make('from')->label('من صندوق')->options(fn () => MoneyAccount::where('is_active', true)->pluck('name', 'id'))->required(),
                        Select::make('to')->label('صندوق الزون')
                            ->options(fn () => MoneyAccount::where('is_active', true)->where('kind', MoneyAccountKind::Cash)->pluck('name', 'id'))
                            ->visible(fn (Get $get) => $get('kind') === DistributionKind::ZoneFund->value)->required(fn (Get $get) => $get('kind') === DistributionKind::ZoneFund->value),
                        TextInput::make('beneficiary')->label('الاسم (شريك / موظف)')
                            ->visible(fn (Get $get) => $get('kind') !== DistributionKind::ZoneFund->value),
                    ])
                    ->action(fn (array $data, CompanySettlement $record, Action $action) => MoneyAccountResource::run($action, fn () => app(TreasuryService::class)->distribute(
                        $record, DistributionKind::from($data['kind']), (int) $data['amount'], MoneyAccount::findOrFail($data['from']),
                        filled($data['to'] ?? null) ? MoneyAccount::find($data['to']) : null, $data['beneficiary'] ?? null,
                    ))),
            ]);
    }

    public static function recordAction(): Action
    {
        return Action::make('newSettlement')->label('تسجيل راجع')->icon(Heroicon::OutlinedPlus)
            ->fillForm(fn () => [
                'period_from' => now()->subMonth()->startOfMonth()->toDateString(),
                'period_to' => now()->subMonth()->endOfMonth()->toDateString(),
                'into' => MoneyAccount::where('kind', MoneyAccountKind::Cash)->value('id'),
            ])
            ->schema([
                TextInput::make('amount')->label('مبلغ الراجع')->integer()->minValue(1)->suffix('د.ع')->required(),
                DatePicker::make('period_from')->label('من تاريخ')->required(),
                DatePicker::make('period_to')->label('إلى تاريخ')->required()->afterOrEqual('period_from'),
                Select::make('into')->label('دخل إلى')->options(fn () => MoneyAccount::where('is_active', true)->pluck('name', 'id'))->required()
                    ->helperText('القاصة إذا استلمتموه نقداً، أو رصيد الشركة إذا أضافته الشركة إلى رصيدكم.'),
                Textarea::make('notes')->label('ملاحظات')->rows(2),
            ])
            ->action(fn (array $data, Action $action) => MoneyAccountResource::run($action, fn () => app(TreasuryService::class)->recordSettlement(
                (int) $data['amount'], $data['period_from'], $data['period_to'], MoneyAccount::findOrFail($data['into']), $data['notes'] ?? null,
            )));
    }

    public static function getPages(): array
    {
        return ['index' => ListCompanySettlements::route('/')];
    }
}
