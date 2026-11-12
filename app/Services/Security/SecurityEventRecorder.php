<?php

namespace App\Services\Security;

use App\Models\Project;
use App\Models\ProjectApiKey;
use App\Models\SecurityEvent;
use App\Services\Alerts\AlertNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records every rejected API request in security_events (plan §7) and
 * alerts when the same IP keeps getting rejected.
 */
class SecurityEventRecorder
{
    public const REASONS = [
        'missing_credentials', 'invalid_key', 'project_mismatch', 'key_revoked', 'key_expired',
        'project_inactive', 'ip_not_allowed', 'scope_denied', 'missing_signature', 'invalid_signature',
        'stale_timestamp', 'replayed_request', 'rate_limited', 'https_required', 'payload_too_large',
        'unsupported_media_type', 'ip_rate_limited',
    ];

    public function __construct(private AlertNotifier $alerts) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function record(Request $request, string $reason, ?Project $project = null, ?ProjectApiKey $key = null, array $meta = []): void
    {
        $ip = (string) $request->ip();

        // Security review M4: a flood of bad requests must not become a flood
        // of INSERTs. The first N events per IP and reason each minute are
        // stored; the rest are only counted (and still raise the alert).
        $sampleKey = 'security:sample:'.sha1($ip.'|'.$reason).':'.now()->format('YmdHi');
        Cache::add($sampleKey, 0, now()->addMinutes(2));
        if ((int) Cache::increment($sampleKey) > (int) config('audit.security.max_events_per_minute', 30)) {
            $this->maybeAlert($ip, $reason);

            return;
        }

        try {
            SecurityEvent::query()->create([
                'project_id' => $project->id ?? $key->project_id ?? null,
                'api_key_id' => $key?->id,
                'ip' => $ip,
                'reason' => $reason,
                'request_meta' => array_filter([
                    'method' => $request->method(),
                    'path' => '/'.ltrim($request->path(), '/'),
                    'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                    'project_header' => mb_substr((string) $request->header('X-Project'), 0, 64) ?: null,
                    'key_prefix' => $this->keyPrefix($request),
                    'request_id' => $request->header('X-Request-Id'),
                    ...$meta,
                ], fn ($v) => $v !== null && $v !== ''),
            ]);
        } catch (Throwable $e) {
            // Never turn a rejected request into a 500.
            Log::error('Failed to record security event', ['reason' => $reason, 'error' => $e->getMessage()]);

            return;
        }

        $this->maybeAlert($ip, $reason);
    }

    private function maybeAlert(string $ip, string $reason): void
    {
        $window = (int) config('audit.security.alert_window_minutes', 5);
        $threshold = (int) config('audit.security.alert_threshold', 20);
        $counterKey = 'security:rejections:'.sha1($ip);

        Cache::add($counterKey, 0, now()->addMinutes($window));
        $count = (int) Cache::increment($counterKey);

        if ($count === $threshold) {
            $this->alerts->send(
                key: "security:ip:{$ip}",
                title: 'Repeated rejected API requests',
                message: "IP {$ip} was rejected {$count} times in {$window} minutes (last reason: {$reason}).",
                level: 'critical',
                context: ['ip' => $ip, 'last_reason' => $reason],
            );
        }
    }

    private function keyPrefix(Request $request): ?string
    {
        $token = (string) $request->bearerToken();

        return $token === '' ? null : mb_substr($token, 0, 8);
    }
}
