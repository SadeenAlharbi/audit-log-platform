<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Alerts\AlertNotifier;
use App\Services\Backup\AppFilesBackup;
use App\Services\Backup\BackupSpool;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('backup:run {--type=app : app (.env + storage) | full | incremental} {--user= : Requesting user id}')]
#[Description('Run a backup: application files here, database backups through the backup container')]
class BackupRunCommand extends Command
{
    public function handle(AppFilesBackup $appFiles, BackupSpool $spool, AlertNotifier $alerts): int
    {
        $type = (string) $this->option('type');
        $user = $this->option('user') ? User::query()->find($this->option('user')) : null;

        if (in_array($type, ['full', 'incremental'], true)) {
            $spool->request($type, $user);
            $this->components->info("Requested a {$type} database backup from the backup container.");

            return self::SUCCESS;
        }

        if ($type !== 'app') {
            $this->components->error('Unknown type. Use app, full or incremental.');

            return self::FAILURE;
        }

        try {
            $run = $appFiles->run($user);
        } catch (Throwable $e) {
            $alerts->send('backup:app:failed', 'Application files backup failed', $e->getMessage(), 'critical');
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Application files backed up to {$run->location}.");

        return self::SUCCESS;
    }
}
