<?php

namespace App\Policies;

use App\Models\SecurityEvent;
use App\Models\User;

class SecurityEventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canGlobally('security_events.view');
    }

    public function view(User $user, SecurityEvent $event): bool
    {
        return $user->canGlobally('security_events.view');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, SecurityEvent $event): bool
    {
        return false;
    }

    public function delete(User $user, SecurityEvent $event): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
