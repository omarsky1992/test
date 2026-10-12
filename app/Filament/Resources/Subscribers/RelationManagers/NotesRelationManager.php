<?php

namespace App\Filament\Resources\Subscribers\RelationManagers;

use App\Models\SubscriberNote;
use App\Services\SubscriberNoteService;
use App\Support\SubscriberFlags;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * «وضع المشترك»: every note on the subscriber, newest first, with who wrote it and when.
 */
class NotesRelationManager extends RelationManager
{
    protected static string $relationship = 'notes';

    protected static ?string $title = 'وضع المشترك';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()->can('notes.view');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $n = SubscriberFlags::activeNotes($ownerRecord->getKey());

        return $n > 0 ? (string) $n : null;
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        return 'warning';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['creator', 'closer']))
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('لا توجد ملاحظات')
            ->columns([
                TextColumn::make('created_at')->label('الوقت')->dateTime('Y/m/d H:i')->sortable(),
                TextColumn::make('body')->label('الملاحظة')->wrap(),
                TextColumn::make('creator.name')->label('كتبها')->placeholder('—'),
                TextColumn::make('status')->label('الحالة')->badge()
                    ->formatStateUsing(fn (string $state) => SubscriberNote::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => $state === 'active' ? 'warning' : 'gray')
                    ->description(fn (SubscriberNote $r) => $r->closed_at
                        ? 'أنهاها '.($r->closer?->name ?? '—').' '.$r->closed_at->setTimezone(config('app.timezone'))->format('Y/m/d').($r->close_reason ? ': '.$r->close_reason : '')
                        : null),
            ])
            ->filters([
                SelectFilter::make('status')->label('الحالة')->options(SubscriberNote::STATUSES),
            ])
            ->headerActions([
                Action::make('addNote')->label('إضافة ملاحظة')->icon(Heroicon::OutlinedPlus)
                    ->visible(fn () => auth()->user()->can('notes.create'))
                    ->schema([Textarea::make('body')->label('الملاحظة')->required()->rows(3)->maxLength(2000)
                        ->placeholder('مثال: تم الاتصال به ولم يرد')])
                    ->action(function (array $data) {
                        app(SubscriberNoteService::class)->add($this->getOwnerRecord(), $data['body']);
                        Notification::make()->success()->title('حُفظت الملاحظة')->send();
                    }),
            ])
            ->recordActions([
                Action::make('closeNote')->label('إنهاء')->icon(Heroicon::OutlinedCheckCircle)->color('success')
                    ->visible(fn (SubscriberNote $r) => $r->isActive() && auth()->user()->can('notes.close'))
                    ->modalHeading('إنهاء الملاحظة')->modalDescription('تختفي من الملاحظات النشطة وتبقى في السجل.')
                    ->schema([TextInput::make('reason')->label('سبب الإنهاء (اختياري)')->maxLength(300)])
                    ->action(function (SubscriberNote $record, array $data) {
                        app(SubscriberNoteService::class)->close($record, $data['reason'] ?? null);
                        Notification::make()->success()->title('أُنهيت الملاحظة')->send();
                    }),
                Action::make('deleteNote')->label('حذف')->icon(Heroicon::OutlinedTrash)->color('danger')
                    ->visible(fn () => auth()->user()->can('notes.delete'))
                    ->requiresConfirmation()->modalHeading('حذف الملاحظة؟')
                    ->modalDescription('تُحذف من الشاشات (يبقى أثرها في سجل العمليات). لإنهاء غرضها استخدم «إنهاء» بدل الحذف.')
                    ->schema([TextInput::make('reason')->label('سبب الحذف')->maxLength(300)])
                    ->action(function (SubscriberNote $record, array $data) {
                        app(SubscriberNoteService::class)->delete($record, $data['reason'] ?? null);
                        Notification::make()->success()->title('حُذفت الملاحظة')->send();
                    }),
            ]);
    }
}
