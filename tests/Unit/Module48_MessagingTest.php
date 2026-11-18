<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\DomainEvents\DomainEvent;
use Antevemus\ASpecification\DomainEvents\DomainEventDispatcher;
use Antevemus\ASpecification\DomainEvents\DomainEventHandlerRegistry;
use Antevemus\ASpecification\DomainEvents\DomainEventKind;
use Antevemus\ASpecification\DomainEvents\Outbox\InMemoryDomainEventOutbox;
use Antevemus\ASpecification\DomainEvents\OutboxDomainEventPublisher;
use Antevemus\ASpecification\Messaging\ConsumeEntry;
use Antevemus\ASpecification\Messaging\ConsumeOutcome;
use Antevemus\ASpecification\Messaging\ConsumeReport;
use Antevemus\ASpecification\Messaging\DomainEventMessageBridge;
use Antevemus\ASpecification\Messaging\Exceptions\InvalidMessageException;
use Antevemus\ASpecification\Messaging\Exceptions\InvalidMessagePayloadException;
use Antevemus\ASpecification\Messaging\Exceptions\MessagingException;
use Antevemus\ASpecification\Messaging\Exceptions\MessagingPersistenceException;
use Antevemus\ASpecification\Messaging\IMessageSource;
use Antevemus\ASpecification\Messaging\IMessageTransport;
use Antevemus\ASpecification\Messaging\Inbox\InMemoryProcessedMessageStore;
use Antevemus\ASpecification\Messaging\Inbox\IProcessedMessageStore;
use Antevemus\ASpecification\Messaging\Inbox\PdoProcessedMessageStore;
use Antevemus\ASpecification\Messaging\Inbox\ProcessedMessage;
use Antevemus\ASpecification\Messaging\InMemory\InMemoryMessageSource;
use Antevemus\ASpecification\Messaging\InMemory\InMemoryMessageTransport;
use Antevemus\ASpecification\Messaging\InMemory\InMemoryTransportFault;
use Antevemus\ASpecification\Messaging\Message;
use Antevemus\ASpecification\Messaging\MessageConsumer;
use Antevemus\ASpecification\Messaging\MessageHandlerRegistry;
use Antevemus\ASpecification\Messaging\MessageMachineBridge;
use Antevemus\ASpecification\Messaging\MessagePublisher;
use Antevemus\ASpecification\Messaging\Outbox\IMessageOutbox;
use Antevemus\ASpecification\Messaging\Outbox\InMemoryMessageOutbox;
use Antevemus\ASpecification\Messaging\Outbox\MessageOutboxEntry;
use Antevemus\ASpecification\Messaging\Outbox\MessageOutboxStatus;
use Antevemus\ASpecification\Messaging\Outbox\PdoMessageOutbox;
use Antevemus\ASpecification\Messaging\OutboxRelay;
use Antevemus\ASpecification\Messaging\ReceivedMessage;
use Antevemus\ASpecification\Messaging\RelayReport;
use Antevemus\ASpecification\Messaging\Support\MessagingSupport;
use Antevemus\ASpecification\Messaging\Support\PdoMessagingTable;
use Antevemus\ASpecification\Messaging\TransportSendResult;
use Antevemus\ASpecification\Persistence\ITransactionManager;
use Antevemus\ASpecification\Persistence\PdoTransactionManager;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\StateMachine\EventOrigin;
use Antevemus\ASpecification\StateMachine\Exceptions\MachineInstanceNotFoundException;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\InMemoryMachineDefinitionRepository;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\InMemoryTransactionManager;
use Antevemus\ASpecification\StateMachine\Persistence\MachinePersistence;
use Antevemus\ASpecification\StateMachine\Runner\MachineRunner;
use Antevemus\ASpecification\Tests\Support\Module48Crash;
use Antevemus\ASpecification\Tests\Support\Module48CrashingOutbox;
use Antevemus\ASpecification\Tests\Support\Module48CrashingSource;
use Antevemus\ASpecification\Tests\Support\Module48EffectLog;
use Antevemus\ASpecification\Tests\Support\Module48FailingPdo;
use Antevemus\ASpecification\Tests\Support\Module48FailingStatement;
use Antevemus\ASpecification\Tests\Support\ReadmeBlocks;
use Antevemus\ASpecification\Tests\TestCase;
use Antevemus\ASpecification\Util\UlidGenerator;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Error;
use InvalidArgumentException;
use LogicException;
use PDO;
use PDOException;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;
use Throwable;

/**
 * Module48_MessagingTest - Forward 063 (2.14.0): the broker-agnostic messaging hook of the core.
 *
 * - D2: Message (versioned name, JSON-safe payload up to 65 535 bytes, ULID, UTC instant, ordering key, tenant,
 *   headers, the wire form v1 read back without loss, over random payloads of fixed seeds);
 * - D3/D4/RN-02: MessagePublisher writes the outbox in the caller's transaction only; InMemoryMessageOutbox and
 *   PdoMessageOutbox answer the same battery (runContract()), the heads per ordering key, the exhaustion, the marks;
 * - D5/D6/RN-03: TransportSendResult and OutboxRelay (batches with one message per key, only what the broker confirmed
 *   marked delivered, a failure holding its key back for the run, the exhausted ones reported);
 * - D7: IMessageSource, ReceivedMessage, InMemoryMessageTransport and InMemoryMessageSource (faults, reordering across
 *   keys, redelivery first, dead letters, fan-out to every source);
 * - D8/D10/RN-04: MessageConsumer with the inbox (InMemoryProcessedMessageStore and PdoProcessedMessageStore, the same
 *   battery), the transaction of the row and the handlers, every outcome of ConsumeOutcome, maxDeliveries, the payload
 *   refused by a handler, the names without a handler, the tenant-restricted consumer;
 * - D9: DomainEventMessageBridge (inherited idempotency) and MessageMachineBridge (a machine moved once);
 * - D13: property sequences (seeds SEEDS) of publications, relays and consumptions over random ordering keys through a
 *   transport that fails, loses confirmations, duplicates and reorders across keys, with crashes between the
 *   confirmation and the mark and between the commit and the acknowledgement, and handlers that fail: no confirmed
 *   message lost, each effect once per consumer, the order of each key kept, the dead letters reported; on memory, on
 *   SQLite and, with ASPEC_PDO_{PGSQL,MYSQL,MARIADB}_DSN, on PostgreSQL 17, MySQL 8.4 and MariaDB 11.4, the transcript of
 *   every database equal to the one of memory;
 * - RN-05: no reason, exception message nor report carries the payload (a secret planted in every payload);
 * - the coverage guard: every file of src/Messaging/ is declared here, imported by this module and in the mutation scope;
 * - D14: examples 26 to 26.3 of docs/<language>/messaging/index.md run as written, the same code in both languages.
 *
 * @version    2.14.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Unit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class Module48_MessagingTest extends TestCase
{
    private const DDL = __DIR__ . '/../../resources/sql/messaging.ansi.sql';

    /** Seeds of the property sequences (D13): fixed, registered in the forward and in quality/tests.md. */
    private const SEEDS = [20261115, 20261116, 20261117];

    private const STEPS_MEMORY = 400;

    private const STEPS_SERVER = 120;

    /** Planted in every payload: it must never reach a reason, a message of an exception nor a report (RN-05). */
    private const SECRET = 'SECRET-PAYLOAD-063-c41e';

    /** Every file of src/Messaging/, by path without `.php` (the coverage guard). */
    private const MESSAGING_FILES = [
        'ConsumeEntry', 'ConsumeOutcome', 'ConsumeReport', 'DomainEventMessageBridge',
        'Exceptions/InvalidMessageException', 'Exceptions/InvalidMessagePayloadException', 'Exceptions/MessagingException',
        'Exceptions/MessagingPersistenceException', 'IMessageSource', 'IMessageTransport',
        'InMemory/InMemoryMessageSource', 'InMemory/InMemoryMessageTransport', 'InMemory/InMemoryTransportFault',
        'Inbox/IProcessedMessageStore', 'Inbox/InMemoryProcessedMessageStore', 'Inbox/PdoProcessedMessageStore',
        'Inbox/ProcessedMessage', 'Message', 'MessageConsumer', 'MessageHandlerRegistry', 'MessageMachineBridge',
        'MessagePublisher', 'Outbox/IMessageOutbox', 'Outbox/InMemoryMessageOutbox', 'Outbox/MessageOutboxEntry',
        'Outbox/MessageOutboxStatus', 'Outbox/PdoMessageOutbox', 'OutboxRelay', 'ReceivedMessage', 'RelayReport',
        'Support/MessagingSupport', 'Support/PdoMessagingTable', 'TransportSendResult',
    ];

    /** @var list<string> Every reason, report line and exception message seen, checked for the secret (RN-05) */
    private array $seen = [];

    private int $tick = 0;

    private int $effectSeq = 0;

    public function run(): void
    {
        $this->testMessageNames();
        $this->testMessagePayload();
        $this->testMessageFields();
        $this->testWireForm();
        $this->testWireFormRoundTripProperty();
        $this->testSupport();
        $this->testValueObjects();
        $this->testTransportSendResult();
        $this->testReceivedMessage();
        $this->testHandlerRegistry();
        $this->testInMemoryTransport();
        $this->testInMemorySource();
        $this->testReorderAcrossKeysProperty();
        $this->testBoundaries();

        $this->runBatteries('memória', fn(): array => $this->memoryEnvironment());
        $this->testConsumerWithoutTransactions();
        $this->testConsumerPropagatesInfrastructureFailures();
        $this->testDomainEventBridge();
        $this->testMachineBridge();
        $memory = [];
        foreach (self::SEEDS as $seed) {
            $memory[$seed] = $this->runSequence($this->memoryEnvironment(), $seed, self::STEPS_MEMORY, 'memória');
        }

        if (!extension_loaded('pdo_sqlite')) {
            fwrite(STDOUT, "    [AVISO] pdo_sqlite ausente; o Módulo 48 roda só a parte em memória.\n");
            $this->testCoverageGuard(false);
            $this->assertNoSecretSeen();

            return;
        }

        $this->assertTrue(is_file(self::DDL), 'DDL de referência da mensageria deve existir');
        $this->runDatabase('SQLite', static fn(): PDO => self::sqliteEmpty(), $memory, self::STEPS_MEMORY);
        $this->testDriverFailuresWithoutTheDriverMessage();
        $this->testConsumerTablesWithOtherColumnTypes();
        $this->testExamplesAreTheSameInBothLanguages();
        $this->testExamplesRunAsWritten();

        foreach (['pgsql' => 'PostgreSQL', 'mysql' => 'MySQL', 'mariadb' => 'MariaDB'] as $family => $label) {
            $pdo = self::connectFromEnvironment($family);
            if ($pdo === null) {
                fwrite(STDOUT, '    [AVISO] ASPEC_PDO_' . strtoupper($family) . "_DSN não definido; Módulo 48 em {$label} pulado.\n");
                continue;
            }
            $before = $this->getAssertionCount();
            $short = [];
            foreach (self::SEEDS as $seed) {
                $short[$seed] = $this->runSequence($this->memoryEnvironment(), $seed, self::STEPS_SERVER, 'memória');
            }
            $this->runDatabase($label, static fn(): PDO => self::connectFromEnvironment($family) ?? throw new RuntimeException('no connection'), $short, self::STEPS_SERVER);
            self::dropSchema($pdo);
            fwrite(STDOUT, "    [063] {$label} " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . ": outbox, inbox, relay, consumidor, propriedades e paridade com a memória OK (" . ($this->getAssertionCount() - $before) . " asserções)\n");
        }

        $this->testCoverageGuard(true);
        $this->assertNoSecretSeen();
    }

    // =====================================================================
    // D2: the message
    // =====================================================================

    private function testMessageNames(): void
    {
        foreach (['livechat.message_received.v1', 'crm.lead.created.v12', 'a.b.v1', 'agents2.persona_trigger_fired.v3', str_repeat('a', 100) . '.' . str_repeat('b', 16) . '.v1'] as $name) {
            $this->assertTrue(Message::isValidName($name), "nome válido: {$name}");
            $this->assertEquals($name, (new Message($name))->name);
        }
        $invalid = [
            '', 'Livechat.message.v1', 'livechat.v1', 'livechat.message', 'livechat.message.v0', 'livechat.message.V1',
            'livechat..message.v1', ' a.b.v1', 'a.b.v1 ', 'a-b.c.v1', '1a.b.v1', 'a.b.v01', 'a.b.v1.', 'a.b.c',
            'a.b.vx', 'á.b.v1', "a.b.v1\n", str_repeat('a', 101) . '.' . str_repeat('b', 16) . '.v1',
        ];
        foreach ($invalid as $name) {
            $this->assertFalse(Message::isValidName($name), 'nome inválido: ' . json_encode($name));
            $e = $this->expectInvalid(fn() => new Message($name, ['secret' => self::SECRET]));
            $this->assertTrue($e instanceof InvalidArgumentException, 'InvalidMessageException é InvalidArgumentException');
            $this->assertFalse($name !== '' && str_contains($e->getMessage(), $name), 'RN-05: o nome recusado não é repetido');
        }
        $this->assertEquals(120, strlen(str_repeat('a', 100) . '.' . str_repeat('b', 16) . '.v1'), 'o nome mais longo aceito tem 120 caracteres');
    }

    private function testMessagePayload(): void
    {
        $resource = fopen('php://memory', 'r');
        $deep = [];
        for ($i = 0; $i < 63; $i++) {
            $deep = [$deep];
        }
        $this->assertEquals(64, self::depth($deep), 'a carga de 64 níveis');
        $this->assertEquals($deep, (new Message('a.b.v1', $deep))->payload, '64 níveis passam (a carga é o nível 1)');
        $invalid = [
            'objeto' => ['secret' => self::SECRET, 'x' => (object) ['a' => 1]],
            'INF' => ['secret' => self::SECRET, 'x' => INF],
            'NAN' => ['secret' => self::SECRET, 'x' => [NAN]],
            'texto inválido' => ['secret' => self::SECRET, 'x' => "\xB1\x31"],
            'chave inválida' => ['secret' => self::SECRET, "\xB1" => 1],
            'closure' => ['secret' => self::SECRET, 'x' => static fn(): int => 1],
            'recurso' => ['secret' => self::SECRET, 'x' => $resource],
            'fundo demais' => ['secret' => self::SECRET, 'x' => [$deep]],
            '65 níveis' => [$deep],
            'depois de um array' => ['a' => [], 'secret' => self::SECRET, 'b' => INF],
        ];
        $why = [
            'objeto' => 'a value of type stdClass', 'INF' => 'not finite', 'NAN' => 'not finite', 'texto inválido' => 'a text that is not valid UTF-8',
            'chave inválida' => 'a key that is not valid UTF-8', 'closure' => 'a value of type Closure', 'recurso' => 'a value of type resource',
            'fundo demais' => 'nested deeper than 64 levels', '65 níveis' => 'nested deeper than 64 levels', 'depois de um array' => 'not finite',
        ];
        foreach ($invalid as $label => $payload) {
            $e = $this->expectInvalid(fn() => new Message('a.b.v1', $payload), "carga recusada: {$label}");
            $this->assertTrue(str_contains($e->getMessage(), 'message "a.b.v1"') && str_contains($e->getMessage(), $why[$label]), "a recusa nomeia a mensagem e o motivo: {$label}: " . $e->getMessage());
        }
        fclose($resource);

        $fits = new Message('a.b.v1', ['s' => str_repeat('a', 65527)]);
        $this->assertEquals(65535, $fits->payloadSize(), '65 535 bytes cabem');
        $this->assertEquals(Message::MAX_PAYLOAD_BYTES, $fits->payloadSize());
        $e = $this->expectInvalid(fn() => new Message('a.b.v1', ['s' => str_repeat('a', 65528)]));
        $this->assertTrue(str_contains($e->getMessage(), '65536 bytes') && str_contains($e->getMessage(), '65535'), 'a recusa diz o tamanho');
        $wide = new Message('a.b.v1', ['s' => str_repeat('ç', 32763)]);
        $this->assertEquals(65534, $wide->payloadSize(), 'o tamanho conta bytes, não caracteres');
        $this->expectInvalid(fn() => new Message('a.b.v1', ['s' => str_repeat('ç', 32764)]));

        $message = new Message('a.b.v1', ['a' => 1.0, 'b' => 'x/y', 'c' => 'ç', 'd' => [], 'e' => [1, 2], 'f' => null, 'g' => true, 'h' => -0.5]);
        $this->assertEquals('{"a":1.0,"b":"x/y","c":"ç","d":[],"e":[1,2],"f":null,"g":true,"h":-0.5}', $message->payloadJson(), 'JSON sem barras nem unicode escapados, com a fração zero');
        $this->assertEquals(strlen($message->payloadJson()), $message->payloadSize());
        $this->assertEquals('[]', (new Message('a.b.v1'))->payloadJson(), 'carga vazia');
    }

    private function testMessageFields(): void
    {
        $at = new DateTimeImmutable('2026-11-15 07:30:00.123456', new DateTimeZone('America/Sao_Paulo'));
        $message = new Message('crm.lead_created.v1', ['lead_id' => 7], 'lead:7', 'acme', ['trace-id' => 'abc', 'X.Y_z-1' => 'ç'], self::mid(1), $at);
        $this->assertEquals(self::mid(1), $message->messageId);
        $this->assertEquals('msg:' . self::mid(1), $message->idempotencyKey());
        $this->assertEquals('2026-11-15 10:30:00.123456', $message->occurredAt->format('Y-m-d H:i:s.u'), 'o instante em UTC');
        $this->assertEquals('UTC', $message->occurredAt->getTimezone()->getName());
        $this->assertEquals($at->getTimestamp(), $message->occurredAt->getTimestamp(), 'o mesmo instante');
        $this->assertEquals('lead:7', $message->orderingKey);
        $this->assertEquals('acme', $message->tenant);
        $this->assertEquals(['trace-id' => 'abc', 'X.Y_z-1' => 'ç'], $message->headers);
        $this->assertEquals('message "crm.lead_created.v1" (id ' . self::mid(1) . ', ordering key "lead:7")', $message->describe());
        $this->assertEquals('message "a.b.v1" (id ' . self::mid(2) . ')', (new Message('a.b.v1', messageId: self::mid(2)))->describe());
        $this->assertThrows(Error::class, static function () use ($message): void {
            $message->name = 'x.y.v1'; // @phpstan-ignore-line readonly
        });

        $generated = new Message('a.b.v1');
        $this->assertTrue(UlidGenerator::isValid($generated->messageId), 'o id gerado é um ULID');
        $this->assertTrue(abs($generated->occurredAt->getTimestamp() - time()) <= 5, 'occurredAt padrão = agora');
        $this->assertEquals('UTC', $generated->occurredAt->getTimezone()->getName());
        $this->assertEquals(null, $generated->orderingKey);
        $this->assertEquals(null, $generated->tenant);
        $this->assertEquals([], $generated->headers);
        $this->assertFalse($generated->messageId === (new Message('a.b.v1'))->messageId, 'ids diferentes');

        foreach (['abc', strtolower(self::mid(3)), 'U' . substr(self::mid(3), 1), '8' . substr(self::mid(3), 1), self::mid(3) . 'A', self::mid(3) . "\n"] as $id) {
            $this->expectInvalid(fn() => new Message('a.b.v1', messageId: $id), "id recusado: {$id}");
        }
        $texts = ['', ' x', 'x ', "a\nb", "a\x7Fb", "\xB1", self::SECRET . "\t"];
        foreach ($texts as $text) {
            $e = $this->expectInvalid(fn() => new Message('a.b.v1', orderingKey: $text), 'chave recusada');
            $this->assertTrue(str_contains($e->getMessage(), 'ordering key'), 'a recusa diz o campo');
            $this->expectInvalid(fn() => new Message('a.b.v1', tenant: $text), 'tenant recusado');
        }
        $this->assertEquals(190, mb_strlen((string) (new Message('a.b.v1', orderingKey: str_repeat('ç', 190)))->orderingKey), '190 caracteres de chave');
        $this->expectInvalid(fn() => new Message('a.b.v1', orderingKey: str_repeat('k', 191)));
        $this->assertEquals(120, mb_strlen((string) (new Message('a.b.v1', tenant: str_repeat('t', 120)))->tenant), '120 caracteres de tenant');
        $this->expectInvalid(fn() => new Message('a.b.v1', tenant: str_repeat('t', 121)));

        $headers = [];
        for ($i = 0; $i < 32; $i++) {
            $headers['h' . $i] = str_repeat('v', 1024);
        }
        $this->assertCount(32, (new Message('a.b.v1', headers: $headers))->headers, '32 cabeçalhos de 1 024 bytes');
        $this->expectInvalid(fn() => new Message('a.b.v1', headers: $headers + ['h32' => 'x']), '33 cabeçalhos');
        $badHeaders = [
            [0 => 'x'], ['1abc' => 'x'], ['a b' => 'x'], ['' => 'x'], [str_repeat('n', 65) => 'x'], ['ok' => 5],
            ['ok' => str_repeat('v', 1025)], ['ok' => "a\nb"], ['ok' => "\xB1"], ['ok' => self::SECRET . "\x01"], ['ok' => null],
        ];
        foreach ($badHeaders as $i => $bad) {
            $e = $this->expectInvalid(fn() => new Message('a.b.v1', headers: $bad), "cabeçalho recusado {$i}");
            $this->assertTrue(str_contains($e->getMessage(), 'eader'), 'a recusa fala do cabeçalho');
        }
        $this->assertEquals([str_repeat('n', 64) => 'x'], (new Message('a.b.v1', headers: [str_repeat('n', 64) => 'x']))->headers, 'nome de 64 caracteres');
    }

    private function testWireForm(): void
    {
        $message = new Message('crm.lead_created.v1', ['lead_id' => 7, 'secret' => self::SECRET], 'lead:7', 'acme', ['trace-id' => 'abc'], self::mid(4), new DateTimeImmutable('2026-11-15 10:30:00.000001', new DateTimeZone('UTC')));
        $this->assertEquals([
            'v' => 1,
            'id' => self::mid(4),
            'name' => 'crm.lead_created.v1',
            'occurred_at' => '2026-11-15T10:30:00.000001Z',
            'ordering_key' => 'lead:7',
            'tenant' => 'acme',
            'headers' => ['trace-id' => 'abc'],
            'payload' => ['lead_id' => 7, 'secret' => self::SECRET],
        ], $message->toArray(), 'a forma de fio v1');
        $this->assertEquals(Message::WIRE_KEYS, array_keys($message->toArray()));
        $this->assertEquals('{"v":1,"id":"' . self::mid(4) . '","name":"crm.lead_created.v1","occurred_at":"2026-11-15T10:30:00.000001Z","ordering_key":"lead:7","tenant":"acme","headers":{"trace-id":"abc"},"payload":{"lead_id":7,"secret":"' . self::SECRET . '"}}', $message->toJson());
        $this->assertEquals($message->toArray(), Message::fromJson($message->toJson())->toArray(), 'fromJson(toJson()) sem perda');
        $this->assertEquals($message->toArray(), Message::fromArray($message->toArray())->toArray(), 'fromArray(toArray()) sem perda');
        $this->assertEquals('{"v":1,"id":"' . self::mid(5) . '","name":"a.b.v1","occurred_at":"2026-11-15T10:30:00.000000Z","ordering_key":null,"tenant":null,"headers":{},"payload":[]}', (new Message('a.b.v1', messageId: self::mid(5), occurredAt: new DateTimeImmutable('2026-11-15 10:30:00', new DateTimeZone('UTC'))))->toJson(), 'cabeçalhos vazios como objeto');

        $minimal = Message::fromArray(['v' => 1, 'id' => self::mid(6), 'name' => 'a.b.v1', 'occurred_at' => '2026-11-15T10:30:00.000000Z', 'payload' => ['x' => 1]]);
        $this->assertEquals([null, null, []], [$minimal->orderingKey, $minimal->tenant, $minimal->headers], 'as chaves opcionais ausentes');

        $base = $message->toArray();
        $invalid = [
            'chave desconhecida' => $base + ['extra' => self::SECRET],
            'sem v' => array_diff_key($base, ['v' => 0]),
            'v 2' => ['v' => 2] + $base,
            'v texto' => ['v' => '1'] + $base,
            'id não texto' => ['id' => 7] + $base,
            'sem nome' => array_diff_key($base, ['name' => 0]),
            'instante sem T' => ['occurred_at' => '2026-11-15 10:30:00.000001'] + $base,
            'instante com fuso' => ['occurred_at' => '2026-11-15T10:30:00.000001+01:00'] + $base,
            'instante sem micro' => ['occurred_at' => '2026-11-15T10:30:00Z'] + $base,
            'instante impossível' => ['occurred_at' => '2026-02-30T10:30:00.000000Z'] + $base,
            'sem carga' => array_diff_key($base, ['payload' => 0]),
            'carga texto' => ['payload' => self::SECRET] + $base,
            'chave de ordem inteira' => ['ordering_key' => 7] + $base,
            'tenant array' => ['tenant' => [self::SECRET]] + $base,
            'cabeçalhos texto' => ['headers' => self::SECRET] + $base,
            'cabeçalho inválido' => ['headers' => ['ok' => [self::SECRET]]] + $base,
            'nome inválido' => ['name' => 'Bad'] + $base,
            'id inválido' => ['id' => 'nope'] + $base,
        ];
        foreach ($invalid as $label => $document) {
            $this->expectInvalid(fn() => Message::fromArray($document), "documento recusado: {$label}");
            $this->expectInvalid(fn() => Message::fromJson((string) json_encode($document)), "JSON recusado: {$label}");
        }
        foreach (['not json ' . self::SECRET, '"' . self::SECRET . '"', '[1,2]', '7', '{"v":1', ''] as $json) {
            $this->expectInvalid(fn() => Message::fromJson($json), 'JSON recusado');
        }
        $deep = [];
        for ($i = 0; $i < 63; $i++) {
            $deep = [$deep];
        }
        $wire = ['payload' => $deep] + $base;
        $this->assertEquals($deep, Message::fromJson((string) json_encode($wire))->payload, 'uma carga de 64 níveis é lida da forma de fio');
        $e = $this->expectInvalid(fn() => Message::fromJson((string) json_encode(['payload' => [$deep]] + $base)));
        $this->assertTrue(str_contains($e->getMessage(), 'is not valid JSON, or its payload is nested deeper than 64 levels'), 'a forma de fio funda demais: ' . $e->getMessage());
        $e = $this->expectInvalid(fn() => Message::fromArray(['payload' => [$deep]] + $base));
        $this->assertTrue(str_contains($e->getMessage(), 'nested deeper than 64 levels'));
        $e = $this->expectInvalid(fn() => Message::fromArray(['color' => 1, 7 => 2] + $base));
        $this->assertTrue(str_contains($e->getMessage(), 'has 2 unknown key(s)'), 'as chaves desconhecidas contadas: ' . $e->getMessage());
    }

    /** D2: the wire form is lossless over random payloads (seeded). */
    private function testWireFormRoundTripProperty(): void
    {
        foreach (self::SEEDS as $seed) {
            $random = new Randomizer(new Mt19937($seed));
            for ($i = 0; $i < 150; $i++) {
                $payload = self::randomPayload($random, 0);
                $message = new Message(
                    'prop.fact.v' . $random->getInt(1, 9),
                    is_array($payload) ? $payload : ['v' => $payload],
                    $random->getInt(0, 2) === 0 ? null : 'k' . $random->getInt(0, 9),
                    $random->getInt(0, 1) === 0 ? null : 't' . $random->getInt(0, 3),
                    $random->getInt(0, 1) === 0 ? [] : ['a' => (string) $random->getInt(0, 99), 'b-c' => 'ç'],
                    null,
                    (new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC')))->modify('+' . $random->getInt(0, 86400000) . ' seconds')->modify('+' . $random->getInt(0, 999999) . ' microseconds')
                );
                $back = Message::fromJson($message->toJson());
                $this->assertTrue($back->payload === $message->payload, 'a carga volta idêntica (===)');
                $this->assertEquals($message->toArray(), $back->toArray(), 'a mensagem volta idêntica');
                $this->assertEquals($message->payloadJson(), $back->payloadJson(), 'o JSON da carga é estável');
                $this->assertEquals($message->occurredAt->format('Y-m-d H:i:s.u'), $back->occurredAt->format('Y-m-d H:i:s.u'), 'o instante ao microssegundo');
            }
        }
    }

    private function testSupport(): void
    {
        $this->assertEquals('2026-11-15 10:00:00.000001', MessagingSupport::fromText('2026-11-15 10:00:00.000001')?->format('Y-m-d H:i:s.u'));
        foreach (['2026-11-15 10:00:00', '2026-11-15 10:00:00.1', 'x', '', '2026-13-01 00:00:00.000000', ' 2026-11-15 10:00:00.000001'] as $text) {
            $this->assertEquals(null, MessagingSupport::fromText($text), "instante estrito: {$text}");
        }
        $this->assertEquals('UTC', MessagingSupport::fromText('2026-11-15 10:00:00.000001')?->getTimezone()->getName());
        $this->assertEquals('2026-11-15 13:00:00.000000', MessagingSupport::toText(new DateTimeImmutable('2026-11-15 10:00:00', new DateTimeZone('-03:00'))));
        $this->assertEquals(2000, mb_strlen(MessagingSupport::truncate(str_repeat('ç', 2001))));
        $this->assertEquals(str_repeat('ç', 2000), MessagingSupport::truncate(str_repeat('ç', 2000)));
        $this->assertEquals('a' . str_repeat('b', 1999), MessagingSupport::truncate('a' . str_repeat('b', 2000)), 'corta o fim, nunca o começo');
        $this->assertEquals([true, false, false], [MessagingSupport::isUlid(self::mid(1)), MessagingSupport::isUlid(self::mid(1) . "\n"), MessagingSupport::isUlid('x')]);
        $this->assertEquals('RuntimeException', MessagingSupport::shortClass(new RuntimeException('x')));
        $this->assertEquals('MessagingPersistenceException', MessagingSupport::shortClass(new MessagingPersistenceException('x')));
        $this->assertEquals('RuntimeException@anonymous', MessagingSupport::shortClass(new class ('x') extends RuntimeException {
        }));
        $this->assertEquals('UTC', MessagingSupport::now()->getTimezone()->getName());
    }

    private function testValueObjects(): void
    {
        $at = new DateTimeImmutable('2026-11-15 10:00:00', new DateTimeZone('-03:00'));
        $message = new Message('a.b.v1', ['secret' => self::SECRET], 'k', messageId: self::mid(10));
        $entry = new MessageOutboxEntry(self::mid(11), $message, $at);
        $this->assertEquals([MessageOutboxStatus::PENDING, 0, null, null, null], [$entry->status, $entry->attempts, $entry->lastError, $entry->deliveredAt, $entry->lastAttemptAt]);
        $this->assertEquals('2026-11-15 13:00:00', $entry->enqueuedAt->format('Y-m-d H:i:s'), 'enqueuedAt em UTC');
        $this->assertEquals(self::mid(10), $entry->messageId());
        $this->assertEquals('key:k', $entry->queueKey());
        $this->assertEquals('entry:' . self::mid(12), (new MessageOutboxEntry(self::mid(12), new Message('a.b.v1'), $at))->queueKey(), 'sem chave: uma fila por entrada');
        $this->assertFalse($entry->isDelivered());
        $failed = $entry->withFailed(str_repeat('r', 2100), $at);
        $this->assertEquals([MessageOutboxStatus::FAILED, 1, 2000, null], [$failed->status, $failed->attempts, mb_strlen((string) $failed->lastError), $failed->deliveredAt]);
        $this->assertEquals('2026-11-15 13:00:00', $failed->lastAttemptAt?->format('Y-m-d H:i:s'));
        $delivered = $failed->withDelivered($at);
        $this->assertEquals([MessageOutboxStatus::DELIVERED, 2, true], [$delivered->status, $delivered->attempts, $delivered->isDelivered()]);
        $this->assertEquals($failed->lastError, $delivered->lastError, 'a entrega guarda o último motivo');
        $this->assertEquals([
            'entry_id' => self::mid(11), 'message' => $message->toArray(), 'status' => 'delivered', 'attempts' => 2,
            'last_error' => str_repeat('r', 2000), 'enqueued_at' => '2026-11-15 13:00:00.000000',
            'delivered_at' => '2026-11-15 13:00:00.000000', 'last_attempt_at' => '2026-11-15 13:00:00.000000',
        ], $delivered->toArray());
        $this->assertEquals([null, null], [$entry->toArray()['delivered_at'], $entry->toArray()['last_attempt_at']]);
        $this->assertThrows(InvalidArgumentException::class, fn() => new MessageOutboxEntry('not-a-ulid', $message, $at));
        $this->assertThrows(InvalidArgumentException::class, fn() => new MessageOutboxEntry(self::mid(13), $message, $at, attempts: -1));
        $this->assertEquals(0, (new MessageOutboxEntry(self::mid(13), $message, $at, attempts: 0))->attempts);

        $row = ProcessedMessage::of('agents', new Message('a.b.v1', tenant: 'acme', messageId: self::mid(14)), $at);
        $this->assertEquals(['consumer' => 'agents', 'idempotency_key' => 'msg:' . self::mid(14), 'message_id' => self::mid(14), 'message_name' => 'a.b.v1', 'tenant' => 'acme', 'processed_at' => '2026-11-15 13:00:00.000000'], $row->toArray());
        foreach (['', 'Agents', 'a b', '-a', str_repeat('a', 121), 'é'] as $consumer) {
            $this->assertThrows(InvalidArgumentException::class, fn() => ProcessedMessage::assertConsumer($consumer), "consumidor recusado: {$consumer}");
        }
        foreach (['a', '0', 'agents', 'crm.sync:v2-b_c', str_repeat('a', 120)] as $consumer) {
            $this->assertEquals($consumer, ProcessedMessage::assertConsumer($consumer));
        }
        $this->assertThrows(InvalidArgumentException::class, fn() => new ProcessedMessage('a', '', 'x', 'a.b.v1', null, $at));
        $this->assertThrows(InvalidArgumentException::class, fn() => new ProcessedMessage('a', str_repeat('k', 191), 'x', 'a.b.v1', null, $at));
        $this->assertEquals(190, strlen((new ProcessedMessage('a', str_repeat('k', 190), 'x', 'a.b.v1', null, $at))->idempotencyKey));

        $this->assertEquals([true, true, true, true, false, false, false], array_map(static fn(ConsumeOutcome $o): bool => $o->isAcknowledged(), [
            ConsumeOutcome::HANDLED, ConsumeOutcome::DUPLICATE, ConsumeOutcome::UNKNOWN_NAME, ConsumeOutcome::OTHER_TENANT,
            ConsumeOutcome::RETRIED, ConsumeOutcome::HELD_BACK, ConsumeOutcome::DEAD_LETTERED,
        ]));
        $entries = [
            new ConsumeEntry('0', 'm1', 'a.b.v1', 'k', 1, ConsumeOutcome::HANDLED),
            new ConsumeEntry('1', null, null, null, 2, ConsumeOutcome::DEAD_LETTERED, 'bad body'),
            new ConsumeEntry('2', 'm3', 'a.c.v1', null, 1, ConsumeOutcome::UNKNOWN_NAME, 'no handler'),
            new ConsumeEntry('3', 'm4', 'a.b.v1', null, 1, ConsumeOutcome::HANDLED),
        ];
        $report = new ConsumeReport($entries);
        $this->assertEquals([4, 2, 1, 0], [$report->receivedCount(), $report->countOf(ConsumeOutcome::HANDLED), $report->countOf(ConsumeOutcome::DEAD_LETTERED), $report->countOf(ConsumeOutcome::RETRIED)]);
        $this->assertEquals(['m1', 'm4'], $report->handledIds());
        $this->assertEquals(['1' => 'bad body'], $report->deadLetters());
        $this->assertEquals(['no handler'], $report->warnings());
        $this->assertEquals($entries, $report->entries());
        $this->assertEquals([$entries[1]], $report->of(ConsumeOutcome::DEAD_LETTERED));
        $this->assertEquals(['broker_id' => '1', 'message_id' => null, 'name' => null, 'ordering_key' => null, 'delivery_count' => 2, 'outcome' => 'dead_lettered', 'reason' => 'bad body'], $report->toArray()[1]);
        $this->assertEquals(0, (new ConsumeReport())->receivedCount());
        $odd = new ConsumeReport([
            new ConsumeEntry('5', null, null, null, 1, ConsumeOutcome::HANDLED),
            new ConsumeEntry('6', null, null, null, 1, ConsumeOutcome::DEAD_LETTERED),
            new ConsumeEntry('7', null, null, null, 1, ConsumeOutcome::DEAD_LETTERED, 'second'),
            new ConsumeEntry('8', null, null, null, 1, ConsumeOutcome::UNKNOWN_NAME),
        ]);
        $this->assertTrue($odd->handledIds() === [''] && $odd->deadLetters() === ['6' => '', '7' => 'second'] && $odd->warnings() === [''], 'as listas do relatório são sempre de texto, todas as mortas');

        $relay = new RelayReport([$entry], [self::mid(9) => 'r'], [self::mid(9)], 3);
        $this->assertEquals([1, 1, 3], [$relay->deliveredCount(), $relay->failedCount(), $relay->batchCount()]);
        $this->assertEquals([$entry], $relay->getDelivered());
        $this->assertEquals([self::mid(10)], $relay->deliveredIds());
        $this->assertEquals([self::mid(9) => 'r'], $relay->getFailures());
        $this->assertEquals([self::mid(9)], $relay->getExhausted());
        $this->assertEquals(['delivered' => [self::mid(10)], 'failed' => [self::mid(9) => 'r'], 'exhausted' => [self::mid(9)], 'batches' => 3], $relay->toArray());
        $this->assertEquals([0, 0, 0, []], [(new RelayReport())->deliveredCount(), (new RelayReport())->failedCount(), (new RelayReport())->batchCount(), (new RelayReport())->getExhausted()]);

        $persistence = new MessagingPersistenceException('m', '23505', '7');
        $this->assertEquals(['23505', '7', true], [$persistence->getSqlState(), $persistence->getDriverCode(), $persistence->isIntegrityViolation()]);
        $this->assertFalse((new MessagingPersistenceException('m', '42P01'))->isIntegrityViolation());
        $this->assertFalse((new MessagingPersistenceException('m'))->isIntegrityViolation());
        $this->assertEquals([null, null], [(new MessagingPersistenceException('m'))->getSqlState(), (new MessagingPersistenceException('m'))->getDriverCode()]);
        $this->assertTrue(new InvalidMessagePayloadException('x') instanceof MessagingException, 'a recusa da carga é uma MessagingException');
        $this->assertTrue(new MessagingPersistenceException('x') instanceof RuntimeException);
    }

    private function testTransportSendResult(): void
    {
        $a = new Message('a.b.v1', messageId: self::mid(20));
        $b = new Message('a.b.v1', messageId: self::mid(21));
        $all = TransportSendResult::allConfirmed([$a, $b]);
        $this->assertEquals([self::mid(20), self::mid(21)], $all->confirmedIds());
        $this->assertEquals([true, null, null, []], [$all->isConfirmed(self::mid(20)), $all->brokerIdOf(self::mid(20)), $all->reasonOf(self::mid(20)), $all->failures()]);
        $failed = TransportSendResult::allFailed([$a, $b], 'connection reset');
        $this->assertEquals([[], [self::mid(20) => 'connection reset', self::mid(21) => 'connection reset']], [$failed->confirmedIds(), $failed->failures()]);
        $this->assertFalse($failed->isConfirmed(self::mid(20)));

        $result = new TransportSendResult();
        $one = $result->withConfirmed(self::mid(20), '1-0');
        $two = $one->withFailed(self::mid(21), 'NOROUTE');
        $this->assertEquals([[], []], [$result->confirmedIds(), $result->failures()], 'imutável');
        $this->assertEquals([[self::mid(20)], [self::mid(21) => 'NOROUTE'], '1-0'], [$two->confirmedIds(), $two->failures(), $two->brokerIdOf(self::mid(20))]);
        $flipped = $two->withConfirmed(self::mid(21))->withFailed(self::mid(20), 'nack');
        $this->assertEquals([[self::mid(21)], [self::mid(20) => 'nack'], null, 'nack'], [$flipped->confirmedIds(), $flipped->failures(), $flipped->brokerIdOf(self::mid(20)), $flipped->reasonOf(self::mid(20))]);
        $this->assertEquals(null, $two->reasonOf(self::mid(99)), 'uma mensagem de que o resultado não fala');
        $this->assertFalse($two->isConfirmed(self::mid(99)));
        $this->assertThrows(InvalidArgumentException::class, fn() => new TransportSendResult([self::mid(20) => null], [self::mid(20) => 'x']));
        $this->assertThrows(InvalidArgumentException::class, fn() => new TransportSendResult([], [self::mid(20) => ' ']));
        $this->assertThrows(InvalidArgumentException::class, fn() => $result->withFailed(self::mid(20), ''));
        $this->assertEquals(2000, mb_strlen((string) (new TransportSendResult([], [self::mid(20) => str_repeat('x', 2500)]))->reasonOf(self::mid(20))));
        $this->assertEquals(0, count(TransportSendResult::allConfirmed([])->confirmedIds()));
        $this->assertTrue((new TransportSendResult())->withConfirmed('123')->confirmedIds() === ['123'], 'um id numérico continua texto');
    }

    private function testReceivedMessage(): void
    {
        $message = new Message('a.b.v1', ['secret' => self::SECRET], 'k', messageId: self::mid(30));
        $ok = ReceivedMessage::of($message, 2, '7');
        $this->assertEquals([true, 2, '7', null], [$ok->isValid(), $ok->deliveryCount, $ok->brokerId, $ok->invalidReason()]);
        $this->assertTrue($ok->message() === $message);
        $this->assertEquals($message->describe() . ', delivery 7', $ok->describe());
        $decoded = ReceivedMessage::decode($message->toJson(), 1, '8');
        $this->assertEquals($message->toArray(), $decoded->message()->toArray());
        $invalid = ReceivedMessage::decode('{"v":1,"secret":"' . self::SECRET . '"}', 3, '9');
        $this->assertFalse($invalid->isValid());
        $this->assertEquals('The delivery is not a message of format 1: A message document has 1 unknown key(s); the keys of format version 1 are: v, id, name, occurred_at, ordering_key, tenant, headers, payload.', $invalid->invalidReason());
        $this->seen((string) $invalid->invalidReason());
        $this->assertEquals('invalid delivery 9', $invalid->describe());
        $e = $this->assertThrows(LogicException::class, fn() => $invalid->message());
        $this->seen($e->getMessage());
        $this->assertTrue(str_contains($e->getMessage(), 'Delivery 9 holds no message'));
        $this->assertEquals(2000, mb_strlen((string) ReceivedMessage::invalid(1, 'x', str_repeat('r', 2001))->invalidReason()));
        $this->assertThrows(InvalidArgumentException::class, fn() => ReceivedMessage::of($message, 0, '1'));
        $this->assertThrows(InvalidArgumentException::class, fn() => ReceivedMessage::of($message, 1, ''));
        $this->assertThrows(InvalidArgumentException::class, fn() => ReceivedMessage::invalid(1, '1', ' '));
        $this->assertThrows(InvalidArgumentException::class, fn() => ReceivedMessage::invalid(0, '1', 'r'));
        $this->assertEquals(1, ReceivedMessage::of($message, 1, '1')->deliveryCount);
    }

    private function testHandlerRegistry(): void
    {
        $registry = new MessageHandlerRegistry();
        $this->assertEquals([false, [], []], [$registry->has('a.b.v1'), $registry->handlersFor('a.b.v1'), $registry->names()]);
        $calls = [];
        $registry->register('a.b.v1', static function (Message $m) use (&$calls): void {
            $calls[] = 'first';
        })->register('a.b.v1', static function (Message $m) use (&$calls): void {
            $calls[] = 'second';
        })->register('c.d.v2', static function (): void {
        });
        $this->assertEquals([true, ['a.b.v1', 'c.d.v2']], [$registry->has('a.b.v1'), $registry->names()]);
        foreach ($registry->handlersFor('a.b.v1') as $handler) {
            $handler(new Message('a.b.v1'));
        }
        $this->assertEquals(['first', 'second'], $calls, 'na ordem do registro');
        foreach (['', 'a.b', 'A.b.v1', 'documents.validated'] as $name) {
            $this->expectInvalid(fn() => $registry->register($name, static function (): void {
            }), "nome de handler recusado: {$name}");
        }
    }

    private function testInMemoryTransport(): void
    {
        $transport = new InMemoryMessageTransport();
        $a = new Message('a.b.v1', ['secret' => self::SECRET], 'k1', messageId: self::mid(40));
        $b = new Message('a.b.v1', ['secret' => self::SECRET], 'k2', messageId: self::mid(41));
        $c = new Message('a.b.v1', ['secret' => self::SECRET], null, messageId: self::mid(42));
        $d = new Message('a.b.v1', ['secret' => self::SECRET], null, messageId: self::mid(43));
        $result = $transport->send([$a, $b]);
        $this->assertEquals([[self::mid(40), self::mid(41)], '0', '1'], [$result->confirmedIds(), $result->brokerIdOf(self::mid(40)), $result->brokerIdOf(self::mid(41))]);
        $faults = [self::mid(40) => InMemoryTransportFault::FAIL, self::mid(41) => InMemoryTransportFault::LOSE_CONFIRM, self::mid(42) => InMemoryTransportFault::DUPLICATE, self::mid(43) => InMemoryTransportFault::NONE];
        $transport->withFaults(static fn(Message $m): InMemoryTransportFault => $faults[$m->messageId]);
        $result = $transport->send([$a, $b, $c, $d]);
        $this->assertEquals([self::mid(42), self::mid(43)], $result->confirmedIds(), 'confirmadas: a duplicada e a normal');
        $this->assertEquals(['3', '5'], [$result->brokerIdOf(self::mid(42)), $result->brokerIdOf(self::mid(43))], 'a duplicada ocupa 3 e 4');
        $this->assertEquals('The in-memory broker refused ' . $a->describe() . ' (injected fault).', $result->reasonOf(self::mid(40)));
        $this->seen((string) $result->reasonOf(self::mid(40)));
        $this->assertEquals([false, null], [$result->isConfirmed(self::mid(41)), $result->reasonOf(self::mid(41))], 'a confirmação perdida: o resultado não fala dela');
        $this->assertEquals(6, $transport->storedCount(), '2 + perdida (gravada) + duplicada (2) + normal');
        $this->assertEquals([self::mid(40), self::mid(41), self::mid(41), self::mid(42), self::mid(42), self::mid(43)], array_map(static fn(Message $m): string => $m->messageId, $transport->stored()));
        $this->assertEquals([4 => $c->toJson(), 5 => $d->toJson()], $transport->bodiesFrom(4), 'bodiesFrom guarda a posição');
        $this->assertEquals([], $transport->bodiesFrom(6));
        $transport->withFaults(null)->storeRaw('not a message ' . self::SECRET);
        $this->assertEquals([7, 6], [$transport->storedCount(), count($transport->stored())], 'o corpo cru fica fora de stored()');
        $transport->failNextBatch('connection reset by peer');
        $e = $this->assertThrows(MessagingException::class, fn() => $transport->send([$a]));
        $this->assertEquals('connection reset by peer', $e->getMessage());
        $this->assertEquals([self::mid(40)], $transport->send([$a])->confirmedIds(), 'só o próximo lote falha');
        $this->assertEquals(4, $transport->batchCount(), 'os lotes, o que falhou incluído');
        $this->assertEquals([], $transport->send([])->confirmedIds());
        $this->assertEquals([8, 7], [$transport->storedCount(), count($transport->stored())], 'um corpo cru no meio não esconde as mensagens depois dele');
        $pairs = (new InMemoryMessageTransport())->reorderAcrossKeys(new Randomizer(new Mt19937(self::SEEDS[2])));
        $swapped = 0;
        for ($i = 0; $i < 40; $i++) {
            $before = $pairs->storedCount();
            $pairs->send([new Message('a.b.v1', [], 'x', messageId: self::mid(200000 + 2 * $i)), new Message('a.b.v1', [], 'y', messageId: self::mid(200001 + 2 * $i))]);
            $swapped += array_slice($pairs->stored(), $before)[0]->orderingKey === 'y' ? 1 : 0;
        }
        $this->assertTrue($swapped > 5, "um lote de duas chaves também é reordenado ({$swapped} de 40)");
        $this->assertTrue($transport->withFaults(null) === $transport && $transport->reorderAcrossKeys(null) === $transport && $transport->storeRaw('x') === $transport, 'fluente');
    }

    private function testInMemorySource(): void
    {
        $transport = new InMemoryMessageTransport();
        $messages = [];
        for ($i = 0; $i < 5; $i++) {
            $messages[] = new Message('a.b.v1', ['n' => $i, 'secret' => self::SECRET], 'k' . ($i % 2), messageId: self::mid(50 + $i));
        }
        $transport->send(array_slice($messages, 0, 3));
        $transport->send(array_slice($messages, 3));
        $source = new InMemoryMessageSource($transport);
        $other = new InMemoryMessageSource($transport);
        $this->assertThrows(InvalidArgumentException::class, fn() => $source->receive(0, 0));
        $this->assertThrows(InvalidArgumentException::class, fn() => $source->receive(1, -1));
        $this->assertEquals([5, 0], [$source->pendingCount(), $source->inFlightCount()]);
        $first = $source->receive(2, 0);
        $this->assertEquals([['0', 1], ['1', 1]], array_map(static fn(ReceivedMessage $r): array => [$r->brokerId, $r->deliveryCount], $first));
        $this->assertEquals([3, 2], [$source->pendingCount(), $source->inFlightCount()]);
        $source->reject($first[0], true, 'retry');
        $source->ack($first[1]);
        $this->assertEquals(1, $source->acknowledgedCount());
        $e = $this->assertThrows(MessagingException::class, fn() => $source->ack($first[1]));
        $this->assertTrue(str_contains($e->getMessage(), 'Delivery 1 is not in flight'));
        $this->assertThrows(MessagingException::class, fn() => $source->reject($first[1], true, 'x'));
        $this->assertThrows(MessagingException::class, fn() => $source->ack(ReceivedMessage::of($messages[0], 1, 'x7')));
        $this->assertThrows(MessagingException::class, fn() => $source->ack(ReceivedMessage::of($messages[0], 1, '07')));
        $second = $source->receive(3, 100);
        $this->assertEquals([['0', 2], ['2', 1], ['3', 1]], array_map(static fn(ReceivedMessage $r): array => [$r->brokerId, $r->deliveryCount], $second), 'a devolvida vem antes das novas');
        $source->reject($second[2], true, 'retry');
        $source->reject($second[1], true, 'retry');
        $source->reject($second[0], false, 'dead: ' . str_repeat('x', 2100));
        $third = $source->receive(10, 0);
        $this->assertEquals([['2', 2], ['3', 2], ['4', 1]], array_map(static fn(ReceivedMessage $r): array => [$r->brokerId, $r->deliveryCount], $third), 'as devolvidas na ordem do log, qualquer que seja a ordem da devolução');
        $this->assertEquals(3, $source->recoverInFlight(), 'o consumidor morreu');
        $this->assertEquals([0, 3], [$source->inFlightCount(), $source->pendingCount()]);
        $fourth = $source->receive(10, 0);
        $this->assertEquals([3, 3, 2], array_map(static fn(ReceivedMessage $r): int => $r->deliveryCount, $fourth), 'a recuperação conta uma entrega a mais');
        foreach ($fourth as $delivery) {
            $source->ack($delivery);
        }
        $this->assertEquals([0, 0, 4], [$source->pendingCount(), $source->inFlightCount(), $source->acknowledgedCount()]);
        $dead = $source->deadLetters();
        $this->assertCount(1, $dead);
        $this->assertEquals(['0', self::mid(50), 2], [$dead[0]['brokerId'], $dead[0]['messageId'], $dead[0]['deliveryCount']]);
        $this->assertEquals(2000, mb_strlen($dead[0]['reason']), 'o motivo truncado');
        $this->assertEquals(['0', '1', '2', '3', '4'], array_map(static fn(ReceivedMessage $r): string => $r->brokerId, $other->receive(10, 0)), 'cada fonte vê tudo (um grupo de consumidores por fonte)');

        $again = new InMemoryMessageSource($transport);
        $three = $again->receive(3, 0);
        foreach ($three as $delivery) {
            $again->reject($delivery, true, 'retry');
        }
        $this->assertEquals(['0', '1'], array_map(static fn(ReceivedMessage $r): string => $r->brokerId, $again->receive(2, 0)), 'receive() devolve no máximo $max, também das devolvidas');
        $transport->storeRaw('{"secret":"' . self::SECRET . '"}');
        $raw = $source->receive(1, 0)[0];
        $this->assertFalse($raw->isValid(), 'um corpo inválido é uma entrega inválida');
        $source->reject($raw, false, (string) $raw->invalidReason());
        $this->assertEquals([null, '5'], [$source->deadLetters()[1]['messageId'], $source->deadLetters()[1]['brokerId']]);
        $this->assertEquals([], $source->receive(5, 0), 'nada mais');
    }

    /** D13: reorderAcrossKeys() keeps the order within each key and does reorder across keys. */
    private function testReorderAcrossKeysProperty(): void
    {
        $random = new Randomizer(new Mt19937(self::SEEDS[0]));
        $transport = (new InMemoryMessageTransport())->reorderAcrossKeys(new Randomizer(new Mt19937(self::SEEDS[1])));
        $moved = 0;
        $n = 0;
        for ($i = 0; $i < 200; $i++) {
            $batch = [];
            for ($j = 0, $size = $random->getInt(1, 8); $j < $size; $j++) {
                $key = $random->getInt(0, 4);
                $batch[] = new Message('a.b.v1', ['n' => $n], $key === 4 ? null : 'k' . $key, messageId: self::mid(100000 + $n++));
            }
            $before = $transport->storedCount();
            $transport->send($batch);
            $stored = array_slice($transport->stored(), $before);
            $this->assertEquals(self::idsOf($batch, true), self::idsOf($stored, true), 'os mesmos ids, em outra ordem');
            foreach (['k0', 'k1', 'k2', 'k3'] as $key) {
                $this->assertEquals(self::idsOfKey($batch, $key), self::idsOfKey($stored, $key), "a ordem da chave {$key} se mantém");
            }
            $moved += self::idsOf($batch, false) === self::idsOf($stored, false) ? 0 : 1;
        }
        $this->assertTrue($moved > 50, "o transporte reordenou entre chaves em {$moved} de 200 lotes");
    }

    /** Defaults and edges of the publisher and the consumer that the batteries do not reach. */
    private function testBoundaries(): void
    {
        $calls = 0;
        $outbox = new InMemoryMessageOutbox();
        $publisher = new MessagePublisher($outbox, new UlidGenerator(static fn(): int => 1763200000000), function () use (&$calls): DateTimeImmutable {
            $calls++;

            return $this->at('2026-11-15 10:00:00');
        });
        $this->assertEquals([0, 0], [$publisher->publish(), $calls], 'publicar nada não lê o relógio');
        $publisher->publish($this->msg(5000, null));
        $this->assertEquals([1, 1763200000000], [$calls, UlidGenerator::timestampOf((string) $outbox->find(self::mid(5000))?->entryId)], 'o gerador dado faz o id da entrada');

        $transport = new InMemoryMessageTransport();
        $waits = new \ArrayObject();
        $source = new class (new InMemoryMessageSource($transport), $waits) implements IMessageSource {
            public function __construct(private readonly InMemoryMessageSource $inner, private readonly \ArrayObject $waits)
            {
            }

            public function receive(int $max, int $waitMs): array
            {
                $this->waits->append([$max, $waitMs]);

                return $this->inner->receive($max, $waitMs);
            }

            public function ack(ReceivedMessage $message): void
            {
                $this->inner->ack($message);
            }

            public function reject(ReceivedMessage $message, bool $requeue, string $reason): void
            {
                $this->inner->reject($message, $requeue, $reason);
            }
        };
        $batch = [];
        for ($i = 0; $i < 101; $i++) {
            $batch[] = $this->msg(5100 + $i, null);
        }
        $transport->send($batch);
        $consumer = new MessageConsumer('defaults', $source, new MessageHandlerRegistry(), new InMemoryProcessedMessageStore());
        $this->assertEquals([100, 1], [$consumer->consume()->receivedCount(), $consumer->consume()->receivedCount()], 'consume() recebe 100 por padrão');
        $this->assertEquals([[100, 0], [100, 0]], $waits->getArrayCopy(), 'e não espera por padrão');
        $consumer->consume(7, 250);
        $this->assertEquals([7, 250], $waits->getArrayCopy()[2], 'o máximo e a espera chegam à fonte');
    }

    // =====================================================================
    // The batteries over an environment (memory, SQLite, the servers)
    // =====================================================================

    /**
     * @param Closure(): array<string, mixed> $fresh
     */
    private function runBatteries(string $label, Closure $fresh): void
    {
        $this->contractOutbox($label, $fresh());
        $this->contractOutboxPayloads($label, $fresh());
        $this->contractInbox($label, $fresh());
        $this->testPublisher($label, $fresh());
        $this->testRelayOrderAndBatches($label, $fresh());
        $this->testRelayFailures($label, $fresh());
        $this->testRelayCrashBetweenConfirmAndMark($label, $fresh());
        $this->testConsumerOutcomes($label, $fresh());
        $this->testConsumerFailuresAndRedeliveries($label, $fresh());
        $this->testConsumerTenant($label, $fresh());
        $this->testTwoConsumers($label, $fresh());
    }

    /** @param array<string, mixed> $env */
    private function contractOutbox(string $label, array $env): void
    {
        /** @var IMessageOutbox $outbox */
        $outbox = $env['outbox'];
        $this->assertEquals([0, []], [$outbox->count(), $outbox->fetchPending(10)], "{$label}: outbox vazia");
        $publisher = new MessagePublisher($outbox, null, $this->clock());
        $a1 = $this->msg(200, 'k1');
        $a2 = $this->msg(201, 'k1');
        $b1 = $this->msg(202, 'k2');
        $n1 = $this->msg(203, null);
        $n2 = $this->msg(204, null);
        $a3 = $this->msg(205, 'k1');
        $this->assertEquals(6, $publisher->publish($a1, $a2, $b1, $n1, $n2, $a3), "{$label}: seis gravadas");
        $this->assertEquals(0, $publisher->publish($a1, $n2), "{$label}: publicar de novo não grava (id da mensagem único)");
        $this->assertEquals(0, $publisher->publish(), "{$label}: nada a publicar");
        $this->assertEquals(6, $outbox->count());
        $this->assertEquals(self::ids($a1, $b1, $n1, $n2), self::entryIds($outbox->fetchPending(10)), "{$label}: as cabeças por chave; sem chave, toda entrada é cabeça");
        $this->assertEquals(self::ids($a1, $b1), self::entryIds($outbox->fetchPending(2)), "{$label}: o limite");
        $this->assertEquals(self::ids($a1), self::entryIds($outbox->fetchPending(1)));
        $e = $this->assertThrows(InvalidArgumentException::class, fn() => $outbox->fetchPending(0));
        $this->assertEquals('The limit must be at least 1, 0 given.', $e->getMessage());

        $head = $outbox->fetchPending(1)[0];
        $outbox->markFailed($head, 'The broker did not confirm ' . $a1->describe() . '.', $this->at('2026-11-15 11:00:00.000001'));
        $failed = $outbox->find($a1->messageId);
        $this->assertEquals([MessageOutboxStatus::FAILED, 1, 'The broker did not confirm ' . $a1->describe() . '.', '2026-11-15 11:00:00.000001', null], [
            $failed?->status, $failed?->attempts, $failed?->lastError, $failed?->lastAttemptAt?->format('Y-m-d H:i:s.u'), $failed?->deliveredAt,
        ], "{$label}: marcada como falha");
        $this->assertEquals(self::ids($a1, $b1, $n1, $n2), self::entryIds($outbox->fetchPending(10)), "{$label}: a falha continua cabeça");
        $this->assertEquals(self::ids($a2, $b1, $n1, $n2), self::entryIds($outbox->fetchPending(10, 1)), "{$label}: esgotada (1 tentativa) deixa de bloquear a chave");
        $this->assertEquals(self::ids($a1, $b1, $n1, $n2), self::entryIds($outbox->fetchPending(10, 2)), "{$label}: com 2 tentativas ainda não esgotou");
        $outbox->markFailed($failed, str_repeat('ç', 2100), $this->at('2026-11-15 11:00:01'));
        $this->assertEquals([2, 2000], [$outbox->find($a1->messageId)?->attempts, mb_strlen((string) $outbox->find($a1->messageId)?->lastError)], "{$label}: o motivo truncado a 2 000 caracteres");
        $outbox->markDelivered($failed, $this->at('2026-11-15 11:00:02.5'));
        $delivered = $outbox->find($a1->messageId);
        $this->assertEquals([MessageOutboxStatus::DELIVERED, 3, '2026-11-15 11:00:02.500000', '2026-11-15 11:00:02.500000', true], [
            $delivered?->status, $delivered?->attempts, $delivered?->deliveredAt?->format('Y-m-d H:i:s.u'), $delivered?->lastAttemptAt?->format('Y-m-d H:i:s.u'), $delivered?->isDelivered(),
        ], "{$label}: entregue");
        $this->assertEquals(mb_substr(str_repeat('ç', 2100), 0, 2000), $delivered?->lastError, "{$label}: a entrega guarda o último motivo");
        $this->assertEquals(self::ids($a2, $b1, $n1, $n2), self::entryIds($outbox->fetchPending(10)), "{$label}: a próxima da chave vira cabeça");
        foreach ([$a2, $b1, $n1] as $message) {
            $outbox->markDelivered($outbox->find($message->messageId), $this->at('2026-11-15 11:00:03'));
        }
        $this->assertEquals(self::ids($n2, $a3), self::entryIds($outbox->fetchPending(10)), "{$label}: as cabeças por ordem de entrada");
        $this->assertEquals(self::ids($a1, $a2, $a3), self::entryIds($outbox->findByOrderingKey('k1')), "{$label}: a fila de uma chave");
        $this->assertEquals([], $outbox->findByOrderingKey('k9'));
        $this->assertEquals(null, $outbox->find(self::mid(999)));
        $entry = $outbox->find($a3->messageId);
        $this->assertEquals($a3->toArray(), $entry?->message->toArray(), "{$label}: a mensagem volta inteira");
        $this->assertEquals('2026-11-15 10:00:00.000000', $entry?->enqueuedAt->format('Y-m-d H:i:s.u'), "{$label}: o instante da publicação (o relógio do publicador)");
        $this->assertTrue(UlidGenerator::isValid((string) $entry?->entryId), "{$label}: o id da entrada é um ULID");

        $ghost = new MessageOutboxEntry(self::mid(998), $this->msg(998, 'k1'), $this->at('2026-11-15 10:00:00'));
        foreach ([fn() => $outbox->markDelivered($ghost, $this->at('2026-11-15 10:00:00')), fn() => $outbox->markFailed($ghost, 'x', $this->at('2026-11-15 10:00:00'))] as $mark) {
            $e = $this->assertThrows(MessagingPersistenceException::class, $mark);
            $this->seen($e->getMessage());
            $this->assertTrue(str_contains($e->getMessage(), self::mid(998) . ' (message "a.b.v1"'), "{$label}: a entrada inexistente nomeada");
        }

        $tx = $env['tx'];
        $this->assertThrows(Module48Crash::class, static function () use ($tx, $publisher): void {
            $tx->transactional(static function () use ($publisher): void {
                $publisher->publish(new Message('a.b.v1', ['secret' => self::SECRET], 'k1', messageId: self::mid(997)));
                throw new Module48Crash('the write failed');
            });
        });
        $this->assertEquals([null, 6], [$outbox->find(self::mid(997)), $outbox->count()], "{$label}: a transação desfeita leva a mensagem junto (RN-02)");
        $tx->transactional(static fn(): int => $publisher->publish(new Message('a.b.v1', [], 'k1', messageId: self::mid(996))));
        $this->assertEquals(7, $outbox->count(), "{$label}: a transação confirmada grava");

        $late = new MessageOutboxEntry('01JD00000000000000000000Z2', $this->msg(990, 'late'), $this->at('2026-11-15 10:00:00'));
        $early = new MessageOutboxEntry('01JD00000000000000000000Z1', $this->msg(991, 'late'), $this->at('2026-11-15 10:00:00'));
        $this->assertEquals(2, $outbox->enqueue([$late, $early]), "{$label}: gravadas fora de ordem");
        $this->assertEquals(self::ids($this->msg(991, 'late'), $this->msg(990, 'late')), self::entryIds($outbox->findByOrderingKey('late')), "{$label}: a ordem é a do id da entrada, não a da gravação");
        $heads = array_values(array_filter($outbox->fetchPending(100), static fn(MessageOutboxEntry $e): bool => $e->message->orderingKey === 'late'));
        $this->assertEquals(self::ids($this->msg(991, 'late')), self::entryIds($heads), "{$label}: a cabeça da chave é a do menor id");
        $many = [];
        for ($i = 0; $i < 101; $i++) {
            $many[] = $this->msg(3000 + $i, null);
        }
        $publisher->publish(...$many);
        $this->assertCount(100, $outbox->fetchPending(), "{$label}: o limite padrão é 100");
    }

    /** @param array<string, mixed> $env */
    private function contractOutboxPayloads(string $label, array $env): void
    {
        $outbox = $env['outbox'];
        $publisher = new MessagePublisher($outbox, new UlidGenerator(static fn(): int => 1763200000000), $this->clock());
        $headers = [];
        for ($i = 0; $i < 32; $i++) {
            $headers['h' . $i] = str_repeat('v', 1000) . 'ç' . $i;
        }
        $rich = [
            'unicode' => 'Configuração Δ 中文 😀 \\ / " \'',
            'floats' => [1.0, 0.1, -2.5, 1.0E-10, 123456789.125, -0.0],
            'ints' => [PHP_INT_MAX, PHP_INT_MIN, 0, -1],
            'nested' => ['a' => ['b' => ['c' => [true, false, null]]]],
            'empty' => [],
            'list' => ['x', 'y'],
            'sparse' => [3 => 'a', 7 => 'b'],
            'numericKeys' => ['01' => 'zero-one', '1.5' => 'x', '' => 'empty key'],
            'secret' => self::SECRET,
        ];
        $messages = [
            new Message('rich.payload.v1', $rich, 'kç😀', 'tenant ç', $headers, self::mid(300), $this->at('2026-11-15 10:00:00.999999')),
            new Message('big.payload.v1', ['s' => str_repeat('a', 65527)], null, null, [], self::mid(301), $this->at('2026-11-15 10:00:01')),
            new Message('big.payload.v1', ['s' => str_repeat('ç', 32763)], 'k', null, [], self::mid(302), $this->at('2026-11-15 10:00:02')),
            new Message('empty.payload.v1', [], str_repeat('k', 190), str_repeat('t', 120), [], self::mid(303), $this->at('2026-11-15 10:00:03')),
        ];
        $this->assertEquals(4, $publisher->publish(...$messages));
        foreach ($messages as $message) {
            $back = $outbox->find($message->messageId);
            $this->assertEquals($message->toArray(), $back?->message->toArray(), "{$label}: {$message->name} volta sem perda");
            $this->assertTrue($message->payload === $back?->message->payload, "{$label}: a carga === a gravada");
            $this->assertEquals($message->payloadJson(), $back?->message->payloadJson(), "{$label}: o JSON da carga");
        }
        $this->assertEquals(65535, $outbox->find(self::mid(301))?->message->payloadSize(), "{$label}: 65 535 bytes gravados e lidos");
        $this->assertEquals(4, count($outbox->fetchPending(10)), "{$label}: chaves com unicode e de 190 caracteres");
        $this->assertEquals(self::ids($messages[0]), self::entryIds($outbox->findByOrderingKey('kç😀')), "{$label}: a chave com unicode é encontrada");
    }

    /** @param array<string, mixed> $env */
    private function contractInbox(string $label, array $env): void
    {
        /** @var IProcessedMessageStore $inbox */
        $inbox = $env['inbox'];
        $m1 = $this->msg(400, 'k', 'acme');
        $m2 = $this->msg(401, null);
        $this->assertEquals([false, null, 0, 0], [$inbox->has('agents', $m1->idempotencyKey()), $inbox->find('agents', $m1->idempotencyKey()), $inbox->count(), $inbox->count('agents')], "{$label}: inbox vazia");
        $this->assertTrue($inbox->markProcessed('agents', $m1, $this->at('2026-11-15 10:00:00.000001')), "{$label}: gravada");
        $this->assertFalse($inbox->markProcessed('agents', $m1, $this->at('2026-11-15 10:00:05')), "{$label}: já estava (nada gravado)");
        $this->assertTrue($inbox->has('agents', $m1->idempotencyKey()));
        $this->assertFalse($inbox->has('crm', $m1->idempotencyKey()), "{$label}: outro consumidor é outra partição");
        $row = $inbox->find('agents', $m1->idempotencyKey());
        $this->assertEquals(['consumer' => 'agents', 'idempotency_key' => 'msg:' . self::mid(400), 'message_id' => self::mid(400), 'message_name' => 'a.b.v1', 'tenant' => 'acme', 'processed_at' => '2026-11-15 10:00:00.000001'], $row?->toArray(), "{$label}: a linha (sem a carga)");
        $this->assertTrue($inbox->markProcessed('crm', $m1, $this->at('2026-11-15 10:00:02')));
        $this->assertTrue($inbox->markProcessed('agents', $m2, $this->at('2026-11-15 10:00:03')));
        $this->assertEquals([3, 2, 1, 0], [$inbox->count(), $inbox->count('agents'), $inbox->count('crm'), $inbox->count('nobody')]);
        $this->assertEquals(null, $inbox->find('agents', 'msg:nothing'));
        $this->assertEquals(null, $inbox->find('agents', $m2->idempotencyKey())?->tenant);
        $this->assertThrows(InvalidArgumentException::class, fn() => $inbox->markProcessed('Agents', $m1, $this->at('2026-11-15 10:00:00')));
        $this->assertEquals(1, $inbox->purgeProcessedBefore('agents', $this->at('2026-11-15 10:00:03')), "{$label}: a retenção apaga a anterior ao instante");
        $this->assertEquals([false, true, 2], [$inbox->has('agents', $m1->idempotencyKey()), $inbox->has('agents', $m2->idempotencyKey()), $inbox->count()]);
        $this->assertEquals(0, $inbox->purgeProcessedBefore('agents', $this->at('2026-11-15 10:00:03')), "{$label}: o instante exato fica");
        $this->assertEquals(1, $inbox->purgeProcessedBefore('agents', $this->at('2026-11-15 10:00:03.000001')));
        $this->assertEquals(0, $inbox->purgeProcessedBefore('nobody', $this->at('2030-01-01 00:00:00')));
        $this->assertEquals(1, $inbox->count('crm'), "{$label}: a retenção é por consumidor");

        $tx = $env['tx'];
        $this->assertThrows(Module48Crash::class, function () use ($tx, $inbox): void {
            $tx->transactional(function () use ($inbox): void {
                $inbox->markProcessed('agents', $this->msg(402, null), $this->at('2026-11-15 10:00:00'));
                throw new Module48Crash('the handler failed');
            });
        });
        $this->assertFalse($inbox->has('agents', 'msg:' . self::mid(402)), "{$label}: a linha volta com a transação");
    }

    /** @param array<string, mixed> $env */
    private function testPublisher(string $label, array $env): void
    {
        $outbox = $env['outbox'];
        $times = ['2026-11-15 12:00:00', '2026-11-15 12:00:01'];
        $publisher = new MessagePublisher($outbox, clock: function () use (&$times): DateTimeImmutable {
            return $this->at((string) array_shift($times));
        });
        $this->assertTrue($publisher->getOutbox() === $outbox);
        $this->assertEquals(2, $publisher->publish($this->msg(500, 'k'), $this->msg(501, 'k')), "{$label}: uma chamada, um instante");
        $this->assertEquals(['2026-11-15 12:00:00', '2026-11-15 12:00:00'], [$outbox->find(self::mid(500))?->enqueuedAt->format('Y-m-d H:i:s'), $outbox->find(self::mid(501))?->enqueuedAt->format('Y-m-d H:i:s')]);
        $this->assertEquals(1, $publisher->publish($this->msg(502, 'k'), $this->msg(502, 'k')), "{$label}: a mesma mensagem duas vezes na mesma chamada grava uma");
        $this->assertEquals('2026-11-15 12:00:01', $outbox->find(self::mid(502))?->enqueuedAt->format('Y-m-d H:i:s'));
        $this->assertTrue(strcmp((string) $outbox->find(self::mid(500))?->entryId, (string) $outbox->find(self::mid(502))?->entryId) < 0, "{$label}: a ordem das entradas é a da publicação");
        $older = new Message('a.b.v1', [], 'k', messageId: self::mid(1), occurredAt: $this->at('2020-01-01 00:00:00'));
        $publisher2 = new MessagePublisher($outbox, null, $this->clock());
        $publisher2->publish($older);
        $this->assertEquals(self::ids($this->msg(500, 'k'), $this->msg(501, 'k'), $this->msg(502, 'k'), $older), self::entryIds($outbox->findByOrderingKey('k')), "{$label}: um id de mensagem mais velho entra no fim da fila (a ordem é a da publicação)");
        $this->assertEquals(0, (new MessagePublisher($outbox))->publish($older), "{$label}: o publicador padrão também pula a repetida");
    }

    /** @param array<string, mixed> $env */
    private function testRelayOrderAndBatches(string $label, array $env): void
    {
        $outbox = $env['outbox'];
        $publisher = new MessagePublisher($outbox, null, $this->clock());
        $expected = [];
        $n = 600;
        foreach (['k1', 'k2', 'k3'] as $key) {
            for ($i = 0; $i < 3; $i++) {
                $expected[$key][] = self::mid($n);
                $publisher->publish($this->msg($n++, $key));
            }
        }
        $publisher->publish($this->msg($n++, null), $this->msg($n++, null));
        $batches = new \ArrayObject();
        $inner = new InMemoryMessageTransport();
        $spy = new class ($inner, $batches) implements IMessageTransport {
            public function __construct(private readonly InMemoryMessageTransport $inner, private readonly \ArrayObject $batches)
            {
            }

            public function send(array $batch): TransportSendResult
            {
                $this->batches->append(array_map(static fn(Message $m): string => (string) $m->orderingKey, $batch));

                return $this->inner->send($batch);
            }
        };
        $relay = new OutboxRelay($outbox, $spy, null, 2, $this->clock());
        $report = $relay->relayPending(100);
        $this->assertEquals([11, 0, []], [$report->deliveredCount(), $report->failedCount(), $report->getExhausted()], "{$label}: tudo confirmado");
        $this->assertEquals(count($batches), $report->batchCount());
        foreach ($batches->getArrayCopy() as $batch) {
            $keys = array_filter($batch, static fn(string $k): bool => $k !== '');
            $this->assertTrue(count($batch) <= 2 && count($keys) === count(array_unique($keys)), "{$label}: lote de no máximo 2, uma mensagem por chave: " . json_encode($batch));
        }
        foreach ($expected as $key => $ids) {
            $this->assertEquals($ids, self::idsOfKey($inner->stored(), $key), "{$label}: o broker gravou a chave {$key} na ordem da publicação");
        }
        $this->assertEquals([], $outbox->fetchPending(10), "{$label}: nada pendente");
        $this->assertEquals([0, 0], [$relay->relayPending(100)->deliveredCount(), $relay->relayPending(100)->batchCount()], "{$label}: o relay não manda nada duas vezes");
        foreach ($report->getDelivered() as $entry) {
            $this->assertTrue($outbox->find($entry->messageId())?->isDelivered() ?? false, "{$label}: marcada entregue");
            $this->assertEquals('2026-11-15 10:00:00', $outbox->find($entry->messageId())?->deliveredAt?->format('Y-m-d H:i:s'), "{$label}: o instante do relógio do relay");
        }

        $publisher->publish($this->msg($n++, 'k1'), $this->msg($n++, 'k2'), $this->msg($n++, 'k3'), $this->msg($n++, null));
        $limited = (new OutboxRelay($outbox, $inner, batchSize: 100, clock: $this->clock()))->relayPending(3);
        $this->assertEquals([3, 1], [$limited->deliveredCount(), $limited->batchCount()], "{$label}: o limite da rodada");
        $this->assertEquals(1, count($outbox->fetchPending(10)));
        foreach ([fn() => $relay->relayPending(0), fn() => new OutboxRelay($outbox, $inner, 0), fn() => new OutboxRelay($outbox, $inner, null, 0)] as $invalid) {
            $this->assertThrows(InvalidArgumentException::class, $invalid);
        }
        $this->assertEquals(1, (new OutboxRelay($outbox, $inner, 1, 1))->relayPending(100)->deliveredCount(), "{$label}: o relógio padrão");
        for ($i = 0; $i < 10; $i++) {
            $publisher->publish($this->msg(650 + $i, null));
        }
        $five = (new OutboxRelay($outbox, $spy, null, 2, $this->clock()))->relayPending(5);
        $this->assertEquals([5, 3], [$five->deliveredCount(), $five->batchCount()], "{$label}: o limite da rodada atravessa os lotes (2 + 2 + 1)");
        $this->assertEquals([2, 2, 1], array_map('count', array_slice($batches->getArrayCopy(), -3)), "{$label}: o último lote só com o que resta do limite");
        $this->assertEquals(5, (new OutboxRelay($outbox, $spy, null, 2, $this->clock()))->relayPending(5)->deliveredCount());
    }

    /** @param array<string, mixed> $env */
    private function testRelayFailures(string $label, array $env): void
    {
        $outbox = $env['outbox'];
        $publisher = new MessagePublisher($outbox, null, $this->clock());
        $a1 = $this->msg(700, 'a');
        $a2 = $this->msg(701, 'a');
        $b1 = $this->msg(702, 'b');
        $n1 = $this->msg(703, null);
        $publisher->publish($a1, $a2, $b1, $n1);
        $transport = new InMemoryMessageTransport();
        $faults = [$a1->messageId => [InMemoryTransportFault::FAIL], $n1->messageId => [InMemoryTransportFault::LOSE_CONFIRM]];
        $transport->withFaults(static function (Message $m) use (&$faults): InMemoryTransportFault {
            return isset($faults[$m->messageId]) ? (array_shift($faults[$m->messageId]) ?? InMemoryTransportFault::NONE) : InMemoryTransportFault::NONE;
        });
        $relay = new OutboxRelay($outbox, $transport, null, 10, $this->clock());
        $first = $relay->relayPending(100);
        $this->assertEquals([$b1->messageId], $first->deliveredIds(), "{$label}: só b1 confirmada");
        $this->assertEquals([
            $a1->messageId => 'The in-memory broker refused ' . $a1->describe() . ' (injected fault).',
            $n1->messageId => 'The broker did not confirm ' . $n1->describe() . '.',
        ], $first->getFailures(), "{$label}: o motivo do transporte; a confirmação perdida");
        $this->assertEquals([1, 1], [$outbox->find($a1->messageId)?->attempts, $outbox->find($n1->messageId)?->attempts]);
        $this->assertEquals(MessageOutboxStatus::PENDING, $outbox->find($a2->messageId)?->status, "{$label}: a2 nem foi mandada: a chave a ficou presa na rodada");
        $this->assertEquals(1, $first->batchCount(), "{$label}: as cabeças presas não voltam na mesma rodada");
        $this->assertEquals([$b1->messageId, $n1->messageId], self::idsOf($transport->stored(), false), "{$label}: n1 foi gravada mesmo sem confirmação");
        $second = $relay->relayPending(100);
        $this->assertEquals([$a1->messageId, $n1->messageId, $a2->messageId], $second->deliveredIds(), "{$label}: na rodada seguinte, a1, n1 e depois a2");
        $this->assertEquals(2, $second->batchCount());
        $this->assertEquals(2, count(array_filter($transport->stored(), static fn(Message $m): bool => $m->messageId === $n1->messageId)), "{$label}: n1 duas vezes no broker (pelo menos uma vez)");
        $this->assertEquals(self::ids($a1, $a2), self::idsOfKey($transport->stored(), 'a'), "{$label}: a ordem da chave a no broker");
        foreach (array_merge($first->getFailures(), $second->getFailures()) as $reason) {
            $this->seen($reason);
        }

        $c1 = $this->msg(704, 'c');
        $c2 = $this->msg(705, 'c');
        $d1 = $this->msg(706, 'd');
        $publisher->publish($c1, $c2, $d1);
        $transport->failNextBatch('Connection refused [tcp://127.0.0.1:6379]');
        $thrown = $relay->relayPending(100);
        $this->assertEquals([0, [$c1->messageId, $d1->messageId]], [$thrown->deliveredCount(), array_keys($thrown->getFailures())], "{$label}: o lote inteiro falha quando o transporte lança");
        $this->assertEquals('The transport failed for the whole batch (MessagingException: Connection refused [tcp://127.0.0.1:6379]).', $thrown->getFailures()[$c1->messageId]);
        $this->assertEquals('The transport failed for the whole batch (MessagingException: Connection refused [tcp://127.0.0.1:6379]).', $outbox->find($d1->messageId)?->lastError);
        $this->assertEquals(3, $relay->relayPending(100)->deliveredCount(), "{$label}: depois, tudo");

        $e1 = $this->msg(707, 'e');
        $e2 = $this->msg(708, 'e');
        $f1 = $this->msg(709, null);
        $publisher->publish($e1, $e2, $f1);
        $always = [$e1->messageId => true, $f1->messageId => true];
        $transport->withFaults(static fn(Message $m): InMemoryTransportFault => isset($always[$m->messageId]) ? InMemoryTransportFault::FAIL : InMemoryTransportFault::NONE);
        $limited = new OutboxRelay($outbox, $transport, 2, 10, $this->clock());
        $r1 = $limited->relayPending(100);
        $this->assertEquals([0, 2, []], [$r1->deliveredCount(), $r1->failedCount(), $r1->getExhausted()], "{$label}: primeira tentativa");
        $r2 = $limited->relayPending(100);
        $this->assertEquals([[$e1->messageId, $f1->messageId], 0], [$r2->getExhausted(), $r2->deliveredCount()], "{$label}: a segunda tentativa esgota (relatada)");
        $r3 = $limited->relayPending(100);
        $this->assertEquals([[$e2->messageId], []], [$r3->deliveredIds(), $r3->getFailures()], "{$label}: esgotada, a e1 não segura mais a chave: e2 passa (RN-04 declarada)");
        $this->assertEquals([MessageOutboxStatus::FAILED, 2], [$outbox->find($e1->messageId)?->status, $outbox->find($e1->messageId)?->attempts]);
        $this->assertEquals(0, $limited->relayPending(100)->batchCount(), "{$label}: as esgotadas não saem mais");
        $this->assertEquals(self::ids($e1, $f1), (new OutboxRelay($outbox, $transport->withFaults(null), null, 10, $this->clock()))->relayPending(100)->deliveredIds(), "{$label}: sem limite, um relay as manda de novo");

        $sizes = new \ArrayObject();
        $counting = new class ($transport, $sizes) implements IMessageTransport {
            public function __construct(private readonly InMemoryMessageTransport $inner, private readonly \ArrayObject $sizes)
            {
            }

            public function send(array $batch): TransportSendResult
            {
                $this->sizes->append(count($batch));

                return $this->inner->send($batch);
            }
        };
        $loose = [];
        for ($i = 0; $i < 5; $i++) {
            $loose[] = $this->msg(720 + $i, null);
        }
        $publisher->publish(...$loose);
        $transport->withFaults(static fn(Message $m): InMemoryTransportFault => $m->messageId === self::mid(720) ? InMemoryTransportFault::FAIL : InMemoryTransportFault::NONE);
        $once = (new OutboxRelay($outbox, $counting, 1, 2, $this->clock()))->relayPending(100);
        $this->assertEquals([[2, 2, 1], [self::mid(720)], 4], [$sizes->getArrayCopy(), $once->getExhausted(), $once->deliveredCount()], "{$label}: esgotada sem chave, a rodada segue sem passar do tamanho do lote");
        $transport->withFaults(null);
        for ($i = 0; $i < 101; $i++) {
            $publisher->publish($this->msg(4000 + $i, null));
        }
        $sizes->exchangeArray([]);
        $default = new OutboxRelay($outbox, $counting, clock: $this->clock());
        $this->assertEquals([100, [100]], [$default->relayPending()->deliveredCount(), $sizes->getArrayCopy()], "{$label}: o limite e o lote padrão são 100");
        $this->assertEquals([2, [100, 2]], [$default->relayPending()->deliveredCount(), $sizes->getArrayCopy()], "{$label}: o resto, com a 720 que só o relay com limite dava por esgotada");
        for ($i = 0; $i < 101; $i++) {
            $publisher->publish($this->msg(4200 + $i, null));
        }
        $sizes->exchangeArray([]);
        $this->assertEquals([101, [100, 1]], [$default->relayPending(500)->deliveredCount(), $sizes->getArrayCopy()], "{$label}: o lote padrão é 100, qualquer que seja o limite");
    }

    /** @param array<string, mixed> $env */
    private function testRelayCrashBetweenConfirmAndMark(string $label, array $env): void
    {
        $crashing = new Module48CrashingOutbox($env['outbox']);
        $publisher = new MessagePublisher($crashing, null, $this->clock());
        $publisher->publish($this->msg(800, 'k'), $this->msg(801, 'k'), $this->msg(802, 'j'));
        $transport = new InMemoryMessageTransport();
        $crashing->crashOnDelivered = static fn(MessageOutboxEntry $e): bool => $e->messageId() === self::mid(801);
        $relay = new OutboxRelay($crashing, $transport, null, 10, $this->clock());
        $this->assertThrows(Module48Crash::class, fn() => $relay->relayPending(100));
        $this->assertEquals([true, false], [$crashing->find(self::mid(800))?->isDelivered(), $crashing->find(self::mid(801))?->isDelivered()], "{$label}: 800 marcada; o processo morreu depois da confirmação da 801");
        $crashing->crashOnDelivered = null;
        $again = (new OutboxRelay($crashing, $transport, null, 10, $this->clock()))->relayPending(100);
        $this->assertEquals(self::ids($this->msg(801, 'k')), array_values(array_intersect($again->deliveredIds(), [self::mid(801)])), "{$label}: a 801 é mandada de novo");
        $this->assertEquals([self::mid(800), self::mid(801), self::mid(801)], self::idsOfKey($transport->stored(), 'k'), "{$label}: o broker tem a 801 duas vezes, nunca a perdeu");

        $source = new InMemoryMessageSource($transport);
        $effects = $env['effect'];
        $handlers = (new MessageHandlerRegistry())->register('a.b.v1', static function (Message $m) use ($effects): void {
            $effects('relay|' . $m->messageId);
        });
        $report = (new MessageConsumer('relay-crash', $source, $handlers, $env['inbox'], $env['tx'], clock: $this->clock()))->consume(100);
        $this->assertEquals([3, 1], [$report->countOf(ConsumeOutcome::HANDLED), $report->countOf(ConsumeOutcome::DUPLICATE)], "{$label}: o consumidor deduplica a cópia");
        $this->assertEquals(['relay|' . self::mid(800), 'relay|' . self::mid(802), 'relay|' . self::mid(801)], array_values(array_filter(($env['effects'])(), static fn(string $l): bool => str_starts_with($l, 'relay|'))), "{$label}: cada efeito uma vez");
    }

    /** @param array<string, mixed> $env */
    private function testConsumerOutcomes(string $label, array $env): void
    {
        $transport = new InMemoryMessageTransport();
        $plain = new InMemoryMessageSource($transport);
        $source = new Module48CrashingSource($plain);
        $effects = $env['effect'];
        $handlers = (new MessageHandlerRegistry())
            ->register('a.b.v1', static function (Message $m) use ($effects): void {
                $effects('one|' . $m->messageId);
            })
            ->register('a.b.v1', static function (Message $m) use ($effects): void {
                $effects('two|' . $m->messageId);
            });
        $consumer = new MessageConsumer('outcomes', $source, $handlers, $env['inbox'], $env['tx'], clock: $this->clock());
        $this->assertEquals(['outcomes', null, 5], [$consumer->getConsumer(), $consumer->getTenant(), $consumer->getMaxDeliveries()]);
        $m1 = $this->msg(900, 'k');
        $m2 = new Message('other.fact.v1', ['secret' => self::SECRET], 'k', messageId: self::mid(901));
        $transport->send([$m1]);
        $transport->send([$m1]); // the broker delivers it twice
        $transport->send([$m2]);
        $transport->storeRaw('{"name":"a.b.v1","payload":{"secret":"' . self::SECRET . '"}}');
        $report = $consumer->consume(10, 0);
        $this->assertEquals(['handled', 'duplicate', 'unknown_name', 'dead_lettered'], array_map(static fn(ConsumeEntry $e): string => $e->outcome->value, $report->entries()), "{$label}: os desfechos");
        $this->assertEquals(['ack 0', 'ack 1', 'ack 2', 'dead 3'], $source->answers, "{$label}: as respostas à fonte");
        $this->assertEquals(['Consumer "outcomes" has no handler for ' . $m2->describe() . ': acknowledged without handling.'], $report->warnings(), "{$label}: o aviso do nome sem handler");
        $this->assertTrue(str_starts_with($report->deadLetters()['3'], 'The delivery is not a message of format 1: '), "{$label}: a entrega inválida vai à fila morta");
        $this->assertEquals([self::mid(900)], $report->handledIds());
        $this->assertEquals(['one|' . self::mid(900), 'two|' . self::mid(900)], self::linesWith(($env['effects'])(), self::mid(900)), "{$label}: os dois handlers, uma vez");
        $this->assertTrue(($env['inbox'])->has('outcomes', $m1->idempotencyKey()), "{$label}: a linha da inbox");
        $this->assertFalse(($env['inbox'])->has('outcomes', $m2->idempotencyKey()), "{$label}: o nome sem handler não entra na inbox");
        $first = $report->entries()[0];
        $this->assertEquals(['0', self::mid(900), 'a.b.v1', 'k', 1, null], [$first->brokerId, $first->messageId, $first->name, $first->orderingKey, $first->deliveryCount, $first->reason]);
        $this->assertEquals(0, $consumer->consume(10)->receivedCount(), "{$label}: nada mais");
        foreach ($report->entries() as $entry) {
            $this->seen((string) $entry->reason);
        }
        $this->assertEquals('2026-11-15 10:00:00', ($env['inbox'])->find('outcomes', $m1->idempotencyKey())?->processedAt->format('Y-m-d H:i:s'), "{$label}: o instante do relógio do consumidor");
        foreach ([[fn() => $consumer->consume(0), 'consume() takes at least 1 delivery, 0 given.'], [fn() => $consumer->consume(1, -1), 'consume() cannot wait a negative time, -1 given.']] as [$invalid, $text]) {
            $e = $this->assertThrows(InvalidArgumentException::class, $invalid);
            $this->assertEquals($text, $e->getMessage(), "{$label}: o consumidor valida antes da fonte");
        }
        foreach ([
            fn() => new MessageConsumer('Bad', $source, $handlers, $env['inbox']),
            fn() => new MessageConsumer('ok', $source, $handlers, $env['inbox'], maxDeliveries: 0),
            fn() => new MessageConsumer('ok', $source, $handlers, $env['inbox'], tenant: ''),
            fn() => new MessageConsumer('ok', $source, $handlers, $env['inbox'], tenant: ' acme'),
            fn() => new MessageConsumer('ok', $source, $handlers, $env['inbox'], tenant: str_repeat('t', 121)),
        ] as $invalid) {
            $this->assertThrows(InvalidArgumentException::class, $invalid);
        }
        $this->assertEquals(1, (new MessageConsumer('ok', $source, $handlers, $env['inbox'], maxDeliveries: 1, tenant: str_repeat('t', 120)))->getMaxDeliveries());

        // A crash between the commit and the acknowledgement: the redelivery is a duplicate
        $m3 = $this->msg(902, 'k');
        $transport->send([$m3]);
        $source->crashOnAck = static fn(ReceivedMessage $r): bool => $r->message()->messageId === self::mid(902);
        $this->assertThrows(Module48Crash::class, fn() => $consumer->consume(10));
        $source->crashOnAck = null;
        $this->assertTrue(($env['inbox'])->has('outcomes', $m3->idempotencyKey()), "{$label}: o efeito e a linha confirmados antes do ack");
        $this->assertEquals(1, $plain->recoverInFlight());
        $again = $consumer->consume(10);
        $this->assertEquals([['duplicate', 2]], array_map(static fn(ConsumeEntry $e): array => [$e->outcome->value, $e->deliveryCount], $again->entries()), "{$label}: a reentrega é duplicada");
        $this->assertEquals(['one|' . self::mid(902), 'two|' . self::mid(902)], self::linesWith(($env['effects'])(), self::mid(902)), "{$label}: efeito uma vez");
    }

    /** @param array<string, mixed> $env */
    private function testConsumerFailuresAndRedeliveries(string $label, array $env): void
    {
        $transport = new InMemoryMessageTransport();
        $inner = new InMemoryMessageSource($transport);
        $source = new Module48CrashingSource($inner);
        $effects = $env['effect'];
        $fail = [self::mid(1000) => 2, self::mid(1003) => 99];
        $errors = [];
        $handlers = (new MessageHandlerRegistry())->register('a.b.v1', static function (Message $m) use ($effects, &$fail): void {
            $effects('fx|' . $m->messageId);
            if (($m->payload['refuse'] ?? false) === true) {
                throw new InvalidMessagePayloadException('lead_id missing in ' . json_encode($m->payload));
            }
            if (($fail[$m->messageId] ?? 0) > 0) {
                $fail[$m->messageId]--;
                throw new RuntimeException('handler broke on ' . json_encode($m->payload));
            }
        });
        $consumer = new MessageConsumer('failures', $source, $handlers, $env['inbox'], $env['tx'], 3, null, $this->clock(), static function (Message $m, Throwable $e, int $count) use (&$errors): void {
            $errors[] = [$m->messageId, get_class($e), $count];
            throw new RuntimeException('a broken logger');
        });
        $k1 = $this->msg(1000, 'k');
        $k2 = $this->msg(1001, 'k');
        $j1 = $this->msg(1002, 'j');
        $poison = $this->msg(1003, 'p');
        $after = $this->msg(1004, 'p');
        $refused = new Message('a.b.v1', ['refuse' => true, 'secret' => self::SECRET], 'r', messageId: self::mid(1005));
        $transport->send([$k1, $j1, $poison, $refused]);
        $transport->send([$k2, $after]);

        $r1 = $consumer->consume(10);
        $this->assertEquals([
            [self::mid(1000), 'retried'], [self::mid(1002), 'handled'], [self::mid(1003), 'retried'],
            [self::mid(1005), 'dead_lettered'], [self::mid(1001), 'held_back'], [self::mid(1004), 'held_back'],
        ], array_map(static fn(ConsumeEntry $e): array => [$e->messageId, $e->outcome->value], $r1->entries()), "{$label}: rodada 1");
        $this->assertEquals('A handler of consumer "failures" failed for ' . $k1->describe() . ' (RuntimeException) on delivery 1 of at most 3.', $r1->entries()[0]->reason);
        $this->assertEquals('A handler of consumer "failures" failed for ' . $refused->describe() . ' (InvalidMessagePayloadException) on delivery 1 of at most 3; the payload was refused.', $r1->entries()[3]->reason, "{$label}: a carga recusada vai à fila morta de primeira");
        $this->assertEquals('An earlier message of ordering key "k" was retried in this run: ' . $k2->describe() . ' held back.', $r1->entries()[4]->reason);
        $this->assertEquals([[self::mid(1000), RuntimeException::class, 1], [self::mid(1003), RuntimeException::class, 1], [self::mid(1005), InvalidMessagePayloadException::class, 1]], $errors, "{$label}: onHandlerError recebe a exceção (e o que ele lança é ignorado)");
        $this->assertEquals(['fx|' . self::mid(1002)], self::linesWith(($env['effects'])(), 'fx|'), "{$label}: os efeitos das que falharam voltaram com a transação");
        $this->assertFalse(($env['inbox'])->has('failures', $k1->idempotencyKey()), "{$label}: a linha também");

        $r2 = $consumer->consume(10);
        $this->assertEquals([
            [self::mid(1000), 'retried', 2], [self::mid(1003), 'retried', 2], [self::mid(1001), 'held_back', 2], [self::mid(1004), 'held_back', 2],
        ], array_map(static fn(ConsumeEntry $e): array => [$e->messageId, $e->outcome->value, $e->deliveryCount], $r2->entries()), "{$label}: rodada 2: as devolvidas antes, na ordem do log");
        $r3 = $consumer->consume(10);
        $this->assertEquals([
            [self::mid(1000), 'handled', 3], [self::mid(1003), 'dead_lettered', 3], [self::mid(1001), 'handled', 3], [self::mid(1004), 'handled', 3],
        ], array_map(static fn(ConsumeEntry $e): array => [$e->messageId, $e->outcome->value, $e->deliveryCount], $r3->entries()), "{$label}: rodada 3: a envenenada vai à fila morta na entrega 3 e destrava a chave");
        $this->assertEquals('A handler of consumer "failures" failed for ' . $poison->describe() . ' (RuntimeException) on delivery 3 of at most 3.', $r3->entries()[1]->reason);
        $this->assertEquals(['fx|' . self::mid(1002), 'fx|' . self::mid(1000), 'fx|' . self::mid(1001), 'fx|' . self::mid(1004)], self::linesWith(($env['effects'])(), 'fx|'), "{$label}: cada efeito uma vez, k na ordem (RN-04)");
        $this->assertEquals([self::mid(1005), self::mid(1003)], array_map(static fn(array $d): ?string => $d['messageId'], $inner->deadLetters()), "{$label}: a fila morta da fonte = o relatado");
        $this->assertEquals([0, 0], [$inner->pendingCount(), $inner->inFlightCount()]);

        // A delivery above maxDeliveries (the process died on it each time) is dead-lettered without running
        $crasher = $this->msg(1006, 'z');
        $transport->send([$crasher]);
        for ($i = 0; $i < 3; $i++) {
            $inner->receive(10, 0);
            $inner->recoverInFlight();
        }
        $r4 = $consumer->consume(10);
        $this->assertEquals([[self::mid(1006), 'dead_lettered', 4]], array_map(static fn(ConsumeEntry $e): array => [$e->messageId, $e->outcome->value, $e->deliveryCount], $r4->entries()));
        $this->assertEquals('Message "a.b.v1" (id ' . self::mid(1006) . ', ordering key "z") was delivered 4 times to consumer "failures", more than maxDeliveries (3).', $r4->entries()[0]->reason);
        $this->assertEquals([], self::linesWith(($env['effects'])(), self::mid(1006)), "{$label}: o handler não rodou");
        $letters = $inner->deadLetters();
        $last = (array) end($letters);
        $this->assertEquals([self::mid(1006), 4, 0, 0], [$last['messageId'] ?? null, $last['deliveryCount'] ?? null, $inner->pendingCount(), $inner->inFlightCount()], "{$label}: a entrega acima do limite foi à fila morta da fonte");
        foreach ([...$r1->entries(), ...$r2->entries(), ...$r3->entries(), ...$r4->entries()] as $entry) {
            $this->seen((string) $entry->reason);
        }
        foreach ($inner->deadLetters() as $dead) {
            $this->seen($dead['reason']);
        }

        // A race of two processes of the same consumer on the inbox: requeued, then a duplicate
        $racing = new class ($env['inbox']) implements IProcessedMessageStore {
            public bool $race = true;

            public function __construct(private readonly IProcessedMessageStore $inner)
            {
            }

            public function has(string $consumer, string $idempotencyKey): bool
            {
                return $this->inner->has($consumer, $idempotencyKey);
            }

            public function markProcessed(string $consumer, Message $message, DateTimeImmutable $at): bool
            {
                if ($this->race) {
                    $this->race = false;
                    $this->inner->markProcessed($consumer, $message, $at);
                    throw new MessagingPersistenceException('Message inbox statement failed: mark processed (SQLSTATE 23505, driver code 7).', '23505', '7');
                }

                return $this->inner->markProcessed($consumer, $message, $at);
            }

            public function find(string $consumer, string $idempotencyKey): ?ProcessedMessage
            {
                return $this->inner->find($consumer, $idempotencyKey);
            }

            public function count(?string $consumer = null): int
            {
                return $this->inner->count($consumer);
            }

            public function purgeProcessedBefore(string $consumer, DateTimeImmutable $before): int
            {
                return $this->inner->purgeProcessedBefore($consumer, $before);
            }
        };
        $raceTransport = new InMemoryMessageTransport();
        $raceSource = new InMemoryMessageSource($raceTransport);
        $raceConsumer = new MessageConsumer('race', $raceSource, $handlers, $racing, null, 3, null, $this->clock());
        $raceTransport->send([$this->msg(1007, 'q'), $this->msg(1008, 'q')]);
        $rr = $raceConsumer->consume(10);
        $this->assertEquals([['retried', 'The inbox of consumer "race" got ' . $this->msg(1007, 'q')->describe() . ' from another process at the same time: requeued.'], ['held_back', 'An earlier message of ordering key "q" was retried in this run: ' . $this->msg(1008, 'q')->describe() . ' held back.']], array_map(static fn(ConsumeEntry $e): array => [$e->outcome->value, $e->reason], $rr->entries()), "{$label}: a corrida na inbox");
        $this->assertEquals(['duplicate', 'handled'], array_map(static fn(ConsumeEntry $e): string => $e->outcome->value, $raceConsumer->consume(10)->entries()), "{$label}: a reentrega acha a linha");
    }

    /** @param array<string, mixed> $env */
    private function testConsumerTenant(string $label, array $env): void
    {
        $transport = new InMemoryMessageTransport();
        $effects = $env['effect'];
        $handlers = (new MessageHandlerRegistry())->register('a.b.v1', static function (Message $m) use ($effects): void {
            $effects('tenant|' . $m->messageId . '|' . ($m->tenant ?? '-'));
        });
        $acmeSource = new InMemoryMessageSource($transport);
        $acme = new MessageConsumer('tenant-acme', $acmeSource, $handlers, $env['inbox'], $env['tx'], tenant: 'acme', clock: $this->clock());
        $all = new MessageConsumer('tenant-all', new InMemoryMessageSource($transport), $handlers, $env['inbox'], $env['tx'], clock: $this->clock());
        $transport->send([$this->msg(1100, null, 'acme'), $this->msg(1101, null, 'globex'), $this->msg(1102, null, null), $this->msg(1103, null, 'ACME')]);
        $report = $acme->consume(10);
        $this->assertEquals(['handled', 'other_tenant', 'other_tenant', 'other_tenant'], array_map(static fn(ConsumeEntry $e): string => $e->outcome->value, $report->entries()), "{$label}: D10, só o tenant do consumidor");
        $this->assertEquals('Consumer "tenant-acme" handles tenant "acme" only; ' . $this->msg(1101, null)->describe() . ' is of tenant "globex": acknowledged without handling.', $report->entries()[1]->reason);
        $this->assertEquals('Consumer "tenant-acme" handles tenant "acme" only; ' . $this->msg(1102, null)->describe() . ' is of no tenant: acknowledged without handling.', $report->entries()[2]->reason);
        $this->assertEquals('acme', $acme->getTenant());
        $this->assertEquals([4, 0, 0], [$acmeSource->acknowledgedCount(), $acmeSource->pendingCount(), $acmeSource->inFlightCount()], "{$label}: as de outro tenant recebem ack");
        $this->assertEquals(str_repeat('ç', 120), (new MessageConsumer('wide', $acmeSource, $handlers, $env['inbox'], tenant: str_repeat('ç', 120)))->getTenant(), "{$label}: 120 caracteres de tenant, não bytes");
        $this->assertEquals(4, $all->consume(10)->countOf(ConsumeOutcome::HANDLED), "{$label}: o consumidor sem tenant recebe tudo");
        $this->assertEquals(['tenant|' . self::mid(1100) . '|acme', 'tenant|' . self::mid(1100) . '|acme', 'tenant|' . self::mid(1101) . '|globex', 'tenant|' . self::mid(1102) . '|-', 'tenant|' . self::mid(1103) . '|ACME'], self::linesWith(($env['effects'])(), 'tenant|'));
        $this->assertEquals('acme', ($env['inbox'])->find('tenant-acme', 'msg:' . self::mid(1100))?->tenant, "{$label}: o tenant na inbox");
    }

    /** @param array<string, mixed> $env */
    private function testTwoConsumers(string $label, array $env): void
    {
        $transport = (new InMemoryMessageTransport())->withFaults(static fn(Message $m): InMemoryTransportFault => InMemoryTransportFault::DUPLICATE);
        $publisher = new MessagePublisher($env['outbox'], null, $this->clock());
        $publisher->publish($this->msg(1200, 'k'), $this->msg(1201, 'k'), $this->msg(1202, null));
        (new OutboxRelay($env['outbox'], $transport, null, 10, $this->clock()))->relayPending(100);
        $effects = $env['effect'];
        foreach (['alpha', 'beta'] as $name) {
            $handlers = (new MessageHandlerRegistry())->register('a.b.v1', static function (Message $m) use ($effects, $name): void {
                $effects("two|{$name}|" . $m->messageId);
            });
            $consumer = new MessageConsumer($name, new InMemoryMessageSource($transport), $handlers, $env['inbox'], $env['tx'], clock: $this->clock());
            $report = $consumer->consume(2);
            $report2 = $consumer->consume(100);
            $this->assertEquals([3, 3], [$report->countOf(ConsumeOutcome::HANDLED) + $report2->countOf(ConsumeOutcome::HANDLED), $report->countOf(ConsumeOutcome::DUPLICATE) + $report2->countOf(ConsumeOutcome::DUPLICATE)], "{$label}: {$name}: três tratadas, três cópias");
        }
        $this->assertEquals([
            'two|alpha|' . self::mid(1200), 'two|alpha|' . self::mid(1202), 'two|alpha|' . self::mid(1201),
            'two|beta|' . self::mid(1200), 'two|beta|' . self::mid(1202), 'two|beta|' . self::mid(1201),
        ], self::linesWith(($env['effects'])(), 'two|'), "{$label}: uma vez por consumidor (RN-03)");
    }

    // =====================================================================
    // The consumer without a transaction manager, and infrastructure failures
    // =====================================================================

    private function testConsumerWithoutTransactions(): void
    {
        $transport = new InMemoryMessageTransport();
        $inbox = new InMemoryProcessedMessageStore();
        $order = [];
        $handlers = (new MessageHandlerRegistry())->register('a.b.v1', static function (Message $m) use (&$order, $inbox): void {
            $order[] = 'handler saw the inbox ' . ($inbox->has('plain', $m->idempotencyKey()) ? 'with' : 'without') . ' the row';
            if (($m->payload['fail'] ?? false) === true) {
                throw new RuntimeException('x');
            }
        });
        $consumer = new MessageConsumer('plain', new InMemoryMessageSource($transport), $handlers, $inbox);
        $transport->send([$this->msg(1300, null), new Message('a.b.v1', ['fail' => true], messageId: self::mid(1301))]);
        $report = $consumer->consume(10);
        $this->assertEquals(['handled', 'retried'], array_map(static fn(ConsumeEntry $e): string => $e->outcome->value, $report->entries()));
        $this->assertEquals(['handler saw the inbox without the row', 'handler saw the inbox without the row'], $order, 'sem transação: o handler antes da linha');
        $this->assertEquals([true, false], [$inbox->has('plain', 'msg:' . self::mid(1300)), $inbox->has('plain', 'msg:' . self::mid(1301))], 'a falha não grava a linha');
        $this->assertEquals('UTC', $inbox->find('plain', 'msg:' . self::mid(1300))?->processedAt->getTimezone()->getName(), 'o relógio padrão');

        $tx = new InMemoryTransactionManager([$inbox]);
        $seen = [];
        $handlers2 = (new MessageHandlerRegistry())->register('a.b.v1', static function (Message $m) use (&$seen, $inbox): void {
            $seen[] = $inbox->has('tx', $m->idempotencyKey());
        });
        $transport2 = new InMemoryMessageTransport();
        $transport2->send([$this->msg(1302, null)]);
        (new MessageConsumer('tx', new InMemoryMessageSource($transport2), $handlers2, $inbox, $tx))->consume(10);
        $this->assertEquals([true], $seen, 'com transação: a linha antes do handler, na mesma transação');
    }

    private function testConsumerPropagatesInfrastructureFailures(): void
    {
        $transport = new InMemoryMessageTransport();
        $source = new InMemoryMessageSource($transport);
        $broken = new class () implements IProcessedMessageStore {
            public function has(string $consumer, string $idempotencyKey): bool
            {
                return false;
            }

            public function markProcessed(string $consumer, Message $message, DateTimeImmutable $at): bool
            {
                throw new MessagingPersistenceException('Message inbox statement failed: mark processed on message_inbox (SQLSTATE 08006, driver code 7).', '08006', '7');
            }

            public function find(string $consumer, string $idempotencyKey): ?ProcessedMessage
            {
                return null;
            }

            public function count(?string $consumer = null): int
            {
                return 0;
            }

            public function purgeProcessedBefore(string $consumer, DateTimeImmutable $before): int
            {
                return 0;
            }
        };
        $handlers = (new MessageHandlerRegistry())->register('a.b.v1', static function (): void {
        });
        $transport->send([$this->msg(1400, null), $this->msg(1401, null)]);
        $consumer = new MessageConsumer('broken', $source, $handlers, $broken, new InMemoryTransactionManager());
        $e = $this->assertThrows(MessagingPersistenceException::class, fn() => $consumer->consume(10));
        $this->assertEquals('08006', $e->getSqlState());
        $this->assertEquals([2, 0], [$source->inFlightCount(), $source->acknowledgedCount()], 'a falha que não é da mensagem para a rodada; tudo fica em voo para a reentrega');
        $this->assertEquals([], $source->deadLetters());
    }

    // =====================================================================
    // D9: the bridges
    // =====================================================================

    private function testDomainEventBridge(): void
    {
        $outbox = new InMemoryMessageOutbox();
        $publisher = new MessagePublisher($outbox, null, $this->clock());
        $bridge = new DomainEventMessageBridge($publisher, ['invoice.overdue' => 'billing.invoice_overdue.v1', 'billing.invoice_paid.v1'], static fn(DomainEvent $e): ?string => $e->entityId === '1' ? 'acme' : null);
        $this->assertEquals(['invoice.overdue' => 'billing.invoice_overdue.v1', 'billing.invoice_paid.v1' => 'billing.invoice_paid.v1'], $bridge->getNames(), 'mapa e lista');
        $registry = $bridge->registerIn(new DomainEventHandlerRegistry());
        $this->assertEquals(['invoice.overdue', 'billing.invoice_paid.v1'], $registry->names());

        $at = new DateTimeImmutable('2026-11-15 10:00:00.123456', new DateTimeZone('UTC'));
        $event = new DomainEvent('invoice.overdue', 'invoice.is_overdue', DomainEventKind::ENTER, 'App\\Invoice', '1', $at);
        $message = $bridge->toMessage($event);
        $this->assertEquals('billing.invoice_overdue.v1', $message->name);
        $this->assertEquals(['trigger' => 'invoice.is_overdue', 'kind' => 'enter', 'entity_class' => 'App\\Invoice', 'entity_id' => '1'], $message->payload, 'a carga do evento, nunca a entidade');
        $this->assertEquals(['App\\Invoice:1', 'acme', [DomainEventMessageBridge::HEADER_DOMAIN_EVENT_KEY => $event->getIdempotencyKey()]], [$message->orderingKey, $message->tenant, $message->headers]);
        $this->assertEquals($at->format('Y-m-d H:i:s.u'), $message->occurredAt->format('Y-m-d H:i:s.u'));
        $this->assertTrue(UlidGenerator::isValid($message->messageId), 'o id derivado é um ULID');
        $this->assertEquals((int) $at->format('Uv'), UlidGenerator::timestampOf($message->messageId), 'o tempo do ULID é o instante do evento');
        $this->assertEquals(self::oracleUlid($event->getIdempotencyKey(), (int) $at->format('Uv')), $message->messageId, 'o id = tempo + os 80 primeiros bits do SHA-256 da chave');
        $this->assertEquals($message->messageId, $bridge->toMessage($event)->messageId, 'determinístico');
        $this->assertEquals($message->messageId, DomainEventMessageBridge::messageIdOf($event->getIdempotencyKey(), (int) $at->format('Uv')));
        $this->assertFalse($message->messageId === $bridge->toMessage($event, 'de:other')->messageId, 'a chave dada pelo despachante manda');
        $this->assertEquals('de:other', $bridge->toMessage($event, 'de:other')->headers['domain-event-key']);
        $this->assertEquals('0000000000', substr(DomainEventMessageBridge::messageIdOf('k', -5), 0, 10), 'o tempo antes de 1970 vira 0');
        $this->assertEquals('7ZZZZZZZZZ', substr(DomainEventMessageBridge::messageIdOf('k', PHP_INT_MAX), 0, 10), 'o tempo além do ULID vira o máximo');
        foreach (['', 'a', 'de:x', str_repeat('z', 300)] as $key) {
            $this->assertEquals(self::oracleUlid($key, 1763200000000), DomainEventMessageBridge::messageIdOf($key, 1763200000000), 'o oráculo');
        }

        $long = new DomainEvent('billing.invoice_paid.v1', 'invoice.paid', DomainEventKind::EXIT, 'App\\' . str_repeat('C', 150), str_repeat('9', 60), $at);
        $this->assertEquals('entity:' . hash('sha256', 'App\\' . str_repeat('C', 150) . "\0" . str_repeat('9', 60)), $bridge->toMessage($long)->orderingKey, 'chave longa demais: o hash');
        $this->assertEquals(190, mb_strlen(DomainEventMessageBridge::orderingKeyOf(new DomainEvent('billing.invoice_paid.v1', 'invoice.paid', DomainEventKind::EXIT, 'App\\' . str_repeat('C', 145), str_repeat('9', 40), $at))), 'exatamente 190: sem hash');
        $this->assertEquals([null, 'exit'], [$bridge->toMessage($long)->tenant, $bridge->toMessage($long)->payload['kind']]);
        $wide = new DomainEvent('billing.invoice_paid.v1', 'invoice.paid', DomainEventKind::EXIT, 'App\\X', str_repeat('ç', 180), $at);
        $this->assertEquals('App\\X:' . str_repeat('ç', 180), DomainEventMessageBridge::orderingKeyOf($wide), '190 caracteres contam caracteres, não bytes');

        // Through the DomainEventDispatcher, redelivered: one message
        $events = new InMemoryDomainEventOutbox();
        (new OutboxDomainEventPublisher($events))->publish([$event]);
        $dispatcher = new DomainEventDispatcher($events, $registry);
        $this->assertEquals(1, $dispatcher->dispatchPending()->deliveredCount());
        $bridge($event, $event->getIdempotencyKey());
        $this->assertEquals(1, $outbox->count(), 'a reentrega do evento publica o mesmo id, que a outbox pula');
        $this->assertEquals($message->toArray(), $outbox->find($message->messageId)?->message->toArray());
        $this->assertThrows(LogicException::class, fn() => $bridge->toMessage(new DomainEvent('other.event', 't', DomainEventKind::ENTER, 'C', '1', $at)));
        foreach ([[], ['documents.validated'], ['x' => 'Bad.name.v1'], ['' => 'a.b.v1'], ['x' => 5]] as $names) {
            $this->assertThrows(InvalidArgumentException::class, fn() => new DomainEventMessageBridge($publisher, $names));
        }
        $this->assertEquals(null, (new DomainEventMessageBridge($publisher, ['invoice.overdue' => 'a.b.v1']))->toMessage($event)->tenant, 'sem resolvedor: sem tenant');
    }

    private function testMachineBridge(): void
    {
        $persistence = MachinePersistence::inMemory();
        $machine = Spec::machine('collection', 1)
            ->region('collection', 'idle')
                ->state('idle')
                    ->on('START_COLLECTION', 'collecting')
                ->state('collecting')
                    ->on('PAID', 'closed')
                ->finalState('closed')
            ->build();
        $runner = new MachineRunner(Spec::stateMachine(), new InMemoryMachineDefinitionRepository([$machine]), $persistence);
        $runner->start('collection-1', 'collection', 1, null, $this->at('2026-11-15 08:00:00'), tenant: 'acme');
        $runner->start('collection-2', 'collection', 1, null, $this->at('2026-11-15 08:00:00'));
        $contexts = [];
        $bridge = new MessageMachineBridge(
            $runner,
            ['billing.invoice_overdue.v1' => 'START_COLLECTION'],
            static fn(Message $m): ?string => isset($m->payload['entity_id']) ? 'collection-' . $m->payload['entity_id'] : null,
            static function (Message $m, string $instanceId) use (&$contexts): array {
                $contexts[] = $instanceId;

                return ['source' => 'message'];
            },
            'messaging'
        );
        $handlers = $bridge->registerIn(new MessageHandlerRegistry());
        $this->assertEquals(['billing.invoice_overdue.v1'], $handlers->names());
        $this->assertEquals(['billing.invoice_overdue.v1' => 'START_COLLECTION'], $bridge->getEventTypes());
        $message = new Message('billing.invoice_overdue.v1', ['entity_id' => '1', 'secret' => self::SECRET], 'App\\Invoice:1', 'acme', messageId: self::mid(1500), occurredAt: $this->at('2026-11-15 09:00:00.5'));
        $result = $bridge->deliver($message);
        $this->assertTrue($result?->isAccepted() ?? false, 'a máquina andou');
        $this->assertEquals(['collecting', 2], [$runner->load('collection-1')->snapshot->stateOf('collection'), $runner->load('collection-1')->version]);
        $move = $persistence->history->findByInstance('collection-1')[1];
        $this->assertEquals(['START_COLLECTION', EventOrigin::EXTERNAL, 'messaging', $message->idempotencyKey(), $message->payload, '2026-11-15 09:00:00.500000'], [
            $move->eventType, $move->origin, $move->actor, $move->idempotencyKey, $move->payload, $move->occurredAt->format('Y-m-d H:i:s.u'),
        ], 'o evento da máquina: tipo, origem externa, ator, a chave da mensagem, a carga como está, o instante');
        $this->assertEquals(['collection-1'], $contexts);
        $bridge($message);
        $this->assertEquals(2, $runner->load('collection-1')->version, 'a reentrega não move de novo (a chave da mensagem no runner)');
        $this->assertEquals(null, $bridge->deliver(new Message('billing.invoice_overdue.v1', [], tenant: 'acme')), 'sem instância: nada');
        $this->assertThrows(MachineInstanceNotFoundException::class, fn() => $bridge->deliver(new Message('billing.invoice_overdue.v1', ['entity_id' => '1'], tenant: 'globex')), 'a instância de outro tenant não é achada');
        $this->assertThrows(LogicException::class, fn() => $bridge->deliver(new Message('other.fact.v1')));
        $internal = new MessageMachineBridge($runner, ['billing.invoice_overdue.v1' => 'START_COLLECTION'], static fn(Message $m): ?string => 'collection-2', scopeByTenant: false, origin: EventOrigin::INTERNAL);
        $this->assertTrue($internal->deliver(new Message('billing.invoice_overdue.v1', [], tenant: 'globex', messageId: self::mid(1501)))?->isAccepted() ?? false, 'sem escopo de tenant');
        $this->assertEquals([EventOrigin::INTERNAL, null], [$persistence->history->findByInstance('collection-2')[1]->origin, $persistence->history->findByInstance('collection-2')[1]->actor]);
        foreach ([[], ['Bad' => 'X'], ['a.b.v1' => ''], ['a.b.v1' => ' X'], [5 => 'X'], ['a.b.v1' => 5]] as $map) {
            $this->assertThrows(InvalidArgumentException::class, fn() => new MessageMachineBridge($runner, $map, static fn(): ?string => null));
        }

        // Through a consumer: a failure of the machine is retried
        $transport = new InMemoryMessageTransport();
        $consumer = new MessageConsumer('collections', new InMemoryMessageSource($transport), $handlers, new InMemoryProcessedMessageStore(), new InMemoryTransactionManager(), clock: $this->clock());
        $transport->send([new Message('billing.invoice_overdue.v1', ['entity_id' => '404'], 'App\\Invoice:404', 'acme', messageId: self::mid(1502)), $message]);
        $this->assertEquals(['retried', 'handled'], array_map(static fn(ConsumeEntry $e): string => $e->outcome->value, $consumer->consume(10)->entries()), 'a instância inexistente é tentada de novo; a outra é tratada');
    }

    // =====================================================================
    // D13: property sequences
    // =====================================================================

    /**
     * Random publications, relays and consumptions; returns the transcript.
     *
     * @param array<string, mixed> $env
     * @return list<mixed>
     */
    private function runSequence(array $env, int $seed, int $steps, string $label): array
    {
        $random = new Randomizer(new Mt19937($seed));
        $transport = (new InMemoryMessageTransport())->reorderAcrossKeys(new Randomizer(new Mt19937($seed + 1)));
        $faulty = true;
        $transport->withFaults(static function () use ($random, &$faulty): InMemoryTransportFault {
            if (!$faulty) {
                return InMemoryTransportFault::NONE;
            }

            return [InMemoryTransportFault::FAIL, InMemoryTransportFault::LOSE_CONFIRM, InMemoryTransportFault::DUPLICATE, InMemoryTransportFault::NONE, InMemoryTransportFault::NONE, InMemoryTransportFault::NONE, InMemoryTransportFault::NONE][$random->getInt(0, 6)];
        });
        $outbox = new Module48CrashingOutbox($env['outbox']);
        $outbox->crashOnDelivered = static function () use ($random, &$faulty): bool {
            return $faulty && $random->getInt(0, 19) === 0;
        };
        $inner = new InMemoryMessageSource($transport);
        $source = new Module48CrashingSource($inner);
        $source->crashOnAck = static function () use ($random, &$faulty): bool {
            return $faulty && $random->getInt(0, 14) === 0;
        };
        $effects = $env['effect'];
        $handlers = (new MessageHandlerRegistry())->register('prop.fact.v1', static function (Message $m) use ($effects, $random, &$faulty): void {
            $effects('prop|' . $m->messageId . '|' . ($m->orderingKey ?? '-'));
            if (($m->payload['poison'] ?? false) === true) {
                throw new RuntimeException('poisoned ' . json_encode($m->payload));
            }
            if (($m->payload['refuse'] ?? false) === true) {
                throw new InvalidMessagePayloadException('refused ' . json_encode($m->payload));
            }
            if ($faulty && $random->getInt(0, 9) === 0) {
                throw new RuntimeException('flaky ' . json_encode($m->payload));
            }
        });
        $publisher = new MessagePublisher($outbox, null, $this->clock());
        $keys = ['k0', 'k1', 'k2', 'k3', null];
        $published = [];
        $poisoned = [];
        $unwanted = [];
        $confirmed = [];
        $dead = [];
        $transcript = [];
        $n = $seed % 1000 * 100000;

        $relay = function () use ($outbox, $transport, $random, &$confirmed, &$transcript): void {
            try {
                $report = (new OutboxRelay($outbox, $transport, null, $random->getInt(1, 5), $this->clock()))->relayPending($random->getInt(1, 8));
                $confirmed = array_merge($confirmed, $report->deliveredIds());
                foreach ($report->getFailures() as $reason) {
                    $this->seen($reason);
                }
                $transcript[] = ['relay', $report->toArray()];
            } catch (Module48Crash) {
                $transcript[] = ['relay', 'crash'];
            }
        };
        $deadInCrashedRuns = 0;
        $consume = function (bool $faultyRun) use ($source, $inner, $handlers, $env, $random, &$dead, &$transcript, &$deadInCrashedRuns): void {
            $consumer = new MessageConsumer('prop', $source, $handlers, $env['inbox'], $env['tx'], 3, null, $this->clock());
            $answered = count($source->answers);
            try {
                $report = $consumer->consume($faultyRun ? $random->getInt(1, 6) : 50);
                foreach ($report->of(ConsumeOutcome::DEAD_LETTERED) as $entry) {
                    $dead[] = (string) $entry->messageId;
                }
                foreach ($report->entries() as $entry) {
                    $this->seen((string) $entry->reason);
                }
                $transcript[] = ['consume', $report->toArray()];
            } catch (Module48Crash) {
                $deadInCrashedRuns += count(array_filter(array_slice($source->answers, $answered), static fn(string $a): bool => str_starts_with($a, 'dead ')));
                $inner->recoverInFlight();
                $transcript[] = ['consume', 'crash'];
            }
        };

        for ($step = 0; $step < $steps; $step++) {
            $action = $random->getInt(0, 9);
            if ($action <= 3) {
                $chunk = [];
                for ($i = 0, $size = $random->getInt(1, 4); $i < $size; $i++) {
                    $kind = $random->getInt(0, 39);
                    $chunk[] = new Message(
                        $kind === 0 ? 'prop.other.v1' : 'prop.fact.v1',
                        ['n' => $n, 'secret' => self::SECRET, 'poison' => $kind === 1, 'refuse' => $kind === 2],
                        $keys[$random->getInt(0, 4)],
                        null,
                        [],
                        self::mid($n++),
                        $this->at('2026-11-15 10:00:00')
                    );
                }
                $rollback = $random->getInt(0, 4) === 0;
                try {
                    $env['tx']->transactional(static function () use ($publisher, $chunk, $rollback): void {
                        $publisher->publish(...$chunk);
                        if ($rollback) {
                            throw new Module48Crash('the write was rolled back');
                        }
                    });
                } catch (Module48Crash) {
                }
                $transcript[] = ['publish', count($chunk), $rollback];
                if (!$rollback) {
                    foreach ($chunk as $message) {
                        $published[$message->messageId] = $message->orderingKey;
                        if ($message->name === 'prop.other.v1') {
                            $unwanted[$message->messageId] = true;
                        } elseif ($message->payload['poison'] || $message->payload['refuse']) {
                            $poisoned[$message->messageId] = true;
                        }
                    }
                }
            } elseif ($action <= 6) {
                $relay();
            } else {
                $consume(true);
            }
        }

        // Drain: the faults stop, every confirmed message reaches the consumer
        $faulty = false;
        for ($i = 0; $i < 500 && $outbox->fetchPending(1) !== []; $i++) {
            $relay();
        }
        $this->assertEquals([], $outbox->fetchPending(1), "{$label} semente {$seed}: a outbox esvaziou");
        for ($i = 0; $i < 500 && ($inner->pendingCount() > 0 || $inner->inFlightCount() > 0); $i++) {
            $consume(false);
        }
        $this->assertEquals([0, 0], [$inner->pendingCount(), $inner->inFlightCount()], "{$label} semente {$seed}: a fonte esvaziou");

        // RN-02/RN-03: what was committed is delivered, what was rolled back never left
        $stored = self::idsOf($transport->stored(), false);
        $storedSet = array_flip($stored);
        foreach ($published as $id => $_) {
            $this->assertTrue($outbox->find($id)?->isDelivered() ?? false, "{$label} semente {$seed}: {$id} entregue na outbox");
            $this->assertTrue(isset($storedSet[$id]), "{$label} semente {$seed}: {$id} no broker");
        }
        $this->assertEquals(count($published), $outbox->count(), "{$label} semente {$seed}: só as confirmadas pela transação estão na outbox");
        $this->assertEquals([], array_values(array_diff(array_keys($storedSet), array_map('strval', array_keys($published)))), "{$label} semente {$seed}: nada desfeito chegou ao broker");
        $this->assertEquals([], array_values(array_diff($confirmed, $stored)), "{$label} semente {$seed}: nenhuma mensagem confirmada se perdeu");

        // RN-03: each effect once per consumer; RN-04: the order of each key, the dead letters aside
        $lines = array_values(array_filter(($env['effects'])(), static fn(string $l): bool => str_starts_with($l, 'prop|')));
        $effects = array_map(static fn(string $l): string => explode('|', $l)[1], $lines);
        $fromSource = array_map(static fn(array $d): string => (string) $d['messageId'], $inner->deadLetters());
        $deadSet = array_flip($fromSource);
        $handled = array_values(array_filter($effects, static fn(string $id): bool => !isset($poisoned[$id])));
        $this->assertEquals(count($handled), count(array_unique($handled)), "{$label} semente {$seed}: nenhum efeito duas vezes");
        foreach ($published as $id => $_) {
            if (isset($poisoned[$id]) || isset($unwanted[$id])) {
                continue;
            }
            $this->assertTrue(in_array($id, $handled, true) || isset($deadSet[$id]), "{$label} semente {$seed}: {$id} tratada (ou na fila morta, declarada)");
        }
        foreach ($poisoned as $id => $_) {
            $this->assertTrue(isset($deadSet[$id]), "{$label} semente {$seed}: a envenenada {$id} foi à fila morta");
        }
        $this->assertEquals(count(array_unique($handled)), ($env['inbox'])->count('prop'), "{$label} semente {$seed}: uma linha de inbox por efeito");
        $order = array_flip(array_map('strval', array_keys($published)));
        foreach (['k0', 'k1', 'k2', 'k3'] as $key) {
            $ofKey = array_values(array_filter($handled, static fn(string $id): bool => ($published[$id] ?? null) === $key && !isset($deadSet[$id])));
            $positions = array_map(static fn(string $id): int => $order[$id], $ofKey);
            $sorted = $positions;
            sort($sorted);
            $this->assertEquals($sorted, $positions, "{$label} semente {$seed}: a chave {$key} na ordem da publicação");
        }
        $this->assertEquals(count($fromSource), count($dead) + $deadInCrashedRuns, "{$label} semente {$seed}: a fila morta da fonte = a relatada (mais as das rodadas que morreram)");
        $this->assertEquals([], array_values(array_diff($dead, $fromSource)), "{$label} semente {$seed}: toda morta relatada está na fila morta");
        foreach ($inner->deadLetters() as $letter) {
            $this->seen($letter['reason']);
        }
        $this->assertTrue(count($published) > $steps / 4 && count($dead) > 0 && count(array_unique($handled)) > $steps / 5, "{$label} semente {$seed}: a sequência exercitou o caminho (" . count($published) . ' publicadas, ' . count($dead) . ' mortas)');
        $transcript[] = ['effects', $effects];
        $transcript[] = ['dead', $dead];

        return $transcript;
    }

    // =====================================================================
    // Databases
    // =====================================================================

    /**
     * @param Closure(): PDO $connect
     * @param array<int, list<mixed>> $memory Transcripts of memory by seed
     */
    private function runDatabase(string $label, Closure $connect, array $memory, int $steps): void
    {
        $this->runBatteries($label, function () use ($connect): array {
            return $this->pdoEnvironment($connect());
        });
        $this->testPdoDetails($connect(), $label);
        foreach (self::SEEDS as $seed) {
            $transcript = $this->runSequence($this->pdoEnvironment($connect()), $seed, $steps, $label);
            $this->assertTrue($transcript === $memory[$seed], "{$label} semente {$seed}: a transcrição é a da memória");
        }
    }

    private function testPdoDetails(PDO $pdo, string $label): void
    {
        self::resetSchema($pdo);
        $family = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        // Names and the column map
        foreach ([
            fn() => new PdoMessageOutbox($pdo, 'bad name'),
            fn() => new PdoMessageOutbox($pdo, 'message_outbox', 'x;y'),
            fn() => new PdoMessageOutbox($pdo, 'message_outbox', '', ['color' => 'c']),
            fn() => new PdoMessageOutbox($pdo, 'message_outbox', '', ['payload' => 'pay load']),
            fn() => new PdoMessageOutbox($pdo, 'message_outbox', '', ['payload' => 5]),
            fn() => new PdoMessageOutbox($pdo, ''),
            fn() => new PdoProcessedMessageStore($pdo, '1inbox'),
            fn() => new PdoProcessedMessageStore($pdo, 'message_inbox', '', [0 => 'x']),
        ] as $invalid) {
            $e = $this->assertThrows(InvalidArgumentException::class, $invalid);
            $this->seen($e->getMessage());
        }
        foreach ([
            'Unknown message outbox column "color". Expected one of: entry_id, ' => fn() => new PdoMessageOutbox($pdo, 'message_outbox', '', ['color' => 'c']),
            'Message outbox column "pay load" (canonical payload) is not a plain SQL identifier.' => fn() => new PdoMessageOutbox($pdo, 'message_outbox', '', ['payload' => 'pay load']),
            'Message outbox column "int" (canonical payload) is not a plain SQL identifier.' => fn() => new PdoMessageOutbox($pdo, 'message_outbox', '', ['payload' => 5]),
            'Unknown message inbox column "0".' => fn() => new PdoProcessedMessageStore($pdo, 'message_inbox', '', [0 => 'x']),
            'Message outbox schema "x;y" is not a plain SQL identifier.' => fn() => new PdoMessageOutbox($pdo, 'message_outbox', 'x;y'),
        ] as $text => $invalid) {
            $e = $this->assertThrows(InvalidArgumentException::class, $invalid);
            $this->assertTrue(str_starts_with($e->getMessage(), $text), "{$label}: " . $e->getMessage());
        }
        $outbox = new PdoMessageOutbox($pdo);
        $this->assertEquals(['message_outbox', array_combine(PdoMessageOutbox::COLUMNS, PdoMessageOutbox::COLUMNS)], [$outbox->getTable(), $outbox->getColumns()]);
        $this->assertTrue($outbox->getPdo() === $pdo);
        $inbox = new PdoProcessedMessageStore($pdo);
        $this->assertEquals('message_inbox', $inbox->getTable());
        $this->assertTrue($inbox->getPdo() === $pdo);
        $schema = (string) $pdo->query(match ($family) {
            'pgsql' => 'SELECT current_schema()',
            'mysql' => 'SELECT DATABASE()',
            default => "SELECT 'main'",
        })->fetchColumn();
        $qualified = new PdoMessageOutbox($pdo, 'message_outbox', $schema);
        $this->assertEquals($schema . '.message_outbox', $qualified->getTable());
        (new MessagePublisher($qualified))->publish($this->msg(1600, 'k'));
        $this->assertEquals(1, (new PdoProcessedMessageStore($pdo, 'message_inbox', $schema))->count() + $qualified->count(), "{$label}: a tabela qualificada pelo schema");

        $mapped = 'm48_mapped_outbox';
        $ddl = (string) file_get_contents(self::DDL);
        preg_match('/CREATE TABLE IF NOT EXISTS message_outbox \(.*?\n\);/s', $ddl, $create);
        $statement = str_replace(['message_outbox', 'uq_mo_', 'chk_mo_'], [$mapped, 'uq_m48m_', 'chk_m48m_'], $create[0]);
        foreach (PdoMessageOutbox::COLUMNS as $column) {
            $statement = (string) preg_replace('/\b' . $column . '\b/', 'x_' . $column, $statement);
        }
        $pdo->exec($statement);
        $columns = array_combine(PdoMessageOutbox::COLUMNS, array_map(static fn(string $c): string => 'x_' . $c, PdoMessageOutbox::COLUMNS));
        $custom = new PdoMessageOutbox($pdo, $mapped, '', $columns);
        $this->assertEquals($columns, $custom->getColumns());
        $publisher = new MessagePublisher($custom, null, $this->clock());
        $publisher->publish($this->msg(1601, 'k'), $this->msg(1602, 'k'), $this->msg(1603, null));
        $this->assertEquals(self::ids($this->msg(1601, 'k'), $this->msg(1603, null)), self::entryIds($custom->fetchPending(10, 3)), "{$label}: o mapa de colunas nas cabeças");
        $relay = (new OutboxRelay($custom, new InMemoryMessageTransport(), 5, 10, $this->clock()))->relayPending(100);
        $this->assertEquals(3, $relay->deliveredCount(), "{$label}: o relay sobre a tabela mapeada");
        $this->assertEquals(self::ids($this->msg(1601, 'k'), $this->msg(1602, 'k')), self::entryIds($custom->findByOrderingKey('k')));
        $this->assertEquals([3, true], [$custom->count(), $custom->find(self::mid(1602))?->isDelivered()]);
        $inboxColumns = array_combine(PdoProcessedMessageStore::COLUMNS, array_map(static fn(string $c): string => 'y_' . $c, PdoProcessedMessageStore::COLUMNS));
        preg_match('/CREATE TABLE IF NOT EXISTS message_inbox \(.*?\n\);/s', $ddl, $createInbox);
        $statement = str_replace(['message_inbox', 'uq_mi_'], ['m48_mapped_inbox', 'uq_m48i_'], $createInbox[0]);
        foreach (PdoProcessedMessageStore::COLUMNS as $column) {
            $statement = (string) preg_replace('/\b' . $column . '\b/', 'y_' . $column, $statement);
        }
        $pdo->exec($statement);
        $customInbox = new PdoProcessedMessageStore($pdo, 'm48_mapped_inbox', '', $inboxColumns);
        $this->assertTrue($customInbox->markProcessed('agents', $this->msg(1601, 'k'), $this->at('2026-11-15 10:00:00')));
        $this->assertEquals([true, false, 1, 1, 'agents'], [
            $customInbox->has('agents', 'msg:' . self::mid(1601)), $customInbox->markProcessed('agents', $this->msg(1601, 'k'), $this->at('2026-11-15 10:00:00')),
            $customInbox->count(), $customInbox->count('agents'), $customInbox->find('agents', 'msg:' . self::mid(1601))?->consumer,
        ], "{$label}: o mapa de colunas da inbox");
        $this->assertEquals(1, $customInbox->purgeProcessedBefore('agents', $this->at('2026-11-16 00:00:00')));

        // Failures: the SQLSTATE and the driver code, never the driver message nor the payload (RN-05)
        $missing = new PdoMessageOutbox($pdo, 'm48_missing');
        $secret = new Message('secret.refused.v1', ['secret' => self::SECRET], self::SECRET, messageId: self::mid(1604));
        foreach ([
            fn() => $missing->count(),
            fn() => (new MessagePublisher($missing))->publish($secret),
            fn() => $missing->fetchPending(10),
            fn() => (new PdoProcessedMessageStore($pdo, 'm48_missing'))->markProcessed('agents', $secret, $this->at('2026-11-15 10:00:00')),
            fn() => (new PdoProcessedMessageStore($pdo, 'm48_missing'))->purgeProcessedBefore('agents', $this->at('2026-11-15 10:00:00')),
            fn() => (new PdoProcessedMessageStore($pdo, 'm48_missing'))->count('agents'),
        ] as $failing) {
            $e = $this->assertThrows(MessagingPersistenceException::class, $failing);
            $this->seen($e->getMessage());
            $this->assertTrue($e->getSqlState() !== null && $e->getPrevious() === null, "{$label}: o SQLSTATE, sem a exceção do driver encadeada");
            $this->assertTrue(str_contains($e->getMessage(), 'm48_missing') && str_contains($e->getMessage(), 'The driver message is not repeated'), "{$label}: a tabela nomeada: " . $e->getMessage());
        }
        $guarded = 'm48_guarded_outbox';
        $statement = str_replace(['message_outbox', 'uq_mo_', 'chk_mo_status', 'chk_mo_attempts'], [$guarded, 'uq_m48g_', 'chk_m48g_status', 'chk_m48g_attempts'], $create[0]);
        $statement = str_replace('CONSTRAINT chk_m48g_attempts', "CONSTRAINT chk_m48g_guard CHECK (message_name <> 'secret.refused.v1'),\n    CONSTRAINT chk_m48g_attempts", $statement);
        $pdo->exec($statement);
        $e = $this->assertThrows(MessagingPersistenceException::class, fn() => (new MessagePublisher(new PdoMessageOutbox($pdo, $guarded)))->publish($secret));
        $this->seen($e->getMessage());
        $this->assertTrue(str_contains($e->getMessage(), 'enqueue on m48_guarded_outbox for message id ' . self::mid(1604)) && $e->getSqlState() !== null, "{$label}: a recusa do CHECK nomeia a operação e o id: " . $e->getMessage());
        $entry = new MessageOutboxEntry((string) $outbox->find(self::mid(1600))?->entryId, $this->msg(1605, 'k'), $this->at('2026-11-15 10:00:00'));
        $e = $this->assertThrows(MessagingPersistenceException::class, fn() => $outbox->enqueue([$entry]));
        $this->assertTrue($e->isIntegrityViolation(), "{$label}: a chave primária repetida é violação de integridade ({$e->getSqlState()})");

        // A tampered row is refused when read
        foreach ([
            "payload = 'not json'" => 'not valid JSON',
            "payload = '\"text\"'" => 'has a payload that is not a JSON array or object',
            "headers = '[1'" => 'not valid JSON',
            "occurred_at = 'yesterday'" => 'not YYYY-MM-DD HH:MM:SS.ffffff',
            "enqueued_at = '2026-11-15'" => 'not YYYY-MM-DD HH:MM:SS.ffffff',
            "message_name = 'Bad'" => 'is not a valid message',
            "message_id = 'nope'" => 'is not a valid message',
            "headers = '{\"a\":{\"b\":\"c\"}}'" => 'not valid JSON',
            "headers = '{\"a\":5}'" => 'is not a valid message',
            'payload = ' . $pdo->quote(str_repeat('[', 65) . str_repeat(']', 65)) => 'not valid JSON',
        ] as $set => $expected) {
            self::resetSchema($pdo);
            (new MessagePublisher($outbox))->publish(new Message('a.b.v1', ['secret' => self::SECRET], 'k', messageId: self::mid(1606)));
            $pdo->exec("UPDATE message_outbox SET {$set}");
            $e = $this->assertThrows(MessagingPersistenceException::class, fn() => $outbox->fetchPending(10));
            $this->seen($e->getMessage());
            $this->assertTrue(str_contains($e->getMessage(), $expected) && str_contains($e->getMessage(), 'Stored message outbox entry'), "{$label}: {$set}: " . $e->getMessage());
        }
        self::resetSchema($pdo);
        $inbox->markProcessed('agents', $this->msg(1607, null), $this->at('2026-11-15 10:00:00'));
        $pdo->exec("UPDATE message_inbox SET processed_at = 'later'");
        $e = $this->assertThrows(MessagingPersistenceException::class, fn() => $inbox->find('agents', 'msg:' . self::mid(1607)));
        $this->assertTrue(str_contains($e->getMessage(), 'processed_at that is not'), "{$label}: a inbox adulterada");
        $pdo->exec("UPDATE message_inbox SET processed_at = '2026-11-15 10:00:00.000000', consumer = 'Bad Name'");
        $e = $this->assertThrows(MessagingPersistenceException::class, fn() => $inbox->find('Bad Name', 'msg:' . self::mid(1607)));
        $this->assertTrue(str_contains($e->getMessage(), 'Stored inbox row is invalid'), "{$label}: o consumidor adulterado");
        $pdo->exec("UPDATE message_inbox SET consumer = 'agents'");
        $this->assertEquals('agents', $inbox->find('agents', 'msg:' . self::mid(1607))?->consumer);

        // A payload of 64 levels round-trips; column names read back in upper case (Oracle, PDO::CASE_UPPER) are folded
        $deep = [];
        for ($i = 0; $i < 63; $i++) {
            $deep = [$deep];
        }
        (new MessagePublisher($outbox))->publish(new Message('a.b.v1', $deep, 'deep', messageId: self::mid(1608)));
        $this->assertEquals($deep, $outbox->find(self::mid(1608))?->message->payload, "{$label}: 64 níveis gravados e lidos");
        $pdo->setAttribute(PDO::ATTR_CASE, PDO::CASE_UPPER);
        try {
            $this->assertEquals([self::mid(1608), 'agents'], [$outbox->find(self::mid(1608))?->messageId(), $inbox->find('agents', 'msg:' . self::mid(1607))?->consumer], "{$label}: colunas em maiúsculas");
            $this->assertEquals(1, count($outbox->fetchPending(10)), "{$label}: as cabeças também");
        } finally {
            $pdo->setAttribute(PDO::ATTR_CASE, PDO::CASE_NATURAL);
        }

        // A consumer table whose instants may be NULL: an entry without them is refused when read
        $lax = str_replace(['message_outbox', 'uq_mo_', 'chk_mo_', 'occurred_at              VARCHAR(26)    NOT NULL', 'enqueued_at              VARCHAR(26)    NOT NULL'], ['m48_lax_outbox', 'uq_m48l_', 'chk_m48l_', 'occurred_at              VARCHAR(26)', 'enqueued_at              VARCHAR(26)'], $create[0]);
        $this->assertFalse(str_contains($lax, 'occurred_at              VARCHAR(26)    NOT NULL'), 'a variante sem NOT NULL');
        $pdo->exec($lax);
        $laxOutbox = new PdoMessageOutbox($pdo, 'm48_lax_outbox');
        foreach (['occurred_at', 'enqueued_at'] as $column) {
            $pdo->exec('DELETE FROM m48_lax_outbox');
            (new MessagePublisher($laxOutbox))->publish($this->msg(1609, 'k'));
            $pdo->exec("UPDATE m48_lax_outbox SET {$column} = NULL");
            $e = $this->assertThrows(MessagingPersistenceException::class, fn() => $laxOutbox->find(self::mid(1609)));
            $this->assertTrue(str_ends_with($e->getMessage(), "has no {$column}."), "{$label}: {$column} NULL: " . $e->getMessage());
        }
        $pdo->exec('DROP TABLE IF EXISTS m48_lax_outbox');
        self::dropSchema($pdo);
        foreach ([$mapped, 'm48_mapped_inbox', $guarded] as $table) {
            $pdo->exec('DROP TABLE IF EXISTS ' . $table);
        }
    }

    /** RN-05: a driver that fails in silence or throws: the SQLSTATE and the code, never its message. */
    private function testDriverFailuresWithoutTheDriverMessage(): void
    {
        $cases = [
            [['HY000', 7, 'driver says ' . self::SECRET], 'SQLSTATE HY000, driver code 7', 'HY000', '7'],
            [['HY000'], 'SQLSTATE HY000, driver code unknown', 'HY000', null],
            [['HY000', 7, null], 'SQLSTATE HY000, driver code 7', 'HY000', '7'],
            [[], 'SQLSTATE unknown, driver code unknown', null, null],
            [['', 5, self::SECRET], 'SQLSTATE unknown, driver code 5', null, '5'],
            [[42, 7], 'SQLSTATE 42, driver code 7', '42', '7'],
        ];
        foreach ($cases as [$info, $text, $state, $code]) {
            $pdo = new Module48FailingPdo();
            $outbox = new PdoMessageOutbox($pdo);
            $pdo->info = $info;
            $e = $this->assertThrows(MessagingPersistenceException::class, fn() => $outbox->count());
            $this->seen($e->getMessage());
            $this->assertEquals('Message outbox statement failed: count on message_outbox (' . $text . '). The driver message is not repeated: it may quote a stored row.', $e->getMessage(), 'prepare() falso: ' . json_encode($info));
            $this->assertTrue($e->getSqlState() === $state && $e->getDriverCode() === $code && $e->getPrevious() === null, 'o SQLSTATE e o código à parte: ' . json_encode($info));
        }
        $thrown = [
            ['HY001', 'SQLSTATE HY001, driver code unknown', 'HY001'],
            [0, 'SQLSTATE unknown, driver code unknown', null],
        ];
        foreach ($thrown as [$code, $text, $state]) {
            $pdo = new Module48FailingPdo();
            $inbox = new PdoProcessedMessageStore($pdo);
            $exception = new PDOException('driver says ' . self::SECRET);
            (new \ReflectionProperty(\Exception::class, 'code'))->setValue($exception, $code); // a driver code as text, as PDO gives one
            $pdo->throw = $exception;
            $e = $this->assertThrows(MessagingPersistenceException::class, fn() => $inbox->markProcessed('agents', $this->msg(1700, null), $this->at('2026-11-15 10:00:00')));
            $this->seen($e->getMessage());
            $this->assertEquals('Message inbox statement failed: find on message_inbox (' . $text . '). The driver message is not repeated: it may quote a stored row.', $e->getMessage(), 'PDOException sem errorInfo: ' . json_encode($code));
            $this->assertTrue($e->getSqlState() === $state && $e->getPrevious() === null);
        }
        $pdo = new Module48FailingPdo();
        self::resetSchema($pdo);
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [Module48FailingStatement::class, []]);
        Module48FailingStatement::$info = ['23000', 19, 'UNIQUE constraint failed: ' . self::SECRET];
        $e = $this->assertThrows(MessagingPersistenceException::class, fn() => (new MessagePublisher(new PdoMessageOutbox($pdo)))->publish(new Message('a.b.v1', ['secret' => self::SECRET], messageId: self::mid(1701))));
        $this->seen($e->getMessage());
        $this->assertEquals('Message outbox statement failed: find on message_outbox for message id ' . self::mid(1701) . ' (SQLSTATE 23000, driver code 19). The driver message is not repeated: it may quote a stored row.', $e->getMessage(), 'execute() falso');
        $this->assertTrue($e->isIntegrityViolation());
    }

    /** A consumer table of SQLite whose text columns are INTEGER: what comes back as a number is read as text. */
    private function testConsumerTablesWithOtherColumnTypes(): void
    {
        $pdo = self::sqliteEmpty();
        $ddl = (string) file_get_contents(self::DDL);
        foreach (['ordering_key             VARCHAR(190)' => 'ordering_key             INTEGER', 'tenant                   VARCHAR(120)' => 'tenant                   INTEGER', 'last_error               VARCHAR(2000)' => 'last_error               INTEGER'] as $from => $to) {
            $ddl = str_replace($from, $to, $ddl);
        }
        $lines = array_filter(explode("\n", $ddl), static fn(string $line): bool => !str_starts_with(ltrim($line), '--'));
        foreach (explode(';', implode("\n", $lines)) as $statement) {
            if (trim($statement) !== '') {
                $pdo->exec($statement);
            }
        }
        $this->assertEquals('INTEGER', (string) $pdo->query("SELECT type FROM pragma_table_info('message_inbox') WHERE name = 'tenant'")->fetchColumn(), 'a coluna tenant da inbox é INTEGER');
        $outbox = new PdoMessageOutbox($pdo);
        (new MessagePublisher($outbox))->publish(new Message('a.b.v1', [], '123', '7', messageId: self::mid(1800)));
        $outbox->markFailed($outbox->find(self::mid(1800)), '42', $this->at('2026-11-15 10:00:00'));
        $this->assertEquals('integer', (string) $pdo->query('SELECT typeof(ordering_key) FROM message_outbox')->fetchColumn(), 'gravada como número');
        $entry = $outbox->find(self::mid(1800));
        $this->assertTrue($entry?->message->orderingKey === '123' && $entry->message->tenant === '7' && $entry->lastError === '42', 'lidas como texto');
        $inbox = new PdoProcessedMessageStore($pdo);
        $inbox->markProcessed('agents', new Message('a.b.v1', tenant: '9', messageId: self::mid(1801)), $this->at('2026-11-15 10:00:00'));
        $this->assertTrue($inbox->find('agents', 'msg:' . self::mid(1801))?->tenant === '9', 'o tenant da inbox lido como texto');
    }

    // =====================================================================
    // D14: the examples of docs/<language>/messaging/index.md
    // =====================================================================

    private function testExamplesAreTheSameInBothLanguages(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['26', '26.1', '26.2', '26.3'] as $number) {
            $english = ReadmeBlocks::of($root . '/docs/en/messaging/index.md', $number);
            $portuguese = ReadmeBlocks::of($root . '/docs/pt-BR/messaging/index.md', $number);
            $this->assertCount(1, $english, "um bloco PHP no exemplo {$number} (EN)");
            $this->assertCount(1, $portuguese, "um bloco PHP no exemplo {$number} (pt-BR)");
            $this->assertEquals(ReadmeBlocks::codeTokens($english[0]), ReadmeBlocks::codeTokens($portuguese[0]), "exemplo {$number}: bloco igual fora os comentários");
        }
    }

    private function testExamplesRunAsWritten(): void
    {
        $pdo = self::sqliteEmpty();
        self::resetSchema($pdo);
        $pdo->exec("CREATE TABLE quote (id INTEGER NOT NULL, status VARCHAR(20) NOT NULL, CONSTRAINT pk_quote PRIMARY KEY (id))");
        $pdo->exec("INSERT INTO quote (id, status) VALUES (123, 'draft')");
        $pdo->exec('CREATE TABLE agent_task (quote_id INTEGER NOT NULL, kind VARCHAR(40) NOT NULL)');

        // DOC 26 >>>
        $transactions = new PdoTransactionManager($pdo);
        $publisher = new MessagePublisher(new PdoMessageOutbox($pdo));

        $transactions->transactional(function () use ($pdo, $publisher): void {
            $pdo->exec("UPDATE quote SET status = 'submitted' WHERE id = 123");
            $publisher->publish(new Message(
                'underwriting.quote_submitted.v1',
                ['quote_id' => 123, 'premium' => 1250.0],
                orderingKey: 'quote:123',
                tenant: 'acme'
            ));
        });

        $transport = new InMemoryMessageTransport();
        $relay = new OutboxRelay(new PdoMessageOutbox($pdo), $transport, maxAttempts: 10);

        $sent = $relay->relayPending(100)->deliveredCount();
        $again = $relay->relayPending(100)->deliveredCount();
        // <<< DOC 26
        $this->assertTranscribed('DOC 26', '26', 6);
        $this->assertEquals([1, 0, 'submitted'], [$sent, $again, (string) $pdo->query('SELECT status FROM quote WHERE id = 123')->fetchColumn()], 'exemplo 26');

        // DOC 26.1 >>>
        $handlers = (new MessageHandlerRegistry())->register(
            'underwriting.quote_submitted.v1',
            function (Message $message) use ($pdo): void {
                $insert = $pdo->prepare('INSERT INTO agent_task (quote_id, kind) VALUES (?, ?)');
                $insert->execute([$message->payload['quote_id'], 'review_quote']);
            }
        );
        $consumer = new MessageConsumer(
            'agents',
            new InMemoryMessageSource($transport),
            $handlers,
            new PdoProcessedMessageStore($pdo),
            $transactions,
            maxDeliveries: 5,
            tenant: 'acme'
        );

        $report = $consumer->consume(100, waitMs: 1000);
        $handled = count($report->handledIds());
        $tasks = (int) $pdo->query('SELECT COUNT(*) FROM agent_task')->fetchColumn();

        $transport->send([(new PdoMessageOutbox($pdo))->findByOrderingKey('quote:123')[0]->message]);
        $duplicate = $consumer->consume(100)->entries()[0]->outcome;
        // <<< DOC 26.1
        $this->assertTranscribed('DOC 26.1', '26.1', 4);
        $this->assertEquals([1, 1, ConsumeOutcome::DUPLICATE], [$handled, $tasks, $duplicate], 'exemplo 26.1');
        $this->assertEquals(1, (int) $pdo->query('SELECT COUNT(*) FROM agent_task')->fetchColumn(), 'exemplo 26.1: a cópia não rodou o handler');

        // DOC 26.2 >>>
        $handlers->register('crm.lead_created.v1', function (Message $message): void {
            if (!isset($message->payload['lead_id'])) {
                throw new InvalidMessagePayloadException('lead_id is required');
            }
        });
        $transactions->transactional(fn(): int => $publisher->publish(
            new Message('crm.lead_created.v1', ['name' => 'Ana'], orderingKey: 'lead:9', tenant: 'acme'),
            new Message('crm.lead_created.v1', ['lead_id' => 9, 'name' => 'Ana'], orderingKey: 'lead:9', tenant: 'acme'),
        ));
        $relay->relayPending(100);

        $report = $consumer->consume(100);
        $outcomes = array_map(fn(ConsumeEntry $entry): string => $entry->outcome->value, $report->entries());
        $reason = $report->entries()[0]->reason;
        // <<< DOC 26.2
        $this->assertTranscribed('DOC 26.2', '26.2', 2);
        $this->assertEquals(['dead_lettered', 'handled'], $outcomes, 'exemplo 26.2');
        $this->assertTrue(preg_match('/^A handler of consumer "agents" failed for message "crm\.lead_created\.v1" \(id [0-9A-Z]{26}, ordering key "lead:9"\) \(InvalidMessagePayloadException\) on delivery 1 of at most 5; the payload was refused\.$/', (string) $reason) === 1, 'exemplo 26.2: o motivo: ' . $reason);

        $persistence = MachinePersistence::inMemory();
        $runner = new MachineRunner(Spec::stateMachine(), new InMemoryMachineDefinitionRepository([Spec::machine('collection', 1)
            ->region('collection', 'idle')
                ->state('idle')
                    ->on('START_COLLECTION', 'collecting')
                ->finalState('collecting')
            ->build()]), $persistence);
        $runner->start('collection-77', 'collection', 1, null, new DateTimeImmutable('2026-11-15 08:00:00'), tenant: 'acme');
        $domainOutbox = new InMemoryDomainEventOutbox();
        (new OutboxDomainEventPublisher($domainOutbox))->publish([new DomainEvent('invoice.overdue', 'invoice.is_overdue', DomainEventKind::ENTER, 'App\\Invoice', '77', new DateTimeImmutable('2026-11-15 09:00:00'))]);

        // DOC 26.3 >>>
        $domainHandlers = (new DomainEventMessageBridge(
            $publisher,
            ['invoice.overdue' => 'billing.invoice_overdue.v1'],
            fn(DomainEvent $event): ?string => 'acme'
        ))->registerIn(new DomainEventHandlerRegistry());

        (new MessageMachineBridge(
            $runner,
            ['billing.invoice_overdue.v1' => 'START_COLLECTION'],
            fn(Message $message): ?string => 'collection-' . $message->payload['entity_id']
        ))->registerIn($handlers);

        $dispatched = (new DomainEventDispatcher($domainOutbox, $domainHandlers))->dispatchPending()->deliveredCount();
        $relay->relayPending(100);
        $consumer->consume(100);
        $state = $runner->load('collection-77')->snapshot->stateOf('collection');
        // <<< DOC 26.3
        $this->assertTranscribed('DOC 26.3', '26.3', 5);
        $this->assertEquals([1, 'collecting'], [$dispatched, $state], 'exemplo 26.3');
        $this->assertEquals('App\\Invoice:77', (new PdoMessageOutbox($pdo))->findByOrderingKey('App\\Invoice:77')[0]->message->orderingKey, 'exemplo 26.3: a chave de ordem da entidade');
    }

    /**
     * The code between the markers is the block of the example, token by token (its `use` lines are imports of this file).
     */
    private function assertTranscribed(string $name, string $number, int $imports): void
    {
        $source = (string) file_get_contents(__FILE__);
        $start = strpos($source, "// {$name} >>>\n");
        $end = strpos($source, "// <<< {$name}\n");
        $this->assertTrue($start !== false && $end !== false && $end > $start, "transcription {$name} marked");
        $transcribed = ReadmeBlocks::codeTokens(substr($source, (int) $start, (int) $end - (int) $start));
        $block = ReadmeBlocks::of(dirname(__DIR__, 2) . '/docs/en/messaging/index.md', $number)[0] ?? '';
        preg_match_all('/^use ([^;]+);$/m', $block, $uses);
        $this->assertCount($imports, $uses[1], "{$name}: imports");
        foreach ($uses[1] as $import) {
            $this->assertTrue(str_contains($source, "\nuse {$import};\n"), "{$name}: {$import} is imported by this file too");
        }
        $code = ReadmeBlocks::codeTokens((string) preg_replace('/^use [^;]+;$/m', '', $block));
        $this->assertTrue(count($code) > 30 && $transcribed === $code, sprintf('%s: the transcription is the code of the page (%d tokens transcribed, %d on the page)', $name, count($transcribed), count($code)));
    }

    // =====================================================================
    // The coverage guard and the secrecy
    // =====================================================================

    private function testCoverageGuard(bool $withPdo): void
    {
        $root = dirname(__DIR__, 2);
        $folder = $root . '/src/Messaging';
        $files = [];
        $testing = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $relative = substr($file->getPathname(), strlen($folder) + 1, -4);
                // 2.15.0: the contract battery (Testing/) is guarded by Module 49, which declares its files; no file escapes both.
                if (str_starts_with($relative, 'Testing/')) {
                    $testing[] = substr($relative, strlen('Testing/'));
                    continue;
                }
                $files[] = $relative;
            }
        }
        sort($files);
        sort($testing);
        $this->assertEquals(Module49_MessagingContractBatteryTest::TESTING_FILES, $testing, 'every file of src/Messaging/Testing/ is declared by Module 49');
        $declared = self::MESSAGING_FILES;
        sort($declared);
        $this->assertEquals($declared, $files, 'every file of src/Messaging/ is declared here (a new one must be tested and declared)');
        $source = (string) file_get_contents(__FILE__);
        foreach ($files as $file) {
            $class = 'Antevemus\\ASpecification\\Messaging\\' . str_replace('/', '\\', $file);
            $this->assertTrue(str_contains($source, "\nuse {$class};\n"), "{$class} is imported by this module");
            $pdoOnly = str_contains($file, 'Pdo');
            $this->assertTrue((!$withPdo && $pdoOnly) || class_exists($class, false) || interface_exists($class, false) || enum_exists($class, false), "{$class} was loaded by the battery");
            $code = (string) file_get_contents("{$folder}/{$file}.php");
            $this->assertTrue(str_contains($code, "declare(strict_types=1);") && str_contains($code, '@version    2.14.0') && str_contains($code, '@subpackage Messaging'), "{$file}: strict types, @version 2.14.0, @subpackage");
        }
        $infection = (string) file_get_contents($root . '/infection.json5');
        $this->assertTrue(str_contains($infection, '|Messaging/'), 'src/Messaging/ is in the mutation scope');
    }

    private function assertNoSecretSeen(): void
    {
        $this->assertTrue(count($this->seen) > 150, 'reasons and messages collected: ' . count($this->seen));
        foreach ($this->seen as $text) {
            $this->assertFalse(str_contains($text, self::SECRET), 'RN-05: the payload never reaches a reason, a report nor an exception: ' . $text);
        }
    }

    private function seen(string $text): void
    {
        $this->seen[] = $text;
    }

    private function expectInvalid(Closure $fn, string $message = ''): Throwable
    {
        $e = $this->assertThrows(InvalidMessageException::class, $fn, $message);
        $this->seen($e->getMessage());

        return $e;
    }

    // =====================================================================
    // Support
    // =====================================================================

    /** A ULID of the battery: deterministic, valid (Crockford digits only after the prefix). */
    private static function mid(int $n): string
    {
        return '01JD' . str_pad((string) $n, 22, '0', STR_PAD_LEFT);
    }

    private function msg(int $n, ?string $key, ?string $tenant = null): Message
    {
        return new Message('a.b.v1', ['n' => $n, 'secret' => self::SECRET], $key, $tenant, [], self::mid($n), $this->at('2026-11-15 10:00:00'));
    }

    private function at(string $text): DateTimeImmutable
    {
        return new DateTimeImmutable($text, new DateTimeZone('UTC'));
    }

    /** @return Closure(): DateTimeImmutable */
    private function clock(): Closure
    {
        return function (): DateTimeImmutable {
            return $this->at('2026-11-15 10:00:00');
        };
    }

    /** @return list<string> */
    private static function ids(Message ...$messages): array
    {
        return array_map(static fn(Message $m): string => $m->messageId, $messages);
    }

    /**
     * @param list<MessageOutboxEntry> $entries
     * @return list<string>
     */
    private static function entryIds(array $entries): array
    {
        return array_map(static fn(MessageOutboxEntry $e): string => $e->messageId(), $entries);
    }

    /**
     * @param list<Message> $messages
     * @return list<string>
     */
    private static function idsOf(array $messages, bool $sorted): array
    {
        $ids = array_map(static fn(Message $m): string => $m->messageId, $messages);
        if ($sorted) {
            sort($ids);
        }

        return $ids;
    }

    /**
     * @param list<Message> $messages
     * @return list<string>
     */
    private static function idsOfKey(array $messages, string $key): array
    {
        return array_values(array_map(static fn(Message $m): string => $m->messageId, array_filter($messages, static fn(Message $m): bool => $m->orderingKey === $key)));
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private static function linesWith(array $lines, string $needle): array
    {
        return array_values(array_filter($lines, static fn(string $l): bool => str_contains($l, $needle)));
    }

    private static function depth(mixed $value): int
    {
        if (!is_array($value)) {
            return 0;
        }
        $max = 0;
        foreach ($value as $item) {
            $max = max($max, self::depth($item));
        }

        return 1 + $max;
    }

    private static function randomPayload(Randomizer $random, int $depth): mixed
    {
        $kind = $random->getInt(0, $depth > 3 ? 5 : 7);

        return match ($kind) {
            0 => null,
            1 => $random->getInt(0, 1) === 1,
            2 => $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
            3 => $random->getInt(-1000000, 1000000) / $random->getInt(1, 1000),
            4 => ['', 'ç', '😀', 'a/b', 'x"y', "tab\tx", '中文', 'ç' . $random->getInt(0, 99)][$random->getInt(0, 7)],
            5 => (float) $random->getInt(-5, 5),
            6 => array_map(static fn(): mixed => self::randomPayload($random, $depth + 1), range(1, $random->getInt(0, 4)) ?: []),
            default => (static function () use ($random, $depth): array {
                $map = [];
                for ($i = 0, $n = $random->getInt(0, 4); $i < $n; $i++) {
                    $map[['a', 'b', 'ç', '01', '7', 'x y', ''][$random->getInt(0, 6)] . $i] = self::randomPayload($random, $depth + 1);
                }

                return $map;
            })(),
        };
    }

    /** An oracle of DomainEventMessageBridge::messageIdOf(), written apart (base conversion by division). */
    private static function oracleUlid(string $key, int $milliseconds): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $time = '';
        for ($i = 0, $t = $milliseconds; $i < 10; $i++, $t = intdiv($t, 32)) {
            $time = $alphabet[$t % 32] . $time;
        }
        $bytes = array_values(unpack('C*', substr(hash('sha256', $key, true), 0, 10)) ?: []);
        $random = '';
        for ($chunk = 0; $chunk < 16; $chunk++) {
            $value = 0;
            for ($bit = 0; $bit < 5; $bit++) {
                $position = $chunk * 5 + $bit;
                $value = ($value << 1) | (($bytes[intdiv($position, 8)] >> (7 - $position % 8)) & 1);
            }
            $random .= $alphabet[$value];
        }

        return $time . $random;
    }

    /** @return array<string, mixed> */
    private function memoryEnvironment(): array
    {
        $outbox = new InMemoryMessageOutbox();
        $inbox = new InMemoryProcessedMessageStore();
        $log = new Module48EffectLog();

        return [
            'outbox' => $outbox,
            'inbox' => $inbox,
            'tx' => new InMemoryTransactionManager([$outbox, $inbox, $log]),
            'effect' => static function (string $line) use ($log): void {
                $log->add($line);
            },
            'effects' => static fn(): array => $log->lines(),
        ];
    }

    /** @return array<string, mixed> */
    private function pdoEnvironment(PDO $pdo): array
    {
        self::resetSchema($pdo);

        return [
            'outbox' => new PdoMessageOutbox($pdo),
            'inbox' => new PdoProcessedMessageStore($pdo),
            'tx' => new PdoTransactionManager($pdo),
            'effect' => function (string $line) use ($pdo): void {
                $insert = $pdo->prepare('INSERT INTO m48_effect (seq, line) VALUES (?, ?)');
                $insert->execute([++$this->effectSeq, $line]);
            },
            'effects' => static fn(): array => array_map('strval', $pdo->query('SELECT line FROM m48_effect ORDER BY seq')->fetchAll(PDO::FETCH_COLUMN)),
        ];
    }

    private static function resetSchema(PDO $pdo): void
    {
        self::dropSchema($pdo);
        $lines = array_filter(explode("\n", (string) file_get_contents(self::DDL)), static fn(string $line): bool => !str_starts_with(ltrim($line), '--'));
        foreach (explode(';', implode("\n", $lines)) as $statement) {
            if (trim($statement) !== '') {
                $pdo->exec($statement);
            }
        }
        $pdo->exec('CREATE TABLE m48_effect (seq INTEGER NOT NULL, line VARCHAR(400) NOT NULL, CONSTRAINT pk_m48_effect PRIMARY KEY (seq))');
    }

    private static function dropSchema(PDO $pdo): void
    {
        foreach (['message_outbox', 'message_inbox', 'm48_effect', 'quote', 'agent_task'] as $table) {
            $pdo->exec('DROP TABLE IF EXISTS ' . $table);
        }
    }

    private static function sqliteEmpty(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    private static function connectFromEnvironment(string $family): ?PDO
    {
        $prefix = 'ASPEC_PDO_' . strtoupper($family);
        $dsn = getenv($prefix . '_DSN');
        if ($dsn === false || $dsn === '') {
            return null;
        }
        $user = getenv($prefix . '_USER');
        $password = getenv($prefix . '_PASSWORD');

        return new PDO($dsn, $user === false ? null : $user, $password === false ? null : $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
}
