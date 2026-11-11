<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Creates the first Super Admin from ADMIN_EMAIL / ADMIN_PASSWORD.
 * Skipped when they are empty; never changes an existing user's password.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('audit.bootstrap_admin.email');
        $password = config('audit.bootstrap_admin.password');

        if (blank($email) || blank($password)) {
            $this->command->warn('ADMIN_EMAIL / ADMIN_PASSWORD not set - no Super Admin created.');

            return;
        }

        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => config('audit.bootstrap_admin.name'),
                'password' => $password,
                'is_active' => true,
            ],
        );

        if (! $user->hasRole('super_admin')) {
            $user->assignRole('super_admin');
        }
    }
}
