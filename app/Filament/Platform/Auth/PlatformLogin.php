<?php

namespace App\Filament\Platform\Auth;

use App\Filament\Pages\Auth\Login;

/**
 * The platform owner signs in with a username, like the clients' staff, but against the platform's
 * own accounts (guard «platform»; made only by `php artisan platform:admin`).
 */
class PlatformLogin extends Login {}
