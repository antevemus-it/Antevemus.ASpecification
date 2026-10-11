<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\DomainEvents;

use Antevemus\ASpecification\DomainEvents\Outbox\DomainEventOutboxEntry;

/**
 * DomainEventDispatchReport - Outcome of One DomainEventDispatcher::dispatchPending() Run
 *
 * The entries delivered and the entries whose attempt failed with the recorded error message
 * (never a stack trace), by idempotency key (forward 029, D5).
 *
 * @version    1.11.0
 * @package    Antevemus\ASpecification
 * @subpackage DomainEvents
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class DomainEventDispatchReport
{
    /**
     * @param list<DomainEventOutboxEntry> $delivered Entries delivered, in delivery order
     * @param array<string, string> $failures Error message by idempotency key, in attempt order
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
     * @return list<DomainEventOutboxEntry>
     */
    public function getDelivered(): array
    {
        return $this->delivered;
    }

    /**
     * Error message by idempotency key.
     *
     * @return array<string, string>
     */
    public function getFailures(): array
    {
        return $this->failures;
    }
}
