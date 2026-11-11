<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

/**
 * Logs are read-only for everyone (plan §13): nobody may create, edit or
 * delete them through the dashboard.
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyProjectPermission('logs.view');
    }

    public function view(User $user, AuditLog $log): bool
    {
        return $user->canInProject('logs.view', $log->project_id);
    }

    public function export(User $user, ?int $projectId = null): bool
    {
        return $projectId === null
            ? $user->hasAnyProjectPermission('logs.export')
            : $user->canInProject('logs.export', $projectId);
    }

    public function viewSensitive(User $user, AuditLog $log): bool
    {
        return $user->canInProject('logs.view_sensitive', $log->project_id);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AuditLog $log): bool
    {
        return false;
    }

    public function delete(User $user, AuditLog $log): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
