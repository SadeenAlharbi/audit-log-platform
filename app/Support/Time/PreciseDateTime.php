<?php

namespace App\Support\Time;

use Carbon\CarbonImmutable;

/**
 * A CarbonImmutable whose string form keeps the milliseconds.
 *
 * Laravel builds the API cursor (cursorPaginate) from (string) $model->occurred_at.
 * Plain Carbon prints "Y-m-d H:i:s", so a cursor taken from a log at
 * 10:00:00.900 compared as 10:00:00.000 and the next page skipped every log
 * of that same second that had not been shown yet.
 */
class PreciseDateTime extends CarbonImmutable
{
    public const STRING_FORMAT = 'Y-m-d H:i:s.v';

    public function __toString(): string
    {
        return $this->format(self::STRING_FORMAT);
    }
}
