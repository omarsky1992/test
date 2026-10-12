<?php

namespace App\Filament\Resources\Blacklist;

use App\Filament\Actions\NoteActions;
use App\Filament\Resources\Blacklist\Pages\ManageBlacklist;
use App\Filament\Resources\Subscribers\SubscriberResource;
use App\Models\BlacklistEntry;
use App\Models\Subscriber;
use App\Services\BlacklistService;
use App\Support\SubscriberFlags;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * «القائمة السوداء»: subscribers who keep breaking their payment promises, for internal follow-up
 * only. Being on it changes and blocks nothing.
 */
class BlacklistResource extends Resource
{
    protected static ?string $model = BlacklistEntry::class;

    protected static ?string $slug = 'blacklist';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;

    protected static string|UnitEnum|null $navigationGroup = 'العمليات اليومية';

    protected static ?int $navigationSort = 8;

    protected static ?string $modelLabel = 'مشترك في القائمة السوداء';

    protected static ?string $pluralModelLabel = 'القائمة السوداء';

    public static function canViewAny(): bool
    {
        return auth()->user()->can('blacklist.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            // A subscriber with an active note is shaded wherever it appears, until the note is closed.
            ->recordClasses(fn ($record) => SubscriberFlags::rowClass($record->subscriber_id))
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['subscriber', 'adder', 'remover']))
            ->defaultSort('added_at', 'desc')
            ->emptyStateHeading('القائمة السوداء فارغة')
            ->columns([
                TextColumn::make('subscriber.full_name')->label('المشترك')->weight('bold')
                    ->url(fn (BlacklistEntry $r) => SubscriberResource::getUrl('view', ['record' => $r->subscriber_id]))
                    ->description(fn (BlacklistEntry $r) => collect([$r->subscriber?->code, $r->subscriber?->external_id ? "ID {$r->subscriber->external_id}" : null, $r->subscriber?->phone])->filter()->implode(' · '))
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereIn('subscriber_id', Subscriber::query()->search($search)->select('subscribers.id'))),
                TextColumn::make('reason_type')->label('السبب')->badge()->color('danger')
                    ->formatStateUsing(fn (string $state) => BlacklistEntry::REASONS[$state] ?? $state)
                    ->description(fn (BlacklistEntry $r) => $r->reason)->wrap(),
                TextColumn::make('added_at')->label('تاريخ الإضافة')->dateTime('Y/m/d H:i')->sortable(),
                TextColumn::make('adder.name')->label('أضافه')->placeholder('—'),
                TextColumn::make('removed_at')->label('الحالة')->badge()
                    ->state(fn (BlacklistEntry $r) => $r->removed_at ? 'أُزيل' : 'في القائمة')
                    ->color(fn (BlacklistEntry $r) => $r->removed_at ? 'gray' : 'danger')
                    ->description(fn (BlacklistEntry $r) => $r->removed_at
                        ? ($r->remover?->name ?? '—').' '.$r->removed_at->setTimezone(config('app.timezone'))->format('Y/m/d').($r->remove_reason ? ': '.$r->remove_reason : '')
                        : null),
            ])
            ->filters([
                TernaryFilter::make('current')->label('الحالة')->placeholder('الكل')->trueLabel('في القائمة الآن')->falseLabel('أُزيلوا')
                    ->default(true)
                    ->queries(true: fn (Builder $q) => $q->whereNull('removed_at'), false: fn (Builder $q) => $q->whereNotNull('removed_at'), blank: fn (Builder $q) => $q),
            ])
            ->recordActions([
                NoteActions::open(),
                Action::make('remove')->label('إزالة من القائمة')->icon(Heroicon::OutlinedArrowUturnLeft)->color('gray')
                    ->visible(fn (BlacklistEntry $r) => $r->removed_at === null && auth()->user()->can('blacklist.manage'))
                    ->modalHeading(fn (BlacklistEntry $r) => 'إزالة '.$r->subscriber?->full_name.' من القائمة السوداء')
                    ->schema([TextInput::make('reason')->label('السبب (اختياري)')->maxLength(300)])
                    ->action(function (BlacklistEntry $record, array $data) {
                        app(BlacklistService::class)->remove($record, $data['reason'] ?? null);
                        Notification::make()->success()->title('أُزيل من القائمة السوداء')->send();
                    }),
            ]);
    }

    /** Adding a subscriber, searched by name, phone, code, ID or username. */
    public static function addAction(?Subscriber $subscriber = null): Action
    {
        return Action::make('addToBlacklist')->label('إضافة إلى القائمة السوداء')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
            ->visible(fn () => auth()->user()->can('blacklist.manage')
                && ($subscriber === null || ! SubscriberFlags::blacklisted($subscriber->id)))
            ->modalDescription('للمتابعة الداخلية فقط: لا يغيّر الاشتراك أو الديون أو المدفوعات، ولا يمنع التجديد أو التفعيل.')
            ->schema(array_values(array_filter([
                $subscriber ? null : Select::make('subscriber_id')->label('المشترك')->required()->searchable()
                    ->helperText('ابحث بالاسم أو الهاتف أو رقم المشترك أو اليوزر.')
                    ->getSearchResultsUsing(fn (string $search) => Subscriber::query()->search($search)->limit(20)->get()
                        ->mapWithKeys(fn (Subscriber $s) => [$s->id => trim("{$s->full_name} · {$s->code} ".($s->phone ?? ''))])->all())
                    ->getOptionLabelUsing(fn ($value) => Subscriber::find($value)?->full_name),
                Select::make('reason_type')->label('السبب')->required()->options(BlacklistEntry::REASONS)->live(),
                Textarea::make('reason')->label('التفاصيل')->rows(2)->maxLength(500)
                    ->required(fn (Get $get) => $get('reason_type') === 'other'),
            ])))
            ->action(function (array $data) use ($subscriber) {
                $target = $subscriber ?? Subscriber::findOrFail($data['subscriber_id']);
                app(BlacklistService::class)->add($target, $data['reason_type'], $data['reason'] ?? null);
                Notification::make()->success()->title("أُضيف {$target->full_name} إلى القائمة السوداء")->send();
            });
    }

    public static function getPages(): array
    {
        return ['index' => ManageBlacklist::route('/')];
    }
}
