<?php

namespace App\Filament\Widgets;

use App\Filament\Actions\NoteActions;
use App\Models\AccountRenewal;
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
 * «المفعّل حديثاً»: full activations a sync found on the company site, each with «دين» (primary debt)
 * and «نقد» (active, no debt). A row leaves the list once decided.
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

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => RecentActivationService::pending()->with(['subscriber', 'account.currentPlan'])->latest('detected_at'))
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
                TextColumn::make('new_days')->label('الأيام')->formatStateUsing(fn ($state) => "{$state} يوم"),
                TextColumn::make('new_ends_at')->label('ينتهي')->dateTime('Y/m/d')->placeholder('—'),
                TextColumn::make('detected_at')->label('اكتُشف')->dateTime('Y/m/d H:i'),
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
                Action::make('cash')->label('نقد')->icon(Heroicon::OutlinedBanknotes)->color('success')->button()
                    ->requiresConfirmation()
                    ->modalHeading(fn (AccountRenewal $r) => 'تسجيل تفعيل '.$r->subscriber?->full_name.' نقداً')
                    ->modalDescription('يبقى المشترك فعّالاً بدون دين.')
                    ->modalSubmitActionLabel('نقد')
                    ->action(function (AccountRenewal $record) {
                        app(RecentActivationService::class)->asCash($record);
                        Notification::make()->success()->title('سُجّل نقداً: فعّال بدون دين')->send();
                    }),
            ]);
    }
}
