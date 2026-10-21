<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * AbstractWindowCalendar - A Calendar Made of One Working Window per Local Date
 *
 * Walks the local dates of its time zone, from the date of the start instant on, and counts only
 * the seconds inside each date's working window (none on a weekend day or a holiday). advance()
 * spends the balance window after window and returns the instant it runs out (the end of a
 * window when the balance is exactly what that window had left); it gives up after maxDays dates
 * and returns null. workSecondsBetween() adds the seconds of every window between the two
 * instants, over at most maxDays dates.
 *
 * @internal The two calendars of the library extend it; it is not an extension point.
 *
 * @version    2.7.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Calendar
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class AbstractWindowCalendar implements IDeadlineCalendar
{
    /** Default limit of local dates walked (about ten years). */
    public const DEFAULT_MAX_DAYS = 3700;

    /**
     * @param DateTimeZone $timezone Time zone of the local dates and windows
     * @param HolidaySet $holidays Dates without a window
     * @param int $maxDays Limit of local dates walked, at least 1
     * @throws InvalidArgumentException When $maxDays is below 1
     */
    protected function __construct(
        protected readonly DateTimeZone $timezone,
        protected readonly HolidaySet $holidays,
        protected readonly int $maxDays
    ) {
        if ($maxDays < 1) {
            throw new InvalidArgumentException(sprintf('The limit of days of a calendar must be at least 1 (got %d).', $maxDays));
        }
    }

    /**
     * The working window of a local date, as microseconds since the epoch, or null when the whole
     * date is off.
     *
     * @return array{0: int, 1: int}|null
     */
    abstract protected function window(string $date): ?array;

    public function advance(DateTimeImmutable $from, int $workSeconds): ?DateTimeImmutable
    {
        if ($workSeconds < 0) {
            throw new InvalidArgumentException(sprintf('The balance of working seconds must not be negative (got %d).', $workSeconds));
        }
        if ($workSeconds === 0) {
            return $from;
        }
        $start = CalendarTime::micros($from);
        $balance = $workSeconds * CalendarTime::MICROS;
        $date = CalendarTime::localDate($from, $this->timezone);
        for ($i = 0; $i < $this->maxDays; $i++) {
            $window = $this->window($date);
            if ($window !== null) {
                $begin = max($start, $window[0]);
                $available = max(0, $window[1] - $begin);
                if ($balance <= $available) {
                    return CalendarTime::fromMicros($begin + $balance, $from);
                }
                $balance -= $available;
            }
            $date = CalendarTime::nextDate($date);
        }

        return null;
    }

    public function workSecondsBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $start = CalendarTime::micros($from);
        $end = CalendarTime::micros($to);
        $total = 0;
        $date = CalendarTime::localDate($from, $this->timezone);
        for ($i = 0; $i < $this->maxDays && CalendarTime::localMicros($date, 0, $this->timezone) < $end; $i++) {
            $window = $this->window($date);
            if ($window !== null) {
                $total += max(0, min($end, $window[1]) - max($start, $window[0]));
            }
            $date = CalendarTime::nextDate($date);
        }

        return intdiv($total, CalendarTime::MICROS);
    }

    /**
     * Validates a list of ISO-8601 weekdays (1 = Monday to 7 = Sunday) and returns them as a set.
     *
     * @param array<mixed> $days
     * @return array<int, true>
     * @throws InvalidArgumentException
     */
    protected static function weekdays(array $days, string $what): array
    {
        foreach ($days as $day) {
            if (!is_int($day) || $day < 1 || $day > 7) {
                throw new InvalidArgumentException(sprintf('The %s must be ISO-8601 weekdays, 1 (Monday) to 7 (Sunday).', $what));
            }
        }

        return array_fill_keys($days, true);
    }
}
