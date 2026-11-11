<?php

namespace App\Services\Archive;

use App\Models\BackupRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Write-ahead archive of audit_logs (plan §9, §12): every stored log is
 * copied to S3/MinIO as gzip-compressed JSONL, independently of the database
 * backups, so the table can be rebuilt even if the database is lost.
 *
 *   logs/{project_slug}/{YYYY}/{MM}/{DD}/{firstId}-{lastId}.jsonl.gz
 *   logs/{project_slug}/{YYYY}/{MM}/{DD}/{firstId}-{lastId}.json   (manifest)
 *
 * Progress is a watermark on audit_logs.id (AUTO_INCREMENT, monotonic),
 * stored in backup_runs.meta.last_id of the last successful run. Retention
 * never deletes a row above the watermark.
 */
class LogArchiver
{
    public const COLUMNS = [
        'id', 'uid', 'project_id', 'api_key_id', 'batch_id', 'external_id', 'event', 'action', 'severity',
        'actor_type', 'actor_id', 'actor_name', 'actor_ip', 'user_agent', 'subject_type', 'subject_id',
        'old_values', 'new_values', 'metadata', 'tags', 'request_id', 'correlation_id',
        'occurred_at', 'received_at', 'created_at',
    ];

    public static function watermark(): int
    {
        $run = BackupRun::query()
            ->where('type', 'logs_archive')
            ->where('status', 'succeeded')
            ->latest('id')
            ->first();

        return (int) ($run?->meta['last_id'] ?? 0);
    }

    /**
     * @return array{files: int, rows: int, last_id: int}
     */
    public function run(?int $maxRows = null): array
    {
        $from = self::watermark();
        $run = BackupRun::query()->create([
            'type' => 'logs_archive',
            'status' => 'running',
            'started_at' => now(),
            'meta' => ['from_id' => $from, 'last_id' => $from],
        ]);

        $disk = Storage::disk((string) config('audit.archive.disk'));
        $chunk = (int) config('audit.archive.chunk_size', 5000);
        $settled = now()->utc()->subSeconds((int) config('audit.archive.settle_seconds', 120))->format('Y-m-d H:i:s.v');
        $slugs = DB::table('projects')->pluck('slug', 'id');

        $lastId = $from;
        $rows = 0;
        $files = 0;
        $bytes = 0;

        try {
            while ($maxRows === null || $rows < $maxRows) {
                $batch = DB::table('audit_logs')
                    ->select(self::COLUMNS)
                    ->where('id', '>', $lastId)
                    ->where('created_at', '<=', $settled)
                    ->orderBy('id')
                    ->limit($chunk)
                    ->get();

                if ($batch->isEmpty()) {
                    break;
                }

                foreach ($batch->groupBy('project_id') as $projectId => $logs) {
                    $first = $logs->first();
                    $last = $logs->last();
                    $slug = $slugs[$projectId] ?? 'project-'.$projectId;
                    $day = substr((string) $first->created_at, 0, 10);
                    $base = sprintf('logs/%s/%s/%s-%s', $slug, str_replace('-', '/', $day), $first->id, $last->id);

                    $jsonl = $logs->map(fn ($row) => json_encode(self::encodeRow($row), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))->implode("\n")."\n";
                    $gz = gzencode($jsonl, 6);

                    $disk->put($base.'.jsonl.gz', $gz);
                    $disk->put($base.'.json', json_encode([
                        'project_id' => (int) $projectId,
                        'project' => $slug,
                        'rows' => $logs->count(),
                        'first_id' => (int) $first->id,
                        'last_id' => (int) $last->id,
                        'occurred_from' => $logs->min('occurred_at'),
                        'occurred_to' => $logs->max('occurred_at'),
                        'sha256' => hash('sha256', $gz),
                        'created_at' => now()->utc()->toIso8601String(),
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                    $files++;
                    $bytes += strlen($gz);
                }

                $rows += $batch->count();
                $lastId = (int) $batch->last()->id;

                // Persist progress after every chunk so a crash never re-archives much.
                $run->forceFill(['meta' => ['from_id' => $from, 'last_id' => $lastId, 'rows' => $rows, 'files' => $files]])->save();
            }

            $run->forceFill([
                'status' => 'succeeded',
                'finished_at' => now(),
                'size' => $bytes,
                'location' => config('audit.archive.disk').':logs/',
                'meta' => ['from_id' => $from, 'last_id' => $lastId, 'rows' => $rows, 'files' => $files],
            ])->save();
        } catch (Throwable $e) {
            // The watermark of a failed run is not used; the next run resumes
            // from the last successful one (files are overwritten, not duplicated).
            $run->forceFill(['status' => 'failed', 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000)])->save();

            throw $e;
        }

        return ['files' => $files, 'rows' => $rows, 'last_id' => $lastId];
    }

    /**
     * JSON columns are stored decoded so the archive is plain JSON.
     *
     * @return array<string, mixed>
     */
    public static function encodeRow(object $row): array
    {
        $data = (array) $row;
        foreach (['old_values', 'new_values', 'metadata', 'tags'] as $col) {
            $data[$col] = $data[$col] === null ? null : json_decode((string) $data[$col], true);
        }
        foreach (['id', 'project_id', 'api_key_id', 'batch_id'] as $col) {
            $data[$col] = $data[$col] === null ? null : (int) $data[$col];
        }

        return $data;
    }
}
