<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Messaging;

use Antevemus\ASpecification\Messaging\Outbox\MessageOutboxEntry;

/**
 * RelayReport - Outcome of One OutboxRelay::relayPending() Run
 *
 * The entries the broker confirmed (marked delivered), the reason of each send it did not confirm
 * (marked failed), by message id, the message ids whose last attempt reached the attempt limit of
 * the relay in this run (exhausted: no longer sent, no longer holding their ordering key back, so a
 * later message of the key may overtake them; RN-04 declares it here), and the number of batches
 * (forward 063, D6). No reason carries a value of the payload (RN-05).
 *
 * Since 2.16.0 (forward 065, D2), for a relay with a lock: whether the run sent nothing because another
 * relay held the lock (isLockDenied()), and whether it stopped before a batch because the renewal of the
 * lock failed (isLockLost(): its lease ran out and another relay took it over). toArray() is unchanged.
 *
 * @version    2.16.0
 * @package    Antevemus\ASpecification
 * @subpackage Messaging
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class RelayReport
{
    /**
     * @param list<MessageOutboxEntry> $delivered Entries confirmed, in send order (as they were before the mark)
     * @param array<string, string> $failures Reason by message id, in send order
     * @param list<string> $exhausted Message ids that reached the attempt limit in this run
     * @param int $batches Calls to the transport
     * @param bool $lockDenied True when another relay held the lock: nothing was sent
     * @param bool $lockLost True when the run stopped because the renewal of the lock failed
     */
    public function __construct(
        private readonly array $delivered = [],
        private readonly array $failures = [],
        private readonly array $exhausted = [],
        private readonly int $batches = 0,
        private readonly bool $lockDenied = false,
        private readonly bool $lockLost = false
    ) {
    }

    /** True when the relay did not get its lock (another relay holds it): the run sent nothing. */
    public function isLockDenied(): bool
    {
        return $this->lockDenied;
    }

    /**
     * True when the run stopped before a batch because the renewal of the lock failed (its lease ran
     * out and another relay took it over); what was marked before stays marked.
     */
    public function isLockLost(): bool
    {
        return $this->lockLost;
    }

    /** Number of messages confirmed by the broker. */
    public function deliveredCount(): int
    {
        return count($this->delivered);
    }

    /** Number of sends not confirmed. */
    public function failedCount(): int
    {
        return count($this->failures);
    }

    /**
     * Entries confirmed, in send order.
     *
     * @return list<MessageOutboxEntry>
     */
    public function getDelivered(): array
    {
        return $this->delivered;
    }

    /**
     * Ids of the messages confirmed, in send order.
     *
     * @return list<string>
     */
    public function deliveredIds(): array
    {
        return array_map(static fn(MessageOutboxEntry $e): string => $e->messageId(), $this->delivered);
    }

    /**
     * Reason by message id of the sends not confirmed.
     *
     * @return array<string, string>
     */
    public function getFailures(): array
    {
        return $this->failures;
    }

    /**
     * Message ids that reached the attempt limit in this run.
     *
     * @return list<string>
     */
    public function getExhausted(): array
    {
        return $this->exhausted;
    }

    /** Calls to the transport. */
    public function batchCount(): int
    {
        return $this->batches;
    }

    /**
     * JSON-friendly summary (ids and reasons, never a payload).
     *
     * @return array{delivered: list<string>, failed: array<string, string>, exhausted: list<string>, batches: int}
     */
    public function toArray(): array
    {
        return ['delivered' => $this->deliveredIds(), 'failed' => $this->failures, 'exhausted' => $this->exhausted, 'batches' => $this->batches];
    }
}
