<?php

namespace App\Filament\Resources\SecondarySales;

use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\SecondarySales\Pages\ListSecondarySales;
use App\Models\SecondarySale;
use App\Models\Subscriber;
use App\Services\SecondarySaleService;
use App\Support\Money;
use App\Support\SubscriberFlags;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * «المبيعات الثانوية»: their own list and total. Nothing here touches a box, a debt, the sales, the
 * purchases or the profit.
 */
class SecondarySaleResource extends Resource
{
    protected static ?string $model = SecondarySale::class;

    protected static ?string $slug = 'secondary-sales';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquare2Stack;

    protected static string|UnitEnum|null $navigationGroup = 'المالية';

    protected static ?int $navigationSort = 8;

    protected static ?string $modelLabel = 'بيع ثانوي';

    protected static ?string $pluralModelLabel = 'المبيعات الثانوية';

    public static function canViewAny(): bool
    {
        return auth()->user()->can('secondary_sales.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['subscriber', 'creator']))
            ->recordClasses(fn (SecondarySale $r) => $r->subscriber_id ? SubscriberFlags::rowClass($r->subscriber_id) : null)
            ->defaultSort('sold_at', 'desc')
            ->columns([
                TextColumn::make('number')->label('الرقم')->weight('bold')->searchable(),
                TextColumn::make('sold_at')->label('التاريخ')->dateTime('Y/m/d H:i')->sortable(),
                TextColumn::make('description')->label('الوصف')->wrap()->searchable(),
                TextColumn::make('buyer')->label('المشتري')->state(fn (SecondarySale $r) => $r->subscriber?->full_name ?? $r->buyer_name ?? '—'),
                TextColumn::make('amount')->label('المبلغ')->formatStateUsing(fn ($state) => Money::format($state, false))
                    ->summarize(Sum::make()->label('المجموع')->query(fn ($query) => $query->where('status', 'posted'))->formatStateUsing(fn ($state) => Money::format((int) $state))),
                TextColumn::make('creator.name')->label('سجّله')->placeholder('—'),
                TextColumn::make('status')->label('الحالة')->badge()
                    ->formatStateUsing(fn (string $state) => SecondarySale::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => $state === 'posted' ? 'success' : 'gray')
                    ->description(fn (SecondarySale $r) => $r->void_reason),
            ])
            ->filters([
                SelectFilter::make('status')->label('الحالة')->options(SecondarySale::STATUSES),
            ])
            ->recordActions([
                Action::make('void')->label('إلغاء')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                    ->visible(fn (SecondarySale $r) => $r->status === 'posted' && auth()->user()->can('secondary_sales.void'))
                    ->requiresConfirmation()
                    ->schema([Textarea::make('reason')->label('السبب')->required()->maxLength(300)])
                    ->action(function (array $data, SecondarySale $record, Action $action) {
                        self::run($action, fn () => app(SecondarySaleService::class)->void($record, $data['reason']), 'أُلغي البيع');
                    }),
            ]);
    }

    public static function recordAction(): Action
    {
        return Action::make('newSecondarySale')->label('تسجيل بيع ثانوي')->icon(Heroicon::OutlinedPlus)
            ->visible(fn () => auth()->user()->can('secondary_sales.create'))
            ->fillForm(fn () => ['idempotency_key' => (string) Str::uuid()])
            ->modalDescription('يُحسب وحده: لا يدخل الصناديق ولا المبيعات ولا المشتريات ولا الربح، ولا يغيّر أي رصيد أو دين.')
            ->schema([
                Hidden::make('idempotency_key'),
                TextInput::make('description')->label('ماذا بعت')->required()->maxLength(200),
                TextInput::make('amount')->label('المبلغ')->integer()->minValue(1)->suffix('د.ع')->required(),
                Select::make('subscriber_id')->label('المشترك (اختياري)')->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Subscriber::query()->search($search)->limit(20)->pluck('full_name', 'id')->all())
                    ->getOptionLabelUsing(fn ($value) => Subscriber::find($value)?->full_name),
                TextInput::make('buyer_name')->label('اسم المشتري (إن لم يكن مشتركاً)')->maxLength(120),
                Textarea::make('notes')->label('ملاحظات')->rows(2),
            ])
            ->action(function (array $data, Action $action) {
                self::run($action, fn () => app(SecondarySaleService::class)->record(
                    $data['description'], (int) $data['amount'], filled($data['subscriber_id'] ?? null) ? (int) $data['subscriber_id'] : null,
                    $data['buyer_name'] ?? null, $data['notes'] ?? null, $data['idempotency_key'] ?? null,
                ), 'سُجّل البيع الثانوي');
            });
    }

    /** «تصفير المبيعات الثانوية»: the total starts again from now; nothing is deleted. */
    public static function resetAction(): Action
    {
        return Action::make('resetSecondarySales')->label('تصفير المبيعات الثانوية')->icon('heroicon-o-arrow-path')->color('gray')
            ->visible(fn () => auth()->user()->can('secondary_sales.reset'))
            ->requiresConfirmation()
            ->modalHeading('تصفير المبيعات الثانوية')
            ->schema([
                Text::make(fn () => 'المجموع الآن: '.Money::format(app(SecondarySaleService::class)->totals()['total'])
                    .'. يبدأ العدّ من جديد، وتبقى كل المبيعات في السجل. لا يتغيّر أي رصيد.'),
                Textarea::make('notes')->label('ملاحظة (اختياري)')->rows(2),
            ])
            ->modalSubmitActionLabel('تصفير')
            ->action(function (array $data, Action $action) {
                self::run($action, fn () => app(SecondarySaleService::class)->reset($data['notes'] ?? null), 'صُفّرت المبيعات الثانوية');
            });
    }

    private static function run(Action $action, \Closure $callback, string $done): void
    {
        try {
            $callback();
            Notification::make()->success()->title($done)->send();
        } catch (BusinessRuleException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $action->halt();
        }
    }

    public static function getPages(): array
    {
        return ['index' => ListSecondarySales::route('/')];
    }
}
