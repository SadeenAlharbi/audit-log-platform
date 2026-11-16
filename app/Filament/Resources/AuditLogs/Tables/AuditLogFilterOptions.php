<?php

namespace App\Filament\Resources\AuditLogs\Tables;

use App\Models\AuditLog;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Values offered by the audit log filters: what has actually been received,
 * limited to the projects the viewer may see (AuditLog's global scope).
 *
 * The list shown when a filter opens comes from the last 90 days (so MariaDB
 * reads only those partitions) and is cached for five minutes per viewer.
 * Typing searches every stored value, 50 results at most.
 */
class AuditLogFilterOptions
{
    public const RECENT_DAYS = 90;

    public const LIMIT = 50;

    /** @return array<string, string> */
    public static function events(?string $search = null): array
    {
        $events = self::distinct('event', $search, 200);

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

    /** @return array<string, string> */
    public static function actions(?string $search = null): array
    {
        $options = [];
        foreach (self::distinct('action', $search) as $action) {
            $options[$action] = self::actionLabel($action);
        }

        return $options;
    }

    public static function actionLabel(string $action): string
    {
        $arabic = AuditLogsTable::ACTION_LABELS[$action] ?? null;

        return $arabic === null ? $action : "{$arabic} ({$action})";
    }

    /** @return array<string, string> actor_id => "name (type #id)" */
    public static function actors(?string $search = null): array
    {
        return self::cached('actor_id', $search, fn () => self::base($search === null)
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

    /** @return array<string, string> */
    public static function values(string $column, ?string $search = null): array
    {
        $values = self::distinct($column, $search);

        return array_combine($values, $values);
    }

    /** @return array<string, string> */
    public static function tags(?string $search = null): array
    {
        return self::cached('tags', $search, function () use ($search) {
            $tags = self::base($search === null)
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

    /** @return list<string> */
    private static function distinct(string $column, ?string $search, int $limit = self::LIMIT): array
    {
        return self::cached($column, $search, fn () => self::base($search === null)
            ->whereNotNull($column)
            ->when($search !== null, fn (Builder $q) => $q->where($column, 'like', self::like((string) $search)))
            ->distinct()
            ->orderBy($column)
            ->limit($limit)
            ->pluck($column)
            ->map(fn ($v) => (string) $v)
            ->all());
    }

    /** @return Builder<AuditLog> */
    private static function base(bool $recentOnly): Builder
    {
        return AuditLog::query()
            ->when($recentOnly, fn (Builder $q) => $q->where('occurred_at', '>=', now()->subDays(self::RECENT_DAYS)->startOfDay()))
            ->reorder();
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $load
     * @return T
     */
    private static function cached(string $column, ?string $search, Closure $load): mixed
    {
        // Searches are not cached: they change with every key press.
        if ($search !== null) {
            return $load();
        }

        return Cache::remember('audit-filter:'.$column.':'.(auth()->id() ?? 'guest'), 300, $load);
    }

    private static function like(string $search): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search).'%';
    }
}
