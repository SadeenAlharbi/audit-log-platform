<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Spatie\Permission\Models\Role;

/**
 * project_user pivot: user + project + project-level role (plan §13).
 */
class ProjectMember extends Pivot
{
    protected $table = 'project_user';

    public $incrementing = false;

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
