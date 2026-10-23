<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * «وضع المشترك»: a note about a subscriber (called, promised to pay in 7 days…). Active until it is
 * closed; closed notes stay in the history. Deleting only hides it. Nothing here touches money.
 */
#[Unguarded]
class SubscriberNote extends Model
{
    use SoftDeletes;

    const UPDATED_AT = null;

    public const STATUSES = ['active' => 'نشطة', 'closed' => 'منتهية'];

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
            'remind_at' => 'immutable_datetime',
            'reminded_at' => 'immutable_datetime',
            'remind_number_ids' => 'array',
        ];
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', 'active');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /** The authorized numbers the reminder goes to: the chosen ones, or all active ones when none was chosen. */
    public function remindNumbers(): Collection
    {
        return WhatsappNumber::with('user')->where('is_active', true)
            ->when($this->remind_number_ids, fn ($q) => $q->whereIn('id', $this->remind_number_ids))
            ->orderBy('id')->get()->filter(fn (WhatsappNumber $n) => $n->user?->is_active ?? true)->values();
    }

    /** «كل الأرقام المخولة» or the chosen numbers' names. */
    public function remindLabel(): string
    {
        return $this->remind_number_ids
            ? WhatsappNumber::with('user')->whereIn('id', $this->remind_number_ids)->orderBy('id')->get()->map(fn (WhatsappNumber $n) => $n->label ?: $n->user?->name ?: $n->phone)->implode('، ')
            : 'كل الأرقام المخولة';
    }
}
