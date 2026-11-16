<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Messaging;

use Antevemus\ASpecification\Messaging\Support\MessagingSupport;
use InvalidArgumentException;

/**
 * TransportSendResult - What the Broker Answered for Each Message of a Batch
 *
 * Returned by IMessageTransport::send() (forward 063, D5): the ids of the messages the broker
 * confirmed (with the id the broker gave them, when it gives one: the entry id of a Redis stream),
 * and the reason of each one that failed (truncated to 2 000 characters; never a value of the
 * payload, RN-05), both in the order they were given. A message id cannot be both. Immutable:
 * withConfirmed() and withFailed() return a copy, so that a transport builds the answer as the broker
 * replies.
 *
 * <code>
 * $result = (new TransportSendResult())->withConfirmed($a->messageId, '1731650000000-0')->withFailed($b->messageId, 'NOROUTE');
 * </code>
 *
 * @version    2.14.0
 * @package    Antevemus\ASpecification
 * @subpackage Messaging
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class TransportSendResult
{
    /** @var array<string, string|null> message id => broker id */
    private readonly array $confirmed;

    /** @var array<string, string> message id => reason */
    private readonly array $failed;

    /**
     * @param array<string, string|null> $confirmed Broker id (or null) by confirmed message id
     * @param array<string, string> $failed Reason by failed message id
     * @throws InvalidArgumentException When a message id is in both, or a reason is empty
     */
    public function __construct(array $confirmed = [], array $failed = [])
    {
        foreach ($failed as $messageId => $reason) {
            if (array_key_exists($messageId, $confirmed)) {
                throw new InvalidArgumentException(sprintf('Message %s cannot be both confirmed and failed.', (string) $messageId));
            }
            if (trim($reason) === '') {
                throw new InvalidArgumentException(sprintf('The failure of message %s needs a reason.', (string) $messageId));
            }
            $failed[$messageId] = MessagingSupport::truncate($reason);
        }
        $this->confirmed = $confirmed;
        $this->failed = $failed;
    }

    /**
     * Every message of the batch confirmed (a transport whose broker stores the batch in one step).
     *
     * @param list<Message> $batch
     */
    public static function allConfirmed(array $batch): self
    {
        $confirmed = [];
        foreach ($batch as $message) {
            $confirmed[$message->messageId] = null;
        }

        return new self($confirmed);
    }

    /**
     * Every message of the batch failed for one reason (a connection lost before any answer).
     *
     * @param list<Message> $batch
     */
    public static function allFailed(array $batch, string $reason): self
    {
        $failed = [];
        foreach ($batch as $message) {
            $failed[$message->messageId] = $reason;
        }

        return new self([], $failed);
    }

    /** A copy with the message confirmed (no longer failed). */
    public function withConfirmed(string $messageId, ?string $brokerId = null): self
    {
        $failed = $this->failed;
        unset($failed[$messageId]);
        $confirmed = $this->confirmed;
        unset($confirmed[$messageId]);
        $confirmed[$messageId] = $brokerId;

        return new self($confirmed, $failed);
    }

    /** A copy with the message failed (no longer confirmed). */
    public function withFailed(string $messageId, string $reason): self
    {
        $confirmed = $this->confirmed;
        unset($confirmed[$messageId]);
        $failed = $this->failed;
        unset($failed[$messageId]);
        $failed[$messageId] = $reason;

        return new self($confirmed, $failed);
    }

    /** True when the broker confirmed the message. */
    public function isConfirmed(string $messageId): bool
    {
        return array_key_exists($messageId, $this->confirmed);
    }

    /** Id the broker gave the message, when confirmed and when it gives one. */
    public function brokerIdOf(string $messageId): ?string
    {
        return $this->confirmed[$messageId] ?? null;
    }

    /** Reason of a failed message; null when it did not fail (or the result says nothing about it). */
    public function reasonOf(string $messageId): ?string
    {
        return $this->failed[$messageId] ?? null;
    }

    /**
     * Ids of the confirmed messages.
     *
     * @return list<string>
     */
    public function confirmedIds(): array
    {
        return array_map('strval', array_keys($this->confirmed));
    }

    /**
     * Reason by failed message id.
     *
     * @return array<string, string>
     */
    public function failures(): array
    {
        return $this->failed;
    }
}
