<?php

namespace App\Models;

use App\Enums\AccountStatus;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Unguarded]
#[Hidden(['secret_encrypted'])]
class Account extends Model
{
    protected function casts(): array
    {
        return [
            'status' => AccountStatus::class,
            'service_ends_at' => 'immutable_datetime',
            'external_ends_at' => 'immutable_datetime',
            'external_synced_at' => 'immutable_datetime',
        ];
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function currentPlan(): BelongsTo
    {
        return $this->belongsTo(ServicePlan::class, 'current_plan_id');
    }

    public function activations(): HasMany
    {
        return $this->hasMany(Activation::class);
    }

    public function periods(): HasMany
    {
        return $this->hasMany(ActivationPeriod::class);
    }

    public function debts(): HasMany
    {
        return $this->hasMany(Debt::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }
}
