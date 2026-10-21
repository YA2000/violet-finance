<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        // every ability is a permission key of the Roles screen (see App\Support\Perms)
        Gate::before(fn ($user, string $ability) => $user->hasPerm($ability) ? true : null);
    }
}
