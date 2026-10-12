<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Unguarded]
class Payment extends Model
{
    protected function casts(): array
    {
        return [
            'payment_type' => PaymentType::class,
            'method' => PaymentMethod::class,
            'status' => DocumentStatus::class,
            'received_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
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

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }

    /** The WhatsApp message telling the subscriber the payment arrived, if one was queued. */
    public function whatsappNotice(): HasOne
    {
        return $this->hasOne(WhatsappOutbox::class)->where('kind', 'sub_payment');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PaymentLine::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
