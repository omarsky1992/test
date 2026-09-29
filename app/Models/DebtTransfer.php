<?php

namespace App\Models;

use App\Enums\DebtBucket;
use App\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Unguarded]
class DebtTransfer extends Model
{
    protected function casts(): array
    {
        return [
            'from_bucket' => DebtBucket::class,
            'to_bucket' => DebtBucket::class,
            'status' => DocumentStatus::class,
            'performed_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
        ];
    }

    public function debt(): BelongsTo
    {
        return $this->belongsTo(Debt::class);
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

    public function period(): BelongsTo
    {
        return $this->belongsTo(ActivationPeriod::class, 'period_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
