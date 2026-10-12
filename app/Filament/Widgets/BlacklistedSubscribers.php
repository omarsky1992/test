<?php

namespace App\Filament\Widgets;

use App\Filament\Actions\NoteActions;
use App\Filament\Resources\Blacklist\BlacklistResource;
use App\Filament\Resources\Subscribers\SubscriberResource;
use App\Models\BlacklistEntry;
use App\Services\BlacklistService;
use App\Support\SubscriberFlags;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * «القائمة السوداء» on the home page: who is on it now, why, who added them and when.
 */
class BlacklistedSubscribers extends TableWidget
{
    protected static ?int $sort = 6;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = '⛔ القائمة السوداء';

    public static function canView(): bool
    {
        return auth()->user()->can('blacklist.view');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => BlacklistEntry::query()->current()->with(['subscriber', 'adder'])->latest('added_at'))
            ->recordClasses(fn (BlacklistEntry $record) => SubscriberFlags::rowClass($record->subscriber_id))
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading('القائمة السوداء فارغة')
            ->columns([
                SubscriberFlags::mark(TextColumn::make('subscriber.full_name')->label('المشترك')->weight('bold')
                    ->url(fn (BlacklistEntry $r) => SubscriberFlags::subscriberUrl($r->subscriber_id)), fn (BlacklistEntry $r) => $r->subscriber_id),
                TextColumn::make('subscriber.code')->label('رقم المشترك')->placeholder('—')
                    ->description(fn (BlacklistEntry $r) => $r->subscriber?->phone),
                TextColumn::make('reason_type')->label('السبب')->badge()->color('danger')
                    ->formatStateUsing(fn (string $state) => BlacklistEntry::REASONS[$state] ?? $state)
                    ->description(fn (BlacklistEntry $r) => $r->reason)->wrap(),
                TextColumn::make('adder.name')->label('أضافه')->placeholder('—'),
                TextColumn::make('added_at')->label('التاريخ')->dateTime('Y/m/d'),
            ])
            ->headerActions([
                Action::make('allBlacklist')->label('فتح القائمة السوداء')->link()->icon('heroicon-m-arrow-left')
                    ->url(fn () => BlacklistResource::getUrl()),
            ])
            ->recordActions([
                NoteActions::open(),
                Action::make('open')->label('كشف المشترك')->icon(Heroicon::OutlinedEye)->color('gray')->iconButton()
                    ->url(fn (BlacklistEntry $r) => SubscriberResource::getUrl('view', ['record' => $r->subscriber_id])),
                Action::make('remove')->label('إزالة')->icon(Heroicon::OutlinedArrowUturnLeft)->color('gray')->iconButton()
                    ->visible(fn () => auth()->user()->can('blacklist.manage'))
                    ->modalHeading(fn (BlacklistEntry $r) => 'إزالة '.$r->subscriber?->full_name.' من القائمة السوداء')
                    ->schema([TextInput::make('reason')->label('السبب (اختياري)')->maxLength(300)])
                    ->action(function (BlacklistEntry $record, array $data) {
                        app(BlacklistService::class)->remove($record, $data['reason'] ?? null);
                        Notification::make()->success()->title('أُزيل من القائمة السوداء')->send();
                    }),
            ]);
    }
}
