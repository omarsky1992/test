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
            'company_synced_at' => 'immutable_datetime',
            'company_days_left' => 'integer',
        ];
    }

    public function renewals(): HasMany
    {
        return $this->hasMany(AccountRenewal::class)->orderByDesc('detected_at');
    }

    /**
     * Whole days left before a company end date, counting the last partial day as 0, so a
     * subscription that ends today reads 0 and its renewal is caught.
     */
    public static function daysLeft(?\DateTimeInterface $endsAt, ?\DateTimeInterface $now = null): ?int
    {
        if ($endsAt === null) {
            return null;
        }
        $seconds = $endsAt->getTimestamp() - ($now ?? now())->getTimestamp();

        return $seconds <= 0 ? 0 : min(32767, intdiv($seconds, 86400));
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
