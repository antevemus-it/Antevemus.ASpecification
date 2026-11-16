<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Messaging;

/**
 * IMessageSource - The Hook In: Deliveries of a Broker to One Consumer
 *
 * Read by MessageConsumer (forward 063, D7). Implemented by the satellite packages (a consumer group
 * of a Redis stream; a durable queue of RabbitMQ) and, in the core, by InMemoryMessageSource, which
 * reads what an InMemoryMessageTransport stored.
 *
 * Contract:
 * - receive() returns at most $max deliveries, waiting at most $waitMs milliseconds for the first
 *   one (0 = do not wait). A delivery stays in flight until ack() or reject(); a consumer that dies
 *   before either gets it delivered again (at-least-once), with a higher deliveryCount.
 * - Order: deliveries of one ordering key come in the order the broker stored them, and a delivery
 *   rejected with requeue comes again BEFORE any later delivery of the same key (Redis: the pending
 *   entries of the consumer are read before new ones; RabbitMQ: a requeued message keeps its place).
 *   Order holds for one consumer per source: competing consumers on one queue, as in any broker,
 *   keep it only per consumer.
 * - ack() removes the delivery for good. reject($requeue = true) gives it back to be delivered again;
 *   reject($requeue = false) moves it to the dead letter queue of the broker with the reason (a
 *   stream `<prefix>.dead`; a dead letter exchange).
 * - What the broker delivered and cannot be read back as a Message (not the JSON of format 1, a
 *   payload larger than the limit) is returned as ReceivedMessage::invalid(), never dropped.
 * - A reason never carries a value of the payload (RN-05).
 *
 * @version    2.14.0
 * @package    Antevemus\ASpecification
 * @subpackage Messaging
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IMessageSource
{
    /**
     * Takes up to $max deliveries.
     *
     * @param int $max Maximum number of deliveries (>= 1)
     * @param int $waitMs Longest wait for the first delivery, in milliseconds (>= 0)
     * @return list<ReceivedMessage>
     */
    public function receive(int $max, int $waitMs): array;

    /**
     * Acknowledges a delivery: it will not come again.
     */
    public function ack(ReceivedMessage $message): void;

    /**
     * Rejects a delivery: back to the queue ($requeue) or to the dead letter queue with the reason.
     *
     * @param string $reason Why (recorded with a dead letter; never a value of the payload)
     */
    public function reject(ReceivedMessage $message, bool $requeue, string $reason): void;
}
