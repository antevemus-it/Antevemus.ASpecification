<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Messaging;

use Antevemus\ASpecification\Messaging\Exceptions\InvalidMessageException;
use Antevemus\ASpecification\Messaging\Support\MessagingSupport;
use InvalidArgumentException;
use LogicException;

/**
 * ReceivedMessage - One Delivery of a Broker to a Consumer
 *
 * Returned by IMessageSource::receive() (forward 063, D7): the Message rebuilt from what the broker
 * delivered, the number of times this message was delivered to this consumer (1 the first time) and
 * the id the broker uses to acknowledge it (a stream entry id, a delivery tag).
 *
 * A delivery that cannot be read back as a Message is built with invalid(): it has no message, only a
 * reason (and the name and id when the source could read them), and MessageConsumer rejects it
 * without requeue (D8). decode() reads the JSON wire form and returns one or the other, so that a
 * source never drops what it received.
 *
 * @version    2.14.0
 * @package    Antevemus\ASpecification
 * @subpackage Messaging
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class ReceivedMessage
{
    /**
     * @throws InvalidArgumentException When the count is below 1, the broker id is empty, or the
     *         delivery has neither a message nor a reason
     */
    private function __construct(
        private readonly ?Message $message,
        public readonly int $deliveryCount,
        public readonly string $brokerId,
        private readonly ?string $invalidReason
    ) {
        if ($deliveryCount < 1) {
            throw new InvalidArgumentException(sprintf('A delivery count starts at 1, %d given.', $deliveryCount));
        }
        if ($brokerId === '') {
            throw new InvalidArgumentException('A delivery needs the id the broker gave it.');
        }
    }

    /** A delivery of a message. */
    public static function of(Message $message, int $deliveryCount, string $brokerId): self
    {
        return new self($message, $deliveryCount, $brokerId, null);
    }

    /**
     * A delivery that cannot be read back as a Message.
     *
     * @param string $reason Why (never a value of what was delivered)
     * @throws InvalidArgumentException When the reason is empty
     */
    public static function invalid(int $deliveryCount, string $brokerId, string $reason): self
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('An invalid delivery needs a reason.');
        }

        return new self(null, $deliveryCount, $brokerId, MessagingSupport::truncate($reason));
    }

    /**
     * Reads the JSON wire form (Message::toJson()) of a delivery: a delivery of the message, or an
     * invalid one whose reason is the safe message of InvalidMessageException.
     */
    public static function decode(string $json, int $deliveryCount, string $brokerId): self
    {
        try {
            return self::of(Message::fromJson($json), $deliveryCount, $brokerId);
        } catch (InvalidMessageException $e) {
            return self::invalid($deliveryCount, $brokerId, 'The delivery is not a message of format 1: ' . $e->getMessage());
        }
    }

    /** True when the delivery holds a message. */
    public function isValid(): bool
    {
        return $this->message !== null;
    }

    /**
     * The message delivered.
     *
     * @throws LogicException When the delivery is invalid
     */
    public function message(): Message
    {
        return $this->message ?? throw new LogicException(sprintf('Delivery %s holds no message: %s', $this->brokerId, (string) $this->invalidReason));
    }

    /** Why the delivery holds no message; null when it holds one. */
    public function invalidReason(): ?string
    {
        return $this->invalidReason;
    }

    /** Text that names the delivery in a reason: the message (name, id, key) and the broker id. */
    public function describe(): string
    {
        return $this->message === null
            ? sprintf('invalid delivery %s', $this->brokerId)
            : sprintf('%s, delivery %s', $this->message->describe(), $this->brokerId);
    }
}
