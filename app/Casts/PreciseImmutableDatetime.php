<?php

namespace App\Casts;

use App\Support\Time\PreciseDateTime;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Same as the "immutable_datetime" cast, but the value is a PreciseDateTime
 * (millisecond string form), so cursor pagination stays exact on DATETIME(3).
 *
 * @implements CastsAttributes<PreciseDateTime, mixed>
 */
class PreciseImmutableDatetime implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?PreciseDateTime
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof DateTimeInterface
            ? PreciseDateTime::instance($value)
            : PreciseDateTime::parse((string) $value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $model->fromDateTime($value);
    }
}
