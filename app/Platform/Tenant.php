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
    protected $hidden = ['waha_api_key', 'waha_webhook_secret'];

    /** Its own WhatsApp (QR) service is set up (`deploy/client-whatsapp.sh`). */
    public function hasWhatsapp(): bool
    {
        return filled($this->waha_url) && filled($this->waha_api_key) && filled($this->waha_webhook_secret);
    }

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
            'waha_api_key' => 'encrypted',
            'waha_webhook_secret' => 'encrypted',
        ];
    }
}
