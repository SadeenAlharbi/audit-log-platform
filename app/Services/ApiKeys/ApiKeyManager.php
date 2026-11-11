<?php

namespace App\Services\ApiKeys;

use App\Models\Project;
use App\Models\ProjectApiKey;
use App\Models\User;
use App\Services\Audit\PlatformAuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Creates, rotates and revokes project API keys (plan §5, §7, §13).
 */
class ApiKeyManager
{
    public function __construct(private PlatformAuditLogger $audit) {}

    /**
     * @param  list<string>  $scopes
     * @param  list<string>|null  $allowedIps
     */
    public function create(
        Project $project,
        string $name,
        array $scopes = [ProjectApiKey::SCOPE_WRITE],
        string $environment = 'production',
        bool $withSignature = true,
        ?array $allowedIps = null,
        ?User $by = null,
        bool $allowCombinedScopes = false,
        ?CarbonInterface $expiresAt = null,
    ): IssuedApiKey {
        $scopes = $this->validateScopes($scopes, $allowCombinedScopes);

        if (! in_array($environment, ['production', 'staging'], true)) {
            throw new InvalidArgumentException("Unknown environment [{$environment}].");
        }

        $allowedIps = $this->validateIps($allowedIps);

        $plainKey = $this->generateKey();
        $plainSecret = $withSignature ? bin2hex(random_bytes(32)) : null;

        $key = DB::transaction(function () use ($project, $name, $scopes, $environment, $allowedIps, $by, $expiresAt, $plainKey, $plainSecret) {
            $key = ProjectApiKey::query()->create([
                'project_id' => $project->id,
                'name' => $name,
                'environment' => $environment,
                'key_prefix' => substr($plainKey, 0, 8),
                'key_hash' => ProjectApiKey::hashKey($plainKey),
                'hmac_secret' => $plainSecret,
                'scopes' => $scopes,
                'allowed_ips' => $allowedIps,
                'expires_at' => $expiresAt ?? now()->addDays((int) config('audit.api_keys.lifetime_days', 90)),
                'created_by' => $by?->getKey(),
            ]);

            $this->audit->record('api_key.created', $key, [
                'project' => $project->slug,
                'name' => $name,
                'environment' => $environment,
                'scopes' => $scopes,
                'signed' => $plainSecret !== null,
                'allowed_ips' => $allowedIps,
                'expires_at' => $key->expires_at?->toIso8601String(),
            ], $by);

            return $key;
        });

        return new IssuedApiKey($key, $plainKey, $plainSecret);
    }

    /**
     * Issues a replacement key with the same settings. The old key keeps
     * working for the configured grace period, then expires.
     */
    public function rotate(ProjectApiKey $old, ?User $by = null): IssuedApiKey
    {
        if ($old->revoked_at !== null) {
            throw new InvalidArgumentException('A revoked key cannot be rotated.');
        }

        return DB::transaction(function () use ($old, $by) {
            $issued = $this->create(
                project: $old->project,
                name: $old->name,
                scopes: $old->scopes ?? [ProjectApiKey::SCOPE_WRITE],
                environment: $old->environment,
                withSignature: $old->hmac_secret !== null,
                allowedIps: $old->allowed_ips,
                by: $by,
                allowCombinedScopes: true,
            );

            $graceEnds = now()->addHours((int) config('audit.api_keys.rotation_grace_hours', 24));
            $newExpiry = $old->expires_at !== null && $old->expires_at->lessThan($graceEnds) ? $old->expires_at : $graceEnds;
            $old->forceFill(['expires_at' => $newExpiry])->save();

            $this->audit->record('api_key.rotated', $old, [
                'replaced_by' => $issued->key->id,
                'old_key_expires_at' => $newExpiry->toIso8601String(),
            ], $by);

            return $issued;
        });
    }

    public function revoke(ProjectApiKey $key, ?User $by = null, ?string $reason = null): void
    {
        if ($key->revoked_at !== null) {
            return;
        }

        $key->forceFill(['revoked_at' => now(), 'revoked_by' => $by?->getKey()])->save();

        $this->audit->record('api_key.revoked', $key, array_filter(['reason' => $reason]), $by);
    }

    /**
     * @param  list<string>|null  $ips
     */
    public function updateAllowedIps(ProjectApiKey $key, ?array $ips, ?User $by = null): void
    {
        $ips = $this->validateIps($ips);
        $old = $key->allowed_ips;
        $key->forceFill(['allowed_ips' => $ips])->save();

        $this->audit->record('api_key.allowed_ips_updated', $key, ['old' => $old, 'new' => $ips], $by);
    }

    public function findByPlainKey(string $plainKey): ?ProjectApiKey
    {
        if (! str_starts_with($plainKey, (string) config('audit.api_keys.prefix', 'alh_')) || strlen($plainKey) > 128) {
            return null;
        }

        return ProjectApiKey::query()
            ->with('project')
            ->where('key_hash', ProjectApiKey::hashKey($plainKey))
            ->first();
    }

    private function generateKey(): string
    {
        return config('audit.api_keys.prefix', 'alh_').Str::random(44);
    }

    /**
     * @param  list<string>  $scopes
     * @return list<string>
     */
    private function validateScopes(array $scopes, bool $allowCombined): array
    {
        $scopes = array_values(array_unique($scopes));
        $known = [ProjectApiKey::SCOPE_WRITE, ProjectApiKey::SCOPE_READ];

        if ($scopes === [] || array_diff($scopes, $known) !== []) {
            throw new InvalidArgumentException('Scopes must be logs:write and/or logs:read.');
        }

        // Plan §13: write and read in one key only by explicit decision.
        if (count($scopes) > 1 && ! $allowCombined) {
            throw new InvalidArgumentException('Combining logs:write and logs:read in one key requires an explicit decision.');
        }

        sort($scopes);

        return $scopes;
    }

    /**
     * @param  list<string>|null  $ips
     * @return list<string>|null
     */
    private function validateIps(?array $ips): ?array
    {
        if ($ips === null) {
            return null;
        }

        $ips = array_values(array_unique(array_filter(array_map('trim', $ips))));

        foreach ($ips as $ip) {
            if (! IpMatcher::isValidRule($ip)) {
                throw new InvalidArgumentException("Invalid IP or CIDR [{$ip}].");
            }
        }

        return $ips === [] ? null : $ips;
    }
}
