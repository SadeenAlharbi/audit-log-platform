<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\BackupRun;
use App\Services\Audit\PlatformAuditLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Plan §12: every backup AND restore is recorded in backup_runs. Full and
 * point-in-time restores are done by hand (docs/runbook.md §3.1/§3.2), so
 * the operator records them with this command as the last step.
 */
#[Signature('backup:record-restore
    {kind : full or pitr}
    {--source= : Backup the restore started from (file name or remote path)}
    {--stop-at= : PITR: the --stop-datetime used (UTC)}
    {--by= : Who performed the restore (name or e-mail)}
    {--reason= : Why (incident reference)}')]
#[Description('Record a manual full or point-in-time database restore in backup_runs and platform_audit')]
class BackupRecordRestoreCommand extends Command
{
    public function handle(PlatformAuditLogger $audit): int
    {
        $kind = (string) $this->argument('kind');
        if (! in_array($kind, ['full', 'pitr'], true)) {
            $this->components->error('kind must be "full" or "pitr".');

            return self::FAILURE;
        }

        $meta = array_filter([
            'kind' => $kind,
            'source' => $this->option('source'),
            'stop_at' => $this->option('stop-at'),
            'performed_by' => $this->option('by'),
            'reason' => $this->option('reason'),
            // State after the restore, to compare with max_audit_id of the backup.
            'max_audit_id' => (int) AuditLog::query()->withoutGlobalScopes()->max('id'),
            'audit_logs' => AuditLog::query()->withoutGlobalScopes()->count(),
        ], fn ($v) => $v !== null && $v !== '');

        $run = BackupRun::query()->create([
            'type' => 'restore',
            'status' => 'succeeded',
            'started_at' => now(),
            'finished_at' => now(),
            'location' => $this->option('source'),
            'meta' => $meta,
        ]);

        $audit->record('backup.restore_recorded', $run, $meta);

        $this->components->info("Recorded restore #{$run->id}: max id {$meta['max_audit_id']}, {$meta['audit_logs']} audit logs.");

        return self::SUCCESS;
    }
}
