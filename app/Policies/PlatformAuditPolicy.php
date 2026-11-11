<?php

namespace App\Policies;

use App\Models\PlatformAudit;
use App\Models\User;

class PlatformAuditPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canGlobally('settings.manage');
    }

    public function view(User $user, PlatformAudit $entry): bool
    {
        return $user->canGlobally('settings.manage');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, PlatformAudit $entry): bool
    {
        return false;
    }

    public function delete(User $user, PlatformAudit $entry): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
