<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Unguarded]
class PaymentLine extends Model
{
    const UPDATED_AT = null;

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethodType::class, 'payment_method_id');
    }

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }
}
