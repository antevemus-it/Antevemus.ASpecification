<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Calendar;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * ContinuousCalendar - Elapsed Time, Every Second Counts
 *
 * The calendar of a deadline that never stops: 24 hours a day, 7 days a week. Its arithmetic is
 * the one the core uses for a deadline without calendar (the balance of a pause is the whole
 * seconds left, floored and never negative; resuming adds them to the instant of the step, in its
 * time zone), so a pause under this calendar keeps and restores exactly what a pause without
 * calendar does. One day is 86 400 seconds: a duration of `P1D` under this calendar is 24 elapsed
 * hours, also across a daylight-saving change, while a deadline without calendar adds one
 * wall-clock day (23 or 25 hours on those two days of the year).
 *
 * @version    2.7.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Calendar
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class ContinuousCalendar implements IDeadlineCalendar
{
    /** Seconds of one day. */
    public const SECONDS_PER_DAY = 86400;

    /**
     * {@inheritDoc}
     *
     * Never null: elapsed time has no limit.
     */
    public function advance(DateTimeImmutable $from, int $workSeconds): ?DateTimeImmutable
    {
        if ($workSeconds < 0) {
            throw new InvalidArgumentException(sprintf('The balance of working seconds must not be negative (got %d).', $workSeconds));
        }

        return CalendarTime::fromMicros(CalendarTime::micros($from) + $workSeconds * CalendarTime::MICROS, $from);
    }

    public function workSecondsBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return max(0, intdiv(CalendarTime::micros($to) - CalendarTime::micros($from), CalendarTime::MICROS));
    }

    public function secondsPerDay(): int
    {
        return self::SECONDS_PER_DAY;
    }
}
