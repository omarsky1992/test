<?php

namespace App\Filament\Resources\Accounts;

use App\Enums\AccountStatus;
use App\Enums\DebtStatus;
use App\Filament\Actions\Operations;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Resources\Subscribers\SubscriberResource;
use App\Models\Account;
use App\Services\SubscriberService;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class AccountResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWifi;

    protected static string|UnitEnum|null $navigationGroup = 'العمليات اليومية';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'حساب';

    protected static ?string $pluralModelLabel = 'الحسابات';

    public static function canViewAny(): bool
    {
        return auth()->user()->can('accounts.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()->can('accounts.update');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->schema([
                ...SubscriberResource::accountFields(),
                Select::make('status')->label('الحالة')->options(AccountStatus::class)->required()
                    ->disabled(fn () => ! auth()->user()->can('accounts.close')),
            ]),
            Textarea::make('notes')->label('ملاحظات')->rows(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return static::buildTable($table, showSubscriber: true);
    }

    public static function buildTable(Table $table, bool $showSubscriber): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['subscriber', 'currentPlan'])
                ->withSum(['debts as open_debt' => fn (Builder $d) => $d->whereIn('status', [DebtStatus::Open, DebtStatus::Partial])], 'balance'))
            ->columns([
                TextColumn::make('subscriber.full_name')->label('المشترك')->weight('bold')->visible($showSubscriber)
                    ->url(fn (Account $r) => SubscriberResource::getUrl('view', ['record' => $r->subscriber_id])),
                TextColumn::make('username')->label('اليوزر')->searchable()->copyable()->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('location_label')->label('البيت')->placeholder('—'),
                TextColumn::make('currentPlan.name_ar')->label('الفئة')->placeholder('—'),
                TextColumn::make('service_ends_at')->label('ينتهي')->dateTime('Y/m/d H:i')->sortable()
                    ->description(fn (Account $r) => self::remaining($r))
                    ->color(fn (Account $r) => ! $r->service_ends_at ? 'gray' : (now()->greaterThan($r->service_ends_at) ? 'danger' : (now()->addDays(3)->greaterThan($r->service_ends_at) ? 'warning' : 'success'))),
                TextColumn::make('open_debt')->label('الدين')->formatStateUsing(fn ($state) => Money::format((int) $state))
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('fat_code')->label('الفات')->searchable()->toggleable(),
                TextColumn::make('pole_number')->label('العامود')->searchable()->toggleable(),
                TextColumn::make('serial_number')->label('السيريال')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->label('الحالة')->badge()->toggleable(),
            ])
            ->filters([
                Filter::make('expired')->label('منتهي')->query(fn (Builder $query) => $query->where('service_ends_at', '<', now())),
                Filter::make('expiring')->label('ينتهي خلال 3 أيام')
                    ->query(fn (Builder $query) => $query->whereBetween('service_ends_at', [now(), now()->addDays(3)])),
                Filter::make('never')->label('بلا تفعيل')->query(fn (Builder $query) => $query->whereNull('service_ends_at')),
                SelectFilter::make('status')->label('الحالة')->options(AccountStatus::class),
            ])
            ->recordActions([
                Operations::activate(),
                Operations::pay(),
                ActionGroup::make([
                    Operations::revealSecret(),
                    EditAction::make()->using(fn (Model $record, array $data) => app(SubscriberService::class)->updateAccount($record, $data)),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAccounts::route('/')];
    }

    private static function remaining(Account $account): ?string
    {
        if (! $account->service_ends_at) {
            return null;
        }
        $hours = (int) floor(now()->diffInHours($account->service_ends_at, false));

        return $hours >= 0 ? 'متبقي '.intdiv($hours, 24).' يوم و'.($hours % 24).' ساعة' : 'منتهٍ منذ '.intdiv(-$hours, 24).' يوم';
    }
}
