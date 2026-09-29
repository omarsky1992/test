<?php

namespace App\Filament\Resources\Debts;

use App\Enums\DebtBucket;
use App\Enums\DebtSource;
use App\Enums\DebtStatus;
use App\Exceptions\BusinessRuleException;
use App\Filament\Actions\Operations;
use App\Filament\Resources\Debts\Pages\ListDebts;
use App\Filament\Resources\Subscribers\SubscriberResource;
use App\Models\Account;
use App\Models\Debt;
use App\Services\DebtService;
use App\Support\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
use App\Support\Options;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class DebtResource extends Resource
{
    protected static ?string $model = Debt::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'المالية';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'دين';

    protected static ?string $pluralModelLabel = 'الديون';

    public static function canViewAny(): bool
    {
        return auth()->user()->can('debts.view');
    }

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
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['subscriber', 'account', 'activation']))
            ->defaultSort('debt_date', 'desc')
            ->columns([
                TextColumn::make('number')->label('الرقم')->searchable(),
                TextColumn::make('subscriber.full_name')->label('المشترك')->weight('bold')->visible($showSubscriber)
                    ->url(fn (Debt $r) => SubscriberResource::getUrl('view', ['record' => $r->subscriber_id])),
                TextColumn::make('account.username')->label('اليوزر')->searchable()->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('source')->label('المصدر')->badge(),
                TextColumn::make('bucket')->label('النوع')->badge(),
                TextColumn::make('original_amount')->label('الأصل')->formatStateUsing(fn ($state) => Money::format($state, false)),
                TextColumn::make('paid_amount')->label('المسدد')->formatStateUsing(fn ($state) => Money::format($state, false)),
                TextColumn::make('balance')->label('المتبقي')->weight('bold')->sortable()
                    ->formatStateUsing(fn ($state) => Money::format($state, false))
                    ->summarize(Sum::make()->label('المجموع')->formatStateUsing(fn ($state) => Money::format((int) $state))),
                TextColumn::make('debt_date')->label('التاريخ')->dateTime('Y/m/d')->sortable()
                    ->description(fn (Debt $r) => 'منذ '.(int) $r->debt_date->diffInDays(now()).' يوم'),
                TextColumn::make('status')->label('الحالة')->badge(),
            ])
            ->filters([
                SelectFilter::make('source')->label('المصدر')->options(DebtSource::class),
                SelectFilter::make('status')->label('الحالة')->options(DebtStatus::class),
            ])
            ->recordActions([
                Operations::pay()->visible(fn (Debt $r) => in_array($r->status, [DebtStatus::Open, DebtStatus::Partial], true)),
                Operations::transfer(),
                ActionGroup::make([Operations::voidDebt()]),
            ]);
    }

    public static function manualDebtAction(?Account $account = null): Action
    {
        return Action::make('manualDebt')
            ->label('دين يدوي')
            ->icon(Heroicon::OutlinedPlus)
            ->color('gray')
            ->visible(fn () => auth()->user()->can('debts.create_manual') || auth()->user()->can('debts.create_opening'))
            ->modalHeading('تسجيل دين يدوي')
            ->fillForm(['account_id' => $account?->id, 'bucket' => DebtBucket::Primary->value, 'source' => DebtSource::Manual->value])
            ->schema([
                Operations::accountField()->hidden($account !== null),
                Grid::make(2)->schema([
                    TextInput::make('amount')->label('المبلغ')->integer()->minValue(1)->suffix('د.ع')->required(),
                    ToggleButtons::make('bucket')->label('النوع')->options(Options::of(DebtBucket::class))->inline()->required(),
                    ToggleButtons::make('source')->label('المصدر')->inline()->required()
                        ->options(array_filter([
                            DebtSource::Manual->value => auth()->user()->can('debts.create_manual') ? 'يدوي (أجور، أخرى)' : null,
                            DebtSource::Opening->value => auth()->user()->can('debts.create_opening') ? 'افتتاحي (قبل النظام)' : null,
                        ])),
                    DateTimePicker::make('debt_date')->label('تاريخ الدين')->seconds(false)->default(now()),
                    DatePicker::make('due_date')->label('تاريخ الاستحقاق'),
                ]),
                Textarea::make('notes')->label('السبب / ملاحظات')->required()->rows(2),
            ])
            ->action(function (array $data, Action $action) use ($account) {
                try {
                    $debt = app(DebtService::class)->create(
                        account: $account ?? Account::findOrFail($data['account_id']),
                        amount: (int) $data['amount'],
                        bucket: DebtBucket::from($data['bucket']),
                        source: DebtSource::from($data['source']),
                        debtDate: filled($data['debt_date'] ?? null) ? CarbonImmutable::parse($data['debt_date']) : null,
                        dueDate: $data['due_date'] ?? null,
                        notes: $data['notes'],
                    );
                    Notification::make()->success()->title("تم تسجيل الدين {$debt->number}")->send();
                } catch (BusinessRuleException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();
                }
            });
    }

    public static function getPages(): array
    {
        return ['index' => ListDebts::route('/')];
    }
}
