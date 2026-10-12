<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A message a user wrote and sent to the subscribers they picked; each recipient is a row in the
 * outbox with its own status.
 */
#[Unguarded]
class WhatsappBroadcast extends Model
{
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WhatsappOutbox::class, 'broadcast_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
