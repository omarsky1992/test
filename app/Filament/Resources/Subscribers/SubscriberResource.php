<?php

namespace App\Filament\Resources\Subscribers;

use App\Enums\DebtBucket;
use App\Enums\DebtStatus;
use App\Enums\SubscriberStatus;
use App\Filament\Resources\Subscribers\Pages\CreateSubscriber;
use App\Filament\Resources\Subscribers\Pages\EditSubscriber;
use App\Filament\Resources\Subscribers\Pages\ListSubscribers;
use App\Filament\Resources\Subscribers\Pages\ViewSubscriber;
use App\Filament\Resources\Subscribers\RelationManagers;
use App\Models\Subscriber;
use App\Services\PaymentService;
use App\Support\Arabic;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use UnitEnum;

class SubscriberResource extends Resource
{
    protected static ?string $model = Subscriber::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'العمليات اليومية';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'مشترك';

    protected static ?string $pluralModelLabel = 'المشتركون';

    protected static ?string $recordTitleAttribute = 'full_name';

    public static function canViewAny(): bool
    {
        return auth()->user()->can('subscribers.view');
    }

    public static function canCreate(): bool
    {
        return auth()->user()->can('subscribers.create');
    }

    public static function canEdit($record): bool
    {
        return auth()->user()->can('subscribers.update');
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('بيانات المشترك')->columns(2)->schema([
                TextInput::make('full_name')->label('الاسم')->required()->maxLength(150),
                TextInput::make('phone')
                    ->label('رقم الهاتف المسجل في الشركة')
                    ->tel()
                    ->required()
                    ->maxLength(20)
                    ->live(onBlur: true)
                    ->hint(fn (?string $state, ?Subscriber $record) => self::duplicatePhoneHint($state, $record))
                    ->hintColor('warning'),
                TextInput::make('alt_phone')->label('هاتف آخر')->tel()->maxLength(20),
                Select::make('status')->label('الحالة')->options(SubscriberStatus::class)->default('active')
                    ->visibleOn('edit')
                    ->disabled(fn () => ! auth()->user()->can('subscribers.archive')),
                Textarea::make('address')->label('العنوان')->rows(2),
                Textarea::make('notes')->label('ملاحظات')->rows(2),
            ]),
            Section::make('الحساب الأول (اليوزر)')->columns(3)->visibleOn('create')->schema(self::accountFields('account.')),
        ]);
    }

    /**
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    public static function accountFields(string $prefix = ''): array
    {
        return [
            TextInput::make("{$prefix}username")->label('يوزر الاشتراك')->required()->maxLength(80)
                ->unique('accounts', 'username', ignoreRecord: true)
                ->extraInputAttributes(['dir' => 'ltr']),
            TextInput::make("{$prefix}secret")->label('الباسورد')->password()->revealable()->maxLength(100)
                ->helperText($prefix === '' ? 'اتركه فارغاً للإبقاء على الباسورد الحالي.' : null),
            TextInput::make("{$prefix}serial_number")->label('السيريال')->maxLength(60)->extraInputAttributes(['dir' => 'ltr']),
            TextInput::make("{$prefix}phone")->label('هاتف الحساب')->tel()->maxLength(20),
            TextInput::make("{$prefix}fat_code")->label('الفات')->maxLength(50),
            TextInput::make("{$prefix}pole_number")->label('رقم العامود')->maxLength(50),
            TextInput::make("{$prefix}location_label")->label('البيت / الموقع')->placeholder('مثال: البيت الثاني')->maxLength(150),
        ];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('full_name')->label('الاسم')->weight('bold')->size('lg'),
                TextEntry::make('phone')->label('رقم الهاتف')->url(fn (Subscriber $r) => "tel:{$r->phone}")->extraAttributes(['dir' => 'ltr']),
                TextEntry::make('code')->label('رقم المشترك'),
                TextEntry::make('status')->label('الحالة')->badge(),
                TextEntry::make('address')->label('العنوان')->placeholder('—')->columnSpan(2),
                TextEntry::make('notes')->label('ملاحظات')->placeholder('—')->columnSpan(2),
            ]),
            Grid::make(3)->schema([
                TextEntry::make('secondary_total')->label('دين ثانوي')->state(fn (Subscriber $r) => Money::format(self::bucketTotal($r, DebtBucket::Secondary)))->color('warning')->weight('bold'),
                TextEntry::make('primary_total')->label('دين أولي')->state(fn (Subscriber $r) => Money::format(self::bucketTotal($r, DebtBucket::Primary)))->color('info')->weight('bold'),
                TextEntry::make('credit_total')->label('رصيد مقدم')->state(fn (Subscriber $r) => Money::format($r->accounts->sum(fn ($a) => app(PaymentService::class)->creditBalance($a))))->weight('bold'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('accounts')
                ->withSum(['debts as open_debt' => fn (Builder $d) => $d->whereIn('status', [DebtStatus::Open, DebtStatus::Partial])], 'balance')
                ->withMax('accounts as service_ends_at', 'service_ends_at'))
            ->columns([
                TextColumn::make('code')->label('الرقم')->sortable(),
                TextColumn::make('full_name')->label('الاسم')->weight('bold')->sortable()
                    ->searchable(query: fn (Builder $query, string $search) => $query->search($search)),
                TextColumn::make('phone')->label('الهاتف')->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('accounts_count')->label('الحسابات')->alignCenter(),
                TextColumn::make('open_debt')->label('الدين')->formatStateUsing(fn ($state) => Money::format((int) $state))
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'gray')->sortable(),
                TextColumn::make('service_ends_at')->label('أقرب/آخر انتهاء')->dateTime('Y/m/d H:i')->sortable()
                    ->color(fn ($state) => $state && now()->greaterThan($state) ? 'danger' : null),
                TextColumn::make('status')->label('الحالة')->badge(),
            ])
            ->defaultSort('id', 'desc')
            ->searchPlaceholder('الاسم، الهاتف، اليوزر، السيريال، رقم السند…')
            ->filters([
                SelectFilter::make('status')->label('الحالة')->options(SubscriberStatus::class),
                Filter::make('has_debt')->label('عليه دين')
                    ->query(fn (Builder $query) => $query->whereHas('debts', fn (Builder $d) => $d->whereIn('status', [DebtStatus::Open, DebtStatus::Partial]))),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\AccountsRelationManager::class,
            RelationManagers\ActivationsRelationManager::class,
            RelationManagers\DebtsRelationManager::class,
            RelationManagers\PaymentsRelationManager::class,
            RelationManagers\FollowUpsRelationManager::class,
            RelationManagers\AuditLogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubscribers::route('/'),
            'create' => CreateSubscriber::route('/create'),
            'view' => ViewSubscriber::route('/{record}'),
            'edit' => EditSubscriber::route('/{record}/edit'),
        ];
    }

    public static function getGlobalSearchResults(string $search): Collection
    {
        return Subscriber::search($search)->with('accounts')->limit(10)->get()
            ->map(fn (Subscriber $s) => new GlobalSearchResult(
                title: $s->full_name,
                url: static::getUrl('view', ['record' => $s]),
                details: ['الهاتف' => $s->phone, 'اليوزر' => $s->accounts->pluck('username')->join('، ')],
            ));
    }

    private static function bucketTotal(Subscriber $subscriber, DebtBucket $bucket): int
    {
        return (int) $subscriber->debts()->where('bucket', $bucket)->whereIn('status', [DebtStatus::Open, DebtStatus::Partial])->sum('balance');
    }

    private static function duplicatePhoneHint(?string $phone, ?Subscriber $record): ?string
    {
        if (blank($phone)) {
            return null;
        }
        $other = Subscriber::where('phone_normalized', Arabic::phone($phone))->when($record, fn ($query) => $query->whereKeyNot($record->id))->first();

        return $other ? "الرقم مسجل للمشترك {$other->full_name} ({$other->code}). أضف الحساب له بدل مشترك جديد." : null;
    }
}
