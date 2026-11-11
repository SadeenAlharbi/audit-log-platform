<?php

namespace App\Models\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Plan §13: when a dashboard user is signed in, Project / AuditLog /
 * ProjectApiKey queries are automatically limited to the projects the user
 * may see, so security never depends on a manual filter.
 *
 * It does not apply to API requests (authenticated by key, filtered by the
 * key's project), queue workers or console commands.
 *
 * @implements Scope<Model>
 */
class ProjectAccessScope implements Scope
{
    public function __construct(
        private string $permission,
        private string $column = 'project_id',
    ) {}

    public function apply(Builder $builder, Model $model): void
    {
        $guard = Auth::guard('web');

        if (! $guard->hasUser()) {
            return;
        }

        $user = $guard->user();
        if (! $user instanceof User) {
            return;
        }

        $ids = $user->projectIdsWith($this->permission);

        if ($ids === null) {
            return; // platform-wide permission
        }

        $builder->whereIn($model->qualifyColumn($this->column), $ids === [] ? [0] : $ids);
    }
}
