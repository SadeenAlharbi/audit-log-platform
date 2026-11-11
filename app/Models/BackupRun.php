<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $type
 * @property string $status
 * @property int|null $size
 * @property string|null $location
 * @property string|null $checksum
 * @property array<string, mixed>|null $meta
 * @property int|null $triggered_by
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property string|null $error
 */
class BackupRun extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'meta' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
