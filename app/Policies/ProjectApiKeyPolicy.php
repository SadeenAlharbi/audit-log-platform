<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\ProjectApiKey;
use App\Models\User;

class ProjectApiKeyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyProjectPermission('api_keys.view');
    }

    public function view(User $user, ProjectApiKey $key): bool
    {
        return $user->canInProject('api_keys.view', $key->project_id);
    }

    public function create(User $user, ?Project $project = null): bool
    {
        return $project === null
            ? $user->hasAnyProjectPermission('api_keys.create')
            : $project->archived_at === null && $user->canInProject('api_keys.create', $project);
    }

    public function rotate(User $user, ProjectApiKey $key): bool
    {
        return $key->revoked_at === null && $user->canInProject('api_keys.rotate', $key->project_id);
    }

    public function revoke(User $user, ProjectApiKey $key): bool
    {
        return $key->revoked_at === null && $user->canInProject('api_keys.revoke', $key->project_id);
    }

    /** Security Officer / Project Owner manage the allowed IP list. */
    public function manageIps(User $user, ProjectApiKey $key): bool
    {
        return $key->revoked_at === null && $user->canInProject('api_keys.manage_ips', $key->project_id);
    }

    public function update(User $user, ProjectApiKey $key): bool
    {
        return false; // keys are immutable apart from the actions above
    }

    public function delete(User $user, ProjectApiKey $key): bool
    {
        return false; // revoke instead
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
