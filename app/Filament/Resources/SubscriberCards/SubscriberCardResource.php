<?php

namespace App\Filament\Resources\SubscriberCards;

use App\Enums\DebtBucket;
use App\Enums\DebtStatus;
use App\Exceptions\BusinessRuleException;
use App\Filament\Actions\Operations;
use App\Filament\Pages\WhatsappReminders;
use App\Filament\Resources\SubscriberCards\Pages\ListSubscriberCards;
use App\Filament\Resources\Subscribers\SubscriberResource;
use App\Models\Account;
use App\Models\ActivationDue;
use App\Models\Subscriber;
use App\Services\ActivationDueService;
use App\Support\SubscriberStatus;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The subscribers as coloured cards (phone-friendly), with the day-to-day actions on each card.
 */
class SubscriberCardResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static ?string $slug = 'cards';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'العمليات اليومية';

    protected static ?int $navigationSort = 0;

    protected static ?string $modelLabel = 'مشترك';

    protected static ?string $pluralModelLabel = 'المشتركون (بطاقات)';

    public static function canViewAny(): bool
    {
        return auth()->user()->can('accounts.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $open = fn (DebtBucket $bucket) => fn (Builder $d) => $d->where('bucket', $bucket)->whereIn('status', [DebtStatus::Open, DebtStatus::Partial]);

        return SubscriberStatus::base()
            ->with(['subscriber', 'currentPlan'])
            ->withSum(['debts as secondary_due' => $open(DebtBucket::Secondary)], 'balance')
            ->withSum(['debts as primary_due' => $open(DebtBucket::Primary)], 'balance')
            ->withExists(['activationDues as must_activate' => fn (Builder $q) => $q->where('status', 'pending')]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->contentGrid(['md' => 2, 'xl' => 3])
            ->paginated([12, 24, 48])
            ->defaultPaginationPageOption(12)
            ->modifyQueryUsing(fn (Builder $query) => $query->orderByRaw(SubscriberStatus::ENDS.' asc nulls last')->orderBy('accounts.id'))
            ->columns([
                Stack::make([ViewColumn::make('card')->view('filament.cards.account')
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn (Builder $q) => $q
                        ->whereIn('accounts.subscriber_id', Subscriber::query()->search($search)->select('subscribers.id'))
                        ->orWhere('accounts.username', 'ilike', '%'.addcslashes($search, '%_\\').'%')))]),
            ])
            ->recordUrl(null)
            ->recordActions([
                Operations::pay(),
                Operations::transfer()->visible(fn (Account $record) => (int) $record->secondary_due > 0),
                Operations::activate(),
                Action::make('markDone')->label('تم التفعيل')->icon(Heroicon::OutlinedCheckBadge)->color('primary')
                    ->visible(fn (Account $record) => $record->must_activate && auth()->user()->can('activations.mark_done'))
                    ->requiresConfirmation()->modalHeading('تم التفعيل على موقع الشركة؟')
                    ->action(function (Account $record) {
                        foreach (ActivationDue::where('account_id', $record->id)->where('status', 'pending')->get() as $due) {
                            try {
                                app(ActivationDueService::class)->markDone($due);
                            } catch (BusinessRuleException $e) {
                                Notification::make()->danger()->title($e->getMessage())->send();

                                return;
                            }
                        }
                        Notification::make()->success()->title('تم التفعيل')->send();
                    }),
                Action::make('call')->label('اتصال')->icon(Heroicon::OutlinedPhone)->color('gray')->iconButton()
                    ->visible(fn (Account $record) => filled($record->phone ?? $record->subscriber?->phone))
                    ->url(fn (Account $record) => 'tel:'.preg_replace('/[^\d+]/', '', $record->phone ?? $record->subscriber?->phone)),
                Action::make('remind')->label('واتساب')->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)->color('success')->iconButton()
                    ->visible(fn () => auth()->user()->can('whatsapp.remind'))
                    ->url(fn (Account $record) => WhatsappReminders::getUrl(['account' => $record->id])),
                Action::make('open')->label('كشف المشترك')->icon(Heroicon::OutlinedEye)->color('gray')->iconButton()
                    ->url(fn (Account $record) => SubscriberResource::getUrl('view', ['record' => $record->subscriber_id])),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListSubscriberCards::route('/')];
    }
}
