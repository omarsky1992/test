<?php

namespace App\Filament\Resources\DeviceSales;

use App\Enums\DocumentStatus;
use App\Enums\MoneyAccountKind;
use App\Filament\Actions\Operations;
use App\Filament\Resources\DeviceSales\Pages\ListDeviceSales;
use App\Filament\Resources\MoneyAccounts\MoneyAccountResource;
use App\Models\Account;
use App\Models\DeviceSale;
use App\Models\DeviceType;
use App\Models\MoneyAccount;
use App\Services\TreasuryService;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class DeviceSaleResource extends Resource
{
    protected static ?string $model = DeviceSale::class;

    protected static ?string $slug = 'device-sales';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static string|UnitEnum|null $navigationGroup = 'المالية';

    protected static ?int $navigationSort = 7;

    protected static ?string $modelLabel = 'بيع';

    protected static ?string $pluralModelLabel = 'مبيعات الأجهزة';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['itemType', 'subscriber', 'moneyAccount', 'creator']))
            ->defaultSort('sold_at', 'desc')
            ->columns([
                TextColumn::make('number')->label('الرقم')->weight('bold'),
                TextColumn::make('sold_at')->label('التاريخ')->dateTime('Y/m/d H:i'),
                TextColumn::make('itemType.name_ar')->label('النوع')->badge(),
                TextColumn::make('description')->label('الوصف')->wrap(),
                TextColumn::make('buyer')->label('المشتري')->state(fn (DeviceSale $r) => $r->subscriber?->full_name ?? $r->buyer_name ?? '—'),
                TextColumn::make('total_amount')->label('المبلغ')->formatStateUsing(fn ($state) => Money::format($state, false))
                    ->summarize(Sum::make()->label('المجموع')->query(fn ($query) => $query->where('status', DocumentStatus::Posted))->formatStateUsing(fn ($state) => Money::format((int) $state))),
                TextColumn::make('profit')->label('الربح')
                    ->state(fn (DeviceSale $r) => $r->cost_amount === null ? null : $r->total_amount - $r->cost_amount)
                    ->formatStateUsing(fn ($state) => Money::format((int) $state, false))->placeholder('—'),
                TextColumn::make('settlement')->label('التسوية')->badge()
                    ->formatStateUsing(fn ($state) => $state === 'paid' ? 'مدفوع' : 'دين')
                    ->color(fn ($state) => $state === 'paid' ? 'success' : 'warning'),
                TextColumn::make('status')->label('الحالة')->badge(),
            ])
            ->recordActions([
                Action::make('void')->label('إلغاء')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                    ->authorize('sales.void')
                    ->visible(fn (DeviceSale $r) => $r->status === DocumentStatus::Posted)
                    ->requiresConfirmation()
                    ->schema([Textarea::make('reason')->label('السبب')->required()])
                    ->action(fn (array $data, DeviceSale $record, Action $action) => MoneyAccountResource::run($action, fn () => app(TreasuryService::class)->voidDeviceSale($record, $data['reason']))),
            ]);
    }

    public static function recordAction(): Action
    {
        return Action::make('newSale')->label('تسجيل بيع')->icon(Heroicon::OutlinedPlus)
            ->authorize('sales.create')
            ->fillForm(fn () => ['settlement' => 'paid', 'quantity' => 1, 'money_account_id' => MoneyAccount::where('kind', MoneyAccountKind::Cash)->value('id')])
            ->schema([
                Grid::make(2)->schema([
                    Select::make('item_type_id')->label('النوع')->options(fn () => DeviceType::where('is_active', true)->pluck('name_ar', 'id'))->required(),
                    TextInput::make('description')->label('الوصف')->required()->placeholder('مثال: راوتر TP-Link'),
                    TextInput::make('unit_price')->label('سعر البيع')->integer()->minValue(1)->suffix('د.ع')->required(),
                    TextInput::make('quantity')->label('الكمية')->integer()->minValue(1)->required(),
                    TextInput::make('cost')->label('الكلفة (سعر الشراء)')->integer()->minValue(0)->suffix('د.ع')
                        ->helperText('لحساب الربح. اختياري.'),
                    TextInput::make('serial')->label('السيريال')->extraInputAttributes(['dir' => 'ltr']),
                    ToggleButtons::make('settlement')->label('التسوية')->options(['paid' => 'مدفوع الآن', 'debt' => 'دين على مشترك'])->inline()->required()->live(),
                    Select::make('money_account_id')->label('استُلم في')
                        ->options(fn () => MoneyAccount::where('is_active', true)->where('kind', '<>', MoneyAccountKind::Company)->pluck('name', 'id'))
                        ->visible(fn (Get $get) => $get('settlement') === 'paid')->required(fn (Get $get) => $get('settlement') === 'paid'),
                    Operations::accountField()->label('حساب المشترك')->required(fn (Get $get) => $get('settlement') === 'debt'),
                    TextInput::make('buyer_name')->label('اسم المشتري (إن لم يكن مشتركاً)')->visible(fn (Get $get) => $get('settlement') === 'paid'),
                ]),
                Textarea::make('notes')->label('ملاحظات')->rows(2),
            ])
            ->action(fn (array $data, Action $action) => MoneyAccountResource::run($action, fn () => app(TreasuryService::class)->recordDeviceSale(
                type: DeviceType::findOrFail($data['item_type_id']),
                description: $data['description'],
                unitPrice: (int) $data['unit_price'],
                quantity: (int) $data['quantity'],
                cost: filled($data['cost'] ?? null) ? (int) $data['cost'] : null,
                into: $data['settlement'] === 'paid' ? MoneyAccount::findOrFail($data['money_account_id']) : null,
                account: filled($data['account_id'] ?? null) ? Account::find($data['account_id']) : null,
                buyerName: $data['buyer_name'] ?? null,
                serial: $data['serial'] ?? null,
                notes: $data['notes'] ?? null,
            )));
    }

    public static function getPages(): array
    {
        return ['index' => ListDeviceSales::route('/')];
    }
}
