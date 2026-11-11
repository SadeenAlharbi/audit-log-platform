<?php

namespace App\Services\Security;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Access review (plan §13): one line per user with platform roles, project
 * memberships and the sensitive permissions they hold.
 */
class AccessReview
{
    public const SENSITIVE = [
        'logs.view_sensitive', 'users.manage', 'roles.manage', 'settings.manage',
        'backups.run', 'backups.restore', 'api_keys.create', 'api_keys.rotate',
    ];

    /**
     * @return list<array<string, string>>
     */
    public function rows(bool $sensitiveOnly = false): array
    {
        $memberships = DB::table('project_user')
            ->join('projects', 'projects.id', '=', 'project_user.project_id')
            ->join('roles', 'roles.id', '=', 'project_user.role_id')
            ->get(['project_user.user_id', 'projects.slug', 'roles.name', 'project_user.role_id'])
            ->groupBy('user_id');

        $projectRolePermissions = DB::table('role_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->get(['role_has_permissions.role_id', 'permissions.name'])
            ->groupBy('role_id')
            ->map(fn ($rows) => $rows->pluck('name')->all());

        $rows = [];
        foreach (User::query()->with('roles.permissions')->orderBy('email')->get() as $user) {
            $platform = $user->getAllPermissions()->pluck('name')->all();
            $projects = $memberships->get($user->id, collect());

            $projectPermissions = $projects->flatMap(fn ($m) => $projectRolePermissions->get($m->role_id, []))->all();
            $sensitive = array_values(array_intersect(self::SENSITIVE, array_unique([...$platform, ...$projectPermissions])));

            if ($sensitiveOnly && $sensitive === []) {
                continue;
            }

            $rows[] = [
                'name' => $user->name,
                'email' => $user->email,
                'active' => $user->is_active ? 'yes' : 'no',
                'mfa' => $user->app_authentication_secret ? 'yes' : 'no',
                'platform_roles' => $user->roles->pluck('name')->implode(' | '),
                'project_roles' => $projects->map(fn ($m) => "{$m->slug}:{$m->name}")->implode(' | '),
                'sensitive_permissions' => implode(' | ', $sensitive),
                'last_login_at' => (string) $user->last_login_at?->toDateTimeString(),
            ];
        }

        return $rows;
    }

    public function csv(bool $sensitiveOnly = false): string
    {
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        $rows = $this->rows($sensitiveOnly);
        fputcsv($out, array_keys($rows[0] ?? ['name' => '', 'email' => '', 'active' => '', 'mfa' => '', 'platform_roles' => '', 'project_roles' => '', 'sensitive_permissions' => '', 'last_login_at' => '']), escape: '');
        foreach ($rows as $row) {
            fputcsv($out, $row, escape: '');
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }
}
