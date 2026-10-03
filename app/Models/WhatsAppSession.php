<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;

#[Unguarded]
class WhatsAppSession extends Model
{
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'last_message_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
