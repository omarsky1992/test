<?php

namespace App\Filament\Resources\PaymentMethods;

use App\Enums\MoneyAccountKind;
use App\Enums\PaymentMethod;
use App\Filament\Resources\PaymentMethods\Pages\ManagePaymentMethods;
use App\Models\MoneyAccount;
use App\Models\PaymentMethodType;
use App\Services\Audit;
use App\Support\Options;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Ways money is received. Adding a new wallet or bank here needs no code change.
 */
class PaymentMethodResource extends Resource
{
    protected static ?string $model = PaymentMethodType::class;

    protected static ?string $slug = 'payment-methods';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'الإدارة';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'طريقة دفع';

    protected static ?string $pluralModelLabel = 'طرق الدفع';

    public static function canViewAny(): bool
    {
        return auth()->user()->can('money_accounts.manage');
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        $categories = collect(Options::of(PaymentMethod::class))->except(PaymentMethod::Mixed->value)->all();

        return $schema->columns(2)->components([
            TextInput::make('name_ar')->label('الاسم')->required()->maxLength(80)->placeholder('مثال: زين كاش'),
            TextInput::make('code')->label('الرمز')->required()->maxLength(40)->unique(ignoreRecord: true)
                ->alphaDash()->extraInputAttributes(['dir' => 'ltr'])->placeholder('zain_cash'),
            Select::make('category')->label('النوع')->options($categories)->required(),
            Select::make('money_account_id')->label('الصندوق الافتراضي')
                ->options(fn () => MoneyAccount::where('is_active', true)->where('kind', '<>', MoneyAccountKind::Company)->pluck('name', 'id'))
                ->placeholder('يختاره الموظف عند القبض'),
            Toggle::make('requires_receiver')->label('يتطلب اسم المستلم'),
            Toggle::make('requires_reference')->label('يتطلب رقم العملية'),
            TextInput::make('sort_order')->label('الترتيب')->integer()->default(0),
            Toggle::make('is_active')->label('فعّالة')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name_ar')->label('الطريقة')->weight('bold'),
                TextColumn::make('category')->label('النوع')->badge(),
                TextColumn::make('moneyAccount.name')->label('الصندوق الافتراضي')->placeholder('يختاره الموظف'),
                IconColumn::make('requires_receiver')->label('اسم المستلم')->boolean(),
                IconColumn::make('requires_reference')->label('رقم العملية')->boolean(),
                IconColumn::make('is_active')->label('فعّالة')->boolean(),
            ])
            ->recordActions([
                EditAction::make()->using(function (Model $record, array $data) {
                    $original = $record->getAttributes();
                    $record->update($data);
                    app(Audit::class)->changes('payment_method.updated', $record, $original);

                    return $record;
                }),
            ]);
    }

    public static function createAction(): CreateAction
    {
        return CreateAction::make()->after(fn (Model $record) => app(Audit::class)->log('payment_method.created', $record, null, $record->only(['code', 'name_ar'])));
    }

    public static function getPages(): array
    {
        return ['index' => ManagePaymentMethods::route('/')];
    }
}
