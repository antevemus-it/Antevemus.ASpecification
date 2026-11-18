<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Messaging\IMessageSource;
use Antevemus\ASpecification\Messaging\InMemory\InMemoryMessageSource;
use Antevemus\ASpecification\Messaging\ReceivedMessage;
use Throwable;

/**
 * The source of Module49FaultyBench (2.15.0, forward 064): InMemoryMessageSource with the fault of the bench planted.
 * REQUEUES_TO_THE_BACK keeps the deliveries it received in a queue of its own, where a requeued one goes last.
 *
 * @version 2.15.0
 */
final class Module49FaultySource implements IMessageSource
{
    /** @var list<ReceivedMessage> REQUEUES_TO_THE_BACK: received from the inner source, not handed out yet */
    private array $queue = [];

    /** @var array<string, ReceivedMessage> handed out and not answered, by broker id */
    private array $held = [];

    public function __construct(public readonly InMemoryMessageSource $inner, private readonly Module49Fault $fault)
    {
    }

    public function receive(int $max, int $waitMs): array
    {
        if ($this->fault === Module49Fault::REQUEUES_TO_THE_BACK) {
            $this->queue = [...$this->queue, ...$this->inner->receive(1000, $waitMs)];
            $deliveries = array_splice($this->queue, 0, $max);
        } else {
            $deliveries = $this->inner->receive($max, $waitMs);
        }
        if ($this->fault === Module49Fault::DROPS_INVALID_BODIES) {
            foreach ($deliveries as $delivery) {
                if (!$delivery->isValid()) {
                    $this->inner->ack($delivery);
                }
            }
            $deliveries = array_values(array_filter($deliveries, static fn(ReceivedMessage $d): bool => $d->isValid()));
        }
        if ($this->fault === Module49Fault::COUNT_STUCK_AT_ONE) {
            $deliveries = array_map(static fn(ReceivedMessage $d): ReceivedMessage => $d->isValid() ? ReceivedMessage::of($d->message(), 1, $d->brokerId) : $d, $deliveries);
        }
        if ($this->fault === Module49Fault::REVERSES_A_RECEIVE) {
            $deliveries = array_reverse($deliveries);
        }
        foreach ($deliveries as $delivery) {
            $this->held[$delivery->brokerId] = $delivery;
        }

        return $deliveries;
    }

    public function ack(ReceivedMessage $message): void
    {
        unset($this->held[$message->brokerId]);
        if ($this->fault === Module49Fault::SILENT_DOUBLE_ACK) {
            try {
                $this->inner->ack($message);
            } catch (Throwable) {
                // The fault: an acknowledgement of a delivery that is not in flight goes unnoticed.
            }

            return;
        }
        $this->inner->ack($message);
    }

    public function reject(ReceivedMessage $message, bool $requeue, string $reason): void
    {
        unset($this->held[$message->brokerId]);
        if ($requeue && $this->fault === Module49Fault::REQUEUES_TO_THE_BACK) {
            // Still in flight on the inner source: it comes again from this queue, last, with the count raised.
            $this->queue[] = $message->isValid() ? ReceivedMessage::of($message->message(), $message->deliveryCount + 1, $message->brokerId) : $message;

            return;
        }
        if (!$requeue && $this->fault === Module49Fault::REASON_CARRIES_THE_PAYLOAD && $message->isValid()) {
            $reason .= ' ' . json_encode($message->message()->payload);
        }
        $this->inner->reject($message, $requeue, $reason);
    }

    /** The process died: what it held goes back (or, with LOSES_IN_FLIGHT_ON_DEATH, is lost). */
    public function die(): void
    {
        $this->queue = [];
        if ($this->fault === Module49Fault::LOSES_IN_FLIGHT_ON_DEATH) {
            foreach ($this->held as $delivery) {
                $this->inner->ack($delivery);
            }
            $this->held = [];

            return;
        }
        $this->held = [];
        $this->inner->recoverInFlight();
    }
}
