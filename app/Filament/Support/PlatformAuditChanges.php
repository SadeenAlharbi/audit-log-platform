<?php

namespace App\Filament\Support;

use App\Listeners\RecordPermissionChanges;
use App\Support\Time\DisplayTime;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Turns platform_audit.changes into something readable on the detail page.
 * The column holds different shapes depending on the action:
 *
 *  - config.baseline / config.changed: {hash, changed, previous, values}
 *  - *.updated: {field: {old, new}, ...}
 *  - api_key.allowed_ips_updated: {old, new}
 *  - anything else: plain key => value details
 */
final class PlatformAuditChanges
{
    /**
     * Before / after rows for the diff view, or null when the record has no
     * before / after (plain details, or a configuration baseline).
     *
     * @param  array<mixed>|null  $changes
     * @return list<array{key: string, old: string|null, new: string|null, status: string}>|null
     */
    public static function diffRows(string $action, ?array $changes): ?array
    {
        if ($changes === null || $changes === []) {
            return null;
        }

        if (self::isConfig($action, $changes)) {
            /** @var array<string, mixed> $values */
            $values = $changes['values'];
            $changed = is_array($changes['changed'] ?? null) ? array_map('strval', $changes['changed']) : [];
            if ($action === 'config.baseline' || $changed === []) {
                return null;
            }
            $previous = is_array($changes['previous'] ?? null) ? $changes['previous'] : [];
            $keys = array_flip($changed);

            return ValueDiff::rows(array_intersect_key($previous, $keys), array_intersect_key($values, $keys));
        }

        if (array_keys($changes) === ['old', 'new']) {
            $key = $action === 'api_key.allowed_ips_updated' ? 'allowed_ips' : 'value';

            return self::labelled(ValueDiff::rows([$key => $changes['old']], [$key => $changes['new']]));
        }

        if (self::isFieldDiff($changes)) {
            $old = $new = [];
            foreach ($changes as $field => $pair) {
                $old[$field] = $pair['old'];
                $new[$field] = $pair['new'];
            }

            return self::labelled(array_values(array_filter(ValueDiff::rows($old, $new), fn (array $row): bool => $row['status'] !== 'same')));
        }

        return null;
    }

    /**
     * Key => value lines for records without a before / after: the details of
     * an action, or the full settings of a configuration record.
     *
     * @param  array<mixed>|null  $changes
     * @return array<string, string>
     */
    public static function details(string $action, ?array $changes): array
    {
        if ($changes === null || $changes === []) {
            return [];
        }

        if (self::isConfig($action, $changes)) {
            /** @var array<string, mixed> $values */
            $values = $changes['values'];
            ksort($values);

            return array_map(self::scalar(...), $values);
        }

        if (preg_match('/^(role|permission)\.(assigned|removed|granted|revoked)$/', $action, $m) === 1 && is_array($changes['items'] ?? null)) {
            $isRole = $m[1] === 'role';
            // Older records may hold ids; resolve them like the listener does now.
            $names = RecordPermissionChanges::names($changes['items'], $isRole ? Role::class : Permission::class);
            $labels = array_map(fn ($name): string => match (true) {
                ! is_string($name) => (string) $name,
                $isRole => Labels::role($name).' ('.$name.')',
                default => Labels::permission($name).' ('.$name.')',
            }, $names);

            return [$isRole ? 'الأدوار' : 'الصلاحيات' => implode('، ', $labels)];
        }

        $out = [];
        foreach (self::flatten($changes) as $key => $value) {
            $out[Labels::field($key)] = self::friendly(self::scalar($value));
        }

        return $out;
    }

    /**
     * Arabic names for the platform's own fields (settings keep their keys).
     *
     * @param  list<array{key: string, old: string|null, new: string|null, status: string}>  $rows
     * @return list<array{key: string, old: string|null, new: string|null, status: string}>
     */
    private static function labelled(array $rows): array
    {
        return array_map(fn (array $row): array => [
            'key' => Labels::field($row['key']),
            'old' => $row['old'] === null ? null : self::friendly($row['old']),
            'new' => $row['new'] === null ? null : self::friendly($row['new']),
        ] + $row, $rows);
    }

    /** @param array<mixed> $changes */
    public static function isConfig(string $action, array $changes): bool
    {
        return str_starts_with($action, 'config.') && is_array($changes['values'] ?? null);
    }

    /** @param array<mixed> $changes */
    private static function isFieldDiff(array $changes): bool
    {
        foreach ($changes as $pair) {
            if (! is_array($pair) || array_keys($pair) !== ['old', 'new']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<mixed>  $data
     * @return array<string, mixed>
     */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $out += self::flatten($value, $path);
            } else {
                $out[$path] = $value;
            }
        }

        return $out;
    }

    /** true / false / null and ISO times as the dashboard shows them (settings keep raw values). */
    private static function friendly(string $value): string
    {
        return match (true) {
            $value === 'true' => 'نعم',
            $value === 'false' => 'لا',
            $value === 'null', $value === '' => '—',
            preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $value) === 1 => DisplayTime::local($value)->format('Y-m-d H:i'),
            default => $value,
        };
    }

    private static function scalar(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            is_int($value), is_float($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }
}
