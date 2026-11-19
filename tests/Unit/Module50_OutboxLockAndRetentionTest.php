<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\DomainEvents\DomainEvent;
use Antevemus\ASpecification\DomainEvents\DomainEventDispatcher;
use Antevemus\ASpecification\DomainEvents\DomainEventDispatchReport;
use Antevemus\ASpecification\DomainEvents\DomainEventHandlerRegistry;
use Antevemus\ASpecification\DomainEvents\DomainEventKind;
use Antevemus\ASpecification\DomainEvents\Exceptions\DomainEventPersistenceException;
use Antevemus\ASpecification\DomainEvents\Outbox\DomainEventOutboxEntry;
use Antevemus\ASpecification\DomainEvents\Outbox\IDomainEventOutboxRetention;
use Antevemus\ASpecification\DomainEvents\Outbox\InMemoryDomainEventOutbox;
use Antevemus\ASpecification\DomainEvents\Outbox\PdoDomainEventOutbox;
use Antevemus\ASpecification\Messaging\Exceptions\MessagingPersistenceException;
use Antevemus\ASpecification\Messaging\Inbox\PdoProcessedMessageStore;
use Antevemus\ASpecification\Messaging\InMemory\InMemoryMessageTransport;
use Antevemus\ASpecification\Messaging\Message;
use Antevemus\ASpecification\Messaging\MessagePublisher;
use Antevemus\ASpecification\Messaging\Outbox\IMessageOutboxRetention;
use Antevemus\ASpecification\Messaging\Outbox\InMemoryMessageOutbox;
use Antevemus\ASpecification\Messaging\Outbox\MessageOutboxEntry;
use Antevemus\ASpecification\Messaging\Outbox\PdoMessageOutbox;
use Antevemus\ASpecification\Messaging\OutboxRelay;
use Antevemus\ASpecification\Messaging\RelayReport;
use Antevemus\ASpecification\Persistence\ILeaseLock;
use Antevemus\ASpecification\Persistence\InMemoryLeaseLock;
use Antevemus\ASpecification\Persistence\LeaseLockException;
use Antevemus\ASpecification\Persistence\LeaseLockSupport;
use Antevemus\ASpecification\Persistence\PdoLeaseLock;
use Antevemus\ASpecification\Persistence\PdoTransactionManager;
use Antevemus\ASpecification\StateMachine\Effect;
use Antevemus\ASpecification\StateMachine\EffectPhase;
use Antevemus\ASpecification\StateMachine\Exceptions\MachinePersistenceException;
use Antevemus\ASpecification\StateMachine\Persistence\IEffectOutboxRetention;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\InMemoryEffectOutbox;
use Antevemus\ASpecification\StateMachine\Persistence\MachinePersistence;
use Antevemus\ASpecification\StateMachine\Persistence\OutboxEntry;
use Antevemus\ASpecification\StateMachine\Persistence\OutboxStatus;
use Antevemus\ASpecification\StateMachine\Persistence\Pdo\PdoEffectOutbox;
use Antevemus\ASpecification\StateMachine\Persistence\Pdo\PdoMachineSchema;
use Antevemus\ASpecification\StateMachine\Runner\DispatchReport;
use Antevemus\ASpecification\StateMachine\Runner\EffectDispatcher;
use Antevemus\ASpecification\StateMachine\Runner\EffectHandlerRegistry;
use Antevemus\ASpecification\Tests\Support\Module48Crash;
use Antevemus\ASpecification\Tests\Support\Module50HookedDomainEventOutbox;
use Antevemus\ASpecification\Tests\Support\Module50HookedEffectOutbox;
use Antevemus\ASpecification\Tests\Support\Module50HookedLock;
use Antevemus\ASpecification\Tests\Support\Module50HookedOutbox;
use Antevemus\ASpecification\Tests\Support\Module50HookedTransport;
use Antevemus\ASpecification\Tests\Support\Module50HookPdo;
use Antevemus\ASpecification\Tests\Support\Module50LegacyMarkFailedOutbox;
use Antevemus\ASpecification\Tests\Support\ReadmeBlocks;
use Antevemus\ASpecification\Tests\TestCase;
use Antevemus\ASpecification\Util\UlidGenerator;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Fiber;
use InvalidArgumentException;
use PDO;
use PDOException;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;
use stdClass;

/**
 * Module50_OutboxLockAndRetentionTest - Forward 065 (2.16.0): the dispatchers of the three outboxes in production, one at
 * a time and the retention. The three: OutboxRelay (messages), DomainEventDispatcher (domain events) and EffectDispatcher
 * (effects of the state machine), each over its outbox in memory and over PDO, every battery run for each of them.
 *
 * - D9: ILeaseLock, with InMemoryLeaseLock and PdoLeaseLock answering the same battery (one lease per name, its end
 *   inclusive, the renewal that keeps the instant of the acquisition, the takeover past the lease, the release by the
 *   owner only, the names independent, the arguments), and random sequences of acquisitions and releases of fixed seeds
 *   whose transcript is the same in memory and in every database; the PDO lock alone: the column map, a missing table
 *   without the driver message, the race of the first acquisition (the other connection inserts between the reads and
 *   the INSERT), inside a transaction of the caller that goes on (the savepoint; on PostgreSQL the transaction a failed
 *   statement aborts, measured), a failing INSERT and a failing savepoint; and two PHP processes racing for the three
 *   locks of the dispatchers on SQLite, PostgreSQL 17, MySQL 8.4 and MariaDB 11.4, never inside a critical section
 *   together;
 * - D2/D10: each dispatcher without a lock is the one of 2.15.0 (no owner, no clock call, the report without a lock
 *   flag); with one: its default name (the kind and the table), denied (nothing delivered, the lock of the other
 *   untouched), taken, renewed before every batch or round after the first and before the heads are read (the exact
 *   calls), lost (stops before the next one, the lock of the other untouched), released at the end and when the run
 *   throws, the lease of its clock;
 * - D3/D11: a failure mark never overwrites a delivered entry (no attempt counted, nothing changed), a missing entry
 *   still throws, a delivery mark counts as before; on the three outboxes, in memory and over PDO;
 * - D4/D5/D12: the three retention interfaces: only delivered entries, strictly before the instant, the oldest delivery
 *   first and ties by the key, the limit (and the chunks of the DELETEs), a purged key published again; random sequences
 *   of fixed seeds whose transcript is the same in memory and in every database; a table of the 2.15 DDL, purged without
 *   the index and with it (the CREATE UNIQUE INDEX of the header of each DDL);
 * - D6/D13: property sequences (seeds SEEDS) with two and three dispatcher processes interleaved on fibers over one
 *   outbox, for each of the three, with deaths at every suspension point (before a renewal, a read, a mark, and before
 *   and after a delivery), restarts, publications, deliveries refused, and leases run out while a process is paused, with
 *   the lock and without it, against a model of what was published: with the lock and no pause, every delivery by the
 *   holder, no confirmed copy after a marked one, the attempts equal to the marked deliveries; always, no delivered entry
 *   reopened and the order of the first copies of each queue kept (exhausted entries aside); the same generator over the
 *   failure mark of 2.15.0 reopens delivered entries; on memory, on SQLite and on the three servers, the transcripts equal;
 * - the coverage guard: every file of src/ at `@version 2.16.0` is declared here, new or changed, imported, loaded; the
 *   new ones are in the mutation scope; Module 48 guards the rest of src/Messaging/;
 * - D7/D13: examples 26.4 and 26.5 (messaging), 18.2 (domain events) and 14.8 (state machine) run as written, the same
 *   code in both languages.
 *
 * @version    2.16.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Unit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class Module50_OutboxLockAndRetentionTest extends TestCase
{
    /** Files of src/ born in 2.16.0 (Module 48 leaves the ones of src/Messaging/ to this module). */
    public const NEW_FILES = [
        'DomainEvents/Outbox/IDomainEventOutboxRetention',
        'Messaging/Outbox/IMessageOutboxRetention',
        'Persistence/ILeaseLock',
        'Persistence/InMemoryLeaseLock',
        'Persistence/LeaseLockException',
        'Persistence/LeaseLockSupport',
        'Persistence/PdoLeaseLock',
        'StateMachine/Persistence/IEffectOutboxRetention',
    ];

    /** Files of src/ changed in 2.16.0: `@version 2.16.0`, and their docblock says what changed. */
    public const CHANGED_FILES = [
        'DomainEvents/DomainEventDispatchReport',
        'DomainEvents/DomainEventDispatcher',
        'DomainEvents/Outbox/InMemoryDomainEventOutbox',
        'DomainEvents/Outbox/PdoDomainEventOutbox',
        'Messaging/Outbox/InMemoryMessageOutbox',
        'Messaging/Outbox/PdoMessageOutbox',
        'Messaging/OutboxRelay',
        'Messaging/RelayReport',
        'StateMachine/Persistence/InMemory/InMemoryEffectOutbox',
        'StateMachine/Persistence/Pdo/PdoEffectOutbox',
        'StateMachine/Runner/DispatchReport',
        'StateMachine/Runner/EffectDispatcher',
    ];

    /** The three outboxes and their dispatchers. */
    private const KINDS = ['messages' => 'mensagens', 'domain_events' => 'eventos de domínio', 'effects' => 'efeitos'];

    /** Seeds of the property sequences (D6/D13): fixed, registered in quality/tests.md. */
    private const SEEDS = [20261119, 20261120, 20261121];

    private const STEPS_MEMORY = 200;

    private const STEPS_SERVER = 50;

    /** Lease of the dispatchers of the property sequences, in seconds. */
    private const LEASE = 30;

    /** Each configuration of the property: the lock, and leases run out while a process is paused. */
    private const CONFIGS = [
        'lock' => ['lock' => true, 'stalls' => false, 'legacy' => false],
        'lock+pauses' => ['lock' => true, 'stalls' => true, 'legacy' => false],
        'no-lock' => ['lock' => false, 'stalls' => false, 'legacy' => false],
    ];

    private const DDLS = [
        'messages' => __DIR__ . '/../../resources/sql/messaging.ansi.sql',
        'domain_events' => __DIR__ . '/../../resources/sql/domain_events.ansi.sql',
        'effects' => __DIR__ . '/../../resources/sql/state_machine.ansi.sql',
        'lock' => __DIR__ . '/../../resources/sql/lease_lock.ansi.sql',
    ];

    /** The constraint of the retention index of each DDL, and its table. */
    private const RETENTION_INDEXES = [
        'messages' => ['uq_mo_delivered', 'message_outbox'],
        'domain_events' => ['uq_deo_delivered', 'domain_event_outbox'],
        'effects' => ['uq_smo_delivered', 'state_machine_outbox'],
    ];

    private const T0 = '2026-11-19 10:00:00';

    /** @var array<string, array<string, int>> kind => configuration => totals, of the memory sequences (the report) */
    public array $totals = [];

    public function run(): void
    {
        $this->testArguments();
        $this->lockContract('memória', static function (): array {
            $lock = new InMemoryLeaseLock();

            return [$lock, $lock];
        });
        $locks = [];
        foreach (self::SEEDS as $seed) {
            $locks[$seed] = $this->lockSequence($seed, new InMemoryLeaseLock());
        }
        $retention = [];
        $properties = [];
        foreach (array_keys(self::KINDS) as $kind) {
            $this->runBehaviour($kind, 'memória', fn(): array => $this->world($kind, null));
            foreach (self::SEEDS as $seed) {
                $retention[$kind][$seed] = $this->retentionSequence($seed, $this->world($kind, null));
            }
            $properties[$kind] = $this->runProperties($kind, 'memória', fn(): array => $this->world($kind, null), self::STEPS_MEMORY, [2, 3]);
        }
        $this->testTheFailureMarkOf215Reopens();

        if (!extension_loaded('pdo_sqlite')) {
            fwrite(STDOUT, "    [AVISO] pdo_sqlite ausente; o Módulo 50 roda só a parte em memória.\n");
            $this->testCoverageGuard(false);

            return;
        }

        $this->runDatabase('SQLite', 'sqlite', static fn(): PDO => self::sqliteEmpty(), $locks, $retention, $properties, self::STEPS_MEMORY, [2, 3]);
        $this->testSqliteFiles();
        $this->testFailingStatements();
        $this->testExamplesAreTheSameInBothLanguages();
        $this->testMessagingExamplesRunAsWritten();
        $this->testDomainEventExampleRunsAsWritten();
        $this->testEffectExampleRunsAsWritten();

        foreach (['pgsql' => 'PostgreSQL', 'mysql' => 'MySQL', 'mariadb' => 'MariaDB'] as $family => $label) {
            $pdo = self::connectFromEnvironment($family);
            if ($pdo === null) {
                fwrite(STDOUT, '    [AVISO] ASPEC_PDO_' . strtoupper($family) . "_DSN não definido; Módulo 50 em {$label} pulado.\n");
                continue;
            }
            $before = $this->getAssertionCount();
            $short = [];
            foreach (array_keys(self::KINDS) as $kind) {
                $short[$kind] = $this->runProperties($kind, 'memória', fn(): array => $this->world($kind, null), self::STEPS_SERVER, [3]);
            }
            $connect = static fn(): PDO => self::connectFromEnvironment($family) ?? throw new RuntimeException('no connection');
            $this->runDatabase($label, $family, $connect, $locks, $retention, $short, self::STEPS_SERVER, [3]);
            $this->testRaces($label, $connect, $family);
            $this->testTwoProcesses($label, self::environmentDsn($family));
            self::dropSchema($pdo);
            fwrite(STDOUT, "    [065] {$label} " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . ': trava, três despachantes, marca de falha, retenção, propriedades e dois processos OK (' . ($this->getAssertionCount() - $before) . " asserções)\n");
        }

        $this->testCoverageGuard(true);
    }

    // =====================================================================
    // D9/D10: arguments and defaults
    // =====================================================================

    private function testArguments(): void
    {
        $lock = new InMemoryLeaseLock();
        $now = $this->at(self::T0);
        foreach (['' => 0, str_repeat('x', 121) => 121, str_repeat('ç', 121) => 121] as $text => $length) {
            $e = $this->assertThrows(InvalidArgumentException::class, fn() => $lock->tryAcquire('outbox', (string) $text, $now, 30), "dono de {$length} caracteres");
            $this->assertEquals("The owner of a lease lock must have 1 to 120 characters, {$length} given.", $e->getMessage());
            $e = $this->assertThrows(InvalidArgumentException::class, fn() => $lock->tryAcquire((string) $text, 'owner', $now, 30), "nome de {$length} caracteres");
            $this->assertEquals("The name of a lease lock must have 1 to 120 characters, {$length} given.", $e->getMessage());
        }
        foreach ([0, -1] as $lease) {
            $e = $this->assertThrows(InvalidArgumentException::class, fn() => $lock->tryAcquire('outbox', 'relay', $now, $lease), "prazo {$lease}");
            $this->assertEquals("The lease of a lock must be at least 1 second, {$lease} given.", $e->getMessage());
        }
        $this->assertTrue($lock->tryAcquire(str_repeat('n', 120), str_repeat('ç', 120), $now, 1), '120 caracteres (multibyte) bastam');
        $this->assertEquals(str_repeat('ç', 120), $lock->holder(str_repeat('n', 120), $now));
        $lock->release(str_repeat('n', 120), str_repeat('ç', 120));
        $this->assertEquals([120, 120, 60], [ILeaseLock::MAX_NAME_LENGTH, ILeaseLock::MAX_OWNER_LENGTH, LeaseLockSupport::DEFAULT_LEASE_SECONDS]);
        $this->assertEquals('2026-11-19 10:00:30.000000', LeaseLockSupport::toText(LeaseLockSupport::leaseEnd($now, 30)));
        $this->assertEquals('2026-11-19 10:00:30.000000', LeaseLockSupport::toText(LeaseLockSupport::leaseEnd(new DateTimeImmutable('2026-11-19 07:00:00', new DateTimeZone('America/Sao_Paulo')), 30)), 'o fim do prazo em UTC');

        $this->assertEquals(
            ['messaging.outbox_relay', 'domain_events.dispatcher', 'state_machine.effect_dispatcher'],
            [OutboxRelay::LOCK_NAME, DomainEventDispatcher::LOCK_NAME, EffectDispatcher::LOCK_NAME],
            'o tipo de cada nome padrão'
        );
        $this->assertEquals([60, 60, 60], [OutboxRelay::DEFAULT_LEASE_SECONDS, DomainEventDispatcher::DEFAULT_LEASE_SECONDS, EffectDispatcher::DEFAULT_LEASE_SECONDS]);
        foreach (array_keys(self::KINDS) as $kind) {
            $label = self::KINDS[$kind];
            $world = $this->world($kind, null);
            $make = fn(array $options): array => ($world['dispatcher'])($world['outbox'], static function (): void {
            }, static fn(): bool => true, $options);
            foreach (['' => 0, str_repeat('x', 121) => 121] as $text => $length) {
                $this->assertThrows(InvalidArgumentException::class, fn() => $make(['lock' => $lock, 'owner' => (string) $text]), "{$label}: dono de {$length} caracteres");
                $this->assertThrows(InvalidArgumentException::class, fn() => $make(['owner' => (string) $text]), "{$label}: dono de {$length} caracteres também sem trava");
                $this->assertThrows(InvalidArgumentException::class, fn() => $make(['lock' => $lock, 'name' => (string) $text]), "{$label}: nome de {$length} caracteres");
                $this->assertThrows(InvalidArgumentException::class, fn() => $make(['name' => (string) $text]), "{$label}: nome de {$length} caracteres também sem trava");
            }
            foreach ([0, -1] as $lease) {
                $this->assertThrows(InvalidArgumentException::class, fn() => $make(['lock' => $lock, 'lease' => $lease]), "{$label}: prazo {$lease}");
                $this->assertThrows(InvalidArgumentException::class, fn() => $make(['lease' => $lease]), "{$label}: prazo {$lease} também sem trava");
            }
            $plain = $make([]);
            $this->assertTrue($plain['owner'] === null, "{$label}: sem trava e sem dono, não há dono (nem ULID gerado)");
            $this->assertEquals($world['defaultName'], $plain['name'], "{$label}: o nome padrão em memória é o tipo");
            $this->assertEquals('given', $make(['owner' => 'given'])['owner'], "{$label}: o dono dado sem trava fica guardado");
            $first = (string) $make(['lock' => $lock])['owner'];
            $second = (string) $make(['lock' => $lock])['owner'];
            $this->assertTrue(UlidGenerator::isValid($first) && UlidGenerator::isValid($second) && $first !== $second, "{$label}: com trava, cada instância tem um ULID novo");
            $this->assertEquals(['relay@host:1', 'billing'], [$make(['lock' => $lock, 'owner' => 'relay@host:1'])['owner'], $make(['lock' => $lock, 'name' => 'billing'])['name']], "{$label}: dono e nome dados");
            $this->assertEquals($world['defaultName'], $make(['lock' => $lock, 'name' => null])['name']);
        }
        $long = new PdoMessageOutbox(self::sqliteEmpty(), 'outbox', str_repeat('s', 100));
        $this->assertEquals('messaging.outbox_relay:' . str_repeat('s', 100) . '.outbox', (new OutboxRelay($long, new InMemoryMessageTransport()))->getLockName(), 'sem trava, um nome padrão longo demais não é recusado (comportamento de 2.15.0)');
        $this->assertThrows(InvalidArgumentException::class, fn() => new OutboxRelay($long, new InMemoryMessageTransport(), lock: $lock), 'com trava, é');
        $longEvents = new PdoDomainEventOutbox(self::sqliteEmpty(), 'outbox', str_repeat('s', 100));
        $this->assertEquals('domain_events.dispatcher:' . str_repeat('s', 100) . '.outbox', (new DomainEventDispatcher($longEvents, new DomainEventHandlerRegistry()))->getLockName(), 'eventos de domínio: sem trava, o nome padrão longo demais não é recusado');
        $this->assertThrows(InvalidArgumentException::class, fn() => new DomainEventDispatcher($longEvents, new DomainEventHandlerRegistry(), lock: $lock), 'eventos de domínio: com trava, é');

        foreach ([[RelayReport::class, 'mensagens'], [DomainEventDispatchReport::class, 'eventos'], [DispatchReport::class, 'efeitos']] as [$class, $label]) {
            $report = new $class();
            $this->assertTrue(!$report->isLockDenied() && !$report->isLockLost(), "{$label}: o relatório de sempre não fala da trava");
            $denied = new $class(lockDenied: true);
            $lost = new $class(lockLost: true);
            $this->assertTrue($denied->isLockDenied() && !$denied->isLockLost() && $lost->isLockLost() && !$lost->isLockDenied(), "{$label}: as duas bandeiras");
        }
        $this->assertEquals(['delivered' => [], 'failed' => [], 'exhausted' => [], 'batches' => 0], (new RelayReport(lockDenied: true, lockLost: true))->toArray(), 'toArray() inalterado');
    }

    // =====================================================================
    // D9: the lock battery (memory and PDO)
    // =====================================================================

    /**
     * @param Closure(): array{InMemoryLeaseLock|PdoLeaseLock, InMemoryLeaseLock|PdoLeaseLock} $fresh Two handles of one store
     */
    private function lockContract(string $label, Closure $fresh): void
    {
        [$a, $b] = $fresh();
        $n = 'outbox-a';
        $t = fn(string $offset): DateTimeImmutable => $this->at(self::T0)->modify($offset);

        $this->assertTrue($a->tryAcquire($n, 'relay-a', $t('+0 seconds'), 30), "{$label}: a trava livre é tomada");
        $this->assertEquals('relay-a 2026-11-19 10:00:00.000000 2026-11-19 10:00:30.000000', self::leaseOf($b, $n), "{$label}: o outro lado vê dono, aquisição e prazo");
        $this->assertEquals('relay-a', $b->holder($n, $t('+0 seconds')));
        $this->assertFalse($b->tryAcquire($n, 'relay-b', $t('+0 seconds'), 30), "{$label}: segurada por outro");
        $this->assertTrue($b->tryAcquire('outbox-b', 'relay-b', $t('+0 seconds'), 30), "{$label}: outro nome é outra trava");
        $this->assertEquals(['relay-a', 'relay-b'], [$a->holder($n, $t('+1 second')), $a->holder('outbox-b', $t('+1 second'))]);
        $b->release('outbox-b', 'relay-b');
        $this->assertTrue(self::leaseOf($a, 'outbox-b') === null && self::leaseOf($a, $n) !== null, "{$label}: soltar uma não solta a outra");
        $this->assertFalse($b->tryAcquire($n, 'relay-b', $t('+30 seconds'), 30), "{$label}: o fim do prazo ainda é do dono");
        $this->assertEquals('relay-a', $b->holder($n, $t('+30 seconds')), "{$label}: dono no fim do prazo");
        $this->assertTrue($b->holder($n, $t('+30 seconds +1 microsecond')) === null, "{$label}: ninguém depois do prazo");

        $this->assertTrue($a->tryAcquire($n, 'relay-a', $t('+10 seconds'), 30), "{$label}: o dono renova");
        $this->assertEquals('relay-a 2026-11-19 10:00:00.000000 2026-11-19 10:00:40.000000', self::leaseOf($b, $n), "{$label}: a renovação estende o prazo e guarda a aquisição");
        $this->assertTrue($a->tryAcquire($n, 'relay-a', $t('+10 seconds'), 30), "{$label}: renovar no mesmo instante (nada muda na linha) ainda segura");
        $this->assertEquals('relay-a 2026-11-19 10:00:00.000000 2026-11-19 10:00:40.000000', self::leaseOf($a, $n));
        $this->assertTrue($a->tryAcquire($n, 'relay-a', $t('+10 seconds'), 5), "{$label}: o dono pode encurtar o prazo");
        $this->assertEquals('relay-a 2026-11-19 10:00:00.000000 2026-11-19 10:00:15.000000', self::leaseOf($a, $n));

        $this->assertFalse($b->tryAcquire($n, 'relay-b', $t('+15 seconds'), 5), "{$label}: o fim exato do prazo ainda segura");
        $this->assertTrue($b->tryAcquire($n, 'relay-b', $t('+15 seconds +1 microsecond'), 5), "{$label}: um microssegundo depois, o outro assume");
        $this->assertEquals('relay-b 2026-11-19 10:00:15.000001 2026-11-19 10:00:20.000001', self::leaseOf($a, $n), "{$label}: a aquisição é a do novo dono");
        $this->assertFalse($a->tryAcquire($n, 'relay-a', $t('+16 seconds'), 30), "{$label}: o antigo dono não renova uma trava assumida");
        $a->release($n, 'relay-a');
        $this->assertEquals('relay-b', $a->holder($n, $t('+16 seconds')), "{$label}: soltar uma trava que não é sua não faz nada");
        $a->release($n, 'relay-c');
        $a->release('outbox-z', 'relay-b');
        $this->assertEquals('relay-b', $b->holder($n, $t('+16 seconds')), "{$label}: nem soltar o mesmo dono em outro nome");

        $this->assertTrue($b->tryAcquire($n, 'relay-b', $t('+60 seconds'), 30), "{$label}: o dono renova depois do prazo quando ninguém assumiu");
        $this->assertEquals('relay-b 2026-11-19 10:00:15.000001 2026-11-19 10:01:30.000000', self::leaseOf($a, $n), "{$label}: e guarda a aquisição");
        $b->release($n, 'relay-b');
        $this->assertTrue(self::leaseOf($a, $n) === null && $a->holder($n, $t('+61 seconds')) === null, "{$label}: liberada, não há linha nem dono");
        $b->release($n, 'relay-b');
        $this->assertTrue(self::leaseOf($a, $n) === null, "{$label}: soltar duas vezes não faz nada");

        $this->assertTrue($a->tryAcquire($n, 'relay-a', new DateTimeImmutable('2026-11-19 07:05:00.5', new DateTimeZone('America/Sao_Paulo')), 2), "{$label}: tomada de novo, num instante de outro fuso");
        $this->assertEquals('relay-a 2026-11-19 10:05:00.500000 2026-11-19 10:05:02.500000', self::leaseOf($b, $n), "{$label}: os instantes em UTC");
        $this->assertFalse($b->tryAcquire($n, 'relay-b', new DateTimeImmutable('2026-11-19 06:05:01', new DateTimeZone('America/Manaus')), 2), "{$label}: o outro fuso compara pelo instante (10:05:01 UTC, dentro do prazo)");
        $this->assertTrue($b->tryAcquire($n, 'relay-b', new DateTimeImmutable('2026-11-19 11:05:03', new DateTimeZone('+01:00')), 2), "{$label}: e assume depois dele (10:05:03 UTC)");
        $b->release($n, 'relay-b');
        $this->assertTrue($b->tryAcquire($n, 'relay-a', $t('+1 hour'), 1) && $a->tryAcquire($n, 'relay-a', $t('+1 hour'), 1), "{$label}: o mesmo dono por dois lados é o mesmo dono");
        $a->release($n, 'relay-a');
        $this->assertTrue(self::leaseOf($b, $n) === null);
    }

    /**
     * Random acquisitions and releases of three owners over two names (fixed seed): the transcript, compared between
     * memory and every database.
     *
     * @return list<string>
     */
    private function lockSequence(int $seed, InMemoryLeaseLock|PdoLeaseLock $lock): array
    {
        $random = new Randomizer(new Mt19937($seed));
        $now = $this->at(self::T0);
        $transcript = [];
        for ($step = 0; $step < 120; $step++) {
            $now = $now->modify(sprintf('+%d seconds +%d microseconds', $random->getInt(0, 3), $random->getInt(0, 999999)));
            $owner = ['relay-a', 'relay-b', 'relay-c'][$random->getInt(0, 2)];
            $name = ['outbox-a', 'outbox-b'][$random->getInt(0, 1)];
            if ($random->getInt(1, 100) <= 25) {
                $lock->release($name, $owner);
                $transcript[] = sprintf('%s release %s %s | %s', LeaseLockSupport::toText($now), $name, $owner, self::leaseOf($lock, $name) ?? '-');
                continue;
            }
            $lease = $random->getInt(1, 5);
            $answer = $lock->tryAcquire($name, $owner, $now, $lease);
            $transcript[] = sprintf('%s acquire %s %s %d %s | %s | %s', LeaseLockSupport::toText($now), $name, $owner, $lease, $answer ? 'yes' : 'no', self::leaseOf($lock, $name) ?? '-', $lock->holder($name, $now) ?? '-');
        }
        $this->assertEquals(120, count($transcript));

        return $transcript;
    }

    // =====================================================================
    // D2/D10, D3/D11, D4/D12: each dispatcher with a lock, the failure mark, the retention
    // =====================================================================

    /**
     * @param Closure(): array<string, mixed> $world
     */
    private function runBehaviour(string $kind, string $store, Closure $world): void
    {
        $label = self::KINDS[$kind] . " ({$store})";
        $this->testWithoutLock($label, $world());
        $this->testDenied($label, $world());
        $this->testTakesRenewsAndReleases($label, $world());
        $this->testLosesTheLock($label, $world());
        $this->testReleasesWhenTheRunThrows($label, $world());
        $this->testFailureMarks($label, $world());
        $this->retentionContract($label, $world());
        $this->testPurgeInChunks($label, $world());
    }

    /** @param array<string, mixed> $w */
    private function testWithoutLock(string $label, array $w): void
    {
        ($w['publish'])([1 => 'k1', 2 => 'k2', 3 => 'k1'], $this->at(self::T0));
        $calls = 0;
        $clock = function () use (&$calls): DateTimeImmutable {
            $calls++;

            return $this->at(self::T0);
        };
        $dispatcher = ($w['dispatcher'])($w['outbox'], static function (): void {
        }, static fn(): bool => true, ['clock' => $clock]);
        $report = ($dispatcher['run'])(10);
        $this->assertEquals([3, 3], [count($report['delivered']), $calls], "{$label}: sem trava, o de 2.15.0 (uma chamada do relógio por marca, nenhuma para a trava)");
        $this->assertTrue(!$report['denied'] && !$report['lost'] && $dispatcher['owner'] === null, "{$label}: sem trava, o relatório não fala dela");
        if ($w['kind'] === 'messages') {
            $this->assertEquals(['delivered', 'failed', 'exhausted', 'batches'], array_keys($report['report']->toArray()), "{$label}: toArray() com as chaves de sempre");
        }
    }

    /** @param array<string, mixed> $w */
    private function testDenied(string $label, array $w): void
    {
        $lock = ($w['lock'])();
        ($w['publish'])([11 => 'k1', 12 => null], $this->at(self::T0));
        $deliveries = 0;
        $dispatcher = ($w['dispatcher'])($w['outbox'], static function (): void {
        }, static function () use (&$deliveries): bool {
            $deliveries++;

            return true;
        }, ['clock' => fn(): DateTimeImmutable => $this->at('2026-11-19 10:00:59'), 'lock' => $lock, 'owner' => 'me']);
        $this->assertEquals($w['defaultName'] . ($w['pdo'] ? ':' . $w['table'] : ''), $dispatcher['name'], "{$label}: o nome padrão é o tipo mais a tabela");
        $this->assertTrue($lock->tryAcquire($dispatcher['name'], 'other-process', $this->at(self::T0), 60));
        $this->assertTrue($lock->tryAcquire('another-outbox', 'third-process', $this->at(self::T0), 60), 'a trava de outra outbox não atrapalha');
        $report = ($dispatcher['run'])(10);
        $this->assertTrue($report['denied'] && !$report['lost'], "{$label}: outro processo segura a trava: negada");
        $this->assertEquals([0, 0, 0], [count($report['delivered']), count($report['failed']), $deliveries], "{$label}: nada foi entregue");
        $this->assertEquals(['pending', 'pending'], [($w['state'])(($w['id'])(11, 'k1'))['status'], ($w['state'])(($w['id'])(12, null))['status']], "{$label}: a outbox não mudou");
        $this->assertEquals('other-process 2026-11-19 10:00:00.000000 2026-11-19 10:01:00.000000', self::leaseOf($lock, $dispatcher['name']), "{$label}: a trava do outro ficou intacta (não foi solta)");
        $lock->release($dispatcher['name'], 'other-process');
        $this->assertEquals(2, count(($dispatcher['run'])(10)['delivered']), "{$label}: solta, entrega");
        $this->assertTrue(self::leaseOf($lock, $dispatcher['name']) === null && self::leaseOf($lock, 'another-outbox') !== null, "{$label}: e solta a sua no fim, só a sua");
    }

    /** @param array<string, mixed> $w */
    private function testTakesRenewsAndReleases(string $label, array $w): void
    {
        $inner = ($w['lock'])();
        ($w['publish'])([21 => 'a', 22 => 'a', 23 => 'a', 24 => 'b', 25 => 'b'], $this->at(self::T0));
        $leases = [];
        $lock = new Module50HookedLock($inner, static function (): void {
        }, static fn(): bool => false);
        $now = $this->at(self::T0);
        $name = 'worker-lock';
        $dispatcher = ($w['dispatcher'])($w['outbox'], static function (): void {
        }, static function () use ($inner, $name, &$leases): bool {
            $leases[] = self::leaseOf($inner, $name);

            return true;
        }, ['batch' => 2, 'clock' => function () use (&$now): DateTimeImmutable {
            $now = $now->modify('+1 second');

            return $now;
        }, 'lock' => $lock, 'lease' => 45, 'owner' => 'worker-1', 'name' => $name]);
        $report = ($dispatcher['run'])(100);
        $this->assertEquals(5, count($report['delivered']), "{$label}: tudo entregue");
        $this->assertTrue(!$report['denied'] && !$report['lost'], "{$label}: nem negada nem perdida");
        $this->assertEquals(['acquire:worker-1:yes', 'acquire:worker-1:yes', 'acquire:worker-1:yes', 'acquire:worker-1:yes', 'release:worker-1'], $lock->calls, "{$label}: tomada antes do 1º lote, renovada antes do 2º, do 3º e da leitura que não achou nada, solta no fim");
        $this->assertEquals([
            'worker-1 2026-11-19 10:00:01.000000 2026-11-19 10:00:46.000000',
            'worker-1 2026-11-19 10:00:01.000000 2026-11-19 10:00:49.000000',
            'worker-1 2026-11-19 10:00:01.000000 2026-11-19 10:00:52.000000',
        ], array_values(array_unique($leases)), "{$label}: o prazo é o relógio do despachante mais leaseSeconds, renovado antes de cada lote, com a aquisição guardada");
        $this->assertTrue(self::leaseOf($inner, $name) === null, "{$label}: solta no fim");

        ($w['publish'])([26 => 'a', 27 => 'b', 28 => 'c', 29 => 'd'], $this->at(self::T0));
        $lock->calls = [];
        $this->assertEquals(4, count(($dispatcher['run'])(4)['delivered']));
        $this->assertEquals($w['kind'] === 'messages' ? ['acquire:worker-1:yes', 'acquire:worker-1:yes', 'release:worker-1'] : ['acquire:worker-1:yes', 'release:worker-1'], $lock->calls, "{$label}: a rodada que acaba pelo limite não renova à toa");
    }

    /** @param array<string, mixed> $w */
    private function testLosesTheLock(string $label, array $w): void
    {
        $lock = ($w['lock'])();
        $other = ($w['lock'])();
        ($w['publish'])([31 => 'a', 32 => 'a', 33 => 'a'], $this->at(self::T0));
        $now = $this->at(self::T0);
        $dispatcher = ($w['dispatcher'])($w['outbox'], static function (): void {
        }, function () use ($other, &$now): bool {
            // The process is paused in the delivery for longer than its lease, and another one takes the lock over
            $now = $now->modify('+31 seconds');
            if ($other->holder('slow-lock', $now) === null) {
                $this->assertTrue($other->tryAcquire('slow-lock', 'intruder', $now, 300), 'o outro assume depois do prazo');
            }

            return true;
        }, ['batch' => 1, 'clock' => function () use (&$now): DateTimeImmutable {
            return $now;
        }, 'lock' => $lock, 'lease' => 30, 'owner' => 'slow', 'name' => 'slow-lock']);
        $report = ($dispatcher['run'])(100);
        $this->assertTrue($report['lost'] && !$report['denied'], "{$label}: a renovação falhou: perdida");
        $this->assertEquals([($w['id'])(31, 'a')], $report['delivered'], "{$label}: parou antes do lote seguinte; o que já marcou fica marcado");
        $this->assertEquals(['delivered', 'pending', 'pending'], array_map(fn(int $n): string => ($w['state'])(($w['id'])($n, 'a'))['status'], [31, 32, 33]));
        $this->assertEquals('intruder', $lock->holder('slow-lock', $now), "{$label}: soltar no fim não tira a trava de quem assumiu");
        $other->release('slow-lock', 'intruder');
    }

    /** @param array<string, mixed> $w */
    private function testReleasesWhenTheRunThrows(string $label, array $w): void
    {
        $lock = ($w['lock'])();
        ($w['publish'])([41 => 'a'], $this->at(self::T0));
        $crashing = ($w['wrap'])($w['outbox'], 'crashing', static function (string $op): void {
            if ($op === 'delivered') {
                throw new Module48Crash('the process died in the mark');
            }
        });
        $dispatcher = ($w['dispatcher'])($crashing, static function (): void {
        }, static fn(): bool => true, ['clock' => fn(): DateTimeImmutable => $this->at(self::T0), 'lock' => $lock, 'owner' => 'crashing']);
        $this->assertThrows(Module48Crash::class, fn() => ($dispatcher['run'])(10), "{$label}: a marca lança");
        $this->assertTrue(self::leaseOf($lock, $dispatcher['name']) === null, "{$label}: a trava foi solta mesmo assim (finally)");
        $this->assertEquals('pending', ($w['state'])(($w['id'])(41, 'a'))['status']);
        $next = ($w['dispatcher'])($w['outbox'], static function (): void {
        }, static fn(): bool => true, ['lock' => $lock]);
        $this->assertEquals(1, count(($next['run'])(10)['delivered']), "{$label}: o próximo toma a trava na hora");
    }

    /** @param array<string, mixed> $w */
    private function testFailureMarks(string $label, array $w): void
    {
        ($w['publish'])([51 => 'f', 52 => 'g'], $this->at(self::T0));
        $first = ($w['id'])(51, 'f');
        $entry = ($w['entry'])($first);
        ($w['markDelivered'])($entry, $this->at('2026-11-19 10:00:01'));
        ($w['markFailed'])($entry, 'a late failure of another dispatcher', $this->at('2026-11-19 10:00:02'));
        $this->assertEquals(
            ['status' => 'delivered', 'attempts' => 1, 'error' => null, 'delivered' => '2026-11-19 10:00:01.000000', 'last' => '2026-11-19 10:00:01.000000'],
            ($w['state'])($first),
            "{$label}: D3: a falha tardia não sobrescreve a entrega nem conta tentativa"
        );
        ($w['markFailed'])(($w['entry'])($first), 'again', $this->at('2026-11-19 10:00:03'));
        $this->assertEquals(1, ($w['state'])($first)['attempts'], "{$label}: nem com a entrada lida depois da entrega");
        ($w['markDelivered'])($entry, $this->at('2026-11-19 10:00:04'));
        $this->assertEquals(['delivered', 2, '2026-11-19 10:00:04.000000'], array_values(array_intersect_key(($w['state'])($first), ['status' => 0, 'attempts' => 0, 'delivered' => 0])), "{$label}: uma segunda entrega conta, como antes (a entrega aconteceu)");

        $second = ($w['id'])(52, 'g');
        ($w['markFailed'])(($w['entry'])($second), 'refused', $this->at('2026-11-19 10:00:05'));
        ($w['markFailed'])(($w['entry'])($second), 'refused again', $this->at('2026-11-19 10:00:06'));
        $this->assertEquals(['status' => 'failed', 'attempts' => 2, 'error' => 'refused again', 'delivered' => null, 'last' => '2026-11-19 10:00:06.000000'], ($w['state'])($second), "{$label}: uma falha de uma entrada aberta conta, como antes");

        $ghost = ($w['ghost'])();
        $this->assertThrows($w['missing'], fn() => ($w['markFailed'])($ghost, 'x', $this->at(self::T0)), "{$label}: falha de uma entrada que não existe");
        $this->assertThrows($w['missing'], fn() => ($w['markDelivered'])($ghost, $this->at(self::T0)), "{$label}: entrega de uma entrada que não existe");
    }

    /** @param array<string, mixed> $w */
    private function retentionContract(string $label, array $w): void
    {
        $keys = [61 => 'r', 62 => 'r', 63 => 's', 64 => null, 65 => 't', 66 => 'u', 67 => 'v', 68 => 'w', 69 => 'x'];
        ($w['publish'])($keys, $this->at(self::T0));
        $mark = function (int $n, string $how, string $at, int $times = 1) use ($w, $keys): void {
            for ($i = 0; $i < $times; $i++) {
                $entry = ($w['entry'])(($w['id'])($n, $keys[$n]));
                $how === 'delivered' ? ($w['markDelivered'])($entry, $this->at($at)) : ($w['markFailed'])($entry, 'refused', $this->at($at));
            }
        };
        $mark(61, 'delivered', '2026-11-10 08:00:00');
        $mark(62, 'delivered', '2026-11-09 08:00:00');
        $mark(63, 'delivered', '2026-11-10 08:00:00');      // the same instant as 61: ties by the key
        $mark(64, 'delivered', '2026-11-12 00:00:00');      // exactly the instant: kept (strictly before)
        $mark(65, 'failed', '2026-11-01 08:00:00', 3);      // failed long ago (exhausted for a dispatcher with maxAttempts 3)
        $mark(66, 'failed', '2026-11-01 08:00:00');
        $mark(67, 'failed', '2026-11-01 08:00:00');
        $mark(67, 'delivered', '2026-11-02 08:00:00');      // failed, then delivered
        $mark(68, 'delivered', '2026-11-11 23:59:59.999999');
        $before = $this->at('2026-11-12 00:00:00');
        $remaining = fn(): array => array_values(array_filter(array_keys($keys), static fn(int $n): bool => ($w['state'])(($w['id'])($n, $keys[$n])) !== null));

        $this->assertEquals(2, ($w['purge'])($before, 2), "{$label}: o limite");
        $this->assertEquals([61, 63, 64, 65, 66, 68, 69], $remaining(), "{$label}: as duas entregas mais antigas primeiro (67 em 02/11, 62 em 09/11)");
        $this->assertEquals(1, ($w['purge'])($before, 1), "{$label}: empate no instante: a menor chave primeiro");
        $this->assertEquals([63, 64, 65, 66, 68, 69], $remaining());
        $this->assertEquals(2, ($w['purge'])($before, null), "{$label}: o resto, limite padrão");
        $this->assertEquals([64, 65, 66, 69], $remaining(), "{$label}: ficam a entrega no instante exato, as falhas (esgotada ou não) e a pendente");
        $this->assertEquals(0, ($w['purge'])($before, null), "{$label}: nada mais");
        $this->assertEquals(1, ($w['purge'])($before->modify('+1 microsecond'), null), "{$label}: um microssegundo depois, a do instante");
        $this->assertEquals(0, ($w['purge'])($this->at('2030-01-01 00:00:00'), 1000), "{$label}: falhas e pendentes nunca saem");
        $this->assertEquals([65, 66, 69], $remaining());
        $this->assertThrows(InvalidArgumentException::class, fn() => ($w['purge'])($before, 0), "{$label}: limite 0");
        $e = $this->assertThrows(InvalidArgumentException::class, fn() => ($w['purge'])($before, -3), "{$label}: limite negativo");
        $this->assertEquals('The limit must be at least 1, -3 given.', $e->getMessage());

        // D5: the outboxes that deduplicate do it by the rows that exist: once purged, the same key is published again
        if ($w['dedupes']) {
            $this->assertEquals(0, ($w['publish'])([69 => 'x'], $this->at(self::T0)), "{$label}: a pendente ainda deduplica");
        } else {
            $this->assertThrows($w['missing'], fn() => ($w['publish'])([69 => 'x'], $this->at(self::T0)), "{$label}: a outbox de efeitos não deduplica: a mesma posição é recusada (o runner nunca grava um passo duas vezes)");
        }
        $this->assertEquals(1, ($w['publish'])([61 => 'r'], $this->at(self::T0)), "{$label}: D5: a chave removida é gravada de novo");
        $this->assertEquals(['pending', 0], [($w['state'])(($w['id'])(61, 'r'))['status'], ($w['state'])(($w['id'])(61, 'r'))['attempts']], "{$label}: como uma entrada nova");
    }

    /** @param array<string, mixed> $w */
    private function testPurgeInChunks(string $label, array $w): void
    {
        $count = $w['chunk'] * 2 + 3;
        ($w['transactional'])(function () use ($w, $count): void {
            ($w['publish'])(array_fill_keys(range(1001, 1000 + $count), null), $this->at(self::T0));
            for ($i = 1; $i <= $count; $i++) {
                ($w['markDelivered'])(($w['entry'])(($w['id'])(1000 + $i, null)), $this->at('2026-11-01 00:00:00')->modify('+' . $i . ' seconds'));
            }
        });
        $this->assertEquals($count - 1, ($w['purge'])($this->at('2026-12-01 00:00:00'), $count - 1), "{$label}: um lote maior que " . $w['chunk'] . ' (três DELETEs)');
        $this->assertTrue(($w['state'])(($w['id'])(1000 + $count, null)) !== null && ($w['state'])(($w['id'])(1001, null)) === null, "{$label}: sobra a última entrega");
        $this->assertEquals(1, ($w['purge'])($this->at('2026-12-01 00:00:00'), 5));
    }

    /**
     * Random publications, marks and purges (fixed seed): the transcript, compared between memory and every database.
     *
     * @param array<string, mixed> $w
     * @return list<string>
     */
    private function retentionSequence(int $seed, array $w): array
    {
        $random = new Randomizer(new Mt19937($seed));
        $instants = array_map(fn(int $h): DateTimeImmutable => $this->at('2026-11-01 00:00:00')->modify("+{$h} hours"), range(0, 9));
        $transcript = [];
        $keys = [];
        $n = 2000;
        $describe = static fn(?array $state): string => $state === null ? 'gone' : sprintf('%s %d %s', $state['status'], $state['attempts'], $state['delivered'] ?? '-');
        for ($step = 0; $step < 100; $step++) {
            $roll = $random->getInt(1, 100);
            if ($roll <= 35) {
                $batch = [];
                for ($i = 0, $k = $random->getInt(1, 3); $i < $k; $i++) {
                    $batch[++$n] = [null, 'a', 'b'][$random->getInt(0, 2)];
                }
                $keys += $batch;
                $transcript[] = 'publish ' . implode(',', array_keys($batch)) . ' -> ' . ($w['publish'])($batch, $this->at(self::T0));
            } elseif ($roll <= 75 && $n > 2000) {
                $pick = $random->getInt(2001, $n);
                $id = ($w['id'])($pick, $keys[$pick]);
                $at = $instants[$random->getInt(0, 9)];
                $delivered = $random->getInt(0, 1) === 1;
                $exists = ($w['state'])($id) !== null;
                if ($exists) {
                    $entry = ($w['entry'])($id);
                    $delivered ? ($w['markDelivered'])($entry, $at) : ($w['markFailed'])($entry, 'refused', $at);
                }
                $transcript[] = sprintf('%s %d %s', $delivered ? 'delivered' : 'failed', $pick, $exists ? $describe(($w['state'])($id)) : 'gone');
            } else {
                $before = $instants[$random->getInt(0, 9)]->modify('+' . $random->getInt(0, 1) . ' microseconds');
                $limit = $random->getInt(1, 4);
                $purged = ($w['purge'])($before, $limit);
                $left = array_values(array_filter(array_keys($keys), static fn(int $i): bool => ($w['state'])(($w['id'])($i, $keys[$i])) !== null));
                $transcript[] = sprintf('purge %s %d -> %d | %s', LeaseLockSupport::toText($before), $limit, $purged, implode(',', $left));
            }
        }
        $this->assertTrue(count(array_filter($transcript, static fn(string $l): bool => preg_match('/^purge .* -> [1-9]/', $l) === 1)) >= 5, self::KINDS[$w['kind']] . ": sequência de retenção {$seed}: purgas que removeram algo");

        return $transcript;
    }

    // =====================================================================
    // D6/D13: the property sequences
    // =====================================================================

    /**
     * @param Closure(): array<string, mixed> $world
     * @param list<int> $relayCounts
     * @return array<string, array{transcript: list<string>, stats: array<string, int>}>
     */
    private function runProperties(string $kind, string $store, Closure $world, int $steps, array $relayCounts): array
    {
        $label = self::KINDS[$kind] . " ({$store})";
        $runs = [];
        $totals = [];
        foreach (self::CONFIGS as $name => $config) {
            foreach ($relayCounts as $relays) {
                foreach (self::SEEDS as $seed) {
                    $key = "{$name}/{$relays}/{$seed}";
                    $runs[$key] = $this->runProperty($seed, $relays, $config, $steps, $world(), "{$label} {$key}");
                    foreach ($runs[$key]['stats'] as $stat => $value) {
                        $totals[$name][$stat] = ($totals[$name][$stat] ?? 0) + $value;
                    }
                }
            }
        }
        $this->assertEquals(0, $totals['lock']['duplicates_without_death'], "{$label}: com trava e sem pausa, nenhuma duplicata sem morte");
        if ($steps === self::STEPS_MEMORY) {
            // The generator reaches what it is meant to (the short sequences of the servers are compared with memory instead)
            $this->assertTrue($totals['lock']['deaths'] > 0 && $totals['lock']['denied'] > 0 && $totals['lock']['dead_holder_waits'] > 0, "{$label}: com trava, houve mortes, negações e esperas pelo prazo de um morto: " . json_encode($totals['lock']));
            $this->assertTrue($totals['lock+pauses']['lost'] > 0 && $totals['lock+pauses']['stale_deliveries'] > 0, "{$label}: com pausas, houve trava perdida e entrega depois do prazo: " . json_encode($totals['lock+pauses']));
            $this->assertTrue($totals['no-lock']['duplicates_without_death'] > 0 && $totals['no-lock']['blocked_reopenings'] > 0, "{$label}: sem trava, o mesmo gerador mostra a duplicata sem morte e a falha tardia que o D3 barra: " . json_encode($totals['no-lock']));
            if ($store === 'memória') {
                $this->totals[$kind] = $totals;
                $summary = [];
                foreach ($totals as $name => $stats) {
                    $summary[] = $name . ' ' . json_encode($stats);
                }
                fwrite(STDOUT, '    [065] ' . self::KINDS[$kind] . ', propriedades (' . count($runs) . " sequências):\n      " . implode("\n      ", $summary) . "\n");
            }
        }

        return $runs;
    }

    private function testTheFailureMarkOf215Reopens(): void
    {
        $reopened = 0;
        foreach ([2, 3] as $relays) {
            foreach (self::SEEDS as $seed) {
                $world = $this->world('messages', null);
                $world['outbox'] = new Module50LegacyMarkFailedOutbox($world['outbox']);
                $run = $this->runProperty($seed, $relays, ['lock' => false, 'stalls' => false, 'legacy' => true], self::STEPS_MEMORY, $world, "2.15 {$relays}/{$seed}");
                $reopened += $run['stats']['reopened'];
            }
        }
        $this->assertTrue($reopened > 0, 'contraprova: com a marca de falha de 2.15.0, o mesmo gerador sem trava reabre entregas: ' . $reopened);
        $this->totals['legacy'] = ['reopened' => $reopened];
        fwrite(STDOUT, "    [065] contraprova 2.15.0 (relay sem trava, 6 sequências): {$reopened} entregas reabertas; 2.16.0: 0\n");
    }

    /**
     * One property sequence: dispatcher processes on fibers over one outbox, scheduled by a seeded random generator.
     *
     * @param array{lock: bool, stalls: bool, legacy: bool} $config
     * @param array<string, mixed> $w The adapter of the outbox
     * @return array{transcript: list<string>, stats: array<string, int>}
     */
    private function runProperty(int $seed, int $relays, array $config, int $steps, array $w, string $label): array
    {
        $random = new Randomizer(new Mt19937($seed + 7919 * $relays + ($config['lock'] ? 104729 : 0) + ($config['stalls'] ? 1299709 : 0)));
        $innerLock = $config['lock'] ? ($w['lock'])() : null;
        $s = new stdClass();
        $s->now = $this->at(self::T0);
        $s->published = [];       // id => [queue, number]
        $s->procs = [];           // pid => [owner, generation, run, fiber, alive, lock name]
        $s->dead = [];            // owner => true
        $s->sends = [];           // [owner, id, confirmed, marked]
        $s->awaiting = [];        // owner => [id => index of the delivery]
        $s->broker = [];          // [id, owner] of every delivery that succeeded
        $s->delivered = [];       // id => true while observed delivered
        $s->exhausted = [];       // id => true once observed exhausted
        $s->faultless = false;
        $s->transcript = [];
        $s->stats = ['runs' => 0, 'deaths' => 0, 'denied' => 0, 'lost' => 0, 'dead_holder_waits' => 0, 'duplicates' => 0, 'duplicates_without_death' => 0, 'blocked_reopenings' => 0, 'reopened' => 0, 'stale_deliveries' => 0, 'exhausted_then_delivered' => 0];
        $maxAttempts = [null, 3, 4][$random->getInt(0, 2)];
        $clock = static fn(): DateTimeImmutable => $s->now;
        $lockName = 'property-lock';

        $hook = static function (string $op, string $owner, mixed $payload) use ($s, $w, $innerLock, $lockName): void {
            Fiber::suspend($op); // the scheduler resumes the process here, or drops it (its death)
            if ($op === 'send' && $innerLock !== null && $innerLock->holder($lockName, $s->now) !== $owner) {
                $s->stats['stale_deliveries']++;
            }
            if (($op === 'delivered' || $op === 'failed') && is_object($payload)) {
                $id = ($w['idOf'])($payload);
                $index = $s->awaiting[$owner][$id] ?? null;
                if ($index !== null) {
                    $s->sends[$index][3] = true;
                    unset($s->awaiting[$owner][$id]);
                }
                if ($op === 'failed' && (($w['state'])($id)['status'] ?? null) === 'delivered') {
                    $s->stats['blocked_reopenings']++;
                }
            }
        };
        $broker = static function (string $owner, string $id) use ($s, $random): bool {
            $confirmed = $s->faultless || $random->getInt(1, 100) <= 75;
            $s->sends[] = [$owner, $id, $confirmed, false];
            $s->awaiting[$owner][$id] = array_key_last($s->sends);
            if ($confirmed) {
                $s->broker[] = [$id, $owner];
            }

            return $confirmed;
        };
        $lock = $innerLock === null ? null : new Module50HookedLock($innerLock, $hook, static fn(string $owner): bool => isset($s->dead[$owner]));

        $publish = function (int $count) use ($s, $random, $w): void {
            $lines = [];
            for ($i = 0; $i < $count; $i++) {
                $n = count($s->published) + 1;
                $key = ['k1', 'k2', 'k3', null][$random->getInt(0, 3)];
                ($w['publish'])([$n => $key], $s->now);
                $s->published[($w['id'])($n, $key)] = [$key ?? 'solo-' . $n, $n];
                $lines[] = $n . ($key === null ? '' : ':' . $key);
            }
            $s->transcript[] = 'publish ' . implode(' ', $lines);
        };
        $spawn = function (int $pid) use ($s, $w, $hook, $broker, $maxAttempts, $clock, $lock, $random, $lockName): void {
            $generation = ($s->procs[$pid][1] ?? -1) + 1;
            $owner = sprintf('p%d-g%d', $pid, $generation);
            $dispatcher = ($w['dispatcher'])(($w['wrap'])($w['outbox'], $owner, $hook), $hook, $broker, [
                'maxAttempts' => $maxAttempts,
                'batch' => $random->getInt(1, 3),
                'clock' => $clock,
                'lock' => $lock,
                'lease' => self::LEASE,
                'owner' => $owner,
                'name' => $lockName,
            ]);
            $s->procs[$pid] = [$owner, $generation, $dispatcher['run'], null, true];
            $s->transcript[] = 'spawn ' . $owner;
        };
        $short = static fn(array $ids): string => implode(',', array_map(static fn(string $id): string => (string) $s->published[$id][1], $ids));
        $finish = function (int $pid, array $report) use ($s, $innerLock, $lockName, $short): void {
            $s->stats['runs']++;
            $s->stats['denied'] += $report['denied'] ? 1 : 0;
            $s->stats['lost'] += $report['lost'] ? 1 : 0;
            $holder = $report['denied'] && $innerLock !== null ? $innerLock->holder($lockName, $s->now) : null;
            $s->stats['dead_holder_waits'] += $holder !== null && isset($s->dead[$holder]) ? 1 : 0;
            $s->transcript[] = sprintf(
                'done %s delivered [%s] failed [%s] exhausted [%s]%s%s',
                $s->procs[$pid][0],
                $short($report['delivered']),
                $short($report['failed']),
                $short($report['exhausted']),
                $report['denied'] ? ' denied' : '',
                $report['lost'] ? ' lost' : ''
            );
            $s->procs[$pid][3] = null;
        };
        $resume = function (int $pid) use ($s, $random, $finish): void {
            $fiber = $s->procs[$pid][3];
            if ($fiber === null) {
                $run = $s->procs[$pid][2];
                $limit = $random->getInt(1, 10);
                $fiber = new Fiber(static fn(): array => $run($limit));
                $s->procs[$pid][3] = $fiber;
                $op = $fiber->start();
            } else {
                $op = $fiber->resume();
            }
            if ($fiber->isTerminated()) {
                $finish($pid, $fiber->getReturn());
            } else {
                $s->transcript[] = $s->procs[$pid][0] . ' ' . $op;
            }
        };
        $kill = function (int $pid) use ($s): void {
            [$owner, , , $fiber] = $s->procs[$pid];
            $s->dead[$owner] = true;
            $s->procs[$pid][4] = false;
            $s->stats['deaths']++;
            // A killed process is never resumed. Dropping its suspended fiber unwinds it without running any code but the
            // `finally` blocks (no catch sees it, and the dispatchers catch every Throwable of a delivery), and the only one,
            // the release of the lock, does nothing for a dead owner: its lock stays held until the lease runs out.
            $s->procs[$pid][3] = null;
            if ($fiber instanceof Fiber) {
                $this->assertTrue($fiber->isSuspended(), 'o processo morre suspenso');
                $fiber = null;
            }
            unset($s->awaiting[$owner]);
            $s->transcript[] = 'kill ' . $owner;
        };
        $observe = function () use ($s, $w, $maxAttempts, $config, $label): void {
            $reopened = 0;
            foreach (array_keys($s->published) as $id) {
                $state = ($w['state'])($id) ?? throw new RuntimeException("{$label}: entry {$id} vanished");
                if ($state['status'] === 'delivered') {
                    $s->delivered[$id] = true;
                } elseif (isset($s->delivered[$id])) {
                    $reopened++;
                    unset($s->delivered[$id]);
                }
                if ($state['status'] !== 'delivered' && $maxAttempts !== null && $state['attempts'] >= $maxAttempts) {
                    $s->exhausted[$id] = true;
                }
            }
            $s->stats['reopened'] += $reopened;
            if (!$config['legacy']) {
                $this->assertEquals(0, $reopened, "{$label}: nenhuma entrega reaberta (D3)");
            }
        };
        $inFlight = static function () use ($s): bool {
            foreach ($s->procs as $proc) {
                if ($proc[4] && $proc[3] !== null) {
                    return true;
                }
            }

            return false;
        };
        $tick = function (int $max) use ($s, $random): void {
            $s->now = $s->now->modify(sprintf('+%d seconds +%d microseconds', $random->getInt(1, $max), $random->getInt(0, 999999)));
            $s->transcript[] = 'tick ' . LeaseLockSupport::toText($s->now);
        };

        $publish(8);
        for ($pid = 0; $pid < $relays; $pid++) {
            $spawn($pid);
        }
        for ($step = 0; $step < $steps; $step++) {
            $alive = array_keys(array_filter($s->procs, static fn(array $p): bool => $p[4]));
            $dead = array_keys(array_filter($s->procs, static fn(array $p): bool => !$p[4]));
            $roll = $random->getInt(1, 100);
            if ($roll <= 10) {
                $publish($random->getInt(1, 3));
            } elseif ($roll <= 24 && ($config['stalls'] || !$inFlight())) {
                // Without pauses the clock moves only while no live process is in the middle of a run, so no live lease runs
                // out (a dead holder's does); with pauses it moves at any time, past the lease too.
                $tick($config['stalls'] ? self::LEASE + 15 : 20);
            } elseif ($roll <= 28 && $alive !== []) {
                $kill($alive[$random->getInt(0, count($alive) - 1)]);
            } elseif ($roll <= 34 && $dead !== []) {
                $spawn($dead[$random->getInt(0, count($dead) - 1)]);
            } elseif ($alive !== []) {
                $resume($alive[$random->getInt(0, count($alive) - 1)]);
            } else {
                $spawn($dead[0]);
            }
            $observe();
        }

        // Drain: no more deaths, pauses nor refusals; every process restarted; runs until nothing is open
        $s->faultless = true;
        foreach (array_keys(array_filter($s->procs, static fn(array $p): bool => !$p[4])) as $pid) {
            $spawn($pid);
        }
        $done = false;
        for ($round = 0; $round < 400 && !$done; $round++) {
            $busy = array_keys(array_filter($s->procs, static fn(array $p): bool => $p[3] !== null));
            if ($busy !== []) {
                $resume($busy[0]);
            } elseif (!($w['open'])($maxAttempts)) {
                $done = true;
            } else {
                $before = $s->stats['denied'];
                do {
                    $resume(0);
                } while ($s->procs[0][3] !== null);
                if ($s->stats['denied'] > $before) {
                    $tick(self::LEASE + 1); // a dead holder: wait for its lease to run out
                }
            }
            $observe();
        }
        $this->assertTrue($done, "{$label}: a drenagem termina");

        // The model: every published entry delivered or exhausted; every delivery reached the other side; nothing else did
        $inBroker = array_fill_keys(array_column($s->broker, 0), true);
        $final = [];
        foreach ($s->published as $id => [, $n]) {
            $state = ($w['state'])($id) ?? throw new RuntimeException('no entry');
            $delivered = $state['status'] === 'delivered';
            $exhausted = !$delivered && $maxAttempts !== null && $state['attempts'] >= $maxAttempts;
            $this->assertTrue($delivered || $exhausted, "{$label}: {$n} entregue ou esgotada");
            $this->assertTrue(!$delivered || isset($inBroker[$id]), "{$label}: {$n} entregue chegou ao outro lado");
            if ($delivered && isset($s->exhausted[$id])) {
                $s->stats['exhausted_then_delivered']++;
            }
            $final[] = $n . ' ' . $state['status'] . ' ' . $state['attempts'];
        }
        $this->assertEquals([], array_values(array_diff(array_keys($inBroker), array_keys($s->published))), "{$label}: do outro lado, só o que foi publicado");

        // The order of the first copies of each queue (the exhausted ones aside, RN-04)
        foreach (['k1', 'k2', 'k3'] as $queue) {
            $firsts = [];
            foreach ($s->broker as [$id]) {
                if ($s->published[$id][0] === $queue && !isset($s->exhausted[$id]) && !in_array($s->published[$id][1], $firsts, true)) {
                    $firsts[] = $s->published[$id][1];
                }
            }
            $sorted = $firsts;
            sort($sorted);
            $this->assertEquals($sorted, $firsts, "{$label}: a ordem das primeiras cópias da fila {$queue}");
        }

        // Duplicates, and, with the lock and no pause, the guarantees of a single live process
        $copies = [];
        foreach ($s->sends as $index => [, $id, $confirmed]) {
            if ($confirmed) {
                $copies[$id][] = $index;
            }
        }
        foreach ($copies as $indexes) {
            $s->stats['duplicates'] += count($indexes) - 1;
            for ($i = 0; $i < count($indexes) - 1; $i++) {
                $earlier = $s->sends[$indexes[$i]];
                if ($earlier[3] || !isset($s->dead[$earlier[0]])) {
                    $s->stats['duplicates_without_death']++;
                }
            }
        }
        if ($config['lock'] && !$config['stalls']) {
            $this->assertEquals(0, $s->stats['stale_deliveries'], "{$label}: toda entrega é do dono da trava");
            $this->assertEquals(0, $s->stats['blocked_reopenings'], "{$label}: nenhuma falha tardia sobre uma entrega");
            $this->assertEquals(0, $s->stats['duplicates_without_death'], "{$label}: uma entrega só se repete quando quem a fez morreu antes de marcá-la");
            foreach ($s->published as $id => [, $n]) {
                $marked = count(array_filter($s->sends, static fn(array $send): bool => $send[1] === $id && $send[3]));
                $this->assertEquals($marked, ($w['state'])($id)['attempts'] ?? null, "{$label}: as tentativas de {$n} são exatamente as entregas marcadas");
            }
        }
        $s->transcript[] = 'final ' . implode(' | ', $final);
        $s->transcript[] = 'broker ' . $short(array_column($s->broker, 0));
        $s->transcript[] = 'stats ' . json_encode($s->stats);

        return ['transcript' => $s->transcript, 'stats' => $s->stats];
    }

    // =====================================================================
    // The outboxes, as adapters
    // =====================================================================

    /**
     * The adapter of one outbox (memory, or over $pdo with a fresh schema): publish by number and queue, the state of an
     * entry by id, the marks, the purge, a dispatcher with its options, the hooked wrapper of a process.
     *
     * @return array<string, mixed>
     */
    private function world(string $kind, ?PDO $pdo): array
    {
        if ($pdo !== null) {
            self::resetSchema($pdo);
        }
        $memoryLock = new InMemoryLeaseLock();
        $common = [
            'kind' => $kind,
            'pdo' => $pdo !== null,
            // One lock store: every handle the same instance in memory, a new PdoLeaseLock over the same table otherwise
            'lock' => $pdo === null ? static fn(): InMemoryLeaseLock => $memoryLock : static fn(): PdoLeaseLock => new PdoLeaseLock($pdo),
            'transactional' => $pdo === null ? static fn(Closure $fn): mixed => $fn() : static fn(Closure $fn): mixed => (new PdoTransactionManager($pdo))->transactional($fn),
        ];

        return $common + match ($kind) {
            'messages' => $this->messagesAdapter($pdo),
            'domain_events' => $this->domainEventsAdapter($pdo),
            'effects' => $this->effectsAdapter($pdo),
        };
    }

    /** @return array<string, mixed> */
    private function messagesAdapter(?PDO $pdo): array
    {
        $outbox = $pdo === null ? new InMemoryMessageOutbox() : new PdoMessageOutbox($pdo);
        $ms = 1763546400000;
        $publisher = new MessagePublisher($outbox, new UlidGenerator(static function () use (&$ms): int {
            return $ms++;
        }), fn(): DateTimeImmutable => $this->at(self::T0));
        $state = static function (?MessageOutboxEntry $e): ?array {
            return $e === null ? null : ['status' => $e->status->value, 'attempts' => $e->attempts, 'error' => $e->lastError, 'delivered' => self::text($e->deliveredAt), 'last' => self::text($e->lastAttemptAt)];
        };

        return [
            'outbox' => $outbox,
            'table' => 'message_outbox',
            'defaultName' => OutboxRelay::LOCK_NAME,
            'chunk' => PdoMessageOutbox::PURGE_CHUNK,
            'dedupes' => true,
            'missing' => MessagingPersistenceException::class,
            'id' => static fn(int $n, ?string $key): string => self::mid($n),
            'publish' => function (array $messages, DateTimeImmutable $at) use ($publisher): int {
                $list = [];
                foreach ($messages as $n => $key) {
                    $list[] = new Message('prop.fact.v1', ['n' => $n], $key, null, [], self::mid($n), $at);
                }

                return $publisher->publish(...$list);
            },
            'state' => static fn(string $id): ?array => $state($outbox->find($id)),
            'entry' => static fn(string $id): ?MessageOutboxEntry => $outbox->find($id),
            'ghost' => fn(): MessageOutboxEntry => new MessageOutboxEntry(self::mid(59), new Message('prop.fact.v1', [], null, null, [], self::mid(59), $this->at(self::T0)), $this->at(self::T0)),
            'markDelivered' => static fn(MessageOutboxEntry $e, DateTimeImmutable $at) => $outbox->markDelivered($e, $at),
            'markFailed' => static fn(MessageOutboxEntry $e, string $reason, DateTimeImmutable $at) => $outbox->markFailed($e, $reason, $at),
            'purge' => static fn(DateTimeImmutable $before, ?int $limit): int => $limit === null ? $outbox->purgeDeliveredBefore($before) : $outbox->purgeDeliveredBefore($before, $limit),
            'open' => static fn(?int $max): bool => $outbox->fetchPending(1000, $max) !== [],
            'idOf' => static fn(MessageOutboxEntry $e): string => $e->messageId(),
            'wrap' => static fn(object $inner, string $owner, Closure $hook): Module50HookedOutbox => new Module50HookedOutbox($inner, $owner, $hook),
            'dispatcher' => static function (object $outbox, Closure $hook, Closure $broker, array $o): array {
                $relay = new OutboxRelay(
                    $outbox,
                    new Module50HookedTransport((string) ($o['owner'] ?? 'relay'), $hook, $broker),
                    $o['maxAttempts'] ?? null,
                    $o['batch'] ?? 100,
                    $o['clock'] ?? null,
                    $o['lock'] ?? null,
                    $o['lease'] ?? OutboxRelay::DEFAULT_LEASE_SECONDS,
                    $o['owner'] ?? null,
                    $o['name'] ?? null
                );

                return [
                    'run' => static function (int $limit) use ($relay): array {
                        $report = $relay->relayPending($limit);

                        return ['delivered' => $report->deliveredIds(), 'failed' => array_keys($report->getFailures()), 'exhausted' => $report->getExhausted(), 'denied' => $report->isLockDenied(), 'lost' => $report->isLockLost(), 'report' => $report];
                    },
                    'owner' => $relay->getLockOwner(),
                    'name' => $relay->getLockName(),
                ];
            },
        ];
    }

    /** @return array<string, mixed> */
    private function domainEventsAdapter(?PDO $pdo): array
    {
        $outbox = $pdo === null ? new InMemoryDomainEventOutbox() : new PdoDomainEventOutbox($pdo);
        $event = fn(int $n, ?string $key): DomainEvent => new DomainEvent('prop.entered', 'prop_trigger', DomainEventKind::ENTER, 'App\\Prop', $key ?? 'solo-' . $n, $this->at(self::T0)->modify("+{$n} microseconds"));
        $state = static function (?DomainEventOutboxEntry $e): ?array {
            return $e === null ? null : ['status' => $e->status->value, 'attempts' => $e->attempts, 'error' => $e->lastError, 'delivered' => self::text($e->deliveredAt), 'last' => self::text($e->lastAttemptAt)];
        };

        return [
            'outbox' => $outbox,
            'table' => 'domain_event_outbox',
            'defaultName' => DomainEventDispatcher::LOCK_NAME,
            'chunk' => PdoDomainEventOutbox::PURGE_CHUNK,
            'dedupes' => true,
            'missing' => DomainEventPersistenceException::class,
            'id' => static fn(int $n, ?string $key): string => $event($n, $key)->getIdempotencyKey(),
            'publish' => static function (array $events) use ($outbox, $event): int {
                $entries = [];
                foreach ($events as $n => $key) {
                    $entries[] = new DomainEventOutboxEntry(self::mid($n), $event($n, $key));
                }

                return $outbox->enqueue($entries);
            },
            'state' => static fn(string $id): ?array => $state($outbox->findByIdempotencyKey($id)),
            'entry' => static fn(string $id): ?DomainEventOutboxEntry => $outbox->findByIdempotencyKey($id),
            'ghost' => static fn(): DomainEventOutboxEntry => new DomainEventOutboxEntry(self::mid(59), $event(59, null)),
            'markDelivered' => static fn(DomainEventOutboxEntry $e, DateTimeImmutable $at) => $outbox->markDelivered($e, $at),
            'markFailed' => static fn(DomainEventOutboxEntry $e, string $reason, DateTimeImmutable $at) => $outbox->markFailed($e, $reason, $at),
            'purge' => static fn(DateTimeImmutable $before, ?int $limit): int => $limit === null ? $outbox->purgeDeliveredBefore($before) : $outbox->purgeDeliveredBefore($before, $limit),
            'open' => static fn(?int $max): bool => $outbox->fetchPending(1000, $max) !== [],
            'idOf' => static fn(DomainEventOutboxEntry $e): string => $e->idempotencyKey(),
            'wrap' => static fn(object $inner, string $owner, Closure $hook): Module50HookedDomainEventOutbox => new Module50HookedDomainEventOutbox($inner, $owner, $hook),
            'dispatcher' => static function (object $outbox, Closure $hook, Closure $broker, array $o): array {
                $owner = (string) ($o['owner'] ?? 'dispatcher');
                $handlers = (new DomainEventHandlerRegistry())->register('prop.entered', static function (DomainEvent $e, string $key) use ($hook, $broker, $owner): void {
                    $hook('send', $owner, [$key]);
                    $confirmed = $broker($owner, $key);
                    $hook('sent', $owner, [$key]);
                    if (!$confirmed) {
                        throw new RuntimeException('the handler refused it');
                    }
                });
                $dispatcher = new DomainEventDispatcher($outbox, $handlers, $o['maxAttempts'] ?? null, $o['clock'] ?? null, $o['lock'] ?? null, $o['lease'] ?? DomainEventDispatcher::DEFAULT_LEASE_SECONDS, $o['owner'] ?? null, $o['name'] ?? null);

                return [
                    'run' => static function (int $limit) use ($dispatcher): array {
                        $report = $dispatcher->dispatchPending($limit);

                        return ['delivered' => array_map(static fn(DomainEventOutboxEntry $e): string => $e->idempotencyKey(), $report->getDelivered()), 'failed' => array_keys($report->getFailures()), 'exhausted' => [], 'denied' => $report->isLockDenied(), 'lost' => $report->isLockLost(), 'report' => $report];
                    },
                    'owner' => $dispatcher->getLockOwner(),
                    'name' => $dispatcher->getLockName(),
                ];
            },
        ];
    }

    /** @return array<string, mixed> */
    private function effectsAdapter(?PDO $pdo): array
    {
        $outbox = $pdo === null ? new InMemoryEffectOutbox() : new PdoEffectOutbox(new PdoMachineSchema($pdo));
        $instance = static fn(int $n, ?string $key): string => $key ?? 'solo-' . $n;
        $find = static function (string $id) use ($outbox): ?OutboxEntry {
            $instanceId = substr($id, 0, (int) strrpos(substr($id, 0, (int) strrpos($id, ':')), ':'));
            foreach ($outbox->findByInstance($instanceId) as $entry) {
                if ($entry->effectKey() === $id) {
                    return $entry;
                }
            }

            return null;
        };
        $entry = fn(int $n, ?string $key): OutboxEntry => new OutboxEntry($instance($n, $key), $n, 0, 'prop', 1, new Effect('prop_action', ['n' => $n], 'main', EffectPhase::ENTRY, 'open', null), OutboxStatus::PENDING, 0, null, $this->at(self::T0)->modify("+{$n} microseconds"));
        $state = static function (?OutboxEntry $e): ?array {
            return $e === null ? null : ['status' => $e->status->value, 'attempts' => $e->attempts, 'error' => $e->lastError, 'delivered' => self::text($e->deliveredAt), 'last' => self::text($e->lastAttemptAt)];
        };

        return [
            'outbox' => $outbox,
            'table' => 'state_machine_outbox',
            'defaultName' => EffectDispatcher::LOCK_NAME,
            'chunk' => PdoEffectOutbox::PURGE_CHUNK,
            'dedupes' => false,
            'missing' => MachinePersistenceException::class,
            'id' => static fn(int $n, ?string $key): string => sprintf('%s:%d:0', $instance($n, $key), $n),
            'publish' => static function (array $effects) use ($outbox, $entry): int {
                $outbox->enqueue(array_map($entry, array_keys($effects), array_values($effects)));

                return count($effects);
            },
            'state' => static fn(string $id): ?array => $state($find($id)),
            'entry' => static fn(string $id): ?OutboxEntry => $find($id),
            'ghost' => static fn(): OutboxEntry => $entry(59, 'ghost'),
            'markDelivered' => static fn(OutboxEntry $e, DateTimeImmutable $at) => $outbox->markDelivered($e, $at),
            'markFailed' => static fn(OutboxEntry $e, string $reason, DateTimeImmutable $at) => $outbox->markFailed($e, $reason, $at),
            'purge' => static fn(DateTimeImmutable $before, ?int $limit): int => $limit === null ? $outbox->purgeDeliveredBefore($before) : $outbox->purgeDeliveredBefore($before, $limit),
            'open' => static fn(?int $max): bool => $outbox->fetchPending(1000, $max) !== [],
            'idOf' => static fn(OutboxEntry $e): string => $e->effectKey(),
            'wrap' => static fn(object $inner, string $owner, Closure $hook): Module50HookedEffectOutbox => new Module50HookedEffectOutbox($inner, $owner, $hook),
            'dispatcher' => static function (object $outbox, Closure $hook, Closure $broker, array $o): array {
                $owner = (string) ($o['owner'] ?? 'dispatcher');
                $handlers = (new EffectHandlerRegistry())->register('prop_action', static function (Effect $effect, string $key) use ($hook, $broker, $owner): void {
                    $hook('send', $owner, [$key]);
                    $confirmed = $broker($owner, $key);
                    $hook('sent', $owner, [$key]);
                    if (!$confirmed) {
                        throw new RuntimeException('the handler refused it');
                    }
                });
                $dispatcher = new EffectDispatcher($outbox, $handlers, $o['maxAttempts'] ?? null, $o['clock'] ?? null, $o['lock'] ?? null, $o['lease'] ?? EffectDispatcher::DEFAULT_LEASE_SECONDS, $o['owner'] ?? null, $o['name'] ?? null);

                return [
                    'run' => static function (int $limit) use ($dispatcher): array {
                        $report = $dispatcher->dispatchPending($limit);

                        return ['delivered' => array_map(static fn(OutboxEntry $e): string => $e->effectKey(), $report->getDelivered()), 'failed' => array_keys($report->getFailures()), 'exhausted' => [], 'denied' => $report->isLockDenied(), 'lost' => $report->isLockLost(), 'report' => $report];
                    },
                    'owner' => $dispatcher->getLockOwner(),
                    'name' => $dispatcher->getLockName(),
                ];
            },
        ];
    }

    // =====================================================================
    // The databases
    // =====================================================================

    /**
     * @param Closure(): PDO $connect
     * @param array<int, list<string>> $locks
     * @param array<string, array<int, list<string>>> $retention
     * @param array<string, array<string, array{transcript: list<string>, stats: array<string, int>}>> $memory
     * @param list<int> $relayCounts
     */
    private function runDatabase(string $label, string $family, Closure $connect, array $locks, array $retention, array $memory, int $steps, array $relayCounts): void
    {
        $pdo = $connect();
        self::resetSchema($pdo);
        $this->lockContract($label, static function () use ($pdo, $connect, $family): array {
            self::resetSchema($pdo);
            // SQLite in memory has one connection; the servers get a second one
            $other = $family === 'sqlite' ? $pdo : $connect();

            return [new PdoLeaseLock($pdo), new PdoLeaseLock($other)];
        });
        foreach (self::SEEDS as $seed) {
            self::resetSchema($pdo);
            $this->assertEquals($locks[$seed], $this->lockSequence($seed, new PdoLeaseLock($pdo)), "{$label}: a sequência de trava {$seed} é a da memória");
        }
        foreach (array_keys(self::KINDS) as $kind) {
            $this->runBehaviour($kind, $label, fn(): array => $this->world($kind, $pdo));
            foreach (self::SEEDS as $seed) {
                $this->assertEquals($retention[$kind][$seed], $this->retentionSequence($seed, $this->world($kind, $pdo)), "{$label}: a sequência de retenção {$seed} de " . self::KINDS[$kind] . ' é a da memória');
            }
            $database = $this->runProperties($kind, $label, fn(): array => $this->world($kind, $pdo), $steps, $relayCounts);
            foreach ($memory[$kind] as $key => $run) {
                $this->assertEquals($run['transcript'], $database[$key]['transcript'], "{$label}: a sequência {$key} de " . self::KINDS[$kind] . ' é a da memória');
            }
            $this->testMigrationOf215($label, $kind, $pdo);
        }
        $this->testPdoLockDetails($label, $pdo);
    }

    private function testPdoLockDetails(string $label, PDO $pdo): void
    {
        self::resetSchema($pdo);
        $lock = new PdoLeaseLock($pdo);
        $this->assertTrue($lock->getPdo() === $pdo && $lock->getTable() === 'lease_lock', "{$label}: conexão e tabela padrão");
        $this->assertEquals(array_combine(PdoLeaseLock::COLUMNS, PdoLeaseLock::COLUMNS), $lock->getColumns());
        $this->assertEquals(['lock_name', 'owner', 'locked_until', 'acquired_at'], PdoLeaseLock::COLUMNS);
        $this->assertTrue($lock->tryAcquire('a', 'x', $this->at(self::T0), 30) && $lock->tryAcquire('b', 'y', $this->at(self::T0), 30), "{$label}: dois nomes numa tabela são duas travas");
        $this->assertEquals(2, (int) $pdo->query('SELECT COUNT(*) FROM lease_lock')->fetchColumn());
        $lock->release('a', 'x');
        $lock->release('b', 'y');
        $this->assertTrue($lock->tryAcquire(str_repeat('n', 120), str_repeat('o', 120), $this->at(self::T0), 1), "{$label}: nome e dono de 120 caracteres cabem nas colunas");
        foreach ([['', 'o', 30], ['n', '', 30], [str_repeat('n', 121), 'o', 30], ['n', str_repeat('o', 121), 30], ['n', 'o', 0]] as [$badName, $badOwner, $badLease]) {
            $this->assertThrows(InvalidArgumentException::class, fn() => $lock->tryAcquire($badName, $badOwner, $this->at(self::T0), $badLease), "{$label}: a trava PDO confere nome, dono e prazo antes de ir ao banco");
        }
        $this->assertThrows(InvalidArgumentException::class, fn() => new PdoLeaseLock($pdo, 'bad name'), "{$label}: tabela inválida");
        $this->assertThrows(InvalidArgumentException::class, fn() => new PdoLeaseLock($pdo, 'lease_lock', 'bad schema'), "{$label}: schema inválido");
        $e = $this->assertThrows(InvalidArgumentException::class, fn() => new PdoLeaseLock($pdo, columns: ['holder' => 'h']), "{$label}: coluna desconhecida");
        $this->assertEquals('Unknown lease lock column "holder". Expected one of: lock_name, owner, locked_until, acquired_at.', $e->getMessage());
        $e = $this->assertThrows(InvalidArgumentException::class, fn() => new PdoLeaseLock($pdo, columns: ['owner' => 'bad col']), "{$label}: coluna real inválida");
        $this->assertEquals('Lease lock column "bad col" (canonical owner) is not a plain SQL identifier.', $e->getMessage());
        $this->assertEquals('main.lease_lock', (new PdoLeaseLock($pdo, schema: 'main'))->getTable(), "{$label}: tabela qualificada pelo schema");

        // A consumer table with other names
        $pdo->exec('CREATE TABLE m50_locks (lock_key VARCHAR(120) NOT NULL, held_by VARCHAR(120) NOT NULL, lease_end VARCHAR(26) NOT NULL, taken_at VARCHAR(26) NOT NULL, note VARCHAR(20), CONSTRAINT pk_m50_locks PRIMARY KEY (lock_key))');
        $mapped = static fn(PDO $connection): PdoLeaseLock => new PdoLeaseLock($connection, 'm50_locks', '', ['lock_name' => 'lock_key', 'owner' => 'held_by', 'locked_until' => 'lease_end', 'acquired_at' => 'taken_at']);
        $this->lockContract("{$label} (mapa de colunas)", static fn(): array => [$mapped($pdo), $mapped($pdo)]);
        $this->assertTrue($mapped($pdo)->tryAcquire('orders', 'x', $this->at(self::T0), 5));
        $this->assertEquals(['orders', 'x', '2026-11-19 10:00:05.000000'], array_map('strval', $pdo->query('SELECT lock_key, held_by, lease_end FROM m50_locks')->fetch(PDO::FETCH_NUM)), "{$label}: as colunas reais");
        $pdo->exec('DROP TABLE m50_locks');

        // A missing table: the SQLSTATE, never the driver message
        $missing = new PdoLeaseLock($pdo, 'm50_missing');
        $e = $this->assertThrows(LeaseLockException::class, fn() => $missing->tryAcquire('x', 'a', $this->at(self::T0), 5), "{$label}: tabela que não existe");
        $this->assertTrue(preg_match('/^Lease lock statement failed: acquire of lock "x" on m50_missing \(SQLSTATE [0-9A-Z]{5}, driver code -?\d+\)\. The driver message is not repeated\.$/', $e->getMessage()) === 1, "{$label}: a mensagem: " . $e->getMessage());
        $this->assertTrue($e->getSqlState() !== null && $e->getDriverCode() !== null && !$e->isIntegrityViolation());
        $this->assertThrows(LeaseLockException::class, fn() => $missing->release('x', 'a'));
        $this->assertThrows(LeaseLockException::class, fn() => $missing->lease('x'));
    }

    /**
     * A table of the 2.15 DDL (no retention index): purged without the index, then with the index of the header of the
     * DDL; the DDL and the lock DDL run again, idempotent.
     */
    private function testMigrationOf215(string $label, string $kind, PDO $pdo): void
    {
        [$constraint, $table] = self::RETENTION_INDEXES[$kind];
        self::dropSchema($pdo);
        $ddl = (string) file_get_contents(self::DDLS[$kind]);
        $old = (string) preg_replace('/^\s+CONSTRAINT ' . $constraint . ' UNIQUE \([^)]+\),\n/m', '', $ddl);
        $this->assertTrue($old !== $ddl && !str_contains(self::statements($old), $constraint), "{$label}: o DDL de 2.15 de {$table}");
        self::runDdl($pdo, $old);
        $w = ['kind' => $kind] + $this->adapterOver($kind, $pdo);
        ($w['publish'])([71 => 'm', 72 => 'm', 73 => null], $this->at(self::T0));
        foreach ([71, 72] as $n) {
            ($w['markDelivered'])(($w['entry'])(($w['id'])($n, 'm')), $this->at('2026-11-01 00:00:00'));
        }
        $this->assertEquals(1, ($w['purge'])($this->at(self::T0), 1), "{$label}: {$table} de 2.15, sem o índice, é purgada");
        $this->assertTrue(preg_match('/^--\s+(CREATE UNIQUE INDEX ' . $constraint . ' ON ' . $table . ' \([a-z_, ]+\));$/m', $ddl, $index) === 1, "{$label}: o índice opcional de {$table} está no cabeçalho do DDL");
        $pdo->exec($index[1]);
        $this->assertEquals(1, ($w['purge'])($this->at(self::T0), 5), "{$label}: {$table}, com o índice");
        self::runDdl($pdo, $ddl);
        self::runDdl($pdo, (string) file_get_contents(self::DDLS['lock']));
        self::runDdl($pdo, (string) file_get_contents(self::DDLS['lock']));
        $this->assertTrue(($w['state'])(($w['id'])(73, null)) !== null && ($w['state'])(($w['id'])(71, 'm')) === null, "{$label}: rodar os DDLs de novo não toca a outbox (idempotentes)");
        $this->assertTrue((new PdoLeaseLock($pdo))->tryAcquire('a', 'b', $this->at(self::T0), 5), "{$label}: a trava funciona na tabela criada depois");
    }

    /** The adapter over a schema that is already there (no reset). @return array<string, mixed> */
    private function adapterOver(string $kind, PDO $pdo): array
    {
        return match ($kind) {
            'messages' => $this->messagesAdapter($pdo),
            'domain_events' => $this->domainEventsAdapter($pdo),
            'effects' => $this->effectsAdapter($pdo),
        };
    }

    /** The two connections of a SQLite file (WAL): the races and two processes. */
    private function testSqliteFiles(): void
    {
        $path = sys_get_temp_dir() . '/aspec065-' . bin2hex(random_bytes(4)) . '.sqlite';
        try {
            $connect = static function () use ($path): PDO {
                $pdo = new Module50HookPdo('sqlite:' . $path);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->setAttribute(PDO::ATTR_TIMEOUT, 10);
                $pdo->exec('PRAGMA journal_mode = WAL');

                return $pdo;
            };
            $this->testRaces('SQLite (arquivo, WAL)', $connect, 'sqlite');
            $this->testTwoProcesses('SQLite (arquivo, WAL)', ['sqlite:' . $path, '', '']);
        } finally {
            foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    /**
     * Two connections: the other one inserts the row of the lock between the reads of this one and its INSERT; outside
     * and inside a transaction of the caller, which goes on.
     *
     * @param Closure(): PDO $connect
     */
    private function testRaces(string $label, Closure $connect, string $family): void
    {
        $first = $connect();
        if (!$first instanceof Module50HookPdo) {
            $first = self::hookedFromEnvironment($family);
        }
        $second = $connect();
        self::resetSchema($first);
        $first->exec('CREATE TABLE m50_note (id INTEGER NOT NULL, CONSTRAINT pk_m50_note PRIMARY KEY (id))');
        $mine = new PdoLeaseLock($first);
        $theirs = new PdoLeaseLock($second);
        $now = $this->at(self::T0);
        $n = 'state_machine.effect_dispatcher:state_machine_outbox';

        $first->before('INSERT INTO lease_lock', function () use ($theirs, $now, $n): void {
            $this->assertTrue($theirs->tryAcquire($n, 'relay-b', $now, 30), 'a outra conexão insere primeiro');
        });
        $this->assertFalse($mine->tryAcquire($n, 'relay-a', $now, 30), "{$label}: a corrida da primeira aquisição: a violação da chave é trava não tomada");
        $this->assertEquals('relay-b', $mine->holder($n, $now), "{$label}: a outra segura");
        $theirs->release($n, 'relay-b');

        // Inside a transaction of the caller: the savepoint keeps it going. SQLite serializes the writers of a database, and
        // on MySQL and MariaDB the UPDATE of this transaction holds a gap lock on the absent row, so there the other INSERT
        // would wait for this commit: the row appears through this same connection, inside the savepoint, which the
        // violation rolls back with it. On PostgreSQL the other connection inserts and commits in the middle.
        $inside = $family === 'pgsql' ? $theirs : new PdoLeaseLock($first);
        $first->beginTransaction();
        $first->exec('INSERT INTO m50_note (id) VALUES (1)');
        $first->before('INSERT INTO lease_lock', function () use ($inside, $now, $n): void {
            $this->assertTrue($inside->tryAcquire($n, 'relay-b', $now, 30), 'a outra insere primeiro, durante a transação');
        });
        $this->assertFalse($mine->tryAcquire($n, 'relay-a', $now, 30), "{$label}: perdida dentro de uma transação");
        $first->exec('INSERT INTO m50_note (id) VALUES (2)');
        $first->commit();
        $this->assertEquals(2, (int) $second->query('SELECT COUNT(*) FROM m50_note')->fetchColumn(), "{$label}: a transação de quem chamou seguiu e confirmou as duas escritas");
        $theirs->release($n, 'relay-b');

        $first->beginTransaction();
        $this->assertTrue($mine->tryAcquire($n, 'relay-a', $now, 30), "{$label}: tomada dentro de uma transação (savepoint solto)");
        $first->exec('INSERT INTO m50_note (id) VALUES (3)');
        $first->commit();
        $this->assertEquals(['relay-a', 3], [$theirs->holder($n, $now), (int) $second->query('SELECT COUNT(*) FROM m50_note')->fetchColumn()], "{$label}: confirmadas a trava e a escrita");
        $mine->release($n, 'relay-a');

        // An INSERT that fails for another reason propagates, and the transaction still goes on
        $first->beginTransaction();
        $first->before('INSERT INTO lease_lock', static function (): void {
            $e = new PDOException('disk I/O error near "SECRET"');
            $e->errorInfo = ['HY000', 10, 'disk I/O error near "SECRET"'];
            throw $e;
        });
        $e = $this->assertThrows(LeaseLockException::class, fn() => $mine->tryAcquire($n, 'relay-a', $now, 30), "{$label}: outra falha do INSERT");
        $this->assertTrue($e->getSqlState() === 'HY000' && $e->getDriverCode() === '10' && !$e->isIntegrityViolation() && !str_contains($e->getMessage(), 'SECRET'), "{$label}: com o SQLSTATE, sem a mensagem do driver: " . $e->getMessage());
        $first->exec('INSERT INTO m50_note (id) VALUES (4)');
        $first->commit();
        $this->assertEquals(4, (int) $second->query('SELECT COUNT(*) FROM m50_note')->fetchColumn(), "{$label}: o savepoint desfeito, a transação seguiu");

        if ($family === 'pgsql') {
            // Measured: without the savepoint, the failed INSERT aborts the transaction of PostgreSQL
            $theirs->tryAcquire($n, 'relay-b', $now, 30);
            $first->beginTransaction();
            try {
                $first->exec("INSERT INTO lease_lock (lock_name, owner, locked_until, acquired_at) VALUES ('{$n}', 'x', 'x', 'x')");
            } catch (PDOException) {
            }
            $aborted = $this->assertThrows(PDOException::class, static fn() => $first->exec('INSERT INTO m50_note (id) VALUES (5)'), "{$label}: medido: sem savepoint a transação fica abortada");
            $this->assertEquals('25P02', $aborted->errorInfo[0] ?? null, "{$label}: SQLSTATE 25P02 (in failed sql transaction)");
            $first->rollBack();
            $theirs->release($n, 'relay-b');
        }
        $first->exec('DROP TABLE m50_note');
    }

    /** A savepoint that fails, and a statement that fails with the error mode silent. */
    private function testFailingStatements(): void
    {
        $pdo = new class ('sqlite::memory:') extends PDO {
            public bool $failExec = false;

            public bool $failPrepare = false;

            public function exec(string $statement): int|false
            {
                return $this->failExec && str_starts_with($statement, 'SAVEPOINT') ? false : parent::exec($statement);
            }

            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                return $this->failPrepare ? false : parent::prepare($query, $options);
            }

            public function errorInfo(): array
            {
                return $this->failExec || $this->failPrepare ? ['HY000', 21, 'misuse near "SECRET"'] : parent::errorInfo();
            }
        };
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        self::resetSchema($pdo);
        $lock = new PdoLeaseLock($pdo);
        $pdo->beginTransaction();
        $pdo->failExec = true;
        $e = $this->assertThrows(LeaseLockException::class, fn() => $lock->tryAcquire('n', 'a', $this->at(self::T0), 5), 'o SAVEPOINT falha (exec() devolve false)');
        $this->assertEquals('Lease lock statement failed: acquire of lock "n" on lease_lock (SQLSTATE HY000, driver code 21). The driver message is not repeated.', $e->getMessage());
        $pdo->failExec = false;
        $pdo->rollBack();
        $pdo->failPrepare = true;
        $e = $this->assertThrows(LeaseLockException::class, fn() => $lock->release('n', 'a'), 'prepare() devolve false');
        $this->assertEquals('Lease lock statement failed: release of lock "n" on lease_lock (SQLSTATE HY000, driver code 21). The driver message is not repeated.', $e->getMessage());
        $pdo->failPrepare = false;
        $pdo->exec('DROP TABLE lease_lock');
        $e = $this->assertThrows(LeaseLockException::class, fn() => $lock->holder('n', $this->at(self::T0)), 'execute() devolve false (modo silencioso)');
        $this->assertTrue(str_starts_with($e->getMessage(), 'Lease lock statement failed: read of lock "n" on lease_lock (SQLSTATE HY000'), $e->getMessage());

        $throwing = new class ('sqlite::memory:') extends PDO {
            public function exec(string $statement): int|false
            {
                if (str_starts_with($statement, 'SAVEPOINT')) {
                    $e = new PDOException('SECRET');
                    $e->errorInfo = ['42000', 1, 'SECRET'];
                    throw $e;
                }

                return parent::exec($statement);
            }
        };
        $throwing->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::resetSchema($throwing);
        $throwing->beginTransaction();
        $e = $this->assertThrows(LeaseLockException::class, fn() => (new PdoLeaseLock($throwing))->tryAcquire('n', 'a', $this->at(self::T0), 5), 'o SAVEPOINT lança');
        $this->assertTrue($e->getSqlState() === '42000' && $e->getDriverCode() === '1' && !str_contains($e->getMessage(), 'SECRET'), 'SQLSTATE e código, sem a mensagem: ' . $e->getMessage());
        $throwing->rollBack();

        $coded = new class ('sqlite::memory:') extends PDO {
            public function exec(string $statement): int|false
            {
                if (str_starts_with($statement, 'SAVEPOINT')) {
                    throw new PDOException('SECRET');
                }

                return parent::exec($statement);
            }
        };
        $coded->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::resetSchema($coded);
        $coded->beginTransaction();
        $e = $this->assertThrows(LeaseLockException::class, fn() => (new PdoLeaseLock($coded))->tryAcquire('n', 'a', $this->at(self::T0), 5), 'o SAVEPOINT lança sem errorInfo');
        $this->assertTrue($e->getSqlState() === null && $e->getDriverCode() === null && str_contains($e->getMessage(), '(SQLSTATE unknown, driver code unknown)') && !str_contains($e->getMessage(), 'SECRET'), 'sem SQLSTATE: ' . $e->getMessage());
        $coded->rollBack();
    }

    /**
     * Two PHP processes race for the three locks of the dispatchers (one PdoLeaseLock, three names) on a real database;
     * each enters the critical section of a name whenever it holds its lock: never both in the same one at once.
     *
     * @param array{string, string, string} $dsn DSN, user, password
     */
    private function testTwoProcesses(string $label, array $dsn): void
    {
        $pdo = new PDO($dsn[0], $dsn[1] === '' ? null : $dsn[1], $dsn[2] === '' ? null : $dsn[2], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        self::resetSchema($pdo);
        $pdo->exec('DROP TABLE IF EXISTS m50_critical');
        $pdo->exec('CREATE TABLE m50_critical (id INTEGER NOT NULL, holder VARCHAR(40), CONSTRAINT pk_m50_critical PRIMARY KEY (id))');
        $pdo->exec('INSERT INTO m50_critical (id, holder) VALUES (1, NULL), (2, NULL), (3, NULL)');
        $names = [OutboxRelay::LOCK_NAME . ':message_outbox', DomainEventDispatcher::LOCK_NAME . ':domain_event_outbox', EffectDispatcher::LOCK_NAME . ':state_machine_outbox'];
        $dir = sys_get_temp_dir() . '/aspec065-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $start = sprintf('%.6F', microtime(true) + 1.0);
        $processes = [];
        $results = [];
        try {
            foreach (['worker-a', 'worker-b'] as $owner) {
                $command = [
                    '/bin/sh', '-c', 'exec "$0" "$@" </dev/null >/dev/null 2>&1',
                    PHP_BINARY, '-d', 'xdebug.mode=off', dirname(__DIR__) . '/Support/lease_lock_worker.php',
                    $dsn[0], $dsn[1], $dsn[2], $owner, $start, '1500', "{$dir}/{$owner}.json", ...$names,
                ];
                $process = proc_open($command, [], $pipes, dirname(__DIR__, 2), ['XDEBUG_MODE' => 'off', 'PATH' => (string) getenv('PATH')]);
                $this->assertTrue(is_resource($process), "{$label}: processo {$owner} iniciado");
                $processes[$owner] = $process;
            }
            $deadline = microtime(true) + 60;
            foreach ($processes as $process) {
                while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
                    usleep(20000);
                }
            }
            foreach (array_keys($processes) as $owner) {
                $results[$owner] = json_decode((string) @file_get_contents("{$dir}/{$owner}.json"), true);
                $this->assertTrue(is_array($results[$owner]) && $results[$owner]['error'] === null, "{$label}: {$owner} terminou sem erro: " . json_encode($results[$owner]));
            }
        } finally {
            foreach ($processes as $process) {
                if (proc_get_status($process)['running']) {
                    proc_terminate($process, 9);
                }
                proc_close($process);
            }
            array_map('unlink', glob("{$dir}/*") ?: []);
            @rmdir($dir);
        }
        $entries = $results['worker-a']['entries'] + $results['worker-b']['entries'];
        $denials = $results['worker-a']['denials'] + $results['worker-b']['denials'];
        $this->assertEquals(0, $results['worker-a']['overlaps'] + $results['worker-b']['overlaps'], "{$label}: nunca os dois na seção crítica de um nome: " . json_encode($results));
        // Both ran, and they raced: a denial, or both inside in turn. Under load SQLite may starve one writer in its busy
        // handler for most of the window (measured in the runner: 1 entry against 117, no denial), which is not a failure
        // of the lock; the overlaps above are the property.
        $this->assertTrue(
            $entries >= 20 && $results['worker-a']['entries'] + $results['worker-a']['denials'] > 0 && $results['worker-b']['entries'] + $results['worker-b']['denials'] > 0
                && ($denials > 0 || min($results['worker-a']['entries'], $results['worker-b']['entries']) > 0),
            "{$label}: os dois rodaram e disputaram: " . json_encode($results)
        );
        $free = (int) $pdo->query('SELECT COUNT(*) FROM m50_critical WHERE holder IS NULL')->fetchColumn();
        $this->assertTrue($free === 3 && (int) $pdo->query('SELECT COUNT(*) FROM lease_lock')->fetchColumn() === 0, "{$label}: no fim, seções e travas livres");
        fwrite(STDOUT, "    [065] {$label}, dois processos por 1,5 s sobre as três travas: {$entries} entradas, {$denials} negações, 0 sobreposições\n");
        $pdo->exec('DROP TABLE m50_critical');
    }

    // =====================================================================
    // D7/D13: the examples of the three pages
    // =====================================================================

    private const EXAMPLES = [
        'messaging/index.md' => ['26.4', '26.5'],
        'events/from-specifications.md' => ['18.2'],
        'state-machine/index.md' => ['14.8'],
    ];

    private function testExamplesAreTheSameInBothLanguages(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (self::EXAMPLES as $page => $numbers) {
            foreach ($numbers as $number) {
                $english = ReadmeBlocks::of("{$root}/docs/en/{$page}", $number);
                $portuguese = ReadmeBlocks::of("{$root}/docs/pt-BR/{$page}", $number);
                $this->assertCount(1, $english, "um bloco PHP no exemplo {$number} (EN)");
                $this->assertCount(1, $portuguese, "um bloco PHP no exemplo {$number} (pt-BR)");
                $this->assertEquals(ReadmeBlocks::codeTokens($english[0]), ReadmeBlocks::codeTokens($portuguese[0]), "exemplo {$number}: bloco igual fora os comentários");
            }
        }
    }

    private function testMessagingExamplesRunAsWritten(): void
    {
        $pdo = self::sqliteEmpty();
        self::resetSchema($pdo);
        // The context of example 26
        $transactions = new PdoTransactionManager($pdo);
        $publisher = new MessagePublisher(new PdoMessageOutbox($pdo));
        $transport = new InMemoryMessageTransport();
        $transactions->transactional(fn(): int => $publisher->publish(new Message('underwriting.quote_submitted.v1', ['quote_id' => 123], orderingKey: 'quote:123', tenant: 'acme')));
        (new OutboxRelay(new PdoMessageOutbox($pdo), $transport))->relayPending(100);

        // DOC 26.4 >>>
        $lock = new PdoLeaseLock($pdo);
        $relay = new OutboxRelay(
            new PdoMessageOutbox($pdo),
            $transport,
            maxAttempts: 10,
            lock: $lock,
            leaseSeconds: 60,
            lockOwner: 'relay@' . gethostname() . ':' . getmypid()
        );
        $name = $relay->getLockName();

        $transactions->transactional(fn(): int => $publisher->publish(
            new Message('underwriting.quote_approved.v1', ['quote_id' => 123], orderingKey: 'quote:123', tenant: 'acme')
        ));

        $lock->tryAcquire($name, 'relay@other-host:4242', new DateTimeImmutable(), 60);
        $denied = $relay->relayPending(100)->isLockDenied();
        $lock->release($name, 'relay@other-host:4242');

        $report = $relay->relayPending(100);
        $sent = $report->deliveredCount();
        $lost = $report->isLockLost();
        // <<< DOC 26.4
        $this->assertTranscribed('messaging/index.md', 'DOC 26.4', '26.4', 2);
        $this->assertEquals(['messaging.outbox_relay:message_outbox', true, 1, false, 2], [$name, $denied, $sent, $lost, $transport->storedCount()], 'exemplo 26.4');
        $this->assertTrue($lock->lease($name) === null, 'exemplo 26.4: solta no fim');

        $pdo->exec("UPDATE message_outbox SET delivered_at = '" . LeaseLockSupport::toText((new DateTimeImmutable())->modify('-1 year')) . "' WHERE status = 'delivered'");
        $transactions->transactional(fn(): int => $publisher->publish(new Message('underwriting.quote_rejected.v1', ['quote_id' => 124], orderingKey: 'quote:124', tenant: 'acme')));

        // DOC 26.5 >>>
        $purge = function (IMessageOutboxRetention $outbox, DateTimeImmutable $before): int {
            $total = 0;
            do {
                $purged = $outbox->purgeDeliveredBefore($before, 1000);
                $total += $purged;
            } while ($purged === 1000);

            return $total;
        };

        $purged = $purge(new PdoMessageOutbox($pdo), new DateTimeImmutable('-7 days'));
        $left = (new PdoMessageOutbox($pdo))->count();

        (new PdoProcessedMessageStore($pdo))->purgeProcessedBefore('agents', new DateTimeImmutable('-8 days'));
        // <<< DOC 26.5
        $this->assertTranscribed('messaging/index.md', 'DOC 26.5', '26.5', 1);
        $this->assertEquals([2, 1], [$purged, $left], 'exemplo 26.5');
        $this->assertEquals('pending', (string) $pdo->query('SELECT status FROM message_outbox')->fetchColumn(), 'exemplo 26.5: a pendente ficou');
    }

    private function testDomainEventExampleRunsAsWritten(): void
    {
        $pdo = self::sqliteEmpty();
        self::resetSchema($pdo);
        // The context of example 18: two crossings in the outbox, their handlers
        $seen = [];
        $handlers = (new DomainEventHandlerRegistry())
            ->register('delinquency.started', function (DomainEvent $event, string $idempotencyKey) use (&$seen): void {
                $seen[] = $event->name;
            })
            ->register('delinquency.ended', function (DomainEvent $event, string $idempotencyKey) use (&$seen): void {
                $seen[] = $event->name;
            });
        (new PdoDomainEventOutbox($pdo))->enqueue([
            new DomainEventOutboxEntry(self::mid(1), new DomainEvent('delinquency.started', 'policy.delinquent', DomainEventKind::ENTER, 'App\\Policy', 'P-1', $this->at('2026-11-19 09:00:00'))),
            new DomainEventOutboxEntry(self::mid(2), new DomainEvent('delinquency.ended', 'policy.delinquent', DomainEventKind::EXIT, 'App\\Policy', 'P-1', $this->at('2026-11-19 09:30:00'))),
        ]);

        // DOC 18.2 >>>
        $dispatcher = new DomainEventDispatcher(
            new PdoDomainEventOutbox($pdo),
            $handlers,
            maxAttempts: 10,
            lock: new PdoLeaseLock($pdo),
            leaseSeconds: 60
        );
        $name = $dispatcher->getLockName();
        $report = $dispatcher->dispatchPending(100);
        $delivered = $report->deliveredCount();
        $skipped = $report->isLockDenied();

        $purge = fn(IDomainEventOutboxRetention $outbox): int => $outbox->purgeDeliveredBefore(new DateTimeImmutable('-30 days'), 1000);
        $purged = $purge(new PdoDomainEventOutbox($pdo));
        // <<< DOC 18.2
        $this->assertTranscribed('events/from-specifications.md', 'DOC 18.2', '18.2', 3);
        $this->assertEquals(['domain_events.dispatcher:domain_event_outbox', 2, false, 0, ['delinquency.started', 'delinquency.ended']], [$name, $delivered, $skipped, $purged, $seen], 'exemplo 18.2');
        $this->assertTrue((new PdoLeaseLock($pdo))->lease($name) === null, 'exemplo 18.2: solta no fim');
    }

    private function testEffectExampleRunsAsWritten(): void
    {
        $pdo = self::sqliteEmpty();
        self::resetSchema($pdo);
        // The context of example 14.5, over PDO: one effect of a step in the outbox, its handler
        $sent = [];
        $handlers = (new EffectHandlerRegistry())
            ->register('notify_reviewer', function (Effect $effect, string $idempotencyKey) use (&$sent): void {
                $sent[] = $effect->action;
            });
        $persistence = MachinePersistence::pdo($pdo);
        $persistence->outbox->enqueue([new OutboxEntry('doc-42', 2, 0, 'document', 1, new Effect('notify_reviewer', [], 'lifecycle', EffectPhase::TRANSITION, 'pending', null), OutboxStatus::PENDING, 0, null, $this->at('2026-10-10 10:00:00'))]);

        // DOC 14.8 >>>
        $dispatcher = new EffectDispatcher(
            $persistence->outbox,
            $handlers,
            lock: new PdoLeaseLock($pdo),
            leaseSeconds: 60
        );
        $name = $dispatcher->getLockName();
        $delivered = $dispatcher->dispatchPending()->deliveredCount();
        $again = $dispatcher->dispatchPending()->isLockDenied();

        $purge = fn(IEffectOutboxRetention $outbox): int => $outbox->purgeDeliveredBefore(new DateTimeImmutable('-30 days'), 1000);
        $purged = $purge($persistence->outbox);
        // <<< DOC 14.8
        $this->assertTranscribed('state-machine/index.md', 'DOC 14.8', '14.8', 2);
        $this->assertEquals(['state_machine.effect_dispatcher:state_machine_outbox', 1, false, 0, ['notify_reviewer']], [$name, $delivered, $again, $purged, $sent], 'exemplo 14.8');
    }

    /**
     * The code between the markers is the block of the example, token by token (its `use` lines are imports of this file).
     */
    private function assertTranscribed(string $page, string $name, string $number, int $imports): void
    {
        $source = (string) file_get_contents(__FILE__);
        $start = strpos($source, "// {$name} >>>\n");
        $end = strpos($source, "// <<< {$name}\n");
        $this->assertTrue($start !== false && $end !== false && $end > $start, "transcription {$name} marked");
        $transcribed = ReadmeBlocks::codeTokens(substr($source, (int) $start, (int) $end - (int) $start));
        $block = ReadmeBlocks::of(dirname(__DIR__, 2) . '/docs/en/' . $page, $number)[0] ?? '';
        preg_match_all('/^use ([^;]+);$/m', $block, $uses);
        $this->assertCount($imports, $uses[1], "{$name}: imports");
        foreach ($uses[1] as $import) {
            $this->assertTrue(str_contains($source, "\nuse {$import};\n"), "{$name}: {$import} is imported by this file too");
        }
        $code = ReadmeBlocks::codeTokens((string) preg_replace('/^use [^;]+;$/m', '', $block));
        $this->assertTrue(count($code) > 30 && $transcribed === $code, sprintf('%s: the transcription is the code of the page (%d tokens transcribed, %d on the page)', $name, count($transcribed), count($code)));
    }

    // =====================================================================
    // The coverage guard
    // =====================================================================

    private function testCoverageGuard(bool $withPdo): void
    {
        $root = dirname(__DIR__, 2);
        $folder = $root . '/src';
        $source = (string) file_get_contents(__FILE__);
        $touched = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), '@version    2.16.0')) {
                $touched[] = substr($file->getPathname(), strlen($folder) + 1, -4);
            }
        }
        sort($touched);
        $declared = [...self::NEW_FILES, ...self::CHANGED_FILES];
        sort($declared);
        $this->assertEquals($declared, $touched, 'every file of src/ at @version 2.16.0 is declared here, new or changed');
        $infection = (string) file_get_contents($root . '/infection.json5');
        preg_match_all('/^\s*"(~[^\n]*~)",?$/m', $infection, $excludes);
        $this->assertCount(2, $excludes[1], 'the two excludes of infection.json5');
        $patterns = array_map(static fn(string $quoted): string => (string) json_decode('"' . $quoted . '"'), $excludes[1]);
        foreach ($declared as $file) {
            $class = 'Antevemus\\ASpecification\\' . str_replace('/', '\\', $file);
            $code = (string) file_get_contents("{$folder}/{$file}.php");
            $this->assertTrue(str_contains($source, "\nuse {$class};\n"), "{$class} is imported by this module");
            $this->assertTrue((!$withPdo && str_contains($file, 'Pdo')) || class_exists($class, false) || interface_exists($class, false), "{$class} was loaded by this module");
            $new = in_array($file, self::NEW_FILES, true);
            $this->assertTrue(str_contains($code, 'declare(strict_types=1);') && ($new ? str_contains($code, 'orward 065, D') : str_contains($code, 'Since 2.16.0 (forward 065')), "{$file}: strict types, and the docblock says what 2.16.0 brought");
            if ($new) {
                foreach ($patterns as $pattern) {
                    $this->assertTrue(preg_match($pattern, $file . '.php') === 0, "{$file}: in the mutation scope (not excluded by {$pattern})");
                }
            }
        }
    }

    // =====================================================================
    // Support
    // =====================================================================

    /** A ULID of the module: deterministic, valid. */
    private static function mid(int $n): string
    {
        return '01JF' . str_pad((string) $n, 22, '0', STR_PAD_LEFT);
    }

    private function at(string $text): DateTimeImmutable
    {
        return new DateTimeImmutable($text, new DateTimeZone('UTC'));
    }

    private static function text(?DateTimeImmutable $instant): ?string
    {
        return $instant === null ? null : LeaseLockSupport::toText($instant);
    }

    private static function leaseOf(ILeaseLock $lock, string $name): ?string
    {
        $lease = $lock instanceof InMemoryLeaseLock || $lock instanceof PdoLeaseLock ? $lock->lease($name) : throw new RuntimeException('no inspection');

        return $lease === null ? null : sprintf('%s %s %s', $lease['owner'], $lease['acquired_at'], $lease['locked_until']);
    }

    /** The statements of a DDL text, without its comment lines. */
    private static function statements(string $ddl): string
    {
        return implode("\n", array_filter(explode("\n", $ddl), static fn(string $line): bool => !str_starts_with(ltrim($line), '--')));
    }

    private static function runDdl(PDO $pdo, string $ddl): void
    {
        foreach (explode(';', self::statements($ddl)) as $statement) {
            if (trim($statement) !== '') {
                $pdo->exec($statement);
            }
        }
    }

    /** @var \WeakMap<PDO, true>|null Connections whose schema is in place, emptied by resetSchema() instead of recreated */
    private static ?\WeakMap $ready = null;

    private static function resetSchema(PDO $pdo): void
    {
        self::$ready ??= new \WeakMap();
        if (isset(self::$ready[$pdo])) {
            foreach (self::tables() as $table) {
                $pdo->exec('DELETE FROM ' . $table);
            }

            return;
        }
        self::dropSchema($pdo);
        foreach (self::DDLS as $ddl) {
            self::runDdl($pdo, (string) file_get_contents($ddl));
        }
        self::$ready[$pdo] = true;
    }

    private static function dropSchema(PDO $pdo): void
    {
        if (self::$ready !== null) {
            unset(self::$ready[$pdo]);
        }
        foreach (['m50_locks', 'm50_note', 'm50_critical', ...self::tables()] as $table) {
            $pdo->exec('DROP TABLE IF EXISTS ' . $table);
        }
    }

    /**
     * The tables of the four DDLs, the dependent ones first (the reverse of the order they are created in).
     *
     * @return list<string>
     */
    private static function tables(): array
    {
        $tables = [];
        foreach (self::DDLS as $ddl) {
            preg_match_all('/^CREATE TABLE IF NOT EXISTS (\w+)/m', (string) file_get_contents($ddl), $created);
            $tables = [...$tables, ...$created[1]];
        }

        return array_reverse($tables);
    }

    private static function sqliteEmpty(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    /** @return array{string, string, string} */
    private static function environmentDsn(string $family): array
    {
        $prefix = 'ASPEC_PDO_' . strtoupper($family);

        return [(string) getenv($prefix . '_DSN'), (string) getenv($prefix . '_USER'), (string) getenv($prefix . '_PASSWORD')];
    }

    private static function connectFromEnvironment(string $family): ?PDO
    {
        [$dsn, $user, $password] = self::environmentDsn($family);
        if ($dsn === '') {
            return null;
        }

        return new PDO($dsn, $user === '' ? null : $user, $password === '' ? null : $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private static function hookedFromEnvironment(string $family): Module50HookPdo
    {
        [$dsn, $user, $password] = self::environmentDsn($family);

        return new Module50HookPdo($dsn, $user === '' ? null : $user, $password === '' ? null : $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
}
