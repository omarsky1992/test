<?php

namespace App\Filament\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\Account;
use App\Models\Subscriber;
use App\Models\SubscriberNote;
use App\Models\WhatsappNumber;
use App\Services\SubscriberNoteService;
use App\Support\SubscriberFlags;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Exceptions\Halt;
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
                ...self::reminderFields(),
            ] : [])
            ->modalSubmitActionLabel('حفظ الملاحظة')
            ->modalSubmitAction(fn (Action $action) => auth()->user()->can('notes.create') ? $action : false)
            ->modalCancelActionLabel('إغلاق')
            ->action(function (array $data, ?Model $record) use ($resolve) {
                if (blank($data['body'] ?? null)) {
                    return;
                }
                self::save($resolve($record), $data);
            });
    }

    /** «ذكّرني»: an optional WhatsApp reminder for the note, to all the authorized numbers or one. */
    public static function reminderFields(): array
    {
        return [
            Select::make('remind_preset')->label('ذكّرني بواتساب (اختياري)')->placeholder('بدون تذكير')->live()
                ->options(['1h' => 'بعد ساعة', '1d' => 'بعد يوم', '2d' => 'بعد يومين', '3d' => 'بعد 3 أيام', '5d' => 'بعد 5 أيام', '7d' => 'بعد أسبوع', 'custom' => 'موعد أختاره'])
                ->visible(fn () => WhatsappNumber::where('is_active', true)->exists()),
            DateTimePicker::make('remind_at')->label('موعد التذكير')->seconds(false)->native(false)
                ->minDate(fn () => now()->startOfDay())->required(fn (Get $get) => $get('remind_preset') === 'custom')
                ->visible(fn (Get $get) => $get('remind_preset') === 'custom'),
            // Several numbers can be chosen; none chosen means all the authorized numbers.
            Select::make('remind_to')->label('يصل التذكير إلى')->multiple()->placeholder('كل الأرقام المخولة')
                ->helperText('اختر موظفاً أو أكثر، أو اتركه فارغاً ليصل لكل الأرقام المخولة.')
                ->options(fn () => WhatsappNumber::with('user')->where('is_active', true)->get()
                    ->mapWithKeys(fn (WhatsappNumber $n) => [$n->id => trim(($n->label ?: $n->user?->name ?: 'رقم').' – '.$n->phone)])->all())
                ->visible(fn (Get $get) => filled($get('remind_preset'))),
        ];
    }

    /** Saves a note from the form, with its reminder when one was chosen. */
    public static function save(Subscriber $subscriber, array $data): void
    {
        $preset = $data['remind_preset'] ?? null;
        $remindAt = match ($preset) {
            null, '' => null,
            'custom' => filled($data['remind_at'] ?? null) ? CarbonImmutable::parse($data['remind_at'], config('app.timezone')) : null,
            '1h' => CarbonImmutable::now()->addHour(),
            default => CarbonImmutable::now()->addDays((int) $preset),
        };
        $to = array_values(array_filter((array) ($data['remind_to'] ?? []), fn ($v) => $v !== 'all' && $v !== ''));
        try {
            $note = app(SubscriberNoteService::class)->add($subscriber, $data['body'], $remindAt, $remindAt && $to ? array_map('intval', $to) : null);
        } catch (BusinessRuleException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw new Halt;
        }
        Notification::make()->success()->title('حُفظت الملاحظة')
            ->body($note->remind_at ? '⏰ التذكير بواتساب: '.$note->remind_at->setTimezone(config('app.timezone'))->format('Y/m/d H:i').' ← '.$note->remindLabel() : null)->send();
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
