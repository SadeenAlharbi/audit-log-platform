<?php

namespace App\Services\Backup;

use Carbon\CarbonImmutable;

/**
 * Grandfather-father-son retention (plan §12): keep the newest backup of
 * each of the last N days, N weeks and N months.
 */
final class GfsRetention
{
    /**
     * @param  array<string, CarbonImmutable>  $backups  path => taken at
     * @return list<string> paths to delete
     */
    public static function toDelete(array $backups, int $daily, int $weekly, int $monthly, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        arsort($backups); // newest first

        $keep = [];
        $seen = ['d' => [], 'w' => [], 'm' => []];

        foreach ($backups as $path => $at) {
            $buckets = [
                'd' => [$at->format('Y-m-d'), $daily, $at->greaterThan($now->subDays($daily))],
                'w' => [$at->format('o-W'), $weekly, $at->greaterThan($now->subWeeks($weekly))],
                'm' => [$at->format('Y-m'), $monthly, $at->greaterThan($now->subMonths($monthly))],
            ];

            foreach ($buckets as $kind => [$bucket, $limit, $inWindow]) {
                if ($inWindow && ! isset($seen[$kind][$bucket]) && count($seen[$kind]) < $limit) {
                    $seen[$kind][$bucket] = true;
                    $keep[$path] = true;
                }
            }
        }

        return array_values(array_diff(array_keys($backups), array_keys($keep)));
    }
}
