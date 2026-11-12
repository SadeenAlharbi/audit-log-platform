<?php

namespace App\Services\Archive;

use App\Models\BackupRun;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Restores one project's logs from the JSONL archive (plan §9: "restore one
 * project's logs from the archive on request"). Rows keep their original
 * id and uid; rows that still exist are skipped (INSERT IGNORE), so running
 * it twice is harmless. The restored range is protected from the nightly
 * prune for audit.archive.restore_hold_days days.
 */
class ArchiveRestorer
{
    /**
     * @return array{files: int, rows: int, inserted: int}
     */
    public function restore(Project $project, CarbonImmutable $from, CarbonImmutable $to, ?User $by = null, bool $dryRun = false, ?int $holdDays = null): array
    {
        $disk = Storage::disk((string) config('audit.archive.disk'));
        $run = $dryRun ? null : BackupRun::query()->create([
            'type' => 'restore',
            'status' => 'running',
            'started_at' => now(),
            'triggered_by' => $by?->id,
            'meta' => [
                'project' => $project->slug,
                'project_id' => $project->id,
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                // The nightly prune leaves this range alone until then
                // (RetentionPruner::activeHolds), otherwise expired logs that
                // were restored on request would be deleted again that night.
                'hold_until' => now()->utc()->addDays($holdDays ?? (int) config('audit.archive.restore_hold_days', 30))->toIso8601String(),
            ],
        ]);

        $files = 0;
        $rows = 0;
        $inserted = 0;
        $fromStr = $from->utc()->format('Y-m-d H:i:s.v');
        $toStr = $to->utc()->format('Y-m-d H:i:s.v');

        try {
            foreach ($disk->allFiles('logs/'.$project->slug) as $manifestPath) {
                if (! str_ends_with($manifestPath, '.json')) {
                    continue;
                }

                $manifest = json_decode((string) $disk->get($manifestPath), true);
                if (! is_array($manifest) || ($manifest['occurred_to'] ?? '') < $fromStr || ($manifest['occurred_from'] ?? '') > $toStr) {
                    continue;
                }

                $gz = (string) $disk->get(substr($manifestPath, 0, -5).'.jsonl.gz');
                if (! hash_equals((string) $manifest['sha256'], hash('sha256', $gz))) {
                    throw new RuntimeException("Checksum mismatch for {$manifestPath}");
                }

                $files++;
                $batch = [];
                foreach (explode("\n", trim((string) gzdecode($gz))) as $line) {
                    $row = json_decode($line, true);
                    if (! is_array($row) || $row['occurred_at'] < $fromStr || $row['occurred_at'] > $toStr) {
                        continue;
                    }
                    $row['project_id'] = $project->id; // the slug is the stable identity
                    foreach (['old_values', 'new_values', 'metadata', 'tags'] as $col) {
                        $row[$col] = $row[$col] === null ? null : json_encode($row[$col], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                    $batch[] = $row;
                    $rows++;
                }

                if (! $dryRun) {
                    foreach (array_chunk($batch, 500) as $chunk) {
                        $inserted += DB::table('audit_logs')->insertOrIgnore($chunk);
                    }
                }
            }

            $run?->forceFill([
                'status' => 'succeeded',
                'finished_at' => now(),
                'meta' => [...$run->meta, 'files' => $files, 'rows' => $rows, 'inserted' => $inserted],
            ])->save();
        } catch (Throwable $e) {
            $run?->forceFill(['status' => 'failed', 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000)])->save();

            throw $e;
        }

        return ['files' => $files, 'rows' => $rows, 'inserted' => $inserted];
    }
}
