<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Exceptions;

/**
 * UnknownDeadlineCalendarException - A Deadline Names a Calendar That Is Not Registered
 *
 * Occurs when a deadline declares a calendar name that the DeadlineCalendarRegistry of the core
 * does not know. The validator reports the same condition as a definition error
 * (`unknown_calendar`) when it receives the registry, which the runner always gives it: reaching
 * this exception means the definition was interpreted without being validated against the
 * calendars in use.
 *
 * @version    2.7.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class UnknownDeadlineCalendarException extends StateMachineException
{
    /**
     * @param string $calendarName Name of the calendar referenced by the deadline
     */
    public function __construct(public readonly string $calendarName)
    {
        parent::__construct(sprintf(
            'No deadline calendar registered under "%s". Register it in the DeadlineCalendarRegistry or validate the definition at load time.',
            $calendarName
        ));
    }
}
