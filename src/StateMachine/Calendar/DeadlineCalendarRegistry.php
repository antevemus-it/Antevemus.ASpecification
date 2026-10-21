<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Calendar;

use Antevemus\ASpecification\StateMachine\DefinitionSupport;
use Antevemus\ASpecification\StateMachine\Exceptions\StateMachineException;
use Antevemus\ASpecification\StateMachine\Exceptions\UnknownDeadlineCalendarException;
use Closure;

/**
 * DeadlineCalendarRegistry - Named Calendars of the Deadlines
 *
 * The definition stays data: a deadline names its calendar (`"calendar": "business"`) and the
 * application maps the name to a calendar here, as it maps guard names to specifications in the
 * GuardRegistry. A name maps either to one calendar (the same for every instance) or to a factory
 * `Closure(object|array|null $context): IDeadlineCalendar` that receives the context of the call,
 * for calendars that depend on the instance (the working hours of the team in charge, the
 * holidays of the city of the contract).
 *
 * Contract of a factory: for the same instance it must return the same calendar every time it is
 * asked (on entering the state, on pausing, on resuming), because a paused deadline keeps its
 * balance in working seconds of that calendar. The core asks for a calendar only when it needs
 * one, and never caches it between calls. A factory that throws rejects the step.
 *
 * The registry starts empty: the library ships no calendar under any name.
 *
 * @version    2.7.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Calendar
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class DeadlineCalendarRegistry
{
    /** @var array<string, IDeadlineCalendar|Closure(object|array<mixed>|null): IDeadlineCalendar> */
    private array $calendars = [];

    /**
     * Registers (or replaces) a calendar under a name.
     *
     * @param string $name Calendar name, as deadlines declare it
     * @param IDeadlineCalendar|Closure(object|array<mixed>|null): IDeadlineCalendar $calendar The calendar, or its factory
     * @return self
     * @throws \Antevemus\ASpecification\StateMachine\Exceptions\InvalidMachineDefinitionException When the name is not a valid name
     */
    public function register(string $name, IDeadlineCalendar|Closure $calendar): self
    {
        $this->calendars[DefinitionSupport::assertName($name, 'calendar name')] = $calendar;

        return $this;
    }

    /** True when a calendar is registered under the name. */
    public function has(string $name): bool
    {
        return isset($this->calendars[$name]);
    }

    /**
     * Registered calendar names, in registration order.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->calendars);
    }

    /**
     * The calendar registered under the name, for the given context.
     *
     * @param string $name Calendar name
     * @param object|array<mixed>|null $context Context of the call (given to a factory)
     * @return IDeadlineCalendar
     * @throws UnknownDeadlineCalendarException When no calendar is registered under the name
     * @throws StateMachineException When a factory does not return an IDeadlineCalendar
     */
    public function resolve(string $name, object|array|null $context = null): IDeadlineCalendar
    {
        if (!isset($this->calendars[$name])) {
            throw new UnknownDeadlineCalendarException($name);
        }
        $calendar = $this->calendars[$name];
        if ($calendar instanceof IDeadlineCalendar) {
            return $calendar;
        }
        $built = $calendar($context);
        if (!$built instanceof IDeadlineCalendar) {
            throw new StateMachineException(sprintf(
                'The factory of calendar "%s" must return an IDeadlineCalendar (got %s).',
                $name,
                get_debug_type($built)
            ));
        }

        return $built;
    }
}
