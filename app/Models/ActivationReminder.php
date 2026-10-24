<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

#[Unguarded]
class ActivationReminder extends Model
{
    protected function casts(): array
    {
        return [
            'number_ids' => 'array',
            'ends_at_when_set' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPending(): bool
    {
        return $this->sent_at === null && $this->cancelled_at === null;
    }

    /** The chosen authorized numbers, or all active ones when none was chosen. */
    public function numbers(): Collection
    {
        return WhatsappNumber::with('user')->where('is_active', true)
            ->when($this->number_ids, fn ($q) => $q->whereIn('id', $this->number_ids))
            ->orderBy('id')->get()->filter(fn (WhatsappNumber $n) => $n->user?->is_active ?? true)->values();
    }

    public function numbersLabel(): string
    {
        return $this->number_ids
            ? WhatsappNumber::with('user')->whereIn('id', $this->number_ids)->orderBy('id')->get()->map(fn (WhatsappNumber $n) => $n->label ?: $n->user?->name ?: $n->phone)->implode('، ')
            : 'كل الأرقام المخولة';
    }
}
