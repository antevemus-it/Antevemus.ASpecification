# Messaging Between Modules: Outbox, Relay, Consumer and Inbox

### 26. Publishing a Fact and Relaying It to a Broker (Module 48)

Since v2.14.0 the core has a hook for messages between modules, in `Antevemus\ASpecification\Messaging`: one module publishes a fact with data ("a quote was sent for analysis", "a message arrived in the live chat") and the others consume it through a broker, without one module calling another. The core ships no broker client: the transports live in satellite packages (Redis Streams, RabbitMQ), behind two interfaces of the core, `IMessageTransport` (out) and `IMessageSource` (in), and the core has an in-memory implementation of both, the reference and the test double.

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

**Reference DDL:** `resources/sql/messaging.ansi.sql` (`message_outbox`, `message_inbox`, `CREATE TABLE IF NOT EXISTS`, portable to SQLite, PostgreSQL, MySQL and MariaDB; on MySQL and MariaDB the tables need `utf8mb4`). Nothing else of the library reads or writes these tables: a database without them answers as on 2.13.0.
