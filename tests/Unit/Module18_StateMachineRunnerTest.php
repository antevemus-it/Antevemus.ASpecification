<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\StateMachine\Effect;
use Antevemus\ASpecification\StateMachine\Event;
use Antevemus\ASpecification\StateMachine\EventOrigin;
use Antevemus\ASpecification\StateMachine\Exceptions\ConcurrencyConflictException;
use Antevemus\ASpecification\StateMachine\Exceptions\InvalidMachineDefinitionException;
use Antevemus\ASpecification\StateMachine\Exceptions\MachineInstanceAlreadyExistsException;
use Antevemus\ASpecification\StateMachine\Exceptions\MachineInstanceNotFoundException;
use Antevemus\ASpecification\StateMachine\Exceptions\MachineMigrationException;
use Antevemus\ASpecification\StateMachine\Exceptions\MachinePersistenceException;
use Antevemus\ASpecification\StateMachine\Exceptions\SyncEffectFailedException;
use Antevemus\ASpecification\StateMachine\Exceptions\UnknownMachineDefinitionException;
use Antevemus\ASpecification\StateMachine\GuardRegistry;
use Antevemus\ASpecification\StateMachine\MachineDefinition;
use Antevemus\ASpecification\StateMachine\MachineSnapshot;
use Antevemus\ASpecification\StateMachine\Persistence\DueDeadline;
use Antevemus\ASpecification\StateMachine\Persistence\HistoryEntry;
use Antevemus\ASpecification\StateMachine\Persistence\IMachineHistoryStore;
use Antevemus\ASpecification\StateMachine\Persistence\IMachineInstanceStore;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\InMemoryMachineDefinitionRepository;
use Antevemus\ASpecification\StateMachine\Persistence\MachineInstance;
use Antevemus\ASpecification\StateMachine\Persistence\MachinePersistence;
use Antevemus\ASpecification\StateMachine\Persistence\OutboxEntry;
use Antevemus\ASpecification\StateMachine\Persistence\OutboxStatus;
use Antevemus\ASpecification\StateMachine\Persistence\Pdo\PdoMachineSchema;
use Antevemus\ASpecification\StateMachine\Persistence\PersistenceSupport;
use Antevemus\ASpecification\StateMachine\Runner\EffectDispatcher;
use Antevemus\ASpecification\StateMachine\Runner\EffectHandlerRegistry;
use Antevemus\ASpecification\StateMachine\Runner\MachineRunner;
use Antevemus\ASpecification\StateMachine\StateMachine;
use Antevemus\ASpecification\StateMachine\TransitionResult;
use Antevemus\ASpecification\StateMachine\TransitionStatus;
use Antevemus\ASpecification\Tests\TestCase;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use ReflectionClass;
use RuntimeException;

/**
 * Module18_StateMachineRunnerTest - Runner e Persistência da Máquina de Estados (1.8.0)
 *
 * Forward 023, fases 5 e 6 (parte de persistência): portas (`IMachineInstanceStore`,
 * `IMachineHistoryStore`, `IEffectOutbox`, `IIdempotencyStore`, `IMachineRejectionLog`,
 * `ITransactionManager`, `IMachineDefinitionRepository`), implementações InMemory e PDO sobre o DDL
 * de referência `resources/sql/state_machine.ansi.sql`, o `MachineRunner` (start, send com CAS por
 * versão e idempotência, migração explícita de versão, entrega de prazos) e o `EffectDispatcher`
 * (outbox entregue depois do commit, em ordem por instância).
 *
 * A mesma bateria roda nos dois stores (§4 item 4): InMemory e PDO sobre SQLite em memória. Cobre
 * RN-07 (histórico append-only, log de rejeição opcional), RN-08 (CAS com dois runners no mesmo
 * store), RN-09 (idempotência devolve o resultado original sem gravar nada), RN-10 (outbox: ordem,
 * falha, tentativas, mensagem sem stack trace), D12 (efeito síncrono desfaz tudo), D16 (instância
 * presa à versão + migração explícita), D18 (prazo → findDue → deliverDue com relógio injetado) e a
 * espinha da Virttus com a pendência registrada e resolvida.
 *
 * Forward 025, fase 3 (1.9.0): início rejeitado por fonte de prazo inválida não grava nada; região
 * com prazo pausado fora do `findDue` nos dois stores; retomada com o saldo; `deliverDue` com
 * provedor de contexto; coluna `paused_remaining` no PDO, banco criado pelo DDL da 1.8 (funciona
 * até a primeira pausa; o ALTER TABLE do cabeçalho o atualiza) e snapshot da 1.8.0 gravado em JSON.
 * README 14.7 (prazos do contexto, pausa com saldo, deliverDue com contexto) e a nota de atualização
 * da 1.8 em 14.6, transcritos.
 *
 * @version    1.9.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Unit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class Module18_StateMachineRunnerTest extends TestCase
{
    private const DDL = __DIR__ . '/../../resources/sql/state_machine.ansi.sql';

    private const FIXTURE = __DIR__ . '/../Fixtures/state_machine/virttus_quote.v1.json';

    /** The Virttus spine with a pending item registered and resolved at proposal_sent. */
    private const VIRTTUS_STEPS = [
        ['COMPLETE_CALCULATION', 'progress', 'registering', 'calculation_done', 'complete_calculation'],
        ['REQUEST_CLIENT_DOCUMENTS', 'progress', 'calculation_done', 'awaiting_client_documents', 'request_client_documents'],
        ['CLIENT_DOCUMENTS_RECEIVED', 'progress', 'awaiting_client_documents', 'awaiting_hiring_proposal', 'client_documents_received'],
        ['SEND_PROPOSAL', 'progress', 'awaiting_hiring_proposal', 'proposal_sent', 'send_proposal'],
        ['REGISTER_PENDING', 'pending', 'none', 'open', 'register_pending'],
        ['RESOLVE_PENDING', 'pending', 'open', 'none', 'resolve_pending'],
        ['APPROVE_PROPOSAL_QUOTATION', 'progress', 'proposal_sent', 'approve_proposal_quotation', 'approve_proposal_quotation'],
        ['COMPLETE_PROPOSAL', 'progress', 'approve_proposal_quotation', 'completed', 'complete_proposal'],
    ];

    public function run(): void
    {
        $this->testReferenceDdl();
        $this->testSchemaIdentifiersAndColumnMap();

        foreach ($this->backends() as $label => $factory) {
            $this->testVirttusSpineHistory($label, $factory);
            $this->testStartAndSendErrors($label, $factory);
            $this->testConcurrencyTwoRunners($label, $factory);
            $this->testConcurrencyInsideTheTransaction($label, $factory);
            $this->testIdempotency($label, $factory);
            $this->testOutboxDispatch($label, $factory);
            $this->testOutboxExhaustionAndMissingHandler($label, $factory);
            $this->testSyncEffects($label, $factory);
            $this->testDeadlines($label, $factory);
            $this->testPinnedVersionAndMigration($label, $factory);
            $this->testRejectionLog($label, $factory);
            $this->testHistoryIsAppendOnly($label, $factory);
            $this->testTransactionBoundary($label, $factory);
            $this->testCanAndNextEventsThroughRunner($label, $factory);
            $this->testDeadlineSourcesAndPause($label, $factory);
            $this->testMigrationRefusesStrandedPause($label, $factory);
        }

        $this->testParityInMemoryVsPdo();
        if (extension_loaded('pdo_sqlite')) {
            $this->testPausedProjectionAndDdlUpgrade();
        }
        $this->testReadme14_5RunnerInMemory();
        if (extension_loaded('pdo_sqlite')) {
            $this->testReadme14_6PdoPersistence();
        }
        $this->testReadme14_7DeadlinesFromTheContext();
    }

    // ==========================================
    // Helpers
    // ==========================================

    /**
     * The two backends of the battery: label => factory(bool $withRejectionLog).
     *
     * @return array<string, Closure(bool): MachinePersistence>
     */
    private function backends(): array
    {
        $backends = ['InMemory' => static fn(bool $log = false): MachinePersistence => MachinePersistence::inMemory($log)];
        if (extension_loaded('pdo_sqlite')) {
            $backends['Pdo(SQLite)'] = fn(bool $log = false): MachinePersistence => MachinePersistence::pdo($this->sqlite(), withRejectionLog: $log);
        } else {
            fwrite(STDOUT, "    [AVISO] pdo_sqlite ausente; a bateria roda só no InMemory.\n");
        }

        return $backends;
    }

    /** An in-memory SQLite database with the reference DDL. */
    private function sqlite(?string $ddl = null): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        self::loadDdl($pdo, $ddl ?? (string) file_get_contents(self::DDL));

        return $pdo;
    }

    /**
     * Runs a DDL script: drops `--` comment lines and executes each `;`-terminated statement.
     */
    private static function loadDdl(PDO $pdo, string $ddl): void
    {
        $lines = array_filter(explode("\n", $ddl), static fn(string $line): bool => !str_starts_with(ltrim($line), '--'));
        foreach (explode(';', implode("\n", $lines)) as $statement) {
            if (trim($statement) !== '') {
                $pdo->exec($statement);
            }
        }
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time);
    }

    private function virttus(): MachineDefinition
    {
        return MachineDefinition::fromJson((string) file_get_contents(self::FIXTURE));
    }

    private function virttusGuards(): GuardRegistry
    {
        return Spec::guardRegistry()->register('hasRole', static function (array $params): ISpecification {
            $roles = $params['roles'] ?? [];
            if ($roles === []) {
                return Spec::alwaysTrue();
            }

            return Spec::property('context.role', Spec::in($roles))
                ->because('The user role is not allowed to take this action.')
                ->withCode('ROLE_NOT_ALLOWED');
        });
    }

    /**
     * A machine with effects in the three phases:
     * a (entry a_entry, exit a_exit) --GO [t_go]--> b (entry b_entry) --BACK--> a; NOTE is targetless [t_note].
     */
    private function mailMachine(): MachineDefinition
    {
        return Spec::machine('mail', 1)
            ->region('r', 'a')
                ->state('a')->entry('a_entry')->exit('a_exit')
                    ->on('GO', 'b', 'go')->action('t_go')
                    ->on('NOTE', null, 'note')->action('t_note')
                ->state('b')->entry('b_entry')
                    ->on('BACK', 'a', 'back')
                    ->on('NOTE', null, 'note_b')->action('t_note')
            ->build();
    }

    /** The document deadline machine: delivered (P3D → EXPIRE) → expired | validated. */
    private function deadlineMachine(): MachineDefinition
    {
        return Spec::machine('deadline', 1)
            ->region('doc', 'delivered')
                ->state('delivered')->deadline('P3D', 'EXPIRE')->entry('notify_delivered')
                    ->on('EXPIRE', 'expired', 'expire')
                    ->on('RESUBMIT', 'delivered', 'resubmit')
                    ->on('COMMENT', null, 'comment')->action('log_comment')
                    ->on('VALIDATE', 'validated', 'validate')
                ->finalState('validated')
                ->finalState('expired')
            ->build();
    }

    /**
     * @param list<MachineDefinition> $definitions
     */
    private function runner(MachinePersistence $persistence, array $definitions, ?GuardRegistry $guards = null, ?EffectHandlerRegistry $effects = null): MachineRunner
    {
        return new MachineRunner(new StateMachine($guards), new InMemoryMachineDefinitionRepository($definitions), $persistence, $effects);
    }

    /**
     * @param list<HistoryEntry> $rows
     * @return list<string>
     */
    private function historyLabels(array $rows): array
    {
        return array_map(
            static fn(HistoryEntry $h): string => sprintf('%d.%d %s %s:%s>%s', $h->instanceVersion, $h->sortOrder, $h->eventType, $h->region, $h->fromState ?? '-', $h->toState),
            $rows
        );
    }

    /**
     * @param list<OutboxEntry> $entries
     * @return list<string>
     */
    private function outboxLabels(array $entries): array
    {
        return array_map(
            static fn(OutboxEntry $e): string => sprintf('%s %s/%s %s#%d', $e->effectKey(), $e->effect->phase->value, $e->effect->action, $e->status->value, $e->attempts),
            $entries
        );
    }

    // ==========================================
    // 1. Reference DDL and PDO schema
    // ==========================================

    private function testReferenceDdl(): void
    {
        $this->assertTrue(is_file(self::DDL), 'resources/sql/state_machine.ansi.sql must exist');
        if (!extension_loaded('pdo_sqlite')) {
            return;
        }
        $ddl = (string) file_get_contents(self::DDL);
        $statements = implode("\n", array_filter(explode("\n", $ddl), static fn(string $l): bool => !str_starts_with(ltrim($l), '--')));
        $this->assertFalse(str_contains($statements, '.state_machine_'), 'The reference DDL does not qualify a schema');
        $this->assertFalse((bool) preg_match('/CREATE\s+INDEX/i', $statements), 'No CREATE INDEX (MySQL has no CREATE INDEX IF NOT EXISTS)');
        // 2.7.0: two more tables (the published definitions and their drafts, Module 40)
        $this->assertEquals(8, preg_match_all('/CREATE TABLE IF NOT EXISTS/', $statements), 'Eight idempotent CREATE TABLE statements (six since 1.8.0, two since 2.7.0)');

        $pdo = $this->sqlite();
        self::loadDdl($pdo, $ddl); // idempotent: a second run is harmless

        foreach (PdoMachineSchema::DEFAULT_TABLES as $role => $table) {
            $info = $pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC);
            $columns = array_map(static fn(array $c): string => $c['name'], $info);
            // 2.7.0: the DDL also declares the opt-in columns (tenant, state codes), last; unmapped, the stores never touch them
            // 2.10.0: and the event of the step in the outbox (event_snapshot), after them
            $this->assertEquals([...PdoMachineSchema::TABLE_COLUMNS[$role], ...(PdoMachineSchema::TABLE_COLUMNS_SINCE_2_7[$role] ?? []), ...(PdoMachineSchema::TABLE_COLUMNS_SINCE_2_10[$role] ?? [])], $columns, "Columns of {$table} match the canonical list");
            $pk = array_filter($info, static fn(array $c): bool => (int) $c['pk'] > 0);
            $this->assertTrue($pk !== [], "{$table} has a primary key");
        }

        $indexes = static function (PDO $pdo, string $table): array {
            $out = [];
            foreach ($pdo->query("PRAGMA index_list({$table})")->fetchAll(PDO::FETCH_ASSOC) as $index) {
                $cols = array_map(static fn(array $c): string => $c['name'], $pdo->query("PRAGMA index_info('{$index['name']}')")->fetchAll(PDO::FETCH_ASSOC));
                $out[] = ['unique' => (int) $index['unique'] === 1, 'columns' => $cols];
            }

            return $out;
        };
        $this->assertTrue(
            in_array(['unique' => true, 'columns' => ['instance_id', 'idempotency_key']], $indexes($pdo, 'state_machine_idempotency'), true),
            'UNIQUE (instance_id, idempotency_key) (RN-12)'
        );
        $leading = array_map(static fn(array $i): array => array_slice($i['columns'], 0, 2), $indexes($pdo, 'state_machine_region'));
        $this->assertTrue(in_array(['machine_key', 'due_at'], $leading, true), 'An index leads with (machine_key, due_at) (RN-12)');

        // Constraints hold.
        $this->assertThrows(\PDOException::class, fn() => $pdo->exec(
            "INSERT INTO state_machine_instance (instance_id, machine_key, machine_version, version, snapshot, done, created_at, updated_at) VALUES ('x', 'k', 1, 1, '{}', 'X', 'a', 'a')"
        ), 'done must be Y or N');
        $this->assertThrows(\PDOException::class, fn() => $pdo->exec(
            "INSERT INTO state_machine_region (instance_id, region, machine_key, current_state, sort_order) VALUES ('nobody', 'r', 'k', 's', 0)"
        ), 'A region row needs its instance (FK)');
    }

    private function testSchemaIdentifiersAndColumnMap(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            return;
        }
        $pdo = new PDO('sqlite::memory:');
        $this->assertThrows(InvalidArgumentException::class, fn() => new PdoMachineSchema($pdo, 'bad schema'));
        $this->assertThrows(InvalidArgumentException::class, fn() => new PdoMachineSchema($pdo, '', ['nope' => 'x']));
        $this->assertThrows(InvalidArgumentException::class, fn() => new PdoMachineSchema($pdo, '', ['instance' => 'x; DROP']));
        $this->assertThrows(InvalidArgumentException::class, fn() => new PdoMachineSchema($pdo, '', [], ['unknown_column' => 'x']));
        $this->assertThrows(InvalidArgumentException::class, fn() => new PdoMachineSchema($pdo, '', [], ['instance_id' => 'a-b']));
        $schema = new PdoMachineSchema($pdo, 'wf', ['history' => 'trail']);
        $this->assertEquals('wf.trail', $schema->table('history'));
        $this->assertEquals('wf.state_machine_outbox', $schema->table('outbox'));
        $this->assertTrue(in_array('due_at', PdoMachineSchema::canonicalColumns(), true));

        // A consumer schema: other table names and `process_id` instead of `instance_id` everywhere.
        $ddl = str_replace(['state_machine_', 'instance_id', 'due_at'], ['wf_', 'process_id', 'deadline_at'], (string) file_get_contents(self::DDL));
        $pdo = $this->sqlite($ddl);
        $tables = [];
        foreach (PdoMachineSchema::DEFAULT_TABLES as $role => $table) {
            $tables[$role] = str_replace('state_machine_', 'wf_', $table);
        }
        $persistence = MachinePersistence::pdo($pdo, '', $tables, ['instance_id' => 'process_id', 'due_at' => 'deadline_at']);
        $runner = $this->runner($persistence, [$this->deadlineMachine()]);
        $t0 = $this->at('2026-10-10T10:00:00+00:00');
        $runner->start('doc-1', 'deadline', null, null, $t0);
        $this->assertTrue($runner->send('doc-1', Event::external('COMMENT', $t0->modify('+1 hour'), ['text' => 'hi']))->isAccepted());
        $this->assertEquals(2, $runner->load('doc-1')->version);
        $this->assertCount(2, $persistence->history->findByInstance('doc-1'));
        $this->assertCount(2, $persistence->outbox->findByInstance('doc-1'));
        $due = $runner->findDue($t0->modify('+3 days'));
        $this->assertCount(1, $due, 'findDue compiles the specification with the mapped columns');
        $this->assertEquals('doc-1', $due[0]->instanceId);
        $this->assertEquals(2, (int) $pdo->query('SELECT COUNT(*) FROM wf_history WHERE process_id = \'doc-1\'')->fetchColumn());
    }

    // ==========================================
    // 2. Battery (InMemory and Pdo)
    // ==========================================

    /**
     * Acceptance §4 item 1 through the runner: the Virttus spine with a pending item registered and
     * resolved at proposal_sent; one history row per step with the D15 fields; rejections write nothing.
     *
     * @param Closure(bool): MachinePersistence $factory
     */
    private function testVirttusSpineHistory(string $label, Closure $factory): void
    {
        $persistence = $factory(false);
        $runner = $this->runner($persistence, [$this->virttus()], $this->virttusGuards());
        $t0 = $this->at('2026-10-10T09:00:00-03:00');
        $context = ['role' => 'operator'];

        $start = $runner->start('quote-1', 'virttus.rental_guarantee_quote', null, $context, $t0, 'ana');
        $this->assertTrue($start->isAccepted(), "{$label}: start accepted");
        $this->assertEquals(['progress' => 'registering', 'pending' => 'none'], $runner->load('quote-1')->snapshot->getStates());
        $this->assertEquals(1, $runner->load('quote-1')->version);

        foreach (self::VIRTTUS_STEPS as $step => [$event, $region, $from, $to, $id]) {
            $at = $t0->modify('+' . ($step + 1) . ' hours');
            $payload = $event === 'REGISTER_PENDING' ? ['motive' => 'missing_document'] : [];
            $result = $runner->send('quote-1', Event::external($event, $at, $payload, "click-{$step}"), $context, 'ana');
            $this->assertTrue($result->isAccepted(), "{$label}: {$event} accepted: " . $result->getReason());

            if ($event === 'REGISTER_PENDING') {
                $versionBefore = $runner->load('quote-1')->version;
                $historyBefore = count($persistence->history->findByInstance('quote-1'));
                $blocked = $runner->send('quote-1', Event::external('APPROVE_PROPOSAL_QUOTATION', $at, [], 'blocked-click'), $context, 'ana');
                $this->assertTrue($blocked->isRejected(), "{$label}: progress blocked while pending is open");
                $this->assertEquals(['QUOTE_PENDING_OPEN'], $blocked->getReason()->getCodes());
                $this->assertEquals($versionBefore, $runner->load('quote-1')->version, "{$label}: a rejection does not bump the version");
                $this->assertCount($historyBefore, $persistence->history->findByInstance('quote-1'), "{$label}: a rejection writes no history (RN-07)");
            }
        }

        $instance = $runner->load('quote-1');
        $this->assertEquals(['progress' => 'completed', 'pending' => 'none'], $instance->snapshot->getStates());
        $this->assertTrue($instance->snapshot->isDone(), "{$label}: completed is done");
        $this->assertEquals(1 + count(self::VIRTTUS_STEPS), $instance->version);
        $this->assertEquals('2026-10-10 20:00:00.000000', PersistenceSupport::toText($instance->updatedAt));
        $this->assertEquals('2026-10-10 12:00:00.000000', PersistenceSupport::toText($instance->createdAt));

        $history = $persistence->history->findByInstance('quote-1');
        $this->assertCount(2 + count(self::VIRTTUS_STEPS), $history, "{$label}: two start rows + one row per step");
        $this->assertEquals(
            [
                '1.0 machine.start progress:->registering',
                '1.1 machine.start pending:->none',
                '2.0 COMPLETE_CALCULATION progress:registering>calculation_done',
                '3.0 REQUEST_CLIENT_DOCUMENTS progress:calculation_done>awaiting_client_documents',
                '4.0 CLIENT_DOCUMENTS_RECEIVED progress:awaiting_client_documents>awaiting_hiring_proposal',
                '5.0 SEND_PROPOSAL progress:awaiting_hiring_proposal>proposal_sent',
                '6.0 REGISTER_PENDING pending:none>open',
                '7.0 RESOLVE_PENDING pending:open>none',
                '8.0 APPROVE_PROPOSAL_QUOTATION progress:proposal_sent>approve_proposal_quotation',
                '9.0 COMPLETE_PROPOSAL progress:approve_proposal_quotation>completed',
            ],
            $this->historyLabels($history),
            "{$label}: the history tells the whole story"
        );
        foreach (self::VIRTTUS_STEPS as $step => [$event, $region, $from, $to, $id]) {
            $row = $history[$step + 2];
            $this->assertEquals([$region, $from, $to, $id], [$row->region, $row->fromState, $row->toState, $row->transitionId]);
            $this->assertEquals('virttus.rental_guarantee_quote', $row->machineKey);
            $this->assertEquals(1, $row->machineVersion);
            $this->assertEquals('ana', $row->actor);
            $this->assertEquals("click-{$step}", $row->idempotencyKey);
            $this->assertEquals(EventOrigin::EXTERNAL, $row->origin);
        }
        $this->assertEquals(['motive' => 'missing_document'], $history[6]->payload, "{$label}: the motive is payload (D15)");
        $this->assertEquals(EventOrigin::INTERNAL, $history[0]->origin);
        $this->assertEquals(null, $history[0]->transitionId);
        $this->assertEquals([], $persistence->outbox->findByInstance('quote-1'), "{$label}: the fixture declares no action");

        // Done is inert through the runner too: unhandled, nothing written.
        $after = $runner->send('quote-1', Event::external('REGISTER_PENDING', $t0->modify('+1 day')));
        $this->assertTrue($after->isUnhandled());
        $this->assertEquals([TransitionResult::CODE_MACHINE_DONE], $after->getReason()->getCodes());
        $this->assertCount(2 + count(self::VIRTTUS_STEPS), $persistence->history->findByInstance('quote-1'));
    }

    /**
     * @param Closure(bool): MachinePersistence $factory
     */
    private function testStartAndSendErrors(string $label, Closure $factory): void
    {
        $persistence = $factory(false);
        $runner = $this->runner($persistence, [$this->mailMachine()]);
        $t0 = $this->at('2026-10-10T10:00:00+00:00');
        $runner->start('m-1', 'mail', 1, null, $t0);

        $this->assertThrows(MachineInstanceAlreadyExistsException::class, fn() => $runner->start('m-1', 'mail', 1, null, $t0), "{$label}: start is not idempotent by id");
        $this->assertThrows(MachineInstanceAlreadyExistsException::class, fn() => $persistence->instances->insert($runner->load('m-1')), "{$label}: insert refuses a taken id");
        $this->assertThrows(UnknownMachineDefinitionException::class, fn() => $runner->start('m-2', 'mail', 7, null, $t0));
        $this->assertThrows(UnknownMachineDefinitionException::class, fn() => $runner->start('m-2', 'nope', null, null, $t0));
        $this->assertThrows(InvalidArgumentException::class, fn() => $runner->start('', 'mail', 1, null, $t0));
        $this->assertThrows(InvalidArgumentException::class, fn() => $runner->start(str_repeat('x', 121), 'mail', 1, null, $t0));
        $this->assertThrows(MachineInstanceNotFoundException::class, fn() => $runner->send('ghost', Event::external('GO', $t0)));
        $this->assertThrows(MachineInstanceNotFoundException::class, fn() => $runner->load('ghost'));
        $this->assertThrows(InvalidArgumentException::class, fn() => $runner->send('m-1', Event::external(MachineRunner::START_EVENT, $t0)), 'Reserved event types are refused');
        $this->assertThrows(InvalidArgumentException::class, fn() => $runner->send('m-1', Event::external(MachineRunner::MIGRATE_EVENT, $t0)));
        $this->assertEquals(null, $persistence->instances->load('m-2'), "{$label}: failed starts wrote nothing");

        // A definition refused by the validator never runs.
        $broken = Spec::machine('broken', 1)->region('r', 'a')->state('a')->on('GO', 'missing')->build();
        $this->assertThrows(InvalidMachineDefinitionException::class, fn() => $this->runner($factory(false), [$broken])->start('b-1', 'broken', 1, null, $t0));

        // The repository refuses another definition under a registered (key, version).
        $repository = new InMemoryMachineDefinitionRepository([$this->mailMachine()]);
        $repository->register($this->mailMachine()); // same fingerprint: no-op
        $this->assertThrows(InvalidMachineDefinitionException::class, fn() => $repository->register(Spec::machine('mail', 1)->region('r', 'z')->state('z')->build()));
        $this->assertEquals(1, $repository->latestVersion('mail'));
        $this->assertEquals(null, $repository->latestVersion('none'));
    }

    /**
     * RN-08: two runners over the same store; the second writer of the same version loses.
     *
     * @param Closure(bool): MachinePersistence $factory
     */
    private function testConcurrencyTwoRunners(string $label, Closure $factory): void
    {
        $persistence = $factory(false);
        $definitions = new InMemoryMachineDefinitionRepository([$this->mailMachine()]);
        $runnerA = new MachineRunner(new StateMachine(), $definitions, $persistence);
        $runnerB = new MachineRunner(new StateMachine(), $definitions, $persistence);
        $t0 = $this->at('2026-10-10T10:00:00+00:00');
        $runnerA->start('m-1', 'mail', 1, null, $t0);

        // Both users opened the screen at version 1.
        $seenByA = $runnerA->load('m-1')->version;
        $seenByB = $runnerB->load('m-1')->version;
        $this->assertEquals(1, $seenByA);

        $this->assertTrue($runnerB->send('m-1', Event::external('GO', $t0->modify('+1 minute')), null, 'bob', $seenByB)->isAccepted());
        $conflict = $this->assertThrows(
            ConcurrencyConflictException::class,
            fn() => $runnerA->send('m-1', Event::external('GO', $t0->modify('+2 minutes')), null, 'ana', $seenByA),
            "{$label}: the stale writer loses"
        );
        \assert($conflict instanceof ConcurrencyConflictException);
        $this->assertEquals(['m-1', 1, 2], [$conflict->getInstanceId(), $conflict->getExpectedVersion(), $conflict->getActualVersion()]);
        $this->assertEquals(2, $runnerA->load('m-1')->version, "{$label}: only B's step was written");
        $this->assertEquals(['1.0 machine.start r:->a', '2.0 GO r:a>b'], $this->historyLabels($persistence->history->findByInstance('m-1')));

        // The store's compare-and-set itself.
        $current = $persistence->instances->load('m-1');
        $this->assertThrows(ConcurrencyConflictException::class, fn() => $persistence->instances->compareAndSet($current->next($current->snapshot, $t0), 1));
        $this->assertThrows(ConcurrencyConflictException::class, fn() => $persistence->instances->compareAndSet(
            new MachineInstance('nobody', $current->snapshot, 2, $t0, $t0),
            1
        ), "{$label}: CAS on a missing instance is a conflict");
    }

    /**
     * RN-08 inside one send(): another writer bumps the version between the load and the CAS; the
     * CAS fails, the exception reaches the caller (no internal retry) and nothing is written.
     *
     * @param Closure(bool): MachinePersistence $factory
     */
    private function testConcurrencyInsideTheTransaction(string $label, Closure $factory): void
    {
        $persistence = $factory(false);
        $t0 = $this->at('2026-10-10T10:00:00+00:00');
        $this->runner($persistence, [$this->mailMachine()])->start('m-1', 'mail', 1, null, $t0);
        $outboxBefore = count($persistence->outbox->findByInstance('m-1'));

        $racing = new class ($persistence->instances) implements IMachineInstanceStore {
            public int $races = 0;

            public function __construct(private readonly IMachineInstanceStore $inner)
            {
            }

            public function load(string $instanceId): ?MachineInstance
            {
                $loaded = $this->inner->load($instanceId);
                if ($loaded !== null && $this->races === 0) {
                    // The concurrent writer commits its step right after our read.
                    $this->races++;
                    $this->inner->compareAndSet($loaded->next($loaded->snapshot, $loaded->updatedAt), $loaded->version);
                }

                return $loaded;
            }

            public function insert(MachineInstance $instance): void
            {
                $this->inner->insert($instance);
            }

            public function compareAndSet(MachineInstance $instance, int $expectedVersion): void
            {
                $this->inner->compareAndSet($instance, $expectedVersion);
            }

            public function findDue(DateTimeImmutable $now, ?string $machineKey = null, int $limit = 100): array
            {
                return $this->inner->findDue($now, $machineKey, $limit);
            }
        };
        $raced = new MachinePersistence($racing, $persistence->history, $persistence->outbox, $persistence->idempotency, $persistence->transactions);
        $runner = $this->runner($raced, [$this->mailMachine()]);

        $this->assertThrows(ConcurrencyConflictException::class, fn() => $runner->send('m-1', Event::external('GO', $t0, [], 'go-1')), "{$label}: CAS lost inside send()");
        $this->assertEquals(1, $racing->races, 'The runner did not retry by itself');
        $this->assertEquals(['1.0 machine.start r:->a'], $this->historyLabels($persistence->history->findByInstance('m-1')), "{$label}: no history written");
        $this->assertCount($outboxBefore, $persistence->outbox->findByInstance('m-1'), "{$label}: no outbox written");
        $this->assertEquals(null, $persistence->idempotency->find('m-1', 'go-1'), "{$label}: no idempotency record");

        // The caller retries: the key was never recorded, so the event is evaluated again.
        $retry = $runner->send('m-1', Event::external('GO', $t0, [], 'go-1'));
        $this->assertTrue($retry->isAccepted());
    }

    /**
     * RN-09: a repeated key gives the ORIGINAL result back and writes nothing; keys are per
     * instance; events without key are never deduplicated; rejected events are not recorded.
     *
     * @param Closure(bool): MachinePersistence $factory
     */
    private function testIdempotency(string $label, Closure $factory): void
    {
        $persistence = $factory(false);
        $runner = $this->runner($persistence, [$this->mailMachine()]);
        $t0 = $this->at('2026-10-10T10:00:00-03:00');
        $runner->start('m-1', 'mail', 1, null, $t0);
        $runner->start('m-2', 'mail', 1, null, $t0);

        $original = $runner->send('m-1', Event::external('GO', $t0->modify('+1 hour'), ['by' => 'first'], 'click-42'), null, 'ana');
        $this->assertTrue($original->isAccepted());
        $runner->send('m-1', Event::external('BACK', $t0->modify('+2 hours')));
        $version = $runner->load('m-1')->version;
        $history = count($persistence->history->findByInstance('m-1'));
        $outbox = count($persistence->outbox->findByInstance('m-1'));

        $replay = $runner->send('m-1', Event::external('GO', $t0->modify('+3 hours'), ['by' => 'second'], 'click-42'), null, 'bob');
        $this->assertEquals($original->toArray(), $replay->toArray(), "{$label}: the original result comes back");
        $this->assertEquals('b', $replay->getSnapshot()->stateOf('r'), 'The snapshot after the ORIGINAL event, not the current one');
        $this->assertEquals('2026-10-10T11:00:00.000000-03:00', $replay->getEvent()->toArray()['occurred_at'], 'The original event, offset included');
        $this->assertEquals(['by' => 'first'], $replay->getEvent()->payload);
        $this->assertEquals(['exit:a_exit', 'transition:t_go', 'entry:b_entry'], array_map(static fn(Effect $e): string => $e->phase->value . ':' . $e->action, $replay->getEffects()));
        $this->assertEquals('go', $replay->getTransitions()[0]->transitionId);
        $this->assertEquals($version, $runner->load('m-1')->version, "{$label}: nothing written (version)");
        $this->assertCount($history, $persistence->history->findByInstance('m-1'), "{$label}: nothing written (history)");
        $this->assertCount($outbox, $persistence->outbox->findByInstance('m-1'), "{$label}: nothing written (outbox)");
        $this->assertEquals('a', $runner->load('m-1')->snapshot->stateOf('r'));

        // The key is unique per instance: the same key on another instance is a new event.
        $this->assertTrue($runner->send('m-2', Event::external('GO', $t0, [], 'click-42'))->isAccepted(), "{$label}: keys are per instance");
        $this->assertEquals(2, $runner->load('m-2')->version);

        // Without key, never deduplicated: the targetless NOTE runs twice.
        $runner->send('m-1', Event::external('NOTE', $t0->modify('+4 hours')));
        $runner->send('m-1', Event::external('NOTE', $t0->modify('+4 hours')));
        $this->assertEquals($version + 2, $runner->load('m-1')->version, "{$label}: two keyless events, two steps");
        $notes = array_values(array_filter($persistence->history->findByInstance('m-1'), static fn(HistoryEntry $h): bool => $h->eventType === 'NOTE'));
        $this->assertCount(2, $notes);
        $this->assertEquals(['a', 'a', 'note'], [$notes[0]->fromState, $notes[0]->toState, $notes[0]->transitionId], 'Targetless row: from = to');

        // A rejected/unhandled keyed event is not recorded: the same key is evaluated again later.
        $unhandled = $runner->send('m-1', Event::external('BACK', $t0->modify('+5 hours'), [], 'back-1'));
        $this->assertTrue($unhandled->isUnhandled());
        $this->assertEquals(null, $persistence->idempotency->find('m-1', 'back-1'));
        $runner->send('m-1', Event::external('GO', $t0->modify('+6 hours')));
        $this->assertTrue($runner->send('m-1', Event::external('BACK', $t0->modify('+7 hours'), [], 'back-1'))->isAccepted(), "{$label}: same key accepted once possible");
        $this->assertTrue($persistence->idempotency->find('m-1', 'back-1')?->isAccepted() ?? false);

        // Direct store contract: a second record of the same key is a conflict.
        $this->assertThrows(ConcurrencyConflictException::class, fn() => $persistence->idempotency->record('m-1', 'back-1', 99, $original, $t0));
    }

    /**
     * RN-10: effects written with the step, delivered after the commit in write order per
     * instance; a failure increments attempts, keeps the message without stack trace, holds the
     * instance's later effects back, never undoes the transition, and is retried.
     *
     * @param Closure(bool): MachinePersistence $factory
     */
    private function testOutboxDispatch(string $label, Closure $factory): void
    {
        $persistence = $factory(false);
        $runner = $this->runner($persistence, [$this->mailMachine()]);
        $t0 = $this->at('2026-10-10T10:00:00+00:00');
        $runner->start('m-1', 'mail', 1, null, $t0);
        $runner->start('m-2', 'mail', 1, null, $t0->modify('+1 second'));
        $runner->send('m-1', Event::external('GO', $t0->modify('+1 minute')));
        $runner->send('m-1', Event::external('BACK', $t0->modify('+2 minutes')));

        $this->assertEquals(
            [
                'm-1:1:0 entry/a_entry pending#0',
                'm-1:2:0 exit/a_exit pending#0',
                'm-1:2:1 transition/t_go pending#0',
                'm-1:2:2 entry/b_entry pending#0',
                'm-1:3:0 entry/a_entry pending#0',
            ],
            $this->outboxLabels($persistence->outbox->findByInstance('m-1')),
            "{$label}: effects in the outbox, SCXML order, same transaction as the step"
        );

        $calls = [];
        $failGo = true;
        $handlers = new EffectHandlerRegistry();
        $record = static function (Effect $effect, string $key, string $instanceId) use (&$calls): void {
            $calls[] = "{$instanceId}:{$effect->action}@{$key}";
        };
        foreach (['a_entry', 'a_exit', 'b_entry'] as $action) {
            $handlers->register($action, $record);
        }
        $handlers->register('t_go', static function (Effect $effect, string $key, string $instanceId) use (&$calls, &$failGo): void {
            if ($failGo) {
                throw new RuntimeException("SMTP down\nStack trace:\n#0 /srv/mail.php(12): send()\n#1 {main}");
            }
            $calls[] = "{$instanceId}:{$effect->action}@{$key}";
        });
        $clock = $t0->modify('+1 hour');
        $dispatcher = new EffectDispatcher($persistence->outbox, $handlers, null, static function () use (&$clock): DateTimeImmutable {
            return $clock;
        });

        $report = $dispatcher->dispatchPending(100);
        $this->assertEquals(
            ['m-1:a_entry@m-1:1:0', 'm-2:a_entry@m-2:1:0', 'm-1:a_exit@m-1:2:0'],
            $calls,
            "{$label}: in order per instance; m-1 stops at the failed t_go; m-2 is not held back"
        );
        $this->assertEquals(3, $report->deliveredCount());
        $this->assertEquals(1, $report->failedCount());
        $this->assertEquals(['m-1:2:1' => 'RuntimeException: SMTP down'], $report->getFailures(), "{$label}: the message, never the stack trace");

        $entries = $persistence->outbox->findByInstance('m-1');
        $this->assertEquals(
            [
                'm-1:1:0 entry/a_entry delivered#1',
                'm-1:2:0 exit/a_exit delivered#1',
                'm-1:2:1 transition/t_go failed#1',
                'm-1:2:2 entry/b_entry pending#0',
                'm-1:3:0 entry/a_entry pending#0',
            ],
            $this->outboxLabels($entries)
        );
        $failed = $entries[2];
        $this->assertEquals('RuntimeException: SMTP down', $failed->lastError);
        $this->assertFalse(str_contains((string) $failed->lastError, '#0'), 'No stack frame recorded');
        $this->assertEquals('2026-10-10 11:00:00.000000', PersistenceSupport::toText($failed->lastAttemptAt));
        $this->assertEquals(null, $failed->deliveredAt);
        $this->assertEquals('2026-10-10 11:00:00.000000', PersistenceSupport::toText($entries[0]->deliveredAt));
        $this->assertEquals('a', $runner->load('m-1')->snapshot->stateOf('r'), "{$label}: a failing handler never undoes the transition");
        $this->assertEquals(3, $runner->load('m-1')->version);

        // Heads: one per instance, the failed entry is still m-1's head.
        $heads = $persistence->outbox->fetchPending(10);
        $this->assertEquals(['m-1:2:1'], array_map(static fn(OutboxEntry $e): string => $e->effectKey(), $heads));

        // Retry once the mail server is back: the rest follows in order.
        $failGo = false;
        $calls = [];
        $clock = $t0->modify('+2 hours');
        $report = $dispatcher->dispatchPending(100);
        $this->assertEquals(['m-1:t_go@m-1:2:1', 'm-1:b_entry@m-1:2:2', 'm-1:a_entry@m-1:3:0'], $calls);
        $this->assertEquals(0, $report->failedCount());
        $retried = $persistence->outbox->findByInstance('m-1')[2];
        $this->assertEquals([OutboxStatus::DELIVERED, 2, 'RuntimeException: SMTP down'], [$retried->status, $retried->attempts, $retried->lastError], "{$label}: attempts count both tries; the last error stays as a trace");
        $this->assertEquals([], $persistence->outbox->fetchPending(10), 'Nothing pending');
        $this->assertEquals(0, $dispatcher->dispatchPending(5)->deliveredCount(), 'A second run delivers nothing');

        // The limit bounds the attempts of a run.
        $runner->send('m-2', Event::external('GO', $t0->modify('+3 hours')));
        $calls = [];
        $this->assertEquals(2, $dispatcher->dispatchPending(2)->deliveredCount());
        $this->assertEquals(['m-2:a_exit@m-2:2:0', 'm-2:t_go@m-2:2:1'], $calls);
        $this->assertEquals(1, $dispatcher->dispatchPending(2)->deliveredCount());
        $this->assertThrows(InvalidArgumentException::class, fn() => $dispatcher->dispatchPending(0));
    }

    /**
     * RN-10 edges: an action without handler fails like a throwing handler; with maxAttempts an
     * exhausted entry stops being attempted and stops holding its successors back.
     *
     * @param Closure(bool): MachinePersistence $factory
     */
    private function testOutboxExhaustionAndMissingHandler(string $label, Closure $factory): void
    {
        $persistence = $factory(false);
        $runner = $this->runner($persistence, [$this->mailMachine()]);
        $t0 = $this->at('2026-10-10T10:00:00+00:00');
        $runner->start('m-1', 'mail', 1, null, $t0);
        $runner->send('m-1', Event::external('GO', $t0));

        $calls = [];
        $handlers = (new EffectHandlerRegistry())
            ->register('a_exit', static function (Effect $e) use (&$calls): void {
                $calls[] = $e->action;
            })
            ->register('t_go', static function (Effect $e) use (&$calls): void {
                $calls[] = $e->action;
            })
            ->register('b_entry', static function (Effect $e) use (&$calls): void {
                $calls[] = $e->action;
            });
        // a_entry has no handler.
        $dispatcher = new EffectDispatcher($persistence->outbox, $handlers, 2, static fn(): DateTimeImmutable => $t0);

        $report = $dispatcher->dispatchPending();
        $this->assertEquals(['m-1:1:0' => 'No handler registered for action "a_entry".'], $report->getFailures(), "{$label}: missing handler");
        $this->assertEquals([], $calls, 'The later effects wait');
        $report = $dispatcher->dispatchPending();
        $this->assertEquals(1, $report->failedCount());
        $this->assertEquals([], $calls);
        $exhausted = $persistence->outbox->findByInstance('m-1')[0];
        $this->assertEquals([OutboxStatus::FAILED, 2], [$exhausted->status, $exhausted->attempts]);

        // Exhausted after 2 attempts: no longer attempted, no longer blocking.
        $report = $dispatcher->dispatchPending();
        $this->assertEquals(['a_exit', 't_go', 'b_entry'], $calls, "{$label}: the successors go once the head is exhausted");
        $this->assertEquals(0, $report->failedCount());
        $this->assertEquals([], $persistence->outbox->fetchPending(10, 2));
        $this->assertEquals(['m-1:1:0'], array_map(static fn(OutboxEntry $e): string => $e->effectKey(), $persistence->outbox->fetchPending(10)), 'Without a limit it is still a head');
        $this->assertThrows(InvalidArgumentException::class, fn() => new EffectDispatcher($persistence->outbox, $handlers, 0));
    }

    /**
     * D12: a synchronous effect runs inside the transaction; its exception rolls everything back;
     * when it succeeds it is written to the outbox already delivered.
     *
     * @param Closure(bool): MachinePersistence $factory
     */
    private function testSyncEffects(string $label, Closure $factory): void
    {
        $persistence = $factory(false);
        $veto = true;
        $seen = [];
        $handlers = (new EffectHandlerRegistry())
            ->registerSync('t_go', function (Effect $effect, string $key, string $instanceId) use (&$veto, &$seen, $persistence): void {
                // Inside the transaction: the new snapshot is already visible to the same connection.
                $seen[] = [$key, $persistence->instances->load($instanceId)?->version];
                if ($veto) {
                    throw new RuntimeException('Credit limit exceeded');
                }
            })
            ->register('a_exit', static fn() => null);
        $this->assertTrue($handlers->isSync('t_go'));
        $this->assertFalse($handlers->isSync('a_exit'));
        $this->assertFalse($handlers->isSync('unknown'));
        $this->assertEquals(['t_go', 'a_exit'], $handlers->names());

        $runner = $this->runner($persistence, [$this->mailMachine()], null, $handlers);
        $t0 = $this->at('2026-10-10T10:00:00+00:00');
        $runner->start('m-1', 'mail', 1, null, $t0);

        $e = $this->assertThrows(SyncEffectFailedException::class, fn() => $runner->send('m-1', Event::external('GO', $t0, [], 'go-1')), "{$label}: the veto");
        \assert($e instanceof SyncEffectFailedException);
        $this->assertEquals('t_go', $e->getEffect()->action);
        $this->assertEquals('m-1', $e->getInstanceId());
        $this->assertInstanceOf(RuntimeException::class, $e->getPrevious());
        $this->assertTrue(str_contains($e->getMessage(), 'Credit limit exceeded'));
        $this->assertEquals([['m-1:2:1', 2]], $seen, "{$label}: ran after the CAS, inside the transaction");
        $this->assertEquals(1, $runner->load('m-1')->version, "{$label}: rolled back (version)");
        $this->assertEquals('a', $runner->load('m-1')->snapshot->stateOf('r'));
        $this->assertCount(1, $persistence->history->findByInstance('m-1'), "{$label}: rolled back (history)");
        $this->assertCount(1, $persistence->outbox->findByInstance('m-1'), "{$label}: rolled back (outbox)");
        $this->assertEquals(null, $persistence->idempotency->find('m-1', 'go-1'), "{$label}: rolled back (idempotency)");

        $veto = false;
        $this->assertTrue($runner->send('m-1', Event::external('GO', $t0, [], 'go-1'))->isAccepted());
        $this->assertEquals(
            ['m-1:1:0 entry/a_entry pending#0', 'm-1:2:0 exit/a_exit pending#0', 'm-1:2:1 transition/t_go delivered#1', 'm-1:2:2 entry/b_entry pending#0'],
            $this->outboxLabels($persistence->outbox->findByInstance('m-1')),
            "{$label}: the sync effect is in the outbox, already delivered"
        );

        // A synchronous entry effect that vetoes the start: no instance.
        $startVeto = (new EffectHandlerRegistry())->registerSync('a_entry', static fn() => throw new RuntimeException('no'));
        $vetoRunner = $this->runner($persistence, [$this->mailMachine()], null, $startVeto);
        $this->assertThrows(SyncEffectFailedException::class, fn() => $vetoRunner->start('m-9', 'mail', 1, null, $t0));
        $this->assertEquals(null, $persistence->instances->load('m-9'), "{$label}: start rolled back");
        $this->assertEquals([], $persistence->history->findByInstance('m-9'));
    }

    /**
     * D18, RN-06: the deadline is persisted per region, found by findDue(now), delivered by the
     * runner with internal origin; late or repeated deliveries are harmless.
     *
     * @param Closure(bool): MachinePersistence $factory
     */
    private function testDeadlines(string $label, Closure $factory): void
    {
        $persistence = $factory(false);
        $runner = $this->runner($persistence, [$this->deadlineMachine(), $this->mailMachine()]);
        $t0 = $this->at('2026-10-10T10:00:00-03:00'); // 13:00 UTC
        $runner->start('doc-b', 'deadline', 1, null, $t0);
        $runner->start('doc-a', 'deadline', 1, null, $t0);
        $runner->start('doc-c', 'deadline', 1, null, $t0->modify('+1 day'));
        $runner->start('mail-1', 'mail', 1, null, $t0);

        $this->assertEquals([], $runner->findDue($t0->modify('+2 days')), "{$label}: nothing due yet");
        $due = $runner->findDue($t0->modify('+3 days'));
        $this->assertEquals(
            [
                ['instance_id' => 'doc-a', 'machine_key' => 'deadline', 'region' => 'doc', 'state' => 'delivered', 'due_at' => '2026-10-13 13:00:00.000000'],
                ['instance_id' => 'doc-b', 'machine_key' => 'deadline', 'region' => 'doc', 'state' => 'delivered', 'due_at' => '2026-10-13 13:00:00.000000'],
            ],
            array_map(static fn(DueDeadline $d): array => $d->toArray(), $due),
            "{$label}: due at exactly dueAt (inclusive), ordered by due instant then id"
        );
        $this->assertCount(3, $runner->findDue($t0->modify('+5 days')));
        $this->assertCount(1, $runner->findDue($t0->modify('+5 days'), null, 1), 'limit');
        $this->assertCount(0, $runner->findDue($t0->modify('+5 days'), 'mail'), 'machine key filter');
        $this->assertCount(3, $runner->findDue($t0->modify('+5 days'), 'deadline'));

        // Sent from outside or before it is due: rejected, nothing written.
        $this->assertTrue($runner->send('doc-a', Event::external('EXPIRE', $t0->modify('+4 days')))->isRejected());
        $early = $runner->send('doc-a', Event::internal('EXPIRE', $t0->modify('+1 day')));
        $this->assertEquals([TransitionResult::CODE_DEADLINE_NOT_DUE], $early->getReason()->getCodes());
        $this->assertEquals(1, $runner->load('doc-a')->version);

        // Reentry recomputes the deadline (RN-06): doc-b resubmitted at +2 days is due at +5 days.
        $runner->send('doc-b', Event::external('RESUBMIT', $t0->modify('+2 days')));
        $this->assertEquals(['doc-a'], array_map(static fn(DueDeadline $d): string => $d->instanceId, $runner->findDue($t0->modify('+3 days'))));

        // The cron at +3 days delivers doc-a only.
        $deliveries = $runner->deliverDue($t0->modify('+3 days'), null, 100, 'scheduler');
        $this->assertCount(1, $deliveries);
        $this->assertTrue($deliveries[0]->isDelivered(), "{$label}: deadline delivered");
        $this->assertFalse($deliveries[0]->isSkipped());
        $this->assertEquals('expired', $runner->load('doc-a')->snapshot->stateOf('doc'));
        $row = $persistence->history->findByInstance('doc-a')[1];
        $this->assertEquals(['EXPIRE', 'expire', EventOrigin::INTERNAL, 'scheduler'], [$row->eventType, $row->transitionId, $row->origin, $row->actor]);
        $this->assertEquals('deadline:doc:2026-10-13 13:00:00.000000', $row->idempotencyKey);
        $this->assertEquals(['region' => 'doc', 'due_at' => '2026-10-13T10:00:00.000000-03:00'], $row->payload);
        $this->assertEquals([], $runner->findDue($t0->modify('+3 days')), 'Expired: no deadline any more');

        // A repeated delivery of the same deadline event gives the original result, writes nothing.
        $again = $runner->send('doc-a', Event::internal('EXPIRE', $t0->modify('+9 days'), [], 'deadline:doc:2026-10-13 13:00:00.000000'));
        $this->assertTrue($again->isAccepted());
        $this->assertEquals($deliveries[0]->result->toArray(), $again->toArray());
        $this->assertCount(2, $persistence->history->findByInstance('doc-a'));
        // A late delivery with another key is unhandled (done), harmless.
        $this->assertTrue($runner->send('doc-a', Event::internal('EXPIRE', $t0->modify('+9 days')))->isUnhandled());

        // The cron at +6 days: doc-b and doc-c; a done instance is never due again.
        $runner->send('doc-c', Event::external('VALIDATE', $t0->modify('+2 days')));
        $later = $runner->deliverDue($t0->modify('+6 days'));
        $this->assertEquals(['doc-b'], array_map(static fn($d): string => $d->deadline->instanceId, $later), "{$label}: doc-c validated (done) is not due");
        $this->assertEquals('expired', $runner->load('doc-b')->snapshot->stateOf('doc'));
        $this->assertEquals([], $runner->deliverDue($t0->modify('+30 days')), 'Nothing left');

        // An auxiliary region keeps its deadline in the snapshot after the machine is done, but a
        // done instance is inert, so it is never due (no due instant in the projection).
        $aux = Spec::machine('aux', 1)
            ->region('main', 'open')->state('open')->on('CLOSE', 'closed', 'close')->finalState('closed')
            ->region('reminder', 'waiting')->state('waiting')->deadline('P1D', 'REMIND')->on('REMIND', 'waiting', 'remind')
            ->build();
        $auxRunner = $this->runner($persistence, [$aux]);
        $auxRunner->start('aux-1', 'aux', 1, null, $t0);
        $this->assertCount(1, $auxRunner->findDue($t0->modify('+2 days'), 'aux'));
        $auxRunner->send('aux-1', Event::external('CLOSE', $t0->modify('+1 hour')));
        $this->assertTrue($auxRunner->load('aux-1')->snapshot->isDone());
        $this->assertTrue($auxRunner->load('aux-1')->snapshot->dueAt('reminder') !== null, 'The snapshot keeps the auxiliary deadline');
        $this->assertEquals([], $auxRunner->findDue($t0->modify('+2 days'), 'aux'), "{$label}: a done instance is never due");
    }

    /**
     * D16: an instance runs on the version it was born with; a new version does not touch it;
     * migrating is explicit, checked against the target and recorded in the history.
     *
     * @param Closure(bool): MachinePersistence $factory
     */
    private function testPinnedVersionAndMigration(string $label, Closure $factory): void
    {
        $v1 = Spec::machine('order', 1)
            ->region('flow', 'draft')
                ->state('draft')->on('SUBMIT', 'submitted', 'submit')
                ->state('submitted')->on('APPROVE', 'approved', 'approve')
                ->finalState('approved')
            ->build();
        $v2 = Spec::machine('order', 2)
            ->region('flow', 'draft')
                ->state('draft')->on('SUBMIT', 'in_review', 'submit')
                ->state('in_review')->on('APPROVE', 'approved', 'approve')->on('REJECT', 'draft', 'reject')
                ->finalState('approved')
            ->region('audit', 'clean')
                ->state('clean')->on('FLAG', 'flagged', 'flag')
                ->state('flagged')->on('CLEAR', 'clean', 'clear')
            ->build();
        $persistence = $factory(false);
        $definitions = new InMemoryMachineDefinitionRepository([$v1]);
        $runner = new MachineRunner(new StateMachine(), $definitions, $persistence);
        $t0 = $this->at('2026-10-10T10:00:00+00:00');

        $runner->start('o-1', 'order', null, null, $t0);
        $runner->send('o-1', Event::external('SUBMIT', $t0));
        $definitions->register($v2);
        $runner->start('o-2', 'order', null, null, $t0);
        $this->assertEquals(2, $runner->load('o-2')->getMachineVersion(), "{$label}: new instances start on the latest version");

        // o-1 keeps running on v1 although v2 is registered.
        $this->assertEquals(1, $runner->load('o-1')->getMachineVersion());
        $this->assertEquals('submitted', $runner->load('o-1')->snapshot->stateOf('flow'));
        $this->assertTrue($runner->send('o-1', Event::external('REJECT', $t0))->isUnhandled(), "{$label}: v2's REJECT does not exist for a v1 instance");
        $this->assertTrue($runner->send('o-2', Event::external('SUBMIT', $t0))->isAccepted());
        $this->assertEquals('in_review', $runner->load('o-2')->snapshot->stateOf('flow'));

        // Migrating needs a registered migration.
        $this->assertThrows(MachineMigrationException::class, fn() => $runner->migrate('o-1', 2, $t0));

        // A migration that does not conform to the target is refused, nothing written.
        $runner->registerMigration('order', 1, 2, static fn(MachineSnapshot $s): MachineSnapshot => new MachineSnapshot('order', 2, ['flow' => 'submitted', 'audit' => 'clean']));
        $e = $this->assertThrows(MachineMigrationException::class, fn() => $runner->migrate('o-1', 2, $t0));
        $this->assertTrue(str_contains($e->getMessage(), 'region "flow" is in state "submitted"'));
        $runner->registerMigration('order', 1, 2, static fn(MachineSnapshot $s): MachineSnapshot => $s);
        $e = $this->assertThrows(MachineMigrationException::class, fn() => $runner->migrate('o-1', 2, $t0));
        $this->assertTrue(str_contains($e->getMessage(), 'belongs to "order" v1'));
        $this->assertEquals(2, $runner->load('o-1')->version, "{$label}: refused migrations wrote nothing");

        // The real migration: submitted → in_review, a new audit region.
        $runner->registerMigration('order', 1, 2, static function (MachineSnapshot $s): MachineSnapshot {
            $map = ['draft' => 'draft', 'submitted' => 'in_review', 'approved' => 'approved'];

            return new MachineSnapshot('order', 2, ['flow' => $map[$s->stateOf('flow')], 'audit' => 'clean']);
        });
        $this->assertThrows(ConcurrencyConflictException::class, fn() => $runner->migrate('o-1', 2, $t0, 'ops', 1), 'expected version checked');
        $migrated = $runner->migrate('o-1', 2, $t0->modify('+1 hour'), 'ops', 2);
        $this->assertEquals(3, $migrated->version);
        $this->assertEquals(2, $migrated->getMachineVersion());
        $this->assertEquals(['flow' => 'in_review', 'audit' => 'clean'], $runner->load('o-1')->snapshot->getStates());
        $rows = $persistence->history->findByInstance('o-1');
        $this->assertEquals(
            ['1.0 machine.start flow:->draft', '2.0 SUBMIT flow:draft>submitted', '3.0 machine.migrate flow:submitted>in_review', '3.1 machine.migrate audit:->clean'],
            $this->historyLabels($rows),
            "{$label}: the migration is in the history"
        );
        $this->assertEquals(['from_version' => 1, 'to_version' => 2], $rows[2]->payload);
        $this->assertEquals([2, 'ops', EventOrigin::INTERNAL, null], [$rows[2]->machineVersion, $rows[2]->actor, $rows[2]->origin, $rows[2]->transitionId]);

        // From now on o-1 follows v2.
        $this->assertTrue($runner->send('o-1', Event::external('REJECT', $t0))->isAccepted(), "{$label}: v2 semantics after migration");
        $this->assertThrows(InvalidArgumentException::class, fn() => $runner->registerMigration('order', 2, 2, static fn(MachineSnapshot $s): MachineSnapshot => $s));

        // A target refused by the validator is never migrated to.
        $definitions->register(Spec::machine('order', 3)->region('flow', 'draft')->state('draft')->on('X', 'nowhere')->build());
        $runner->registerMigration('order', 2, 3, static fn(MachineSnapshot $s): MachineSnapshot => $s);
        $this->assertThrows(InvalidMachineDefinitionException::class, fn() => $runner->migrate('o-1', 3, $t0));
        $this->assertEquals(2, $runner->load('o-1')->getMachineVersion());
    }

    /**
     * RN-07: the rejection log is optional and off by default; when on, it records rejected and
     * unhandled events with the reason, and the history still holds accepted steps only.
     *
     * @param Closure(bool): MachinePersistence $factory
     */
    private function testRejectionLog(string $label, Closure $factory): void
    {
        $this->assertEquals(null, $factory(false)->rejections, "{$label}: off by default");

        $persistence = $factory(true);
        $runner = $this->runner($persistence, [$this->virttus()], $this->virttusGuards());
        $t0 = $this->at('2026-10-10T10:00:00+00:00');
        $runner->start('q-1', 'virttus.rental_guarantee_quote', 1, null, $t0);
        $runner->send('q-1', Event::external('REGISTER_PENDING', $t0));
        $runner->send('q-1', Event::external('COMPLETE_CALCULATION', $t0->modify('+1 minute'), ['n' => 1], 'k-1'), null, 'ana');
        $runner->send('q-1', Event::external('COMPLETE_PROPOSAL', $t0->modify('+2 minutes')), null, 'bob');

        $log = $persistence->rejections?->findByInstance('q-1') ?? [];
        $this->assertCount(2, $log, "{$label}: one rejected, one unhandled");
        $this->assertEquals([TransitionStatus::REJECTED, 'COMPLETE_CALCULATION', 'ana', 'k-1', ['n' => 1], 2], [$log[0]->status, $log[0]->eventType, $log[0]->actor, $log[0]->idempotencyKey, $log[0]->payload, $log[0]->instanceVersion]);
        $this->assertEquals('QUOTE_PENDING_OPEN', $log[0]->reason['failures'][0]['code']);
        $this->assertEquals(TransitionStatus::UNHANDLED, $log[1]->status);
        $this->assertEquals(26, strlen($log[0]->rejectionId), 'ULID id');
        $this->assertCount(3, $persistence->history->findByInstance('q-1'), "{$label}: the history only holds accepted steps");
    }

    /**
     * D15: the history port has no update nor delete; a duplicate position is refused.
     *
     * @param Closure(bool): MachinePersistence $factory
     */
    private function testHistoryIsAppendOnly(string $label, Closure $factory): void
    {
        $methods = array_map(static fn(\ReflectionMethod $m): string => $m->getName(), (new ReflectionClass(IMachineHistoryStore::class))->getMethods());
        sort($methods);
        $this->assertEquals(['append', 'findByInstance'], $methods, 'No update, no delete in the port');

        $persistence = $factory(false);
        $runner = $this->runner($persistence, [$this->mailMachine()]);
        $t0 = $this->at('2026-10-10T10:00:00+00:00');
        $runner->start('m-1', 'mail', 1, null, $t0);
        $row = $persistence->history->findByInstance('m-1')[0];
        $this->assertThrows(MachinePersistenceException::class, fn() => $persistence->history->append([$row]), "{$label}: a row cannot be rewritten");
        $this->assertThrows(MachinePersistenceException::class, fn() => $persistence->outbox->enqueue([$persistence->outbox->findByInstance('m-1')[0]]), "{$label}: an outbox entry cannot be rewritten");
    }

    /**
     * The runner joins a transaction the caller already opened: the caller's rollback undoes the
     * step together with the caller's own writes.
     *
     * @param Closure(bool): MachinePersistence $factory
     */
    private function testTransactionBoundary(string $label, Closure $factory): void
    {
        $persistence = $factory(false);
        $runner = $this->runner($persistence, [$this->mailMachine()]);
        $t0 = $this->at('2026-10-10T10:00:00+00:00');
        $runner->start('m-1', 'mail', 1, null, $t0);

        try {
            $persistence->transactions->transactional(function () use ($runner, $t0): void {
                $this->assertTrue($runner->send('m-1', Event::external('GO', $t0))->isAccepted());
                throw new RuntimeException('The business write failed');
            });
        } catch (RuntimeException $e) {
            $this->assertEquals('The business write failed', $e->getMessage());
        }
        $this->assertEquals(1, $runner->load('m-1')->version, "{$label}: the outer rollback undid the step");
        $this->assertCount(1, $persistence->history->findByInstance('m-1'));

        $value = $persistence->transactions->transactional(fn(): string => $runner->send('m-1', Event::external('GO', $t0))->getSnapshot()->stateOf('r'));
        $this->assertEquals('b', $value);
        $this->assertEquals(2, $runner->load('m-1')->version, "{$label}: the outer commit kept it");
    }

    /**
     * D7 through the runner: can() and nextEvents() read the persisted snapshot, write nothing.
     *
     * @param Closure(bool): MachinePersistence $factory
     */
    private function testCanAndNextEventsThroughRunner(string $label, Closure $factory): void
    {
        $persistence = $factory(false);
        $runner = $this->runner($persistence, [$this->virttus()], $this->virttusGuards());
        $t0 = $this->at('2026-10-10T10:00:00+00:00');
        $runner->start('q-1', 'virttus.rental_guarantee_quote', 1, null, $t0);
        $runner->send('q-1', Event::external('REGISTER_PENDING', $t0));

        $can = $runner->can('q-1', Event::external('COMPLETE_CALCULATION', $t0));
        $this->assertTrue($can->isRejected());
        $this->assertEquals(['QUOTE_PENDING_OPEN'], $can->getReason()->getCodes());
        $next = $runner->nextEvents('q-1', null, $t0);
        $this->assertTrue($next['RESOLVE_PENDING']->isAccepted());
        $this->assertTrue($next['COMPLETE_CALCULATION']->isRejected());
        $this->assertEquals(2, $runner->load('q-1')->version, "{$label}: nothing written");
        $this->assertTrue($runner->getStateMachine() instanceof StateMachine);
        $this->assertTrue($runner->getPersistence() === $persistence);
    }

    // ==========================================
    // 3. Parity (§4 item 4)
    // ==========================================

    /**
     * The same scenario on InMemory and on Pdo(SQLite) leaves byte-for-byte the same instances,
     * history, outbox, idempotency answers and due deadlines.
     */
    private function testParityInMemoryVsPdo(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            return;
        }
        $dumps = [];
        foreach ($this->backends() as $label => $factory) {
            $persistence = $factory(true);
            $handlers = (new EffectHandlerRegistry())->register('notify_delivered', static fn() => null);
            $runner = $this->runner($persistence, [$this->deadlineMachine(), $this->mailMachine(), $this->virttus()], $this->virttusGuards(), $handlers);
            $t0 = $this->at('2026-10-10T10:00:00-03:00');

            $runner->start('10', 'deadline', 1, null, $t0, 'ana');
            $runner->start('9', 'deadline', 1, null, $t0);
            $runner->start('m', 'mail', 1, null, $t0);
            $runner->start('q', 'virttus.rental_guarantee_quote', 1, ['role' => 'operator'], $t0);
            $runner->send('10', Event::external('COMMENT', $t0->modify('+1 hour'), ['text' => 'olá / ünïcode', 'n' => 1.0], 'c-1'), null, 'ana');
            $runner->send('m', Event::external('GO', $t0->modify('+2 hours')));
            $runner->send('q', Event::external('REGISTER_PENDING', $t0->modify('+3 hours'), ['motive' => 'x']));
            $runner->send('q', Event::external('COMPLETE_CALCULATION', $t0->modify('+4 hours')));
            $runner->start('z', 'deadline', 1, null, $t0->modify('+5 days'));
            (new EffectDispatcher($persistence->outbox, $handlers, null, static fn(): DateTimeImmutable => $t0))->dispatchPending(3);
            $dueBefore = array_map(static fn(DueDeadline $d): array => $d->toArray(), $runner->findDue($t0->modify('+3 days')));
            $runner->deliverDue($t0->modify('+3 days'));

            $dump = [
                'due_before' => $dueBefore,
                'due' => array_map(static fn(DueDeadline $d): array => $d->toArray(), $runner->findDue($t0->modify('+10 days'))),
            ];
            foreach (['10', '9', 'm', 'q', 'z'] as $id) {
                $dump[$id] = [
                    'instance' => $runner->load($id)->toArray(),
                    'history' => array_map(static fn(HistoryEntry $h): array => $h->toArray(), $persistence->history->findByInstance($id)),
                    'outbox' => array_map(static fn(OutboxEntry $e): array => $e->toArray(), $persistence->outbox->findByInstance($id)),
                    'rejections' => array_map(static function ($r): array {
                        $a = $r->toArray();
                        unset($a['rejection_id']);

                        return $a;
                    }, $persistence->rejections?->findByInstance($id) ?? []),
                ];
            }
            $dump['idempotency'] = $persistence->idempotency->find('10', 'c-1')?->toArray();
            $dump['deadline'] = $persistence->idempotency->find('9', 'deadline:doc:2026-10-13 13:00:00.000000')?->toArray();
            $dumps[$label] = $dump;
        }

        $this->assertEquals($dumps['InMemory'], $dumps['Pdo(SQLite)'], 'InMemory and Pdo(SQLite) leave the same state');
        $this->assertEquals('expired', $dumps['InMemory']['9']['instance']['snapshot']['states']['doc']);
        $this->assertEquals('2026-10-18T10:00:00.000000-03:00', $dumps['Pdo(SQLite)']['z']['instance']['snapshot']['due_at']['doc'], 'The snapshot keeps its offsets');
        $this->assertEquals('olá / ünïcode', $dumps['InMemory']['10']['history'][1]['payload']['text']);
        $this->assertEquals(1.0, $dumps['Pdo(SQLite)']['10']['history'][1]['payload']['n'], 'Float payload keeps its type');
        $this->assertCount(1, $dumps['InMemory']['q']['rejections']);
        $this->assertEquals(['10', '9'], array_column($dumps['InMemory']['due_before'], 'instance_id'), 'Binary order: "10" before "9" in both stores');
        $this->assertEquals([['instance_id' => 'z', 'machine_key' => 'deadline', 'region' => 'doc', 'state' => 'delivered', 'due_at' => '2026-10-18 13:00:00.000000']], $dumps['InMemory']['due']);
        $this->assertEquals(['delivered', 'delivered', 'failed'], [
            $dumps['InMemory']['10']['outbox'][0]['status'],
            $dumps['InMemory']['9']['outbox'][0]['status'],
            $dumps['InMemory']['m']['outbox'][0]['status'],
        ]);
        $this->assertTrue($dumps['InMemory']['idempotency'] !== null && $dumps['InMemory']['deadline'] !== null);
    }

    // ==========================================
    // Forward 025 (1.9.0): prazos do contexto e pausa através do runner (D8, D9, D11, RN-03)
    // ==========================================

    /** The claim machine of Module17 (SLA in its own region, paused while pending.open). */
    private function claimMachine(): MachineDefinition
    {
        return Spec::machine('claim', 1)
            ->region('progress', 'received')
                ->state('received')->deadline(event: 'STAGE_OVERDUE', afterFrom: 'context.deadlines.received')
                    ->on('START_ANALYSIS', 'document_analysis', 'start_analysis')
                    ->on('STAGE_OVERDUE', 'late', 'received_overdue')
                ->state('document_analysis')->deadline(event: 'STAGE_OVERDUE', afterFrom: 'context.deadlines.document_analysis')
                    ->on('APPROVE', 'closed', 'approve')
                    ->on('STAGE_OVERDUE', 'late', 'analysis_overdue')
                ->state('late')
                    ->on('APPROVE', 'closed', 'approve_late')
                ->finalState('closed')
            ->region('pending', 'none')
                ->state('none')->on('REGISTER_PENDING', 'open', 'register_pending')
                ->state('open')->on('RESOLVE_PENDING', 'none', 'resolve_pending')
            ->region('sla', 'running')
                ->state('running')->deadline(event: 'SLA_BREACHED', atFrom: 'context.sla_due_at', pausedWhile: 'pending.open')
                    ->on('SLA_BREACHED', 'breached', 'sla_breached')
                    ->on('CLOSE_SLA', 'met', 'close_sla')
                ->state('breached')->on('CLOSE_SLA', 'met', 'close_breached')
                ->finalState('met')
            ->build();
    }

    /** @return array<string, mixed> */
    private function clientA(): array
    {
        return ['sla_due_at' => '2026-11-09T23:59:59-03:00', 'deadlines' => ['received' => 'P2D', 'document_analysis' => 'P5D']];
    }

    /**
     * Battery (InMemory and Pdo): a start with an invalid source writes nothing; a paused region is
     * never due; resuming makes it due at the shifted instant; deliverDue passes the context.
     *
     * @param Closure(bool): MachinePersistence $factory
     */
    /**
     * A migration that keeps a paused balance in a state whose deadline has no pausedWhile is refused:
     * the region could never resume and would stay without a deadline forever.
     *
     * @param Closure(bool): MachinePersistence $factory
     */
    private function testMigrationRefusesStrandedPause(string $label, Closure $factory): void
    {
        $persistence = $factory(false);
        $v2 = MachineDefinition::fromArray(array_replace($this->claimMachine()->toArray(), ['version' => 2]));
        $array = $v2->toArray();
        foreach ($array['regions'] as &$region) {
            if ($region['name'] === 'sla') {
                foreach ($region['states'] as &$state) {
                    if ($state['name'] === 'running') {
                        unset($state['deadline']['pausedWhile']);
                    }
                }
                unset($state);
            }
        }
        unset($region);
        $v2 = MachineDefinition::fromArray($array);
        $runner = $this->runner($persistence, [$this->claimMachine(), $v2]);
        $t0 = $this->at('2026-10-10T10:00:00-03:00');
        $this->assertTrue($runner->start('m-1', 'claim', 1, $this->clientA(), $t0)->isAccepted());
        $this->assertTrue($runner->send('m-1', Event::external('REGISTER_PENDING', $t0->modify('+1 day')), $this->clientA())->isAccepted());
        $this->assertTrue($runner->load('m-1')->snapshot->isPaused('sla'), "{$label}: paused before the migration");

        $runner->registerMigration('claim', 1, 2, static fn(MachineSnapshot $s): MachineSnapshot => MachineSnapshot::fromArray(array_replace($s->toArray(), ['machine_version' => 2])));
        $e = $this->assertThrows(MachineMigrationException::class, fn() => $runner->migrate('m-1', 2, $t0->modify('+2 days')));
        $this->assertTrue(str_contains($e->getMessage(), 'declares no pausedWhile'), "{$label}: the stranded pause is named");
        $this->assertEquals(1, $runner->load('m-1')->snapshot->machineVersion, "{$label}: nothing was written");
    }

    private function testDeadlineSourcesAndPause(string $label, Closure $factory): void
    {
        $persistence = $factory(true);
        $runner = $this->runner($persistence, [$this->claimMachine()]);
        $t0 = $this->at('2026-10-10T10:00:00-03:00');

        // D8: the start is rejected and nothing is written (not even the rejection log: no event).
        $bad = $runner->start('c-bad', 'claim', 1, ['deadlines' => ['received' => 'P2D']], $t0);
        $this->assertTrue($bad->isRejected(), "{$label}: start without the SLA source is rejected");
        $this->assertEquals([TransitionResult::CODE_DEADLINE_SOURCE_INVALID], $bad->getReason()->getCodes());
        $this->assertEquals(null, $persistence->instances->load('c-bad'), "{$label}: no instance");
        $this->assertEquals([], $persistence->history->findByInstance('c-bad'));
        $this->assertEquals([], $persistence->outbox->findByInstance('c-bad'));

        $this->assertTrue($runner->start('c-1', 'claim', 1, $this->clientA(), $t0)->isAccepted());
        $this->assertEquals(['progress', 'sla'], array_map(static fn(DueDeadline $d): string => $d->region, $runner->findDue($this->at('2026-11-10T00:00:00-03:00'), 'claim')));

        // Invalid source on send: rejected, version unchanged, logged as a rejection.
        $context = $this->clientA();
        $context['deadlines']['document_analysis'] = 'not-a-duration';
        $rejected = $runner->send('c-1', Event::external('START_ANALYSIS', $t0->modify('+1 hour')), $context);
        $this->assertEquals([TransitionResult::CODE_DEADLINE_SOURCE_INVALID], $rejected->getReason()->getCodes());
        $this->assertEquals(1, $runner->load('c-1')->version);
        $this->assertCount(1, $persistence->rejections?->findByInstance('c-1') ?? []);

        // Pending opened: the SLA region pauses and leaves the due list; the stage deadline stays.
        $t1 = $this->at('2026-10-11T10:00:00-03:00');
        $this->assertTrue($runner->send('c-1', Event::external('REGISTER_PENDING', $t1), $this->clientA())->isAccepted());
        $stored = $runner->load('c-1')->snapshot;
        $this->assertEquals(29 * 86400 + 13 * 3600 + 59 * 60 + 59, $stored->pausedRemaining('sla'), "{$label}: the balance is persisted");
        $this->assertEquals(null, $stored->dueAt('sla'));
        $this->assertEquals(['progress'], array_map(static fn(DueDeadline $d): string => $d->region, $runner->findDue($this->at('2027-12-31T00:00:00Z'), 'claim')), "{$label}: findDue ignores the paused region");

        // The cron while paused: the stage deadline is delivered, the SLA is not.
        $deliveries = $runner->deliverDue($this->at('2026-11-15T00:00:00-03:00'), 'claim', 100, 'scheduler');
        $this->assertEquals(['progress'], array_map(static fn($d): string => $d->deadline->region, $deliveries));
        $this->assertTrue($deliveries[0]->isDelivered());
        $this->assertEquals('late', $runner->load('c-1')->snapshot->stateOf('progress'));
        $this->assertEquals(29 * 86400 + 13 * 3600 + 59 * 60 + 59, $runner->load('c-1')->snapshot->pausedRemaining('sla'), "{$label}: the balance survives other steps");

        // Resolved: resumes at occurredAt + balance (2026-11-20T04:59:59-03:00 = 07:59:59 UTC).
        $this->assertTrue($runner->send('c-1', Event::external('RESOLVE_PENDING', $this->at('2026-10-21T15:00:00-03:00')), $this->clientA())->isAccepted());
        $this->assertEquals([], $runner->findDue($this->at('2026-11-20T04:59:58-03:00'), 'claim'));
        $due = $runner->findDue($this->at('2026-11-20T04:59:59-03:00'), 'claim');
        $this->assertEquals(
            [['instance_id' => 'c-1', 'machine_key' => 'claim', 'region' => 'sla', 'state' => 'running', 'due_at' => '2026-11-20 07:59:59.000000']],
            array_map(static fn(DueDeadline $d): array => $d->toArray(), $due),
            "{$label}: due at the resumed instant"
        );
        $this->assertFalse(array_key_exists('paused_remaining', $runner->load('c-1')->snapshot->toArray()), 'No pause left: 1.8.0 snapshot form');
        $breach = $runner->deliverDue($this->at('2026-11-20T04:59:59-03:00'), 'claim');
        $this->assertTrue($breach[0]->isDelivered());
        $history = $persistence->history->findByInstance('c-1');
        $this->assertEquals(['SLA_BREACHED', 'deadline:sla:2026-11-20 07:59:59.000000'], [$history[count($history) - 1]->eventType, $history[count($history) - 1]->idempotencyKey]);
        $this->assertEquals('breached', $runner->load('c-1')->snapshot->stateOf('sla'));

        // deliverDue and the context: a deadline transition entering a state whose deadline reads
        // the context needs the instance's context (null by default, as in 1.8.0).
        $stages = Spec::machine('stages', 1)
            ->region('r', 'a')
                ->state('a')->deadline(event: 'NEXT', afterFrom: 'context.d.a')->on('NEXT', 'b', 'a_next')
                ->state('b')->deadline(event: 'NEXT', afterFrom: 'context.d.b')->on('NEXT', 'c', 'b_next')
                ->finalState('c')
            ->build();
        $stagesRunner = $this->runner($factory(false), [$stages]);
        $ctx = ['d' => ['a' => 'P1D', 'b' => 'P2D']];
        $stagesRunner->start('s-1', 'stages', 1, $ctx, $t0);
        $withoutContext = $stagesRunner->deliverDue($t0->modify('+1 day'));
        $this->assertFalse($withoutContext[0]->isDelivered(), "{$label}: without the context the next deadline cannot be computed");
        $this->assertEquals([TransitionResult::CODE_DEADLINE_SOURCE_INVALID], $withoutContext[0]->result?->getReason()->getCodes());
        $this->assertEquals('a', $stagesRunner->load('s-1')->snapshot->stateOf('r'));
        $seen = [];
        $withContext = $stagesRunner->deliverDue($t0->modify('+1 day'), null, 100, null, static function (DueDeadline $d, MachineInstance $i) use ($ctx, &$seen): array {
            $seen[] = $d->instanceId . '@' . $i->version;

            return $ctx;
        });
        $this->assertTrue($withContext[0]->isDelivered());
        $this->assertEquals(['s-1@1'], $seen, 'The provider receives the deadline and the loaded instance');
        $this->assertEquals('2026-10-13T10:00:00.000000-03:00', $stagesRunner->load('s-1')->snapshot->dueAt('r')?->format(MachineSnapshot::DATE_FORMAT));
    }

    /**
     * PDO only: the `paused_remaining` column of the region projection, a database created by the
     * 1.8 DDL (works until a deadline pauses; the documented ALTER TABLE upgrades it), and a 1.8.0
     * snapshot stored as JSON without `paused_remaining`.
     */
    private function testPausedProjectionAndDdlUpgrade(): void
    {
        $t0 = $this->at('2026-10-10T10:00:00-03:00');
        $projection = static fn(PDO $pdo, string $id): array => $pdo->query(
            "SELECT region, due_at, paused_remaining FROM state_machine_region WHERE instance_id = '{$id}' ORDER BY sort_order"
        )->fetchAll(PDO::FETCH_NUM);

        // Current DDL: the balance is projected, due_at is NULL while paused.
        $pdo = $this->sqlite();
        $runner = $this->runner(MachinePersistence::pdo($pdo), [$this->claimMachine()]);
        $runner->start('c-1', 'claim', 1, $this->clientA(), $t0);
        $runner->send('c-1', Event::external('REGISTER_PENDING', $this->at('2026-10-11T10:00:00-03:00')), $this->clientA());
        $rows = array_map(static fn(array $r): array => [$r[0], $r[1], $r[2] === null ? null : (int) $r[2]], $projection($pdo, 'c-1'));
        $this->assertEquals([
            ['progress', '2026-10-12 13:00:00.000000', null],
            ['pending', null, null],
            ['sla', null, 29 * 86400 + 13 * 3600 + 59 * 60 + 59],
        ], $rows, 'paused_remaining projected, due_at NULL');
        $runner->send('c-1', Event::external('RESOLVE_PENDING', $this->at('2026-10-21T15:00:00-03:00')), $this->clientA());
        $this->assertEquals(['sla', '2026-11-20 07:59:59.000000', null], $projection($pdo, 'c-1')[2], 'Resumed: due_at back, paused_remaining NULL');

        // A database created by the 1.8 DDL (no paused_remaining column).
        $ddl = (string) file_get_contents(self::DDL);
        $old = str_replace("    paused_remaining         INTEGER,\n", '', $ddl);
        $this->assertFalse($old === $ddl, 'The 1.9 DDL declares paused_remaining');
        $legacy = $this->sqlite($old);
        $columns = array_map(static fn(array $c): string => $c['name'], $legacy->query('PRAGMA table_info(state_machine_region)')->fetchAll(PDO::FETCH_ASSOC));
        $this->assertFalse(in_array('paused_remaining', $columns, true));
        $persistence = MachinePersistence::pdo($legacy);
        $legacyRunner = $this->runner($persistence, [$this->deadlineMachine(), $this->claimMachine()]);
        $legacyRunner->start('doc-1', 'deadline', 1, null, $t0);
        $this->assertTrue($legacyRunner->send('doc-1', Event::external('RESUBMIT', $t0->modify('+1 day')))->isAccepted(), 'Never-pausing definitions keep working on the 1.8 table');
        $this->assertCount(1, $legacyRunner->findDue($t0->modify('+4 days')));
        $legacyRunner->start('c-1', 'claim', 1, $this->clientA(), $t0);
        $this->assertThrows(MachinePersistenceException::class, fn() => $legacyRunner->send('c-1', Event::external('REGISTER_PENDING', $t0->modify('+1 day')), $this->clientA()), 'The first paused region needs the column');
        $this->assertEquals(1, $legacyRunner->load('c-1')->version, 'Rolled back');

        // The ALTER TABLE documented in the DDL header upgrades it in place.
        $this->assertEquals(1, preg_match('/^--\s+(ALTER TABLE state_machine_region ADD COLUMN paused_remaining INTEGER);$/m', $ddl, $m), 'The header documents the upgrade');
        $legacy->exec($m[1]);
        $this->assertTrue($legacyRunner->send('c-1', Event::external('REGISTER_PENDING', $t0->modify('+1 day')), $this->clientA())->isAccepted());
        $this->assertTrue($legacyRunner->load('c-1')->snapshot->isPaused('sla'));
        $this->assertEquals(['sla', null, 29 * 86400 + 13 * 3600 + 59 * 60 + 59], array_map(static fn(array $r): array => [$r[0], $r[1], $r[2] === null ? null : (int) $r[2]], $projection($legacy, 'c-1'))[2]);

        // A 1.8.0 snapshot stored as JSON (no paused_remaining) loads and runs.
        $v180 = '{"machine_key":"claim","machine_version":1,"states":{"progress":"received","pending":"none","sla":"running"},'
            . '"due_at":{"progress":"2026-10-12T10:00:00.000000-03:00","pending":null,"sla":"2026-11-09T23:59:59.000000-03:00"},"done":false}';
        $pdo->exec("INSERT INTO state_machine_instance (instance_id, machine_key, machine_version, version, snapshot, done, created_at, updated_at) VALUES ('old-1', 'claim', 1, 3, '{$v180}', 'N', '2026-10-10 13:00:00.000000', '2026-10-10 13:00:00.000000')");
        $loaded = $runner->load('old-1');
        $this->assertEquals(3, $loaded->version);
        $this->assertEquals(null, $loaded->snapshot->pausedRemaining('sla'));
        $this->assertEquals(json_decode($v180, true), $loaded->snapshot->toArray(), 'Same array form as stored by 1.8.0');
        $this->assertTrue($runner->send('old-1', Event::external('REGISTER_PENDING', $this->at('2026-10-11T10:00:00-03:00')), $this->clientA())->isAccepted());
        $this->assertEquals(29 * 86400 + 13 * 3600 + 59 * 60 + 59, $runner->load('old-1')->snapshot->pausedRemaining('sla'));
    }

    // ==========================================
    // README (1.8.0): exemplos 14.5 e 14.6 transcritos (EN/pt-BR); cada comentário "// valor" do
    // bloco é uma asserção aqui.
    // ==========================================

    /** A máquina `document` do README 14.5 (reusada em 14.6), como escrita. */
    private function readmeDocument(): MachineDefinition
    {
        $document = Spec::machine('document', 1)
            ->region('lifecycle', 'pending')
                ->state('pending')
                    ->on('DELIVER', 'delivered')->guard('payloadHas', ['field' => 'file'])->action('notify_reviewer')
                ->state('delivered')->deadline('P3D', 'EXPIRE')
                    ->on('VALIDATE', 'validated')
                    ->on('EXPIRE', 'expired')->action('notify_owner')
                ->finalState('validated')
                ->finalState('expired')
            ->build();

        return $document;
    }

    /** README 14.5: runner InMemory, idempotência, outbox + EffectDispatcher, prazo com findDue/deliverDue. */
    private function testReadme14_5RunnerInMemory(): void
    {
        $document = $this->readmeDocument();

        $sent = [];
        $handlers = (new EffectHandlerRegistry())
            ->register('notify_reviewer', function (Effect $effect, string $idempotencyKey) use (&$sent): void { $sent[] = $effect->action; })
            ->register('notify_owner', function (Effect $effect, string $idempotencyKey) use (&$sent): void { $sent[] = $effect->action; });

        $persistence = MachinePersistence::inMemory();
        $runner = new MachineRunner(Spec::stateMachine(), new InMemoryMachineDefinitionRepository([$document]), $persistence, $handlers);
        $runner->start('doc-42', 'document', 1, null, new DateTimeImmutable('2026-10-10 09:00'));

        $deliver = Event::external('DELIVER', new DateTimeImmutable('2026-10-10 10:00'), ['file' => 'id.pdf'], 'upload-7f3a');
        $this->assertTrue($runner->send('doc-42', $deliver, actor: 'alice')->isAccepted());  // true
        $this->assertTrue($runner->send('doc-42', $deliver, actor: 'alice')->isAccepted());  // same key, the original result
        $this->assertEquals(2, $runner->load('doc-42')->version);                            // start + one accepted event
        $this->assertCount(2, $persistence->history->findByInstance('doc-42'));              // start + DELIVER

        // After the commit (a worker or a cron): deliver the effects
        $this->assertEquals(1, (new EffectDispatcher($persistence->outbox, $handlers))->dispatchPending()->deliveredCount());
        $this->assertEquals(['notify_reviewer'], $sent);

        // Deadlines: find and deliver the due ones (the EXPIRE event is internal)
        $this->assertEquals([], $runner->findDue(new DateTimeImmutable('2026-10-12 10:00')));  // due at 2026-10-13 10:00
        $deliveries = $runner->deliverDue(new DateTimeImmutable('2026-10-13 10:00'), actor: 'scheduler');
        $this->assertTrue($deliveries[0]->isDelivered());                                    // true
        $this->assertEquals('expired', $runner->load('doc-42')->snapshot->stateOf('lifecycle'));

        // Prosa do exemplo: o EXPIRE vindo de fora é recusado; entrega repetida é inofensiva
        $this->assertEquals([], $runner->deliverDue(new DateTimeImmutable('2026-10-14 10:00')));
        $runner->start('doc-43', 'document', 1, null, new DateTimeImmutable('2026-10-10 09:00'));
        $runner->send('doc-43', Event::external('DELIVER', new DateTimeImmutable('2026-10-10 10:00'), ['file' => 'x.pdf']));
        $outside = $runner->send('doc-43', Event::external('EXPIRE', new DateTimeImmutable('2026-10-20 10:00')));
        $this->assertTrue($outside->isRejected());
        $this->assertEquals(TransitionResult::CODE_INTERNAL_EVENT, $outside->getReason()->failures[0]->code);
    }

    /** README 14.6: o mesmo runner sobre PDO (SQLite em memória com o DDL de referência) e o mapa de tabelas. */
    private function testReadme14_6PdoPersistence(): void
    {
        $document = $this->readmeDocument();
        $handlers = (new EffectHandlerRegistry())
            ->register('notify_reviewer', static function (): void {})
            ->register('notify_owner', static function (): void {});
        $pdo = $this->sqlite(); // Once, with your migration tool: resources/sql/state_machine.ansi.sql

        $runner = new MachineRunner(
            Spec::stateMachine(),
            new InMemoryMachineDefinitionRepository([$document]),  // definitions ship with the application
            MachinePersistence::pdo($pdo),                         // same contract as inMemory()
            $handlers
        );

        $runner->start('doc-42', 'document', 1, null, new DateTimeImmutable('2026-10-10 09:00'));
        $deliver = Event::external('DELIVER', new DateTimeImmutable('2026-10-10 10:00'), ['file' => 'id.pdf'], 'upload-7f3a');
        $this->assertTrue($runner->send('doc-42', $deliver, actor: 'alice')->isAccepted());
        $this->assertTrue($runner->send('doc-42', $deliver, actor: 'alice')->isAccepted());
        $this->assertEquals(2, $runner->load('doc-42')->version);
        $this->assertEquals(1, (int) $pdo->query('SELECT COUNT(*) FROM state_machine_idempotency')->fetchColumn());
        $this->assertEquals('expired', $runner->deliverDue(new DateTimeImmutable('2026-10-13 10:00'))[0]->result?->getSnapshot()->stateOf('lifecycle'));

        // Tables with other names: table names by role
        $renamed = new PDO('sqlite::memory:');
        $renamed->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::loadDdl($renamed, str_replace(
            ['state_machine_instance ', 'state_machine_history '],
            ['quote_state ', 'quote_state_history '],
            (string) file_get_contents(self::DDL)
        ));
        $pdo = $renamed;
        $persistence = MachinePersistence::pdo($pdo, tables: ['instance' => 'quote_state', 'history' => 'quote_state_history']);

        $runner = new MachineRunner(Spec::stateMachine(), new InMemoryMachineDefinitionRepository([$document]), $persistence, $handlers);
        $runner->start('doc-1', 'document', 1, null, new DateTimeImmutable('2026-10-10 09:00'));
        $this->assertEquals(1, (int) $pdo->query('SELECT COUNT(*) FROM quote_state')->fetchColumn());
        $this->assertEquals(1, (int) $pdo->query('SELECT COUNT(*) FROM quote_state_history')->fetchColumn());

        // Nota do README: o PDO já em transação é reaproveitado; quem abriu decide
        $pdo->beginTransaction();
        $runner->send('doc-1', Event::external('DELIVER', new DateTimeImmutable('2026-10-10 10:00'), ['file' => 'a.pdf']));
        $this->assertTrue($pdo->inTransaction());
        $pdo->rollBack();
        $this->assertEquals('pending', $runner->load('doc-1')->snapshot->stateOf('lifecycle'));

        // Nota do README (1.9.0), "Upgrading from 1.8": banco do script da 1.8 funciona até a
        // primeira pausa, que falha e é desfeita; o ALTER TABLE da nota, como escrito, o atualiza.
        $ddl = (string) file_get_contents(self::DDL);
        $legacy = $this->sqlite(str_replace("    paused_remaining         INTEGER,\n", '', $ddl));
        $legacyRunner = new MachineRunner(Spec::stateMachine(), new InMemoryMachineDefinitionRepository([$document, $this->readmeClaim()]), MachinePersistence::pdo($legacy), $handlers);
        $context = ['sla_due_at' => '2026-11-09T18:00:00-03:00', 'deadlines' => ['triage' => 'P2D', 'analysis' => 'P5D']];
        $this->assertTrue($legacyRunner->start('doc-9', 'document', 1, null, new DateTimeImmutable('2026-10-10 09:00'))->isAccepted());
        $this->assertTrue($legacyRunner->start('claim-7', 'claim', 1, $context, new DateTimeImmutable('2026-10-10T09:00:00-03:00'))->isAccepted());
        $pending = Event::external('REGISTER_PENDING', new DateTimeImmutable('2026-10-20T18:00:00-03:00'));
        $this->assertThrows(MachinePersistenceException::class, fn() => $legacyRunner->send('claim-7', $pending, $context), 'the first pause needs the column');
        $this->assertEquals(1, $legacyRunner->load('claim-7')->version, 'the step is rolled back');
        $legacy->exec('ALTER TABLE state_machine_region ADD COLUMN paused_remaining INTEGER;');
        $this->assertTrue($legacyRunner->send('claim-7', $pending, $context)->isAccepted());
        $this->assertEquals(1728000, $legacyRunner->load('claim-7')->snapshot->pausedRemaining('sla'));
        $this->assertTrue(str_contains($ddl, 'ALTER TABLE state_machine_region ADD COLUMN paused_remaining INTEGER;'), 'the DDL header documents the same statement');
    }

    // ==========================================
    // README (1.9.0): exemplo 14.7 transcrito (EN/pt-BR); cada comentário "// valor" do bloco é
    // uma asserção aqui.
    // ==========================================

    /** A máquina `claim` do README 14.7, como escrita. */
    private function readmeClaim(): MachineDefinition
    {
        $claim = Spec::machine('claim', 1)
            ->region('progress', 'triage')
                ->state('triage')->deadline(event: 'TRIAGE_CLOSED', afterFrom: 'context.deadlines.triage')
                    ->on('START_ANALYSIS', 'analysis')
                    ->on('TRIAGE_CLOSED', 'analysis')
                ->state('analysis')->deadline(event: 'ANALYSIS_OVERDUE', afterFrom: 'context.deadlines.analysis')
                    ->on('APPROVE', 'approved')
                    ->on('ANALYSIS_OVERDUE', 'late')
                ->state('late')
                    ->on('APPROVE', 'approved')
                ->finalState('approved')
            ->region('pending', 'none')
                ->state('none')->on('REGISTER_PENDING', 'open')
                ->state('open')->on('RESOLVE_PENDING', 'none')
            ->region('sla', 'running')
                ->state('running')->deadline(event: 'SLA_BREACHED', atFrom: 'context.sla_due_at', pausedWhile: 'pending.open')
                    ->on('SLA_BREACHED', 'breached')
                ->finalState('breached')
            ->build();

        return $claim;
    }

    /** README 14.7: prazo do contexto (atFrom/afterFrom), fonte ausente rejeitada, deliverDue com contexto, pausa com saldo. */
    private function testReadme14_7DeadlinesFromTheContext(): void
    {
        $claim = $this->readmeClaim();
        $this->assertEquals([], Spec::validateMachine($claim), 'the README machine has no warning');

        // The context of each claim: the SLA instant of the contract and the stage durations of the client
        $contexts = ['claim-7' => ['sla_due_at' => '2026-11-09T18:00:00-03:00', 'deadlines' => ['triage' => 'P2D', 'analysis' => 'P5D']]];

        $persistence = MachinePersistence::inMemory();
        $runner = new MachineRunner(Spec::stateMachine(), new InMemoryMachineDefinitionRepository([$claim]), $persistence);
        $this->assertTrue($runner->start('claim-7', 'claim', 1, $contexts['claim-7'], new DateTimeImmutable('2026-10-10T09:00:00-03:00'))->isAccepted());
        $this->assertEquals('2026-11-09T18:00:00-03:00', $runner->load('claim-7')->snapshot->dueAt('sla')->format(DATE_ATOM));      // atFrom
        $this->assertEquals('2026-10-12T09:00:00-03:00', $runner->load('claim-7')->snapshot->dueAt('progress')->format(DATE_ATOM)); // start + P2D

        // A missing source is a rejection, never a silently empty SLA: the start writes nothing
        $missing = $runner->start('claim-8', 'claim', 1, ['deadlines' => ['triage' => 'P2D']], new DateTimeImmutable('2026-10-10T09:00:00-03:00'));
        $this->assertTrue($missing->isRejected());                                                   // true
        $this->assertEquals('SM_DEADLINE_SOURCE_INVALID', $missing->getReason()->failures[0]->code);
        $this->assertEquals('context.sla_due_at', $missing->getReason()->failures[0]->property);
        $this->assertEquals(null, $persistence->instances->load('claim-8'), 'the start writes nothing');
        $this->assertEquals([], $persistence->history->findByInstance('claim-8'));
        $this->assertFalse(str_contains($missing->getReason()->failures[0]->message, 'P2D'), 'the message names the path, never the value');

        // Prosa do comentário: sem o provedor, a entrega é rejeitada (contexto nulo) e fica para a próxima rodada
        $withoutProvider = $runner->deliverDue(new DateTimeImmutable('2026-10-12T09:00:00-03:00'));
        $this->assertFalse($withoutProvider[0]->isDelivered());
        $this->assertEquals([TransitionResult::CODE_DEADLINE_SOURCE_INVALID], $withoutProvider[0]->result?->getReason()->getCodes());
        $this->assertEquals('triage', $runner->load('claim-7')->snapshot->stateOf('progress'));

        // The cron: TRIAGE_CLOSED enters 'analysis', whose deadline reads the context, so the cron provides it
        $deliveries = $runner->deliverDue(
            new DateTimeImmutable('2026-10-12T09:00:00-03:00'),
            contextProvider: fn(DueDeadline $due, MachineInstance $instance) => $contexts[$instance->instanceId]
        );
        $this->assertTrue($deliveries[0]->isDelivered());                                            // true
        $this->assertEquals('2026-10-17T09:00:00-03:00', $runner->load('claim-7')->snapshot->dueAt('progress')->format(DATE_ATOM));

        // A pending item opened 20 days before the SLA: the SLA pauses and keeps the balance
        $runner->send('claim-7', Event::external('REGISTER_PENDING', new DateTimeImmutable('2026-10-20T18:00:00-03:00')), $contexts['claim-7']);
        $snapshot = $runner->load('claim-7')->snapshot;
        $this->assertTrue($snapshot->isPaused('sla'));                                               // true
        $this->assertEquals(null, $snapshot->dueAt('sla'));                                          // a paused deadline is never due
        $this->assertEquals(1728000, $snapshot->pausedRemaining('sla'));                             // 20 days, in whole seconds
        $this->assertEquals(['progress'], array_map(fn(DueDeadline $due) => $due->region, $runner->findDue(new DateTimeImmutable('2027-01-01T00:00:00-03:00'))));

        // Resolved 5 days later: the SLA resumes with the balance, 5 days after the contract instant
        $runner->send('claim-7', Event::external('RESOLVE_PENDING', new DateTimeImmutable('2026-10-25T18:00:00-03:00')), $contexts['claim-7']);
        $this->assertEquals('2026-11-14T18:00:00-03:00', $runner->load('claim-7')->snapshot->dueAt('sla')->format(DATE_ATOM));
        $this->assertFalse($runner->load('claim-7')->snapshot->isPaused('sla'));

        // Prosa do exemplo: reentrar no estado reinicia o prazo e descarta o saldo (pausado de novo, com o prazo inteiro)
        $restart = Spec::machine('restart', 1)
            ->region('flag', 'off')
                ->state('off')->on('OPEN', 'on')
                ->state('on')->on('CLOSE', 'off')
            ->region('sla', 'running')
                ->state('running')->deadline(event: 'BREACH', after: 'P10D', pausedWhile: 'flag.on')
                    ->on('RESTART', 'running')
                    ->on('BREACH', 'breached')
                ->finalState('breached')
            ->build();
        $machine = Spec::stateMachine();
        $t0 = new DateTimeImmutable('2026-10-10T09:00:00-03:00');
        $state = $machine->initialSnapshot($restart, null, $t0)->getSnapshot();
        $state = $machine->transition($restart, $state, Event::external('OPEN', $t0->modify('+4 days')))->getSnapshot();
        $this->assertEquals(6 * 86400, $state->pausedRemaining('sla'), 'paused with 6 days left');
        $state = $machine->transition($restart, $state, Event::external('RESTART', $t0->modify('+5 days')))->getSnapshot();
        $this->assertEquals(10 * 86400, $state->pausedRemaining('sla'), 'reentered: a fresh deadline, paused at once with its full duration');
        $state = $machine->transition($restart, $state, Event::external('CLOSE', $t0->modify('+6 days')))->getSnapshot();
        $this->assertEquals('2026-10-26T09:00:00-03:00', $state->dueAt('sla')?->format(DATE_ATOM));

        // Prosa do exemplo: o validador avisa da pausa na própria região e recusa inState inexistente
        $warnings = Spec::validateMachine(Spec::machine('self_pause', 1)
            ->region('r', 'a')
                ->state('a')->deadline(event: 'EXPIRE', after: 'P1D', pausedWhile: 'r.a')->on('EXPIRE', 'b')
                ->finalState('b')
            ->build());
        $this->assertEquals(['self_referencing_pause'], array_map(static fn($w): string => $w->kind, $warnings));
        $this->assertThrows(InvalidMachineDefinitionException::class, fn() => Spec::validateMachine(Spec::machine('bad_pause', 1)
            ->region('r', 'a')
                ->state('a')->deadline(event: 'EXPIRE', after: 'P1D', pausedWhile: 'nowhere.x')->on('EXPIRE', 'b')
                ->finalState('b')
            ->build()));
    }
}
