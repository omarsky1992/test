<?php

namespace App\Models;

use App\Enums\SubscriberStatus;
use App\Support\Arabic;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Unguarded]
class Subscriber extends Model
{
    protected function casts(): array
    {
        return [
            'status' => SubscriberStatus::class,
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class)->orderBy('id');
    }

    public function activations(): HasMany
    {
        return $this->hasMany(Activation::class);
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

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * One search box for everything an employee may type: name (any alef/ya spelling),
     * phone in any format, subscriber code, username, serial or receipt number.
     */
    #[Scope]
    protected function search(Builder $query, string $term): void
    {
        $term = trim($term);
        if ($term === '') {
            return;
        }
        $like = '%'.addcslashes($term, '%_\\').'%';
        $name = '%'.addcslashes(Arabic::normalize($term), '%_\\').'%';
        $digits = ltrim(preg_replace('/^(00964|964)/', '', preg_replace('/\D/', '', Arabic::phone($term))), '0');

        $query->where(function (Builder $q) use ($like, $name, $digits) {
            $q->where('name_search', 'ilike', $name)
                ->orWhere('code', 'ilike', $like)
                ->orWhereHas('accounts', fn (Builder $a) => $a->where('username', 'ilike', $like)->orWhere('serial_number', 'ilike', $like))
                ->orWhereHas('payments', fn (Builder $p) => $p->where('receipt_number', 'ilike', $like));
            if (strlen($digits) >= 4) {
                $q->orWhere('phone_normalized', 'like', "%{$digits}%")
                    ->orWhereHas('accounts', fn (Builder $a) => $a->where('phone_normalized', 'like', "%{$digits}%"));
            }
        });
    }
}
