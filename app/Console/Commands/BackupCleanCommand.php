<?php

namespace App\Console\Commands;

use App\Services\Audit\PlatformAuditLogger;
use App\Services\Backup\GfsRetention;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('backup:clean {--dry-run}')]
#[Description('Apply the retention policy (daily 14, weekly 8, monthly 12) to off-site backups')]
class BackupCleanCommand extends Command
{
    public function handle(PlatformAuditLogger $audit): int
    {
        $disk = Storage::disk((string) config('audit.backup.disk'));
        $cfg = config('audit.backup');
        $deleted = [];

        // Full DB backups and app-file backups follow GFS.
        foreach (['db/full', 'app'] as $prefix) {
            $backups = [];
            foreach ($disk->allFiles($prefix) as $path) {
                if ($at = self::timestamp($path)) {
                    $backups[$path] = $at;
                }
            }
            $deleted = [...$deleted, ...GfsRetention::toDelete($backups, (int) $cfg['keep_daily'], (int) $cfg['keep_weekly'], (int) $cfg['keep_monthly'])];
        }

        // Incrementals and binary logs are only useful on top of a recent
        // full backup: keep them for the daily window.
        $limit = CarbonImmutable::now('UTC')->subDays((int) $cfg['keep_daily']);
        foreach (['db/incremental', 'db/binlog'] as $prefix) {
            foreach ($disk->allFiles($prefix) as $path) {
                $at = self::timestamp($path);
                if ($at !== null && $at->lessThan($limit)) {
                    $deleted[] = $path;
                }
            }
        }

        foreach ($deleted as $path) {
            $this->line(($this->option('dry-run') ? '[dry-run] ' : '').'delete '.$path);
            if (! $this->option('dry-run')) {
                $disk->delete($path);
            }
        }

        if (! $this->option('dry-run') && $deleted !== []) {
            $audit->record('backup.cleaned', 'backup', ['deleted' => count($deleted)]);
        }

        $this->components->info(count($deleted).' old backup file(s) '.($this->option('dry-run') ? 'would be ' : '').'removed.');

        return self::SUCCESS;
    }

    /** Backup files carry a UTC timestamp like 20261111T020000Z in their name. */
    public static function timestamp(string $path): ?CarbonImmutable
    {
        if (! preg_match('/(\d{8}T\d{6}Z)/', basename($path), $m)) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Ymd\THis\Z', $m[1], 'UTC') ?: null;
    }
}
