<?php

namespace App\Platform;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;

/**
 * A client (agent) of the platform. Its data lives in its own database ({@see $database}); this row
 * only knows how to reach it and how long the client may use the system.
 */
#[Unguarded]
class Tenant extends Model
{
    protected $connection = 'platform';

    protected function casts(): array
    {
        return [
            'is_permanent' => 'boolean',
            'trial_ends_at' => 'immutable_datetime',
            'subscription_starts_at' => 'immutable_datetime',
            'subscription_ends_at' => 'immutable_datetime',
            'stats_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
            'grace_days' => 'integer',
        ];
    }
}
