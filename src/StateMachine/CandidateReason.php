<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine;

/**
 * CandidateReason - Why an Event Listed by nextEvents() Can or Cannot Be Sent
 *
 * The classification nextEvents() gives each listed event (2.10.0), so an action panel can tell
 * "you may do it, but give the data first" from "you may not do it" without reading failure codes:
 *
 * - ALLOWED: the event is accepted as it is.
 * - MISSING_PAYLOAD: the event is accepted once the payload provides the fields the `payloadHas`
 *   guards ask for (TransitionResult::getMissingPayload() lists them); the button stays and asks
 *   for the data.
 * - ROLE_REFUSED: a guard marked as a role guard (GuardRegistry::markRoleGuards()) refuses the
 *   caller in every candidate transition of a region: the event is not the caller's to send.
 * - GUARD_REFUSED: any other refusal (a guard of the domain, an internal-only transition, a
 *   deadline not due, a guard that could not be evaluated).
 *
 * @version    2.10.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
enum CandidateReason: string
{
    /** Accepted as it is. */
    case ALLOWED = 'allowed';

    /** Accepted once the payload provides the fields the `payloadHas` guards require. */
    case MISSING_PAYLOAD = 'missing_payload';

    /** Refused by a guard of the domain (or an implicit guard of the machine). */
    case GUARD_REFUSED = 'guard_refused';

    /** Refused by a role guard in every candidate of a region: not the caller's to send. */
    case ROLE_REFUSED = 'role_refused';
}
