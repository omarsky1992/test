<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Subscriber;
use App\Models\SubscriberNote;
use App\Support\SubscriberFlags;
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

    public function add(Subscriber $subscriber, string $body): SubscriberNote
    {
        $this->authorize('notes.create');
        $body = trim($body);
        if ($body === '') {
            throw new BusinessRuleException('اكتب نص الملاحظة.');
        }

        return DB::transaction(function () use ($subscriber, $body) {
            $note = SubscriberNote::create([
                'subscriber_id' => $subscriber->id, 'body' => mb_substr($body, 0, 2000), 'status' => 'active', 'created_by' => Auth::id(),
            ]);
            $this->audit->log('note.created', $note, null, ['body' => $note->body], subscriberId: $subscriber->id);
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
