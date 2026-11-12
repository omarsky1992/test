<?php

namespace App\Filament\Resources\CreditApplications;

use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\CreditApplications\Pages\ListCreditApplications;
use App\Models\PaymentAllocation;
use App\Services\PaymentService;
use App\Support\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * «الديون المسددة من الرصيد المقدم» (admin, read only but for «إرجاع الدين»): every allocation that
 * old advance credit made on a debt (`credit_application`), with when the credit was received, when
 * it went down on the debt and the days between. Undoing goes through
 * `PaymentService::undoCreditApplications` (reversal postings; nothing is edited here).
 */
class CreditApplicationResource extends Resource
{
    protected static ?string $model = PaymentAllocation::class;

    protected static ?string $slug = 'credit-applications';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static string|UnitEnum|null $navigationGroup = 'المالية';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'تسديد من الرصيد المقدم';

    protected static ?string $pluralModelLabel = 'الديون المسددة من الرصيد المقدم';

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->join('financial_transactions as ft', 'ft.id', '=', 'payment_allocations.txn_id')
            ->join('payments as p', 'p.id', '=', 'payment_allocations.payment_id')
            ->where('ft.txn_type', 'credit_application')
            ->select('payment_allocations.*', 'ft.occurred_at as applied_at', 'p.received_at as credit_at', 'p.receipt_number as receipt')
            ->with(['debt.subscriber:id,full_name', 'debt.account:id,username']);
    }

    /** Whole days between the credit being received and its going down on the debt. */
    public static function days(PaymentAllocation $r): ?int
    {
        if (! $r->credit_at || ! $r->applied_at) {
            return null;
        }

        return (int) floor(CarbonImmutable::parse($r->credit_at)->diffInDays(CarbonImmutable::parse($r->applied_at), true));
    }

    public static function table(Table $table): Table
    {
        $date = fn ($state) => $state ? CarbonImmutable::parse($state)->setTimezone(config('app.timezone'))->format('Y/m/d H:i') : '—';

        return $table
            ->defaultSort('applied_at', 'desc')
            ->searchPlaceholder('اسم المشترك أو رقم الدين')
            ->columns([
                TextColumn::make('debt.subscriber.full_name')->label('المشترك')->weight('bold')
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('debt.subscriber', fn ($w) => $w->where('full_name', 'ilike', "%{$search}%"))
                        ->orWhereHas('debt', fn ($w) => $w->where('number', 'ilike', "%{$search}%"))),
                TextColumn::make('debt.account.username')->label('اليوزر')->extraAttributes(['dir' => 'ltr'])->placeholder('—'),
                TextColumn::make('debt.number')->label('الدين')
                    ->description(fn (PaymentAllocation $r) => $r->debt?->bucket?->getLabel()),
                TextColumn::make('amount')->label('نزل من الرصيد')->formatStateUsing(fn ($state) => Money::format((int) $state))
                    ->summarize(Sum::make()->label('المجموع')->formatStateUsing(fn ($state) => Money::format((int) $state))),
                TextColumn::make('credit_at')->label('تسجيل الرصيد المقدم')->formatStateUsing($date)->sortable()
                    ->description(fn (PaymentAllocation $r) => 'سند '.$r->receipt),
                TextColumn::make('applied_at')->label('تاريخ التنزيل')->formatStateUsing($date)->sortable(),
                TextColumn::make('days')->label('الفرق')->state(fn (PaymentAllocation $r) => self::days($r))
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : ($state === 0 ? 'نفس اليوم' : $state.' يوم'))
                    ->badge()->color(fn ($state) => $state > 30 ? 'danger' : ($state > 0 ? 'warning' : 'gray')),
                TextColumn::make('debt.balance')->label('الباقي على الدين الآن')->formatStateUsing(fn ($state) => Money::format((int) $state)),
                TextColumn::make('is_reversed')->label('الحالة')->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'تم الإرجاع' : 'مسدد من الرصيد')
                    ->color(fn (bool $state) => $state ? 'success' : 'danger'),
            ])
            ->filters([
                SelectFilter::make('is_reversed')->label('الحالة')->default('0')
                    ->options(['0' => 'مسدد من الرصيد (لم يُرجع)', '1' => 'تم الإرجاع'])
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null) ? $query->where('payment_allocations.is_reversed', $data['value'] === '1') : $query),
                Filter::make('period')->label('تاريخ التنزيل')->schema([DatePicker::make('from')->label('من'), DatePicker::make('until')->label('إلى')])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($w, $d) => $w->where('ft.occurred_at', '>=', CarbonImmutable::parse($d, config('app.timezone'))->startOfDay()))
                        ->when($data['until'] ?? null, fn ($w, $d) => $w->where('ft.occurred_at', '<', CarbonImmutable::parse($d, config('app.timezone'))->addDay()->startOfDay()))),
            ])
            ->recordActions([
                Action::make('undo')
                    ->label('إرجاع الدين')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('warning')
                    ->visible(fn (PaymentAllocation $r) => ! $r->is_reversed && $r->debt !== null)
                    ->requiresConfirmation()
                    ->modalHeading(fn (PaymentAllocation $r) => 'إرجاع الدين '.$r->debt?->number.' على '.$r->debt?->subscriber?->full_name)
                    ->modalDescription('يرجع الدين مفتوحاً بقيد عكسي (كل ما نزل عليه من الرصيد)، والرصيد يتحوّل إلى تفعيل نقدي. لا يتغير أي صندوق.')
                    ->schema([Textarea::make('reason')->label('السبب')->default('سُدد من الرصيد المقدم بالخطأ')])
                    ->action(function (PaymentAllocation $record, array $data) {
                        try {
                            $owed = app(PaymentService::class)->undoCreditApplications($record->debt, $data['reason'] ?? null);
                            Notification::make()->success()->title('رجع الدين '.$record->debt->number.': '.Money::format($owed))->send();
                        } catch (BusinessRuleException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListCreditApplications::route('/')];
    }
}
