<?php

namespace App\Services\Archive;

use App\Models\Project;
use App\Services\Audit\PlatformAuditLogger;
use App\Support\Database\AuditLogPartitionManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Retention (decision D3), always after archiving:
 *
 *  1. monthly partitions entirely older than the platform maximum are
 *     dropped in one operation (fast);
 *  2. rows older than each project's own retention_days are deleted in small
 *     batches through the archiver account - the only account the triggers
 *     allow to DELETE.
 *
 * Nothing above the archive watermark is ever deleted.
 */
class RetentionPruner
{
    public function __construct(
        private AuditLogPartitionManager $partitions,
        private PlatformAuditLogger $audit,
    ) {}

    /**
     * @return array{dropped_partitions: list<string>, deleted_rows: int, deleted_keys: int, skipped: list<string>, deleted_security_events?: int}
     */
    public function prune(?CarbonImmutable $now = null, bool $dryRun = false): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $watermark = LogArchiver::watermark();
        $archiver = DB::connection((string) config('audit.archiver.connection', 'archiver'));
        $batchSize = (int) config('audit.retention.delete_batch_size', 5000);
        $maxCutoff = $now->subDays((int) config('audit.retention.max_days', 395));

        $result = ['dropped_partitions' => [], 'deleted_rows' => 0, 'deleted_keys' => 0, 'skipped' => []];

        // 1. Whole partitions older than the platform ceiling.
        foreach ($this->partitions->partitions() as $partition) {
            $month = AuditLogPartitionManager::monthFromName($partition['name']);
            if ($month === null || $month->addMonth()->greaterThan($maxCutoff)) {
                continue;
            }

            $maxId = (int) DB::selectOne("SELECT COALESCE(MAX(id), 0) AS m FROM audit_logs PARTITION ({$partition['name']})")->m;
            if ($maxId > $watermark) {
                $result['skipped'][] = "{$partition['name']} (not fully archived: max id {$maxId} > watermark {$watermark})";

                continue;
            }

            if (! $dryRun) {
                $result['deleted_keys'] += $this->deleteKeys($archiver, null, $month->addMonth(), $batchSize);
                DB::connection((string) config('audit.maintenance_connection', 'maintenance'))
                    ->statement("ALTER TABLE audit_logs DROP PARTITION {$partition['name']}");
            }
            $result['dropped_partitions'][] = $partition['name'];
        }

        // 2. Per-project retention, row by row in batches.
        $projects = Project::query()->withoutGlobalScopes()->get(['id', 'slug', 'retention_days']);
        foreach ($projects as $project) {
            $days = min((int) $project->retention_days, (int) config('audit.retention.max_days', 395));
            $cutoff = $now->subDays($days);

            if ($dryRun) {
                $result['deleted_rows'] += (int) DB::table('audit_logs')
                    ->where('project_id', $project->id)
                    ->where('occurred_at', '<', $cutoff->format('Y-m-d H:i:s.v'))
                    ->where('id', '<=', $watermark)
                    ->count();

                continue;
            }

            do {
                $deleted = $archiver->delete(
                    'DELETE FROM audit_logs WHERE project_id = ? AND occurred_at < ? AND id <= ? LIMIT '.$batchSize,
                    [$project->id, $cutoff->format('Y-m-d H:i:s.v'), $watermark],
                );
                $result['deleted_rows'] += $deleted;
            } while ($deleted === $batchSize);

            $result['deleted_keys'] += $this->deleteKeys($archiver, $project->id, $cutoff, $batchSize);
        }

        if (! $dryRun && ($result['deleted_rows'] > 0 || $result['dropped_partitions'] !== [])) {
            $this->audit->record('audit.pruned', 'audit_logs', [...$result, 'watermark' => $watermark]);
        }

        // security_events is not append-only: old rejections are simply removed.
        if (! $dryRun) {
            $result['deleted_security_events'] = DB::table('security_events')
                ->where('created_at', '<', $now->subDays((int) config('audit.security.events_retention_days', 365))->format('Y-m-d H:i:s.v'))
                ->delete();
        }

        return $result;
    }

    private function deleteKeys(ConnectionInterface $archiver, ?int $projectId, CarbonImmutable $before, int $batchSize): int
    {
        $total = 0;
        do {
            $deleted = $archiver->delete(
                'DELETE FROM ingestion_keys WHERE occurred_at < ?'.($projectId ? ' AND project_id = ?' : '').' LIMIT '.$batchSize,
                $projectId ? [$before->format('Y-m-d H:i:s.v'), $projectId] : [$before->format('Y-m-d H:i:s.v')],
            );
            $total += $deleted;
        } while ($deleted === $batchSize);

        return $total;
    }
}
