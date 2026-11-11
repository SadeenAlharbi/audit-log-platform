<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

/**
 * Laravel Telescope - development only (plan §11). Registered from
 * bootstrap/providers.php when the package is installed and APP_ENV=local.
 */
class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    public function register(): void
    {
        $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);

        // Even in development, API keys, signatures and session cookies are
        // never recorded.
        Telescope::hideRequestHeaders(['authorization', 'cookie', 'set-cookie', 'x-csrf-token', 'x-xsrf-token', 'x-signature']);
        Telescope::hideRequestParameters(['_token', 'password', 'password_confirmation']);

        Telescope::filter(fn (IncomingEntry $entry) => true);
    }

    protected function gate(): void
    {
        Gate::define('viewTelescope', fn (User $user) => $user->hasRole('super_admin'));
    }
}
