<?php

namespace App\Policies;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Role;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canGlobally('roles.manage');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->canGlobally('roles.manage');
    }

    public function create(User $user): bool
    {
        return $user->canGlobally('roles.manage');
    }

    /** super_admin always keeps every permission. */
    public function update(User $user, Role $role): bool
    {
        return $user->canGlobally('roles.manage') && $role->name !== 'super_admin';
    }

    /** The five roles of the plan cannot be deleted. */
    public function delete(User $user, Role $role): bool
    {
        return $user->canGlobally('roles.manage')
            && ! array_key_exists($role->name, RolesAndPermissionsSeeder::ROLES)
            && ! $role->users()->exists();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
