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

    /**
     * super_admin always keeps every permission. Security review M6: nobody
     * edits a role they hold themselves, nor a role with permissions they
     * do not have (granting is limited to one's own permissions, see
     * RoleForm::selectedPermissions).
     */
    public function update(User $user, Role $role): bool
    {
        return $user->canGlobally('roles.manage')
            && $role->name !== 'super_admin'
            && ! $user->hasRole($role)
            && array_diff($role->permissions->pluck('name')->all(), $user->globalPermissionNames()) === [];
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
