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

    /**
     * Security review M6: changing someone's email, password or 2FA is a
     * takeover of their account, so it is only allowed on users who hold no
     * permission the manager lacks (a user manager cannot take over a
     * Super Admin).
     */
    public function update(User $user, User $model): bool
    {
        return $user->canGlobally('users.manage') && ($user->is($model) || $user->covers($model));
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
        return $user->canGlobally('roles.manage') && ! $user->is($model) && $user->covers($model);
    }
}
