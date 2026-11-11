<?php

namespace App\Support\Database;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Monthly RANGE partitioning of audit_logs on TO_DAYS(occurred_at).
 *
 * Layout:
 *   p_before     everything older than the first monthly partition
 *   pYYYYMM      one partition per month (rows of that calendar month, UTC)
 *   p_future     MAXVALUE catch-all; new months are split out of it
 *
 * New months are added with REORGANIZE PARTITION p_future, which is cheap
 * while p_future is empty. The scheduler keeps `ahead` months ready, so in
 * normal operation p_future never receives rows.
 */
final class AuditLogPartitionManager
{
    public const TABLE = 'audit_logs';

    public const BEFORE = 'p_before';

    public const FUTURE = 'p_future';

    public function __construct(private ?ConnectionInterface $connection = null)
    {
        $this->connection ??= DB::connection();
    }

    /**
     * The "PARTITION BY ..." clause used when the table is created.
     */
    public function initialClause(CarbonImmutable $now, int $backfill, int $ahead): string
    {
        $first = $now->startOfMonth()->subMonths(max(0, $backfill));
        $last = $now->startOfMonth()->addMonths(max(1, $ahead));

        $parts = [sprintf(
            'PARTITION %s VALUES LESS THAN (TO_DAYS(%s))',
            self::BEFORE,
            $this->quoteDate($first),
        )];

        foreach ($this->monthsBetween($first, $last) as $month) {
            $parts[] = $this->definitionFor($month);
        }

        $parts[] = sprintf('PARTITION %s VALUES LESS THAN MAXVALUE', self::FUTURE);

        return "PARTITION BY RANGE (TO_DAYS(occurred_at)) (\n    ".implode(",\n    ", $parts)."\n)";
    }

    /**
     * Make sure monthly partitions exist up to $now + $ahead months.
     *
     * @return list<string> names of the partitions that were created
     */
    public function ensureAhead(CarbonImmutable $now, int $ahead, bool $dryRun = false): array
    {
        $lastMonth = $this->lastMonthlyPartition();

        if ($lastMonth === null) {
            throw new RuntimeException('audit_logs has no monthly partitions - was it created by its migration?');
        }

        $target = $now->startOfMonth()->addMonths(max(1, $ahead));
        $missing = $this->monthsBetween($lastMonth->addMonth(), $target);

        if ($missing === [] || $dryRun) {
            return array_map(fn (CarbonImmutable $m) => self::nameFor($m), $missing);
        }

        $definitions = array_map(fn (CarbonImmutable $m) => $this->definitionFor($m), $missing);
        $definitions[] = sprintf('PARTITION %s VALUES LESS THAN MAXVALUE', self::FUTURE);

        $this->connection->statement(sprintf(
            'ALTER TABLE `%s` REORGANIZE PARTITION %s INTO (%s)',
            self::TABLE,
            self::FUTURE,
            implode(', ', $definitions),
        ));

        return array_map(fn (CarbonImmutable $m) => self::nameFor($m), $missing);
    }

    /**
     * @return list<array{name: string, ordinal: int, rows: int, bound: string}>
     */
    public function partitions(): array
    {
        $rows = $this->connection->select(
            'SELECT PARTITION_NAME AS name, PARTITION_ORDINAL_POSITION AS ordinal,
                    TABLE_ROWS AS row_estimate, PARTITION_DESCRIPTION AS bound
               FROM information_schema.PARTITIONS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
              ORDER BY PARTITION_ORDINAL_POSITION',
            [self::TABLE],
        );

        return array_map(fn (object $r) => [
            'name' => (string) $r->name,
            'ordinal' => (int) $r->ordinal,
            'rows' => (int) $r->row_estimate,
            'bound' => (string) $r->bound,
        ], array_values(array_filter($rows, fn (object $r) => $r->name !== null)));
    }

    public function lastMonthlyPartition(): ?CarbonImmutable
    {
        $months = array_filter(
            array_map(fn (array $p) => self::monthFromName($p['name']), $this->partitions()),
        );

        if ($months === []) {
            return null;
        }

        usort($months, fn (CarbonImmutable $a, CarbonImmutable $b) => $a <=> $b);

        return end($months);
    }

    public static function nameFor(CarbonImmutable $month): string
    {
        return 'p'.$month->format('Ym');
    }

    public static function monthFromName(string $name): ?CarbonImmutable
    {
        if (! preg_match('/^p(\d{4})(\d{2})$/', $name, $m)) {
            return null;
        }

        return CarbonImmutable::create((int) $m[1], (int) $m[2], 1, 0, 0, 0, 'UTC');
    }

    private function definitionFor(CarbonImmutable $month): string
    {
        return sprintf(
            'PARTITION %s VALUES LESS THAN (TO_DAYS(%s))',
            self::nameFor($month),
            $this->quoteDate($month->addMonth()),
        );
    }

    /**
     * Months from $from up to and including $to (both normalised to the 1st).
     *
     * @return list<CarbonImmutable>
     */
    private function monthsBetween(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $months = [];
        $cursor = $from->startOfMonth();
        $end = $to->startOfMonth();

        while ($cursor <= $end) {
            $months[] = $cursor;
            $cursor = $cursor->addMonth();
        }

        return $months;
    }

    private function quoteDate(CarbonImmutable $date): string
    {
        return "'".$date->format('Y-m-d')."'";
    }
}
