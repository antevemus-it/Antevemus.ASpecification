# Writing a Broker Satellite: the Contract Battery and a Worked Example

A satellite connects the messaging hook of the core (the [messaging page](index.md), examples 26 to 26.5) to one broker: it implements `IMessageTransport` (what `OutboxRelay` sends with) and `IMessageSource` (what `MessageConsumer` reads), in a package of its own, and the core stays without any broker client. Since v2.15.0 the core ships the **contract battery** those two interfaces must pass, in `Antevemus\ASpecification\Messaging\Testing`: a satellite extends `MessagingContractTestCase` in its own PHPUnit suite, answers it with an `IMessagingBench` over its broker, and runs every scenario the hook promises. The battery is the contract of the version of the core that ships it.

This page walks through it with a **didactic satellite over SQLite tables** (examples 27.1 to 27.4): a "broker" written in a few dozen lines of PDO, which passes the whole battery in the core's own suite (Module 49). It is there to show what each method has to do; it is **not a broker for production** (no network, one process, no retention, no throughput).

## What a satellite implements, and what it never does

- **`IMessageTransport::send(list<Message>): TransportSendResult`**: stores each message durably in the broker, the body being `Message::toJson()` and the tenant kept in a header or property beside it, and answers message by message whether the broker **confirmed** it (stored it durably), with the broker's id when it gives one, or why it failed. A send that cannot reach the broker throws, and the whole batch fails. A batch holds at most one message per ordering key, so the transport needs no ordering of its own.
- **`IMessageSource`**: `receive($max, $waitMs)` returns up to `$max` deliveries (`ReceivedMessage`), waiting at most `$waitMs` for the first; a delivery stays in flight until `ack()` (removed for good) or `reject($requeue, $reason)` (back to the queue, or to the dead letter queue with the reason).
- **An `IMessagingBench`** for the battery (example 27.3): a world of its own per instance, and the operations of a real deployment the battery needs to provoke (a lost connection, a dead consumer, a restarted process, a body written by another producer, the dead letter queue read back).

Never:

- **mark a message confirmed before the broker stored it** (an XADD answered, a publisher confirm, an HTTP 200 whose body lists the entry as successful): the relay marks delivered what you confirm, and a confirmed message that the broker lost is lost for good;
- **drop what the broker delivered and is not a message** (another producer, a truncated body, a format of another version): hand it to `ReceivedMessage::decode()`, which turns it into an invalid delivery that the consumer dead-letters with a reason;
- **put a value of the payload in a reason or an exception message**: name the message (`Message::describe()`, `ReceivedMessage::describe()`), the broker and the cause; the message of a driver or an SDK may quote the payload, so wrap it and keep its code, not its text;
- **acknowledge on the broker by yourself** (auto-ack, receive-and-delete): only `ack()` and `reject()` answer a delivery, after the consumer committed or decided.

## The contract, item by item

Each line is a promise of `IMessageTransport` or `IMessageSource` and the scenarios of the battery that check it. What the battery compares is what the contract promises: the order **within** an ordering key, and the multiset of the rest. The order between two keys is not part of the contract (a Kafka partition, an SQS message group, a stream per message name deliver keys in any interleaving), so the battery never asserts it on deliveries.

| Contract item | What the battery checks |
|---|---|
| Confirmation per message; only what was stored is confirmed | the round trip; the lost connection (nothing stored, every message failed with a reason, sent again when the broker is back) |
| The message comes out as it went in (id, name, instant to the microsecond, key, tenant, headers, payload up to 65 535 bytes) | the round trip; the largest payload, byte for byte |
| Every consumer gets every message; a consumer gets only its own | every consumer gets every message; each bench is a world of its own |
| The order of an ordering key, across message names and across batches of the relay | the order across names; across batches; batches of mixed keys; a batch of many keys |
| A delivery rejected with requeue comes back **before** any later delivery of its key | requeued in one run; with deliveries left behind; one delivery at a time |
| The delivery count rises with each delivery; the consumer dead-letters after `maxDeliveries` | the message that keeps failing (`retried 1`, `retried 2`, `dead_lettered 3`) |
| `reject($requeue = false)` keeps the message **with the reason** in a dead letter queue the bench can read | the dead letter after `maxDeliveries`; the invalid bodies |
| What is not a message is an invalid delivery, never dropped | a body that is not a message (`not json at all`, a document of format 2) |
| A dead consumer's deliveries in flight go to its successor; a restarted process gets back what it left in flight; the count rises | the dead consumer and its successor (twice); the process started again |
| At least once: copies stored by the broker, a relay that dies between the confirmation and the mark | the duplicate; the relay that dies (twice), each absorbed by the inbox, the effect once |
| Answering a delivery that is not in flight throws `MessagingException`; no reason, report nor exception carries the payload | the secrecy scenario (a secret planted in every payload, through every path) |
| All of it under random interleavings | the property sequence: six fixed seeds, at least 150 steps each, of publications, relays that die or lose the connection, consumers that die before an acknowledgement, handlers that fail once, twice or always, and copies stored by the broker; at the end no confirmed message lost, no effect twice, the order of every key kept and the dead letter queue equal to what the reports dead-lettered |

Every scenario is mandatory: there is no capability flag, the battery never skips, and every scenario is a `final` method, so a satellite cannot override one to weaken it. A broker that cannot keep one of these items is not conformant with the hook. The battery also expects of a receive that, within the wait the bench declares (`receiveWaitMs()`), it returns every delivery available, up to its maximum: a satellite whose client returns partial pages loops until it has `$max` deliveries or a page comes back empty.

## Mapping the contract to brokers

The two satellites that exist, and orientation for others. None of the brokers below the first two has a package of this project, and none is promised; the notes say what the broker documentation states and, where it was not verified, what to check before writing the satellite.

| Broker | Ordering key | Confirmation per message | In flight | Delivery count | Requeue before the later ones of the key | Dead letter with the reason | Dead consumer | PHP client |
|---|---|---|---|---|---|---|---|---|
| [Redis Streams](https://github.com/antevemus-it/Antevemus.ASpecificationRedisStreams) (existing, v1.0.0) | one stream per message name; order of the entry ids | the reply of `XADD` | the pending entries list of the consumer group | the delivery counter of the pending entry | the pending entries of the consumer are read before new ones | a dead letter stream, the reason in a field | `XAUTOCLAIM` after an idle time | predis, pure PHP |
| [RabbitMQ](https://github.com/antevemus-it/Antevemus.ASpecificationRabbitMQ) (existing, v1.0.0) | one quorum queue per consumer | publisher confirms (`basic.ack`), the mandatory flag for an unroutable message | unacknowledged deliveries of a channel with manual acknowledgements | the delivery count of the quorum queue | a requeued message keeps its place | a dead letter exchange, the reason in a header | the broker requeues what a closed channel held | php-amqplib, pure PHP |
| AWS SQS, **FIFO queue** | `MessageGroupId` | `SendMessageBatch` (up to 10 entries) reports each entry as successful or failed | the visibility timeout; `DeleteMessage` acknowledges | `ApproximateReceiveCount` | in a group, nothing later is returned while a message is in flight; giving it back (visibility 0, check) keeps it first | the redrive policy moves a message after `maxReceiveCount` receives; its documentation describes no reason (check): send to the dead letter queue yourself, with the reason, then delete | the visibility timeout expires and the message is received again | AWS SDK for PHP (aws/aws-sdk-php) |
| AWS SQS, **Standard queue** | none | | | | | | | **not conformant**: no ordering |
| Apache Kafka | the key chooses the partition; order per partition | a delivery report per message (`acks=all`, idempotent producer) | uncommitted offsets of the partitions assigned to the consumer | none native: keep it yourself | offsets are committed per partition, in order: a requeue means seeking back and pausing the partition | a dead letter topic the satellite produces to, the reason in a header | a rebalance of the consumer group hands the partition, from the last committed offset, to another member | the `rdkafka` extension (php-rdkafka, over librdkafka) |
| Azure Service Bus | sessions (`SessionId`, the AMQP group-id) on a session-aware queue or subscription | the send operation; check the per-message answer of a batch | peek-lock; `Complete` removes | `DeliveryCount`; `MaxDeliveryCount` (default 10) moves to the dead letter queue as `MaxDeliveryCountExceeded` | in a session, an abandoned message is served again by the next receive | `DeadLetter` into `<queue>/$deadletterqueue` with `DeadLetterReason` and `DeadLetterErrorDescription` | an expired lock redelivers, the count rises | check: the native protocol is AMQP 1.0, which php-amqplib (AMQP 0-9-1) does not speak; there is an HTTP REST API |
| Google Cloud Pub/Sub | ordering keys, with message ordering enabled on the subscription | the publish result of each message (check) | the acknowledgement deadline | `delivery_attempt`, only when the subscription has a dead letter topic | a redelivery of a message redelivers every later message of its key, even acknowledged ones | a dead letter topic (5 to 100 attempts); the forwarded message carries counts, not a reason: publish it yourself with the reason | an expired acknowledgement deadline redelivers | google/cloud-pubsub (check the transport: REST or the gRPC extension) |
| NATS JetStream | one subject per key, or the order of the stream (check) | the publish acknowledgement of JetStream (check) | a pull consumer with explicit acknowledgement; `AckWait` | the delivery count in the message metadata (check its name in the client) | `Nak` redelivers, at once or after a delay; check where it comes relative to the later messages (`MaxAckPending`) | no built-in dead letter queue: `Term` stops redelivery and an advisory is published; a satellite publishes the dead letter with the reason itself | `AckWait` expires and the message is redelivered | check: basis-company/nats (PHP) is the most used client |

The hard spots, broker by broker:

- **SQS**: only the FIFO queue keeps an order, per message group; the Standard queue may deliver out of order and is not conformant. A FIFO queue deduplicates a repeated `MessageDeduplicationId` within 5 minutes: the battery sends copies on purpose (the inbox, not the broker, deduplicates), so a deduplication id per send attempt keeps those scenarios honest, and check what that costs you in production. A short poll may return fewer messages than are there, or none: use long polling (`WaitTimeSeconds`) and declare it in `receiveWaitMs()`. For the dead letter reason, a message attribute on the copy you send to the dead letter queue.
- **Kafka**: a per-message reject is the hard part, because a consumer commits offsets, not messages. A satellite keeps the deliveries of a partition in flight in memory, seeks back to a requeued one (and pauses the partition so nothing later of its key goes first), counts deliveries itself (a header or a side store, since Kafka has none) and produces dead letters to a topic. The only established PHP client is an extension.
- **Azure Service Bus**: ordering needs sessions, and a session is locked by one receiver at a time; check whether the REST API covers sessions, and which PHP client exists today, before choosing between AMQP 1.0 and HTTP.
- **Google Cloud Pub/Sub**: with ordering keys, a redelivery brings back every later message of the key, acknowledged ones included; the inbox absorbs them, but the battery would see them as extra `duplicate` deliveries, so check how a satellite keeps the transcript of a key exact. `delivery_attempt` exists only with a dead letter topic, and the minimum of 5 attempts must stay above the `maxDeliveries` of the consumers, so that the consumer, not Pub/Sub, decides the dead letter.
- **NATS JetStream**: check how a `Nak` is ordered against the later messages of the subject when several are pending (`MaxAckPending`), and how a dead letter is kept with its reason, since JetStream has none built in.

### 27. Running the Battery First Against the Reference (Module 49)

Before wiring the broker, run the battery against the in-memory reference of the core: a green run proves the test suite of the satellite is wired (PHPUnit, the autoloader, the core), so the first red run against the broker is about the broker.

```php
use Antevemus\ASpecification\Messaging\Testing\IMessagingBench;
use Antevemus\ASpecification\Messaging\Testing\InMemoryMessagingBench;
use Antevemus\ASpecification\Messaging\Testing\MessagingContractTestCase;

final class InMemoryContractTest extends MessagingContractTestCase
{
    protected function createBench(): IMessagingBench
    {
        return new InMemoryMessagingBench();
    }
}
```

- **`MessagingContractTestCase`** is an abstract PHPUnit `TestCase` with 22 scenarios (one of them, the property sequence, runs once per seed: 27 tests). Override `createBench()`; override `propertySteps()` to run more steps of the property sequence (never fewer than `MIN_PROPERTY_STEPS`, 150: the scenario fails); a subclass that overrides `setUp()` or `tearDown()` calls the parent. The seeds (`PROPERTY_SEEDS`) and the minimum are final constants.
- **`InMemoryMessagingBench`** is the bench of the core: `InMemoryMessageTransport` and `InMemoryMessageSource`, unchanged.
- **PHPUnit** is a `suggest` of the core, not a `require`: an application that never runs the battery never installs it, and nothing else of the library loads these classes.

### 27.1. The Transport

The didactic broker is three tables: the log of entries, the cursor of each consumer and the deliveries of each consumer. The transport writes the batch in one transaction and confirms only after the commit.

```php
use Antevemus\ASpecification\Messaging\Exceptions\MessagingException;
use Antevemus\ASpecification\Messaging\IMessageTransport;
use Antevemus\ASpecification\Messaging\TransportSendResult;
use PDO;
use PDOException;

/**
 * DIDACTIC: a "broker" over SQLite tables, to show what a transport does. Not for production.
 */
final class SqliteMessageTransport implements IMessageTransport
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** The tables of the broker: the log, the cursor of each consumer, the deliveries of each consumer. */
    public static function declareTables(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS broker_entry (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, tenant TEXT NULL, body TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS broker_consumer (consumer TEXT PRIMARY KEY, cursor INTEGER NOT NULL)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS broker_delivery (consumer TEXT NOT NULL, entry_id INTEGER NOT NULL, state TEXT NOT NULL, member TEXT NULL, delivery_count INTEGER NOT NULL, reason TEXT NULL, dead_seq INTEGER NULL, PRIMARY KEY (consumer, entry_id))');
    }

    public function send(array $batch): TransportSendResult
    {
        $stored = [];
        try {
            $this->pdo->beginTransaction();
            $insert = $this->pdo->prepare('INSERT INTO broker_entry (name, tenant, body) VALUES (?, ?, ?)');
            foreach ($batch as $message) {
                // The body is the wire form; the tenant travels beside it, where a consumer could filter on it.
                $insert->execute([$message->name, $message->tenant, $message->toJson()]);
                $stored[$message->messageId] = (string) $this->pdo->lastInsertId();
            }
            $this->pdo->commit(); // stored durably: now, and only now, the batch is confirmed
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            // The message of a driver may quote a value: name the cause by its code, never the payload.
            throw new MessagingException(sprintf('The SQLite broker did not store the batch of %d message(s) (SQLSTATE %s).', count($batch), $e->getCode()));
        }
        $result = new TransportSendResult();
        foreach ($stored as $messageId => $brokerId) {
            $result = $result->withConfirmed($messageId, $brokerId);
        }

        return $result;
    }
}
```

- **The confirmation** comes after `commit()`: a batch whose commit failed confirms nothing, and the exception it throws names the batch and the SQLSTATE, never the text of the driver.
- **The broker id** is the id of the entry (`TransportSendResult::withConfirmed($messageId, $brokerId)`); a broker without one confirms with `null`.

### 27.2. The Source

A source is one member of one consumer. It hands out first what was given back (requeued, or left in flight by a dead member), in the order stored, then what the consumer has not seen yet, from its cursor; every body goes through `ReceivedMessage::decode()`.

```php
use Antevemus\ASpecification\Messaging\Exceptions\MessagingException;
use Antevemus\ASpecification\Messaging\IMessageSource;
use Antevemus\ASpecification\Messaging\ReceivedMessage;
use PDO;
use PDOStatement;
use Throwable;

/**
 * DIDACTIC: one member of one consumer reading the SQLite "broker" of SqliteMessageTransport.
 */
final class SqliteMessageSource implements IMessageSource
{
    /** @param list<string> $names The message names the consumer reads */
    public function __construct(
        private readonly PDO $pdo,
        public readonly string $consumer,
        public readonly array $names,
        public readonly string $member
    ) {
        // Declares the consumer: it reads what is stored from now on (an existing consumer keeps its cursor).
        $this->execute('INSERT OR IGNORE INTO broker_consumer (consumer, cursor) VALUES (?, (SELECT COALESCE(MAX(id), 0) FROM broker_entry))', [$consumer]);
        // A process that starts again gets back what its previous run left in flight.
        $this->recoverFrom($member);
    }

    public function receive(int $max, int $waitMs): array
    {
        $deadline = microtime(true) + $waitMs / 1000;
        while (true) {
            $deliveries = $this->take($max);
            if ($deliveries !== [] || microtime(true) >= $deadline) {
                return $deliveries;
            }
            usleep(10000);
        }
    }

    public function ack(ReceivedMessage $message): void
    {
        $this->answer($message, 'DELETE FROM broker_delivery', []);
    }

    public function reject(ReceivedMessage $message, bool $requeue, string $reason): void
    {
        if ($requeue) {
            $this->answer($message, "UPDATE broker_delivery SET state = 'ready', member = NULL", []);

            return;
        }
        // The dead letter queue keeps the reason and the count; the reason never carries the payload.
        $this->answer($message, "UPDATE broker_delivery SET state = 'dead', member = NULL, reason = ?, dead_seq = (SELECT COALESCE(MAX(dead_seq), 0) + 1 FROM broker_delivery)", [$reason]);
    }

    /** What member $member of this consumer left in flight goes back to the queue (XAUTOCLAIM, a closed channel). */
    public function recoverFrom(string $member): int
    {
        return $this->execute("UPDATE broker_delivery SET state = 'ready', member = NULL WHERE consumer = ? AND state = 'in_flight' AND member = ?", [$this->consumer, $member])->rowCount();
    }

    /** @return list<array{messageId: ?string, reason: string, deliveryCount: int}> */
    public function deadLetters(): array
    {
        $rows = $this->execute("SELECT e.id, e.body, d.reason, d.delivery_count FROM broker_delivery d JOIN broker_entry e ON e.id = d.entry_id WHERE d.consumer = ? AND d.state = 'dead' ORDER BY d.dead_seq", [$this->consumer]);
        $dead = [];
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $delivery = ReceivedMessage::decode((string) $row['body'], (int) $row['delivery_count'], (string) $row['id']);
            $dead[] = ['messageId' => $delivery->isValid() ? $delivery->message()->messageId : null, 'reason' => (string) $row['reason'], 'deliveryCount' => (int) $row['delivery_count']];
        }

        return $dead;
    }

    /** @return list<ReceivedMessage> */
    private function take(int $max): array
    {
        $this->pdo->beginTransaction();
        try {
            // 1. What was given back (rejected with requeue, left by a dead member) comes first, in the order stored.
            $ids = $this->execute("SELECT entry_id FROM broker_delivery WHERE consumer = ? AND state = 'ready' ORDER BY entry_id LIMIT {$max}", [$this->consumer])->fetchAll(PDO::FETCH_COLUMN);
            // 2. Then what this consumer has not seen yet, from its cursor on.
            if (count($ids) < $max) {
                $cursor = (int) $this->execute('SELECT cursor FROM broker_consumer WHERE consumer = ?', [$this->consumer])->fetchColumn();
                $names = implode(', ', array_fill(0, count($this->names), '?'));
                $fresh = $this->execute("SELECT id FROM broker_entry WHERE id > ? AND name IN ({$names}) ORDER BY id LIMIT " . ($max - count($ids)), [$cursor, ...$this->names])->fetchAll(PDO::FETCH_COLUMN);
                foreach ($fresh as $id) {
                    $this->execute("INSERT INTO broker_delivery (consumer, entry_id, state, delivery_count) VALUES (?, ?, 'ready', 0)", [$this->consumer, $id]);
                    $this->execute('UPDATE broker_consumer SET cursor = ? WHERE consumer = ?', [$id, $this->consumer]);
                }
                $ids = [...$ids, ...$fresh];
            }
            $deliveries = [];
            foreach ($ids as $id) {
                $this->execute("UPDATE broker_delivery SET state = 'in_flight', member = ?, delivery_count = delivery_count + 1 WHERE consumer = ? AND entry_id = ?", [$this->member, $this->consumer, $id]);
                $row = $this->execute('SELECT e.body, d.delivery_count FROM broker_entry e JOIN broker_delivery d ON d.entry_id = e.id WHERE d.consumer = ? AND e.id = ?', [$this->consumer, $id])->fetch(PDO::FETCH_ASSOC);
                // Whatever the body is, it is delivered: decode() turns what is not a message into an invalid delivery.
                $deliveries[] = ReceivedMessage::decode((string) $row['body'], (int) $row['delivery_count'], (string) $id);
            }
            $this->pdo->commit();

            return $deliveries;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Answers a delivery this member holds in flight; anything else is a bug of the consumer, made visible. */
    private function answer(ReceivedMessage $message, string $statement, array $values): void
    {
        $id = ctype_digit($message->brokerId) ? (int) $message->brokerId : 0;
        $answered = $this->execute("{$statement} WHERE consumer = ? AND entry_id = ? AND state = 'in_flight' AND member = ?", [...$values, $this->consumer, $id, $this->member])->rowCount();
        if ($answered !== 1) {
            throw new MessagingException(sprintf('Delivery %s is not in flight on member "%s" of consumer "%s": it was already answered, or it came from another one.', $message->brokerId, $this->member, $this->consumer));
        }
    }

    private function execute(string $sql, array $values): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($values);

        return $statement;
    }
}
```

- **The order of a key** comes from reading what was given back before anything new: a requeued delivery comes again before every later delivery of its key, as the pending entries of a Redis consumer group or a requeued message of RabbitMQ do.
- **In flight** is a row with the member that holds it; `ack()` and `reject()` answer only a delivery this member holds in flight, and anything else throws `MessagingException` (a consumer that answers twice is a bug the battery makes visible).
- **Recovery**: a process that starts again recovers its own deliveries in flight (the constructor); a successor recovers those of the dead member (`recoverFrom()`). How a real broker does it varies (an idle time, a closed channel, a visibility timeout, a rebalance): the bench of example 27.3 is where the satellite triggers it.

### 27.3. The Bench

The bench gives the battery a broker of its own per instance and the operations it provokes: a lost connection, a successor, a restarted process, a raw body, the dead letter queue read back.

```php
use Antevemus\ASpecification\Messaging\IMessageSource;
use Antevemus\ASpecification\Messaging\IMessageTransport;
use Antevemus\ASpecification\Messaging\Testing\IMessagingBench;
use LogicException;
use PDO;

/**
 * DIDACTIC: the bench of the SQLite "broker" under the contract battery of the core.
 */
final class SqliteMessagingBench implements IMessagingBench
{
    private readonly PDO $pdo;

    public function __construct()
    {
        // A database in memory per bench: every scenario gets a broker of its own (isolation).
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        SqliteMessageTransport::declareTables($this->pdo);
    }

    public function transport(): IMessageTransport
    {
        return new SqliteMessageTransport($this->pdo);
    }

    public function transportWithLostConnection(): IMessageTransport
    {
        // A database without the tables of the broker: every send fails, and nothing is stored.
        return new SqliteMessageTransport(new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]));
    }

    public function source(string $consumer, array $names, string $member = 'm1'): IMessageSource
    {
        return new SqliteMessageSource($this->pdo, $consumer, $names, $member);
    }

    public function successor(IMessageSource $source): IMessageSource
    {
        $dead = self::own($source);
        $next = new SqliteMessageSource($this->pdo, $dead->consumer, $dead->names, $dead->member . '-next');
        $next->recoverFrom($dead->member); // Redis: XAUTOCLAIM after an idle time; RabbitMQ: the closed channel

        return $next;
    }

    public function restarted(IMessageSource $source): IMessageSource
    {
        $old = self::own($source);

        return new SqliteMessageSource($this->pdo, $old->consumer, $old->names, $old->member); // recovers on start
    }

    public function storeRaw(string $name, string $body): void
    {
        $this->pdo->prepare('INSERT INTO broker_entry (name, tenant, body) VALUES (?, NULL, ?)')->execute([$name, $body]);
    }

    public function deadLetters(IMessageSource $source): array
    {
        return self::own($source)->deadLetters();
    }

    public function receiveWaitMs(): int
    {
        return 0; // what send() stored is readable at once
    }

    public function close(): void
    {
        // The database lives in memory and goes away with the bench.
    }

    private static function own(IMessageSource $source): SqliteMessageSource
    {
        return $source instanceof SqliteMessageSource ? $source : throw new LogicException('Not a source of this bench.');
    }
}
```

- **Isolation**: one database in memory per bench. Against a real broker, use a unique prefix of names per instance (streams, queues, topics, subscriptions) and remove them in `close()`; the battery checks that two benches alive at the same time do not see each other's messages.
- **`successor()` and `restarted()`** may take time against a real broker (wait for an idle time, cut the socket and wait for the broker to requeue): that time is part of the bench, never of the battery.
- **`receiveWaitMs()`** is the longest wait the battery passes to `receive()` and `consume()`: 0 when what `send()` stored is readable at once, the visibility delay of the broker otherwise.

### 27.4. Wiring the Battery Into the Suite of the Satellite

```php
use Antevemus\ASpecification\Messaging\Testing\IMessagingBench;
use Antevemus\ASpecification\Messaging\Testing\MessagingContractTestCase;

final class SqliteContractTest extends MessagingContractTestCase
{
    protected function createBench(): IMessagingBench
    {
        return new SqliteMessagingBench();
    }

    protected function propertySteps(): int
    {
        return 300; // more than the minimum of the battery (150); fewer fails the scenario
    }
}
```

- **One class per bench**: the in-memory one of example 27 and this one, side by side in the suite of the satellite. Both run in the core too (Module 49, through `tests/run_all.php` and PHPUnit), which keeps this page and the battery in step.
- **The specific tests of the broker** (its topology, its connection options, its own failures) stay in the satellite's own classes, next to the battery.

## Continuous integration with a real broker

Run the battery against the broker in a service container, on every push, and make an unreachable broker a failure there: the battery never skips, and the satellite's bench should not either, outside a developer's machine.

```yaml
# .github/workflows/tests.yml (an example with Redis; any broker with a container image works the same way)
jobs:
  contract:
    runs-on: ubuntu-latest
    services:
      broker:
        image: redis:7
        ports: ["6379:6379"]
        options: >-
          --health-cmd "redis-cli ping" --health-interval 2s --health-timeout 2s --health-retries 15
    env:
      BROKER_URL: tcp://127.0.0.1:6379
      BROKER_REQUIRED: "1"
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: "8.2"
      - run: composer install --no-progress
      - run: vendor/bin/phpunit
```

For a managed service without a container image (SQS, Service Bus, Pub/Sub), use the emulator the vendor provides, or an account for the CI with names unique per run, removed in `close()`.

## Packaging

```json
{
    "name": "vendor/aspecification-broker",
    "description": "IMessageTransport and IMessageSource of antevemus/aspecification over Broker",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": "^8.2",
        "antevemus/aspecification": "^2.15",
        "vendor/broker-client": "^1.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0"
    },
    "suggest": {
        "ext-broker": "a faster client, when the broker offers one"
    }
}
```

- **The name** is `vendor/aspecification-<broker>`, under your own vendor: a satellite touches neither the core nor the other satellites.
- **The license** is yours to choose; the core is MIT.
- **`antevemus/aspecification: ^2.15`** is the first version that ships the battery.
- **A client in pure PHP** when the broker has a good one (predis, php-amqplib): nothing to compile on the server of the application. When the only good client is an extension (Kafka), say so in the README and require it (`ext-rdkafka`).
- **`suggest`** in the satellite itself, for what is optional (a faster extension, a PSR-3 logger).

## Versioning

The battery lives in the core and follows its version. A new scenario only checks something the contract already promised (a MINOR never changes the contract of a satellite, and a MAJOR of the core never breaks the API a module uses), so a satellite that fails a new scenario was already not conformant on that item. The satellite adopts the scenarios of a newer core by raising its floor (`^2.15` to `^2.16`); run its CI against the newest core allowed by its constraint too (a scheduled run with `composer update`), so a new scenario shows up there before it shows up for a user.
