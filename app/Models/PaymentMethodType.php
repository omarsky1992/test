<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A way of receiving money (cash, Zain Cash, Qi card, bank transfer…), managed by the admin.
 * Its category maps to the PaymentMethod enum used for summaries.
 */
#[Unguarded]
#[Table('payment_methods')]
class PaymentMethodType extends Model
{
    protected function casts(): array
    {
        return [
            'category' => PaymentMethod::class,
            'requires_receiver' => 'boolean',
            'requires_reference' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }
}
