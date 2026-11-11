<?php

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

it('seeds roles and permissions idempotently', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(Role::query()->count())->toBe(count(RolesAndPermissionsSeeder::ROLES))
        ->and(Permission::query()->count())->toBe(count(RolesAndPermissionsSeeder::PERMISSIONS))
        ->and(Role::findByName('super_admin')->permissions)->toHaveCount(count(RolesAndPermissionsSeeder::PERMISSIONS))
        ->and(Role::findByName('project_viewer')->hasPermissionTo('logs.view_sensitive'))->toBeFalse();
});
