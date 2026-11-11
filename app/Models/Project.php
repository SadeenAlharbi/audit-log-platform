<?php

namespace App\Models;

use App\Exceptions\AppendOnlyViolation;
use App\Models\Scopes\ProjectAccessScope;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A system that sends audit logs. Never deleted - archived instead (D1).
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property bool $is_active
 * @property int $retention_days
 * @property int|null $created_by
 * @property Carbon|null $archived_at
 * @property int|null $archived_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'slug', 'description', 'is_active', 'retention_days', 'created_by'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    protected $attributes = [
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('project_access', new ProjectAccessScope('projects.view', 'id'));

        static::creating(function (self $project) {
            $project->retention_days ??= (int) config('audit.retention.default_days', 365);
        });

        static::deleting(fn () => throw AppendOnlyViolation::delete('Project'));
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'retention_days' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    /** Effective retention: never above the platform ceiling (D3). */
    public function effectiveRetentionDays(): int
    {
        return min($this->retention_days, (int) config('audit.retention.max_days'));
    }

    public function archive(?User $by = null): void
    {
        $this->forceFill([
            'is_active' => false,
            'archived_at' => now(),
            'archived_by' => $by?->getKey(),
        ])->save();
    }

    /** @return HasMany<ProjectApiKey, $this> */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ProjectApiKey::class);
    }

    /** @return HasMany<AuditLog, $this> */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    /** @return BelongsToMany<User, $this, ProjectMember> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(ProjectMember::class)
            ->withPivot(['role_id', 'added_by'])
            ->withTimestamps();
    }

    /** @return HasMany<IngestionBatch, $this> */
    public function batches(): HasMany
    {
        return $this->hasMany(IngestionBatch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->whereNull('archived_at');
    }
}
