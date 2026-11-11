<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canGlobally('users.manage');
    }

    public function view(User $user, User $model): bool
    {
        return $user->canGlobally('users.manage') || $user->is($model);
    }

    public function create(User $user): bool
    {
        return $user->canGlobally('users.manage');
    }

    public function update(User $user, User $model): bool
    {
        return $user->canGlobally('users.manage');
    }

    /** Users are deactivated, never deleted (they are referenced by the audit trail). */
    public function delete(User $user, User $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function assignRoles(User $user, User $model): bool
    {
        return $user->canGlobally('roles.manage') && ! $user->is($model);
    }
}
