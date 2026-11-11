<?php

namespace App\Services\Ingestion;

use App\Jobs\StoreAuditLogs;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\User;
use App\Services\Audit\PlatformAuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * "Send test log" from the dashboard: pushes a platform.test event through
 * the same queue + job as real logs and waits briefly for it to be stored.
 */
class TestLogSender
{
    public function __construct(private PlatformAuditLogger $audit) {}

    /**
     * @return array{uid: string, stored: bool}
     */
    public function send(Project $project, ?User $by = null, int $waitSeconds = 5): array
    {
        $now = CarbonImmutable::now('UTC');
        $uid = (string) Str::ulid();

        $row = [
            'uid' => $uid,
            'project_id' => $project->id,
            'api_key_id' => null,
            'batch_id' => null,
            'external_id' => null,
            'event' => 'platform.test',
            'action' => 'custom',
            'severity' => 'info',
            'actor_type' => 'dashboard_user',
            'actor_id' => $by ? (string) $by->id : null,
            'actor_name' => $by?->name,
            'actor_ip' => app()->runningInConsole() ? null : request()->ip(),
            'user_agent' => null,
            'subject_type' => 'project',
            'subject_id' => (string) $project->id,
            'old_values' => null,
            'new_values' => null,
            'metadata' => json_encode(['source' => 'dashboard test button'], JSON_UNESCAPED_UNICODE),
            'tags' => json_encode(['test']),
            'request_id' => null,
            'correlation_id' => null,
            'occurred_at' => $now->format(LogNormalizer::DATETIME_FORMAT),
            'received_at' => $now->format(LogNormalizer::DATETIME_FORMAT),
        ];

        StoreAuditLogs::dispatch([$row])->onQueue((string) config('audit.api.queue', 'ingest'));

        $this->audit->record('project.test_log_sent', $project, ['uid' => $uid], $by);

        $deadline = microtime(true) + $waitSeconds;
        do {
            if (AuditLog::query()->withoutGlobalScopes()->where('uid', $uid)->exists()) {
                return ['uid' => $uid, 'stored' => true];
            }
            usleep(250_000);
        } while (microtime(true) < $deadline);

        return ['uid' => $uid, 'stored' => false];
    }
}
