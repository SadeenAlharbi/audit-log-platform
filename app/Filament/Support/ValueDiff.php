<?php

namespace App\Filament\Support;

/**
 * Flattens old_values / new_values into rows for the diff view:
 * key path, old value, new value and whether it changed.
 */
final class ValueDiff
{
    /**
     * @param  array<mixed>|null  $old
     * @param  array<mixed>|null  $new
     * @return list<array{key: string, old: string|null, new: string|null, status: string}>
     */
    public static function rows(?array $old, ?array $new): array
    {
        $old = self::flatten($old ?? []);
        $new = self::flatten($new ?? []);
        $keys = array_values(array_unique([...array_keys($old), ...array_keys($new)]));
        sort($keys);

        return array_map(function (string $key) use ($old, $new) {
            $hasOld = array_key_exists($key, $old);
            $hasNew = array_key_exists($key, $new);

            return [
                'key' => $key,
                'old' => $hasOld ? $old[$key] : null,
                'new' => $hasNew ? $new[$key] : null,
                'status' => match (true) {
                    ! $hasOld => 'added',
                    ! $hasNew => 'removed',
                    $old[$key] !== $new[$key] => 'changed',
                    default => 'same',
                },
            ];
        }, $keys);
    }

    /**
     * @param  array<mixed>  $data
     * @return array<string, string>
     */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $out += self::flatten($value, $path);
            } else {
                $out[$path] = match (true) {
                    is_string($value) => $value,
                    is_bool($value) => $value ? 'true' : 'false',
                    $value === null => 'null',
                    is_int($value), is_float($value) => (string) $value,
                    default => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                };
            }
        }

        return $out;
    }
}
