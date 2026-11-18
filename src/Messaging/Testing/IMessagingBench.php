<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Messaging\Testing;

use Antevemus\ASpecification\Messaging\IMessageSource;
use Antevemus\ASpecification\Messaging\IMessageTransport;

/**
 * IMessagingBench - One Broker Under the Contract Battery, as a Satellite Wires It
 *
 * The bench of MessagingContractTestCase (forward 064, D2): what the battery needs from a broker to run
 * every scenario of the messaging hook against it. A satellite implements it over its broker (a test server
 * in a service container, a local emulator); the core implements it over its in-memory reference
 * (InMemoryMessagingBench). It is the union of the benches the Redis Streams and RabbitMQ satellites wrote
 * for their own batteries before 2.15.0.
 *
 * Every method is mandatory, and the battery calls every one: there is no capability flag, and a broker
 * that cannot offer one of them (a dead letter queue that can be read, a consumer that recovers the
 * deliveries of a dead one) is not conformant with the hook (D3).
 *
 * Isolation and cleanup: the battery builds one bench per scenario (createBench()) and closes it at the
 * end (close()). A bench is a world of its own: the streams, queues, topics, tables or key prefixes it
 * creates are new (a unique prefix or name per instance), so that no scenario, and no other bench alive at
 * the same time, sees a message of another one (the battery checks it); close() removes what the bench
 * created, even after a failed scenario.
 *
 * Sources: a source belongs to one consumer (a consumer group, a durable queue, a subscription), reads the
 * message names it was declared with, and is run by one member (a process) of that consumer. Every message
 * sent after source() returns reaches it; a consumer never sees the deliveries of another consumer.
 *
 * @version    2.15.0
 * @package    Antevemus\ASpecification
 * @subpackage Messaging\Testing
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IMessagingBench
{
    /** The transport the relay sends with (the broker reachable). */
    public function transport(): IMessageTransport;

    /**
     * A transport whose connection to the broker is lost: every send() throws, or answers every message
     * as failed with a reason, and nothing it was given reaches the broker.
     */
    public function transportWithLostConnection(): IMessageTransport;

    /**
     * A new source: member $member of consumer $consumer, reading the message names $names. Every message
     * of those names sent after this call reaches the consumer.
     *
     * @param string $consumer Name of the consumer (lowercase, as a MessageConsumer takes it)
     * @param list<string> $names Message names the consumer reads
     * @param string $member The member (process) of the consumer that runs this source
     */
    public function source(string $consumer, array $names, string $member = 'm1'): IMessageSource;

    /**
     * The member behind $source died with deliveries in flight: the source of the member that replaces
     * it (another member of the same consumer), which gets those deliveries again, with a higher delivery
     * count, before anything later of their ordering keys. The source given is not used again.
     */
    public function successor(IMessageSource $source): IMessageSource;

    /**
     * The process behind $source died and started again (the same member of the same consumer): the
     * source of the new process, which gets back what the old one left in flight. The source given is not
     * used again.
     */
    public function restarted(IMessageSource $source): IMessageSource;

    /**
     * Stores a body that the transport did not write (another producer on the same stream, queue or topic),
     * where the consumers of the message name $name read: the battery stores bodies that are not a message
     * of format 1, which a source must deliver as ReceivedMessage::invalid(), never drop.
     */
    public function storeRaw(string $name, string $body): void;

    /**
     * The dead letters of the consumer of $source so far, oldest first, read from the broker: the id of
     * the message when its body is one (null otherwise), the reason given to reject() and the delivery
     * count of the delivery dead-lettered.
     *
     * @return list<array{messageId: ?string, reason: string, deliveryCount: int}>
     */
    public function deadLetters(IMessageSource $source): array;

    /**
     * The longest wait, in milliseconds, the battery passes to receive() and consume(): 0 for a broker whose
     * deliveries can be read as soon as send() returns; the time a sent message takes to become visible
     * otherwise. Within it, a receive returns every delivery available, up to its maximum.
     */
    public function receiveWaitMs(): int;

    /** Removes what the bench created (called at the end of every scenario, failed ones included). */
    public function close(): void;
}
