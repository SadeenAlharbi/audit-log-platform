<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles and permissions from plan §13.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public const GUARD = 'web';

    /** @var list<string> */
    public const PERMISSIONS = [
        'projects.view', 'projects.create', 'projects.update', 'projects.archive',
        'api_keys.view', 'api_keys.create', 'api_keys.rotate', 'api_keys.revoke', 'api_keys.manage_ips',
        'logs.view', 'logs.export', 'logs.view_sensitive',
        'users.manage', 'roles.manage',
        'backups.view', 'backups.run', 'backups.restore',
        'security_events.view',
        'settings.manage',
    ];

    /**
     * role => [scope, permissions]. '*' means every permission.
     *
     * @var array<string, array{0: string, 1: list<string>|string}>
     */
    public const ROLES = [
        'super_admin' => ['platform', '*'],
        'platform_auditor' => ['platform', ['projects.view', 'logs.view', 'logs.export']],
        'security_officer' => ['platform', ['security_events.view', 'api_keys.view', 'api_keys.manage_ips', 'projects.view']],
        'project_owner' => ['project', [
            'projects.view', 'projects.update',
            'api_keys.view', 'api_keys.create', 'api_keys.rotate', 'api_keys.revoke', 'api_keys.manage_ips',
            'logs.view', 'logs.export',
        ]],
        'project_viewer' => ['project', ['projects.view', 'logs.view', 'logs.export']],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, self::GUARD);
        }

        foreach (self::ROLES as $name => [$scope, $permissions]) {
            $role = Role::findOrCreate($name, self::GUARD);
            $role->forceFill(['scope' => $scope])->save();
            $role->syncPermissions($permissions === '*' ? self::PERMISSIONS : $permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
