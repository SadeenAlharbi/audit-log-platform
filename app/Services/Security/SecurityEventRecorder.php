<?php

namespace App\Services\Security;

use App\Models\Project;
use App\Models\ProjectApiKey;
use App\Models\SecurityEvent;
use App\Services\Alerts\AlertNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records every rejected API request in security_events (plan §7) and
 * alerts when the same IP keeps getting rejected.
 *
 * Every attempt is recorded (decision D21): the first N per IP, reason and
 * minute get their own row; the rest of that minute are counted in the cache
 * and written as ONE summary row (request_meta.suppressed = count) by
 * audit:flush-security-events, so a flood cannot become a flood of INSERTs
 * (security review M4) and no attempt goes unrecorded.
 */
class SecurityEventRecorder
{
    public const REASONS = [
        'missing_credentials', 'invalid_key', 'project_mismatch', 'key_revoked', 'key_expired',
        'project_inactive', 'ip_not_allowed', 'scope_denied', 'missing_signature', 'invalid_signature',
        'stale_timestamp', 'replayed_request', 'rate_limited', 'https_required', 'payload_too_large',
        'unsupported_media_type', 'ip_rate_limited',
    ];

    private const SUPPRESSED_PREFIX = 'security:suppressed:';

    /** Counters live this long; the flush (every minute) reads the minutes before the current one. */
    private const SUPPRESSED_TTL_MINUTES = 15;

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
            $this->countSuppressed($ip, $reason, $project->id ?? $key->project_id ?? null, $key?->id);
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

    /**
     * Count an attempt over the per-minute sample in the cache. The first one
     * of each IP and reason registers itself in the minute's index, which
     * flushSuppressed() turns into summary rows.
     */
    private function countSuppressed(string $ip, string $reason, ?int $projectId, ?int $keyId): void
    {
        $minute = now()->utc()->format('YmdHi');
        $hash = sha1($ip.'|'.$reason);
        $counter = self::SUPPRESSED_PREFIX.$minute.':'.$hash;

        Cache::add($counter, 0, now()->addMinutes(self::SUPPRESSED_TTL_MINUTES));
        if ((int) Cache::increment($counter) !== 1) {
            return;
        }

        $index = self::SUPPRESSED_PREFIX.'index:'.$minute;
        Cache::lock($index.':lock', 5)->block(3, function () use ($index, $hash, $ip, $reason, $projectId, $keyId) {
            /** @var array<string, array{ip: string, reason: string, project_id: int|null, api_key_id: int|null}> $entries */
            $entries = Cache::get($index, []);
            $entries[$hash] = ['ip' => $ip, 'reason' => $reason, 'project_id' => $projectId, 'api_key_id' => $keyId];
            Cache::put($index, $entries, now()->addMinutes(self::SUPPRESSED_TTL_MINUTES));
        });
    }

    /**
     * Write one summary row per IP and reason for every finished minute that
     * had attempts over the sample. Returns the number of attempts written.
     */
    public function flushSuppressed(): int
    {
        $total = 0;
        $current = CarbonImmutable::now('UTC')->startOfMinute();

        for ($ago = self::SUPPRESSED_TTL_MINUTES - 1; $ago >= 1; $ago--) {
            $minute = $current->subMinutes($ago);
            $index = self::SUPPRESSED_PREFIX.'index:'.$minute->format('YmdHi');

            /** @var array<string, array{ip: string, reason: string, project_id: int|null, api_key_id: int|null}> $entries */
            $entries = Cache::pull($index, []);
            foreach ($entries as $hash => $entry) {
                $count = (int) Cache::pull(self::SUPPRESSED_PREFIX.$minute->format('YmdHi').':'.$hash, 0);
                if ($count < 1) {
                    continue;
                }

                SecurityEvent::query()->create([
                    'project_id' => $entry['project_id'],
                    'api_key_id' => $entry['api_key_id'],
                    'ip' => $entry['ip'],
                    'reason' => $entry['reason'],
                    'request_meta' => [
                        'suppressed' => $count,
                        'window_start' => $minute->toIso8601String(),
                        'note' => 'attempts over the per-minute sample, counted not stored one by one',
                    ],
                    'created_at' => $minute->addMinute()->subMillisecond(),
                ]);
                $total += $count;
            }
        }

        return $total;
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
