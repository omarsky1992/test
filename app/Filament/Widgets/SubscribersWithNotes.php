<?php

namespace App\Filament\Widgets;

use App\Filament\Actions\NoteActions;
use App\Models\Subscriber;
use App\Support\SubscriberFlags;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * «المشتركون الذين لديهم ملاحظات»: one row per subscriber with active notes (the newest one shown,
 * the count beside it). Closing a subscriber's last note takes it off this list.
 */
class SubscribersWithNotes extends TableWidget
{
    protected static ?int $sort = 5;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = '📝 المشتركون الذين لديهم ملاحظات';

    public static function canView(): bool
    {
        return auth()->user()->can('notes.view');
    }

    public function table(Table $table): Table
    {
        return $table
            // A subscriber with an active note is shaded wherever it appears, until the note is closed.
            ->recordClasses(fn ($record) => SubscriberFlags::rowClass($record->id))
            ->query(fn () => Subscriber::query()
                ->whereHas('notes', fn (Builder $n) => $n->where('status', 'active'))
                ->withCount(['notes as active_notes' => fn (Builder $n) => $n->where('status', 'active')])
                ->withMax(['notes as last_note_at' => fn (Builder $n) => $n->where('status', 'active')], 'created_at')
                ->with('latestActiveNote.creator')
                ->orderByDesc('last_note_at'))
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading('لا توجد ملاحظات نشطة')
            ->columns([
                SubscriberFlags::mark(TextColumn::make('full_name')->label('المشترك')->weight('bold')
                    ->url(fn (Subscriber $r) => SubscriberFlags::notesUrl($r->id)), fn (Subscriber $r) => $r->id),
                TextColumn::make('code')->label('رقم المشترك')->placeholder('—')
                    ->description(fn (Subscriber $r) => $r->external_id ? "ID {$r->external_id}" : null),
                TextColumn::make('latestActiveNote.body')->label('الملاحظة')->limit(70)->wrap()
                    ->tooltip(fn (Subscriber $r) => $r->latestActiveNote?->body),
                TextColumn::make('latestActiveNote.creator.name')->label('أضافها')->placeholder('—'),
                TextColumn::make('last_note_at')->label('التاريخ')->dateTime('Y/m/d H:i'),
                TextColumn::make('active_notes')->label('الحالة')->badge()->color('warning')
                    ->formatStateUsing(fn ($state) => (int) $state > 1 ? "{$state} ملاحظات نشطة" : 'نشطة'),
            ])
            ->recordActions([NoteActions::open()]);
    }
}
