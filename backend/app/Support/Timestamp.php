<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeInterface;

/**
 * How every timestamp is stored and sent: RFC 3339 in UTC, to the second
 * (`2026-11-07T09:18:09Z`). Text in this one form sorts in time order, which
 * the listings' ORDER BY and cursors rely on; each timestamp column's CHECK
 * refuses any other form.
 */
final class Timestamp
{
    public const string FORMAT = 'Y-m-d\TH:i:s\Z';

    /** A Unix time or a date, in the stored form. */
    public static function of(int|DateTimeInterface $time): string
    {
        return gmdate(self::FORMAT, $time instanceof DateTimeInterface ? $time->getTimestamp() : $time);
    }

    public static function now(): string
    {
        return self::of(now());
    }
}
