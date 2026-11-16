<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Messaging;

/**
 * IMessageTransport - The Hook Out: Hands a Batch of Messages to a Broker
 *
 * Called by OutboxRelay only, after the commit, never by the application (RN-02). Implemented by the
 * satellite packages (Redis Streams, RabbitMQ) and, in the core, by InMemoryMessageTransport, the
 * reference implementation and test double (forward 063, D5).
 *
 * Contract:
 * - send() answers, message by message, whether the broker CONFIRMED it, which means "stored it
 *   durably" (an XADD answered by Redis; a publisher confirm, `basic.ack`, from RabbitMQ), or why it
 *   failed. A message of the batch the result says nothing about is taken as not confirmed.
 * - Only a confirmed message is marked delivered by the relay; anything else is sent again later.
 *   Delivery is at-least-once: a message confirmed by the broker whose confirmation was lost is sent
 *   again, and the consumers deduplicate it (their inbox).
 * - The batch holds at most one message per ordering key, so the broker may store it in any order;
 *   a transport needs no ordering of its own.
 * - Every message carries its tenant, which the transport keeps in a header or property of the broker
 *   message so that a consumer can route or filter on it (D10); the body is Message::toJson().
 * - A reason, and the message of an exception thrown by send() (the whole batch then fails), never
 *   carries a value of the payload: it names the message (Message::describe()) and the cause (RN-05).
 *
 * @version    2.14.0
 * @package    Antevemus\ASpecification
 * @subpackage Messaging
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IMessageTransport
{
    /**
     * Sends a batch and reports, per message, the confirmation of the broker.
     *
     * @param list<Message> $batch Messages to send (at least one; at most one per ordering key)
     * @return TransportSendResult Confirmed and failed message ids
     * @throws \Throwable When the batch could not be sent at all (every message fails)
     */
    public function send(array $batch): TransportSendResult;
}
