<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Calendar;

use DateTimeImmutable;
use DateTimeZone;

/**
 * CalendarTime - Instant and Local-Date Arithmetic Shared by the Deadline Calendars
 *
 * Instants are compared and added as integer microseconds since the epoch (never floats); local
 * dates are walked as `Y-m-d` texts in UTC, so a daylight-saving change never skips or
 * repeats a day; a local wall-clock time is built in the calendar's time zone (`2026-11-01 00:00`
 * in America/Sao_Paulo), never by adding 86 400 seconds to the previous midnight.
 *
 * @internal Not part of the public API.
 *
 * @version    2.7.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Calendar
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class CalendarTime
{
    /** Microseconds per second. */
    public const MICROS = 1_000_000;

    /** Seconds of a whole day of wall-clock time (24:00). */
    public const DAY = 86400;

    private function __construct()
    {
    }

    /** Microseconds since the epoch. */
    public static function micros(DateTimeImmutable $instant): int
    {
        return $instant->getTimestamp() * self::MICROS + (int) $instant->format('u');
    }

    /** The instant of $micros microseconds since the epoch, in the time zone of $zoneOf. */
    public static function fromMicros(int $micros, DateTimeImmutable $zoneOf): DateTimeImmutable
    {
        $seconds = intdiv($micros, self::MICROS);
        $fraction = $micros % self::MICROS;
        if ($fraction < 0) {
            $fraction += self::MICROS;
            $seconds--;
        }
        $instant = DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', $seconds, $fraction));
        \assert($instant instanceof DateTimeImmutable);

        return $instant->setTimezone($zoneOf->getTimezone());
    }

    /** The local date (`Y-m-d`) of an instant in a time zone. */
    public static function localDate(DateTimeImmutable $instant, DateTimeZone $timezone): string
    {
        return $instant->setTimezone($timezone)->format('Y-m-d');
    }

    /** The date after a `Y-m-d` date. */
    public static function nextDate(string $date): string
    {
        return self::utcDate($date)->modify('+1 day')->format('Y-m-d');
    }

    /** The ISO-8601 weekday of a `Y-m-d` date (1 = Monday, 7 = Sunday). */
    public static function weekday(string $date): int
    {
        return (int) self::utcDate($date)->format('N');
    }

    /**
     * Microseconds since the epoch of the local wall-clock time $secondsOfDay (0 to 86 400) of a
     * date in a time zone; 86 400 (`24:00:00`, which PHP reads as the next midnight) is the
     * midnight that starts the next date. A wall-clock time skipped by a daylight-saving change
     * moves to the first valid time after it, as PHP does.
     */
    public static function localMicros(string $date, int $secondsOfDay, DateTimeZone $timezone): int
    {
        $time = sprintf('%s %02d:%02d:%02d', $date, intdiv($secondsOfDay, 3600), intdiv($secondsOfDay % 3600, 60), $secondsOfDay % 60);

        return self::micros(new DateTimeImmutable($time, $timezone));
    }

    private static function utcDate(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date, new DateTimeZone('UTC'));
    }
}
