<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Unguarded]
class WhatsappNumber extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'receives_alerts' => 'boolean',
            'last_used_at' => 'immutable_datetime',
        ];
    }

    /**
     * International digits without + or leading zeros, as WhatsApp sends them: 07701234567,
     * +964 770 123 4567 and 009647701234567 all become 9647701234567.
     */
    public static function normalize(?string $phone): string
    {
        return preg_replace('/^00/', '', \App\Support\Arabic::phone($phone));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WhatsappMessage::class);
    }
}
