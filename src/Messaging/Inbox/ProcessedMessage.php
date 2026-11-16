<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Messaging\Inbox;

use Antevemus\ASpecification\Messaging\Message;
use Antevemus\ASpecification\Messaging\Support\MessagingSupport;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * ProcessedMessage - One Row of the Inbox: a Message a Consumer Already Handled
 *
 * Written by MessageConsumer in the transaction of the handler (forward 063, D8): the consumer, the
 * idempotency key of the message (UNIQUE together), its id, name and tenant, and the instant. Never
 * the payload. A redelivery of the same message to the same consumer finds the row and is
 * acknowledged without running the handler again (RN-03).
 *
 * @version    2.14.0
 * @package    Antevemus\ASpecification
 * @subpackage Messaging\Inbox
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class ProcessedMessage
{
    /**
     * Name of a consumer: lowercase, digits and `._:-`, at most 120 characters. Lowercase only, so
     * that the inbox answers the same under the case-insensitive collations of MySQL and MariaDB.
     */
    public const CONSUMER_PATTERN = '/^[a-z0-9][a-z0-9._:-]{0,119}$/D';

    /** Maximum length of an idempotency key (column `idempotency_key`). */
    public const MAX_KEY_LENGTH = 190;

    public readonly DateTimeImmutable $processedAt;

    /**
     * @param string $consumer Consumer that handled the message
     * @param string $idempotencyKey Key of the message (`msg:` + id for a Message)
     * @param string $messageId Id of the message
     * @param string $messageName Name of the message
     * @param string|null $tenant Tenant of the message
     * @param DateTimeImmutable $processedAt Instant of the handling (stored in UTC)
     * @throws InvalidArgumentException When the consumer or the key is out of bounds
     */
    public function __construct(
        public readonly string $consumer,
        public readonly string $idempotencyKey,
        public readonly string $messageId,
        public readonly string $messageName,
        public readonly ?string $tenant,
        DateTimeImmutable $processedAt
    ) {
        self::assertConsumer($consumer);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > self::MAX_KEY_LENGTH) {
            throw new InvalidArgumentException(sprintf('An inbox idempotency key must have 1 to %d bytes.', self::MAX_KEY_LENGTH));
        }
        $this->processedAt = MessagingSupport::utc($processedAt);
    }

    /** The row of a message handled by a consumer at an instant. */
    public static function of(string $consumer, Message $message, DateTimeImmutable $at): self
    {
        return new self($consumer, $message->idempotencyKey(), $message->messageId, $message->name, $message->tenant, $at);
    }

    /**
     * @throws InvalidArgumentException When the name breaks CONSUMER_PATTERN
     */
    public static function assertConsumer(string $consumer): string
    {
        if (preg_match(self::CONSUMER_PATTERN, $consumer) !== 1) {
            throw new InvalidArgumentException(sprintf('The consumer name must match %s (lowercase, at most 120 characters).', self::CONSUMER_PATTERN));
        }

        return $consumer;
    }

    /**
     * JSON-friendly array form.
     *
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'consumer' => $this->consumer,
            'idempotency_key' => $this->idempotencyKey,
            'message_id' => $this->messageId,
            'message_name' => $this->messageName,
            'tenant' => $this->tenant,
            'processed_at' => MessagingSupport::toText($this->processedAt),
        ];
    }
}
