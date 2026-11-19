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
 * Since 2.16.0 (forward 065, D10), for a dispatcher with a lock: whether the run delivered nothing because
 * another dispatcher held the lock (isLockDenied()), and whether it stopped before a round because the
 * renewal of the lock failed (isLockLost()).
 *
 * @version    2.16.0
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
     * @param bool $lockDenied True when another dispatcher held the lock: nothing was delivered
     * @param bool $lockLost True when the run stopped because the renewal of the lock failed
     */
    public function __construct(
        private readonly array $delivered = [],
        private readonly array $failures = [],
        private readonly bool $lockDenied = false,
        private readonly bool $lockLost = false
    ) {
    }

    /** True when the dispatcher did not get its lock (another one holds it): the run delivered nothing (2.16.0). */
    public function isLockDenied(): bool
    {
        return $this->lockDenied;
    }

    /**
     * True when the run stopped before a round because the renewal of the lock failed (its lease ran out and
     * another dispatcher took it over); what was marked before stays marked (2.16.0).
     */
    public function isLockLost(): bool
    {
        return $this->lockLost;
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
