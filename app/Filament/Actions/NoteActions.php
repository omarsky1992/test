<?php

namespace App\Filament\Actions;

use App\Models\Account;
use App\Models\Subscriber;
use App\Models\SubscriberNote;
use App\Services\SubscriberNoteService;
use App\Support\SubscriberFlags;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

/**
 * «وضع المشترك» from any screen that shows a subscriber (its page, a card, a debt): the notes so far
 * and a box to write a new one.
 */
class NoteActions
{
    public static function open(string $name = 'subscriberNotes', ?Subscriber $subscriber = null): Action
    {
        $resolve = fn (?Model $record) => $subscriber ?? self::subscriberOf($record);

        return Action::make($name)
            ->label(fn (?Model $record) => ($n = SubscriberFlags::activeNotes($resolve($record)?->id)) > 0 ? "وضع المشترك ({$n})" : 'وضع المشترك')
            ->icon(Heroicon::OutlinedChatBubbleBottomCenterText)
            ->color(fn (?Model $record) => SubscriberFlags::hasNotes($resolve($record)?->id) ? 'warning' : 'gray')
            ->visible(fn () => auth()->user()->can('notes.view'))
            ->modalHeading(fn (?Model $record) => 'وضع المشترك: '.$resolve($record)?->full_name)
            ->modalWidth('2xl')
            ->modalContent(fn (?Model $record) => view('filament.notes.list', [
                'subscriber' => $resolve($record),
                'notes' => SubscriberNote::with(['creator', 'closer'])->where('subscriber_id', $resolve($record)?->id)
                    ->orderByRaw("status = 'active' desc")->latest('created_at')->latest('id')->limit(30)->get(),
            ]))
            ->schema(fn () => auth()->user()->can('notes.create') ? [
                Textarea::make('body')->label('ملاحظة جديدة')->rows(3)->maxLength(2000)
                    ->placeholder('مثال: تم الاتصال بالمشترك، وعد بتسديد الدين خلال 7 أيام'),
            ] : [])
            ->modalSubmitActionLabel('حفظ الملاحظة')
            ->modalSubmitAction(fn (Action $action) => auth()->user()->can('notes.create') ? $action : false)
            ->modalCancelActionLabel('إغلاق')
            ->action(function (array $data, ?Model $record) use ($resolve) {
                if (blank($data['body'] ?? null)) {
                    return;
                }
                app(SubscriberNoteService::class)->add($resolve($record), $data['body']);
                Notification::make()->success()->title('حُفظت الملاحظة')->send();
            });
    }

    public static function subscriberOf(?Model $record): ?Subscriber
    {
        return match (true) {
            $record instanceof Subscriber => $record,
            $record instanceof Account => $record->subscriber,
            $record !== null && isset($record->subscriber_id) => Subscriber::find($record->subscriber_id),
            default => null,
        };
    }
}
