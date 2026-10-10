<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Runner;

use Antevemus\ASpecification\StateMachine\Persistence\OutboxEntry;

/**
 * DispatchReport - Outcome of One EffectDispatcher::dispatchPending() Run
 *
 * The entries delivered and the entries whose attempt failed with the recorded error message
 * (never a stack trace), by effect idempotency key (forward 023, RN-10).
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Runner
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class DispatchReport
{
    /**
     * @param list<OutboxEntry> $delivered Entries delivered, in delivery order
     * @param array<string, string> $failures Error message by effect key, in attempt order
     */
    public function __construct(
        private readonly array $delivered = [],
        private readonly array $failures = []
    ) {
    }

    /** Number of entries delivered. */
    public function deliveredCount(): int
    {
        return count($this->delivered);
    }

    /** Number of attempts that failed. */
    public function failedCount(): int
    {
        return count($this->failures);
    }

    /**
     * Entries delivered, in delivery order.
     *
     * @return list<OutboxEntry>
     */
    public function getDelivered(): array
    {
        return $this->delivered;
    }

    /**
     * Error message by effect key.
     *
     * @return array<string, string>
     */
    public function getFailures(): array
    {
        return $this->failures;
    }
}
