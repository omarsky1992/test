<?php

namespace App\Models;

use App\Enums\ActivationKind;
use App\Enums\CompletedVia;
use App\Enums\CompletionStatus;
use App\Enums\DocumentStatus;
use App\Enums\Settlement;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Unguarded]
class Activation extends Model
{
    protected function casts(): array
    {
        return [
            'kind' => ActivationKind::class,
            'settlement' => Settlement::class,
            'completion_status' => CompletionStatus::class,
            'completed_via' => CompletedVia::class,
            'status' => DocumentStatus::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
            'start_overridden' => 'boolean',
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

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ServicePlan::class, 'plan_id');
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function periods(): HasMany
    {
        return $this->hasMany(ActivationPeriod::class);
    }

    public function debt(): HasOne
    {
        return $this->hasOne(Debt::class);
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
