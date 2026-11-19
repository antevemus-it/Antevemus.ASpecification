# Messaging Between Modules: Outbox, Relay, Consumer and Inbox

### 26. Publishing a Fact and Relaying It to a Broker (Module 48)

Since v2.14.0 the core has a hook for messages between modules, in `Antevemus\ASpecification\Messaging`: one module publishes a fact with data ("a quote was sent for analysis", "a message arrived in the live chat") and the others consume it through a broker, without one module calling another. The core ships no broker client: the transports live in satellite packages, [Redis Streams](https://github.com/antevemus-it/Antevemus.ASpecificationRedisStreams) and [RabbitMQ](https://github.com/antevemus-it/Antevemus.ASpecificationRabbitMQ) (both v1.0.0), behind two interfaces of the core, `IMessageTransport` (out) and `IMessageSource` (in), and the core has an in-memory implementation of both, the reference and the test double.

The application never talks to the broker. It writes the message to an **outbox** table, in the same transaction as the write that produced the fact, and a **relay** sends the outbox to the broker after the commit, marking delivered only what the broker confirmed. There is no second path, so there is no dual write: the fact and its message commit or roll back together.

```php
use Antevemus\ASpecification\Messaging\InMemory\InMemoryMessageTransport;
use Antevemus\ASpecification\Messaging\Message;
use Antevemus\ASpecification\Messaging\MessagePublisher;
use Antevemus\ASpecification\Messaging\Outbox\PdoMessageOutbox;
use Antevemus\ASpecification\Messaging\OutboxRelay;
use Antevemus\ASpecification\Persistence\PdoTransactionManager;

// $pdo: the tables of resources/sql/messaging.ansi.sql, next to the tables of the application
$transactions = new PdoTransactionManager($pdo);
$publisher = new MessagePublisher(new PdoMessageOutbox($pdo));

$transactions->transactional(function () use ($pdo, $publisher): void {
    $pdo->exec("UPDATE quote SET status = 'submitted' WHERE id = 123"); // the write of the application
    $publisher->publish(new Message(
        'underwriting.quote_submitted.v1',
        ['quote_id' => 123, 'premium' => 1250.0],
        orderingKey: 'quote:123',
        tenant: 'acme'
    ));
});

$transport = new InMemoryMessageTransport(); // in production, the transport of a satellite (Redis Streams, RabbitMQ)
$relay = new OutboxRelay(new PdoMessageOutbox($pdo), $transport, maxAttempts: 10);

$sent = $relay->relayPending(100)->deliveredCount();  // 1: confirmed by the broker, marked delivered
$again = $relay->relayPending(100)->deliveredCount(); // 0: the relay does not send it twice
```

- **The message** (`Message`, `final readonly`): a **versioned name** in lowercase, `module.fact.vN` (`livechat.message_received.v1`; a breaking change of the payload is a new name, `.v2`, consumed side by side with the old one); a **payload** that JSON carries without loss (scalars, arrays and null, finite floats, UTF-8, at most 64 levels), at most 65 535 bytes encoded, the size of a `TEXT` column of MySQL and MariaDB; a ULID `messageId` (generated) and `idempotencyKey()` = `msg:` + the id; `occurredAt` in UTC to the microsecond; an optional `orderingKey` (`quote:123`), the queue of order; an optional `tenant`; at most 32 string `headers` for tracing. `toArray()`/`fromArray()` and `toJson()`/`fromJson()` are the wire form, format version `v: 1`, read back without loss; a document of another version, or with an unknown key, is refused.
- **The outbox** (`IMessageOutbox`, with `PdoMessageOutbox` over `message_outbox` and `InMemoryMessageOutbox`, the same contract): one row per message id (publishing the same message twice stores one row), the order of publication within an ordering key, the attempts and the last reason of each send the broker did not confirm. `PdoMessageOutbox` takes a table name, a schema and a column map (`columns: [canonical => real]`), as the other PDO stores of the library.
- **The relay** (`OutboxRelay::relayPending($limit)`) reads the head of each ordering key, sends at most `batchSize` of them in one call of the transport (so a batch holds at most one message per key, and the broker stores the messages of a key in the order of publication), marks delivered what the transport confirmed and failed what it did not (a refusal, a confirmation that never came, a whole send that threw), and holds a failed key back for the rest of the run. With `maxAttempts`, a message that reached the limit is exhausted, no longer sent and no longer holding its key back, and the report says so (`getExhausted()`). Delivery is **at least once**: a process that dies between the confirmation and the mark sends the message again on the next run, and the consumers deduplicate it.
- **The transport** (`IMessageTransport::send(list<Message>): TransportSendResult`) answers message by message whether the broker confirmed it, meaning "stored it durably" (an `XADD` answered by Redis, a publisher confirm of RabbitMQ), or why it failed. `InMemoryMessageTransport` stores the wire form in a log and can be told to fail a message, lose its confirmation, store it twice, reorder a batch across ordering keys or fail a whole batch, the faults a real broker has.

### 26.1. Consuming, Once per Consumer

`MessageConsumer` receives deliveries from an `IMessageSource` and hands each message to the handlers of its name (`MessageHandlerRegistry`, a handler is `fn(Message): void`). An **inbox** (`IProcessedMessageStore`, with `PdoProcessedMessageStore` over `message_inbox` and `InMemoryProcessedMessageStore`) records, in the same transaction as the handlers, that this consumer handled this message: a message delivered twice runs the handlers once per consumer, the pattern of the idempotent consumer.

```php
use Antevemus\ASpecification\Messaging\Inbox\PdoProcessedMessageStore;
use Antevemus\ASpecification\Messaging\InMemory\InMemoryMessageSource;
use Antevemus\ASpecification\Messaging\MessageConsumer;
use Antevemus\ASpecification\Messaging\MessageHandlerRegistry;

$handlers = (new MessageHandlerRegistry())->register(
    'underwriting.quote_submitted.v1',
    function (Message $message) use ($pdo): void {
        $insert = $pdo->prepare('INSERT INTO agent_task (quote_id, kind) VALUES (?, ?)');
        $insert->execute([$message->payload['quote_id'], 'review_quote']);
    }
);
$consumer = new MessageConsumer(
    'agents',                              // the consumer: its own rows of the inbox
    new InMemoryMessageSource($transport), // in production, a consumer group of a stream, a queue of RabbitMQ
    $handlers,
    new PdoProcessedMessageStore($pdo),
    $transactions,                         // the inbox row and the INSERT of the handler commit together
    maxDeliveries: 5,
    tenant: 'acme'                         // only the messages of this tenant
);

$report = $consumer->consume(100, waitMs: 1000);
$handled = count($report->handledIds());                                     // 1
$tasks = (int) $pdo->query('SELECT COUNT(*) FROM agent_task')->fetchColumn(); // 1

$transport->send([(new PdoMessageOutbox($pdo))->findByOrderingKey('quote:123')[0]->message]); // the broker delivers it again
$duplicate = $consumer->consume(100)->entries()[0]->outcome;                 // ConsumeOutcome::DUPLICATE: the handler did not run
```

- **Each delivery** gets one outcome in the `ConsumeReport` (`ConsumeOutcome`): `handled` (the handlers and the inbox row committed, then acknowledged); `duplicate` (the inbox already had it); `unknown_name` (no handler for the name: acknowledged, with a warning in `warnings()`, because the consumer does not want it); `other_tenant` (the consumer is restricted to a tenant and the message is of another one, or of none: acknowledged without handling); `retried` (a handler threw: rolled back and rejected with requeue); `held_back` (an earlier message of the same ordering key was retried in this run: requeued without running, so the key keeps its order); `dead_lettered` (rejected without requeue, to the dead letter queue of the broker).
- **The transaction.** With a transaction manager over the connection of the handlers' writes (and of the inbox), the inbox row is written first and the handlers run in the same transaction: the effect is exactly once per consumer. Without one, the handlers run first and the row after them, without atomicity: a process that dies between the two runs the handlers again on the redelivery.
- **The source** (`IMessageSource`: `receive($max, $waitMs)`, `ack()`, `reject($requeue, $reason)`) hands out `ReceivedMessage`s: the message rebuilt from the wire form, the delivery count, the id the broker gave it. A delivery rejected with requeue comes back before any later delivery of the same key; something the broker delivered that is not a message is an invalid delivery, never dropped. `InMemoryMessageSource` reads the log of an `InMemoryMessageTransport` with the semantics of a consumer group of a Redis stream: every source sees every message, deliveries stay in flight until answered, and `recoverInFlight()` gives them back as a dead consumer would.
- **The order** of an ordering key holds from the publication to the handler, for one consumer per source (competing consumers on one queue keep it only per consumer, as in any broker), except for a message that went to the dead letter queue, which the report declares.

### 26.2. Failures, Redeliveries and the Dead Letter Queue

A handler that throws is retried: the transaction rolls back and the delivery is rejected with requeue, up to `maxDeliveries`. A handler that knows retrying will not help throws `InvalidMessagePayloadException`, and the message goes to the dead letter queue at once.

```php
use Antevemus\ASpecification\Messaging\ConsumeEntry;
use Antevemus\ASpecification\Messaging\Exceptions\InvalidMessagePayloadException;

$handlers->register('crm.lead_created.v1', function (Message $message): void {
    if (!isset($message->payload['lead_id'])) {
        throw new InvalidMessagePayloadException('lead_id is required'); // retrying would not change the answer
    }
});
$transactions->transactional(fn(): int => $publisher->publish(
    new Message('crm.lead_created.v1', ['name' => 'Ana'], orderingKey: 'lead:9', tenant: 'acme'),
    new Message('crm.lead_created.v1', ['lead_id' => 9, 'name' => 'Ana'], orderingKey: 'lead:9', tenant: 'acme'),
));
$relay->relayPending(100);

$report = $consumer->consume(100);
$outcomes = array_map(fn(ConsumeEntry $entry): string => $entry->outcome->value, $report->entries()); // ['dead_lettered', 'handled']
$reason = $report->entries()[0]->reason;
// A handler of consumer "agents" failed for message "crm.lead_created.v1" (id 01..., ordering key "lead:9")
// (InvalidMessagePayloadException) on delivery 1 of at most 5; the payload was refused.
```

- **maxDeliveries.** A handler that fails on the last allowed delivery sends the message to the dead letter queue; a delivery whose count is already above the limit (a message that kills the process before it answers) is dead-lettered without running. A dead-lettered message unblocks its key: the next message of the key is handled, here at once.
- **Secrecy.** No reason, report or exception of the hook carries the payload: they name the message by its name, id and ordering key, and the consumer and the class of the exception of a handler, never its message, which may quote the payload. The PDO stores report a failed statement by the operation, the table, the SQLSTATE and the driver code, without the message of the driver, because PostgreSQL quotes the failing row. Give the consumer `onHandlerError: fn(Message $message, Throwable $error, int $deliveryCount)` to log the exception itself.
- **What does not belong to a message** stops the run and leaves the rest in flight, to be delivered again: a failure of the broker on an acknowledgement, a failure of the inbox outside a handler. The exception is a race of two processes of the same consumer on the inbox: the delivery is requeued, and its next delivery finds it handled.

### 26.3. Domain Events Out, Messages Into a State Machine

Two ready-made bridges connect the messages to the rest of the library. `DomainEventMessageBridge` is a handler of domain events (examples 18 and 18.1) that publishes each one as a message, so that the triggers of a specification ("an invoice became overdue") reach the other modules by the same path. `MessageMachineBridge` is a handler of messages that sends a machine event to a `MachineRunner` (example 14), so that a fact of another module moves a process of this one.

```php
use Antevemus\ASpecification\DomainEvents\DomainEvent;
use Antevemus\ASpecification\DomainEvents\DomainEventDispatcher;
use Antevemus\ASpecification\DomainEvents\DomainEventHandlerRegistry;
use Antevemus\ASpecification\Messaging\DomainEventMessageBridge;
use Antevemus\ASpecification\Messaging\MessageMachineBridge;

// Out: $domainOutbox holds the domain events of the trigger invoice.is_overdue
$domainHandlers = (new DomainEventMessageBridge(
    $publisher,
    ['invoice.overdue' => 'billing.invoice_overdue.v1'], // domain event name => message name
    fn(DomainEvent $event): ?string => 'acme'           // the tenant of an event
))->registerIn(new DomainEventHandlerRegistry());

// In: the message starts the collection process of the invoice ($runner: a MachineRunner)
(new MessageMachineBridge(
    $runner,
    ['billing.invoice_overdue.v1' => 'START_COLLECTION'],
    fn(Message $message): ?string => 'collection-' . $message->payload['entity_id']
))->registerIn($handlers);

$dispatched = (new DomainEventDispatcher($domainOutbox, $domainHandlers))->dispatchPending()->deliveredCount(); // 1
$relay->relayPending(100);
$consumer->consume(100);
$state = $runner->load('collection-77')->snapshot->stateOf('collection'); // 'collecting'
```

- **From a domain event**, the message has the mapped name (or the event's own, when it is already `module.fact.vN`), a payload with `trigger`, `kind`, `entity_class` and `entity_id`, never the entity, the queue of the entity as its ordering key (`App\Invoice:77`), and an id derived from the idempotency key of the event: a redelivery of the event by the dispatcher publishes the same message id, which the outbox skips. The header `domain-event-key` carries the key of the event.
- **Into the machine**, the event has the instant of the message, its payload as is, the EXTERNAL origin by default and the idempotency key of the message, so a redelivery returns the original result of the runner and does not move the instance again. The tenant of the message is handed to the runner (`scopeByTenant`, true by default), which then loads the instance of that tenant only.
- **Out of scope**: exactly-once delivery between processes (the design is at least once plus the inbox), Kafka, NATS and SQS (other satellites, on demand), and the effects of the state machine relayed by the broker (an effect handler may publish a `Message`).

### 26.4. Running the Relay: One at a Time

Run `relayPending()` from a worker or a cron, outside any transaction of the application. **One relay per outbox, or a lock.** Two relays over the same outbox keep the order of the first delivery of each ordering key, because a message is a head only once the earlier ones of its key were confirmed. They still cost two things: a relay that read an entry before the other one marked it sends it again, after the later messages of its key (a late duplicate, which an inbox absorbs only while it keeps the row), and both count the attempt, so `maxAttempts` runs out early. Since v2.16.0 a lock keeps the relays of an outbox to one at a time.

```php
use Antevemus\ASpecification\Persistence\PdoLeaseLock;
use DateTimeImmutable;

$lock = new PdoLeaseLock($pdo); // the table lease_lock: one row per lock name
$relay = new OutboxRelay(
    new PdoMessageOutbox($pdo),
    $transport,
    maxAttempts: 10,
    lock: $lock,
    leaseSeconds: 60,                                      // longer than the slowest batch
    lockOwner: 'relay@' . gethostname() . ':' . getmypid() // who holds it, for whoever reads the table
);
$name = $relay->getLockName(); // 'messaging.outbox_relay:message_outbox'

$transactions->transactional(fn(): int => $publisher->publish(
    new Message('underwriting.quote_approved.v1', ['quote_id' => 123], orderingKey: 'quote:123', tenant: 'acme')
));

$lock->tryAcquire($name, 'relay@other-host:4242', new DateTimeImmutable(), 60); // another process holds the lock
$denied = $relay->relayPending(100)->isLockDenied();                            // true: nothing was sent
$lock->release($name, 'relay@other-host:4242');

$report = $relay->relayPending(100); // takes the lock, renews it before each batch, releases it at the end
$sent = $report->deliveredCount();   // 1
$lost = $report->isLockLost();       // false: no other relay took the lock over
```

- **Without a lock** the relay is the one of v2.15.0: run exactly one per outbox (one cron entry, one replica of the worker).
- **With a lock** (`ILeaseLock`, in `Antevemus\ASpecification\Persistence`: `PdoLeaseLock` over the table `lease_lock` of `resources/sql/lease_lock.ansi.sql`, or `InMemoryLeaseLock` for the relays of one process), `relayPending()` takes it under its name before its first batch; when another relay holds it, the run sends nothing and the report says so (`isLockDenied()`). It renews the lock before every further batch, before reading the heads, and stops when the renewal fails (`isLockLost()`: its lease ran out and another relay took over). It releases the lock at the end, also when the run throws.
- **The name.** One lock store serves every dispatcher of the application: the relay, the dispatcher of the domain events ([example 18.2](../events/from-specifications.md)) and the one of the effects of the state machine ([example 14.8](../state-machine/index.md)) take the same `PdoLeaseLock`, each under its own name, so two outboxes never hold each other back. The default name is the kind of the dispatcher and the table of its outbox (`messaging.outbox_relay:message_outbox`; `getLockName()`); every relay process of one outbox gets the same one, and `lockName:` gives another.
- **The lease** (`leaseSeconds`, 60 by default) is how long a relay that died keeps the others out. Choose it longer than the slowest batch, the time the transport may take to answer included, and than the skew between the clocks of the machines (each relay compares its own instants). A relay paused for longer than its lease may resume while another one works: what it had sent may then reach the broker twice (still at least once), and a failure mark never turns a delivered entry back into a failed one, so a delivered message is not sent again.
- **The owner** (`lockOwner`) names the relay in the table; without it, each instance gets a new ULID. Give every process its own (the host and the pid). Owners and lock names that differ only by case are the same on MySQL and MariaDB.
- **The connection** may be the one of the outbox. Take the lock outside a long transaction, so the other relays see it at once (on MySQL and MariaDB the conditional `UPDATE` of a transaction also holds a gap lock that makes another relay wait for its commit); when the connection is in a transaction anyway, the first acquisition inserts the row inside a savepoint, so a lost race does not abort the transaction of the caller (on PostgreSQL a failed statement would).
- **Acquiring is portable**, without `SKIP LOCKED`: a conditional `UPDATE` of the row of the lock (free, past its lease, or already the caller's), and an `INSERT` protected by the primary key when there is no row. The database serializes two of them, on SQLite, PostgreSQL, MySQL and MariaDB.

### 26.5. Retention of the Outbox

The outbox keeps every entry, delivered or not. `PdoMessageOutbox` and `InMemoryMessageOutbox` implement `IMessageOutboxRetention`: `purgeDeliveredBefore($before, $limit = 1000)` removes at most `$limit` delivered entries whose delivery is older than `$before`, the oldest delivery first, and returns how many it removed. A separate interface, because a method added to `IMessageOutbox` would break the implementations outside the library.

```php
use Antevemus\ASpecification\Messaging\Outbox\IMessageOutboxRetention;

// A daily job: the delivered entries older than the window of the outbox, 1 000 at a time
$purge = function (IMessageOutboxRetention $outbox, DateTimeImmutable $before): int {
    $total = 0;
    do {
        $purged = $outbox->purgeDeliveredBefore($before, 1000);
        $total += $purged;
    } while ($purged === 1000);

    return $total;
};

$purged = $purge(new PdoMessageOutbox($pdo), new DateTimeImmutable('-7 days')); // 2: the deliveries of last year
$left = (new PdoMessageOutbox($pdo))->count();                                // 1: the pending message stays

// The inbox of each consumer keeps its rows longer: the window of the outbox plus the longest delay of the relay
(new PdoProcessedMessageStore($pdo))->purgeProcessedBefore('agents', new DateTimeImmutable('-8 days'));
```

- **Only delivered entries.** A pending or failed entry, exhausted or not, is never removed: it is a message that did not reach the broker, a dead message on the side of the producer, kept for an investigation.
- **In batches, portably.** The ids of a batch are read with the row limit of the dialect and deleted by list, 500 per `DELETE` (MySQL refuses a `LIMIT` inside an `IN` subquery, and older SQLite builds take at most 999 parameters), so one call never becomes a huge `DELETE`. The lookup is served by the index `uq_mo_delivered` (`status`, `delivered_at`, `entry_id`) of the reference DDL; for a table created before v2.16.0 the header of the DDL has its `CREATE UNIQUE INDEX`, optional: the purge is correct without it, only slower on a large outbox.
- **The windows.** `enqueue()` skips a message id only while its row exists: after the purge, a producer that writes the same message id again publishes it again. So:
  - the window of the outbox must be longer than any retry of a producer that may write the same message again (a domain event redelivered by its dispatcher, a job retried by its queue);
  - the retention of the inbox of each consumer (`purgeProcessedBefore()`) must be at least the window of the outbox plus the longest delay of the relay, so that a copy sent late still finds its row.

**Reference DDL:** `resources/sql/messaging.ansi.sql` (`message_outbox`, `message_inbox`, `CREATE TABLE IF NOT EXISTS`, portable to SQLite, PostgreSQL, MySQL and MariaDB; on MySQL and MariaDB the tables need `utf8mb4`). Nothing else of the library reads or writes these tables: a database without them answers as on 2.13.0. The lock of the relay is in `resources/sql/lease_lock.ansi.sql` (`lease_lock`, since v2.16.0), needed only with a lock.

## Broker satellites

The hook knows no broker: the relay calls `send()` and reads the confirmations it returns, and the consumer calls `receive()`, `ack()` and `reject()`; the core never references a broker client. Two satellites implement both interfaces, each tested against a real broker in its own suite, and neither needs a PHP extension:

- [`antevemus/aspecification-redis-streams`](https://github.com/antevemus-it/Antevemus.ASpecificationRedisStreams) (`RedisStreamsTransport`, `RedisStreamsSource`, over predis): one stream per message name, one consumer group per consumer, the pending entries of a dead process reclaimed with `XAUTOCLAIM`, a dead letter stream; never Pub/Sub. It needs Redis 7.0 or later.
- [`antevemus/aspecification-rabbitmq`](https://github.com/antevemus-it/Antevemus.ASpecificationRabbitMQ) (`RabbitMqTransport`, `RabbitMqSource`, over php-amqplib): a topic exchange with publisher confirms and the mandatory flag, a durable quorum queue per consumer with manual acknowledgements and the delivery count of the broker, a dead letter exchange.

Since v2.15.0 the contract battery is public and ships with the core: `MessagingContractTestCase`, in `Antevemus\ASpecification\Messaging\Testing`, runs every scenario of the hook against an `IMessagingBench` over a broker, and the core runs it against its in-memory reference and against a didactic SQLite satellite (Module 49). A satellite for another broker (Kafka, SQS, Azure Service Bus, Google Cloud Pub/Sub, NATS JetStream) implements `IMessageTransport` and `IMessageSource` and passes that battery in its own suite; it touches neither the core nor the two satellites above. [Writing a satellite](writing-a-satellite.md) shows how, item by item of the contract, with the mapping of each item to the primitives of those brokers and the didactic satellite in full (examples 27 to 27.4).
