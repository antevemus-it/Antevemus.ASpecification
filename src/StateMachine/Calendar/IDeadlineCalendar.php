<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Calendar;

use DateTimeImmutable;

/**
 * IDeadlineCalendar - The Time a Deadline Counts
 *
 * A deadline without a calendar counts elapsed time, as it always did. A deadline that names a
 * calendar (`calendar: 'business'`) counts **working seconds** of that calendar: the seconds that
 * fall inside its working windows (a whole business day, a team's 08:00 to 18:00), skipping
 * weekends and holidays. The port is the pair every business-time computation needs, not just
 * "add N business days": the instant at which a balance of working seconds runs out, and the
 * working seconds between two instants (the balance kept by a paused deadline). "One day" of a
 * calendar is secondsPerDay() working seconds, so `P2D` is two of them and `PT2H` is 7 200
 * working seconds in any calendar.
 *
 * Contract of every implementation (checked by the property tests of the library):
 * - pure: no clock, no database, no network; the same arguments always give the same answer;
 * - `advance($a, 0)` is `$a`; `advance()` is monotonic in its balance and never lands on a second
 *   outside a working window (other than `$a` itself for a zero balance);
 * - `workSecondsBetween($a, $b)` is 0 when `$b <= $a`, monotonic in `$b`, never counts a second
 *   outside a working window, and is floored to whole seconds;
 * - `workSecondsBetween($a, advance($a, $s)) === $s` whenever advance() returns an instant;
 * - a calendar may refuse to look further than a limit of days and then answers null from
 *   advance(); a negative balance is a programming error (InvalidArgumentException).
 *
 * Which holidays apply (state, city, team) is decided by WHICH calendar the application builds
 * for an instance (DeadlineCalendarRegistry), never by a parameter of this port.
 *
 * @version    2.7.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Calendar
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IDeadlineCalendar
{
    /**
     * The instant at which $workSeconds working seconds counted from $from run out.
     *
     * @param DateTimeImmutable $from Instant the count starts at (inside a window or not)
     * @param int $workSeconds Balance of working seconds, zero or positive
     * @return DateTimeImmutable|null The instant, in the time zone of $from; null when it lies beyond the calendar's limit
     * @throws \InvalidArgumentException When $workSeconds is negative
     */
    public function advance(DateTimeImmutable $from, int $workSeconds): ?DateTimeImmutable;

    /**
     * The whole working seconds between two instants (0 when $to is not after $from).
     *
     * @param DateTimeImmutable $from Start of the interval
     * @param DateTimeImmutable $to End of the interval
     * @return int Working seconds, floored
     */
    public function workSecondsBetween(DateTimeImmutable $from, DateTimeImmutable $to): int;

    /**
     * How many working seconds "one day" of a deadline is worth in this calendar (86 400 for a
     * whole business day, the length of the window for working hours).
     */
    public function secondsPerDay(): int;
}
