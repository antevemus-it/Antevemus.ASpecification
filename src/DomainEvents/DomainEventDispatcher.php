<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\DomainEvents;

use Antevemus\ASpecification\DomainEvents\Outbox\DomainEventOutboxEntry;
use Antevemus\ASpecification\DomainEvents\Outbox\DomainEventSupport;
use Antevemus\ASpecification\DomainEvents\Outbox\IDomainEventOutbox;
use Antevemus\ASpecification\DomainEvents\Outbox\PdoDomainEventOutbox;
use Antevemus\ASpecification\Persistence\ILeaseLock;
use Antevemus\ASpecification\Persistence\LeaseLockSupport;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * DomainEventDispatcher - Delivers the Domain Event Outbox After the Commit
 *
 * Runs outside the write's transaction (forward 029, D5), typically from a worker or a cron, in
 * the design of the 1.8.0 EffectDispatcher: dispatchPending($limit) delivers up to $limit entries
 * to the handlers of their event name (DomainEventHandlerRegistry), each with the event's
 * idempotency key, and marks each one delivered or failed (attempts + 1, last error message
 * without stack trace). A failing handler never undoes the write that produced the event. An
 * event with no handler is a failed attempt ("No handler registered..."), not a silent drop.
 *
 * Order: per entity, in write order (FIFO by event id). The dispatcher works in rounds over the
 * queue heads (IDomainEventOutbox::fetchPending()): an entry is only attempted when every earlier
 * entry of its entity was delivered (or is exhausted), and once an entry of an entity fails, the
 * entity is left alone until the next run. Without $maxAttempts a failing event holds its
 * entity's later events back until it succeeds; with it, an entry that reached the limit is
 * exhausted (kept as failed, no longer attempted, no longer blocking).
 *
 * Delivery is at-least-once: handlers deduplicate by the idempotency key (MachineEventBridge does
 * it through the runner's idempotency store).
 *
 * Since 2.16.0 (forward 065, D10): one dispatcher per outbox, or a lock. Without a lock the dispatcher is the
 * one of 2.15.0: two dispatchers over the same outbox may deliver the same entry twice (a late duplicate, after the later events of its entity) and both count the attempt. With a lock (ILeaseLock, `lock:`), dispatchPending() takes it under its name
 * (`lockName`, by default LOCK_NAME plus the table of a PdoDomainEventOutbox) before its first round and
 * returns a report that says so (DomainEventDispatchReport::isLockDenied()) without delivering anything when another
 * dispatcher holds it; renews it before every further round, before the heads are read, and stops when the
 * renewal fails (DomainEventDispatchReport::isLockLost(): the lease ran out and another dispatcher took over); and
 * releases it at the end, also when the run throws. The lease (`leaseSeconds`, 60 by default) must
 * exceed the longest round, the time the handlers may take included, and the skew between the clocks
 * of the workers; the owner (`lockOwner`) is a new ULID per instance unless given.
 *
 * @version    2.16.0
 * @package    Antevemus\ASpecification
 * @subpackage DomainEvents
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class DomainEventDispatcher
{
    /** Default lease of the lock, in seconds. */
    public const DEFAULT_LEASE_SECONDS = LeaseLockSupport::DEFAULT_LEASE_SECONDS;

    /** Kind part of the default lock name (followed by `:` and the table of a PdoDomainEventOutbox). */
    public const LOCK_NAME = 'domain_events.dispatcher';

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $clock;

    private readonly ?string $lockOwner;

    private readonly string $lockName;

    /**
     * @param IDomainEventOutbox $outbox Outbox to deliver
     * @param DomainEventHandlerRegistry $handlers Handlers by event name
     * @param int|null $maxAttempts Attempts after which an entry is exhausted; null = retry forever
     * @param (Closure(): DateTimeImmutable)|null $clock Instant recorded for each attempt and given to the lock (default: system clock)
     * @param ILeaseLock|null $lock One dispatcher at a time (2.16.0); null = no lock, the dispatcher of 2.15.0
     * @param int $leaseSeconds Lease of the lock, renewed before each round (>= 1)
     * @param string|null $lockOwner Owner of the lock (1 to 120 characters); null = a new ULID when there is a lock
     * @param string|null $lockName Name of the lock (1 to 120 characters); null = LOCK_NAME, plus `:` and the table of a PdoDomainEventOutbox
     * @throws InvalidArgumentException When $maxAttempts or $leaseSeconds is below 1, or the owner or the name is invalid
     */
    public function __construct(
        private readonly IDomainEventOutbox $outbox,
        private readonly DomainEventHandlerRegistry $handlers,
        private readonly ?int $maxAttempts = null,
        ?Closure $clock = null,
        private readonly ?ILeaseLock $lock = null,
        private readonly int $leaseSeconds = self::DEFAULT_LEASE_SECONDS,
        ?string $lockOwner = null,
        ?string $lockName = null
    ) {
        if ($maxAttempts !== null && $maxAttempts < 1) {
            throw new InvalidArgumentException(sprintf('maxAttempts must be at least 1, %d given.', $maxAttempts));
        }
        $this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable('now');
        $this->lockOwner = LeaseLockSupport::ownerOf($lock, $lockOwner, $lockName, $leaseSeconds);
        $this->lockName = $lockName ?? self::LOCK_NAME . ($outbox instanceof PdoDomainEventOutbox ? ':' . $outbox->getTable() : '');
        if ($lock !== null) {
            LeaseLockSupport::checkName($this->lockName);
        }
    }

    /** Owner of the lock this dispatcher takes (given, or the ULID generated for this instance); null without a lock and an owner. */
    public function getLockOwner(): ?string
    {
        return $this->lockOwner;
    }

    /** Name of the lock this dispatcher takes (given, or the default one). */
    public function getLockName(): string
    {
        return $this->lockName;
    }

    /**
     * Delivers up to $limit pending entries (each attempt counts, delivered or failed).
     *
     * @param int $limit Maximum number of attempts in this run (>= 1)
     */
    public function dispatchPending(int $limit = 100): DomainEventDispatchReport
    {
        if ($limit < 1) {
            throw new InvalidArgumentException(sprintf('The limit must be at least 1, %d given.', $limit));
        }
        if (!$this->holdLock()) {
            return new DomainEventDispatchReport(lockDenied: true);
        }
        $delivered = [];
        $failures = [];
        /** @var array<string, true> $blocked entities with a failed attempt in this run */
        $blocked = [];
        $attempted = 0;
        $rounds = 0;
        $lost = false;

        try {
            while ($attempted < $limit) {
                // Renewed before every round after the first one, BEFORE the heads are read (2.16.0)
                if ($rounds > 0 && !$this->holdLock()) {
                    $lost = true;
                    break;
                }
                $rounds++;
                $heads = array_values(array_filter(
                    $this->outbox->fetchPending($limit - $attempted + count($blocked), $this->maxAttempts),
                    static fn(DomainEventOutboxEntry $e): bool => !isset($blocked[$e->queueKey()])
                ));
                if ($heads === []) {
                    break;
                }
                foreach ($heads as $entry) {
                    if ($attempted >= $limit) {
                        break 2;
                    }
                    $attempted++;
                    $error = $this->deliver($entry);
                    if ($error === null) {
                        $delivered[] = $entry;
                    } else {
                        $failures[$entry->idempotencyKey()] = $error;
                        $blocked[$entry->queueKey()] = true;
                    }
                }
            }
        } finally {
            $this->lock?->release($this->lockName, (string) $this->lockOwner);
        }

        return new DomainEventDispatchReport($delivered, $failures, false, $lost);
    }

    /** Takes or renews the lock; true without one. */
    private function holdLock(): bool
    {
        return LeaseLockSupport::hold($this->lock, $this->lockName, $this->lockOwner, $this->clock, $this->leaseSeconds);
    }


    /**
     * Attempts one entry; returns null when delivered, the recorded error message otherwise.
     */
    private function deliver(DomainEventOutboxEntry $entry): ?string
    {
        $handlers = $this->handlers->handlersFor($entry->event->name);
        if ($handlers === []) {
            $error = sprintf('No handler registered for domain event "%s".', $entry->event->name);
            $this->outbox->markFailed($entry, $error, ($this->clock)());

            return $error;
        }
        try {
            foreach ($handlers as $handler) {
                $handler($entry->event, $entry->idempotencyKey());
            }
        } catch (Throwable $e) {
            $error = DomainEventSupport::errorMessage($e);
            $this->outbox->markFailed($entry, $error, ($this->clock)());

            return $error;
        }
        $this->outbox->markDelivered($entry, ($this->clock)());

        return null;
    }
}
