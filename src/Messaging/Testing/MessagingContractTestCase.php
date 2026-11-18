<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Messaging\Testing;

use Antevemus\ASpecification\Messaging\ConsumeEntry;
use Antevemus\ASpecification\Messaging\ConsumeOutcome;
use Antevemus\ASpecification\Messaging\ConsumeReport;
use Antevemus\ASpecification\Messaging\Exceptions\MessagingException;
use Antevemus\ASpecification\Messaging\IMessageSource;
use Antevemus\ASpecification\Messaging\IMessageTransport;
use Antevemus\ASpecification\Messaging\Inbox\InMemoryProcessedMessageStore;
use Antevemus\ASpecification\Messaging\Message;
use Antevemus\ASpecification\Messaging\MessageConsumer;
use Antevemus\ASpecification\Messaging\MessageHandlerRegistry;
use Antevemus\ASpecification\Messaging\MessagePublisher;
use Antevemus\ASpecification\Messaging\Outbox\IMessageOutbox;
use Antevemus\ASpecification\Messaging\Outbox\InMemoryMessageOutbox;
use Antevemus\ASpecification\Messaging\Outbox\MessageOutboxEntry;
use Antevemus\ASpecification\Messaging\Outbox\MessageOutboxStatus;
use Antevemus\ASpecification\Messaging\OutboxRelay;
use Antevemus\ASpecification\Messaging\ReceivedMessage;
use Antevemus\ASpecification\Messaging\TransportSendResult;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\IInMemoryTransactional;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\InMemoryTransactionManager;
use ArrayObject;
use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;
use Throwable;

/**
 * MessagingContractTestCase - The Contract Battery of the Messaging Hook, for Every Broker
 *
 * The public battery of IMessageTransport and IMessageSource (forward 064, D1 to D4): a satellite extends it
 * in its own PHPUnit suite, answers createBench() with an IMessagingBench over its broker, and runs every
 * scenario the hook promises. The core runs it against its in-memory reference (InMemoryMessagingBench) and
 * against the didactic SQLite satellite of its manual (Module 49). It unifies the private batteries the Redis
 * Streams and RabbitMQ satellites had until 2.15.0; the battery is the contract of the version of the core
 * that ships it, so a satellite that requires `^2.15` is tested against the contract of what it installs.
 *
 * Every scenario goes the whole way of the application: MessagePublisher into an InMemoryMessageOutbox,
 * OutboxRelay through the transport of the bench, MessageConsumer over a source of the bench with an
 * InMemoryProcessedMessageStore, the effects of the handlers committed with the inbox row (an
 * InMemoryTransactionManager). Each one compares the transcript of the run (one line per delivery,
 * `<alias> <outcome> <delivery count>`), the effects, the reasons and the dead letters with literals.
 *
 * What is compared is what the contract promises, nothing more: the order WITHIN an ordering key (the
 * lines of one key in sequence), and the multiset of the rest. The order between two keys, or of a message
 * without a key, is not part of IMessageSource (a Kafka partition, an SQS message group, a Redis stream per
 * name deliver keys in any interleaving), so it is never asserted on deliveries; what the relay of the core
 * sends, and in which batches, is, because that is the core's own logic.
 *
 * Every scenario is mandatory (D3): there is no capability flag, and the battery never skips. A broker that
 * cannot pass one is not conformant with the hook (the Standard queue of SQS, which does not keep order, is
 * not; its FIFO queue can be). The scenarios:
 *
 * - a round trip delivers the message as published (every field, to the microsecond), once;
 * - every consumer gets every message;
 * - the order of a key across message names, and across batches of the relay;
 * - a delivery rejected with requeue comes back before the later ones of its key (in one run, with deliveries
 *   left behind, and one delivery at a time);
 * - a message that keeps failing goes to the dead letter queue after maxDeliveries, with the reason;
 * - a body that is not a message is delivered as invalid and dead-lettered, never dropped;
 * - a message without a handler is acknowledged with a warning; a consumer of one tenant acknowledges the
 *   others without handling;
 * - the largest payload (65 535 bytes) travels whole; batches of mixed keys keep every key in order;
 * - a duplicate stored by the broker is absorbed by the inbox; a relay that dies between the confirmation and
 *   the mark sends again, and the effect runs once;
 * - the deliveries of a dead consumer go to its successor; a process started again gets what it left in flight;
 * - a lost connection fails the whole batch and marks nothing;
 * - no reason, report or exception carries the payload; answering a delivery that is not in flight throws;
 * - each bench is a world of its own;
 * - random interleavings of publish, relay, consume, reject, duplicate and death, with fixed seeds
 *   (PROPERTY_SEEDS) and at least MIN_PROPERTY_STEPS steps, keep the guarantees against a model of what was
 *   published: no confirmed message lost, no effect twice, the order of every key, the dead letters reported.
 *
 * Wiring: override createBench(); override propertySteps() to run more steps (never fewer than
 * MIN_PROPERTY_STEPS); a subclass that overrides setUp() or tearDown() calls the parent. The battery needs
 * PHPUnit (`suggest` of the core); nothing else of the library loads it.
 *
 * @version    2.15.0
 * @package    Antevemus\ASpecification
 * @subpackage Messaging\Testing
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class MessagingContractTestCase extends TestCase
{
    /** The seeds of the property sequence: the union of the seeds of the two private batteries; fixed. */
    final public const PROPERTY_SEEDS = [20261115, 6379, 42, 11, 23, 2026];

    /** The fewest steps of the property sequence per seed; propertySteps() may raise it, never lower it. */
    final public const MIN_PROPERTY_STEPS = 150;

    /** The maxDeliveries of the consumers of the battery. */
    final public const MAX_DELIVERIES = 3;

    protected const LEAD = 'crm.lead_created.v1';

    protected const UPDATED = 'crm.lead_updated.v1';

    protected const DELETED = 'crm.lead_deleted.v1';

    protected const CHAT = 'livechat.message_received.v1';

    protected const CREATED = 'underwriting.quote_created.v1';

    protected const SENT = 'underwriting.quote_sent.v1';

    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private const RELAY_DIED = 'The process of the relay died after the broker confirmed and before the outbox was marked.';

    private const CONSUMER_DIED = 'The process of the consumer died after the commit and before the acknowledgement.';

    private IMessagingBench $bench;

    private InMemoryMessageOutbox $outbox;

    private IMessageOutbox $crashingOutbox;

    private bool $crashOnNextMark = false;

    private MessagePublisher $publisher;

    private InMemoryProcessedMessageStore $inbox;

    private IInMemoryTransactional $effectLog;

    private InMemoryTransactionManager $transactions;

    private MessageHandlerRegistry $handlers;

    /** Mixed into every message id, so that no two scenarios (nor two runs) share an id. */
    private string $salt = '';

    /** @var array<string, string> message id => alias */
    private array $aliases = [];

    /** @var array<string, ?string> alias => ordering key */
    private array $keys = [];

    /** @var array<string, int> alias => failures left before its handler succeeds */
    private array $failuresLeft = [];

    /** @var list<Message> every message a handler was called with, the failed calls included */
    private array $received = [];

    /**
     * The bench of one scenario: a broker of its own, closed at the end of the scenario.
     */
    abstract protected function createBench(): IMessagingBench;

    /**
     * Steps of the property sequence per seed; a satellite may return more, never fewer than
     * MIN_PROPERTY_STEPS (the scenario fails).
     */
    protected function propertySteps(): int
    {
        return self::MIN_PROPERTY_STEPS;
    }

    /**
     * The seeds of the property sequence, one data set each.
     *
     * @return array<string, array{int}>
     */
    public static function propertySeeds(): array
    {
        $seeds = [];
        foreach (self::PROPERTY_SEEDS as $seed) {
            $seeds["seed {$seed}"] = [$seed];
        }

        return $seeds;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->salt = bin2hex(random_bytes(8));
        $this->outbox = new InMemoryMessageOutbox();
        $this->crashingOutbox = self::crashingOutbox($this->outbox, function (): bool {
            $crash = $this->crashOnNextMark;
            $this->crashOnNextMark = false;

            return $crash;
        });
        $this->publisher = new MessagePublisher($this->outbox);
        $this->inbox = new InMemoryProcessedMessageStore();
        $this->effectLog = self::effectLog();
        $this->transactions = new InMemoryTransactionManager([$this->outbox, $this->inbox, $this->effectLog]);
        $this->handlers = new MessageHandlerRegistry();
        $this->bench = $this->createBench();
    }

    protected function tearDown(): void
    {
        if (isset($this->bench)) {
            $this->bench->close();
        }
        parent::tearDown();
    }

    // =====================================================================
    // The scenarios
    // =====================================================================

    final public function testARoundTripDeliversTheMessageAsPublished(): void
    {
        $source = $this->bench->source('billing', [self::LEAD, self::CHAT]);
        $this->handle(self::LEAD)->handle(self::CHAT);
        $lead = $this->message('lead', self::LEAD, 'lead:1', 'acme', [
            'name' => 'Ana Lúcia', 'score' => 9.0, 'tags' => ['vip', 'sp'], 'none' => null, 'ok' => true, 'nested' => ['a' => ['b' => true]],
        ], ['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01', 'trace-id' => 't-1']);
        $chat = $this->message('chat', self::CHAT, payload: ['text' => 'Olá, 世界 / "quoted"']);
        self::assertSame(2, $this->publish($lead, $chat));

        $relay = $this->relay();
        $first = $relay->relayPending(100);
        self::assertSame(['lead', 'chat'], $this->aliasesOf($first->deliveredIds()), 'the relay sends what was published');
        self::assertSame(0, $first->failedCount());
        self::assertSame(0, $relay->relayPending(100)->deliveredCount(), 'nothing is sent twice');

        $consumer = $this->consumer($source);
        $this->assertTranscript(['lead handled 1', 'chat handled 1'], $consumer->consume(10, $this->waitMs()));
        $byAlias = [];
        foreach ($this->received as $message) {
            $byAlias[(string) $message->payload['alias']] = $message->toArray();
        }
        self::assertSame(['lead' => $lead->toArray(), 'chat' => $chat->toArray()], ['lead' => $byAlias['lead'] ?? null, 'chat' => $byAlias['chat'] ?? null], 'the message comes out as it went in: id, name, instant to the microsecond, key, tenant, headers and payload');
        self::assertSame([], $this->transcript($consumer->consume(10, $this->waitMs())), 'an acknowledged delivery does not come again');
        $this->assertEffects(['lead', 'chat']);
    }

    final public function testEveryConsumerGetsEveryMessage(): void
    {
        $billing = $this->bench->source('billing', [self::LEAD]);
        $agents = $this->bench->source('agents', [self::LEAD]);
        $this->handle(self::LEAD);
        $this->publish($this->message('A', key: 'lead:1'), $this->message('B'));
        $this->relay()->relayPending(100);

        $this->assertTranscript(['A handled 1', 'B handled 1'], $this->consumer($billing, 'billing')->consume(100, $this->waitMs()), 'billing');
        $this->assertTranscript(['A handled 1', 'B handled 1'], $this->consumer($agents, 'agents')->consume(100, $this->waitMs()), 'agents');
        self::assertSame([], $this->transcript($this->consumer($billing, 'billing')->consume(100, $this->waitMs())), 'billing has nothing left');
        $this->assertEffects(['A', 'B', 'A', 'B']);
    }

    final public function testMessagesOfAKeyKeepTheirOrderAcrossNames(): void
    {
        $source = $this->bench->source('billing', [self::CREATED, self::SENT]);
        $this->handle(self::CREATED)->handle(self::SENT);
        for ($i = 1; $i <= 4; $i++) {
            $this->publish(
                $this->message("a{$i}", $i % 2 === 1 ? self::CREATED : self::SENT, 'quote:1'),
                $this->message("b{$i}", $i % 2 === 1 ? self::SENT : self::CREATED, 'quote:2'),
            );
        }
        $relayed = $this->relay(batchSize: 3)->relayPending(100);
        self::assertSame(8, $relayed->deliveredCount());
        self::assertSame(4, $relayed->batchCount(), 'one message per key per batch');

        $this->assertTranscript(
            ['a1 handled 1', 'b1 handled 1', 'a2 handled 1', 'b2 handled 1', 'a3 handled 1', 'b3 handled 1', 'a4 handled 1', 'b4 handled 1'],
            $this->drain($source, 3)
        );
    }

    final public function testMessagesOfAKeyKeepTheirOrderAcrossBatches(): void
    {
        $source = $this->bench->source('billing', [self::LEAD]);
        $this->handle(self::LEAD);
        $this->publish(
            $this->message('A1', key: 'a'),
            $this->message('B1', key: 'b'),
            $this->message('A2', key: 'a'),
            $this->message('C1'),
            $this->message('B2', key: 'b'),
            $this->message('A3', key: 'a'),
        );

        $relayed = $this->relay(batchSize: 2)->relayPending(100);
        // The relay sends the heads of the keys, oldest first, two at a time: [A1, B1], [A2, C1], [B2, A3].
        self::assertSame(3, $relayed->batchCount());
        self::assertSame(['A1', 'B1', 'A2', 'C1', 'B2', 'A3'], $this->aliasesOf($relayed->deliveredIds()));
        $this->assertTranscript(
            ['A1 handled 1', 'B1 handled 1', 'A2 handled 1', 'C1 handled 1', 'B2 handled 1', 'A3 handled 1'],
            $this->consumer($source)->consume(100, $this->waitMs())
        );
    }

    final public function testARequeuedDeliveryComesBackBeforeTheLaterOnesOfItsKey(): void
    {
        $source = $this->bench->source('billing', [self::CREATED, self::CHAT]);
        $this->handle(self::CREATED)->handle(self::CHAT);
        $this->failuresLeft = ['k1' => 1];
        $this->publish(
            $this->message('k1', self::CREATED, 'quote:7'),
            $this->message('l1', self::CHAT, 'chat:1'),
            $this->message('k2', self::CREATED, 'quote:7'),
            $this->message('k3', self::CREATED, 'quote:7'),
        );
        self::assertSame(4, $this->relay()->relayPending(100)->deliveredCount());

        $consumer = $this->consumer($source);
        $this->assertTranscript(['k1 retried 1', 'l1 handled 1', 'k2 held_back 1', 'k3 held_back 1'], $consumer->consume(10, $this->waitMs()), 'the first run');
        $this->assertTranscript(['k1 handled 2', 'k2 handled 2', 'k3 handled 2'], $consumer->consume(10, $this->waitMs()), 'the second run');
        self::assertSame([], $this->transcript($consumer->consume(10, $this->waitMs())));
        $this->assertEffects(['l1', 'k1', 'k2', 'k3']);
    }

    final public function testARequeuedDeliveryComesBackBeforeTheOnesLeftBehind(): void
    {
        $source = $this->bench->source('billing', [self::LEAD]);
        $this->handle(self::LEAD);
        $this->failuresLeft = ['A1' => 1];
        $this->publish($this->message('A1', key: 'a'), $this->message('A2', key: 'a'), $this->message('A3', key: 'a'));
        $this->relay()->relayPending(100);
        $consumer = $this->consumer($source);

        // The broker holds A1, A2, A3; the first run takes two of them, so A3 stays behind.
        $first = $consumer->consume(2, $this->waitMs());
        $second = $consumer->consume(100, $this->waitMs());

        $this->assertTranscript(['A1 retried 1', 'A2 held_back 1'], $first);
        self::assertSame([
            'A handler of consumer "billing" failed for message "crm.lead_created.v1" (id A1, ordering key "a") (RuntimeException) on delivery 1 of at most 3.',
            'An earlier message of ordering key "a" was retried in this run: message "crm.lead_created.v1" (id A2, ordering key "a") held back.',
        ], $this->reasons($first));
        $this->assertTranscript(['A1 handled 2', 'A2 handled 2', 'A3 handled 1'], $second);
        $this->assertEffects(['A1', 'A2', 'A3']);
    }

    final public function testARequeuedDeliveryComesBackFirstOneDeliveryAtATime(): void
    {
        $source = $this->bench->source('billing', [self::CREATED, self::CHAT]);
        $this->handle(self::CREATED)->handle(self::CHAT);
        $this->failuresLeft = ['k1' => 1];
        $this->publish(
            $this->message('k1', self::CREATED, 'quote:7'),
            $this->message('l1', self::CHAT, 'chat:1'),
            $this->message('k2', self::CREATED, 'quote:7'),
            $this->message('k3', self::CREATED, 'quote:7'),
        );
        $this->relay()->relayPending(100);

        $consumer = $this->consumer($source);
        $lines = [];
        $runs = 0;
        do {
            $round = $this->transcript($consumer->consume(1, $this->waitMs()));
            self::assertLessThanOrEqual(1, count($round), 'a run of one delivery');
            $lines = [...$lines, ...$round];
        } while ($round !== [] && ++$runs < 10);
        self::assertSame(5, $runs, 'five runs of one delivery, then nothing');
        $this->assertTranscript(['k1 retried 1', 'k1 handled 2', 'l1 handled 1', 'k2 handled 1', 'k3 handled 1'], $lines);
    }

    final public function testAMessageThatKeepsFailingIsDeadLetteredAfterMaxDeliveries(): void
    {
        $source = $this->bench->source('billing', [self::LEAD]);
        $this->handle(self::LEAD);
        $this->failuresLeft = ['X' => PHP_INT_MAX];
        $this->publish($this->message('X', key: 'x'), $this->message('Y', key: 'x'));
        $this->relay()->relayPending(100);
        $consumer = $this->consumer($source);

        $runs = [];
        $reports = [];
        for ($i = 0; $i < 4; $i++) {
            $reports[] = $report = $consumer->consume(100, $this->waitMs());
            $runs[] = $this->transcript($report);
        }

        self::assertSame([
            ['X retried 1', 'Y held_back 1'],
            ['X retried 2', 'Y held_back 2'],
            ['X dead_lettered 3', 'Y handled 3'],
            [],
        ], $runs);
        $this->assertEffects(['Y']);
        $reason = 'A handler of consumer "billing" failed for message "crm.lead_created.v1" (id X, ordering key "x") (RuntimeException) on delivery 3 of at most 3.';
        self::assertSame($reason, $this->aliased(($reports[2]->entries()[0] ?? null)?->reason));
        self::assertSame([['messageId' => 'X', 'reason' => $reason, 'deliveryCount' => 3]], $this->deadLetters($source), 'the dead letter queue of the broker holds the message, with the reason of the report and the delivery count');
    }

    final public function testABodyThatIsNotAMessageIsDeadLetteredNeverDropped(): void
    {
        $source = $this->bench->source('billing', [self::LEAD]);
        $this->handle(self::LEAD);
        $this->bench->storeRaw(self::LEAD, 'not json at all');
        $this->bench->storeRaw(self::LEAD, '{"v":2,"id":"x"}');
        $this->publish($this->message('A', key: 'lead:1'));
        $this->relay()->relayPending(100);

        $consumer = $this->consumer($source);
        $report = $consumer->consume(100, $this->waitMs());

        $this->assertTranscript(['invalid dead_lettered 1', 'invalid dead_lettered 1', 'A handled 1'], $report);
        $reasons = [
            'The delivery is not a message of format 1: A message document must declare "v": 1.',
            'The delivery is not a message of format 1: A message document of 15 bytes is not valid JSON, or its payload is nested deeper than 64 levels.',
        ];
        $invalid = array_map(static fn(ConsumeEntry $e): string => (string) $e->reason, array_values(array_filter($report->entries(), static fn(ConsumeEntry $e): bool => $e->messageId === null)));
        sort($invalid);
        self::assertSame($reasons, $invalid, 'the reason of an invalid delivery is the one of ReceivedMessage::decode()');
        $dead = $this->deadLetters($source);
        usort($dead, static fn(array $a, array $b): int => strcmp($a['reason'], $b['reason']));
        self::assertSame([
            ['messageId' => null, 'reason' => $reasons[0], 'deliveryCount' => 1],
            ['messageId' => null, 'reason' => $reasons[1], 'deliveryCount' => 1],
        ], $dead, 'both bodies are in the dead letter queue of the broker, with the reason');
        self::assertSame([], $this->transcript($consumer->consume(100, $this->waitMs())), 'nothing comes again');
    }

    final public function testAMessageWithoutAHandlerIsAcknowledgedWithAWarning(): void
    {
        $source = $this->bench->source('billing', [self::LEAD, self::DELETED]);
        $this->handle(self::LEAD);
        $this->publish($this->message('d1', self::DELETED, 'lead:1'), $this->message('c1', self::LEAD, 'lead:1'), $this->message('d2', self::DELETED));
        $this->relay()->relayPending(100);
        $consumer = $this->consumer($source);

        $report = $consumer->consume(100, $this->waitMs());

        $this->assertTranscript(['d1 unknown_name 1', 'c1 handled 1', 'd2 unknown_name 1'], $report);
        $warnings = array_map($this->aliased(...), $report->warnings());
        sort($warnings);
        self::assertSame([
            'Consumer "billing" has no handler for message "crm.lead_deleted.v1" (id d1, ordering key "lead:1"): acknowledged without handling.',
            'Consumer "billing" has no handler for message "crm.lead_deleted.v1" (id d2): acknowledged without handling.',
        ], $warnings);
        self::assertSame([], $this->transcript($consumer->consume(100, $this->waitMs())), 'acknowledged: nothing comes again');
        self::assertSame([], $this->deadLetters($source), 'not a dead letter');
    }

    final public function testAConsumerOfOneTenantAcknowledgesTheOthersWithoutHandling(): void
    {
        $source = $this->bench->source('billing', [self::LEAD]);
        $this->handle(self::LEAD);
        $this->publish($this->message('a', tenant: 'acme'), $this->message('g', tenant: 'globex'), $this->message('z'));
        $this->relay()->relayPending(100);
        $consumer = $this->consumer($source, tenant: 'acme');

        $report = $consumer->consume(100, $this->waitMs());

        $this->assertTranscript(['a handled 1', 'g other_tenant 1', 'z other_tenant 1'], $report);
        $reasons = [];
        foreach ($report->entries() as $entry) {
            $reasons[$this->label($entry->messageId)] = $entry->reason === null ? null : $this->aliased($entry->reason);
        }
        ksort($reasons);
        self::assertSame([
            'a' => null,
            'g' => 'Consumer "billing" handles tenant "acme" only; message "crm.lead_created.v1" (id g) is of tenant "globex": acknowledged without handling.',
            'z' => 'Consumer "billing" handles tenant "acme" only; message "crm.lead_created.v1" (id z) is of no tenant: acknowledged without handling.',
        ], $reasons);
        $this->assertEffects(['a']);
        self::assertSame([], $this->transcript($consumer->consume(100, $this->waitMs())));
    }

    final public function testTheLargestPayloadTravelsWhole(): void
    {
        $source = $this->bench->source('billing', [self::LEAD, self::CHAT]);
        $this->handle(self::LEAD)->handle(self::CHAT);
        // ASCII after a mixed text, then two-byte characters (and one ASCII when odd): 65 535 bytes each.
        $text = 'Olá, 世界 ';
        $base = strlen((string) json_encode(['alias' => 'big1', 'text' => $text], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $mixed = $this->message('big1', self::CHAT, 'chat:9', payload: ['text' => $text . str_repeat('a', Message::MAX_PAYLOAD_BYTES - $base)]);
        $room = Message::MAX_PAYLOAD_BYTES - strlen('{"alias":"big2","text":""}');
        $wide = $this->message('big2', payload: ['text' => str_repeat('é', intdiv($room, 2)) . str_repeat('a', $room % 2)]);
        self::assertSame([Message::MAX_PAYLOAD_BYTES, Message::MAX_PAYLOAD_BYTES], [$mixed->payloadSize(), $wide->payloadSize()]);
        $this->publish($mixed, $wide);
        self::assertSame(2, $this->relay()->relayPending(100)->deliveredCount());

        $this->assertTranscript(['big1 handled 1', 'big2 handled 1'], $this->consumer($source)->consume(10, $this->waitMs()));
        $out = [];
        foreach ($this->received as $message) {
            $out[(string) $message->payload['alias']] = $message->toJson();
        }
        ksort($out);
        self::assertSame(['big1' => $mixed->toJson(), 'big2' => $wide->toJson()], $out, 'byte for byte');
    }

    final public function testBatchesOfMixedKeysKeepEveryKeyInOrder(): void
    {
        $source = $this->bench->source('billing', [self::LEAD, self::CHAT]);
        $this->handle(self::LEAD)->handle(self::CHAT);
        $keys = ['a', 'b', 'c', null];
        for ($i = 1; $i <= 12; $i++) {
            $key = $keys[($i - 1) % 4];
            $this->publish($this->message("m{$i}", $i % 3 === 0 ? self::CHAT : self::LEAD, $key === null ? null : "key:{$key}"));
        }
        $relayed = $this->relay(batchSize: 5)->relayPending(100);
        self::assertSame(12, $relayed->deliveredCount());
        self::assertSame(3, $relayed->batchCount());
        self::assertSame(['m1', 'm2', 'm3', 'm4', 'm8', 'm5', 'm6', 'm7', 'm12', 'm9', 'm10', 'm11'], $this->aliasesOf($relayed->deliveredIds()), 'the heads of the keys and the messages without a key, five at a time');

        $this->assertTranscript(
            ['m1 handled 1', 'm2 handled 1', 'm3 handled 1', 'm4 handled 1', 'm8 handled 1', 'm5 handled 1', 'm6 handled 1', 'm7 handled 1', 'm12 handled 1', 'm9 handled 1', 'm10 handled 1', 'm11 handled 1'],
            $this->consumer($source)->consume(100, $this->waitMs())
        );
    }

    final public function testABatchOfManyKeysKeepsEveryKeyInOrder(): void
    {
        $source = $this->bench->source('billing', [self::LEAD]);
        $this->handle(self::LEAD);
        $published = [];
        for ($n = 1; $n <= 4; $n++) {
            foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'] as $key) {
                $published[] = $this->message(strtoupper($key) . $n, key: $key);
            }
            $published[] = $this->message('U' . $n);
        }
        $this->publish(...$published);

        $relayed = $this->relay()->relayPending(100);
        self::assertSame(4, $relayed->batchCount(), 'a round per position of the keys: every head of the outbox in one batch');
        self::assertSame(36, $relayed->deliveredCount());
        self::assertSame(['A1', 'B1', 'C1', 'D1', 'E1', 'F1', 'G1', 'H1', 'U1', 'U2', 'U3', 'U4', 'A2'], array_slice($this->aliasesOf($relayed->deliveredIds()), 0, 13));

        $report = $this->consumer($source)->consume(100, $this->waitMs());
        $byKey = [];
        foreach ($this->transcript($report) as $line) {
            [$alias, $outcome] = explode(' ', $line);
            self::assertSame('handled', $outcome, $line);
            $byKey[$alias[0]][] = (int) substr($alias, 1);
        }
        ksort($byKey);
        $byKey['U'] = isset($byKey['U']) ? self::sorted($byKey['U']) : [];
        self::assertSame(array_fill_keys(['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'U'], [1, 2, 3, 4]), $byKey);
    }

    final public function testADuplicateIsAbsorbedByTheInbox(): void
    {
        $source = $this->bench->source('billing', [self::LEAD]);
        $this->handle(self::LEAD);
        $message = $this->message('A', key: 'a');
        $this->publish($message);
        $this->relay()->relayPending(100);
        // The broker gets the message again: a relay that died between the confirm and the mark, a publisher that retried.
        $again = $this->bench->transport()->send([$message]);
        $this->bench->transport()->send([$message]);

        $report = $this->consumer($source)->consume(100, $this->waitMs());

        self::assertTrue($again->isConfirmed($message->messageId), 'the broker stores it again');
        $this->assertTranscript(['A handled 1', 'A duplicate 1', 'A duplicate 1'], $report);
        $this->assertEffects(['A']);
    }

    final public function testARelayThatDiesBetweenTheConfirmationAndTheMarkSendsAgainAndTheEffectRunsOnce(): void
    {
        $source = $this->bench->source('billing', [self::LEAD]);
        $this->handle(self::LEAD);

        // The process dies while it marks the outbox: the exception leaves the relay, nothing is marked.
        $m = $this->message('m', key: 'lead:1');
        $this->publish($m);
        $this->crashOnNextMark = true;
        try {
            $this->relay(crashing: true)->relayPending(100);
            self::fail('the relay died');
        } catch (RuntimeException $e) {
            self::assertSame(self::RELAY_DIED, $e->getMessage());
        }
        self::assertFalse((bool) $this->outbox->find($m->messageId)?->isDelivered(), 'not marked');
        self::assertSame(1, $this->relay(crashing: true)->relayPending(100)->deliveredCount(), 'sent again');
        self::assertTrue((bool) $this->outbox->find($m->messageId)?->isDelivered());

        // The process dies after the broker confirmed the batch: the relay takes it as a failed send.
        $this->publish($this->message('A', key: 'a'), $this->message('B', key: 'b'));
        $dying = self::dyingTransport($this->bench->transport());
        $died = $this->relay($dying)->relayPending(100);
        self::assertSame(0, $died->deliveredCount());
        self::assertSame(MessageOutboxStatus::FAILED, $this->outbox->find($this->idOf('A'))?->status, 'not marked delivered: to be sent again');
        self::assertSame(2, $this->relay()->relayPending(100)->deliveredCount(), 'sent again');

        $this->assertTranscript(['m handled 1', 'm duplicate 1', 'A handled 1', 'A duplicate 1', 'B handled 1', 'B duplicate 1'], $this->consumer($source)->consume(100, $this->waitMs()));
        $this->assertEffects(['m', 'A', 'B']);
    }

    final public function testTheDeliveriesOfADeadConsumerGoToItsSuccessor(): void
    {
        $source = $this->bench->source('billing', [self::LEAD], 'worker-1');
        $this->handle(self::LEAD);

        // It takes two of three deliveries and dies before it answers them.
        $this->publish($this->message('A1', key: 'a'), $this->message('A2', key: 'a'), $this->message('B1', key: 'b'));
        $this->relay()->relayPending(100);
        $taken = array_map(fn(ReceivedMessage $d): string => $this->label($d->isValid() ? $d->message()->messageId : null), $source->receive(2, $this->waitMs()));
        self::assertCount(2, $taken);
        $successor = $this->bench->successor($source);
        $expected = array_map(static fn(string $alias): string => sprintf('%s handled %d', $alias, in_array($alias, $taken, true) ? 2 : 1), ['A1', 'A2', 'B1']);
        $this->assertTranscript($expected, $this->consumer($successor)->consume(100, $this->waitMs()), 'what the dead one held, delivered again with the count raised; the rest once');

        // The successor takes every delivery and dies too: its own successor gets all of them again.
        $this->publish($this->message('r1', key: 'lead:3'), $this->message('r2', key: 'lead:3'), $this->message('r3', key: 'lead:4'));
        $this->relay()->relayPending(100);
        self::assertCount(3, $successor->receive(10, $this->waitMs()), 'the member took them and died before answering');
        $next = $this->bench->successor($successor);
        $consumer = $this->consumer($next);
        $this->assertTranscript(['r1 handled 2', 'r3 handled 2', 'r2 handled 2'], $consumer->consume(10, $this->waitMs()));
        self::assertSame([], $this->transcript($consumer->consume(10, $this->waitMs())));
        $this->assertEffects(['A1', 'A2', 'B1', 'r1', 'r2', 'r3']);
    }

    final public function testAProcessStartedAgainGetsWhatItLeftInFlight(): void
    {
        $source = $this->bench->source('billing', [self::LEAD], 'worker-1');
        $this->handle(self::LEAD);
        $this->publish($this->message('s1', key: 'lead:3'), $this->message('s2', key: 'lead:3'));
        $this->relay()->relayPending(100);
        $held = $source->receive(1, $this->waitMs());
        self::assertSame(['s1'], array_map(fn(ReceivedMessage $d): string => $this->label($d->isValid() ? $d->message()->messageId : null), $held), 'the first of the key');

        $restarted = $this->bench->restarted($source);
        $consumer = $this->consumer($restarted);
        $this->assertTranscript(['s1 handled 2', 's2 handled 1'], $consumer->consume(10, $this->waitMs()));
        self::assertSame([], $this->transcript($consumer->consume(10, $this->waitMs())));
    }

    final public function testALostConnectionFailsTheWholeBatchAndMarksNothing(): void
    {
        $source = $this->bench->source('billing', [self::LEAD]);
        $this->handle(self::LEAD);
        $messages = [$this->message('c1'), $this->message('c2', key: 'lead:1'), $this->message('c3', key: 'lead:2')];
        $this->publish(...$messages);

        $failed = $this->relay($this->bench->transportWithLostConnection())->relayPending(100);
        self::assertSame(0, $failed->deliveredCount());
        self::assertSame(3, $failed->failedCount());
        foreach ($messages as $message) {
            $entry = $this->outbox->find($message->messageId);
            self::assertFalse((bool) $entry?->isDelivered(), 'not marked delivered');
            self::assertSame(1, $entry?->attempts);
            self::assertNotSame('', trim($failed->getFailures()[$message->messageId] ?? ''), 'every message fails with a reason');
        }
        self::assertSame([], $this->transcript($this->consumer($source)->consume(10, $this->waitMs())), 'nothing reached the broker');

        self::assertSame(3, $this->relay()->relayPending(100)->deliveredCount(), 'sent when the broker is back');
        $this->assertTranscript(['c1 handled 1', 'c2 handled 1', 'c3 handled 1'], $this->consumer($source)->consume(10, $this->waitMs()));
    }

    final public function testNoReasonReportOrExceptionCarriesThePayload(): void
    {
        $secret = 'S3CR3T-' . bin2hex(random_bytes(6));
        $payload = ['card' => $secret, $secret => [$secret]];
        $source = $this->bench->source('billing', [self::LEAD, self::CHAT]);
        $this->handle(self::LEAD);
        $this->failuresLeft = ['x' => PHP_INT_MAX];
        $x = $this->message('x', key: 'lead:1', tenant: 'acme', payload: $payload, headers: ['x-note' => 'plain']);
        $this->publish($x, $this->message('c', self::CHAT, payload: $payload), $this->message('d', tenant: 'globex', payload: $payload));
        $texts = [];

        $failed = $this->relay($this->bench->transportWithLostConnection())->relayPending(100);
        self::assertSame(3, $failed->failedCount());
        $texts[] = (string) json_encode($failed->toArray());
        $this->relay()->relayPending(100);
        $errors = [];
        $consumer = $this->consumer($source, maxDeliveries: 1, onHandlerError: static function (Message $message, Throwable $error) use (&$errors): void {
            $errors[] = $error->getMessage();
        });
        $report = $consumer->consume(10, $this->waitMs());
        $this->assertTranscript(['x dead_lettered 1', 'c unknown_name 1', 'd handled 1'], $report);
        self::assertStringContainsString($secret, $errors[0] ?? '', 'the exception of the handler, given to onHandlerError, does quote the payload: the scenario would see a leak');
        $texts[] = (string) json_encode($report->toArray());
        $texts[] = (string) json_encode($this->bench->deadLetters($source));

        $this->bench->storeRaw(self::LEAD, '{"v":1,"payload":"' . $secret . '"');
        $invalid = $consumer->consume(10, $this->waitMs());
        $this->assertTranscript(['invalid dead_lettered 1'], $invalid);
        $texts[] = (string) json_encode($invalid->toArray());
        $texts[] = (string) json_encode($this->bench->deadLetters($source));

        $this->bench->transport()->send([$x]);
        $deliveries = $source->receive(10, $this->waitMs());
        self::assertCount(1, $deliveries);
        $source->ack($deliveries[0]);
        $answered = 0;
        foreach ([$deliveries[0], ReceivedMessage::invalid(1, '7.7', 'never handed out')] as $notInFlight) {
            try {
                $source->ack($notInFlight);
                self::fail('a delivery that is not in flight cannot be acknowledged');
            } catch (MessagingException $e) {
                $texts[] = $e->getMessage();
                $answered++;
            }
        }
        self::assertSame(2, $answered, 'an acknowledgement of a delivery not in flight throws MessagingException');
        foreach ($texts as $text) {
            self::assertStringNotContainsString($secret, $text);
        }
    }

    final public function testEachBenchIsAWorldOfItsOwn(): void
    {
        $other = $this->createBench();
        try {
            $mine = $this->bench->source('billing', [self::LEAD]);
            $theirs = $other->source('billing', [self::LEAD]);
            $here = $this->message('here', key: 'lead:1');
            $there = $this->message('there', key: 'lead:1');
            $this->bench->transport()->send([$here]);
            $other->transport()->send([$there]);

            $read = function (IMessageSource $source): array {
                $labels = [];
                foreach ($source->receive(10, $this->waitMs()) as $delivery) {
                    $labels[] = $this->label($delivery->isValid() ? $delivery->message()->messageId : null);
                    $source->ack($delivery);
                }

                return $labels;
            };
            self::assertSame(['here'], $read($mine), 'a source of this bench sees the messages of this bench only');
            self::assertSame(['there'], $read($theirs), 'and the other bench, its own');
        } finally {
            $other->close();
        }
    }

    #[DataProvider('propertySeeds')]
    final public function testRandomInterleavingsKeepTheGuarantees(int $seed): void
    {
        $steps = $this->propertySteps();
        self::assertGreaterThanOrEqual(self::MIN_PROPERTY_STEPS, $steps, sprintf('propertySteps() may raise the steps of the property sequence, never set them below %d', self::MIN_PROPERTY_STEPS));
        $random = new Randomizer(new Mt19937($seed));
        $names = [self::LEAD, self::UPDATED];
        $keys = ['lead:1', 'lead:2', 'lead:3', null];
        foreach ($names as $name) {
            $this->handle($name);
        }

        /** @var list<string> $published the model: aliases in the order of publication */
        $published = [];
        /** @var list<string> $poison */
        $poison = [];
        /** @var list<?string> $dead every dead letter of the consumer, the runs that died included */
        $dead = [];
        /** @var list<ConsumeEntry> $entries */
        $entries = [];
        $stats = ['relayDeaths' => 0, 'consumerDeaths' => 0, 'copies' => 0, 'lostConnections' => 0];
        $source = $this->bench->source('billing', $names);

        $consume = function (int $max, int $dieAtAck) use (&$source, &$dead, &$entries, &$stats, $random): int {
            $dying = self::dyingSource($source, $dieAtAck);
            try {
                $report = $this->consumer($dying['source'])->consume($max, $this->waitMs());
            } catch (RuntimeException $e) {
                self::assertSame(self::CONSUMER_DIED, $e->getMessage(), 'only the planned death stops a run');
                $stats['consumerDeaths']++;
                $dead = [...$dead, ...$dying['dead']()];
                $source = $random->getInt(0, 1) === 0 ? $this->bench->restarted($source) : $this->bench->successor($source);

                return 1;
            }
            self::assertSame(
                array_map(static fn(ConsumeEntry $e): ?string => $e->messageId, $report->of(ConsumeOutcome::DEAD_LETTERED)),
                $dying['dead'](),
                'a run reports every dead letter it made'
            );
            $dead = [...$dead, ...$dying['dead']()];
            $entries = [...$entries, ...$report->entries()];

            return $report->receivedCount();
        };

        for ($step = 0; $step < $steps; $step++) {
            $action = $random->getInt(1, 20);
            if ($action <= 7) {
                $batch = [];
                for ($i = $random->getInt(1, 3); $i > 0; $i--) {
                    $alias = 'p' . (count($published) + count($batch));
                    $roll = $random->getInt(1, 20);
                    $this->failuresLeft[$alias] = match (true) {
                        $roll === 1 => PHP_INT_MAX,
                        $roll <= 4 => 1,
                        $roll === 5 => 2,
                        default => 0,
                    };
                    if ($roll === 1) {
                        $poison[] = $alias;
                    }
                    $batch[] = $this->message($alias, $names[$random->getInt(0, 1)], $keys[$random->getInt(0, 3)]);
                }
                $this->publish(...$batch);
                $published = [...$published, ...array_map(fn(Message $m): string => $this->label($m->messageId), $batch)];
            } elseif ($action <= 11) {
                $death = $random->getInt(1, 6);
                $batchSize = $random->getInt(1, 3);
                $limit = $random->getInt(1, 5);
                $this->crashOnNextMark = $death === 1;
                $transport = $death === 2 ? self::dyingTransport($this->bench->transport()) : $this->bench->transport();
                try {
                    $report = $this->relay($transport, $batchSize, crashing: true)->relayPending($limit);
                    $stats['relayDeaths'] += $death === 2 && $report->failedCount() > 0 ? 1 : 0;
                } catch (RuntimeException $e) {
                    self::assertSame(self::RELAY_DIED, $e->getMessage(), 'only the planned death stops the relay');
                    $stats['relayDeaths']++;
                }
                $this->crashOnNextMark = false; // a crash that did not happen (nothing confirmed) is not kept
            } elseif ($action === 12) {
                $report = $this->relay($this->bench->transportWithLostConnection(), $random->getInt(1, 3))->relayPending($random->getInt(1, 5));
                self::assertSame(0, $report->deliveredCount(), 'a lost connection confirms nothing');
                $stats['lostConnections'] += $report->failedCount() > 0 ? 1 : 0;
            } elseif ($action <= 18) {
                $max = $random->getInt(1, 4);
                $consume($max, $random->getInt(1, 6) === 1 ? $random->getInt(1, 2) : 0);
                $this->assertKeysInOrder($published, $dead, false);
            } elseif ($published !== []) {
                $alias = $published[$random->getInt(0, count($published) - 1)];
                $entry = $this->outbox->find($this->idOf($alias));
                if ($entry !== null && $entry->isDelivered()) {
                    self::assertTrue($this->bench->transport()->send([$entry->message])->isConfirmed($entry->messageId()), 'the broker stores a copy');
                    $stats['copies']++;
                }
            }
        }

        // Drain: relay everything, then consume until the broker has nothing left.
        for ($i = 0; $i < 100 && $this->outbox->fetchPending(1) !== []; $i++) {
            $this->relay()->relayPending(100);
        }
        self::assertSame([], $this->outbox->fetchPending(1), 'the outbox is drained');
        for ($empty = 0, $i = 0; $empty < 3 && $i < 300; $i++) {
            $empty = $consume(10, 0) === 0 ? $empty + 1 : 0;
        }
        self::assertSame(3, $empty, 'the broker is drained');

        // The effects are the truth of what was handled: a run that died lost its report, not its commits.
        $effects = $this->effects();
        $deadAliases = array_map(fn(?string $id): string => $this->label($id), $dead);
        foreach ($published as $alias) {
            $times = count(array_keys($effects, $alias, true));
            self::assertLessThanOrEqual(1, $times, "{$alias}: no effect twice");
            self::assertTrue($times === 1 || in_array($alias, $deadAliases, true), "{$alias}: handled or dead-lettered, never lost");
        }
        self::assertSame([], array_values(array_intersect($poison, $effects)), 'no poison message has an effect');
        self::assertSame([], array_values(array_diff($poison, $deadAliases)), 'every poison message is a dead letter');
        $this->assertKeysInOrder($published, $dead, true);
        self::assertLessThanOrEqual(count($effects), count(array_filter($entries, static fn(ConsumeEntry $e): bool => $e->outcome === ConsumeOutcome::HANDLED)), 'every handled delivery reported has its effect');

        $reported = $deadAliases;
        $stored = array_map(fn(array $d): string => $this->label($d['messageId']), $this->bench->deadLetters($source));
        sort($reported);
        sort($stored);
        self::assertSame($reported, $stored, 'the dead letter queue of the broker holds exactly what the reports dead-lettered');

        self::assertGreaterThan(60, count($published), 'the sequence publishes');
        self::assertNotSame([], $dead, 'the sequence reaches dead letters');
        self::assertGreaterThan(0, $stats['relayDeaths'], 'the sequence kills a relay between the confirmation and the mark');
        self::assertGreaterThan(0, $stats['consumerDeaths'], 'the sequence kills a consumer before an acknowledgement');
        self::assertGreaterThan(0, $stats['copies'], 'the sequence makes the broker store copies');
        self::assertGreaterThan(0, $stats['lostConnections'], 'the sequence loses the connection of a relay');
        self::assertNotSame([], array_filter($entries, static fn(ConsumeEntry $e): bool => $e->outcome === ConsumeOutcome::DUPLICATE), 'the inbox absorbs duplicates');
        self::assertNotSame([], array_filter($entries, static fn(ConsumeEntry $e): bool => $e->outcome === ConsumeOutcome::HELD_BACK), 'the sequence holds deliveries back');
        self::assertNotSame([], array_filter($entries, static fn(ConsumeEntry $e): bool => $e->outcome === ConsumeOutcome::RETRIED), 'the sequence rejects with requeue');
    }

    // =====================================================================
    // The application around the bench
    // =====================================================================

    /** The wait the bench asks for. */
    protected function waitMs(): int
    {
        return $this->bench->receiveWaitMs();
    }

    /**
     * A message named by $alias: its payload carries the alias (`alias`), its id is derived from the alias, and
     * every reason and transcript names it by the alias.
     *
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    protected function message(string $alias, string $name = self::LEAD, ?string $key = null, ?string $tenant = null, array $payload = [], array $headers = []): Message
    {
        $this->keys[$alias] = $key;

        return new Message($name, ['alias' => $alias] + $payload, $key, $tenant, $headers, $this->idOf($alias), new DateTimeImmutable('2026-11-15T12:00:00.123456Z'));
    }

    /** Publishes messages in a transaction of the application. */
    protected function publish(Message ...$messages): int
    {
        return (int) $this->transactions->transactional(fn(): int => $this->publisher->publish(...$messages));
    }

    /** Registers the handler of a name: it records the alias as its effect and fails while the alias has failures left. */
    protected function handle(string $name): self
    {
        $this->handlers->register($name, function (Message $message): void {
            $alias = (string) $message->payload['alias'];
            $this->received[] = $message;
            $this->effectLog->importState([...$this->effectLog->exportState(), $alias]);
            if (($this->failuresLeft[$alias] ?? 0) > 0) {
                $this->failuresLeft[$alias]--;
                // The message of a handler's exception may quote the payload; no reason repeats it.
                throw new RuntimeException('could not handle ' . json_encode($message->payload));
            }
        });

        return $this;
    }

    protected function relay(?IMessageTransport $transport = null, int $batchSize = 100, bool $crashing = false): OutboxRelay
    {
        return new OutboxRelay($crashing ? $this->crashingOutbox : $this->outbox, $transport ?? $this->bench->transport(), batchSize: $batchSize);
    }

    /**
     * @param (Closure(Message, Throwable, int): void)|null $onHandlerError
     */
    protected function consumer(IMessageSource $source, string $consumer = 'billing', ?string $tenant = null, int $maxDeliveries = self::MAX_DELIVERIES, ?Closure $onHandlerError = null): MessageConsumer
    {
        return new MessageConsumer($consumer, $source, $this->handlers, $this->inbox, $this->transactions, $maxDeliveries, $tenant, onHandlerError: $onHandlerError);
    }

    /**
     * The transcript of a report: `<alias> <outcome> <delivery count>` per delivery (`invalid` for an invalid one).
     *
     * @return list<string>
     */
    protected function transcript(ConsumeReport $report): array
    {
        return array_map(fn(ConsumeEntry $entry): string => sprintf('%s %s %d', $this->label($entry->messageId), $entry->outcome->value, $entry->deliveryCount), $report->entries());
    }

    /**
     * Asserts a transcript as the contract defines order: the lines of each ordering key in sequence, the lines
     * of messages without a key (and of invalid deliveries) in any order.
     *
     * @param list<string> $expected
     * @param ConsumeReport|list<string> $actual
     */
    protected function assertTranscript(array $expected, ConsumeReport|array $actual, string $message = ''): void
    {
        $lines = $actual instanceof ConsumeReport ? $this->transcript($actual) : $actual;
        self::assertSame($this->byKey($expected), $this->byKey($lines), sprintf(
            "%sexpected (in order within each ordering key): %s\nactual: %s",
            $message === '' ? '' : $message . "\n",
            implode(', ', $expected),
            implode(', ', $lines)
        ));
    }

    /**
     * Asserts the effects of the handlers, ordered as assertTranscript() orders lines.
     *
     * @param list<string> $expected aliases
     */
    protected function assertEffects(array $expected): void
    {
        $this->assertTranscript($expected, $this->effects(), 'the effects of the handlers');
    }

    /**
     * The effects committed by the handlers so far (the alias of each message handled).
     *
     * @return list<string>
     */
    protected function effects(): array
    {
        return array_values(array_map('strval', $this->effectLog->exportState()));
    }

    /**
     * The dead letters of the consumer of a source, read from the broker, aliased.
     *
     * @return list<array{messageId: ?string, reason: string, deliveryCount: int}>
     */
    protected function deadLetters(IMessageSource $source): array
    {
        return array_map(fn(array $dead): array => [
            'messageId' => $dead['messageId'] === null ? null : $this->label($dead['messageId']),
            'reason' => $this->aliased($dead['reason']),
            'deliveryCount' => $dead['deliveryCount'],
        ], $this->bench->deadLetters($source));
    }

    /**
     * The reasons of a report, aliased.
     *
     * @return list<?string>
     */
    protected function reasons(ConsumeReport $report): array
    {
        return array_map(fn(ConsumeEntry $e): ?string => $e->reason === null ? null : $this->aliased($e->reason), $report->entries());
    }

    /** A text with every message id replaced by its alias. */
    protected function aliased(?string $text): string
    {
        return strtr((string) $text, $this->aliases);
    }

    /** The alias of a message id (`invalid` for null, `unpublished` for an id the battery did not make). */
    protected function label(?string $messageId): string
    {
        return $messageId === null ? 'invalid' : ($this->aliases[$messageId] ?? 'unpublished');
    }

    /** The id of an alias: a ULID derived from the alias and the salt of the scenario. */
    protected function idOf(string $alias): string
    {
        $hash = hash('sha256', $this->salt . "\0" . $alias);
        $id = '01JD';
        for ($i = 0; $i < 22; $i++) {
            $id .= self::ALPHABET[hexdec(substr($hash, $i * 2, 2)) % 32];
        }
        $this->aliases[$id] = $alias;

        return $id;
    }

    /**
     * Consumes runs of $max until a run is empty.
     *
     * @return list<string> The transcript of every run
     */
    protected function drain(IMessageSource $source, int $max): array
    {
        $consumer = $this->consumer($source);
        $lines = [];
        for ($i = 0; $i < 100; $i++) {
            $round = $this->transcript($consumer->consume($max, $this->waitMs()));
            if ($round === []) {
                return $lines;
            }
            $lines = [...$lines, ...$round];
        }
        self::fail('the source never ran dry');
    }

    // =====================================================================
    // Support
    // =====================================================================

    /**
     * @param list<string> $aliases
     * @return list<string>
     */
    private function aliasesOf(array $aliases): array
    {
        return array_map($this->label(...), $aliases);
    }

    /**
     * The model check of the property sequence: the messages of each key were handled in the order they were
     * published, leaving out the dead-lettered ones; at the end, every one of them was handled.
     *
     * @param list<string> $published
     * @param list<?string> $dead
     */
    private function assertKeysInOrder(array $published, array $dead, bool $complete): void
    {
        $deadAliases = array_flip(array_map(fn(?string $id): string => $this->label($id), $dead));
        $effects = $this->effects();
        foreach (array_unique(array_filter($this->keys, static fn(?string $key): bool => $key !== null)) as $key) {
            $expected = array_values(array_filter($published, fn(string $a): bool => $this->keys[$a] === $key && !isset($deadAliases[$a])));
            $actual = array_values(array_filter($effects, fn(string $a): bool => ($this->keys[$a] ?? null) === $key && !isset($deadAliases[$a])));
            if (!$complete) {
                // A message dead-lettered later may still be in this run's effects: compare the handled ones only.
                $handled = array_flip($actual);
                $expected = array_values(array_filter($expected, static fn(string $a): bool => isset($handled[$a])));
            }
            self::assertSame($expected, $actual, "the order of key {$key}");
        }
    }

    /**
     * @param list<string> $lines
     * @return array<string, list<string>>
     */
    private function byKey(array $lines): array
    {
        $groups = [];
        foreach ($lines as $line) {
            $alias = explode(' ', $line, 2)[0];
            $key = $this->keys[$alias] ?? null;
            $groups[$key === null ? '(no key)' : 'key ' . $key][] = $line;
        }
        if (isset($groups['(no key)'])) {
            $groups['(no key)'] = self::sorted($groups['(no key)']);
        }
        ksort($groups);

        return $groups;
    }

    /**
     * @template T
     * @param list<T> $values
     * @return list<T>
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    /** The effects of the handlers, rolled back with the inbox: what a handler writes through its connection. */
    private static function effectLog(): IInMemoryTransactional
    {
        return new class () implements IInMemoryTransactional {
            /** @var list<string> */
            private array $effects = [];

            public function exportState(): array
            {
                return $this->effects;
            }

            public function importState(array $state): void
            {
                $this->effects = array_values(array_map('strval', $state));
            }
        };
    }

    /**
     * An outbox whose process dies while it marks a message delivered, when $crash answers true.
     *
     * @param Closure(): bool $crash
     */
    private static function crashingOutbox(IMessageOutbox $outbox, Closure $crash): IMessageOutbox
    {
        return new class ($outbox, $crash) implements IMessageOutbox {
            public function __construct(private readonly IMessageOutbox $outbox, private readonly Closure $crash)
            {
            }

            public function enqueue(array $entries): int
            {
                return $this->outbox->enqueue($entries);
            }

            public function fetchPending(int $limit = 100, ?int $maxAttempts = null): array
            {
                return $this->outbox->fetchPending($limit, $maxAttempts);
            }

            public function markDelivered(MessageOutboxEntry $entry, DateTimeImmutable $at): void
            {
                if (($this->crash)()) {
                    throw new RuntimeException('The process of the relay died after the broker confirmed and before the outbox was marked.');
                }
                $this->outbox->markDelivered($entry, $at);
            }

            public function markFailed(MessageOutboxEntry $entry, string $reason, DateTimeImmutable $at): void
            {
                $this->outbox->markFailed($entry, $reason, $at);
            }

            public function find(string $messageId): ?MessageOutboxEntry
            {
                return $this->outbox->find($messageId);
            }

            public function findByOrderingKey(string $orderingKey): array
            {
                return $this->outbox->findByOrderingKey($orderingKey);
            }

            public function count(): int
            {
                return $this->outbox->count();
            }
        };
    }

    /** A transport whose process dies after the broker confirmed a batch: the relay never gets the result. */
    private static function dyingTransport(IMessageTransport $inner): IMessageTransport
    {
        return new class ($inner) implements IMessageTransport {
            public function __construct(private readonly IMessageTransport $inner)
            {
            }

            public function send(array $batch): TransportSendResult
            {
                $this->inner->send($batch);

                throw new RuntimeException('The process of the relay died after the broker confirmed the batch.');
            }
        };
    }

    /**
     * A source whose process dies at the n-th acknowledgement (0 = never): after the commit of the inbox row and
     * the effect, before the broker hears of it; what it received and did not answer stays in flight. `dead`
     * returns the message ids of the deliveries the consumer dead-lettered through it.
     *
     * @return array{source: IMessageSource, dead: Closure(): list<?string>}
     */
    private static function dyingSource(IMessageSource $inner, int $dieAtAck): array
    {
        /** @var ArrayObject<int, ?string> $dead */
        $dead = new ArrayObject();
        $source = new class ($inner, $dieAtAck, $dead) implements IMessageSource {
            private int $acks = 0;

            /** @param ArrayObject<int, ?string> $dead */
            public function __construct(private readonly IMessageSource $inner, private readonly int $dieAtAck, private readonly ArrayObject $dead)
            {
            }

            public function receive(int $max, int $waitMs): array
            {
                return $this->inner->receive($max, $waitMs);
            }

            public function ack(ReceivedMessage $message): void
            {
                if (++$this->acks === $this->dieAtAck) {
                    throw new RuntimeException('The process of the consumer died after the commit and before the acknowledgement.');
                }
                $this->inner->ack($message);
            }

            public function reject(ReceivedMessage $message, bool $requeue, string $reason): void
            {
                $this->inner->reject($message, $requeue, $reason);
                if (!$requeue) {
                    $this->dead->append($message->isValid() ? $message->message()->messageId : null);
                }
            }
        };

        return ['source' => $source, 'dead' => static fn(): array => array_values($dead->getArrayCopy())];
    }
}
