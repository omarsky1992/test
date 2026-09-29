<?php

namespace App\Models;

use App\Enums\FollowUpChannel;
use App\Enums\FollowUpOutcome;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Unguarded]
class FollowUp extends Model
{
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'channel' => FollowUpChannel::class,
            'outcome' => FollowUpOutcome::class,
            'promised_at' => 'immutable_datetime',
            'next_follow_up_at' => 'immutable_datetime',
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

    public function activation(): BelongsTo
    {
        return $this->belongsTo(Activation::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
