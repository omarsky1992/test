<?php

namespace App\Platform;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * The platform owner. Lives only in the platform database and is created only from the server's
 * command line (`php artisan platform:admin`): no client, page or API can make one.
 */
#[Unguarded]
class PlatformAdmin extends Authenticatable implements FilamentUser
{
    protected $connection = 'platform';

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'is_active' => 'boolean', 'last_login_at' => 'immutable_datetime'];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'platform' && $this->is_active;
    }
}
