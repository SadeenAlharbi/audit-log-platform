<?php

namespace App\Services\Backup;

use App\Models\User;
use App\Services\Audit\PlatformAuditLogger;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Shared folder between the app and the "backup" container
 * (storage/app/backup-spool <-> /spool):
 *
 *   requests/<type>-<ulid>.req   written by the app, executed by backup.sh
 *   status/<id>.json             written by backup.sh after every run
 *   status/processed/            status files already imported by backup:monitor
 *   reports/                     restore-test reports (Markdown)
 *
 * The app never needs Docker access, and the backup container never needs
 * database write access.
 */
class BackupSpool
{
    public const REQUEST_TYPES = ['full', 'incremental', 'restore-test'];

    public function __construct(private PlatformAuditLogger $audit) {}

    public function path(string $sub = ''): string
    {
        return rtrim((string) config('audit.backup.spool_path'), '/').($sub === '' ? '' : '/'.$sub);
    }

    public function request(string $type, ?User $by = null): string
    {
        if (! in_array($type, self::REQUEST_TYPES, true)) {
            throw new InvalidArgumentException("Unknown backup request [{$type}].");
        }

        File::ensureDirectoryExists($this->path('requests'));
        $file = $this->path('requests/'.$type.'-'.Str::ulid().'.req');
        File::put($file, json_encode([
            'type' => $type,
            'requested_by' => $by?->id,
            'requested_at' => now()->utc()->toIso8601String(),
        ]));

        $this->audit->record('backup.requested', 'backup', ['type' => $type], $by);

        return $file;
    }

    /**
     * Status files not imported yet, oldest first.
     *
     * @return list<array{file: string, data: array<string, mixed>}>
     */
    public function pendingStatuses(): array
    {
        $dir = $this->path('status');
        if (! is_dir($dir)) {
            return [];
        }

        $files = glob($dir.'/*.json') ?: [];
        sort($files);

        $out = [];
        foreach ($files as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data)) {
                $out[] = ['file' => $file, 'data' => $data];
            }
        }

        return $out;
    }

    public function markProcessed(string $file): void
    {
        File::ensureDirectoryExists($this->path('status/processed'));
        File::move($file, $this->path('status/processed/'.basename($file)));
    }
}
