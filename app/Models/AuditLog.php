<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Scopes\ProjectAccessScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One audit log entry. Append-only (triggers + AppendOnly guard).
 *
 * The table's primary key is (id, occurred_at) because of partitioning;
 * Eloquent only needs `id`. Public routes use `uid` (ULID).
 * Always filter by project_id and an occurred_at range so MariaDB can prune
 * partitions.
 *
 * @property int $id
 * @property string $uid
 * @property int $project_id
 * @property int|null $api_key_id
 * @property int|null $batch_id
 * @property string|null $external_id
 * @property string $event
 * @property string $action
 * @property string $severity
 * @property string|null $actor_type
 * @property string|null $actor_id
 * @property string|null $actor_name
 * @property string|null $actor_ip
 * @property string|null $user_agent
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<mixed>|null $old_values
 * @property array<mixed>|null $new_values
 * @property array<mixed>|null $metadata
 * @property list<string>|null $tags
 * @property string|null $request_id
 * @property string|null $correlation_id
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $created_at
 * @property-read Project|null $project
 */
class AuditLog extends Model
{
    use AppendOnly;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::addGlobalScope('project_access', new ProjectAccessScope('logs.view'));
    }

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'metadata' => 'array',
            'tags' => 'array',
            'occurred_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uid';
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withoutGlobalScope('project_access');
    }

    /** @return BelongsTo<ProjectApiKey, $this> */
    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ProjectApiKey::class);
    }

    /** @return BelongsTo<IngestionBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(IngestionBatch::class);
    }

    /** @param Builder<self> $query */
    public function scopeForProject(Builder $query, int $projectId): void
    {
        $query->where('project_id', $projectId);
    }

    /** @param Builder<self> $query */
    public function scopeOccurredBetween(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to): void
    {
        $query->whereBetween('occurred_at', [$from, $to]);
    }
}
