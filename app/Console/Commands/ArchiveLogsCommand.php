<?php

namespace App\Console\Commands;

use App\Services\Alerts\AlertNotifier;
use App\Services\Archive\LogArchiver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('audit:archive {--max-rows= : Stop after this many rows (default: everything pending)}')]
#[Description('Copy newly stored audit logs to the archive disk as compressed JSONL (write-ahead archive)')]
class ArchiveLogsCommand extends Command
{
    public function handle(LogArchiver $archiver, AlertNotifier $alerts): int
    {
        try {
            $result = $archiver->run($this->option('max-rows') !== null ? (int) $this->option('max-rows') : null);
        } catch (Throwable $e) {
            $alerts->send('archive:failed', 'Audit log archive failed', $e->getMessage(), 'critical');
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Archived {$result['rows']} log(s) into {$result['files']} file(s). Watermark: {$result['last_id']}.");

        return self::SUCCESS;
    }
}
