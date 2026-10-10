<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Runner;

use Antevemus\ASpecification\StateMachine\Persistence\DueDeadline;
use Antevemus\ASpecification\StateMachine\TransitionResult;

/**
 * DeadlineDelivery - Outcome of Delivering One Due Deadline
 *
 * MachineRunner::deliverDue() reports one entry per due deadline found (forward 023, D18): the
 * result of the deadline event sent through the runner (accepted, or rejected/unhandled when the
 * instance moved on: harmless), or the reason it was skipped (instance gone, state left since
 * the search, concurrency conflict, synchronous effect veto) for the next run to retry.
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Runner
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class DeadlineDelivery
{
    /**
     * @param DueDeadline $deadline Deadline found
     * @param TransitionResult|null $result Result of the deadline event, when it was sent
     * @param string|null $skipped Why it was not sent (or why sending failed), when it was not
     */
    public function __construct(
        public readonly DueDeadline $deadline,
        public readonly ?TransitionResult $result,
        public readonly ?string $skipped = null
    ) {
    }

    /** True when the deadline event was sent and accepted. */
    public function isDelivered(): bool
    {
        return $this->result?->isAccepted() ?? false;
    }

    /** True when the deadline was not sent. */
    public function isSkipped(): bool
    {
        return $this->skipped !== null;
    }
}
