<?php

namespace App\Services\Backup;

use App\Models\BackupRun;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Backs up the application files (.env + storage/app) to the backups disk,
 * encrypted with age when BACKUP_AGE_RECIPIENT is set (plan §12).
 *
 * The database itself is backed up by the "backup" container
 * (mariadb-backup + binary logs).
 */
class AppFilesBackup
{
    public function run(?User $by = null): BackupRun
    {
        $run = BackupRun::query()->create([
            'type' => 'app_files',
            'status' => 'running',
            'started_at' => now(),
            'triggered_by' => $by?->id,
        ]);

        $tmp = storage_path('app/tmp/app-backup-'.Str::ulid());
        @mkdir(dirname($tmp), 0700, true);

        try {
            $archive = $tmp.'.tar.gz';
            $command = ['tar', '-czf', $archive,
                // app/backups is the local backups disk in development: without this
                // every backup would contain all earlier ones.
                '--exclude=app/backup-spool', '--exclude=app/tmp', '--exclude=app/backups',
                '-C', base_path(), 'storage/app'];

            // The configuration file lives at base_path('.env') in development;
            // production containers get it injected as environment variables,
            // so docker-compose.prod.yml mounts the host file read-only and
            // BACKUP_ENV_FILE points at it.
            $envFile = (string) config('audit.backup.env_file');
            $envIncluded = is_file($envFile) && is_readable($envFile);
            if ($envIncluded) {
                $command = [...$command, '-C', dirname($envFile), basename($envFile)];
            } elseif (! app()->environment(['local', 'testing'])) {
                throw new RuntimeException("The configuration file {$envFile} is not readable: set BACKUP_ENV_FILE or mount it (docker-compose.prod.yml).");
            }
            $this->exec($command);

            $recipient = config('audit.backup.age_recipient');
            // Security review M7: .env holds APP_KEY (which decrypts the HMAC
            // and 2FA secrets): never ship it unencrypted outside development.
            if (blank($recipient) && ! app()->environment(['local', 'testing'])) {
                throw new RuntimeException('BACKUP_AGE_RECIPIENT is not set: refusing to write an unencrypted backup.');
            }
            $final = $archive;
            if (filled($recipient)) {
                $final = $archive.'.age';
                $this->exec(['age', '-r', (string) $recipient, '-o', $final, $archive]);
                @unlink($archive);
            }

            $checksum = hash_file('sha256', $final);
            $size = filesize($final);
            $path = 'app/'.now()->utc()->format('Y/m/d').'/app-'.now()->utc()->format('Ymd\THis\Z').'.tar.gz'.(filled($recipient) ? '.age' : '');

            $stream = fopen($final, 'r');
            Storage::disk((string) config('audit.backup.disk'))->writeStream($path, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            $run->forceFill([
                'status' => 'succeeded',
                'finished_at' => now(),
                'size' => $size,
                'checksum' => $checksum,
                'location' => config('audit.backup.disk').':'.$path,
                'meta' => ['encrypted' => filled($recipient), 'env_included' => $envIncluded],
            ])->save();
        } catch (Throwable $e) {
            $run->forceFill(['status' => 'failed', 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000)])->save();

            throw $e;
        } finally {
            foreach (glob($tmp.'*') ?: [] as $file) {
                @unlink($file);
            }
        }

        return $run;
    }

    /** @param list<string> $command */
    private function exec(array $command): void
    {
        $process = new Process($command, base_path(), null, null, 600);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException($command[0].' failed: '.trim($process->getErrorOutput() ?: $process->getOutput()));
        }
    }
}
