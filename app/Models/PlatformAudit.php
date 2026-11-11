<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The platform auditing itself (plan §13). Fully immutable.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $action
 * @property string|null $target_type
 * @property string|null $target_id
 * @property array<string, mixed>|null $changes
 * @property string|null $ip
 * @property string|null $user_agent
 * @property CarbonImmutable $created_at
 */
class PlatformAudit extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $table = 'platform_audit';

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
