<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\TelescopeServiceProvider;

return array_values(array_filter([
    AppServiceProvider::class,
    AdminPanelProvider::class,
    HorizonServiceProvider::class,

    // Plan §11: Telescope in development only. The package is a dev
    // dependency (absent from the prod image) and is excluded from package
    // discovery in composer.json, so it is registered here, for local only.
    class_exists(Laravel\Telescope\TelescopeApplicationServiceProvider::class)
        && ($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? getenv('APP_ENV')) === 'local'
            ? TelescopeServiceProvider::class
            : null,
]));
