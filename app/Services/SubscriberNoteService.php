<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Subscriber;
use App\Models\SubscriberNote;
use App\Models\WhatsappNumber;
use App\Support\SubscriberFlags;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * «وضع المشترك»: writing, closing and deleting notes. Each step checks its own permission here, not
 * only in the screens. Notes never change a subscription, a debt or any money.
 */
class SubscriberNoteService
{
    public function __construct(private Audit $audit) {}

    /**
     * $remindAt: a WhatsApp reminder at that time (in the future) to the authorized numbers, or only to
     * the $remindNumberIds chosen. It is sent once, and not at all if the note was closed or deleted before.
     */
    public function add(Subscriber $subscriber, string $body, ?\DateTimeInterface $remindAt = null, ?array $remindNumberIds = null): SubscriberNote
    {
        $this->authorize('notes.create');
        $remindAt = $remindAt ? CarbonImmutable::instance($remindAt) : null;
        $body = trim($body);
        if ($body === '') {
            throw new BusinessRuleException('اكتب نص الملاحظة.');
        }
        if ($remindAt !== null && $remindAt->lessThanOrEqualTo(CarbonImmutable::now())) {
            throw new BusinessRuleException('اختر موعد تذكير بعد الوقت الحالي.');
        }
        $remindNumberIds = array_values(array_unique(array_map('intval', array_filter((array) $remindNumberIds)))) ?: null;
        if ($remindAt !== null && $remindNumberIds !== null && WhatsappNumber::whereIn('id', $remindNumberIds)->where('is_active', true)->count() !== count($remindNumberIds)) {
            throw new BusinessRuleException('أحد الأرقام المختارة للتذكير غير مفعّل.');
        }

        return DB::transaction(function () use ($subscriber, $body, $remindAt, $remindNumberIds) {
            $note = SubscriberNote::create([
                'subscriber_id' => $subscriber->id, 'body' => mb_substr($body, 0, 2000), 'status' => 'active', 'created_by' => Auth::id(),
                'remind_at' => $remindAt, 'remind_number_ids' => $remindAt ? $remindNumberIds : null,
            ]);
            $this->audit->log('note.created', $note, null, array_filter([
                'body' => $note->body, 'remind_at' => $remindAt?->toIso8601String(), 'remind_number_ids' => $note->remind_number_ids,
            ], fn ($v) => $v !== null), subscriberId: $subscriber->id);
            SubscriberFlags::forget();

            return $note;
        });
    }

    /** Ends a note: it leaves the active lists and stays in the history. */
    public function close(SubscriberNote $note, ?string $reason = null): SubscriberNote
    {
        $this->authorize('notes.close');
        if (! $note->isActive()) {
            throw new BusinessRuleException('الملاحظة منتهية مسبقاً.');
        }

        return DB::transaction(function () use ($note, $reason) {
            $note->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => Auth::id(), 'close_reason' => filled($reason) ? mb_substr(trim($reason), 0, 300) : null]);
            $this->audit->log('note.closed', $note, ['status' => 'active'], ['status' => 'closed'], $note->close_reason, $note->subscriber_id);
            SubscriberFlags::forget();

            return $note;
        });
    }

    /** Hides a note (written by mistake); who deleted it and when are kept, and the audit log keeps its text. */
    public function delete(SubscriberNote $note, ?string $reason = null): void
    {
        $this->authorize('notes.delete');
        DB::transaction(function () use ($note, $reason) {
            $note->update(['deleted_by' => Auth::id()]);
            $note->delete();
            $this->audit->log('note.deleted', $note, ['body' => $note->body, 'status' => $note->status], null, $reason, $note->subscriber_id);
            SubscriberFlags::forget();
        });
    }

    private function authorize(string $permission): void
    {
        if (! Auth::user()?->can($permission)) {
            throw new AuthorizationException('ليست لديك صلاحية لهذا الإجراء.');
        }
    }
}
