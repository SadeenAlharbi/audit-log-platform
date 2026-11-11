<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

/**
 * Dashboard user.
 *
 * Two permission levels (plan §13):
 *  - platform roles (super_admin, platform_auditor, security_officer) are
 *    assigned through spatie (model_has_roles) and apply to every project;
 *  - project roles (project_owner, project_viewer) are assigned per project
 *    in project_user and apply to that project only.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property bool $is_active
 * @property string|null $app_authentication_secret
 * @property list<string>|null $app_authentication_recovery_codes
 * @property int $failed_login_attempts
 * @property Carbon|null $locked_until
 * @property Carbon|null $password_changed_at
 * @property Carbon|null $last_login_at
 * @property string|null $last_login_ip
 * @property-read ProjectMember $pivot
 */
#[Fillable(['name', 'email', 'password', 'is_active'])]
#[Hidden(['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /** @var array<int, list<string>>|null project_id => permission names (memoised) */
    private ?array $projectPermissions = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
            'failed_login_attempts' => 'integer',
            'locked_until' => 'datetime',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $user) {
            if ($user->isDirty('password')) {
                $user->password_changed_at = now();
            }
        });
    }

    /* ---------------------------------------------------------------
     | Filament
     * --------------------------------------------------------------- */

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active
            && ! $this->isLocked()
            && ($this->roles()->exists() || $this->projectPermissionMap() !== []);
    }

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->app_authentication_secret;
    }

    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        $this->forceFill(['app_authentication_secret' => $secret])->save();
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    /** @return list<string>|null */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->app_authentication_recovery_codes;
    }

    /** @param list<string>|null $codes */
    public function saveAppAuthenticationRecoveryCodes(#[SensitiveParameter] ?array $codes): void
    {
        $this->forceFill(['app_authentication_recovery_codes' => $codes])->save();
    }

    /** Plan §13: 2FA is mandatory for Super Admin and Security Officer. */
    public function requiresMultiFactorAuthentication(): bool
    {
        return $this->hasAnyRole(config('audit.accounts.mfa_required_roles', []));
    }

    /* ---------------------------------------------------------------
     | Authorization helpers
     * --------------------------------------------------------------- */

    /** Permission granted by a platform role (applies to every project). */
    public function canGlobally(string $permission): bool
    {
        try {
            return $this->is_active && $this->hasPermissionTo($permission);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    /** Permission on one project: platform role OR project role. */
    public function canInProject(string $permission, Project|int $project): bool
    {
        if ($this->canGlobally($permission)) {
            return true;
        }

        $projectId = $project instanceof Project ? $project->id : $project;

        return $this->is_active && in_array($permission, $this->projectPermissionMap()[$projectId] ?? [], true);
    }

    /**
     * Projects on which the user holds $permission; null = every project.
     *
     * @return list<int>|null
     */
    public function projectIdsWith(string $permission): ?array
    {
        if ($this->canGlobally($permission)) {
            return null;
        }

        if (! $this->is_active) {
            return [];
        }

        return array_keys(array_filter(
            $this->projectPermissionMap(),
            fn (array $permissions) => in_array($permission, $permissions, true),
        ));
    }

    public function hasAnyProjectPermission(string $permission): bool
    {
        $ids = $this->projectIdsWith($permission);

        return $ids === null || $ids !== [];
    }

    public function projectRole(Project|int $project): ?Role
    {
        $projectId = $project instanceof Project ? $project->id : $project;
        $roleId = DB::table('project_user')->where('project_id', $projectId)->where('user_id', $this->id)->value('role_id');

        return $roleId ? Role::query()->find($roleId) : null;
    }

    public function forgetProjectPermissions(): void
    {
        $this->projectPermissions = null;
    }

    /** @return array<int, list<string>> */
    private function projectPermissionMap(): array
    {
        if ($this->projectPermissions !== null) {
            return $this->projectPermissions;
        }

        $rows = DB::table('project_user')
            ->join('role_has_permissions', 'role_has_permissions.role_id', '=', 'project_user.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->join('projects', 'projects.id', '=', 'project_user.project_id')
            ->where('project_user.user_id', $this->id)
            ->whereNull('projects.archived_at')
            ->get(['project_user.project_id', 'permissions.name']);

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->project_id][] = $row->name;
        }

        return $this->projectPermissions = $map;
    }

    /* ---------------------------------------------------------------
     | Relations & scopes
     * --------------------------------------------------------------- */

    /**
     * @return BelongsToMany<Project, $this, ProjectMember>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)
            ->using(ProjectMember::class)
            ->withPivot(['role_id', 'added_by'])
            ->withTimestamps();
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
