<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Exceptions;

/**
 * UnknownGuardException - Exception Thrown on a Guard Name Without Registered Factory
 *
 * Occurs when a guard expression references a named guard that the GuardRegistry does not know.
 * The validator reports the same condition as a definition error at load time (forward 023, D4):
 * reaching this exception at evaluation time means the definition was interpreted without being
 * validated against the registry in use.
 *
 * Features:
 * - Explicit identification of the unknown guard name
 * - Actionable resolution hint (register the factory or validate the definition at load)
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class UnknownGuardException extends StateMachineException
{
    /**
     * @param string $guardName Name of the guard referenced by the definition
     */
    public function __construct(public readonly string $guardName)
    {
        parent::__construct(sprintf(
            'No guard factory registered for guard "%s". Register it in the GuardRegistry or validate the definition at load time.',
            $guardName
        ));
    }
}
