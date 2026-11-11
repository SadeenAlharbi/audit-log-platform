<?php

namespace App\Models;

use App\Exceptions\AppendOnlyViolation;
use App\Models\Scopes\ProjectAccessScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * API key of a project. Only the SHA-256 hash of the key is stored; the HMAC
 * secret is stored encrypted because the server must read it to verify
 * X-Signature. Keys are revoked, never deleted.
 *
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property string $environment
 * @property string $key_prefix
 * @property string $key_hash
 * @property string|null $hmac_secret
 * @property list<string>|null $scopes
 * @property list<string>|null $allowed_ips
 * @property Carbon|null $last_used_at
 * @property string|null $last_used_ip
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property int|null $revoked_by
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> usable()
 */
#[Fillable(['project_id', 'name', 'environment', 'key_prefix', 'key_hash', 'hmac_secret', 'scopes', 'allowed_ips', 'expires_at', 'created_by'])]
#[Hidden(['key_hash', 'hmac_secret'])]
class ProjectApiKey extends Model
{
    public const SCOPE_WRITE = 'logs:write';

    public const SCOPE_READ = 'logs:read';

    protected static function booted(): void
    {
        static::addGlobalScope('project_access', new ProjectAccessScope('api_keys.view'));
        static::deleting(fn () => throw AppendOnlyViolation::delete('ProjectApiKey'));
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function status(): string
    {
        return match (true) {
            $this->revoked_at !== null => 'revoked',
            $this->isExpired() => 'expired',
            default => 'active',
        };
    }

    protected function casts(): array
    {
        return [
            'hmac_secret' => 'encrypted',
            'scopes' => 'array',
            'allowed_ips' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public static function hashKey(string $plainKey): string
    {
        return hash('sha256', $plainKey);
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        // Keys are always shown inside their project; the project itself
        // must resolve even for users whose scope is limited.
        return $this->belongsTo(Project::class)->withoutGlobalScope('project_access');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @param Builder<self> $query */
    public function scopeUsable(Builder $query): void
    {
        $query->whereNull('revoked_at')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
