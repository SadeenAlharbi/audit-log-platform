<?php

namespace App\Console\Commands;

use App\Support\Database\AuditLogPartitionManager;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('audit:partitions
    {--ahead= : Months to keep ready after the current month (default: config audit.partitions.ahead)}
    {--dry-run : Only show which partitions would be created}')]
#[Description('List the monthly partitions of audit_logs and create the upcoming ones')]
class AuditPartitionsCommand extends Command
{
    public function handle(AuditLogPartitionManager $manager): int
    {
        $ahead = (int) ($this->option('ahead') ?? config('audit.partitions.ahead', 3));
        $dryRun = (bool) $this->option('dry-run');

        $created = $manager->ensureAhead(CarbonImmutable::now('UTC'), $ahead, $dryRun);

        if ($created === []) {
            $this->components->info("Partitions are ready for the next {$ahead} month(s).");
        } elseif ($dryRun) {
            $this->components->warn('Would create: '.implode(', ', $created));
        } else {
            $this->components->info('Created: '.implode(', ', $created));
        }

        $this->table(
            ['#', 'Partition', 'Rows (estimate)', 'Upper bound (TO_DAYS)'],
            array_map(fn (array $p) => [$p['ordinal'], $p['name'], number_format($p['rows']), $p['bound']], $manager->partitions()),
        );

        $future = collect($manager->partitions())->firstWhere('name', AuditLogPartitionManager::FUTURE);
        if ($future !== null && $future['rows'] > 0) {
            $this->components->warn('p_future contains rows: some occurred_at values are later than the newest monthly partition.');
        }

        return self::SUCCESS;
    }
}
