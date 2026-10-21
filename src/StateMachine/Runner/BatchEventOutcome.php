<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Runner;

use Antevemus\ASpecification\StateMachine\TransitionResult;
use Throwable;

/**
 * BatchEventOutcome - What MachineRunner::sendMany() Did With One Event
 *
 * One per event, in the order given (2.10.0): the TransitionResult send() would have returned
 * (accepted, rejected, unhandled, or the original result of a repeated idempotency key), or the
 * exception send() would have thrown for that event alone: the instance does not exist (or belongs to
 * another tenant), its version moved (ConcurrencyConflictException), or a synchronous effect vetoed it
 * (SyncEffectFailedException). Nothing of a failed event is written.
 *
 * @version    2.10.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Runner
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class BatchEventOutcome
{
    /**
     * @param int $index Position of the event in the batch (0-based)
     * @param BatchEvent $request The event as given
     * @param TransitionResult|null $result Result, when the event was processed
     * @param Throwable|null $error Why it was not, when it was not
     */
    public function __construct(
        public readonly int $index,
        public readonly BatchEvent $request,
        public readonly ?TransitionResult $result,
        public readonly ?Throwable $error = null
    ) {
    }

    /** True when the event was processed (whatever its status). */
    public function isProcessed(): bool
    {
        return $this->result !== null;
    }

    /** True when the event was processed and accepted. */
    public function isAccepted(): bool
    {
        return $this->result?->isAccepted() ?? false;
    }

    /** Message of the error, or null when the event was processed. */
    public function errorMessage(): ?string
    {
        return $this->error?->getMessage();
    }
}
