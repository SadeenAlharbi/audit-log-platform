<?php

namespace App\Policies;

use App\Models\BackupRun;
use App\Models\User;

class BackupRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canGlobally('backups.view');
    }

    public function view(User $user, BackupRun $run): bool
    {
        return $user->canGlobally('backups.view');
    }

    public function run(User $user): bool
    {
        return $user->canGlobally('backups.run');
    }

    public function restore(User $user): bool
    {
        return $user->canGlobally('backups.restore');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, BackupRun $run): bool
    {
        return false;
    }

    public function delete(User $user, BackupRun $run): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
