<?php

namespace App\Filament\Widgets;

use App\Enums\DebtBucket;
use App\Enums\DebtStatus;
use App\Filament\Actions\NoteActions;
use App\Models\AccountRenewal;
use App\Models\Debt;
use App\Services\RecentActivationService;
use App\Support\Money;
use App\Support\SubscriberFlags;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * «المفعّل حديثاً»: full activations a sync found on the company site, each with «دين» (primary debt),
 * «مناقلة» (only with an unpaid secondary debt: it moves to the primary debts) and «نقد» (active, no debt). A row leaves the list once decided.
 */
class RecentlyActivated extends TableWidget
{
    protected static ?int $sort = 4;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = '🆕 المفعّل حديثاً';

    public static function canView(): bool
    {
        return auth()->user()->can('activations.recent');
    }

    /** Who took the money and when: «أحمد 35,000 د.ع (10/20)». */
    public static function receivers(AccountRenewal $renewal): string
    {
        return RecentActivationService::receipts($renewal)
            ->map(fn ($p) => ($p->creator?->name ?? '—').' '.Money::format((int) $p->amount).' ('.$p->received_at->setTimezone(config('app.timezone'))->format('m/d').')')
            ->implode(' · ');
    }

    public function table(Table $table): Table
    {
        return $table
            // Those someone already took money from come first, so «نقد» is one look away.
            ->query(fn () => RecentActivationService::pending()->with(['subscriber', 'account.currentPlan'])
                ->addSelect(['received' => RecentActivationService::receiptsQuery()->selectRaw('sum(payments.amount)')])
                ->orderByRaw('not exists ('.($taken = RecentActivationService::receiptsQuery()->selectRaw('1')->toBase())->toSql().')', $taken->getBindings())
                ->latest('detected_at')
                ->addSelect(['secondary_balance' => Debt::query()->selectRaw('sum(balance)')->whereColumn('debts.account_id', 'account_renewals.account_id')
                    ->where('bucket', DebtBucket::Secondary)->whereIn('status', [DebtStatus::Open, DebtStatus::Partial])]))
            ->recordClasses(fn (AccountRenewal $record) => SubscriberFlags::rowClass($record->subscriber_id))
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading('لا يوجد مفعّلون جدد من المزامنة')
            ->columns([
                SubscriberFlags::mark(TextColumn::make('subscriber.full_name')->label('المشترك')->weight('bold')
                    ->url(fn (AccountRenewal $r) => SubscriberFlags::subscriberUrl($r->subscriber_id)), fn (AccountRenewal $r) => $r->subscriber_id),
                TextColumn::make('account.username')->label('الجهاز')->placeholder('—')
                    ->description(fn (AccountRenewal $r) => $r->subscriber?->phone),
                TextColumn::make('plan_name')->label('الفئة')->placeholder('—')
                    ->description(fn (AccountRenewal $r) => $r->account?->currentPlan?->price ? Money::format((int) $r->account->currentPlan->price) : null),
                TextColumn::make('payment_source')->label('الدفع بالموقع')->badge()->placeholder('—')
                    ->color(fn (?string $state) => $state === 'wallet' ? 'info' : 'warning')
                    ->formatStateUsing(fn (?string $state) => AccountRenewal::PAYMENT_SOURCES[$state] ?? $state),
                TextColumn::make('new_days')->label('الأيام')->formatStateUsing(fn ($state) => "{$state} يوم"),
                TextColumn::make('new_ends_at')->label('ينتهي')->dateTime('Y/m/d')->placeholder('—'),
                TextColumn::make('detected_at')->label('اكتُشف')->dateTime('Y/m/d H:i'),
                TextColumn::make('received')->label('مستلم منه')->placeholder('—')->color('success')->weight('bold')->wrap()
                    ->formatStateUsing(fn ($state) => (int) $state > 0 ? '💵 '.Money::format((int) $state) : null)
                    ->description(fn (AccountRenewal $r) => (int) $r->received > 0 ? self::receivers($r) : null),
                TextColumn::make('secondary_balance')->label('دين ثانوي')->placeholder('—')->color('warning')
                    ->formatStateUsing(fn ($state) => (int) $state > 0 ? Money::format((int) $state) : null),
            ])
            ->recordActions([
                NoteActions::open(),
                Action::make('debt')->label('دين')->icon(Heroicon::OutlinedDocumentText)->color('danger')->button()
                    ->modalHeading(fn (AccountRenewal $r) => 'تسجيل تفعيل '.$r->subscriber?->full_name.' ديناً أولياً')
                    ->modalDescription('يُسجَّل دين أولي على المشترك ويبقى «فعّال وعليه دين».')
                    ->fillForm(fn (AccountRenewal $r) => ['amount' => $r->account?->currentPlan?->price ?: null])
                    ->schema([TextInput::make('amount')->label('مبلغ الدين (د.ع)')->integer()->minValue(1)->required()])
                    ->modalSubmitActionLabel('تسجيل الدين')
                    ->action(function (AccountRenewal $record, array $data) {
                        $renewal = app(RecentActivationService::class)->asDebt($record, (int) $data['amount']);
                        Notification::make()->success()->title('سُجّل دين أولي '.Money::format((int) $data['amount']))
                            ->body($renewal->decisionDebt?->number)->send();
                    }),
                Action::make('transfer')->label('مناقلة')->icon(Heroicon::OutlinedArrowsRightLeft)->color('warning')->button()
                    ->visible(fn (AccountRenewal $r) => (int) $r->secondary_balance > 0 && auth()->user()->can('transfers.create'))
                    ->requiresConfirmation()
                    ->modalHeading(fn (AccountRenewal $r) => 'مناقلة دين '.$r->subscriber?->full_name)
                    ->modalDescription(fn (AccountRenewal $r) => 'ينتقل دينه الثانوي غير المسدد ('.Money::format((int) $r->secondary_balance).') إلى الديون الأولية، ويبقى «فعّال وعليه دين».')
                    ->modalSubmitActionLabel('مناقلة')
                    ->action(function (AccountRenewal $record) {
                        app(RecentActivationService::class)->asTransfer($record);
                        Notification::make()->success()->title('تمت المناقلة إلى الديون الأولية')->send();
                    }),
                Action::make('cash')->label('نقد')->icon(Heroicon::OutlinedBanknotes)->color('success')->button()
                    ->requiresConfirmation()
                    ->modalHeading(fn (AccountRenewal $r) => 'تسجيل تفعيل '.$r->subscriber?->full_name.' نقداً')
                    ->modalDescription(fn (AccountRenewal $r) => 'يبقى المشترك فعّالاً بدون دين.'.((int) $r->received > 0 ? ' مستلم منه: '.self::receivers($r) : ''))
                    ->modalSubmitActionLabel('نقد')
                    ->action(function (AccountRenewal $record) {
                        app(RecentActivationService::class)->asCash($record);
                        Notification::make()->success()->title('سُجّل نقداً: فعّال بدون دين')->send();
                    }),
            ]);
    }
}
