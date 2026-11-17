<?php

namespace App\Filament\Resources\AuditLogs\Tables;

use App\Models\AuditLog;
use App\Support\Time\DisplayTime;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Values offered by the audit log filters: what has actually been received,
 * limited to the projects the viewer may see (AuditLog's global scope).
 *
 * Every query (the list shown when a filter opens, and the search while
 * typing) is limited to the period chosen in the "period" filter, or to the
 * last 90 days when none is given, so MariaDB reads only those partitions
 * and never scans the whole table with LIKE '%...%'. The list is cached for
 * five minutes per viewer and period; at most 50 results.
 *
 * @phpstan-type Period array{from?: mixed, until?: mixed}
 */
class AuditLogFilterOptions
{
    public const RECENT_DAYS = 90;

    public const LIMIT = 50;

    /**
     * @param  Period  $period
     * @return array<string, string>
     */
    public static function events(?string $search = null, array $period = []): array
    {
        $events = self::distinct('event', $search, $period, 200);

        // "user.*" style families first, so a whole family can be picked.
        $prefixes = [];
        foreach ($events as $event) {
            $parts = explode('.', $event);
            for ($i = 1; $i < count($parts); $i++) {
                $prefix = implode('.', array_slice($parts, 0, $i)).'.*';
                if ($search === null || str_contains($prefix, $search)) {
                    $prefixes[$prefix] = $prefix;
                }
            }
        }
        ksort($prefixes);

        return array_slice($prefixes + array_combine($events, $events), 0, self::LIMIT * 2, true);
    }

    /**
     * @param  Period  $period
     * @return array<string, string>
     */
    public static function actions(?string $search = null, array $period = []): array
    {
        $options = [];
        foreach (self::distinct('action', $search, $period) as $action) {
            $options[$action] = self::actionLabel($action);
        }

        return $options;
    }

    public static function actionLabel(string $action): string
    {
        $arabic = AuditLogsTable::ACTION_LABELS[$action] ?? null;

        return $arabic === null ? $action : "{$arabic} ({$action})";
    }

    /**
     * @param  Period  $period
     * @return array<string, string> actor_id => "name (type #id)"
     */
    public static function actors(?string $search = null, array $period = []): array
    {
        return self::cached('actor_id', $search, $period, fn () => self::base($period)
            ->whereNotNull('actor_id')
            ->when($search !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('actor_id', 'like', self::like((string) $search))
                ->orWhere('actor_name', 'like', self::like((string) $search))))
            ->selectRaw('actor_id, MAX(actor_name) AS actor_name, MAX(actor_type) AS actor_type')
            ->groupBy('actor_id')
            ->orderBy('actor_id')
            ->limit(self::LIMIT)
            ->toBase()->get()
            ->mapWithKeys(fn ($row) => [(string) $row->actor_id => self::actorLabel($row->actor_id, $row->actor_name, $row->actor_type)])
            ->all());
    }

    public static function actorLabel(mixed $id, ?string $name, ?string $type): string
    {
        $ref = trim(($type ?? '').' #'.$id);

        return filled($name) ? "{$name} ({$ref})" : $ref;
    }

    /**
     * @param  Period  $period
     * @return array<string, string>
     */
    public static function values(string $column, ?string $search = null, array $period = []): array
    {
        $values = self::distinct($column, $search, $period);

        return array_combine($values, $values);
    }

    /**
     * @param  Period  $period
     * @return array<string, string>
     */
    public static function tags(?string $search = null, array $period = []): array
    {
        return self::cached('tags', $search, $period, function () use ($search, $period) {
            $tags = self::base($period)
                ->whereNotNull('tags')
                ->when($search !== null, fn (Builder $q) => $q->where('tags', 'like', self::like((string) $search)))
                ->latest('occurred_at')
                ->limit(2000)
                ->pluck('tags')
                ->flatten()
                ->filter(fn ($tag) => is_string($tag) && ($search === null || str_contains(mb_strtolower($tag), mb_strtolower($search))))
                ->unique()->sort()->take(self::LIMIT)->values()->all();

            return array_combine($tags, $tags);
        });
    }

    /**
     * @param  Period  $period
     * @return list<string>
     */
    private static function distinct(string $column, ?string $search, array $period, int $limit = self::LIMIT): array
    {
        return self::cached($column, $search, $period, fn () => self::base($period)
            ->whereNotNull($column)
            ->when($search !== null, fn (Builder $q) => $q->where($column, 'like', self::like((string) $search)))
            ->distinct()
            ->orderBy($column)
            ->limit($limit)
            ->pluck($column)
            ->map(fn ($v) => (string) $v)
            ->all());
    }

    /**
     * @param  Period  $period
     * @return Builder<AuditLog>
     */
    private static function base(array $period): Builder
    {
        [$from, $until] = self::range($period);

        return AuditLog::query()
            ->where('occurred_at', '>=', $from)
            ->when($until !== null, fn (Builder $q) => $q->where('occurred_at', '<=', $until))
            ->reorder();
    }

    /**
     * The period filter's range in UTC; the last 90 days when it is empty.
     *
     * @param  Period  $period
     * @return array{0: CarbonImmutable, 1: CarbonImmutable|null}
     */
    public static function range(array $period): array
    {
        // The period pickers hold local time (DisplayTime).
        $parse = fn (mixed $value): ?CarbonImmutable => filled($value) && (is_string($value) || $value instanceof DateTimeInterface)
            ? DisplayTime::toUtc($value)
            : null;

        return [$parse($period['from'] ?? null) ?? DisplayTime::startOfDayUtc(self::RECENT_DAYS), $parse($period['until'] ?? null)];
    }

    /**
     * @template T
     *
     * @param  Period  $period
     * @param  Closure(): T  $load
     * @return T
     */
    private static function cached(string $column, ?string $search, array $period, Closure $load): mixed
    {
        // Searches are not cached: they change with every key press.
        if ($search !== null) {
            return $load();
        }

        [$from, $until] = self::range($period);
        $periodKey = $from->format('YmdHi').'-'.($until?->format('YmdHi') ?? 'now');

        return Cache::remember('audit-filter:'.$column.':'.(auth()->id() ?? 'guest').':'.$periodKey, 300, $load);
    }

    private static function like(string $search): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search).'%';
    }
}
