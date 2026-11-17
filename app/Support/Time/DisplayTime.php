<?php

namespace App\Support\Time;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The dashboard shows times in the users' time zone (audit.display_timezone,
 * Asia/Riyadh by default). Everything is still stored and sent over the API
 * in UTC; only the dashboard converts (Filament's default time zone is set
 * to this one in AppServiceProvider).
 */
final class DisplayTime
{
    public static function timezone(): string
    {
        return (string) config('audit.display_timezone', 'Asia/Riyadh');
    }

    /** Now, in the display time zone. */
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone());
    }

    /** Start of the local day $days ago, as UTC (for occurred_at comparisons). */
    public static function startOfDayUtc(int $daysAgo = 0): CarbonImmutable
    {
        return self::now()->subDays($daysAgo)->startOfDay()->utc();
    }

    /**
     * A local calendar day (Y-m-d) as a UTC range [start, end].
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function dayRangeUtc(DateTimeInterface|string $day): array
    {
        $start = CarbonImmutable::parse(is_string($day) ? $day : $day->format('Y-m-d'), self::timezone())->startOfDay();

        return [$start->utc(), $start->endOfDay()->utc()];
    }

    /**
     * A wall-clock value typed in the dashboard (a Filament date-time picker
     * keeps local time in its state) converted to UTC.
     */
    public static function toUtc(DateTimeInterface|string $local): CarbonImmutable
    {
        return $local instanceof DateTimeInterface
            ? CarbonImmutable::instance($local)->utc()
            : CarbonImmutable::parse($local, self::timezone())->utc();
    }

    /** A UTC value shown in the display time zone. */
    public static function local(DateTimeInterface|string $utc): CarbonImmutable
    {
        return CarbonImmutable::parse($utc, 'UTC')->setTimezone(self::timezone());
    }

    /** Current UTC offset of the display time zone, in minutes (SQL day grouping). */
    public static function offsetMinutes(): int
    {
        return (int) self::now()->utcOffset();
    }

    /** "UTC+03:00", for labels. */
    public static function offsetLabel(): string
    {
        return 'UTC'.self::now()->format('P');
    }
}
