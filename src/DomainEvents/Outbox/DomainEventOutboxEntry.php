<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\DomainEvents\Outbox;

use Antevemus\ASpecification\DomainEvents\DomainEvent;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * DomainEventOutboxEntry - One Domain Event Waiting for (or Done with) Delivery
 *
 * Written by OutboxDomainEventPublisher, in the transaction of the write when the repository and
 * the outbox share one (forward 029, D5, D6). `eventId` is a ULID (the primary key and the
 * delivery order: ULIDs sort in generation order); the event's idempotency key is UNIQUE, so the
 * same crossing is stored once. Failures increment `attempts` and keep only the last error
 * message, never a stack trace.
 *
 * @version    1.11.0
 * @package    Antevemus\ASpecification
 * @subpackage DomainEvents\Outbox
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class DomainEventOutboxEntry
{
    public readonly ?DateTimeImmutable $deliveredAt;
    public readonly ?DateTimeImmutable $lastAttemptAt;

    /**
     * @param string $eventId ULID of the entry (26 characters)
     * @param DomainEvent $event The event
     * @param DomainEventOutboxStatus $status Delivery status
     * @param int $attempts Delivery attempts made
     * @param string|null $lastError Message of the last failure (no stack trace)
     * @param DateTimeImmutable|null $deliveredAt Instant of delivery
     * @param DateTimeImmutable|null $lastAttemptAt Instant of the last attempt
     * @throws InvalidArgumentException When the id is empty or longer than 26 characters, or attempts < 0
     */
    public function __construct(
        public readonly string $eventId,
        public readonly DomainEvent $event,
        public readonly DomainEventOutboxStatus $status = DomainEventOutboxStatus::PENDING,
        public readonly int $attempts = 0,
        public readonly ?string $lastError = null,
        ?DateTimeImmutable $deliveredAt = null,
        ?DateTimeImmutable $lastAttemptAt = null
    ) {
        if ($eventId === '' || strlen($eventId) > 26) {
            throw new InvalidArgumentException(sprintf('The outbox event id must have 1 to 26 characters, "%s" given.', $eventId));
        }
        if ($attempts < 0) {
            throw new InvalidArgumentException(sprintf('Attempts cannot be negative, %d given.', $attempts));
        }
        $this->deliveredAt = $deliveredAt === null ? null : DomainEventSupport::utc($deliveredAt);
        $this->lastAttemptAt = $lastAttemptAt === null ? null : DomainEventSupport::utc($lastAttemptAt);
    }

    /** Idempotency key of the event, handed to its handlers. */
    public function idempotencyKey(): string
    {
        return $this->event->getIdempotencyKey();
    }

    /** Queue of the entry: one per entity (class + id), delivered in order. */
    public function queueKey(): string
    {
        return $this->event->entityClass . "\0" . $this->event->entityId;
    }

    /** True when the entry was delivered. */
    public function isDelivered(): bool
    {
        return $this->status === DomainEventOutboxStatus::DELIVERED;
    }

    /** Returns a delivered copy (attempts + 1). */
    public function withDelivered(DateTimeImmutable $at): self
    {
        return new self($this->eventId, $this->event, DomainEventOutboxStatus::DELIVERED, $this->attempts + 1, $this->lastError, $at, $at);
    }

    /** Returns a failed copy (attempts + 1, last error replaced). */
    public function withFailed(string $error, DateTimeImmutable $at): self
    {
        return new self($this->eventId, $this->event, DomainEventOutboxStatus::FAILED, $this->attempts + 1, DomainEventSupport::truncate($error), null, $at);
    }

    /**
     * JSON-friendly array form.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'event' => $this->event->toArray(),
            'status' => $this->status->value,
            'attempts' => $this->attempts,
            'last_error' => $this->lastError,
            'delivered_at' => $this->deliveredAt === null ? null : DomainEventSupport::toText($this->deliveredAt),
            'last_attempt_at' => $this->lastAttemptAt === null ? null : DomainEventSupport::toText($this->lastAttemptAt),
        ];
    }
}
