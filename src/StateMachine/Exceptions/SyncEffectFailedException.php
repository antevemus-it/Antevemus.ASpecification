<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Exceptions;

use Antevemus\ASpecification\StateMachine\Effect;
use Throwable;

/**
 * SyncEffectFailedException - A Synchronous Effect Vetoed the Transition
 *
 * A synchronous effect (registered with EffectHandlerRegistry::registerSync()) runs inside the
 * runner's transaction, before the commit (forward 023, D12). When its handler throws, the whole
 * transition is rolled back (snapshot, history, outbox, idempotency record) and this exception
 * reaches the caller with the handler's exception as `previous`. It is the only way an action can
 * veto a transition, and it has to be declared.
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class SyncEffectFailedException extends StateMachineException
{
    /**
     * @param string $instanceId Instance being transitioned
     * @param Effect $effect Synchronous effect whose handler threw
     * @param Throwable $previous Exception thrown by the handler
     */
    public function __construct(
        private readonly string $instanceId,
        private readonly Effect $effect,
        Throwable $previous
    ) {
        parent::__construct(sprintf(
            'Synchronous effect "%s" of machine instance "%s" failed; the transition was rolled back: %s',
            $effect->action,
            $instanceId,
            $previous->getMessage()
        ), 0, $previous);
    }

    /** Instance being transitioned. */
    public function getInstanceId(): string
    {
        return $this->instanceId;
    }

    /** Synchronous effect whose handler threw. */
    public function getEffect(): Effect
    {
        return $this->effect;
    }
}
