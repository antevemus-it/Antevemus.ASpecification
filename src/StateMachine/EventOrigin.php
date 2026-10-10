<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine;

/**
 * EventOrigin - Where an Event Comes From
 *
 * External events come from callers (users, APIs); internal events come from the machine's own
 * infrastructure (deadline expiry, jobs that report back through the runner). Transitions
 * declared `internal` and deadline events only accept internal events (forward 023, D19).
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
enum EventOrigin: string
{
    /** Sent by a caller from outside the machine. */
    case EXTERNAL = 'external';

    /** Sent by the machine's own infrastructure (deadline, runner). */
    case INTERNAL = 'internal';
}
