<?php

namespace App\Http\Middleware\Api;

use App\Http\Responses\ApiError;
use App\Models\ProjectApiKey;
use App\Services\ApiKeys\ApiKeyManager;
use App\Services\ApiKeys\IpMatcher;
use App\Services\Security\SecurityEventRecorder;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Plan §7: Authorization: Bearer <api_key> + X-Project: <slug>.
 *
 * Checks the key hash, the project, expiry, revocation and the allowed IP
 * list. Every rejection is written to security_events. The client only
 * gets a generic answer, so it cannot probe which check failed.
 */
class AuthenticateApiKey
{
    public function __construct(
        private ApiKeyManager $keys,
        private SecurityEventRecorder $security,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $slug = $request->header('X-Project');

        if (! is_string($token) || $token === '' || ! is_string($slug) || $slug === '') {
            return $this->reject($request, 'missing_credentials');
        }

        $key = $this->keys->findByPlainKey($token);

        if ($key === null) {
            return $this->reject($request, 'invalid_key');
        }

        $project = $key->project;

        if (! hash_equals($project->slug, $slug)) {
            return $this->reject($request, 'project_mismatch', $key);
        }

        if ($key->revoked_at !== null) {
            return $this->reject($request, 'key_revoked', $key);
        }

        if ($key->expires_at !== null && $key->expires_at->isPast()) {
            return $this->reject($request, 'key_expired', $key);
        }

        if (! $project->is_active || $project->archived_at !== null) {
            return $this->reject($request, 'project_inactive', $key, 403);
        }

        if (! IpMatcher::allows($key->allowed_ips, $request->ip())) {
            return $this->reject($request, 'ip_not_allowed', $key, 403);
        }

        $request->attributes->set('api_key', $key);
        $request->attributes->set('project', $project);

        $this->touch($key, (string) $request->ip());

        return $next($request);
    }

    private function reject(Request $request, string $reason, ?ProjectApiKey $key = null, int $status = 401): Response
    {
        $this->security->record($request, $reason, $key?->project, $key);

        return $status === 403
            ? ApiError::make('forbidden', 'This API key is not allowed to perform this request.', 403)
            : ApiError::make('unauthorized', 'Missing or invalid credentials.', 401);
    }

    /** Updates last_used_at at most once a minute per key. */
    private function touch(ProjectApiKey $key, string $ip): void
    {
        if (Cache::add("api_key:touched:{$key->id}", true, 60)) {
            ProjectApiKey::query()->whereKey($key->id)->update([
                'last_used_at' => now(),
                'last_used_ip' => $ip,
            ]);
        }
    }
}
