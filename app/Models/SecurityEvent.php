<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $project_id
 * @property int|null $api_key_id
 * @property string $ip
 * @property string $reason
 * @property array<string, mixed>|null $request_meta
 * @property CarbonImmutable $created_at
 */
class SecurityEvent extends Model
{
    public const UPDATED_AT = null;

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'request_meta' => 'array',
            'created_at' => 'immutable_datetime',
        ];
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
