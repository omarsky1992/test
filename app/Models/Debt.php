<?php

namespace App\Models;

use App\Enums\DebtBucket;
use App\Enums\DebtSource;
use App\Enums\DebtStatus;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Unguarded]
class Debt extends Model
{
    protected function casts(): array
    {
        return [
            'source' => DebtSource::class,
            'bucket' => DebtBucket::class,
            'status' => DebtStatus::class,
            'debt_date' => 'immutable_datetime',
            'due_date' => 'immutable_date',
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

    public function activation(): BelongsTo
    {
        return $this->belongsTo(Activation::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(DebtTransfer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
