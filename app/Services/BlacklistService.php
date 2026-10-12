<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\BlacklistEntry;
use App\Models\Subscriber;
use App\Support\SubscriberFlags;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * «القائمة السوداء»: internal follow-up only. Adding or removing a subscriber changes nothing else:
 * no subscription, debt, payment or activation is touched, and nothing is blocked.
 */
class BlacklistService
{
    public function __construct(private Audit $audit) {}

    public function add(Subscriber $subscriber, string $reasonType, ?string $reason = null): BlacklistEntry
    {
        $this->authorize();
        if (! array_key_exists($reasonType, BlacklistEntry::REASONS)) {
            throw new BusinessRuleException('اختر سبب الإضافة.');
        }
        $reason = filled($reason) ? mb_substr(trim($reason), 0, 500) : null;
        if ($reasonType === 'other' && $reason === null) {
            throw new BusinessRuleException('اكتب السبب.');
        }
        if (BlacklistEntry::current()->where('subscriber_id', $subscriber->id)->exists()) {
            throw new BusinessRuleException("{$subscriber->full_name} في القائمة السوداء مسبقاً.");
        }

        try {
            return DB::transaction(function () use ($subscriber, $reasonType, $reason) {
                $entry = BlacklistEntry::create([
                    'subscriber_id' => $subscriber->id, 'reason_type' => $reasonType, 'reason' => $reason,
                    'added_by' => Auth::id(), 'added_at' => now(),
                ]);
                $this->audit->log('blacklist.added', $entry, null, ['reason' => $entry->reasonLabel(), 'details' => $reason], subscriberId: $subscriber->id);
                SubscriberFlags::forget();

                return $entry;
            });
        } catch (UniqueConstraintViolationException) {
            throw new BusinessRuleException("{$subscriber->full_name} في القائمة السوداء مسبقاً.");
        }
    }

    public function remove(BlacklistEntry $entry, ?string $reason = null): BlacklistEntry
    {
        $this->authorize();
        if ($entry->removed_at !== null) {
            throw new BusinessRuleException('أُزيل من القائمة مسبقاً.');
        }

        return DB::transaction(function () use ($entry, $reason) {
            $entry->update(['removed_at' => now(), 'removed_by' => Auth::id(), 'remove_reason' => filled($reason) ? mb_substr(trim($reason), 0, 300) : null]);
            $this->audit->log('blacklist.removed', $entry, ['listed' => true], ['listed' => false], $entry->remove_reason, $entry->subscriber_id);
            SubscriberFlags::forget();

            return $entry;
        });
    }

    private function authorize(): void
    {
        if (! Auth::user()?->can('blacklist.manage')) {
            throw new AuthorizationException('ليست لديك صلاحية إدارة القائمة السوداء.');
        }
    }
}
