<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Messaging\InMemory;

use Antevemus\ASpecification\Messaging\Exceptions\MessagingException;
use Antevemus\ASpecification\Messaging\IMessageSource;
use Antevemus\ASpecification\Messaging\ReceivedMessage;
use Antevemus\ASpecification\Messaging\Support\MessagingSupport;
use InvalidArgumentException;

/**
 * InMemoryMessageSource - One Consumer's View of an InMemoryMessageTransport
 *
 * The reference implementation of IMessageSource (forward 063, D7), in the semantics of a consumer
 * group of a Redis stream: it reads the log of the transport from its own position, every body
 * decoded by ReceivedMessage::decode() (an invalid body is an invalid delivery, never dropped); a
 * delivery stays in flight until ack() or reject(); its broker id is its position in the log, the
 * same on every redelivery; its delivery count is the number of times this source handed it out.
 *
 * - reject($requeue = true) and recoverInFlight() (the consumer died: XAUTOCLAIM, a closed channel)
 *   give deliveries back; they are handed out again before anything newer, in log order, so the
 *   order of an ordering key holds across redeliveries.
 * - reject($requeue = false) moves the delivery to the dead letters of this source, with the reason
 *   and the delivery count (deadLetters()).
 * - ack() or reject() of a delivery that is not in flight is a MessagingException (a consumer that
 *   answers twice is a bug this double makes visible).
 *
 * @version    2.14.0
 * @package    Antevemus\ASpecification
 * @subpackage Messaging\InMemory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class InMemoryMessageSource implements IMessageSource
{
    /** Next position of the log never handed out. */
    private int $cursor = 0;

    /** @var array<int, true> positions given back, handed out before the cursor moves on */
    private array $ready = [];

    /** @var array<int, true> positions in flight */
    private array $inFlight = [];

    /** @var array<int, int> position => deliveries so far */
    private array $deliveries = [];

    /** @var list<array{brokerId: string, messageId: ?string, reason: string, deliveryCount: int}> */
    private array $dead = [];

    private int $acknowledged = 0;

    public function __construct(private readonly InMemoryMessageTransport $transport)
    {
    }

    /** {@inheritdoc} */
    public function receive(int $max, int $waitMs): array
    {
        if ($max < 1) {
            throw new InvalidArgumentException(sprintf('receive() takes at least 1 delivery, %d given.', $max));
        }
        if ($waitMs < 0) {
            throw new InvalidArgumentException(sprintf('The wait cannot be negative, %d given.', $waitMs));
        }
        ksort($this->ready);
        $positions = array_slice(array_keys($this->ready), 0, $max);
        if (count($positions) < $max) {
            $fresh = array_slice($this->transport->bodiesFrom($this->cursor), 0, $max - count($positions), true);
            $positions = [...$positions, ...array_keys($fresh)];
        }
        $bodies = $this->transport->bodiesFrom(0);
        $received = [];
        foreach ($positions as $position) {
            unset($this->ready[$position]);
            $this->cursor = max($this->cursor, $position + 1);
            $this->inFlight[$position] = true;
            $this->deliveries[$position] = ($this->deliveries[$position] ?? 0) + 1;
            $received[] = ReceivedMessage::decode($bodies[$position], $this->deliveries[$position], (string) $position);
        }

        return $received;
    }

    /** {@inheritdoc} */
    public function ack(ReceivedMessage $message): void
    {
        unset($this->inFlight[$this->mustBeInFlight($message)]);
        $this->acknowledged++;
    }

    /** {@inheritdoc} */
    public function reject(ReceivedMessage $message, bool $requeue, string $reason): void
    {
        $position = $this->mustBeInFlight($message);
        unset($this->inFlight[$position]);
        if ($requeue) {
            $this->ready[$position] = true;

            return;
        }
        $this->dead[] = [
            'brokerId' => $message->brokerId,
            'messageId' => $message->isValid() ? $message->message()->messageId : null,
            'reason' => MessagingSupport::truncate($reason),
            'deliveryCount' => $message->deliveryCount,
        ];
    }

    /**
     * Gives every delivery in flight back (the consumer died before answering them).
     *
     * @return int Deliveries given back
     */
    public function recoverInFlight(): int
    {
        $count = count($this->inFlight);
        $this->ready += $this->inFlight;
        $this->inFlight = [];

        return $count;
    }

    /**
     * The dead letters of this source, in the order they were rejected.
     *
     * @return list<array{brokerId: string, messageId: ?string, reason: string, deliveryCount: int}>
     */
    public function deadLetters(): array
    {
        return $this->dead;
    }

    /** Deliveries waiting: given back, plus the bodies of the log not yet handed out. */
    public function pendingCount(): int
    {
        return count($this->ready) + count($this->transport->bodiesFrom($this->cursor));
    }

    /** Deliveries in flight (handed out, not answered). */
    public function inFlightCount(): int
    {
        return count($this->inFlight);
    }

    /** Deliveries acknowledged. */
    public function acknowledgedCount(): int
    {
        return $this->acknowledged;
    }

    /** @throws MessagingException */
    private function mustBeInFlight(ReceivedMessage $message): int
    {
        $position = (int) $message->brokerId;
        if ((string) $position !== $message->brokerId || !isset($this->inFlight[$position])) {
            throw new MessagingException(sprintf('Delivery %s is not in flight on this source: it was already answered, or it came from another one.', $message->brokerId));
        }

        return $position;
    }
}
