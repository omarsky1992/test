<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Unguarded]
class AccountRenewal extends Model
{
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'detected_at' => 'immutable_datetime',
            'previous_ends_at' => 'immutable_datetime',
            'new_ends_at' => 'immutable_datetime',
            'amount' => 'integer',
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

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ServicePlan::class);
    }

    public function debt(): BelongsTo
    {
        return $this->belongsTo(Debt::class);
    }
}
