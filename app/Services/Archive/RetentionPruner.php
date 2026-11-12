<?php

namespace App\Services\Archive;

use App\Models\BackupRun;
use App\Models\Project;
use App\Services\Audit\PlatformAuditLogger;
use App\Support\Database\AuditLogPartitionManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
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
 * Nothing above the archive watermark is ever deleted, and only rows whose
 * archive run is at least audit.archive.grace_days old (soft archive, plan
 * §9). Ranges restored from the archive on request are left alone while
 * their hold lasts (see ArchiveRestorer).
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
        $watermark = LogArchiver::settledWatermark($now, (int) config('audit.archive.grace_days', 7));
        $holds = self::activeHolds($now);
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

            $monthFrom = $month->format('Y-m-d H:i:s.v');
            $monthTo = $month->addMonth()->format('Y-m-d H:i:s.v');
            if ($holds->contains(fn (array $h): bool => $h['from'] < $monthTo && $h['to'] >= $monthFrom)) {
                $result['skipped'][] = "{$partition['name']} (holds logs restored from the archive)";

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

            // Restored ranges of this project that must survive tonight.
            $holdSql = '';
            $holdBindings = [];
            foreach ($holds->where('project_id', $project->id) as $hold) {
                $holdSql .= ' AND NOT (occurred_at BETWEEN ? AND ?)';
                array_push($holdBindings, $hold['from'], $hold['to']);
            }

            if ($dryRun) {
                $result['deleted_rows'] += (int) DB::selectOne(
                    'SELECT COUNT(*) AS c FROM audit_logs WHERE project_id = ? AND occurred_at < ? AND id <= ?'.$holdSql,
                    [$project->id, $cutoff->format('Y-m-d H:i:s.v'), $watermark, ...$holdBindings],
                )->c;

                continue;
            }

            do {
                $deleted = $archiver->delete(
                    'DELETE FROM audit_logs WHERE project_id = ? AND occurred_at < ? AND id <= ?'.$holdSql.' LIMIT '.$batchSize,
                    [$project->id, $cutoff->format('Y-m-d H:i:s.v'), $watermark, ...$holdBindings],
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

    /**
     * Archive restores whose hold has not expired yet.
     *
     * @return Collection<int, array{project_id: int, from: string, to: string}>
     */
    public static function activeHolds(CarbonImmutable $now): Collection
    {
        return BackupRun::query()
            ->where('type', 'restore')
            ->where('status', 'succeeded')
            ->get(['meta'])
            ->map(fn (BackupRun $run): array => (array) $run->meta)
            ->filter(fn (array $m): bool => isset($m['hold_until'], $m['project_id'], $m['from'], $m['to'])
                && CarbonImmutable::parse($m['hold_until'])->greaterThan($now))
            ->map(fn (array $m): array => [
                'project_id' => (int) $m['project_id'],
                'from' => CarbonImmutable::parse($m['from'])->utc()->format('Y-m-d H:i:s.v'),
                'to' => CarbonImmutable::parse($m['to'])->utc()->format('Y-m-d H:i:s.v'),
            ])
            ->values();
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
