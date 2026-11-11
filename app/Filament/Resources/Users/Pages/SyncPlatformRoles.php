<?php

namespace App\Filament\Resources\Users\Pages;

use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Only platform-scope roles can be assigned globally; a user cannot change
 * their own roles. Changes are recorded by the spatie event listener.
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

        $allowed = Role::query()->where('scope', 'platform')->whereIn('name', $roles)->pluck('name')->all();
        $user->syncRoles($allowed);
    }
}
