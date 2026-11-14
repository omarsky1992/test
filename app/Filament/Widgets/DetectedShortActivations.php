<?php

namespace App\Filament\Widgets;

use App\Filament\Actions\NoteActions;
use App\Models\AccountRenewal;
use App\Services\RenewalService;
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
 * «المكتشف 7 أيام»: short activations a sync found on the company site (by the agent, an employee or
 * the subscriber's app). «نعم» records one secondary debt (the plan price, editable), «لا» records
 * none. A row leaves the list once decided.
 */
class DetectedShortActivations extends TableWidget
{
    protected static ?int $sort = 4;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = '🕖 المكتشف 7 أيام: يُسجَّل دين ثانوي؟';

    public static function canView(): bool
    {
        return auth()->user()->can('activations.recent') && RenewalService::pendingShort()->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => RenewalService::pendingShort()->with(['subscriber', 'account.currentPlan'])->latest('detected_at'))
            ->recordClasses(fn (AccountRenewal $record) => SubscriberFlags::rowClass($record->subscriber_id))
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->columns([
                SubscriberFlags::mark(TextColumn::make('subscriber.full_name')->label('المشترك')->weight('bold')
                    ->url(fn (AccountRenewal $r) => SubscriberFlags::subscriberUrl($r->subscriber_id))
                    ->description(fn (AccountRenewal $r) => $r->is_new ? '🆕 مشترك جديد' : null), fn (AccountRenewal $r) => $r->subscriber_id),
                TextColumn::make('account.username')->label('الجهاز')->placeholder('—')
                    ->description(fn (AccountRenewal $r) => $r->subscriber?->phone),
                TextColumn::make('plan_name')->label('الفئة')->placeholder('—')
                    ->description(fn (AccountRenewal $r) => $r->amount ? Money::format($r->amount) : null),
                TextColumn::make('new_days')->label('الأيام')->formatStateUsing(fn ($state, AccountRenewal $r) => "{$r->previous_days} ← {$state} يوم"),
                TextColumn::make('new_ends_at')->label('ينتهي')->dateTime('Y/m/d H:i')->placeholder('—'),
                TextColumn::make('detected_at')->label('اكتُشف')->dateTime('Y/m/d H:i'),
            ])
            ->recordActions([
                NoteActions::open(),
                Action::make('yes')->label('نعم: دين ثانوي')->icon(Heroicon::OutlinedCheck)->color('danger')->button()
                    ->modalHeading(fn (AccountRenewal $r) => 'تسجيل دين ثانوي على '.$r->subscriber?->full_name)
                    ->modalDescription(fn (AccountRenewal $r) => "تفعيل {$r->new_days} أيام اكتشفته المزامنة. يُسجَّل ديناً ثانوياً.")
                    ->fillForm(fn (AccountRenewal $r) => ['amount' => $r->amount ?: ($r->account?->currentPlan?->price ?: null)])
                    ->schema([TextInput::make('amount')->label('مبلغ الدين (د.ع)')->integer()->minValue(1)->required()])
                    ->modalSubmitActionLabel('تسجيل الدين الثانوي')
                    ->action(function (AccountRenewal $record, array $data) {
                        $renewal = app(RenewalService::class)->approveSecondary($record, (int) $data['amount']);
                        Notification::make()->success()->title('سُجّل دين ثانوي '.Money::format((int) $data['amount']))
                            ->body($renewal->debt?->number)->send();
                    }),
                Action::make('no')->label('لا')->icon(Heroicon::OutlinedXMark)->color('gray')->button()
                    ->requiresConfirmation()
                    ->modalHeading(fn (AccountRenewal $r) => 'بدون دين على '.$r->subscriber?->full_name.'؟')
                    ->modalDescription('يبقى التفعيل مسجّلاً بدون دين ثانوي.')
                    ->modalSubmitActionLabel('بدون دين')
                    ->action(function (AccountRenewal $record) {
                        app(RenewalService::class)->declineSecondary($record);
                        Notification::make()->success()->title('سُجّل بدون دين')->send();
                    }),
            ]);
    }
}
