<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uid
 * @property int $project_id
 * @property int $api_key_id
 * @property int $count
 * @property int $accepted_count
 * @property int $duplicate_count
 * @property int $rejected_count
 * @property string $status
 * @property list<array<string, mixed>>|null $errors
 * @property Carbon|null $processed_at
 * @property Carbon|null $created_at
 */
class IngestionBatch extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    /**
     * HasUlids fills `uid`, not the primary key.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uid'];
    }

    /**
     * Canonical upper-case ULID, like the log uids (Laravel's default is
     * lower-case), so every id in the API has one format.
     */
    public function newUniqueId(): string
    {
        return (string) Str::ulid();
    }

    protected function casts(): array
    {
        return [
            'errors' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uid';
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<ProjectApiKey, $this> */
    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ProjectApiKey::class);
    }
}
