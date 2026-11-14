<?php

namespace App\Filament\Resources\SyncedRenewals;

use App\Filament\Resources\SyncedRenewals\Pages\ListSyncedRenewals;
use App\Models\AccountRenewal;
use App\Support\Money;
use App\Support\SubscriberFlags;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * «سجل المزامنة»: every activation or renewal a sync found (read only): how the company site says it
 * was paid and what became of it, to follow the sync and check it is right.
 */
class SyncedRenewalResource extends Resource
{
    protected static ?string $model = AccountRenewal::class;

    protected static ?string $slug = 'sync-log';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'العمليات اليومية';

    protected static ?int $navigationSort = 7;

    protected static ?string $modelLabel = 'تفعيل من المزامنة';

    protected static ?string $pluralModelLabel = 'سجل المزامنة';

    public static function canViewAny(): bool
    {
        return auth()->user()->can('activations.recent');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNotNull('sync_run_id')
            ->with(['subscriber', 'account', 'debt', 'decisionDebt', 'decider']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordClasses(fn (AccountRenewal $record) => SubscriberFlags::rowClass($record->subscriber_id))
            ->defaultSort('detected_at', 'desc')
            ->searchPlaceholder('اسم المشترك أو الجهاز')
            ->columns([
                TextColumn::make('detected_at')->label('اكتُشف')->dateTime('Y/m/d H:i')->sortable(),
                SubscriberFlags::mark(TextColumn::make('subscriber.full_name')->label('المشترك')->weight('bold')
                    ->url(fn (AccountRenewal $r) => SubscriberFlags::subscriberUrl($r->subscriber_id))
                    ->description(fn (AccountRenewal $r) => $r->is_new ? '🆕 مشترك جديد' : null)
                    ->searchable(query: fn (Builder $query, string $search) => $query
                        ->whereHas('subscriber', fn ($q) => $q->where('full_name', 'ilike', '%'.addcslashes($search, '%_\\').'%'))
                        ->orWhereHas('account', fn ($q) => $q->where('username', 'ilike', '%'.addcslashes($search, '%_\\').'%'))),
                    fn (AccountRenewal $r) => $r->subscriber_id),
                TextColumn::make('account.username')->label('الجهاز')->extraAttributes(['dir' => 'ltr'])->placeholder('—'),
                TextColumn::make('plan_name')->label('الفئة')->placeholder('—'),
                TextColumn::make('new_days')->label('الأيام')
                    ->formatStateUsing(fn ($state, AccountRenewal $r) => "{$r->previous_days} ← {$state} يوم"),
                TextColumn::make('new_ends_at')->label('ينتهي')->dateTime('Y/m/d H:i')->placeholder('—'),
                TextColumn::make('payment_source')->label('الدفع بالموقع')->badge()->placeholder('غير معروف')
                    ->color(fn (?string $state) => AccountRenewal::SOURCE_COLORS[$state] ?? 'gray')
                    ->formatStateUsing(fn (?string $state) => AccountRenewal::PAYMENT_SOURCES[$state] ?? $state)
                    ->description(fn (AccountRenewal $r) => $r->company_amount ? Money::format($r->company_amount) : null),
                TextColumn::make('outcome')->label('النتيجة')->badge()
                    ->state(fn (AccountRenewal $r) => $r->outcome()[0])
                    ->color(fn (AccountRenewal $r) => $r->outcome()[1])
                    ->description(fn (AccountRenewal $r) => match (true) {
                        $r->status === 'debt_created' && $r->amount => Money::format($r->amount),
                        $r->decision === 'debt' && $r->decisionDebt => Money::format((int) $r->decisionDebt->original_amount),
                        default => null,
                    }),
                TextColumn::make('decider.name')->label('قرّره')->placeholder('—')
                    ->description(fn (AccountRenewal $r) => $r->decided_at?->setTimezone(config('app.timezone'))->format('Y/m/d H:i')),
            ])
            ->filters([
                SelectFilter::make('payment_source')->label('الدفع بالموقع')
                    ->options(AccountRenewal::PAYMENT_SOURCES + ['none' => 'غير معروف'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        null, '' => $query,
                        'none' => $query->whereNull('payment_source'),
                        default => $query->where('payment_source', $data['value']),
                    }),
                SelectFilter::make('outcome')->label('النتيجة')->options(AccountRenewal::OUTCOMES)
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'waiting' => $query->where('status', 'activated')->whereNull('decision'),
                        'primary' => $query->where('decision', 'debt'),
                        'cash', 'transfer' => $query->where('decision', $data['value']),
                        'pending_debt', 'debt_created', 'no_debt', 'test' => $query->where('status', $data['value']),
                        default => $query,
                    }),
                TernaryFilter::make('is_new')->label('مشتركين جدد')->trueLabel('الجدد فقط')->falseLabel('بدون الجدد'),
                Filter::make('period')->label('الفترة')->schema([DatePicker::make('from')->label('من'), DatePicker::make('until')->label('إلى')])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->where('detected_at', '>=', CarbonImmutable::parse($d, config('app.timezone'))->startOfDay()))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->where('detected_at', '<', CarbonImmutable::parse($d, config('app.timezone'))->addDay()->startOfDay()))),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListSyncedRenewals::route('/')];
    }
}
