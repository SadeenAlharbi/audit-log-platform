<?php

namespace App\Console\Commands;

use App\Models\BackupRun;
use App\Services\Backup\BackupSpool;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('backup:restore-test {--wait=0 : Minutes to wait for the result}')]
#[Description('Ask the backup container to restore the latest backup into a temporary server and verify it')]
class BackupRestoreTestCommand extends Command
{
    public function handle(BackupSpool $spool): int
    {
        $since = now();
        $spool->request('restore-test');
        $this->components->info('Restore test requested. The backup container picks it up within a minute.');

        $wait = (int) $this->option('wait');
        if ($wait <= 0) {
            return self::SUCCESS;
        }

        $deadline = now()->addMinutes($wait);
        while (now()->lessThan($deadline)) {
            $this->callSilently('backup:monitor');
            $run = BackupRun::query()->where('type', 'restore_test')->where('started_at', '>=', $since->subMinute())->latest('id')->first();

            if ($run !== null) {
                $run->status === 'succeeded'
                    ? $this->components->info('Restore test succeeded: '.json_encode($run->meta))
                    : $this->components->error('Restore test failed: '.$run->error);

                return $run->status === 'succeeded' ? self::SUCCESS : self::FAILURE;
            }

            sleep(15);
        }

        $this->components->warn('No result yet; check the Backups page later.');

        return self::FAILURE;
    }
}
