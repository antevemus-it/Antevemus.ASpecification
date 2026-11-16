<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Messaging\Outbox;

use Antevemus\ASpecification\Messaging\Message;
use Antevemus\ASpecification\Messaging\Support\MessagingSupport;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * MessageOutboxEntry - One Message Waiting for (or Done with) the Broker
 *
 * Written by MessagePublisher in the caller's transaction (forward 063, D3, D4). `entryId` is a
 * ULID generated when the message is published: its order is the order of publication, the order
 * the relay keeps within an ordering key (the message id may be older, given by the application).
 * The message id is UNIQUE: publishing the same message again stores nothing. A send the broker
 * did not confirm increments `attempts` and keeps the last reason, never a stack trace nor a value
 * of the payload.
 *
 * @version    2.14.0
 * @package    Antevemus\ASpecification
 * @subpackage Messaging\Outbox
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class MessageOutboxEntry
{
    public readonly DateTimeImmutable $enqueuedAt;
    public readonly ?DateTimeImmutable $deliveredAt;
    public readonly ?DateTimeImmutable $lastAttemptAt;

    /**
     * @param string $entryId ULID of the entry (the order of publication)
     * @param Message $message The message
     * @param DateTimeImmutable $enqueuedAt Instant of the publication (stored in UTC)
     * @param MessageOutboxStatus $status Delivery status
     * @param int $attempts Sends made
     * @param string|null $lastError Reason of the last send not confirmed
     * @param DateTimeImmutable|null $deliveredAt Instant of the confirmation
     * @param DateTimeImmutable|null $lastAttemptAt Instant of the last send
     * @throws InvalidArgumentException When the entry id is not a ULID, or attempts < 0
     */
    public function __construct(
        public readonly string $entryId,
        public readonly Message $message,
        DateTimeImmutable $enqueuedAt,
        public readonly MessageOutboxStatus $status = MessageOutboxStatus::PENDING,
        public readonly int $attempts = 0,
        public readonly ?string $lastError = null,
        ?DateTimeImmutable $deliveredAt = null,
        ?DateTimeImmutable $lastAttemptAt = null
    ) {
        if (!MessagingSupport::isUlid($entryId)) {
            throw new InvalidArgumentException('The id of a message outbox entry must be a ULID (26 characters of Crockford base32, uppercase).');
        }
        if ($attempts < 0) {
            throw new InvalidArgumentException(sprintf('Attempts cannot be negative, %d given.', $attempts));
        }
        $this->enqueuedAt = MessagingSupport::utc($enqueuedAt);
        $this->deliveredAt = $deliveredAt === null ? null : MessagingSupport::utc($deliveredAt);
        $this->lastAttemptAt = $lastAttemptAt === null ? null : MessagingSupport::utc($lastAttemptAt);
    }

    /** Id of the message. */
    public function messageId(): string
    {
        return $this->message->messageId;
    }

    /**
     * Queue of the entry: one per ordering key, sent in order; a message without a key is a queue
     * of its own (it never holds another back).
     */
    public function queueKey(): string
    {
        return $this->message->orderingKey === null ? 'entry:' . $this->entryId : 'key:' . $this->message->orderingKey;
    }

    /** True when the broker confirmed the entry. */
    public function isDelivered(): bool
    {
        return $this->status === MessageOutboxStatus::DELIVERED;
    }

    /** Returns a delivered copy (attempts + 1). */
    public function withDelivered(DateTimeImmutable $at): self
    {
        return new self($this->entryId, $this->message, $this->enqueuedAt, MessageOutboxStatus::DELIVERED, $this->attempts + 1, $this->lastError, $at, $at);
    }

    /** Returns a failed copy (attempts + 1, last reason replaced). */
    public function withFailed(string $reason, DateTimeImmutable $at): self
    {
        return new self($this->entryId, $this->message, $this->enqueuedAt, MessageOutboxStatus::FAILED, $this->attempts + 1, MessagingSupport::truncate($reason), null, $at);
    }

    /**
     * JSON-friendly array form (the message in its wire form).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'entry_id' => $this->entryId,
            'message' => $this->message->toArray(),
            'status' => $this->status->value,
            'attempts' => $this->attempts,
            'last_error' => $this->lastError,
            'enqueued_at' => MessagingSupport::toText($this->enqueuedAt),
            'delivered_at' => $this->deliveredAt === null ? null : MessagingSupport::toText($this->deliveredAt),
            'last_attempt_at' => $this->lastAttemptAt === null ? null : MessagingSupport::toText($this->lastAttemptAt),
        ];
    }
}
