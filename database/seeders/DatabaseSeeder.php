<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeders contain reference data only and are idempotent (plan §4):
 * running `php artisan db:seed` twice changes nothing.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            SuperAdminSeeder::class,
        ]);
    }
}
