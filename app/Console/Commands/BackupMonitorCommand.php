<?php

namespace App\Console\Commands;

use App\Models\BackupRun;
use App\Services\Alerts\AlertNotifier;
use App\Services\Backup\BackupSpool;
use App\Services\Backup\BackupStatus;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('backup:monitor')]
#[Description('Import results from the backup container and alert on failed, missing or abnormal backups')]
class BackupMonitorCommand extends Command
{
    public function handle(BackupSpool $spool, BackupStatus $status, AlertNotifier $alerts): int
    {
        $imported = 0;

        foreach ($spool->pendingStatuses() as ['file' => $file, 'data' => $data]) {
            $run = $this->import($data);
            $spool->markProcessed($file);
            $imported++;

            if ($run->status === 'failed') {
                $alerts->send("backup:failed:{$run->type}", "Backup failed: {$run->type}", (string) $run->error, 'critical', ['location' => $run->location]);
            } elseif ($this->abnormalSize($run)) {
                $alerts->send("backup:size:{$run->type}", "Backup size is abnormal: {$run->type}",
                    'Size differs from the median of recent backups by more than '.((float) config('audit.backup.size_deviation') * 100).'%.',
                    'warning', ['size' => $run->size, 'location' => $run->location]);
            }
        }

        foreach ($status->problems() as $problem) {
            $alerts->send('backup:stale:'.md5($problem), 'Backup is late', $problem, 'critical');
        }

        $this->components->info("Imported {$imported} backup result(s).");

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $data */
    private function import(array $data): BackupRun
    {
        $type = in_array($data['type'] ?? null, ['full', 'incremental', 'binlog', 'restore_test'], true) ? $data['type'] : 'full';
        $externalId = (string) ($data['id'] ?? '');

        $existing = $externalId === '' ? null : BackupRun::query()->where('meta->run_id', $externalId)->first();

        $attributes = [
            'type' => $type,
            'status' => ($data['status'] ?? '') === 'succeeded' ? 'succeeded' : 'failed',
            'size' => isset($data['size']) ? (int) $data['size'] : null,
            'location' => $data['location'] ?? null,
            'checksum' => $data['checksum'] ?? null,
            'started_at' => CarbonImmutable::parse($data['started_at'] ?? 'now'),
            'finished_at' => isset($data['finished_at']) ? CarbonImmutable::parse($data['finished_at']) : now(),
            'error' => isset($data['error']) ? mb_substr((string) $data['error'], 0, 2000) : null,
            'meta' => [...(is_array($data['meta'] ?? null) ? $data['meta'] : []), 'run_id' => $externalId, 'source' => 'backup-container'],
            'triggered_by' => $data['requested_by'] ?? null,
        ];

        if ($existing) {
            $existing->forceFill($attributes)->save();

            return $existing;
        }

        return BackupRun::query()->create($attributes);
    }

    private function abnormalSize(BackupRun $run): bool
    {
        if ($run->status !== 'succeeded' || ! $run->size || ! in_array($run->type, ['full', 'app_files'], true)) {
            return false;
        }

        $sizes = BackupRun::query()->where('type', $run->type)->where('status', 'succeeded')
            ->whereKeyNot($run->id)->latest('started_at')->limit(7)->pluck('size')->filter()->sort()->values();

        if ($sizes->count() < 3) {
            return false;
        }

        $median = (float) $sizes[(int) floor($sizes->count() / 2)];
        $deviation = (float) config('audit.backup.size_deviation', 0.5);

        return $median > 0 && abs($run->size - $median) / $median > $deviation;
    }
}
