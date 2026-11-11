<?php

namespace App\Exceptions;

use LogicException;

/**
 * Thrown by the application before a write that the database triggers would
 * reject anyway, so the mistake is caught with a clear message.
 */
class AppendOnlyViolation extends LogicException
{
    public static function update(string $model): self
    {
        return new self("{$model} records are append-only and cannot be updated.");
    }

    public static function delete(string $model): self
    {
        return new self("{$model} records are append-only and cannot be deleted through the application.");
    }
}
