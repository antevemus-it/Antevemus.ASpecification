<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Calendar;

use DateTimeZone;
use InvalidArgumentException;

/**
 * BusinessDayCalendar - Whole Business Days
 *
 * A business day counts whole, from local midnight to the next local midnight; a weekend day
 * (Saturday and Sunday by default) or a holiday does not count at all. One day of a deadline is
 * 86 400 working seconds, so `P2D` entered on a Friday afternoon runs out on the Tuesday at the
 * same time, and the same deadline entered on a Saturday runs out on the Wednesday at midnight
 * (Monday and Tuesday counted whole). Midnights are built in the calendar's time zone, so a date
 * with a daylight-saving change has 23 or 25 working hours, as the clock on the wall.
 *
 * @version    2.7.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Calendar
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class BusinessDayCalendar extends AbstractWindowCalendar
{
    /** @var array<int, true> ISO-8601 weekdays off */
    private readonly array $weekend;

    /**
     * @param DateTimeZone $timezone Time zone of the business days
     * @param HolidaySet $holidays Holidays (no business day)
     * @param list<int> $weekend ISO-8601 weekdays off (6 = Saturday, 7 = Sunday)
     * @param int $maxDays Limit of local dates walked (advance() returns null beyond it)
     * @throws InvalidArgumentException When a weekday is not 1 to 7 or the limit is below 1
     */
    public function __construct(
        DateTimeZone $timezone,
        HolidaySet $holidays,
        array $weekend = [6, 7],
        int $maxDays = self::DEFAULT_MAX_DAYS
    ) {
        parent::__construct($timezone, $holidays, $maxDays);
        $this->weekend = self::weekdays($weekend, 'weekend days');
    }

    public function secondsPerDay(): int
    {
        return CalendarTime::DAY;
    }

    protected function window(string $date): ?array
    {
        if (isset($this->weekend[CalendarTime::weekday($date)]) || $this->holidays->isHoliday($date)) {
            return null;
        }

        return [CalendarTime::localMicros($date, 0, $this->timezone), CalendarTime::localMicros($date, CalendarTime::DAY, $this->timezone)];
    }
}
