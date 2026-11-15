<?php

namespace App\Filament\Resources\Users\Pages;

use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Only platform-scope roles can be assigned globally; a user cannot change
 * their own roles, nor grant or remove a role with permissions they lack. Changes are recorded by the spatie event listener.
 */
final class SyncPlatformRoles
{
    /** @param list<string>|null $roles */
    public static function sync(User $user, ?array $roles): void
    {
        $actor = auth()->user();

        if ($roles === null || ! $actor instanceof User || ! $actor->can('assignRoles', $user)) {
            return;
        }

        // Security review (2026-11-15) H: the actor may only grant or remove
        // roles whose permissions they hold themselves; any other role the
        // user already has is kept untouched.
        $held = $actor->globalPermissionNames();
        $manageable = Role::query()->where('scope', 'platform')->with('permissions')->get()
            ->filter(fn (Role $role) => array_diff($role->permissions->pluck('name')->all(), $held) === [])
            ->pluck('name')->all();

        $requested = array_values(array_intersect($roles, $manageable));
        $kept = array_values(array_diff($user->getRoleNames()->all(), $manageable));

        $user->syncRoles(array_unique([...$kept, ...$requested]));
    }
}
