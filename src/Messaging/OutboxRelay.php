<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Messaging;

use Antevemus\ASpecification\Messaging\Outbox\IMessageOutbox;
use Antevemus\ASpecification\Messaging\Outbox\MessageOutboxEntry;
use Antevemus\ASpecification\Messaging\Outbox\PdoMessageOutbox;
use Antevemus\ASpecification\Messaging\Support\MessagingSupport;
use Antevemus\ASpecification\Persistence\ILeaseLock;
use Antevemus\ASpecification\Persistence\LeaseLockSupport;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * OutboxRelay - Sends the Outbox to the Broker and Marks Only What It Confirmed
 *
 * Runs after the commit, outside the write's transaction, typically from a worker or a cron (forward
 * 063, D6); the only component that talks to the broker (RN-02). relayPending($limit) sends up to
 * $limit entries in batches through the IMessageTransport and marks each one:
 * - confirmed by the broker: delivered (attempts + 1);
 * - not confirmed (failed, absent from the result, or the whole send threw): failed (attempts + 1,
 *   the reason of the transport, or `ShortClass` of the exception plus its message), and its
 *   ordering key is held back for the rest of the run.
 *
 * Order: the algorithm of DomainEventDispatcher, in batches. Each round reads the HEAD of every
 * queue (IMessageOutbox::fetchPending()), leaves out the keys held back, and sends at most
 * $batchSize of them in one call; the next round reads the new heads. So a message of an ordering
 * key is sent only once every earlier one of the key was confirmed (or exhausted), a batch holds at
 * most one message per key, and the broker stores the messages of a key in the order of publication.
 * Without $maxAttempts a message the broker never confirms holds its key back until it does; with it,
 * an entry that reached the limit is exhausted (kept as failed, no longer sent, no longer blocking)
 * and reported (RelayReport::getExhausted()).
 *
 * Delivery is at-least-once (RN-03): a confirmed message is never lost, because it is marked only
 * after the confirmation; a process that dies between the confirmation and the mark sends it again
 * on the next run, and the consumers deduplicate it by its idempotency key (their inbox). A mark that
 * fails (the database went away) propagates: the run stops there, and what was confirmed and not
 * marked is sent again.
 *
 * Since 2.16.0 (forward 065, D2, D10): one relay per outbox, or a lock. Without a lock the relay is
 * the one of 2.15.0, and nothing keeps two relays off the same outbox: the order of the first delivery
 * of each key still holds, but a relay that read an entry before another one marked it sends it again
 * (a late duplicate, after the later messages of its key) and both count the attempt. With a lock
 * (ILeaseLock, `lock:`), relayPending() takes it under its name (`lockName`, by default
 * LOCK_NAME plus the table of a PdoMessageOutbox) before its first batch and returns a report that says
 * so (RelayReport::isLockDenied()) without sending anything when another relay holds it; renews it
 * before every further batch, before the heads are read, and stops when the renewal fails
 * (RelayReport::isLockLost(): the lease ran out and another relay took over); and releases it at the
 * end, also when the run throws. The lease (`leaseSeconds`, 60 by default) must exceed the longest
 * batch, the time the transport may take to answer included, and the skew between the clocks of the
 * relays; the owner (`lockOwner`) is a new ULID per instance unless given.
 *
 * @version    2.16.0
 * @package    Antevemus\ASpecification
 * @subpackage Messaging
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class OutboxRelay
{
    /** Default lease of the lock, in seconds. */
    public const DEFAULT_LEASE_SECONDS = LeaseLockSupport::DEFAULT_LEASE_SECONDS;

    /** Kind part of the default lock name (followed by `:` and the table of a PdoMessageOutbox). */
    public const LOCK_NAME = 'messaging.outbox_relay';

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $clock;

    private readonly ?string $lockOwner;

    private readonly string $lockName;

    /**
     * @param IMessageOutbox $outbox Outbox to send
     * @param IMessageTransport $transport The broker
     * @param int|null $maxAttempts Sends after which an entry is exhausted; null = retry forever
     * @param int $batchSize Largest batch handed to the transport (>= 1)
     * @param (Closure(): DateTimeImmutable)|null $clock Instant recorded for each mark and given to the lock (null = now)
     * @param ILeaseLock|null $lock One relay at a time (2.16.0); null = no lock, the relay of 2.15.0
     * @param int $leaseSeconds Lease of the lock, renewed before each batch (>= 1)
     * @param string|null $lockOwner Owner of the lock (1 to 120 characters); null = a new ULID when there is a lock
     * @param string|null $lockName Name of the lock (1 to 120 characters); null = LOCK_NAME, plus `:` and the table of a PdoMessageOutbox
     * @throws InvalidArgumentException When $maxAttempts, $batchSize or $leaseSeconds is below 1, or the owner or the name is invalid
     */
    public function __construct(
        private readonly IMessageOutbox $outbox,
        private readonly IMessageTransport $transport,
        private readonly ?int $maxAttempts = null,
        private readonly int $batchSize = 100,
        ?Closure $clock = null,
        private readonly ?ILeaseLock $lock = null,
        private readonly int $leaseSeconds = self::DEFAULT_LEASE_SECONDS,
        ?string $lockOwner = null,
        ?string $lockName = null
    ) {
        if ($maxAttempts !== null && $maxAttempts < 1) {
            throw new InvalidArgumentException(sprintf('maxAttempts must be at least 1, %d given.', $maxAttempts));
        }
        if ($batchSize < 1) {
            throw new InvalidArgumentException(sprintf('The batch size must be at least 1, %d given.', $batchSize));
        }
        $this->lockOwner = LeaseLockSupport::ownerOf($lock, $lockOwner, $lockName, $leaseSeconds);
        $this->lockName = $lockName ?? self::LOCK_NAME . ($outbox instanceof PdoMessageOutbox ? ':' . $outbox->getTable() : '');
        if ($lock !== null) {
            LeaseLockSupport::checkName($this->lockName);
        }
        $this->clock = $clock ?? MessagingSupport::now(...);
    }

    /** Owner of the lock this relay takes (given, or the ULID generated for this instance); null without a lock and an owner. */
    public function getLockOwner(): ?string
    {
        return $this->lockOwner;
    }

    /** Name of the lock this relay takes (given, or the default one). */
    public function getLockName(): string
    {
        return $this->lockName;
    }

    /**
     * Sends up to $limit pending entries (each send counts, confirmed or not).
     *
     * @param int $limit Maximum number of sends in this run (>= 1)
     * @throws InvalidArgumentException When $limit < 1
     * @throws \Antevemus\ASpecification\Messaging\Exceptions\MessagingPersistenceException When the outbox fails
     */
    public function relayPending(int $limit = 100): RelayReport
    {
        if ($limit < 1) {
            throw new InvalidArgumentException(sprintf('The limit must be at least 1, %d given.', $limit));
        }
        if (!$this->holdLock()) {
            return new RelayReport(lockDenied: true);
        }
        $delivered = [];
        $failures = [];
        $exhausted = [];
        /** @var array<string, true> $blocked queues with a send not confirmed in this run */
        $blocked = [];
        $sent = 0;
        $batches = 0;
        $lost = false;

        try {
            while ($sent < $limit) {
                // Renewed before every batch after the first one, BEFORE the heads are read: a head read
                // under a lease that another relay took over in the meantime is never sent.
                if ($batches > 0 && !$this->holdLock()) {
                    $lost = true;
                    break;
                }
                $room = min($this->batchSize, $limit - $sent);
                $heads = array_values(array_filter(
                    $this->outbox->fetchPending($room + count($blocked), $this->maxAttempts),
                    static fn(MessageOutboxEntry $e): bool => !isset($blocked[$e->queueKey()])
                ));
                if ($heads === []) {
                    break;
                }
                $batch = array_slice($heads, 0, $room);
                $sent += count($batch);
                $batches++;
                $result = $this->send($batch);
                foreach ($batch as $entry) {
                    $id = $entry->messageId();
                    if ($result instanceof TransportSendResult && $result->isConfirmed($id)) {
                        $this->outbox->markDelivered($entry, ($this->clock)());
                        $delivered[] = $entry;
                        continue;
                    }
                    $reason = is_string($result)
                        ? $result
                        : ($result->reasonOf($id) ?? sprintf('The broker did not confirm %s.', $entry->message->describe()));
                    $this->outbox->markFailed($entry, $reason, ($this->clock)());
                    $failures[$id] = MessagingSupport::truncate($reason);
                    $blocked[$entry->queueKey()] = true;
                    if ($this->maxAttempts !== null && $entry->attempts + 1 >= $this->maxAttempts) {
                        $exhausted[] = $id;
                    }
                }
            }
        } finally {
            $this->lock?->release($this->lockName, (string) $this->lockOwner);
        }

        return new RelayReport($delivered, $failures, $exhausted, $batches, false, $lost);
    }

    /** Takes or renews the lock; true without one. */
    private function holdLock(): bool
    {
        return LeaseLockSupport::hold($this->lock, $this->lockName, $this->lockOwner, $this->clock, $this->leaseSeconds);
    }

    /**
     * @param list<MessageOutboxEntry> $batch
     * @return TransportSendResult|string The result, or the reason of a send that threw
     */
    private function send(array $batch): TransportSendResult|string
    {
        try {
            return $this->transport->send(array_map(static fn(MessageOutboxEntry $e): Message => $e->message, $batch));
        } catch (Throwable $e) {
            return sprintf('The transport failed for the whole batch (%s: %s).', MessagingSupport::shortClass($e), $e->getMessage());
        }
    }
}
