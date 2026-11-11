<?php

namespace App\Console\Commands;

use App\Services\Alerts\AlertNotifier;
use App\Services\Archive\LogArchiver;
use App\Services\Archive\RetentionPruner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('audit:prune {--dry-run : Only report what would be deleted} {--skip-archive : Do not run audit:archive first}')]
#[Description('Archive, then delete logs older than each project\'s retention (drops expired monthly partitions)')]
class PruneLogsCommand extends Command
{
    public function handle(LogArchiver $archiver, RetentionPruner $pruner, AlertNotifier $alerts): int
    {
        try {
            if (! $this->option('skip-archive') && ! $this->option('dry-run')) {
                $archived = $archiver->run();
                $this->components->info("Archived {$archived['rows']} pending log(s) first.");
            }

            $result = $pruner->prune(dryRun: (bool) $this->option('dry-run'));
        } catch (Throwable $e) {
            $alerts->send('prune:failed', 'Retention prune failed', $e->getMessage(), 'critical');
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $verb = $this->option('dry-run') ? 'Would delete' : 'Deleted';
        $this->components->info("{$verb} {$result['deleted_rows']} row(s); partitions: ".(implode(', ', $result['dropped_partitions']) ?: 'none').'.');

        foreach ($result['skipped'] as $skipped) {
            $this->components->warn("Skipped {$skipped}");
            $alerts->send('prune:skipped:'.$skipped, 'Partition not pruned', "Partition {$skipped} was kept because it is not fully archived.");
        }

        return self::SUCCESS;
    }
}
