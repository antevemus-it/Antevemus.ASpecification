<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Results\SpecificationResult;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\PredicateSpecification;
use Antevemus\ASpecification\StateMachine\CandidateReason;
use Antevemus\ASpecification\StateMachine\Effect;
use Antevemus\ASpecification\StateMachine\EffectPhase;
use Antevemus\ASpecification\StateMachine\Event;
use Antevemus\ASpecification\StateMachine\EventOrigin;
use Antevemus\ASpecification\StateMachine\Exceptions\ConcurrencyConflictException;
use Antevemus\ASpecification\StateMachine\Exceptions\InvalidMachineDefinitionException;
use Antevemus\ASpecification\StateMachine\Exceptions\InvalidSnapshotException;
use Antevemus\ASpecification\StateMachine\Exceptions\MachineInstanceAlreadyExistsException;
use Antevemus\ASpecification\StateMachine\Exceptions\MachineInstanceNotFoundException;
use Antevemus\ASpecification\StateMachine\Exceptions\MachinePersistenceException;
use Antevemus\ASpecification\StateMachine\Exceptions\StateMachineException;
use Antevemus\ASpecification\StateMachine\Exceptions\SyncEffectFailedException;
use Antevemus\ASpecification\StateMachine\GuardExpression as G;
use Antevemus\ASpecification\StateMachine\GuardRegistry;
use Antevemus\ASpecification\StateMachine\MachineDefinition;
use Antevemus\ASpecification\StateMachine\MachineSnapshot;
use Antevemus\ASpecification\StateMachine\Persistence\EventSnapshot;
use Antevemus\ASpecification\StateMachine\Persistence\HistoryEntry;
use Antevemus\ASpecification\StateMachine\Persistence\IBatchIdempotencyStore;
use Antevemus\ASpecification\StateMachine\Persistence\IIdempotencyStore;
use Antevemus\ASpecification\StateMachine\Persistence\IMachineHistoryStore;
use Antevemus\ASpecification\StateMachine\Persistence\IMachineInstanceStore;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\IInMemoryTransactional;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\InMemoryEffectOutbox;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\InMemoryIdempotencyStore;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\InMemoryMachineDefinitionRepository;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\InMemoryMachineHistoryStore;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\InMemoryMachineInstanceStore;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\InMemoryTransactionManager;
use Antevemus\ASpecification\StateMachine\Persistence\ITransactionManager;
use Antevemus\ASpecification\StateMachine\Persistence\MachineInstance;
use Antevemus\ASpecification\StateMachine\Persistence\MachinePersistence;
use Antevemus\ASpecification\StateMachine\Persistence\OutboxEntry;
use Antevemus\ASpecification\StateMachine\Persistence\OutboxStatus;
use Antevemus\ASpecification\StateMachine\Persistence\Pdo\PdoIdempotencyStore;
use Antevemus\ASpecification\StateMachine\Persistence\Pdo\PdoMachineSchema;
use Antevemus\ASpecification\StateMachine\Runner\BatchEvent;
use Antevemus\ASpecification\StateMachine\Runner\BatchEventOutcome;
use Antevemus\ASpecification\StateMachine\Runner\EffectContext;
use Antevemus\ASpecification\StateMachine\Runner\EffectDispatcher;
use Antevemus\ASpecification\StateMachine\Runner\EffectHandlerRegistry;
use Antevemus\ASpecification\StateMachine\Runner\MachineRunner;
use Antevemus\ASpecification\StateMachine\StateMachine;
use Antevemus\ASpecification\StateMachine\TransitionCandidate;
use Antevemus\ASpecification\StateMachine\TransitionResult;
use Antevemus\ASpecification\StateMachine\TransitionStatus;
use Antevemus\ASpecification\Tests\Support\Module45Answers;
use Antevemus\ASpecification\Tests\Support\PdoRecordingPdo;
use Antevemus\ASpecification\Tests\Support\ReadmeBlocks;
use Antevemus\ASpecification\Tests\TestCase;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;
use Throwable;

/**
 * Module45CountingPdo - a SQLite connection that records its statements and counts the transactions it opens.
 *
 * @version 2.10.0
 */
final class Module45CountingPdo extends PdoRecordingPdo
{
    public int $begins = 0;

    public function beginTransaction(): bool
    {
        $this->begins++;

        return parent::beginTransaction();
    }
}

/**
 * Module45CountingTransactions - an in-memory transaction manager that counts the transactions asked of it.
 *
 * @version 2.10.0
 */
final class Module45CountingTransactions implements ITransactionManager
{
    public int $calls = 0;

    public function __construct(private readonly InMemoryTransactionManager $inner)
    {
    }

    public function inTransaction(): bool
    {
        return $this->inner->inTransaction();
    }

    public function transactional(Closure $work): mixed
    {
        $this->calls++;

        return $this->inner->transactional($work);
    }
}

/**
 * Module45PlainIdempotencyStore - an idempotency store without the batch lookup, counting its find() calls.
 *
 * @version 2.10.0
 */
final class Module45PlainIdempotencyStore implements IIdempotencyStore, IInMemoryTransactional
{
    public int $finds = 0;

    public function __construct(private readonly InMemoryIdempotencyStore $inner)
    {
    }

    public function find(string $instanceId, string $idempotencyKey): ?TransitionResult
    {
        $this->finds++;

        return $this->inner->find($instanceId, $idempotencyKey);
    }

    public function record(string $instanceId, string $idempotencyKey, int $instanceVersion, TransitionResult $result, DateTimeImmutable $recordedAt): void
    {
        $this->inner->record($instanceId, $idempotencyKey, $instanceVersion, $result, $recordedAt);
    }

    public function exportState(): array
    {
        return $this->inner->exportState();
    }

    public function importState(array $state): void
    {
        $this->inner->importState($state);
    }
}

/**
 * Module45ExplodingHistory - a history store that fails for one instance, after the compare-and-set of its step.
 *
 * @version 2.10.0
 */
final class Module45ExplodingHistory implements IMachineHistoryStore, IInMemoryTransactional
{
    public function __construct(private readonly InMemoryMachineHistoryStore $inner, private readonly string $explodesFor)
    {
    }

    public function append(array $entries): void
    {
        foreach ($entries as $entry) {
            if ($entry->instanceId === $this->explodesFor && $entry->instanceVersion > 1) {
                throw new RuntimeException('history unavailable for ' . $this->explodesFor);
            }
        }
        $this->inner->append($entries);
    }

    public function findByInstance(string $instanceId): array
    {
        return $this->inner->findByInstance($instanceId);
    }

    public function exportState(): array
    {
        return $this->inner->exportState();
    }

    public function importState(array $state): void
    {
        $this->inner->importState($state);
    }
}

/**
 * Module45_StateMachineAdoptionTest - 2.10.0: what an application meets when it moves a live workflow into the
 * state machine.
 *
 * - Respostas da 2.9.0 inalteradas: os digests de Module45Answers (can(), nextEvents(), histórico, outbox,
 *   idempotência, as linhas de um SQLite criado pelo DDL da 2.9.0) medidos no código da 2.9.0 batem com os do
 *   código atual; formas de toArray() e a lista de colunas opcionais da 2.7.0 iguais.
 * - Importação: startAt() (estados dados, linha `machine.import` por região, sem entry action, sem guard, sem
 *   efeito, prazos a partir do instante, pausa, final só com allowFinal, recusas antes de gravar, tenant, versão
 *   mais recente); StateMachine::snapshotAt() puro.
 * - Contexto do efeito: EventSnapshot em cada entrada da outbox, EffectContext para o handler de quatro
 *   parâmetros (síncrono e assíncrono), handler de três parâmetros chamado com três argumentos; coluna opt-in
 *   `event_snapshot` (COLUMNS_SINCE_2_10), o DDL da 2.9.0 sem mapeamento e a migration aditiva comentada.
 * - Classificação de nextEvents() (CandidateReason, getMissingPayload()) e motivo principal da recusa
 *   (getPrimaryReason(), getDetails()), com e sem guards de papel marcados.
 * - Colunas JSON largas: nenhum store trunca nem valida o tamanho; o banco recusa acima do tipo e as variantes
 *   largas documentadas no DDL (executadas a partir do próprio comentário) aceitam.
 * - sendMany(): uma transação por chunk, idempotência em lote (uma consulta por chunk), um resultado por evento,
 *   reenvio unitário quando um evento falha depois da primeira escrita, propagação dentro de uma transação do
 *   chamador; propriedade: para qualquer sequência, sendMany() == os mesmos send() em ordem (resultados,
 *   snapshots, histórico, outbox, idempotência), em memória e nos bancos.
 * - O exemplo 24 da documentação, transcrito aqui e conferido token a token.
 *
 * Bancos: SQLite em toda execução; PostgreSQL 17, MySQL 8.4 e MariaDB 11.4 com ASPEC_PDO_{PGSQL,MYSQL,MARIADB}_DSN.
 *
 * @version 2.10.0
 */
class Module45_StateMachineAdoptionTest extends TestCase
{
    private const DDL = __DIR__ . '/../../resources/sql/state_machine.ansi.sql';

    private const ANSWERS = __DIR__ . '/../Fixtures/state_machine/answers_2_9_0.json';

    /** The page of docs/en and docs/pt-BR that hosts example 24. */
    private const README_PAGE = 'state-machine/adoption';

    /** Every table of the reference DDL, children first. */
    private const TABLES = [
        'state_machine_region', 'state_machine_history', 'state_machine_outbox', 'state_machine_idempotency',
        'state_machine_rejection', 'state_machine_instance', 'state_machine_definition', 'state_machine_definition_draft',
    ];

    /** Sequences of the sendMany() == send() property, in memory and in SQLite (fewer on the servers). */
    private const PROPERTY_SEQUENCES = 120;

    public function run(): void
    {
        // The answers of 2.9.0
        $this->testAnswersOf290AreUnchanged();
        $this->testShapesAreUnchanged();
        // Import
        $this->testStartAt('InMemory', static fn(): MachinePersistence => MachinePersistence::inMemory());
        $this->testStartAtRefusals();
        $this->testStartAtDeadlinesPauseAndSources();
        $this->testSnapshotAtIsPure();
        // Effect context
        $this->testEffectContextInMemory();
        $this->testHandlerSignatures();
        $this->testEventSnapshotArrayForm();
        // Classification and primary reason
        $this->testRoleGuardMarks();
        $this->testClassification();
        $this->testClassificationEdgeCases();
        $this->testPrimaryReason();
        // Batches
        $this->testSendManyBasics('InMemory', static fn(): MachinePersistence => MachinePersistence::inMemory());
        $this->testSendManyReplayAndNested();
        $this->testSendManyFallbackStore();
        $this->testSendManyTransactionsPerChunk();
        $this->testNumericInstanceIds('InMemory', static fn(): MachinePersistence => MachinePersistence::inMemory());
        $this->testSendManyEqualsSendProperty('InMemory', static fn(): MachinePersistence => MachinePersistence::inMemory(true), self::PROPERTY_SEQUENCES);
        $this->testDocumentationBlocksAreTheSameInBothLanguages();

        if (!extension_loaded('pdo_sqlite')) {
            fwrite(STDOUT, "    [AVISO] pdo_sqlite ausente; o Módulo 45 roda só as baterias em memória.\n");
            return;
        }
        $this->testReadme24();
        $this->testBatchQueriesAndTransactions();
        $this->pdoLeg('sqlite', fn(): PDO => $this->sqlite());

        foreach (['pgsql' => 'PostgreSQL', 'mysql' => 'MySQL', 'mariadb' => 'MariaDB'] as $family => $label) {
            $pdo = $this->connectFromEnvironment($family);
            if ($pdo === null) {
                fwrite(STDOUT, "    [AVISO] ASPEC_PDO_" . strtoupper($family) . "_DSN não definido; Módulo 45 em {$label} pulado.\n");
                continue;
            }
            $this->pdoLeg($family, fn(): PDO => $pdo);
            $this->dropSchema($pdo);
            fwrite(STDOUT, "    [2.10.0] {$label} " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . ": importação, contexto do efeito, coluna opt-in, colunas largas e sendMany() == send() OK\n");
        }
    }

    // ==========================================
    // The answers of 2.9.0
    // ==========================================

    private function testAnswersOf290AreUnchanged(): void
    {
        $fixture = json_decode((string) file_get_contents(self::ANSWERS), true);
        $this->assertTrue(is_array($fixture) && is_array($fixture['digests'] ?? null), 'digests da 2.9.0 legíveis');
        $expected = $fixture['digests'];
        if (!extension_loaded('pdo_sqlite')) {
            $expected = array_filter($expected, static fn(string $k): bool => !str_starts_with($k, 'runner_sqlite_2_9_ddl/'), ARRAY_FILTER_USE_KEY);
        }
        $actual = Module45Answers::digests();
        $this->assertEquals(array_keys($expected), array_keys($actual), 'as mesmas peças de resposta da 2.9.0');
        foreach ($expected as $piece => $digest) {
            $this->assertEquals($digest, $actual[$piece] ?? null, "resposta da 2.9.0 inalterada: {$piece}");
        }
    }

    private function testShapesAreUnchanged(): void
    {
        $t0 = new DateTimeImmutable('2026-10-21T12:00:00Z');
        $snapshot = new MachineSnapshot('k', 1, ['r' => 's']);
        $result = new TransitionResult(TransitionStatus::ACCEPTED, null, $snapshot);
        $this->assertEquals(['status', 'event', 'snapshot', 'transitions', 'effects', 'reason', 'rejections'], array_keys($result->toArray()), 'TransitionResult::toArray() com a forma da 2.9.0');
        $this->assertTrue($result->primaryReason === $result->reason, 'sem motivo principal dado: o próprio motivo');
        $this->assertEquals([], $result->getDetails());
        $this->assertTrue($result->getCandidateReason() === null && $result->getMissingPayload() === [], 'sem classificação fora de nextEvents()');
        $failure = SpecificationResult::failure('x', 'X');
        $listed = new TransitionResult(TransitionStatus::REJECTED, null, $snapshot, reason: $failure, details: [5 => $failure], candidateReason: CandidateReason::MISSING_PAYLOAD, missingPayload: [3 => 'a', 7 => 'b']);
        $this->assertTrue($listed->getDetails() === [$failure] && $listed->getMissingPayload() === ['a', 'b'], 'detalhes e campos viram listas');

        $effect = new Effect('mail', [], 'r', EffectPhase::ENTRY, 's', null);
        $plain = new OutboxEntry('i', 1, 0, 'k', 1, $effect, OutboxStatus::PENDING, 0, null, $t0);
        $withEvent = new OutboxEntry('i', 1, 0, 'k', 1, $effect, OutboxStatus::PENDING, 0, null, $t0, null, null, new EventSnapshot('E', ['a' => 1], 'ana', EventOrigin::EXTERNAL, $t0));
        $this->assertEquals($plain->toArray(), $withEvent->toArray(), 'OutboxEntry::toArray() não muda com o evento');
        $this->assertEquals(
            ['instance_id', 'instance_version', 'sort_order', 'machine_key', 'machine_version', 'effect_key', 'effect', 'status', 'attempts', 'last_error', 'enqueued_at', 'delivered_at', 'last_attempt_at'],
            array_keys($withEvent->toArray()),
            'OutboxEntry::toArray() com a forma da 2.9.0'
        );
        $this->assertTrue($withEvent->withDelivered($t0)->event === $withEvent->event && $withEvent->withFailed('x', $t0)->event === $withEvent->event, 'withDelivered()/withFailed() mantêm o evento');
        $this->assertEquals(['tenant', 'current_state_code', 'from_state_code', 'to_state_code'], PdoMachineSchema::optionalColumns(), 'optionalColumns() continua a lista da 2.7.0');
        $this->assertEquals(['event_snapshot' => 'event_snapshot'], PdoMachineSchema::COLUMNS_SINCE_2_10);
        $this->assertEquals(['outbox' => ['event_snapshot']], PdoMachineSchema::TABLE_COLUMNS_SINCE_2_10);
        $this->assertEquals(['machine.start', 'machine.migrate', 'machine.import'], MachineRunner::RESERVED_EVENTS);
        $this->assertEquals(MachineRunner::IMPORT_EVENT, StateMachine::IMPORT_EVENT, 'o mesmo tipo reservado no núcleo e no runner');
        $this->assertEquals(100, MachineRunner::DEFAULT_CHUNK_SIZE);
        $this->assertEquals(['inState', 'payloadHas', 'eventOrigin'], (new GuardRegistry())->names(), 'os guards embutidos não mudam');
        $this->assertEquals([], (new GuardRegistry())->roleGuards(), 'nenhum guard de papel por padrão');
    }

    // ==========================================
    // Import
    // ==========================================

    private function testStartAt(string $label, Closure $persistenceFactory): void
    {
        $persistence = $persistenceFactory();
        $calls = [];
        $handlers = (new EffectHandlerRegistry())
            ->register('welcome', static function (Effect $effect) use (&$calls): void {
                $calls[] = $effect->action;
            })
            ->register('notify_analyst', static function (): void {
            });
        $definitions = new InMemoryMachineDefinitionRepository([$this->claimWithEntries(1), $this->claimWithEntries(2)]);
        $runner = new MachineRunner(Spec::stateMachine(), $definitions, $persistence, $handlers);
        $at = new DateTimeImmutable('2026-10-21T09:00:00-03:00');

        $result = $runner->startAt('i-1', 'claim', 1, ['progress' => 'analysis', 'pending' => 'open'], null, $at, 'import', ['legacy_id' => 4711]);
        $this->assertTrue($result->isAccepted(), "{$label}: importação aceita");
        $this->assertTrue($result->event === null && $result->getEffects() === [], "{$label}: sem evento e sem efeito");
        $instance = $runner->load('i-1');
        $this->assertEquals(1, $instance->version, "{$label}: versão 1");
        $this->assertEquals(['progress' => 'analysis', 'pending' => 'open'], $instance->snapshot->getStates(), "{$label}: os estados dados");
        $this->assertEquals('2026-10-26T09:00:00.000000-03:00', $instance->snapshot->dueAt('progress')?->format(MachineSnapshot::DATE_FORMAT), "{$label}: o prazo conta do instante da importação");
        $this->assertTrue($instance->createdAt == $at && $instance->updatedAt == $at, "{$label}: criada no instante da importação");
        $rows = $persistence->history->findByInstance('i-1');
        $this->assertCount(2, $rows, "{$label}: uma linha de histórico por região");
        foreach ($rows as $order => $row) {
            $this->assertEquals(
                ['i-1', 1, $order, 'claim', 1, null, 'machine.import', null, ['legacy_id' => 4711], 'import', null, EventOrigin::INTERNAL],
                [$row->instanceId, $row->instanceVersion, $row->sortOrder, $row->machineKey, $row->machineVersion, $row->fromState, $row->eventType, $row->transitionId, $row->payload, $row->actor, $row->idempotencyKey, $row->origin],
                "{$label}: linha {$order} da importação"
            );
        }
        $this->assertEquals(['progress' => 'analysis', 'pending' => 'open'], [$rows[0]->region => $rows[0]->toState, $rows[1]->region => $rows[1]->toState]);
        $this->assertCount(0, $persistence->outbox->findByInstance('i-1'), "{$label}: nenhuma entry action (o estado analysis tem uma)");
        $this->assertEquals([], $calls, "{$label}: nenhum handler chamado");

        $runner->start('i-2', 'claim', 1, null, $at);
        $this->assertCount(1, $persistence->outbox->findByInstance('i-2'), "{$label}: controle: start() roda a entry action do estado inicial");

        $runner->startAt('i-3', 'claim', null, ['progress' => 'triage'], null, $at);
        $three = $runner->load('i-3');
        $this->assertEquals(2, $three->getMachineVersion(), "{$label}: versão nula = a mais recente");
        $this->assertEquals('none', $three->snapshot->stateOf('pending'), "{$label}: região omitida começa no estado inicial");
        $this->assertCount(0, $persistence->outbox->findByInstance('i-3'), "{$label}: nem a entry action do estado inicial roda na importação");

        $this->assertThrows(MachineInstanceAlreadyExistsException::class, fn() => $runner->startAt('i-1', 'claim', 1, ['progress' => 'triage'], null, $at), "{$label}: id já usado");
        $this->assertEquals(1, $runner->load('i-1')->version, "{$label}: nada gravado na recusa");

        $done = $runner->startAt('i-4', 'claim', 1, ['progress' => 'approved', 'pending' => 'open'], null, $at, allowFinal: true);
        $this->assertTrue($done->isAccepted() && $runner->load('i-4')->snapshot->isDone(), "{$label}: final permitido com allowFinal: instância concluída");
        $this->assertTrue($runner->send('i-4', Event::external('RESOLVE_PENDING', $at))->isUnhandled(), "{$label}: concluída é inerte");

        $next = $runner->send('i-1', Event::external('APPROVE', $at->modify('+1 hour')), null, 'ana');
        $this->assertTrue($next->isAccepted() && $runner->load('i-1')->version === 2, "{$label}: a importada segue o fluxo normal");
        $this->assertThrows(InvalidArgumentException::class, fn() => $runner->send('i-2', Event::external(MachineRunner::IMPORT_EVENT, $at)), "{$label}: machine.import é reservado em send()");
        $this->assertThrows(InvalidArgumentException::class, fn() => new BatchEvent('i-2', Event::external(MachineRunner::IMPORT_EVENT, $at)), "{$label}: e em BatchEvent");
        $this->assertThrows(InvalidArgumentException::class, fn() => new BatchEvent('i-2', Event::external(MachineRunner::START_EVENT, $at)), "{$label}: machine.start também");

        $runner->startAt('t-1', 'claim', 1, ['progress' => 'analysis'], null, $at, tenant: '7');
        $this->assertEquals('7', $runner->load('t-1', '7')->tenant, "{$label}: o tenant vai por último, como nas outras chamadas");
        $this->assertThrows(MachineInstanceNotFoundException::class, fn() => $runner->load('t-1', '12'), "{$label}: outro tenant não a vê");
        $due = $runner->findDue($at->modify('+5 days'), 'claim', 100, '7');
        $this->assertEquals(['t-1'], array_map(static fn($d): string => $d->instanceId, $due), "{$label}: o prazo importado é achado");
        $delivered = $runner->deliverDue($at->modify('+5 days'), 'claim', 100, 'scheduler', null, '7');
        $this->assertTrue($delivered[0]->isDelivered() && $runner->load('t-1')->snapshot->stateOf('progress') === 'late', "{$label}: e entregue");
    }

    private function testStartAtRefusals(): void
    {
        $persistence = MachinePersistence::inMemory();
        $runner = new MachineRunner(Spec::stateMachine(), new InMemoryMachineDefinitionRepository([Module45Answers::claimMachine()]), $persistence);
        $at = new DateTimeImmutable('2026-10-21T09:00:00Z');
        $refusals = [
            'região desconhecida' => [['progres' => 'analysis'], false, 'no region "progres"'],
            'estado não declarado' => [['progress' => 'analyse'], false, 'declares no state "analyse"'],
            'estado de outra região' => [['progress' => 'open'], false, 'declares no state "open"'],
            'estado que não é texto' => [['progress' => 2], false, 'must be a state name'],
            'final sem allowFinal' => [['progress' => 'cancelled'], false, 'needs allowFinal'],
        ];
        foreach ($refusals as $case => [$states, $allowFinal, $fragment]) {
            $e = $this->assertThrows(InvalidSnapshotException::class, fn() => $runner->startAt('x', 'claim', 1, $states, null, $at, allowFinal: $allowFinal), "recusa: {$case}");
            $this->assertTrue(str_contains($e->getMessage(), $fragment), "recusa: {$case}: a mensagem diz ({$e->getMessage()})");
            $this->assertThrows(MachineInstanceNotFoundException::class, fn() => $runner->load('x'), "recusa: {$case}: nada gravado");
        }
        $this->assertCount(0, $persistence->history->findByInstance('x'));
        $this->assertThrows(InvalidSnapshotException::class, fn() => $runner->startAt('x', 'claim', 1, ['progress' => 'approved'], null, $at), 'final recusado por padrão (startAt sem allowFinal)');
        $this->assertThrows(InvalidSnapshotException::class, fn() => Spec::stateMachine()->snapshotAt(Module45Answers::claimMachine(), ['progress' => 'approved'], null, $at), 'final recusado por padrão (snapshotAt sem allowFinal)');
        $this->assertTrue($runner->startAt('y', 'claim', 1, [], null, $at)->isAccepted(), 'nenhum estado dado: todas as regiões no inicial');
        $this->assertEquals(['progress' => 'triage', 'pending' => 'none'], $runner->load('y')->snapshot->getStates());
        $this->assertThrows(InvalidArgumentException::class, fn() => $runner->startAt(' padded', 'claim', 1, [], null, $at), 'id inválido como em start()');
        $this->assertThrows(InvalidArgumentException::class, fn() => $runner->startAt('z', 'claim', 1, [], null, $at, tenant: ''), 'tenant inválido como em start()');
    }

    private function testStartAtDeadlinesPauseAndSources(): void
    {
        $machine = Spec::machine('paused', 1)
            ->region('progress', 'triage')
                ->state('triage')->on('GO', 'analysis')
                ->state('analysis')->deadline(event: 'LATE', afterFrom: 'context.sla', pausedWhile: 'pending.open')
                    ->on('LATE', 'late')
                ->state('late')
            ->region('pending', 'none')
                ->state('none')->on('OPEN', 'open')
                ->state('open')->on('CLOSE', 'none')
            ->build();
        $persistence = MachinePersistence::inMemory();
        $runner = new MachineRunner(Spec::stateMachine(), new InMemoryMachineDefinitionRepository([$machine]), $persistence);
        $at = new DateTimeImmutable('2026-10-21T09:00:00Z');

        $rejected = $runner->startAt('p-0', 'paused', 1, ['progress' => 'analysis'], null, $at);
        $this->assertTrue($rejected->isRejected(), 'fonte do prazo ausente: importação recusada');
        $this->assertEquals([TransitionResult::CODE_DEADLINE_SOURCE_INVALID], $rejected->getReason()->getCodes());
        $this->assertThrows(MachineInstanceNotFoundException::class, fn() => $runner->load('p-0'), 'recusada: nada gravado');
        $this->assertCount(0, $persistence->history->findByInstance('p-0'));

        $runner->startAt('p-1', 'paused', 1, ['progress' => 'analysis', 'pending' => 'open'], ['sla' => 'P2D'], $at);
        $paused = $runner->load('p-1')->snapshot;
        $this->assertTrue($paused->isPaused('progress') && $paused->dueAt('progress') === null, 'pendência aberta: o prazo importado nasce pausado');
        $this->assertEquals(172800, $paused->pausedRemaining('progress'), 'com o saldo inteiro');
        $resumed = $runner->send('p-1', Event::external('CLOSE', $at->modify('+1 day')), ['sla' => 'P2D']);
        $this->assertTrue($resumed->isAccepted(), 'a pendência fecha');
        $this->assertEquals('2026-10-24T09:00:00.000000+00:00', $runner->load('p-1')->snapshot->dueAt('progress')?->format(MachineSnapshot::DATE_FORMAT), 'retoma com o saldo');

        $runner->startAt('p-2', 'paused', 1, ['progress' => 'analysis'], ['sla' => 'PT3H'], $at);
        $this->assertEquals('2026-10-21T12:00:00.000000+00:00', $runner->load('p-2')->snapshot->dueAt('progress')?->format(MachineSnapshot::DATE_FORMAT), 'fonte do contexto: a partir do instante');
    }

    private function testSnapshotAtIsPure(): void
    {
        $machine = Spec::stateMachine();
        $claim = Module45Answers::claimMachine();
        $at = new DateTimeImmutable('2026-10-21T09:00:00Z');
        $a = $machine->snapshotAt($claim, ['progress' => 'late', 'pending' => 'open'], null, $at);
        $b = $machine->snapshotAt($claim, ['pending' => 'open', 'progress' => 'late'], null, $at);
        $this->assertEquals($a->toArray(), $b->toArray(), 'snapshotAt(): a ordem do mapa não importa (regiões na ordem da definição)');
        $this->assertEquals(
            [['progress', null, 'late', null, null], ['pending', null, 'open', null, null]],
            array_map(static fn($t): array => [$t->region, $t->from, $t->to, $t->transitionId, $t->eventType], $a->getTransitions()),
            'uma transição tomada por região, sem origem'
        );
        $this->assertFalse($a->snapshot->isDone());
        $done = $machine->snapshotAt($claim, ['progress' => 'cancelled'], null, $at, true);
        $this->assertTrue($done->snapshot->isDone(), 'região governante final: concluída (a região auxiliar não segura)');
        $this->assertTrue($machine->snapshotAt($claim, ['progress' => 'analysis'], null, $at)->snapshot->dueAt('progress') == $at->modify('+5 days'));
    }

    // ==========================================
    // Effect context
    // ==========================================

    private function testEffectContextInMemory(): void
    {
        $persistence = MachinePersistence::inMemory();
        $seen = [];
        $handlers = (new EffectHandlerRegistry())
            ->register('notify_analyst', static function (Effect $effect, string $key, string $instanceId, EffectContext $context) use (&$seen): void {
                $seen[] = ['async', $key, $instanceId, $context->eventType(), $context->payload(), $context->actor(), $context->occurredAt()?->format(MachineSnapshot::DATE_FORMAT), $context->machineKey, $context->machineVersion, $context->instanceVersion, $context->effect === $effect, $context->idempotencyKey === $key, $context->event?->idempotencyKey];
            })
            ->registerSync('mail_cancellation', static function (Effect $effect, string $key, string $instanceId, EffectContext $context) use (&$seen): void {
                $seen[] = ['sync', $key, $context->payloadValue('motive'), $context->payloadValue('detail.level'), $context->payloadValue('missing'), $context->actor(), $context->event?->origin];
            });
        $runner = new MachineRunner(Spec::stateMachine(), new InMemoryMachineDefinitionRepository([Module45Answers::claimMachine()]), $persistence, $handlers);
        $at = new DateTimeImmutable('2026-10-21T09:00:00-03:00');
        $runner->start('c-1', 'claim', 1, null, $at, 'ana');
        $runner->send('c-1', Event::external('START_ANALYSIS', $at->modify('+1 minute'), ['note' => 'Ação ✓'], 'k-1'), null, 'bob');
        $runner->send('c-1', Event::external('CANCEL', $at->modify('+2 minutes'), ['motive' => 'Duplicate', 'detail' => ['level' => 2]]), null, 'carol');
        $this->assertEquals([['sync', 'c-1:3:0', 'Duplicate', 2, null, 'carol', EventOrigin::EXTERNAL]], $seen, 'handler síncrono de quatro parâmetros recebe o contexto (motivo, caminho, ator)');

        $entries = $persistence->outbox->findByInstance('c-1');
        $this->assertEquals(
            [[null, null, 'pending'], ['2026-10-21 12:02:00.000000', '2026-10-21 12:02:00.000000', 'delivered']],
            array_map(static fn(OutboxEntry $o): array => [$o->toArray()['delivered_at'], $o->toArray()['last_attempt_at'], $o->toArray()['status']], $entries),
            'a entrada assíncrona pendente; a síncrona entregue no instante do evento'
        );
        $this->assertEquals('START_ANALYSIS', $entries[0]->event?->type);
        $this->assertEquals(['note' => 'Ação ✓'], $entries[0]->event?->payload);
        $report = (new EffectDispatcher($persistence->outbox, $handlers))->dispatchPending();
        $this->assertEquals(1, $report->deliveredCount());
        $this->assertEquals(
            ['async', 'c-1:2:0', 'c-1', 'START_ANALYSIS', ['note' => 'Ação ✓'], 'bob', '2026-10-21T09:01:00.000000-03:00', 'claim', 1, 2, true, true, 'k-1'],
            $seen[1],
            'handler assíncrono de quatro parâmetros recebe o contexto da entrada'
        );

        $starts = [];
        $entryHandlers = (new EffectHandlerRegistry())->register('welcome', static function (Effect $effect, string $key, string $instanceId, EffectContext $context) use (&$starts): void {
            $starts[] = [$context->eventType(), $context->payload(), $context->actor(), $context->event?->origin];
        });
        $entryPersistence = MachinePersistence::inMemory();
        $entryRunner = new MachineRunner(Spec::stateMachine(), new InMemoryMachineDefinitionRepository([$this->claimWithEntries(1)]), $entryPersistence, $entryHandlers);
        $entryRunner->start('s-1', 'claim', 1, null, $at, 'ana');
        (new EffectDispatcher($entryPersistence->outbox, $entryHandlers))->dispatchPending();
        $this->assertEquals([['machine.start', [], 'ana', EventOrigin::INTERNAL]], $starts, 'entry action do start: o evento machine.start, payload vazio e o ator do start()');
    }

    private function testHandlerSignatures(): void
    {
        $calls = [];
        $registry = (new EffectHandlerRegistry())
            ->register('three', static function (Effect $e, string $k, string $i) use (&$calls): void {
                $calls[] = ['three', func_num_args()];
            })
            ->register('two', static function (Effect $e, string $k) use (&$calls): void {
                $calls[] = ['two', func_num_args()];
            })
            ->register('variadic', static function (mixed ...$args) use (&$calls): void {
                $calls[] = ['variadic', count($args), $args[3] instanceof EffectContext];
            })
            ->register('invokable', $invokable = new class () {
                public ?string $seen = null;

                public function __invoke(Effect $e, string $k, string $i, ?EffectContext $c = null): void
                {
                    $this->seen = $c?->instanceId;
                }
            })
            ->registerSync('four', static function (Effect $e, string $k, string $i, EffectContext $c) use (&$calls): void {
                $calls[] = ['four', $c->hasEvent()];
            });
        $this->assertEquals([false, false, true, true, true], array_map($registry->takesContext(...), ['three', 'two', 'variadic', 'invokable', 'four']), 'takesContext() lê a assinatura');
        $this->assertFalse($registry->takesContext('none'));
        $at = new DateTimeImmutable('2026-10-21T09:00:00Z');
        foreach (['three', 'two', 'variadic', 'invokable', 'four'] as $action) {
            $registry->invoke(new EffectContext(new Effect($action, [], 'r', EffectPhase::ENTRY, 's', null), 'i:1:0', 'i', 'k', 1, 1));
        }
        $this->assertEquals([['three', 3], ['two', 3], ['variadic', 4, true], ['four', false]], $calls, 'cada handler recebe a forma que declara');
        $this->assertEquals('i', $invokable->seen, 'objeto invocável com o quarto parâmetro opcional recebe o contexto');
        $e = $this->assertThrows(InvalidArgumentException::class, fn() => $registry->invoke(new EffectContext(new Effect('none', [], 'r', EffectPhase::ENTRY, 's', null), 'i:1:0', 'i', 'k', 1, 1)), 'invoke() sem handler');
        $this->assertTrue(str_contains($e->getMessage(), '"none"'));
        $context = new EffectContext(new Effect('x', [], 'r', EffectPhase::ENTRY, 's', null), 'i:1:0', 'i', 'k', 1, 1);
        $this->assertTrue(!$context->hasEvent() && $context->payload() === [] && $context->payloadValue('a') === null && $context->actor() === null && $context->occurredAt() === null && $context->eventType() === null, 'sem evento: payload vazio e nulos');
    }

    private function testEventSnapshotArrayForm(): void
    {
        $at = new DateTimeImmutable('2026-10-21T09:00:00.123456-03:00');
        $snapshot = EventSnapshot::of(new Event('E', $at, ['a' => ['b' => 1]], 'key', EventOrigin::INTERNAL), 'ana');
        $array = $snapshot->toArray();
        $this->assertEquals(['type' => 'E', 'payload' => ['a' => ['b' => 1]], 'actor' => 'ana', 'origin' => 'internal', 'occurred_at' => '2026-10-21T09:00:00.123456-03:00', 'idempotency_key' => 'key'], $array);
        $this->assertEquals($array, EventSnapshot::fromArray($array)->toArray(), 'ida e volta');
        foreach ([
            'sem tipo' => ['payload' => []] + $array,
            'tipo não texto' => ['type' => 1] + $array,
            'payload não array' => ['payload' => 'x'] + $array,
            'ator não texto' => ['actor' => 1] + $array,
            'origem desconhecida' => ['origin' => 'imported'] + $array,
            'instante não texto' => ['occurred_at' => null] + $array,
            'chave não texto' => ['idempotency_key' => 3] + $array,
            'instante inválido' => ['occurred_at' => 'not a date'] + $array,
        ] as $case => $data) {
            if ($case === 'sem tipo') {
                unset($data['type']);
            }
            $this->assertThrows(InvalidArgumentException::class, fn() => EventSnapshot::fromArray($data), "EventSnapshot::fromArray(): {$case}");
        }
        $minimal = EventSnapshot::fromArray(['type' => 'E', 'origin' => 'external', 'occurred_at' => '2026-10-21T09:00:00Z']);
        $this->assertTrue($minimal->payload === [] && $minimal->actor === null && $minimal->idempotencyKey === null, 'payload, ator e chave opcionais');
    }

    // ==========================================
    // Classification and primary reason
    // ==========================================

    private function testRoleGuardMarks(): void
    {
        $registry = new GuardRegistry();
        $this->assertTrue($registry->markRoleGuards('hasAnyRole', 'isOwner', 'hasAnyRole') === $registry, 'fluente');
        $this->assertEquals(['hasAnyRole', 'isOwner'], $registry->roleGuards(), 'na ordem da marcação, sem repetir');
        $this->assertTrue($registry->isRoleGuard('isOwner') && !$registry->isRoleGuard('payloadHas'));
        $this->assertEquals(['inState', 'payloadHas', 'eventOrigin'], $registry->names(), 'marcar não registra fábrica');
        $this->assertThrows(InvalidMachineDefinitionException::class, fn() => $registry->markRoleGuards(' padded'), 'nome inválido recusado');
        $this->assertThrows(InvalidMachineDefinitionException::class, fn() => $registry->markRoleGuards(''), 'nome vazio recusado');
    }

    private function testClassification(): void
    {
        $definition = Module45Answers::approvalMachine();
        $at = new DateTimeImmutable('2026-10-21T09:00:00Z');
        foreach ([false, true] as $marked) {
            $guards = Module45Answers::approvalGuards();
            if ($marked) {
                $guards->markRoleGuards('hasAnyRole');
            }
            $machine = Spec::stateMachine($guards);
            $start = $machine->initialSnapshot($definition, null, $at)->snapshot;
            $role = $marked ? CandidateReason::ROLE_REFUSED : CandidateReason::GUARD_REFUSED;
            $cases = [
                'analyst@8000' => [['analyst'], 8000, ['APPROVE' => [CandidateReason::ALLOWED, []], 'REJECT' => [CandidateReason::MISSING_PAYLOAD, ['motive', 'note']]]],
                'analyst@90000' => [['analyst'], 90000, ['APPROVE' => [CandidateReason::GUARD_REFUSED, []], 'REJECT' => [CandidateReason::MISSING_PAYLOAD, ['motive', 'note']]]],
                'manager@90000' => [['manager'], 90000, ['APPROVE' => [CandidateReason::GUARD_REFUSED, []], 'REJECT' => [CandidateReason::MISSING_PAYLOAD, ['motive', 'note']]]],
                'board@90000' => [['board'], 90000, ['APPROVE' => [CandidateReason::ALLOWED, []], 'REJECT' => [$role, []]]],
                'intern@8000' => [['intern'], 8000, ['APPROVE' => [$role, []], 'REJECT' => [$role, []]]],
            ];
            foreach ($cases as $case => [$roles, $amount, $expected]) {
                $context = ['roles' => $roles, 'amount' => $amount];
                $next = $machine->nextEvents($definition, $start, $context, $at);
                $this->assertEquals(['APPROVE', 'REJECT'], array_keys($next), "{$case}: ESCALATE é interno e fica de fora");
                foreach ($expected as $type => [$reason, $missing]) {
                    $label = ($marked ? 'com marca' : 'sem marca') . " {$case} {$type}";
                    $this->assertTrue($next[$type]->getCandidateReason() === $reason, "{$label}: {$reason->value} (veio " . ($next[$type]->getCandidateReason()?->value ?? 'null') . ')');
                    $this->assertEquals($missing, $next[$type]->getMissingPayload(), "{$label}: campos que faltam");
                    $plain = $machine->can($definition, $start, Event::external($type, $at), $context);
                    $this->assertEquals($plain->toArray(), $next[$type]->toArray(), "{$label}: status e motivo de can(), inalterados");
                    $this->assertEquals($plain->primaryReason->getCodes(), $next[$type]->primaryReason->getCodes(), "{$label}: o mesmo motivo principal");
                    $this->assertTrue($plain->getCandidateReason() === null, "{$label}: can() não classifica");
                }
                $internal = $machine->nextEvents($definition, $start, $context, $at, true);
                $this->assertTrue($internal['ESCALATE']->getCandidateReason() === CandidateReason::GUARD_REFUSED, "{$case}: evento interno listado é GUARD_REFUSED");
            }
            $withPayload = $machine->can($definition, $start, Event::external('REJECT', $at, ['motive' => 'x', 'note' => 'y']), ['roles' => ['analyst']]);
            $this->assertTrue($withPayload->isAccepted(), 'MISSING_PAYLOAD quer dizer: aceito quando os campos vêm');
        }
    }

    private function testClassificationEdgeCases(): void
    {
        $guards = Spec::guardRegistry()
            ->register('flag', static fn(array $p): ISpecification => Spec::property('context.flag', Spec::equalTo(true))->withCode('FLAG_OFF'))
            ->register('boom', static fn(array $p): ISpecification => new PredicateSpecification(static function (): bool {
                throw new RuntimeException('cannot evaluate');
            }))
            ->register('isOwner', static fn(array $p): ISpecification => Spec::property('context.owner', Spec::equalTo(true))->withCode('NOT_OWNER'))
            ->register('withField', static fn(array $p): ISpecification => Spec::alwaysTrue())
            ->markRoleGuards('isOwner');
        $definition = Spec::machine('edges', 1)
            ->region('main', 'a')
                ->state('a')->deadline('P1D', 'TIMEOUT')
                    ->on('NOT_PAYLOAD', 'b')->guard(G::not(G::ref('payloadHas', ['field' => 'x'])))
                    ->on('ANY_PAYLOAD', 'b')->guard(G::anyOf(G::ref('payloadHas', ['field' => 'a']), G::ref('payloadHas', ['field' => 'b'])))
                    ->on('PAYLOAD_AND_FLAG', 'b')->guard(G::allOf(G::ref('payloadHas', ['field' => 'a']), G::ref('flag')))
                    ->on('NOT_NOT', 'b')->guard(G::not(G::not(G::ref('payloadHas', ['field' => 'deep']))))
                    ->on('BOOM', 'b')->guard('boom')
                    ->on('FIRST_NEEDS_DATA', 'b')->guard('payloadHas', ['field' => 'reason'])
                    ->on('FIRST_NEEDS_DATA', 'c')->guard('flag')
                    ->on('OWNER_THEN_DATA', 'b')->guard('isOwner')
                    ->on('OWNER_THEN_DATA', 'c')->guard('payloadHas', ['field' => 'reason'])
                    ->on('OWNER_OR_DATA', 'b')->guard(G::anyOf(G::ref('isOwner'), G::ref('payloadHas', ['field' => 'reason'])))
                    ->on('A_NOT_B', 'b')->guard(G::allOf(G::ref('payloadHas', ['field' => 'a']), G::not(G::ref('payloadHas', ['field' => 'b']))))
                    ->on('FIELD_PARAM', 'b')->guard(G::allOf(G::ref('payloadHas', ['field' => 'a']), G::ref('withField', ['field' => 'zzz'])))
                    ->on('DUP', 'b')->guard(G::allOf(G::ref('payloadHas', ['field' => 'a']), G::anyOf(G::ref('payloadHas', ['field' => 'a']), G::ref('payloadHas', ['field' => 'b']))))
                    ->on('BOOM_THEN_DATA', 'b')->guard('boom')
                    ->on('BOOM_THEN_DATA', 'c')->guard('payloadHas', ['field' => 'r'])
                    ->on('TWO_DATA', 'b')->guard('payloadHas', ['field' => 'r'])
                    ->on('TWO_DATA', 'c')->guard('payloadHas', ['field' => 's'])
                    ->on('MR', 'b', 'mr_flag')->guard('flag')
                    ->on('MR', 'c', 'mr_free')
                    ->on('MR', 'b', 'mr_data')->guard('payloadHas', ['field' => 'zz'])
                    ->on('TIMEOUT', 'c')
                    ->on('OPEN', 'b')
                ->state('b')
                ->state('c')
            ->region('side', 'idle')
                ->state('idle')
                    ->on('OPEN', 'idle', 'side_open')->guard('flag')
                    ->on('MR', 'idle', 'side_mr')->guard('payloadHas', ['field' => 'q'])
            ->build();
        $machine = Spec::stateMachine($guards);
        $at = new DateTimeImmutable('2026-10-21T09:00:00Z');
        $start = $machine->initialSnapshot($definition, null, $at)->snapshot;
        $next = $machine->nextEvents($definition, $start, ['flag' => false, 'owner' => false], $at, true);
        $expected = [
            'NOT_PAYLOAD' => [CandidateReason::ALLOWED, []],
            'ANY_PAYLOAD' => [CandidateReason::MISSING_PAYLOAD, ['a', 'b']],
            'PAYLOAD_AND_FLAG' => [CandidateReason::GUARD_REFUSED, []],
            'NOT_NOT' => [CandidateReason::MISSING_PAYLOAD, ['deep']],
            'BOOM' => [CandidateReason::GUARD_REFUSED, []],
            'FIRST_NEEDS_DATA' => [CandidateReason::MISSING_PAYLOAD, ['reason']],
            'OWNER_THEN_DATA' => [CandidateReason::MISSING_PAYLOAD, ['reason']],
            'OWNER_OR_DATA' => [CandidateReason::MISSING_PAYLOAD, ['reason']],
            'A_NOT_B' => [CandidateReason::MISSING_PAYLOAD, ['a']],
            'FIELD_PARAM' => [CandidateReason::MISSING_PAYLOAD, ['a']],
            'DUP' => [CandidateReason::MISSING_PAYLOAD, ['a', 'b']],
            'BOOM_THEN_DATA' => [CandidateReason::GUARD_REFUSED, []],
            'TWO_DATA' => [CandidateReason::MISSING_PAYLOAD, ['r']],
            'MR' => [CandidateReason::MISSING_PAYLOAD, ['q']],
            'TIMEOUT' => [CandidateReason::GUARD_REFUSED, []],
            'OPEN' => [CandidateReason::GUARD_REFUSED, []],
        ];
        $this->assertEquals(array_keys($expected), array_keys($next), 'os eventos do estado, na ordem');
        foreach ($expected as $type => [$reason, $missing]) {
            $this->assertTrue($next[$type]->getCandidateReason() === $reason, "{$type}: {$reason->value} (veio " . ($next[$type]->getCandidateReason()?->value ?? 'null') . ')');
            $this->assertEquals($missing, $next[$type]->getMissingPayload(), "{$type}: campos que faltam");
        }
        $owner = $machine->nextEvents($definition, $start, ['flag' => true, 'owner' => true], $at);
        $this->assertTrue($owner['OWNER_THEN_DATA']->getCandidateReason() === CandidateReason::ALLOWED && $owner['OPEN']->getCandidateReason() === CandidateReason::ALLOWED, 'dono e flag: permitido');
        $this->assertTrue($owner['PAYLOAD_AND_FLAG']->getCandidateReason() === CandidateReason::MISSING_PAYLOAD, 'flag ligada: só falta o dado');

        $onlyRole = Spec::machine('only_role', 1)
            ->region('main', 'a')
                ->state('a')
                    ->on('TAKE', 'b')->guard(G::allOf(G::ref('isOwner'), G::ref('payloadHas', ['field' => 'why'])))
                ->state('b')
            ->region('other', 'x')
                ->state('x')
                    ->on('TAKE', 'x', 'other_take')->guard('flag')
            ->build();
        $result = $machine->nextEvents($onlyRole, $machine->initialSnapshot($onlyRole, null, $at)->snapshot, ['flag' => false, 'owner' => false], $at)['TAKE'];
        $this->assertTrue($result->getCandidateReason() === CandidateReason::ROLE_REFUSED && $result->getMissingPayload() === [], 'papel recusa numa região e guard na outra: ROLE_REFUSED domina, sem campos');
        $this->assertTrue($machine->can($onlyRole, $machine->initialSnapshot($onlyRole, null, $at)->snapshot, Event::external('NOPE', $at))->getCandidateReason() === null, 'não tratado: sem classificação');
    }

    private function testPrimaryReason(): void
    {
        $definition = Module45Answers::approvalMachine();
        $at = new DateTimeImmutable('2026-10-21T09:00:00Z');
        $table = [
            // roles, amount, primary without marks, primary with marks, transition of the primary
            [['analyst'], 30000, ['ABOVE_APPROVAL_LIMIT'], ['ABOVE_APPROVAL_LIMIT'], 'approve_analyst'],
            [['manager'], 90000, ['ABOVE_APPROVAL_LIMIT'], ['ABOVE_APPROVAL_LIMIT'], 'approve_manager'],
            [['intern'], 8000, ['ROLE_NOT_ALLOWED'], ['ROLE_NOT_ALLOWED'], 'approve_analyst'],
            [['intern'], 90000, ['ROLE_NOT_ALLOWED'], ['ROLE_NOT_ALLOWED'], 'approve_board'],
        ];
        foreach ([false, true] as $marked) {
            $guards = Module45Answers::approvalGuards();
            if ($marked) {
                $guards->markRoleGuards('hasAnyRole');
            }
            $machine = Spec::stateMachine($guards);
            $start = $machine->initialSnapshot($definition, null, $at)->snapshot;
            foreach ($table as [$roles, $amount, $plain, $withMarks, $transition]) {
                $label = ($marked ? 'com marca ' : 'sem marca ') . implode('+', $roles) . "@{$amount}";
                $result = $machine->can($definition, $start, Event::external('APPROVE', $at), ['roles' => $roles, 'amount' => $amount]);
                $this->assertTrue($result->isRejected(), $label);
                $this->assertEquals($marked ? $withMarks : $plain, $result->getPrimaryReason()->getCodes(), "{$label}: motivo principal");
                $this->assertEquals($transition, $result->getPrimaryReason()->failures[0]->metadata['transition_id'] ?? null, "{$label}: da transição {$transition}");
                $this->assertCount(2, $result->getDetails(), "{$label}: as outras duas candidatas nos detalhes");
                $codes = array_merge($result->getPrimaryReason()->getCodes(), ...array_map(static fn(SpecificationResult $d): array => $d->getCodes(), $result->getDetails()));
                sort($codes);
                $all = $result->getReason()->getCodes();
                sort($all);
                $this->assertEquals($all, $codes, "{$label}: principal + detalhes = o motivo inteiro");
                $ids = array_map(static fn(SpecificationResult $d): string => $d->failures[0]->metadata['transition_id'], $result->getDetails());
                $this->assertFalse(in_array($transition, $ids, true), "{$label}: o principal não se repete nos detalhes");
            }
        }

        $machine = Spec::stateMachine(Module45Answers::approvalGuards());
        $start = $machine->initialSnapshot($definition, null, $at)->snapshot;
        $twoRegions = $machine->can($definition, $start, Event::external('REJECT', $at), ['roles' => ['analyst']]);
        $this->assertTrue($twoRegions->getPrimaryReason()->failures[0]->metadata['transition_id'] === $twoRegions->getReason()->failures[0]->metadata['transition_id'], 'duas regiões, uma candidata cada: o principal é o da primeira região');
        $this->assertCount(1, $twoRegions->getDetails(), 'e o da outra região vai para os detalhes');
        $this->assertEquals('reject_review', $twoRegions->getDetails()[0]->failures[0]->metadata['transition_id']);

        $single = Spec::stateMachine()->can(Module45Answers::claimMachine(), Spec::stateMachine()->snapshotAt(Module45Answers::claimMachine(), ['progress' => 'analysis'], null, $at)->snapshot, Event::external('CANCEL', $at));
        $this->assertTrue($single->isRejected() && $single->primaryReason === $single->reason && $single->getDetails() === [], 'uma candidata: o principal é o próprio motivo, sem detalhes');
        $accepted = $machine->can($definition, $start, Event::external('APPROVE', $at), ['roles' => ['board'], 'amount' => 1]);
        $this->assertTrue($accepted->primaryReason === $accepted->reason && $accepted->primaryReason->isSatisfied, 'aceito: o principal é o motivo satisfeito');
        $unhandled = $machine->can($definition, $start, Event::external('NOPE', $at), null);
        $this->assertTrue($unhandled->primaryReason === $unhandled->reason, 'não tratado: o principal é o motivo');

        $guards = Spec::guardRegistry()
            ->register('two', static fn(array $p): ISpecification => Spec::property('context.a', Spec::equalTo(1))->withCode('A')->and(Spec::property('context.b', Spec::equalTo(1))->withCode('B')))
            ->register('one', static fn(array $p): ISpecification => Spec::property('context.c', Spec::equalTo(1))->withCode('C'))
            ->register('boom', static fn(array $p): ISpecification => new PredicateSpecification(static function (): bool {
                throw new RuntimeException('cannot evaluate');
            }));
        $ranked = Spec::machine('ranked', 1)
            ->region('main', 'a')
                ->state('a')
                    ->on('FEWEST', 'b', 'two_failures')->guard('two')
                    ->on('FEWEST', 'b', 'one_failure')->guard('one')
                    ->on('ERROR', 'b', 'plain')->guard('one')
                    ->on('ERROR', 'b', 'throws')->guard('boom')
                    ->on('INTERNAL', 'b', 'internal_one')->internal()
                    ->on('INTERNAL', 'b', 'external_two')->guard('two')
                ->state('b')
            ->build();
        $rankedMachine = Spec::stateMachine($guards);
        $s = $rankedMachine->initialSnapshot($ranked, null, $at)->snapshot;
        $context = ['a' => 0, 'b' => 0, 'c' => 0];
        $this->assertEquals('one_failure', $rankedMachine->can($ranked, $s, Event::external('FEWEST', $at), $context)->getPrimaryReason()->failures[0]->metadata['transition_id'], 'menos falhas primeiro');
        $error = $rankedMachine->can($ranked, $s, Event::external('ERROR', $at), $context)->getPrimaryReason();
        $this->assertTrue($error->isError && $error->failures[0]->metadata['transition_id'] === 'throws', 'guard que não pôde ser avaliado vem primeiro');
        $this->assertEquals('external_two', $rankedMachine->can($ranked, $s, Event::external('INTERNAL', $at), $context)->getPrimaryReason()->failures[0]->metadata['transition_id'], 'transição só interna vai para o fim, mesmo com menos falhas');
        $guards->markRoleGuards('boom');
        $roleError = $rankedMachine->can($ranked, $s, Event::external('ERROR', $at), $context)->getPrimaryReason();
        $this->assertTrue($roleError->isError && $roleError->failures[0]->metadata['transition_id'] === 'throws', 'guard de papel que lança: continua erro de avaliação, não recusa por papel');
        $this->assertTrue($rankedMachine->nextEvents($ranked, $s, $context, $at)['ERROR']->getCandidateReason() === CandidateReason::GUARD_REFUSED, 'e a classificação não lança');
    }

    // ==========================================
    // Batches
    // ==========================================

    private function testSendManyBasics(string $label, Closure $persistenceFactory): void
    {
        $persistence = $persistenceFactory();
        $runner = new MachineRunner(Spec::stateMachine(), new InMemoryMachineDefinitionRepository([Module45Answers::claimMachine()]), $persistence);
        $at = new DateTimeImmutable('2026-10-21T09:00:00Z');
        foreach (['b-1', 'b-2'] as $id) {
            $runner->start($id, 'claim', 1, null, $at, tenant: '7');
        }
        $this->assertEquals([], $runner->sendMany([]), "{$label}: lote vazio");
        $this->assertThrows(InvalidArgumentException::class, fn() => $runner->sendMany([], chunkSize: 0), "{$label}: chunk < 1");
        $this->assertThrows(InvalidArgumentException::class, fn() => $runner->sendMany([Event::external('X', $at)]), "{$label}: item que não é BatchEvent");
        $generated = (static function () use ($at): \Generator {
            yield new BatchEvent('b-1', Event::external('START_ANALYSIS', $at, [], 'k'), null, 'ana');
            yield new BatchEvent('b-1', Event::external('START_ANALYSIS', $at, [], 'k'));
            yield new BatchEvent('b-2', Event::external('START_ANALYSIS', $at), null, null, 9);
            yield new BatchEvent('b-2', Event::external('NOPE', $at));
            yield new BatchEvent('b-9', Event::external('START_ANALYSIS', $at));
            yield new BatchEvent('b-2', Event::external('CANCEL', $at));
        })();
        $outcomes = $runner->sendMany($generated, 'batch', 2, '7');
        $this->assertEquals([0, 1, 2, 3, 4, 5], array_map(static fn(BatchEventOutcome $o): int => $o->index, $outcomes), "{$label}: um resultado por evento, na ordem");
        $this->assertTrue($outcomes[0]->isAccepted() && $outcomes[0]->isProcessed() && $outcomes[0]->errorMessage() === null, "{$label}: aceito");
        $this->assertTrue($outcomes[1]->isAccepted() && $outcomes[1]->result?->toArray() === $outcomes[0]->result?->toArray(), "{$label}: a mesma chave no mesmo chunk devolve o original");
        $this->assertTrue($outcomes[2]->error instanceof ConcurrencyConflictException && !$outcomes[2]->isProcessed() && !$outcomes[2]->isAccepted(), "{$label}: versão esperada errada");
        $this->assertTrue($outcomes[3]->result?->isUnhandled() === true, "{$label}: não tratado");
        $this->assertTrue($outcomes[4]->error instanceof MachineInstanceNotFoundException && $outcomes[4]->errorMessage() === 'Machine instance "b-9" does not exist.', "{$label}: instância desconhecida");
        $this->assertTrue($outcomes[5]->result?->isUnhandled() === true, "{$label}: b-2 continua em triage");
        $history = $persistence->history->findByInstance('b-1');
        $this->assertCount(3, $history, "{$label}: start (duas regiões) + um START_ANALYSIS (a repetição não grava)");
        $this->assertEquals('ana', $history[2]->actor, "{$label}: o ator do evento vence o padrão");
        $this->assertEquals(2, $runner->load('b-1')->version);
        $this->assertEquals(1, $runner->load('b-2')->version, "{$label}: b-2 intocada");

        $other = $runner->sendMany([new BatchEvent('b-1', Event::external('APPROVE', $at))], tenant: '12');
        $this->assertTrue($other[0]->error instanceof MachineInstanceNotFoundException, "{$label}: instância de outro tenant = desconhecida");
    }

    private function testSendManyReplayAndNested(): void
    {
        $at = new DateTimeImmutable('2026-10-21T09:00:00Z');
        [$runner, $persistence] = $this->propertyRunner(MachinePersistence::inMemory());
        foreach (['p-0', 'p-1', 'p-2'] as $id) {
            $runner->start($id, 'prop', 1, null, $at);
        }
        $events = [
            new BatchEvent('p-0', Event::external('GO', $at, [], 'k')),
            new BatchEvent('p-1', Event::external('GO', $at, ['veto' => true])),
            new BatchEvent('p-2', Event::external('TOGGLE', $at)),
        ];
        $outcomes = $runner->sendMany($events);
        $this->assertTrue($outcomes[0]->isAccepted() && $outcomes[1]->error instanceof SyncEffectFailedException && $outcomes[2]->isAccepted(), 'veto no meio do chunk: o chunk é reenviado evento a evento');
        $this->assertEquals([2, 1, 2], [$runner->load('p-0')->version, $runner->load('p-1')->version, $runner->load('p-2')->version], 'só o vetado ficou de fora');
        $this->assertCount(2, $persistence->history->findByInstance('p-1'), 'nada do vetado gravado (só as duas linhas do start)');
        $this->assertTrue($persistence->idempotency->find('p-0', 'k') !== null, 'a chave do primeiro gravada uma vez');

        $thrown = $this->assertThrows(SyncEffectFailedException::class, fn() => $persistence->transactions->transactional(fn() => $runner->sendMany([
            new BatchEvent('p-2', Event::external('TOGGLE', $at)),
            new BatchEvent('p-1', Event::external('GO', $at, ['veto' => true])),
        ])), 'dentro de uma transação do chamador, o veto se propaga, como em send()');
        $this->assertTrue($thrown->getPrevious() instanceof RuntimeException);
        $this->assertEquals(2, $runner->load('p-2')->version, 'e a transação do chamador desfaz tudo');

        $history = new Module45ExplodingHistory(new InMemoryMachineHistoryStore(), 'x-1');
        $instances = new InMemoryMachineInstanceStore();
        $outbox = new InMemoryEffectOutbox();
        $idempotency = new InMemoryIdempotencyStore();
        $broken = new MachinePersistence($instances, $history, $outbox, $idempotency, new InMemoryTransactionManager([$instances, $history, $outbox, $idempotency]));
        [$brokenRunner] = $this->propertyRunner($broken);
        foreach (['x-0', 'x-1', 'x-2'] as $id) {
            $brokenRunner->start($id, 'prop', 1, null, $at);
        }
        $e = $this->assertThrows(RuntimeException::class, fn() => $brokenRunner->sendMany([
            new BatchEvent('x-0', Event::external('TOGGLE', $at)),
            new BatchEvent('x-1', Event::external('TOGGLE', $at)),
            new BatchEvent('x-2', Event::external('TOGGLE', $at)),
        ]), 'erro de gravação: propaga do evento que o causa');
        $this->assertEquals('history unavailable for x-1', $e->getMessage());
        $this->assertEquals([2, 1, 1], [$brokenRunner->load('x-0')->version, $brokenRunner->load('x-1')->version, $brokenRunner->load('x-2')->version], 'como num laço de send(): o anterior gravado, o seguinte não processado');
    }

    private function testSendManyFallbackStore(): void
    {
        $instances = new InMemoryMachineInstanceStore();
        $history = new InMemoryMachineHistoryStore();
        $outbox = new InMemoryEffectOutbox();
        $plain = new Module45PlainIdempotencyStore(new InMemoryIdempotencyStore());
        $transactions = new Module45CountingTransactions(new InMemoryTransactionManager([$instances, $history, $outbox, $plain]));
        $persistence = new MachinePersistence($instances, $history, $outbox, $plain, $transactions);
        $this->assertFalse($plain instanceof IBatchIdempotencyStore);
        [$runner] = $this->propertyRunner($persistence);
        $at = new DateTimeImmutable('2026-10-21T09:00:00Z');
        $runner->start('f-0', 'prop', 1, null, $at);
        $plain->finds = 0;
        $transactions->calls = 0;
        $outcomes = $runner->sendMany([
            new BatchEvent('f-0', Event::external('TOGGLE', $at, [], 'a')),
            new BatchEvent('f-0', Event::external('TOGGLE', $at, [], 'a')),
            new BatchEvent('f-0', Event::external('TOGGLE', $at, [], 'b')),
            new BatchEvent('f-0', Event::external('TOGGLE', $at)),
        ]);
        $this->assertEquals([true, true, true, true], array_map(static fn(BatchEventOutcome $o): bool => $o->isAccepted(), $outcomes));
        $this->assertEquals(3, $plain->finds, 'store sem lote: um find() por evento com chave');
        $this->assertEquals(1, $transactions->calls, 'num só chunk, sem reenvio');
        $this->assertEquals(4, $runner->load('f-0')->version, 'a repetição da chave a não gravou');
    }

    private function testSendManyTransactionsPerChunk(): void
    {
        $instances = new InMemoryMachineInstanceStore();
        $history = new InMemoryMachineHistoryStore();
        $outbox = new InMemoryEffectOutbox();
        $idempotency = new InMemoryIdempotencyStore();
        $transactions = new Module45CountingTransactions(new InMemoryTransactionManager([$instances, $history, $outbox, $idempotency]));
        [$runner] = $this->propertyRunner(new MachinePersistence($instances, $history, $outbox, $idempotency, $transactions));
        $at = new DateTimeImmutable('2026-10-21T09:00:00Z');
        $runner->start('t-0', 'prop', 1, null, $at);
        $runner->start('t-1', 'prop', 1, null, $at);
        $transactions->calls = 0;
        $outcomes = $runner->sendMany([
            new BatchEvent('t-0', Event::external('TOGGLE', $at, [], 'same')),
            new BatchEvent('t-0', Event::external('TOGGLE', $at, [], 'same')),
            new BatchEvent('t-1', Event::external('GO', $at, [], 'go')),
            new BatchEvent('t-1', Event::external('NOPE', $at)),
            new BatchEvent('t-9', Event::external('GO', $at)),
            new BatchEvent('t-1', Event::external('GO', $at, [], 'go')),
        ], chunkSize: 2);
        $this->assertEquals(3, $transactions->calls, 'seis eventos em chunks de 2: três transações, nenhum reenvio (a chave repetida no mesmo chunk devolve o original)');
        $this->assertEquals(['result', 'result', 'result', 'result', 'error', 'result'], array_map(static fn(BatchEventOutcome $o): string => self::describe($o)[0], $outcomes));
        $this->assertEquals([2, 2], [$runner->load('t-0')->version, $runner->load('t-1')->version], 'cada chave gravou uma vez');

        $transactions->calls = 0;
        $runner->sendMany([new BatchEvent('t-0', Event::external('GO', $at, ['veto' => true]))]);
        $this->assertEquals(2, $transactions->calls, 'falha depois da primeira gravação: o chunk e o reenvio por send()');
    }

    private function testNumericInstanceIds(string $label, Closure $persistenceFactory): void
    {
        [$runner, $persistence] = $this->propertyRunner($persistenceFactory());
        $at = new DateTimeImmutable('2026-10-21T09:00:00Z');
        foreach (['4711', '42', '007'] as $id) {
            $runner->start($id, 'prop', 1, null, $at);
        }
        $events = array_map(static fn(string $id): BatchEvent => new BatchEvent($id, Event::external('TOGGLE', $at, [], '1')), ['4711', '42', '007']);
        $first = $runner->sendMany($events);
        $again = $runner->sendMany($events);
        foreach ([0, 1, 2] as $i) {
            $this->assertTrue($first[$i]->isAccepted() && $again[$i]->isAccepted(), "{$label}: ids e chaves numéricos ({$i})");
            $this->assertEquals($first[$i]->result?->toArray(), $again[$i]->result?->toArray(), "{$label}: a repetição acha o original ({$i})");
        }
        $this->assertEquals([2, 2, 2], [$runner->load('4711')->version, $runner->load('42')->version, $runner->load('007')->version], "{$label}: nada gravado na repetição");
        $store = $persistence->idempotency;
        \assert($store instanceof IBatchIdempotencyStore);
        $found = $store->findMany(['4711' => ['1'], '007' => ['1'], '42' => ['2']]);
        $this->assertTrue(count($found) === 2 && isset($found['4711']['1'], $found['007']['1']) && !isset($found['42']), "{$label}: findMany() com ids e chaves numéricos");
    }

    /**
     * For any sequence, sendMany() gives what the same send() calls in the same order give (each failure of
     * one event caught as an outcome): the results, and the snapshots, history, outbox and idempotency records.
     */
    private function testSendManyEqualsSendProperty(string $label, Closure $persistenceFactory, int $sequences): void
    {
        $compared = 0;
        $kinds = ['accepted' => 0, 'rejected' => 0, 'unhandled' => 0, 'error' => 0];
        for ($seed = 1; $seed <= $sequences; $seed++) {
            $random = new Randomizer(new Mt19937(45000 + $seed));
            $events = $this->randomEvents($random, $random->getInt(1, 40));
            $chunk = $random->getInt(1, 9);
            $nested = $seed % 5 === 0;
            if ($nested) {
                $events = array_values(array_filter($events, static fn(BatchEvent $e): bool => ($e->event->payload['veto'] ?? false) !== true));
            }

            [$batchRunner, $batch] = $this->propertyRunner($persistenceFactory());
            $this->startPropertyInstances($batchRunner);
            $send = static fn(): array => $batchRunner->sendMany($events, 'batch', $chunk);
            $batchOutcomes = array_map(self::describe(...), $nested ? $batch->transactions->transactional($send) : $send());
            $batchState = $this->dump($batch);

            [$unitRunner, $unit] = $this->propertyRunner($persistenceFactory());
            $this->startPropertyInstances($unitRunner);
            $loop = static function () use ($unitRunner, $events): array {
                $out = [];
                foreach ($events as $event) {
                    try {
                        $out[] = $unitRunner->send($event->instanceId, $event->event, $event->context, $event->actor ?? 'batch', $event->expectedVersion);
                    } catch (MachineInstanceNotFoundException | ConcurrencyConflictException | SyncEffectFailedException $e) {
                        $out[] = $e;
                    }
                }

                return $out;
            };
            $unitOutcomes = array_map(self::describe(...), $nested ? $unit->transactions->transactional($loop) : $loop());
            $unitState = $this->dump($unit);

            $this->assertEquals($unitOutcomes, $batchOutcomes, "{$label} semente {$seed} (chunk {$chunk}" . ($nested ? ', transação do chamador' : '') . '): os mesmos resultados');
            $this->assertEquals($unitState, $batchState, "{$label} semente {$seed}: os mesmos snapshots, histórico, outbox e idempotência");
            foreach ($batchOutcomes as $outcome) {
                $kinds[$outcome[0] === 'error' ? 'error' : $outcome[1]['status']]++;
            }
            $compared += count($events);
        }
        $this->assertTrue($kinds['accepted'] > 0 && $kinds['rejected'] > 0 && $kinds['unhandled'] > 0 && $kinds['error'] > 0, "{$label}: a amostra cobre aceitos, recusados, não tratados e falhas");
        fwrite(STDOUT, sprintf("    [2.10.0] %s: sendMany() == send() em %d sequências, %d eventos (%s)\n", $label, $sequences, $compared, json_encode($kinds)));
    }

    private function testBatchQueriesAndTransactions(): void
    {
        $pdo = new Module45CountingPdo('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        Module45Answers::loadDdl($pdo, (string) file_get_contents(self::DDL));
        [$runner] = $this->propertyRunner(MachinePersistence::pdo($pdo));
        $at = new DateTimeImmutable('2026-10-21T09:00:00Z');
        $ids = array_map(static fn(int $i): string => 'q-' . $i, range(0, 9));
        foreach ($ids as $id) {
            $runner->start($id, 'prop', 1, null, $at);
        }
        $events = array_map(static fn(string $id): BatchEvent => new BatchEvent($id, Event::external('TOGGLE', $at, [], 'key-' . $id)), $ids);
        $lookups = static fn(PDO $pdo): int => count(array_filter($pdo->statements, static fn(string $sql): bool => str_starts_with($sql, 'SELECT') && str_contains($sql, 'state_machine_idempotency')));

        $pdo->reset();
        $pdo->begins = 0;
        $runner->sendMany($events, chunkSize: 4);
        $this->assertEquals(3, $pdo->begins, 'dez eventos em chunks de 4: três transações');
        $this->assertEquals(3, $lookups($pdo), 'e uma consulta de idempotência por chunk');
        $this->assertTrue(str_contains(implode("\n", $pdo->statements), '(instance_id = :i0 AND idempotency_key IN (:k0)) OR (instance_id = :i1 AND idempotency_key IN (:k1))'), 'um termo por instância, IN (...) com as chaves dela');

        $pdo->reset();
        $pdo->begins = 0;
        $again = $runner->sendMany($events);
        $this->assertEquals([1, 1], [$pdo->begins, $lookups($pdo)], 'repetição em um chunk de 100: uma transação, uma consulta');
        $this->assertEquals(10, count(array_filter($again, static fn(BatchEventOutcome $o): bool => $o->isAccepted())), 'os resultados originais');

        $pdo->reset();
        $pdo->begins = 0;
        foreach ($events as $event) {
            $runner->send($event->instanceId, $event->event);
        }
        $this->assertEquals([10, 10], [$pdo->begins, $lookups($pdo)], 'controle: send() abre uma transação e faz uma consulta por evento');

        $pdo->beginTransaction();
        $pdo->reset();
        $pdo->begins = 0;
        $runner->sendMany(array_map(static fn(string $id): BatchEvent => new BatchEvent($id, Event::external('TOGGLE', $at, [], 'other-' . $id)), $ids), chunkSize: 5);
        $pdo->commit();
        $this->assertEquals([0, 2], [$pdo->begins, $lookups($pdo)], 'dentro da transação do chamador: entra nela, uma consulta por chunk');

        $store = new PdoIdempotencyStore(new PdoMachineSchema($pdo));
        $many = [];
        foreach ($ids as $id) {
            $many[$id] = ['key-' . $id, 'other-' . $id, 'absent', 'key-' . $id];
        }
        $found = $store->findMany($many + ['q-404' => ['key-q-0']]);
        $foundIds = array_keys($found);
        sort($foundIds);
        $sortedIds = $ids;
        sort($sortedIds);
        $this->assertEquals($sortedIds, $foundIds, 'findMany(): só o que existe, por instância');
        foreach ($ids as $id) {
            $keys = array_keys($found[$id]);
            sort($keys);
            $this->assertEquals(['key-' . $id, 'other-' . $id], $keys, "findMany(): {$id}");
            $this->assertEquals($store->find($id, 'key-' . $id)?->toArray(), $found[$id]['key-' . $id]->toArray(), "findMany() = find(): {$id}");
        }
        $big = [];
        for ($i = 0; $i < 450; $i++) {
            $big['q-' . ($i % 10)][] = 'bulk-' . $i;
        }
        $pdo->reset();
        $this->assertEquals([], $store->findMany($big), 'findMany() de 450 pares ausentes');
        $this->assertEquals(3, count($pdo->statements), 'em grupos de ' . PdoIdempotencyStore::BATCH_PARAMETERS . ' pares');
        $this->assertEquals([], $store->findMany([]), 'nada pedido, nada consultado');
    }

    // ==========================================
    // PDO legs
    // ==========================================

    private function pdoLeg(string $family, Closure $connect): void
    {
        $pdo = $connect();
        $this->resetSchema($pdo, self::DDL);
        $this->testStartAt("PDO/{$family}", fn(): MachinePersistence => MachinePersistence::pdo($pdo, columns: ['tenant' => 'tenant']));
        $this->resetSchema($pdo, self::DDL);
        $this->testSendManyBasics("PDO/{$family}", fn(): MachinePersistence => MachinePersistence::pdo($pdo, columns: ['tenant' => 'tenant']));
        $this->testEventSnapshotColumn($family, $pdo);
        $this->testDdl29AndMigration($family, $pdo);
        $this->testWideColumns($family, $pdo);
        $this->testSendManyEqualsSendProperty("PDO/{$family}", function () use ($pdo): MachinePersistence {
            $this->resetSchema($pdo, self::DDL);

            return MachinePersistence::pdo($pdo, columns: PdoMachineSchema::COLUMNS_SINCE_2_10, withRejectionLog: true);
        }, $family === 'sqlite' ? self::PROPERTY_SEQUENCES : 25);
        $this->resetSchema($pdo, self::DDL);
        $this->testNumericInstanceIds("PDO/{$family}", static fn(): MachinePersistence => MachinePersistence::pdo($pdo));
    }

    private function testEventSnapshotColumn(string $family, PDO $pdo): void
    {
        foreach ([true, false] as $mapped) {
            $this->resetSchema($pdo, self::DDL);
            $persistence = MachinePersistence::pdo($pdo, columns: $mapped ? ['tenant' => 'tenant', ...PdoMachineSchema::COLUMNS_SINCE_2_10] : []);
            $seen = [];
            $handlers = (new EffectHandlerRegistry())
                ->register('notify_analyst', static function (Effect $effect, string $key, string $instanceId, EffectContext $context) use (&$seen): void {
                    $seen[] = [$context->hasEvent(), $context->eventType(), $context->payload(), $context->actor(), $context->occurredAt()?->format(MachineSnapshot::DATE_FORMAT), $context->event?->idempotencyKey];
                })
                ->register('mail_cancellation', static function (): void {
                });
            $runner = new MachineRunner(Spec::stateMachine(), new InMemoryMachineDefinitionRepository([Module45Answers::claimMachine()]), $persistence, $handlers);
            $at = new DateTimeImmutable('2026-10-21T09:00:00.250000-03:00');
            $runner->start('e-1', 'claim', 1, null, $at, tenant: $mapped ? '7' : null);
            $runner->send('e-1', Event::external('START_ANALYSIS', $at, ['note' => 'Observação ✓', 'n' => 2.5], 'k-e'), null, 'ana');
            $stored = $pdo->query("SELECT event_snapshot FROM state_machine_outbox WHERE instance_id = 'e-1'")->fetchColumn();
            $entry = $persistence->outbox->findByInstance('e-1')[0];
            (new EffectDispatcher($persistence->outbox, $handlers))->dispatchPending();
            if ($mapped) {
                $this->assertTrue(is_string($stored) && str_contains($stored, 'START_ANALYSIS'), "{$family}: mapeada, a coluna guarda o evento");
                $this->assertEquals(['type' => 'START_ANALYSIS', 'payload' => ['note' => 'Observação ✓', 'n' => 2.5], 'actor' => 'ana', 'origin' => 'external', 'occurred_at' => '2026-10-21T09:00:00.250000-03:00', 'idempotency_key' => 'k-e'], $entry->event?->toArray(), "{$family}: ida e volta do evento");
                $this->assertEquals([[true, 'START_ANALYSIS', ['note' => 'Observação ✓', 'n' => 2.5], 'ana', '2026-10-21T09:00:00.250000-03:00', 'k-e']], $seen, "{$family}: o handler recebe o evento do banco");
                $this->assertEquals('delivered', $pdo->query("SELECT status FROM state_machine_outbox WHERE instance_id = 'e-1'")->fetchColumn(), "{$family}: entregue");
                $this->assertTrue($persistence->outbox->findByInstance('e-1')[0]->event?->toArray() === $entry->event->toArray(), "{$family}: a marca de entrega não toca o evento");
                $pdo->exec("UPDATE state_machine_outbox SET event_snapshot = '{\"type\": 1}' WHERE instance_id = 'e-1'");
                $malformed = $this->assertThrows(MachinePersistenceException::class, fn() => $persistence->outbox->findByInstance('e-1'), "{$family}: evento gravado malformado é erro de persistência");
                $this->assertTrue(str_starts_with($malformed->getMessage(), 'Stored event snapshot is malformed: An event snapshot needs'), "{$family}: a mensagem diz o quê ({$malformed->getMessage()})");
                $this->assertTrue($malformed->getCode() === 0 && $malformed->getPrevious() instanceof InvalidArgumentException, "{$family}: código 0 e a causa");
                $pdo->exec("UPDATE state_machine_outbox SET event_snapshot = '' WHERE instance_id = 'e-1'");
                $this->assertTrue($persistence->outbox->findByInstance('e-1')[0]->event === null, "{$family}: texto vazio = sem evento");
            } else {
                $this->assertTrue($stored === null, "{$family}: sem mapeamento a coluna nunca é escrita");
                $this->assertTrue($entry->event === null, "{$family}: e a entrada volta sem evento");
                $this->assertEquals([[false, null, [], null, null, null]], $seen, "{$family}: o handler vê hasEvent() falso");
            }
        }
        $this->assertThrows(InvalidArgumentException::class, fn() => MachinePersistence::pdo($pdo, columns: ['event_snapshot' => 'bad name']), "{$family}: nome real inválido recusado");
        $schema = new PdoMachineSchema($pdo);
        $this->assertFalse($schema->hasColumn('event_snapshot'), "{$family}: opt-in");
        $this->assertTrue((new PdoMachineSchema($pdo, columns: ['event_snapshot' => 'evt']))->hasColumn('event_snapshot') && (new PdoMachineSchema($pdo, columns: ['event_snapshot' => 'evt']))->column('event_snapshot') === 'evt', "{$family}: pode ter outro nome");
    }

    private function testDdl29AndMigration(string $family, PDO $pdo): void
    {
        $this->resetSchema($pdo, Module45Answers::DDL_2_9);
        $persistence = MachinePersistence::pdo($pdo);
        [$runner] = $this->propertyRunner($persistence);
        $at = new DateTimeImmutable('2026-10-21T09:00:00Z');
        $runner->start('d-1', 'prop', 1, null, $at);
        $runner->startAt('d-2', 'prop', 1, ['main' => 'b'], null, $at, 'import');
        $outcomes = $runner->sendMany([new BatchEvent('d-1', Event::external('GO', $at, [], 'k')), new BatchEvent('d-2', Event::external('GO', $at))]);
        $this->assertTrue($outcomes[0]->isAccepted() && $outcomes[1]->isAccepted(), "{$family} DDL 2.9.0 sem mapeamento: startAt(), sendMany() e efeitos rodam");
        $this->assertTrue((new EffectDispatcher($persistence->outbox, $this->propertyHandlers()))->dispatchPending()->deliveredCount() === 2, "{$family} DDL 2.9.0: efeitos entregues sem a coluna");

        [$mappedRunner] = $this->propertyRunner(MachinePersistence::pdo($pdo, columns: PdoMachineSchema::COLUMNS_SINCE_2_10));
        $e = $this->assertThrows(MachinePersistenceException::class, fn() => $mappedRunner->send('d-1', Event::external('GO', $at)), "{$family} DDL 2.9.0: mapear a coluna antes da migration falha no banco");
        $this->assertTrue(str_contains(strtolower($e->getMessage()), 'event_snapshot'), "{$family}: a mensagem do banco nomeia a coluna");
        $this->assertEquals(2, $runner->load('d-1')->version, "{$family}: e nada é gravado");

        $statements = self::commentedStatements('-- Additive migration 2.9 -> 2.10.0', '-- Wide JSON columns');
        $this->assertEquals(['ALTER TABLE state_machine_outbox ADD COLUMN event_snapshot TEXT'], $statements, 'a migration aditiva comentada no DDL: um ADD COLUMN');
        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
        $this->assertTrue($mappedRunner->send('d-1', Event::external('GO', $at, ['x' => 1]), null, 'ana')->isAccepted(), "{$family}: depois da migration, mapeada");
        $last = $mappedRunner->getPersistence()->outbox->findByInstance('d-1');
        $this->assertEquals(['GO', ['x' => 1], 'ana'], [end($last)->event?->type, end($last)->event?->payload, end($last)->event?->actor], "{$family}: o evento gravado na tabela migrada");
        $this->assertTrue($last[0]->event === null, "{$family}: as entradas de antes da migration ficam sem evento");
    }

    private function testWideColumns(string $family, PDO $pdo): void
    {
        $text = str_repeat('ç', 3000) . str_repeat('<p>observação</p>', 400);
        $this->assertTrue(mb_strlen($text) > 4000);
        foreach (['narrow' => [], 'wide' => self::wideStatements($family)] as $variant => $statements) {
            $this->resetSchema($pdo, self::DDL);
            foreach ($statements as $statement) {
                $pdo->exec($statement);
            }
            $persistence = MachinePersistence::pdo($pdo);
            $runner = new MachineRunner(Spec::stateMachine(), new InMemoryMachineDefinitionRepository([Module45Answers::claimMachine()]), $persistence);
            $at = new DateTimeImmutable('2026-10-21T09:00:00Z');
            $runner->start('w-1', 'claim', 1, null, $at);
            $send = fn(): TransitionResult => $runner->send('w-1', Event::external('START_ANALYSIS', $at, ['observation' => $text], 'k-wide'));
            if ($variant === 'narrow' && $family !== 'sqlite') {
                $e = $this->assertThrows(MachinePersistenceException::class, $send, "{$family} VARCHAR(4000): o banco recusa (a lib não trunca)");
                \assert($e instanceof MachinePersistenceException);
                $this->assertEquals('22001', $e->getSqlState(), "{$family}: SQLSTATE 22001");
                $this->assertEquals(1, $runner->load('w-1')->version, "{$family}: o passo inteiro desfeito");
                $this->assertCount(2, $persistence->history->findByInstance('w-1'), "{$family}: nenhum histórico do passo (só as duas linhas do start)");
                continue;
            }
            $this->assertTrue($send()->isAccepted(), "{$family} {$variant}: gravado");
            $this->assertEquals($text, $persistence->history->findByInstance('w-1')[2]->payload['observation'] ?? null, "{$family} {$variant}: o payload volta inteiro do histórico");
            $this->assertEquals($text, $persistence->idempotency->find('w-1', 'k-wide')?->event?->payload['observation'], "{$family} {$variant}: e do resultado da idempotência");
            $this->assertEquals($text, $send()->event?->payload['observation'], "{$family} {$variant}: a repetição devolve o original inteiro");
        }
        $this->assertEquals($family === 'sqlite' ? 0 : 2, count(self::wideStatements($family)), "{$family}: as variantes largas do comentário do DDL");
    }

    // ==========================================
    // Documentation (example 24)
    // ==========================================

    private function testDocumentationBlocksAreTheSameInBothLanguages(): void
    {
        $root = dirname(__DIR__, 2);
        $english = ReadmeBlocks::of($root . '/docs/en/' . self::README_PAGE . '.md', 24);
        $portuguese = ReadmeBlocks::of($root . '/docs/pt-BR/' . self::README_PAGE . '.md', 24);
        $this->assertCount(4, $english, 'quatro blocos PHP no exemplo 24 (EN)');
        $this->assertCount(4, $portuguese, 'quatro blocos PHP no exemplo 24 (pt-BR)');
        foreach ($english as $i => $block) {
            $this->assertEquals(ReadmeBlocks::codeTokens($block), ReadmeBlocks::codeTokens($portuguese[$i] ?? ''), "exemplo 24: bloco {$i} igual fora os comentários");
        }
        foreach (['en', 'pt-BR'] as $language) {
            $text = (string) file_get_contents($root . "/docs/{$language}/" . self::README_PAGE . '.md');
            foreach (['forward', 'Reversa', 'RN-0', 'docs/contratos', 'Virttus'] as $internal) {
                $this->assertFalse(stripos($text, $internal) !== false, "docs/{$language}: nada interno ({$internal})");
            }
            foreach (self::wideStatements('pgsql') as $statement) {
                $this->assertTrue(str_contains($text, $statement . ';'), "docs/{$language}: a variante larga do PostgreSQL é a do DDL");
            }
            foreach (self::wideStatements('mysql') as $statement) {
                $this->assertTrue(str_contains($text, $statement . ';'), "docs/{$language}: a do MySQL/MariaDB também");
            }
        }
    }

    private function testReadme24(): void
    {
        $pdo = $this->sqlite();

        // README 24.1 >>>
        $claim = Spec::machine('claim', 1)
            ->region('progress', 'triage')
                ->state('triage')
                    ->on('START_ANALYSIS', 'analysis')->action('notify_analyst')
                ->state('analysis')->deadline('P5D', 'ANALYSIS_OVERDUE')
                    ->on('APPROVE', 'approved')
                    ->on('CANCEL', 'cancelled')->guard('payloadHas', ['field' => 'motive'])->action('mail_cancellation')
                    ->on('ANALYSIS_OVERDUE', 'late')
                ->state('late')
                    ->on('APPROVE', 'approved')
                ->finalState('approved')
                ->finalState('cancelled')
            ->region('pending', 'none')
                ->state('none')->on('REGISTER_PENDING', 'open')
                ->state('open')->on('RESOLVE_PENDING', 'none')
            ->build();

        $persistence = MachinePersistence::inMemory();
        $runner = new MachineRunner(Spec::stateMachine(), new InMemoryMachineDefinitionRepository([$claim]), $persistence);
        $at = new DateTimeImmutable('2026-10-21T09:00:00-03:00');

        $runner->startAt('claim-4711', 'claim', null, ['progress' => 'analysis', 'pending' => 'open'], null, $at, actor: 'import', payload: ['legacy_id' => 4711]);
        $instance = $runner->load('claim-4711');
        $r1 = $instance->snapshot->getStates();
        $r2 = $instance->snapshot->dueAt('progress')->format('Y-m-d');
        $r3 = $persistence->history->findByInstance('claim-4711')[0]->eventType;
        $r4 = count($persistence->outbox->findByInstance('claim-4711'));

        $runner->startAt('claim-4712', 'claim', null, ['progress' => 'approved'], null, $at, allowFinal: true);
        $r5 = $runner->load('claim-4712')->snapshot->isDone();
        // <<< README 24.1

        $this->assertEquals(['progress' => 'analysis', 'pending' => 'open'], $r1, 'README 24.1: os estados');
        $this->assertEquals('2026-10-26', $r2, 'README 24.1: o prazo a partir da importação');
        $this->assertEquals('machine.import', $r3, 'README 24.1: a linha de histórico');
        $this->assertEquals(0, $r4, 'README 24.1: nenhum efeito');
        $this->assertTrue($r5, 'README 24.1: final com allowFinal');
        $this->assertEquals(Module45Answers::claimMachine()->fingerprint(), $claim->fingerprint(), 'README 24: a máquina da página é a das baterias');
        $this->assertTranscribed(0, 'README 24.1');

        // README 24.2 >>>
        $mails = [];
        $handlers = (new EffectHandlerRegistry())
            ->register('mail_cancellation', function (Effect $effect, string $key, string $instanceId, EffectContext $context) use (&$mails): void {
                $mails[] = sprintf('%s cancelled by %s: %s', $instanceId, $context->actor(), $context->payloadValue('motive'));
            })
            ->register('notify_analyst', function (Effect $effect, string $key, string $instanceId): void {
            });

        $runner = new MachineRunner(Spec::stateMachine(), new InMemoryMachineDefinitionRepository([$claim]), $persistence, $handlers);
        $r1 = $runner->send('claim-4711', Event::external('CANCEL', $at->modify('+1 hour'), ['motive' => 'Duplicate claim']), actor: 'ana')->isAccepted();
        $r2 = (new EffectDispatcher($persistence->outbox, $handlers))->dispatchPending()->deliveredCount();
        $r3 = $mails;

        $pdoPersistence = MachinePersistence::pdo($pdo, columns: PdoMachineSchema::COLUMNS_SINCE_2_10);
        // <<< README 24.2

        $this->assertTrue($r1, 'README 24.2: aceito');
        $this->assertEquals(1, $r2, 'README 24.2: um efeito entregue');
        $this->assertEquals(['claim-4711 cancelled by ana: Duplicate claim'], $r3, 'README 24.2: o e-mail com o motivo e o ator');
        $this->assertTrue($pdoPersistence->outbox instanceof \Antevemus\ASpecification\StateMachine\Persistence\Pdo\PdoEffectOutbox, 'README 24.2: a persistência PDO com a coluna');
        $this->assertTranscribed(1, 'README 24.2');

        // README 24.3 >>>
        $guards = Spec::guardRegistry()
            ->register('hasAnyRole', fn(array $p) => (new PredicateSpecification(
                fn(TransitionCandidate $c): bool => array_intersect($c->context['roles'] ?? [], $p['roles']) !== []
            ))->because('Your role cannot do this.')->withCode('ROLE_NOT_ALLOWED'))
            ->register('amountUpTo', fn(array $p) => Spec::property('context.amount', Spec::lessThanOrEqualTo($p['max']))
                ->because('The amount is above your approval limit.')->withCode('ABOVE_APPROVAL_LIMIT'))
            ->markRoleGuards('hasAnyRole');

        $approval = Spec::machine('approval', 1)
            ->region('flow', 'awaiting_approval')
                ->state('awaiting_approval')
                    ->on('APPROVE', 'approved', 'approve_analyst')
                        ->guard(G::allOf(G::ref('hasAnyRole', ['roles' => ['analyst']]), G::ref('amountUpTo', ['max' => 10000])))
                    ->on('APPROVE', 'approved', 'approve_manager')
                        ->guard(G::allOf(G::ref('hasAnyRole', ['roles' => ['manager']]), G::ref('amountUpTo', ['max' => 50000])))
                    ->on('APPROVE', 'approved', 'approve_board')->guard('hasAnyRole', ['roles' => ['board']])
                    ->on('REJECT', 'rejected')
                        ->guard(G::allOf(G::ref('hasAnyRole', ['roles' => ['analyst', 'manager']]), G::ref('payloadHas', ['field' => 'motive'])))
                ->finalState('approved')
                ->finalState('rejected')
            ->build();

        $machine = Spec::stateMachine($guards);
        $start = $machine->initialSnapshot($approval, null, $at)->snapshot;

        $result = $machine->can($approval, $start, Event::external('APPROVE', $at), ['roles' => ['manager'], 'amount' => 90000]);
        $r1 = $result->getReason()->getCodes();
        $r2 = $result->getPrimaryReason()->getCodes();
        $r3 = count($result->getDetails());

        $panel = $machine->nextEvents($approval, $start, ['roles' => ['analyst'], 'amount' => 8000], $at);
        $r4 = $panel['APPROVE']->getCandidateReason();
        $r5 = $panel['REJECT']->getCandidateReason();
        $r6 = $panel['REJECT']->getMissingPayload();
        $r7 = $panel['REJECT']->isRejected();

        $board = $machine->nextEvents($approval, $start, ['roles' => ['board'], 'amount' => 8000], $at);
        $r8 = $board['REJECT']->getCandidateReason();
        // <<< README 24.3

        $this->assertEquals(['ROLE_NOT_ALLOWED', 'ABOVE_APPROVAL_LIMIT', 'ABOVE_APPROVAL_LIMIT', 'ROLE_NOT_ALLOWED'], $r1, 'README 24.3: todos os códigos');
        $this->assertEquals(['ABOVE_APPROVAL_LIMIT'], $r2, 'README 24.3: o principal');
        $this->assertEquals(2, $r3, 'README 24.3: os detalhes');
        $this->assertTrue($r4 === CandidateReason::ALLOWED && $r5 === CandidateReason::MISSING_PAYLOAD && $r8 === CandidateReason::ROLE_REFUSED, 'README 24.3: as classificações');
        $this->assertEquals(['motive'], $r6, 'README 24.3: o campo que falta');
        $this->assertTrue($r7, 'README 24.3: o status de can()');
        $this->assertTranscribed(2, 'README 24.3');

        // README 24.4 >>>
        $runner = new MachineRunner(Spec::stateMachine(), new InMemoryMachineDefinitionRepository([$claim]), MachinePersistence::pdo($pdo));
        $rows = [['id' => 'claim-1'], ['id' => 'claim-2'], ['id' => 'claim-3']];
        foreach ($rows as $row) {
            $runner->startAt($row['id'], 'claim', null, ['progress' => 'triage'], null, $at, actor: 'import');
        }
        $events = array_map(
            fn(array $row): BatchEvent => new BatchEvent($row['id'], Event::external('START_ANALYSIS', $at, [], 'file-7:' . $row['id'] . ':START_ANALYSIS')),
            $rows
        );
        $outcomes = $runner->sendMany($events, actor: 'import', chunkSize: 100);
        $r1 = count(array_filter($outcomes, fn($outcome) => $outcome->isAccepted()));
        $again = $runner->sendMany($events, actor: 'import');
        $r2 = $again[0]->result->getTransitions()[0]->to;
        $r3 = $runner->sendMany([new BatchEvent('claim-9', Event::external('START_ANALYSIS', $at))])[0]->errorMessage();
        // <<< README 24.4

        $this->assertEquals(3, $r1, 'README 24.4: três aceitos');
        $this->assertEquals('analysis', $r2, 'README 24.4: o resultado original');
        $this->assertEquals('Machine instance "claim-9" does not exist.', $r3, 'README 24.4: a instância desconhecida');
        $this->assertEquals('3', (string) $pdo->query("SELECT COUNT(*) FROM state_machine_idempotency")->fetchColumn(), 'README 24.4: três chaves, gravadas uma vez');
        $this->assertTranscribed(3, 'README 24.4');
    }

    private function assertTranscribed(int $index, string $name): void
    {
        $source = (string) file_get_contents(__FILE__);
        $start = strpos($source, "// {$name} >>>\n");
        $end = strpos($source, "// <<< {$name}\n");
        $this->assertTrue($start !== false && $end !== false && $end > $start, "transcrição {$name} marcada");
        $tokens = ReadmeBlocks::codeTokens(substr($source, (int) $start, (int) $end - (int) $start));
        $transcribed = [];
        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            if (preg_match('/^\$r\d+$/', $tokens[$i]) === 1 && ($tokens[$i + 1] ?? null) === '=') {
                $i++;
                continue;
            }
            $transcribed[] = $tokens[$i];
        }
        $blocks = ReadmeBlocks::of(dirname(__DIR__, 2) . '/docs/en/' . self::README_PAGE . '.md', 24);
        $block = ReadmeBlocks::codeTokens($blocks[$index] ?? '');
        $found = false;
        $length = count($transcribed);
        for ($i = 0; $i + $length <= count($block) && !$found; $i++) {
            $found = array_slice($block, $i, $length) === $transcribed;
        }
        $this->assertTrue($length > 10 && $found, "{$name}: a transcrição é o código da página");
    }

    // ==========================================
    // Support
    // ==========================================

    /** The claim machine with entry actions (states analysis and triage), in two versions. */
    private function claimWithEntries(int $version): MachineDefinition
    {
        return Spec::machine('claim', $version)
            ->region('progress', 'triage')
                ->state('triage')->entry('welcome')
                    ->on('START_ANALYSIS', 'analysis')->action('notify_analyst')
                ->state('analysis')->entry('welcome')->deadline('P5D', 'ANALYSIS_OVERDUE')
                    ->on('APPROVE', 'approved')
                    ->on('ANALYSIS_OVERDUE', 'late')
                ->state('late')
                    ->on('APPROVE', 'approved')
                ->finalState('approved')
            ->region('pending', 'none')
                ->state('none')->on('REGISTER_PENDING', 'open')
                ->state('open')->on('RESOLVE_PENDING', 'none')
            ->build();
    }

    /** The machine of the property: a synchronous `check` that vetoes when the payload says so, an asynchronous `notify`. */
    private function propertyMachine(): MachineDefinition
    {
        return Spec::machine('prop', 1)
            ->region('main', 'a')
                ->state('a')
                    ->on('GO', 'b')->action('check')->action('notify')
                    ->on('SKIP', 'c')->guard('payloadHas', ['field' => 'motive'])->action('notify')
                ->state('b')
                    ->on('GO', 'c')->action('notify')
                    ->on('BACK', 'a')->action('check')
                ->state('c')
                    ->on('RESET', 'a')->action('check')
            ->region('flag', 'off')
                ->state('off')->on('TOGGLE', 'on')
                ->state('on')->on('TOGGLE', 'off')->action('notify')
            ->build();
    }

    private function propertyHandlers(): EffectHandlerRegistry
    {
        return (new EffectHandlerRegistry())
            ->registerSync('check', static function (Effect $effect, string $key, string $instanceId, EffectContext $context): void {
                if (($context->payload()['veto'] ?? false) === true) {
                    throw new RuntimeException('vetoed');
                }
            })
            ->register('notify', static function (): void {
            });
    }

    /**
     * @return array{0: MachineRunner, 1: MachinePersistence}
     */
    private function propertyRunner(MachinePersistence $persistence): array
    {
        return [new MachineRunner(Spec::stateMachine(), new InMemoryMachineDefinitionRepository([$this->propertyMachine()]), $persistence, $this->propertyHandlers()), $persistence];
    }

    private function startPropertyInstances(MachineRunner $runner): void
    {
        $at = new DateTimeImmutable('2026-10-21T09:00:00Z');
        foreach (['p-0', 'p-1', 'p-2', 'p-3'] as $id) {
            $runner->start($id, 'prop', 1, null, $at, 'starter');
        }
    }

    /**
     * @return list<BatchEvent>
     */
    private function randomEvents(Randomizer $random, int $count): array
    {
        $types = ['GO', 'SKIP', 'BACK', 'RESET', 'TOGGLE', 'NOPE'];
        $payloads = [[], ['motive' => 'm'], ['veto' => true], ['motive' => 'm', 'veto' => true], ['note' => 'Ação']];
        $ids = ['p-0', 'p-1', 'p-2', 'p-3', 'p-9'];
        $actors = [null, 'u1', 'u2'];
        $at = new DateTimeImmutable('2026-10-21T10:00:00Z');
        $events = [];
        for ($i = 0; $i < $count; $i++) {
            $key = $random->getInt(0, 1) === 1 ? 'k' . $random->getInt(0, 5) : null;
            $expected = $random->getInt(0, 6) === 0 ? $random->getInt(1, 6) : null;
            $events[] = new BatchEvent(
                $ids[$random->getInt(0, count($ids) - 1)],
                Event::external($types[$random->getInt(0, count($types) - 1)], $at->modify('+' . $i . ' minutes'), $payloads[$random->getInt(0, count($payloads) - 1)], $key),
                null,
                $actors[$random->getInt(0, 2)],
                $expected
            );
        }

        return $events;
    }

    /**
     * @return array{0: string, 1: mixed}
     */
    private static function describe(BatchEventOutcome|TransitionResult|Throwable $outcome): array
    {
        if ($outcome instanceof BatchEventOutcome) {
            $outcome = $outcome->result ?? $outcome->error;
            \assert($outcome !== null);
        }

        return $outcome instanceof TransitionResult
            ? ['result', $outcome->toArray()]
            : ['error', [get_class($outcome), $outcome->getMessage()]];
    }

    /**
     * Everything the stores answer for the instances and keys of the property.
     *
     * @return array<string, mixed>
     */
    private function dump(MachinePersistence $persistence): array
    {
        $out = [];
        foreach (['p-0', 'p-1', 'p-2', 'p-3', 'p-9'] as $id) {
            $out[$id] = [
                'instance' => $persistence->instances->load($id)?->toArray(),
                'history' => array_map(static fn(HistoryEntry $h): array => $h->toArray(), $persistence->history->findByInstance($id)),
                'outbox' => array_map(static fn(OutboxEntry $o): array => [$o->toArray(), $o->event?->toArray()], $persistence->outbox->findByInstance($id)),
                'idempotency' => array_map(static fn(int $k): ?array => $persistence->idempotency->find($id, 'k' . $k)?->toArray(), range(0, 5)),
                'rejections' => array_map(
                    static fn($r): array => array_diff_key($r->toArray(), ['rejection_id' => true]),
                    $persistence->rejections?->findByInstance($id) ?? []
                ),
            ];
        }

        return $out;
    }

    /**
     * The statements commented in the reference DDL between two markers.
     *
     * @return list<string>
     */
    private static function commentedStatements(string $from, string $to): array
    {
        $ddl = (string) file_get_contents(self::DDL);
        $start = (int) strpos($ddl, $from);
        $section = substr($ddl, $start, (int) strpos($ddl, $to, $start) - $start);
        $statements = [];
        foreach (explode("\n", $section) as $line) {
            if (preg_match('/^--\s+(ALTER TABLE .+);$/', $line, $match) === 1) {
                $statements[] = $match[1];
            }
        }

        return $statements;
    }

    /**
     * The wide variant of the JSON columns of a database, as the header of the reference DDL documents it.
     *
     * @return list<string>
     */
    private static function wideStatements(string $family): array
    {
        $label = match ($family) {
            'pgsql' => 'PostgreSQL',
            'mysql', 'mariadb' => 'MySQL, MariaDB',
            default => 'SQLite',
        };
        $ddl = (string) file_get_contents(self::DDL);
        $start = (int) strpos($ddl, '-- Per database:');
        $section = substr($ddl, $start, (int) strpos($ddl, '-- Upgrading a database created by the 1.8 script', $start) - $start);
        $current = null;
        $statements = [];
        foreach (explode("\n", $section) as $line) {
            if (preg_match('/^--\s{3}(\S.*?)\s{2,}(.*)$/', $line, $match) === 1) {
                $current = $match[1];
                $line = '-- ' . $match[2];
            }
            if ($current === $label && preg_match('/^--\s+(ALTER TABLE .+);$/', $line, $match) === 1) {
                $statements[] = $match[1];
            }
        }

        return $statements;
    }

    private function sqlite(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        Module45Answers::loadDdl($pdo, (string) file_get_contents(self::DDL));

        return $pdo;
    }

    private function resetSchema(PDO $pdo, string $ddl): void
    {
        $this->dropSchema($pdo);
        Module45Answers::loadDdl($pdo, (string) file_get_contents($ddl));
    }

    private function dropSchema(PDO $pdo): void
    {
        foreach (self::TABLES as $table) {
            $pdo->exec('DROP TABLE IF EXISTS ' . $table);
        }
    }

    private function connectFromEnvironment(string $family): ?PDO
    {
        $prefix = 'ASPEC_PDO_' . strtoupper($family);
        $dsn = getenv($prefix . '_DSN');
        if ($dsn === false || $dsn === '') {
            return null;
        }
        $user = getenv($prefix . '_USER');
        $password = getenv($prefix . '_PASSWORD');

        return new PDO($dsn, $user === false ? null : $user, $password === false ? null : $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }
}
