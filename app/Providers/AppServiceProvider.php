<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Audit;
use App\Services\Ledger;
use App\Services\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Ledger::class);
        $this->app->scoped(Audit::class);
        $this->app->scoped(Settings::class);
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        // Admins hold every permission, including ones added later.
        Gate::before(fn (User $user) => $user->hasRole('admin') ? true : null);
    }
}
