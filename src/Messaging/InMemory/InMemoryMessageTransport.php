<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Messaging\InMemory;

use Antevemus\ASpecification\Messaging\Exceptions\MessagingException;
use Antevemus\ASpecification\Messaging\IMessageTransport;
use Antevemus\ASpecification\Messaging\Message;
use Antevemus\ASpecification\Messaging\TransportSendResult;
use Closure;
use InvalidArgumentException;
use Random\Randomizer;

/**
 * InMemoryMessageTransport - A Broker in Memory: the Reference Transport and a Test Double
 *
 * The reference implementation of IMessageTransport (forward 063, D5, D13): an append-only log of
 * the wire form of each message (Message::toJson()), the durable storage of a broker; a message is
 * confirmed once it is in the log, and its broker id is its position. InMemoryMessageSource reads
 * the log, one source per consumer (every source sees every message, as every consumer group of a
 * Redis stream, or every queue bound to a RabbitMQ exchange, does), so that a publish → relay →
 * consume round trip runs in memory.
 *
 * As a test double it can be told to misbehave the way a real broker does:
 * - withFaults(fn(Message): InMemoryTransportFault): per message, fail it, lose its confirmation
 *   (stored, not confirmed: sent again later, so stored twice) or store it twice;
 * - reorderAcrossKeys(Randomizer): store each batch in a random order, keeping the relative order
 *   of the messages of one ordering key (the only reordering a broker may do with what the relay
 *   sends: a batch holds at most one message per key);
 * - failNextBatch(reason): the next send() throws, as a lost connection does.
 * Its reasons name the message (Message::describe()), never its payload (RN-05).
 *
 * @version    2.14.0
 * @package    Antevemus\ASpecification
 * @subpackage Messaging\InMemory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class InMemoryMessageTransport implements IMessageTransport
{
    /** @var list<string> The log: the body of each stored delivery, in storage order */
    private array $log = [];

    /** @var (Closure(Message): InMemoryTransportFault)|null */
    private ?Closure $faults = null;

    private ?Randomizer $reorder = null;

    private ?string $failNextBatch = null;

    private int $batches = 0;

    /** {@inheritdoc} */
    public function send(array $batch): TransportSendResult
    {
        $this->batches++;
        if ($this->failNextBatch !== null) {
            $reason = $this->failNextBatch;
            $this->failNextBatch = null;
            throw new MessagingException($reason);
        }
        $result = new TransportSendResult();
        foreach ($this->storageOrder(array_values($batch)) as $message) {
            $fault = $this->faults === null ? InMemoryTransportFault::NONE : ($this->faults)($message);
            if ($fault === InMemoryTransportFault::FAIL) {
                $result = $result->withFailed($message->messageId, sprintf('The in-memory broker refused %s (injected fault).', $message->describe()));
                continue;
            }
            $this->log[] = $message->toJson();
            $position = (string) (count($this->log) - 1);
            if ($fault === InMemoryTransportFault::DUPLICATE) {
                $this->log[] = $message->toJson();
            }
            if ($fault !== InMemoryTransportFault::LOSE_CONFIRM) {
                $result = $result->withConfirmed($message->messageId, $position);
            }
        }

        return $result;
    }

    /**
     * Injects a fault per message (null = none).
     *
     * @param (Closure(Message): InMemoryTransportFault)|null $faults
     */
    public function withFaults(?Closure $faults): self
    {
        $this->faults = $faults;

        return $this;
    }

    /** Stores each batch in a random order across ordering keys (null = in the order given). */
    public function reorderAcrossKeys(?Randomizer $random): self
    {
        $this->reorder = $random;

        return $this;
    }

    /** Makes the next send() throw a MessagingException with this reason. */
    public function failNextBatch(string $reason): self
    {
        $this->failNextBatch = $reason;

        return $this;
    }

    /**
     * Stores a body that is not the wire form of a message (another producer writing to the same
     * stream), for the consumer's handling of invalid deliveries.
     */
    public function storeRaw(string $body): self
    {
        $this->log[] = $body;

        return $this;
    }

    /**
     * The bodies of the log from a position on (read by InMemoryMessageSource).
     *
     * @return array<int, string> position => body
     */
    public function bodiesFrom(int $position): array
    {
        return array_slice($this->log, $position, null, true);
    }

    /**
     * Every message of the log, in storage order (a duplicate appears twice; raw bodies are left out).
     *
     * @return list<Message>
     */
    public function stored(): array
    {
        $messages = [];
        foreach ($this->log as $body) {
            try {
                $messages[] = Message::fromJson($body);
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return $messages;
    }

    /** Number of bodies in the log. */
    public function storedCount(): int
    {
        return count($this->log);
    }

    /** Number of send() calls, the failed ones included. */
    public function batchCount(): int
    {
        return $this->batches;
    }

    /**
     * @param list<Message> $batch
     * @return list<Message>
     */
    private function storageOrder(array $batch): array
    {
        if ($this->reorder === null || count($batch) < 2) {
            return $batch;
        }
        // Shuffle the slots, then give each key its own messages back in their order.
        $slots = $this->reorder->shuffleArray(array_keys($batch));
        $byKey = [];
        foreach ($batch as $i => $message) {
            $byKey[$message->orderingKey ?? "\0" . $i][] = $message;
        }
        $ordered = [];
        foreach ($slots as $slot) {
            $key = $batch[$slot]->orderingKey ?? "\0" . $slot;
            $ordered[] = array_shift($byKey[$key]);
        }

        return $ordered;
    }
}
