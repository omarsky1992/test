<?php

namespace App\Filament\Resources\CardAccounts;

use App\Enums\MoneyAccountKind;
use App\Filament\Pages\MoneyAccountStatement;
use App\Filament\Resources\CardAccounts\Pages\ListCardAccounts;
use App\Filament\Resources\EmployeeTransfers\EmployeeTransferResource;
use App\Models\MoneyAccount;
use App\Services\InternalTransfers;
use App\Services\TreasuryService;
use App\Support\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * «حسابات البطاقات»: Qi Card, Super Key, Mastercard and every other card or wallet in the system,
 * each with its balance now and what went in and out over a chosen period.
 */
class CardAccountResource extends Resource
{
    protected static ?string $model = MoneyAccount::class;

    protected static ?string $slug = 'card-accounts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'المالية';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'حساب بطاقة';

    protected static ?string $pluralModelLabel = 'حسابات البطاقات';

    public static function canViewAny(): bool
    {
        return auth()->user()->can('cards.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('kind', MoneyAccountKind::Electronic);
    }

    /** @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable} */
    private static function period($livewire): array
    {
        $state = method_exists($livewire, 'getTableFilterState') ? ($livewire->getTableFilterState('period') ?? []) : [];

        return [filled($state['from'] ?? null) ? CarbonImmutable::parse($state['from']) : null, filled($state['until'] ?? null) ? CarbonImmutable::parse($state['until']) : null];
    }

    public static function table(Table $table): Table
    {
        $moves = fn (MoneyAccount $r, $livewire) => app(TreasuryService::class)->movements($r, ...self::period($livewire));

        return $table
            ->description('الداخل والخارج لكل بطاقة خلال الفترة المحددة في الفلتر (إجمالي الحركة)، والرصيد الحالي هو ما على البطاقة الآن.')
            ->columns([
                TextColumn::make('name')->label('البطاقة')->weight('bold')->searchable()
                    ->description(fn (MoneyAccount $r) => $r->holder_name),
                TextColumn::make('total_in')->label('الداخل خلال الفترة')->color('success')
                    ->state(fn (MoneyAccount $r, $livewire) => $moves($r, $livewire)['in'])->formatStateUsing(fn ($state) => Money::format((int) $state)),
                TextColumn::make('total_out')->label('الخارج خلال الفترة')->color('danger')
                    ->state(fn (MoneyAccount $r, $livewire) => $moves($r, $livewire)['out'])->formatStateUsing(fn ($state) => Money::format((int) $state)),
                TextColumn::make('balance')->label('الرصيد الحالي')->weight('bold')
                    ->state(fn (MoneyAccount $r) => app(TreasuryService::class)->balance($r))
                    ->formatStateUsing(fn ($state) => Money::format((int) $state))
                    ->color(fn ($state) => $state < 0 ? 'danger' : 'success'),
            ])
            ->filters([
                Filter::make('period')->label('الفترة')->schema([
                    DatePicker::make('from')->label('من')->default(now()->startOfMonth()),
                    DatePicker::make('until')->label('إلى'),
                ]),
            ])
            ->recordActions([
                Action::make('statement')->label('سجل الحركات')->icon(Heroicon::OutlinedDocumentText)->color('gray')
                    ->url(fn (MoneyAccount $r, $livewire) => MoneyAccountStatement::getUrl(array_filter([
                        'box' => $r->id, 'from' => self::period($livewire)[0]?->toDateString(), 'to' => self::period($livewire)[1]?->toDateString(),
                    ]))),
                self::transferAction('transferFrom'),
                Action::make('reset')->label('تصفير رصيد البطاقة')->icon(Heroicon::OutlinedArrowPath)->color('danger')
                    ->visible(fn () => auth()->user()->can('cards.reset'))
                    ->fillForm(fn () => ['idempotency_key' => (string) Str::uuid()])
                    ->modalHeading(fn (MoneyAccount $r) => 'تصفير رصيد '.$r->name)
                    ->schema(fn (MoneyAccount $record) => [
                        Hidden::make('idempotency_key'),
                        Text::make('الرصيد الحالي: '.Money::format(app(TreasuryService::class)->balance($record))
                            .'. يُسجَّل التصفير تسوية موثقة برقم، وتبقى كل الحركات السابقة كما هي، ويمكن إلغاؤه بقيد عكسي.'),
                        Textarea::make('reason')->label('السبب / الملاحظة')->required()->rows(2)->maxLength(500),
                    ])
                    ->requiresConfirmation()
                    ->action(fn (MoneyAccount $record, array $data, Action $action) => EmployeeTransferResource::run($action,
                        fn () => app(InternalTransfers::class)->resetCard($record, $data['reason'], $data['idempotency_key'] ?? null))),
            ]);
    }

    public static function transferAction(string $name = 'transferCards'): Action
    {
        $cards = fn () => MoneyAccount::where('kind', MoneyAccountKind::Electronic)->where('is_active', true)->orderBy('name')->get()
            ->mapWithKeys(fn (MoneyAccount $m) => [$m->id => $m->name.' – '.Money::format(app(TreasuryService::class)->balance($m))]);

        return Action::make($name)->label('مناقلة بين البطاقات')->icon(Heroicon::OutlinedArrowsRightLeft)->color('info')
            ->visible(fn () => auth()->user()->can('cards.transfer'))
            ->fillForm(fn (?MoneyAccount $record) => ['idempotency_key' => (string) Str::uuid(), 'from' => $record?->id])
            ->modalDescription('تسجَّل داخل النظام فقط، ولا تنفذ تحويلاً فعلياً لدى مزود البطاقة.')
            ->schema([
                Hidden::make('idempotency_key'),
                Select::make('from')->label('من بطاقة')->options($cards)->required(),
                Select::make('to')->label('إلى بطاقة')->options($cards)->required()->different('from'),
                TextInput::make('amount')->label('المبلغ')->integer()->minValue(1)->suffix('د.ع')->required(),
                TextInput::make('reference')->label('رقم مرجعي (اختياري)')->maxLength(100),
                Textarea::make('notes')->label('ملاحظات')->rows(2)->maxLength(500),
            ])
            ->action(fn (array $data, Action $action) => EmployeeTransferResource::run($action, fn () => app(InternalTransfers::class)->betweenCards(
                MoneyAccount::findOrFail($data['from']), MoneyAccount::findOrFail($data['to']), (int) $data['amount'],
                $data['notes'] ?? null, $data['reference'] ?? null, $data['idempotency_key'] ?? null,
            )));
    }

    public static function getPages(): array
    {
        return ['index' => ListCardAccounts::route('/')];
    }
}
