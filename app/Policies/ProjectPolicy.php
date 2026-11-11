<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyProjectPermission('projects.view');
    }

    public function view(User $user, Project $project): bool
    {
        return $user->canInProject('projects.view', $project);
    }

    public function create(User $user): bool
    {
        return $user->canGlobally('projects.create');
    }

    public function update(User $user, Project $project): bool
    {
        return $project->archived_at === null && $user->canInProject('projects.update', $project);
    }

    public function archive(User $user, Project $project): bool
    {
        return $project->archived_at === null && $user->canInProject('projects.archive', $project);
    }

    /** Project Owner adds members to their project (plan §13). */
    public function manageMembers(User $user, Project $project): bool
    {
        return $this->update($user, $project);
    }

    /** Projects are archived, never deleted (decision D1). */
    public function delete(User $user, Project $project): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Project $project): bool
    {
        return false;
    }

    public function restore(User $user, Project $project): bool
    {
        return false;
    }
}
