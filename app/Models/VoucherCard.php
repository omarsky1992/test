<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A prepaid internet or sports-channel card in stock. */
#[Unguarded]
class VoucherCard extends Model
{
    const UPDATED_AT = null;

    public const TYPES = ['internet' => 'كارت إنترنت', 'sports' => 'كارت قنوات رياضية'];

    public const STATUSES = ['available' => 'متوفر', 'activated' => 'مصروف (مستخدم)', 'void' => 'ملغى / تالف'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'activated_at' => 'immutable_datetime', 'voided_at' => 'immutable_datetime'];
    }

    /** The code as this user may see it: whole with «إظهار أرقام الكارتات», otherwise its last 4 characters. */
    public function visibleCode(): string
    {
        return auth()->user()?->can('stock.view_codes') ? $this->code : str_repeat('•', max(0, mb_strlen($this->code) - 4)).mb_substr($this->code, -4);
    }

    public function adder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    public function activator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }
}
