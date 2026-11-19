<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Runner;

use Antevemus\ASpecification\Persistence\ILeaseLock;
use Antevemus\ASpecification\Persistence\LeaseLockSupport;
use Antevemus\ASpecification\StateMachine\Persistence\IEffectOutbox;
use Antevemus\ASpecification\StateMachine\Persistence\OutboxEntry;
use Antevemus\ASpecification\StateMachine\Persistence\Pdo\PdoEffectOutbox;
use Antevemus\ASpecification\StateMachine\Persistence\PersistenceSupport;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * EffectDispatcher - Delivers the Outbox After the Commit
 *
 * Runs outside the runner's transaction (forward 023, D12, RN-10), typically from a worker or a
 * cron: dispatchPending($limit) delivers up to $limit outbox entries to their handlers
 * (EffectHandlerRegistry), each with its idempotency key, and marks each one delivered or failed
 * (attempts + 1, last error message without stack trace). A failing handler never undoes the
 * transition that produced the effect.
 *
 * Order: per instance, in write order. The dispatcher works in rounds over the queue heads
 * (IEffectOutbox::fetchPending()): an entry is only attempted when every earlier entry of its
 * instance was delivered (or is exhausted), and once an entry of an instance fails, the instance
 * is left alone until the next run, so a later effect never overtakes a failed one. Without
 * $maxAttempts a failing effect holds its instance's later effects back until it succeeds; with
 * it, an entry that reached the limit is exhausted (kept as failed, no longer attempted, no
 * longer blocking).
 *
 * Delivery is at-least-once: two dispatchers running at the same time may deliver the same
 * entry; handlers use the idempotency key to deduplicate.
 *
 * Since 2.10.0 every handler is called through EffectHandlerRegistry::invoke(), so a handler that
 * declares the fourth parameter receives the EffectContext of its entry (with the event of the step,
 * when the outbox kept it).
 *
 * Since 2.16.0 (forward 065, D10): one dispatcher per outbox, or a lock. Without a lock the dispatcher is the
 * one of 2.15.0: two dispatchers running at the same time may deliver the same entry, and both count the attempt. With a lock (ILeaseLock, `lock:`), dispatchPending() takes it under its name
 * (`lockName`, by default LOCK_NAME plus the table of a PdoEffectOutbox) before its first round and
 * returns a report that says so (DispatchReport::isLockDenied()) without delivering anything when another
 * dispatcher holds it; renews it before every further round, before the heads are read, and stops when the
 * renewal fails (DispatchReport::isLockLost(): the lease ran out and another dispatcher took over); and
 * releases it at the end, also when the run throws. The lease (`leaseSeconds`, 60 by default) must
 * exceed the longest round, the time the handlers may take included, and the skew between the clocks
 * of the workers; the owner (`lockOwner`) is a new ULID per instance unless given.
 *
 * @version    2.16.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Runner
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class EffectDispatcher
{
    /** Default lease of the lock, in seconds. */
    public const DEFAULT_LEASE_SECONDS = LeaseLockSupport::DEFAULT_LEASE_SECONDS;

    /** Kind part of the default lock name (followed by `:` and the table of a PdoEffectOutbox). */
    public const LOCK_NAME = 'state_machine.effect_dispatcher';

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $clock;

    private readonly ?string $lockOwner;

    private readonly string $lockName;

    /**
     * @param IEffectOutbox $outbox Outbox to deliver
     * @param EffectHandlerRegistry $handlers Handlers by action name
     * @param int|null $maxAttempts Attempts after which an entry is exhausted; null = retry forever
     * @param (Closure(): DateTimeImmutable)|null $clock Instant recorded for each attempt and given to the lock (default: system clock)
     * @param ILeaseLock|null $lock One dispatcher at a time (2.16.0); null = no lock, the dispatcher of 2.15.0
     * @param int $leaseSeconds Lease of the lock, renewed before each round (>= 1)
     * @param string|null $lockOwner Owner of the lock (1 to 120 characters); null = a new ULID when there is a lock
     * @param string|null $lockName Name of the lock (1 to 120 characters); null = LOCK_NAME, plus `:` and the table of a PdoEffectOutbox
     * @throws InvalidArgumentException When $maxAttempts or $leaseSeconds is below 1, or the owner or the name is invalid
     */
    public function __construct(
        private readonly IEffectOutbox $outbox,
        private readonly EffectHandlerRegistry $handlers,
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
        $this->lockName = $lockName ?? self::LOCK_NAME . ($outbox instanceof PdoEffectOutbox ? ':' . $outbox->getTable() : '');
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
     * @return DispatchReport
     */
    public function dispatchPending(int $limit = 100): DispatchReport
    {
        if ($limit < 1) {
            throw new InvalidArgumentException(sprintf('The limit must be at least 1, %d given.', $limit));
        }
        if (!$this->holdLock()) {
            return new DispatchReport(lockDenied: true);
        }
        $delivered = [];
        $failures = [];
        /** @var array<string, true> $blocked instances with a failed attempt in this run */
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
                    static fn(OutboxEntry $e): bool => !isset($blocked[$e->instanceId])
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
                        $failures[$entry->effectKey()] = $error;
                        $blocked[$entry->instanceId] = true;
                    }
                }
            }
        } finally {
            $this->lock?->release($this->lockName, (string) $this->lockOwner);
        }

        return new DispatchReport($delivered, $failures, false, $lost);
    }

    /** Takes or renews the lock; true without one. */
    private function holdLock(): bool
    {
        return LeaseLockSupport::hold($this->lock, $this->lockName, $this->lockOwner, $this->clock, $this->leaseSeconds);
    }


    /**
     * Attempts one entry; returns null when delivered, the recorded error message otherwise.
     */
    private function deliver(OutboxEntry $entry): ?string
    {
        if (!$this->handlers->has($entry->effect->action)) {
            $error = sprintf('No handler registered for action "%s".', $entry->effect->action);
            $this->outbox->markFailed($entry, $error, ($this->clock)());

            return $error;
        }
        try {
            $this->handlers->invoke(EffectContext::of($entry));
        } catch (Throwable $e) {
            $error = PersistenceSupport::errorMessage($e);
            $this->outbox->markFailed($entry, $error, ($this->clock)());

            return $error;
        }
        $this->outbox->markDelivered($entry, ($this->clock)());

        return null;
    }
}
