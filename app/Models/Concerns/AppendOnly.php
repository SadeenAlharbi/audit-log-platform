<?php

namespace App\Models\Concerns;

use App\Exceptions\AppendOnlyViolation;

/**
 * Application-level guard that mirrors the database triggers.
 * The triggers remain the real protection (they also cover raw queries).
 */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(fn ($model) => throw AppendOnlyViolation::update(class_basename($model)));
        static::deleting(fn ($model) => throw AppendOnlyViolation::delete(class_basename($model)));
    }
}
