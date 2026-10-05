<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Enums\MoneyAccountKind;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['branch_id', 'name', 'username', 'email', 'phone', 'password', 'is_active', 'collects_to_custody', 'theme_color', 'ui_mode'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /** @var array<string, mixed> */
    protected $attributes = ['theme_color' => null, 'ui_mode' => null];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'immutable_datetime',
            'password_changed_at' => 'immutable_datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'collects_to_custody' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function custodyAccount(): HasOne
    {
        return $this->hasOne(MoneyAccount::class)->where('kind', MoneyAccountKind::Custody);
    }

    public function advances(): HasMany
    {
        return $this->hasMany(EmployeeAdvance::class);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active;
    }

    /**
     * Employees always get the employee interface; the admin chooses with the switch.
     */
    public function usesEmployeeUi(): bool
    {
        return ! $this->isAdmin() || $this->ui_mode === 'employee';
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }
}
