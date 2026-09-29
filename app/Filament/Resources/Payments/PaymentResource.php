<?php

namespace App\Filament\Resources\Payments;

use App\Enums\DocumentStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use App\Filament\Actions\Operations;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Subscribers\SubscriberResource;
use App\Models\MoneyAccount;
use App\Models\Payment;
use App\Models\User;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'المالية';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'القبض والسندات';

    protected static ?string $modelLabel = 'سند قبض';

    protected static ?string $pluralModelLabel = 'سندات القبض';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return static::buildTable($table, showSubscriber: true);
    }

    public static function buildTable(Table $table, bool $showSubscriber): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['subscriber', 'account', 'moneyAccount', 'creator']))
            ->defaultSort('received_at', 'desc')
            ->columns([
                TextColumn::make('receipt_number')->label('رقم السند')->searchable()->weight('bold'),
                TextColumn::make('received_at')->label('الوقت')->dateTime('Y/m/d H:i')->sortable(),
                TextColumn::make('subscriber.full_name')->label('المشترك')->visible($showSubscriber)
                    ->url(fn (Payment $r) => SubscriberResource::getUrl('view', ['record' => $r->subscriber_id])),
                TextColumn::make('account.username')->label('اليوزر')->searchable()->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('payment_type')->label('النوع')->badge(),
                TextColumn::make('method')->label('الطريقة')->badge()
                    ->description(fn (Payment $r) => $r->receiver_name),
                TextColumn::make('moneyAccount.name')->label('الصندوق')->toggleable(),
                TextColumn::make('amount')->label('المبلغ')->weight('bold')->sortable()
                    ->formatStateUsing(fn ($state) => Money::format($state, false))
                    ->summarize(Sum::make()->label('المجموع')->query(fn ($query) => $query->where('status', DocumentStatus::Posted))
                        ->formatStateUsing(fn ($state) => Money::format((int) $state))),
                TextColumn::make('creator.name')->label('الموظف'),
                TextColumn::make('status')->label('الحالة')->badge(),
            ])
            ->filters([
                Filter::make('received_at')->label('التاريخ')->schema([
                    DatePicker::make('from')->label('من')->default(now()->startOfMonth()),
                    DatePicker::make('until')->label('إلى'),
                ])->query(fn (Builder $query, array $data) => $query
                    ->when($data['from'] ?? null, fn ($query, $d) => $query->where('received_at', '>=', $d))
                    ->when($data['until'] ?? null, fn ($query, $d) => $query->where('received_at', '<', now()->parse($d)->addDay()))),
                SelectFilter::make('method')->label('الطريقة')->options(PaymentMethod::class),
                SelectFilter::make('payment_type')->label('النوع')->options(PaymentType::class),
                SelectFilter::make('money_account_id')->label('الصندوق')->options(fn () => MoneyAccount::pluck('name', 'id')),
                SelectFilter::make('created_by')->label('الموظف')->options(fn () => User::pluck('name', 'id')),
                SelectFilter::make('status')->label('الحالة')->options(DocumentStatus::class),
            ])
            ->recordActions([
                Operations::receipt(),
                ActionGroup::make([Operations::voidPayment()]),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPayments::route('/')];
    }
}
