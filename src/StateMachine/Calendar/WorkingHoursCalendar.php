<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Calendar;

use DateTimeZone;
use InvalidArgumentException;

/**
 * WorkingHoursCalendar - A Team's Working Days and Hours
 *
 * Counts only the seconds between $start and $end (local wall-clock times, `HH:MM` or
 * `HH:MM:SS`, `24:00` for the end of the date) of the working weekdays, and none on a holiday.
 * One day of a deadline is one working window: with 08:00 to 18:00, `P2D` is 72 000 working
 * seconds (two days of ten hours) and `PT2H` is 7 200 in any calendar. A deadline entered at
 * 17:00 on a Friday with `PT2H` runs out at 09:00 on the Monday.
 *
 * @version    2.7.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Calendar
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class WorkingHoursCalendar extends AbstractWindowCalendar
{
    /** @var array<int, true> ISO-8601 working weekdays */
    private readonly array $days;

    /** Start of the window, seconds since local midnight. */
    private readonly int $start;

    /** End of the window, seconds since local midnight (86 400 = the next midnight). */
    private readonly int $end;

    /**
     * @param DateTimeZone $timezone Time zone of the working hours
     * @param list<int> $days ISO-8601 working weekdays (1 = Monday to 7 = Sunday), at least one
     * @param string $start Start of the window (`08:00`)
     * @param string $end End of the window (`18:00`), after the start
     * @param HolidaySet $holidays Holidays (no window)
     * @param int $maxDays Limit of local dates walked (advance() returns null beyond it)
     * @throws InvalidArgumentException When the days, the times or the limit are invalid
     */
    public function __construct(
        DateTimeZone $timezone,
        array $days,
        string $start,
        string $end,
        HolidaySet $holidays,
        int $maxDays = self::DEFAULT_MAX_DAYS
    ) {
        parent::__construct($timezone, $holidays, $maxDays);
        $this->days = self::weekdays($days, 'working days');
        if ($this->days === []) {
            throw new InvalidArgumentException('A working-hours calendar needs at least one working day.');
        }
        $this->start = self::secondsOf($start, 'start');
        $this->end = self::secondsOf($end, 'end');
        if ($this->end <= $this->start) {
            throw new InvalidArgumentException(sprintf('The working window must end after it starts (%s to %s).', $start, $end));
        }
    }

    public function secondsPerDay(): int
    {
        return $this->end - $this->start;
    }

    protected function window(string $date): ?array
    {
        if (!isset($this->days[CalendarTime::weekday($date)]) || $this->holidays->isHoliday($date)) {
            return null;
        }

        return [CalendarTime::localMicros($date, $this->start, $this->timezone), CalendarTime::localMicros($date, $this->end, $this->timezone)];
    }

    /**
     * `HH:MM` or `HH:MM:SS` as seconds since midnight; `24:00` (or `24:00:00`) is the end of the date.
     *
     * @throws InvalidArgumentException
     */
    private static function secondsOf(string $time, string $what): int
    {
        if (preg_match('/^(?:(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?|24:00(?::00)?)$/', $time) !== 1) {
            throw new InvalidArgumentException(sprintf('The %s of the working window must be HH:MM or HH:MM:SS, from 00:00 to 24:00 (got "%s").', $what, $time));
        }
        $parts = array_map('intval', explode(':', $time));

        return $parts[0] * 3600 + $parts[1] * 60 + ($parts[2] ?? 0);
    }
}
