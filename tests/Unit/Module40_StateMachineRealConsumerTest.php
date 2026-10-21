<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Serialization\Exceptions\NonSerializableSpecificationException;
use Antevemus\ASpecification\Serialization\Exceptions\SpecificationCodecException;
use Antevemus\ASpecification\Serialization\SpecificationCodec;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\PredicateSpecification;
use Antevemus\ASpecification\StateMachine\DefinitionSupport;
use Antevemus\ASpecification\StateMachine\Calendar\BusinessDayCalendar;
use Antevemus\ASpecification\StateMachine\Calendar\ContinuousCalendar;
use Antevemus\ASpecification\StateMachine\Calendar\DeadlineCalendarRegistry;
use Antevemus\ASpecification\StateMachine\Calendar\HolidaySet;
use Antevemus\ASpecification\StateMachine\Event;
use Antevemus\ASpecification\StateMachine\Exceptions\ConcurrencyConflictException;
use Antevemus\ASpecification\StateMachine\Exceptions\DefinitionDraftConflictException;
use Antevemus\ASpecification\StateMachine\Exceptions\InvalidMachineDefinitionException;
use Antevemus\ASpecification\StateMachine\Exceptions\MachineInstanceAlreadyExistsException;
use Antevemus\ASpecification\StateMachine\Exceptions\MachineInstanceNotFoundException;
use Antevemus\ASpecification\StateMachine\Exceptions\MachineMigrationException;
use Antevemus\ASpecification\StateMachine\Exceptions\MachinePersistenceException;
use Antevemus\ASpecification\StateMachine\Exceptions\StateMachineException;
use Antevemus\ASpecification\StateMachine\Exceptions\UnknownMachineDefinitionException;
use Antevemus\ASpecification\StateMachine\GuardExpression;
use Antevemus\ASpecification\StateMachine\GuardRegistry;
use Antevemus\ASpecification\StateMachine\MachineDefinition;
use Antevemus\ASpecification\StateMachine\MachineDefinitionError;
use Antevemus\ASpecification\StateMachine\MachineDefinitionDiff;
use Antevemus\ASpecification\StateMachine\MachineSnapshot;
use Antevemus\ASpecification\StateMachine\Persistence\DueDeadline;
use Antevemus\ASpecification\StateMachine\Persistence\IMachineDefinitionDrafts;
use Antevemus\ASpecification\StateMachine\Persistence\IMachineDefinitionRepository;
use Antevemus\ASpecification\StateMachine\Persistence\IMachineInstanceLister;
use Antevemus\ASpecification\StateMachine\Persistence\IMachineInstanceStore;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\IInMemoryTransactional;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\InMemoryEffectOutbox;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\InMemoryIdempotencyStore;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\InMemoryMachineDefinitionRepository;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\InMemoryMachineHistoryStore;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\InMemoryMachineInstanceStore;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\InMemoryTransactionManager;
use Antevemus\ASpecification\StateMachine\Persistence\ITenantScopedInstanceStore;
use Antevemus\ASpecification\StateMachine\Persistence\ITransactionManager;
use Antevemus\ASpecification\StateMachine\Persistence\Pdo\PdoMachineInstanceStore;
use Antevemus\ASpecification\StateMachine\Persistence\Pdo\PdoTransactionManager;
use Antevemus\ASpecification\StateMachine\Persistence\MachineInstance;
use Antevemus\ASpecification\StateMachine\Persistence\MachinePersistence;
use Antevemus\ASpecification\StateMachine\Persistence\Pdo\PdoMachineDefinitionRepository;
use Antevemus\ASpecification\StateMachine\Persistence\Pdo\PdoMachineSchema;
use Antevemus\ASpecification\StateMachine\Persistence\PersistenceSupport;
use Antevemus\ASpecification\StateMachine\Runner\ByStateNameMigration;
use Antevemus\ASpecification\StateMachine\Runner\MachineMigrations;
use Antevemus\ASpecification\StateMachine\Runner\MachineRunner;
use Antevemus\ASpecification\StateMachine\StateDefinition;
use Antevemus\ASpecification\Tests\Support\PdoRecordingPdo;
use Antevemus\ASpecification\Tests\Support\ReadmeBlocks;
use Antevemus\ASpecification\Tests\TestCase;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Module40LeakyTenantStore - negative control of the tenant isolation property: an instance store that
 * says it is tenant-scoped and ignores the tenant.
 *
 * @version 2.7.0
 */
final class Module40LeakyTenantStore implements IMachineInstanceStore, ITenantScopedInstanceStore
{
    public function __construct(private readonly InMemoryMachineInstanceStore $inner)
    {
    }

    public function load(string $instanceId): ?MachineInstance
    {
        return $this->inner->load($instanceId);
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

    public function loadForTenant(string $instanceId, string $tenant): ?MachineInstance
    {
        return $this->inner->load($instanceId);
    }

    public function findDueForTenant(DateTimeImmutable $now, string $tenant, ?string $machineKey = null, int $limit = 100): array
    {
        return $this->inner->findDue($now, $machineKey, $limit);
    }
}

/**
 * Module40PlainStore - an instance store of the application written before 2.7.0: neither tenant-scoped
 * nor able to list instances (the runner must refuse what needs them with a clear message).
 *
 * @version 2.7.0
 */
final class Module40PlainStore implements IMachineInstanceStore, IInMemoryTransactional
{
    public function __construct(private readonly InMemoryMachineInstanceStore $inner)
    {
    }

    public function load(string $instanceId): ?MachineInstance
    {
        return $this->inner->load($instanceId);
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
 * Module40ConflictingStore - lists instances and loses the compare-and-set of one chosen instance, as if
 * another process had moved it in between (migrateAll() must skip it and go on).
 *
 * @version 2.7.0
 */
final class Module40ConflictingStore implements IMachineInstanceStore, IMachineInstanceLister, IInMemoryTransactional
{
    // (the chosen instance is also "gone" when $missing names it: listed, then not found)
    public ?string $conflictOn = null;

    public ?string $missing = null;
    public function __construct(private readonly InMemoryMachineInstanceStore $inner)
    {
    }

    public function load(string $instanceId): ?MachineInstance
    {
        return $instanceId === $this->missing ? null : $this->inner->load($instanceId);
    }

    public function insert(MachineInstance $instance): void
    {
        $this->inner->insert($instance);
    }

    public function compareAndSet(MachineInstance $instance, int $expectedVersion): void
    {
        if ($instance->instanceId === $this->conflictOn) {
            throw new ConcurrencyConflictException($instance->instanceId, $expectedVersion, $expectedVersion + 1);
        }
        $this->inner->compareAndSet($instance, $expectedVersion);
    }

    public function findDue(DateTimeImmutable $now, ?string $machineKey = null, int $limit = 100): array
    {
        return $this->inner->findDue($now, $machineKey, $limit);
    }

    public function idsByMachineVersion(string $machineKey, int $version, int $limit = 100, ?string $after = null): array
    {
        return $this->inner->idsByMachineVersion($machineKey, $version, $limit, $after);
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
 * Module40CountingTransactions - a transaction manager that counts the units of work it runs.
 *
 * @version 2.7.0
 */
final class Module40CountingTransactions implements ITransactionManager
{
    public int $count = 0;

    public function __construct(private readonly ITransactionManager $inner)
    {
    }

    public function transactional(Closure $work): mixed
    {
        $this->count++;

        return $this->inner->transactional($work);
    }
}

/**
 * Module40TrustingRepository - negative control of the tampering test: reads the table of the definitions
 * as PdoMachineDefinitionRepository does, without checking the row against its fingerprint.
 *
 * @version 2.7.0
 */
final class Module40TrustingRepository implements IMachineDefinitionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function register(MachineDefinition $definition): void
    {
        throw new \LogicException('read only');
    }

    public function get(string $key, int $version): MachineDefinition
    {
        $statement = $this->pdo->prepare('SELECT definition FROM state_machine_definition WHERE machine_key = :k AND version = :v');
        $statement->execute([':k' => $key, ':v' => $version]);

        return MachineDefinition::fromJson((string) $statement->fetchColumn());
    }

    public function has(string $key, int $version): bool
    {
        return true;
    }

    public function latestVersion(string $key): ?int
    {
        return null;
    }
}

/**
 * Module40_StateMachineRealConsumerTest - Módulo 40: Máquina de Estados para um Consumidor Real
 * (códigos legados, definição versionada em PDO, tenant), v2.7.0.
 *
 * - L1: o código legado do estado (StateDefinition::$code, StateBuilder::code()), o índice
 *   (stateByCode/codeOf/codesOf/hasStateCodes) com `"20"` ≡ `20`, duplicata e forma malformada recusadas;
 *   fingerprint de definição antiga conferido por literal (o da fixture v1 e o de uma máquina do builder,
 *   medidos no `main` antes da mudança); propriedade de ida e volta dos códigos sobre máquinas geradas.
 * - L2: PdoMachineDefinitionRepository (memória do get(), fingerprint conferido na leitura, adulteração
 *   recusada, com controle negativo), IMachineDefinitionDrafts em memória e em PDO (o mesmo contrato:
 *   rascunho como array, relatório do validador, CAS de revisão, publicação com validador e versão dada
 *   pelo repositório), concorrência de duas conexões, MachineDefinition::diff(),
 *   MachineMigrations::byStateName(), IMachineInstanceLister + MachineRunner::migrateAll() e o guard
 *   `spec` (documento do codec avaliado sobre o contexto); ida e volta da definição PDO por propriedade
 *   (máquinas geradas + as fixtures reais).
 * - L3: tenant na instância e no prazo, ITenantScopedInstanceStore (InMemory e PDO), parâmetros no fim do
 *   runner, propriedade de isolamento InMemory = PDO (com controle negativo: um store que ignora o
 *   tenant), predicado de tenant no SQL dos três dialetos, colunas opt-in por
 *   PdoMachineSchema::COLUMNS_SINCE_2_7; banco criado pelo DDL da 2.6 sem mapeamento roda o fluxo do
 *   runner e a migration aditiva comentada no DDL o atualiza.
 * - Aceitação (metade L1–L3): a definição v2 da cotação (códigos legados 1..21, região `sla` em tempo
 *   corrido com o guard `spec`) publicada pelo repositório PDO, dois tenants, replay da amostra sintética
 *   com a forma do histórico real (projeção da lib = id legado após cada passo, prazo do SLA = oráculo
 *   independente, exclusões contadas); a amostra real, fora do git, roda com ASPEC_VIRTTUS_SAMPLE.
 * - O exemplo 23 da documentação (23.1 a 23.3), transcrito aqui e conferido token a token.
 *
 * Bancos: SQLite em toda execução; PostgreSQL 17, MySQL 8.4 e MariaDB 11.4 com ASPEC_PDO_{PGSQL,MYSQL,
 * MARIADB}_DSN (run_portability.sh, run_parity_suites.sh e o job "SQL parity" da CI).
 *
 * @version 2.7.0
 */
class Module40_StateMachineRealConsumerTest extends TestCase
{
    private const DDL = __DIR__ . '/../../resources/sql/state_machine.ansi.sql';

    private const DDL_2_6 = __DIR__ . '/../Fixtures/state_machine/state_machine.2.6.ansi.sql';

    private const V1 = __DIR__ . '/../Fixtures/state_machine/virttus_quote.v1.json';

    private const V2 = __DIR__ . '/../Fixtures/state_machine/virttus_quote.v2.json';

    private const SAMPLE = __DIR__ . '/../Fixtures/state_machine/virttus_quote.synthetic_history.json';

    private const QUOTE_KEY = 'virttus.rental_guarantee_quote';

    /** The page of docs/en and docs/pt-BR that hosts example 23. */
    private const README_PAGE = 'state-machine/real-consumer';

    /** Every table of the reference DDL, children first. */
    private const TABLES = [
        'state_machine_region', 'state_machine_history', 'state_machine_outbox', 'state_machine_idempotency',
        'state_machine_rejection', 'state_machine_instance', 'state_machine_definition', 'state_machine_definition_draft',
    ];

    /** Fingerprints measured on `main` (2.6.1) before 2.7.0: they must never change. */
    private const FINGERPRINT_V1 = '425491b681f0218a79421efbd0f0ede72161a150776a632e29ba15aa944c262d';

    private const FINGERPRINT_BUILDER = 'b8eb8cd39d1a83a6d0298bca36b3d501286b667e64f6ee9c641bde00c7e02986';

    /** @var list<string> */
    private array $tempFiles = [];

    public function run(): void
    {
        try {
            // L1
            $this->testOldFingerprintsAndShapesAreUnchanged();
            $this->testStateCodes();
            $this->testCodePropertiesOverGeneratedMachines();
            $this->testDiff();
            // L2
            $this->testSpecGuard();
            $this->runDraftContract('InMemory', static fn(): InMemoryMachineDefinitionRepository => new InMemoryMachineDefinitionRepository());
            $this->testByStateNameAndMigrateAll('InMemory', static fn(): MachinePersistence => MachinePersistence::inMemory());
            $this->testMigrateAllSkipsAndRefusals();
            $this->testByStateNameKeepsTheTargetOrderAndThePause();
            $this->testDiffDetails();
            $this->testConflictException();
            $this->testDefaultLimits('InMemory', static fn(IMachineDefinitionRepository $d): MachinePersistence => MachinePersistence::inMemory());
            // L3
            $this->testTenantOnInstanceAndDeadline();
            $this->testRunnerWithTenants('InMemory', static fn(IMachineDefinitionRepository $d): MachinePersistence => MachinePersistence::inMemory());
            $this->testTenantRequiresAScopedStore();
            $this->testIsolationNegativeControl();
            // Documentation (23.1, no database)
            $this->testReadme23_1Codes();
            $this->testReadmeBlocksAreTheSameInBothLanguages();

            if (!extension_loaded('pdo_sqlite')) {
                fwrite(STDOUT, "    [AVISO] pdo_sqlite ausente; o Módulo 40 roda só as baterias em memória.\n");
                return;
            }
            $file = $this->tempFile();
            $this->pdoLeg('sqlite', function () use ($file): PDO {
                $pdo = new PDO('sqlite:' . $file);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->setAttribute(PDO::ATTR_TIMEOUT, 2); // a lock held by the other connection fails fast instead of waiting 60 s
                $pdo->exec('PRAGMA foreign_keys = ON');

                return $pdo;
            });
            $this->testRepositoryMemoizesGet();
            $this->testRepositoryOptionsAndStatementErrors();
            $this->testPdoInstanceStoreDetails();
            $sqlite = $this->sqliteWith(self::DDL);
            $this->testDefaultLimits('PDO/sqlite', static fn(IMachineDefinitionRepository $d): MachinePersistence => MachinePersistence::pdo($sqlite, columns: PdoMachineSchema::COLUMNS_SINCE_2_7, definitions: $d));
            $this->testReadme23_2And23_3();

            foreach (['pgsql' => 'PostgreSQL', 'mysql' => 'MySQL', 'mariadb' => 'MariaDB'] as $family => $label) {
                if ($this->connectFromEnvironment($family) === null) {
                    fwrite(STDOUT, "    [AVISO] ASPEC_PDO_" . strtoupper($family) . "_DSN não definido; Módulo 40 em {$label} pulado.\n");
                    continue;
                }
                $this->pdoLeg($family, fn(): PDO => $this->connectFromEnvironment($family) ?? throw new \RuntimeException('connection lost'));
                $pdo = $this->connectFromEnvironment($family);
                \assert($pdo !== null);
                $this->dropSchema($pdo);
                fwrite(STDOUT, "    [046] {$label} " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . ": códigos, definição versionada, rascunhos, concorrência, tenant, DDL da 2.6 e aceitação OK\n");
            }

            $this->testRealSampleWhenGiven();
        } finally {
            foreach ($this->tempFiles as $file) {
                @unlink($file);
            }
        }
    }

    // ==========================================
    // L1: legacy state codes
    // ==========================================

    private function testOldFingerprintsAndShapesAreUnchanged(): void
    {
        $v1 = $this->v1();
        $this->assertEquals(self::FINGERPRINT_V1, $v1->fingerprint(), 'RN-01: fingerprint da fixture v1 igual ao da 2.6.1 (literal)');
        $this->assertEquals(self::FINGERPRINT_BUILDER, $this->builderMachine()->fingerprint(), 'RN-01: fingerprint de uma máquina do builder igual ao da 2.6.1 (literal)');
        $this->assertFalse($v1->hasStateCodes(), 'v1 não tem códigos');
        foreach ($v1->regions as $region) {
            foreach ($region->states as $state) {
                $this->assertFalse(array_key_exists('code', $state->toArray()), "{$region->name}.{$state->name}: sem chave code");
            }
        }

        $t0 = new DateTimeImmutable('2026-10-21T12:00:00Z');
        $snapshot = new MachineSnapshot('k', 1, ['r' => 's']);
        $instance = new MachineInstance('i-1', $snapshot, 1, $t0, $t0);
        $this->assertEquals(['instance_id', 'version', 'snapshot', 'created_at', 'updated_at'], array_keys($instance->toArray()), 'MachineInstance sem tenant: a forma da 2.6');
        $this->assertTrue($instance->tenant === null);
        $due = new DueDeadline('i-1', 'k', 'r', 's', $t0);
        $this->assertEquals(['instance_id', 'machine_key', 'region', 'state', 'due_at'], array_keys($due->toArray()), 'DueDeadline sem tenant: a forma da 2.6');
        foreach (['pgsql', 'mysql', 'sqlite'] as $dialect) {
            $this->assertEquals(
                Spec::toSql(DueDeadline::specification($t0, 'k'), $dialect)->getSql(),
                Spec::toSql(DueDeadline::specification($t0, 'k', null), $dialect)->getSql(),
                "{$dialect}: a busca sem tenant é a da 2.6"
            );
        }
        $this->assertEquals(['inState', 'payloadHas', 'eventOrigin'], (new GuardRegistry())->names(), 'o guard spec é opt-in: os nomes de um registro novo não mudam');
    }

    private function testStateCodes(): void
    {
        $machine = Spec::machine('codes', 1)
            ->region('a', 's1')
                ->state('s1')->code(1)->on('GO', 's2')
                ->state('s2')->code('X-2')->on('GO', 's3')
                ->finalState('s3')->code('30')
            ->region('b', 'n')
                ->state('n')->on('GO', 'm')
                ->state('m')->code(-4)
            ->build();

        $this->assertTrue($machine->hasStateCodes());
        $this->assertEquals('a', $machine->stateByCode(1)['region'] ?? null);
        $this->assertEquals('s1', $machine->stateByCode(1)['state']->name ?? null);
        $this->assertEquals('s1', $machine->stateByCode('1')['state']->name ?? null, '"1" ≡ 1');
        $this->assertEquals('s3', $machine->stateByCode(30)['state']->name ?? null, '30 ≡ "30" (declarado como texto)');
        $this->assertEquals('m', $machine->stateByCode('-4')['state']->name ?? null, '"-4" ≡ -4');
        $this->assertEquals('s2', $machine->stateByCode('X-2')['state']->name ?? null);
        $this->assertTrue($machine->stateByCode('x-2') === null, 'texto é exato');
        $this->assertTrue($machine->stateByCode('030') === null, '"030" não é 30');
        $this->assertTrue($machine->stateByCode(' 30') === null);
        $this->assertTrue($machine->stateByCode(2) === null);
        $this->assertTrue($machine->codeOf('a', 's3') === '30', 'codeOf devolve como declarado');
        $this->assertTrue($machine->codeOf('a', 's1') === 1);
        $this->assertTrue($machine->codeOf('b', 'n') === null);
        $this->assertTrue($machine->codeOf('a', 'nope') === null && $machine->codeOf('zz', 's1') === null);
        $snapshot = new MachineSnapshot('codes', 1, ['a' => 's2', 'b' => 'n']);
        $this->assertEquals(['a' => 'X-2', 'b' => null], $machine->codesOf($snapshot));
        $this->assertEquals(['a' => null], $machine->codesOf(new MachineSnapshot('codes', 1, ['a' => 'gone'])), 'estado desconhecido: null');

        $array = $machine->toArray();
        $this->assertTrue($array['regions'][0]['states'][0]['code'] === 1);
        $this->assertTrue($array['regions'][0]['states'][2]['code'] === '30', 'o texto inteiro fica texto na forma de array');
        $this->assertFalse(array_key_exists('code', $array['regions'][1]['states'][0]));
        $again = MachineDefinition::fromJson($machine->toJson());
        $this->assertEquals($machine->fingerprint(), $again->fingerprint(), 'ida e volta JSON');
        $this->assertTrue($again->codeOf('a', 's3') === '30' && $again->codeOf('b', 'm') === -4, 'tipos preservados no JSON');
        $without = $array;
        unset($without['regions'][0]['states'][0]['code']);
        $this->assertFalse(MachineDefinition::fromArray($without)->fingerprint() === $machine->fingerprint(), 'o código é semântico: entra no fingerprint');

        // Duplicates: within a region, across regions and through the normalization
        foreach ([[5, 5, 'mesma região'], [5, '5', '5 e "5"'], [-7, '-7', '-7 e "-7"']] as [$first, $second, $what]) {
            $e = $this->assertThrows(InvalidMachineDefinitionException::class, fn() => Spec::machine('dup', 1)
                ->region('a', 'x')->state('x')->code($first)->state('y')->code($second)->build(), "duplicata ({$what})");
            $this->assertEquals([MachineDefinitionError::KIND_DUPLICATE], $e->getErrorKinds(), "duplicata ({$what}): KIND_DUPLICATE");
        }
        $e = $this->assertThrows(InvalidMachineDefinitionException::class, fn() => Spec::machine('dup', 1)
            ->region('a', 'x')->state('x')->code(20)->region('b', 'y')->state('y')->code('20')->build(), 'duplicata entre regiões');
        $this->assertTrue(str_contains($e->getMessage(), 'a.x') && str_contains($e->getMessage(), 'b.y'), 'a mensagem cita os dois estados');
        $this->assertTrue(Spec::machine('ok', 1)->region('a', 'x')->state('x')->code(5)->state('y')->code('05')->build()->stateByCode('05') !== null, '"05" e 5 são códigos distintos');

        // Malformed codes
        $e = $this->assertThrows(InvalidMachineDefinitionException::class, fn() => Spec::machine('bad', 1)->region('a', 'x')->state('x')->code(' 1'));
        $this->assertTrue(str_contains($e->getMessage(), 'state "x"') && str_contains($e->getMessage(), '(got " 1")'), 'a mensagem cita o estado e o código entre aspas');
        foreach (['', ' 1', '1 ', "\t"] as $bad) {
            $e = $this->assertThrows(InvalidMachineDefinitionException::class, fn() => Spec::machine('bad', 1)->region('a', 'x')->state('x')->code($bad), "código \"{$bad}\" recusado");
            $this->assertEquals([MachineDefinitionError::KIND_MALFORMED], $e->getErrorKinds());
            $this->assertThrows(InvalidMachineDefinitionException::class, fn() => new StateDefinition('x', code: $bad), "construtor: \"{$bad}\" recusado");
        }
        foreach ([1.5, true, false, [], ['a' => 1]] as $bad) {
            $e = $this->assertThrows(InvalidMachineDefinitionException::class, fn() => MachineDefinition::fromArray(['key' => 'bad', 'version' => 1, 'regions' => [
                ['name' => 'a', 'initial' => 'x', 'states' => [['name' => 'x', 'code' => $bad]]],
            ]]), 'fromArray: código ' . get_debug_type($bad) . ' recusado');
            $this->assertEquals([MachineDefinitionError::KIND_MALFORMED], $e->getErrorKinds());
        }
        $this->assertTrue(MachineDefinition::fromArray(['key' => 'n', 'version' => 1, 'regions' => [
            ['name' => 'a', 'initial' => 'x', 'states' => [['name' => 'x', 'code' => null]]],
        ]])->codeOf('a', 'x') === null, 'code null = sem código');

        // The normalization
        $this->assertTrue(DefinitionSupport::normalizeCode('20') === 20);
        $this->assertTrue(DefinitionSupport::normalizeCode('0') === 0);
        $this->assertTrue(DefinitionSupport::normalizeCode('-5') === -5);
        $this->assertTrue(DefinitionSupport::normalizeCode((string) PHP_INT_MAX) === PHP_INT_MAX);
        $this->assertTrue(DefinitionSupport::normalizeCode((string) PHP_INT_MIN) === PHP_INT_MIN);
        $this->assertTrue(DefinitionSupport::normalizeCode('9223372036854775808') === '9223372036854775808', 'acima do inteiro: fica texto');
        foreach (['-0', '+5', '05', '5.0', ' 5', '5 ', 'a20', '20a', 'abc', '1e3', ''] as $text) {
            $this->assertTrue(DefinitionSupport::normalizeCode($text) === $text, "\"{$text}\" não é texto inteiro canônico");
        }
        $this->assertTrue(DefinitionSupport::normalizeCode(7) === 7);
    }

    /**
     * RN-02 (códigos): para todo estado codificado de máquinas geradas, stateByCode(codeOf(r, s)) = (r, s),
     * pela forma declarada e pela outra forma do mesmo inteiro; ida e volta JSON com os mesmos tipos;
     * duplicata injetada recusada.
     */
    private function testCodePropertiesOverGeneratedMachines(): void
    {
        $coded = 0;
        $duplicates = 0;
        for ($seed = 1; $seed <= 200; $seed++) {
            $array = self::randomMachine($seed, "gen_{$seed}");
            $machine = MachineDefinition::fromArray($array);
            $again = MachineDefinition::fromJson($machine->toJson(false));
            $this->assertEquals($machine->fingerprint(), $again->fingerprint(), "seed {$seed}: ida e volta");
            $codes = [];
            foreach ($machine->regions as $region) {
                foreach ($region->states as $state) {
                    $code = $machine->codeOf($region->name, $state->name);
                    $this->assertTrue($code === $again->codeOf($region->name, $state->name), "seed {$seed}: tipo do código preservado");
                    if ($code === null) {
                        continue;
                    }
                    $coded++;
                    $codes[] = [$region->name, $state->name, $code];
                    $found = $machine->stateByCode($code);
                    $this->assertTrue($found !== null && $found['region'] === $region->name && $found['state']->name === $state->name, "seed {$seed}: stateByCode(codeOf)");
                    $normalized = DefinitionSupport::normalizeCode($code);
                    if (is_int($normalized)) {
                        $other = is_int($code) ? (string) $code : $normalized;
                        $this->assertTrue($machine->stateByCode($other)['state']->name === $state->name, "seed {$seed}: a outra forma do inteiro acha o estado");
                    }
                }
            }
            $this->assertEquals($codes !== [], $machine->hasStateCodes());
            if (count($codes) >= 2) {
                // Give the code of the first coded state (in its other form when it is an integer) to the second one
                [, , $code] = $codes[0];
                $normalized = DefinitionSupport::normalizeCode($code);
                $copy = is_int($normalized) && is_int($code) ? (string) $code : $code;
                [$r2, $s2] = $codes[1];
                foreach ($array['regions'] as &$region) {
                    if ($region['name'] !== $r2) {
                        continue;
                    }
                    foreach ($region['states'] as &$state) {
                        if ($state['name'] === $s2) {
                            $state['code'] = $copy;
                        }
                    }
                    unset($state);
                }
                unset($region);
                $e = $this->assertThrows(InvalidMachineDefinitionException::class, fn() => MachineDefinition::fromArray($array), "seed {$seed}: duplicata injetada");
                $this->assertEquals([MachineDefinitionError::KIND_DUPLICATE], $e->getErrorKinds());
                $duplicates++;
            }
        }
        $this->assertTrue($coded > 500 && $duplicates > 100, "propriedade com massa ({$coded} estados codificados, {$duplicates} duplicatas)");
        fwrite(STDOUT, "    [046 RN-02 L1] 200 máquinas geradas: {$coded} estados codificados acham-se pelo código, {$duplicates} duplicatas recusadas\n");
    }

    private function testDiff(): void
    {
        $v1 = $this->v1();
        $v2 = $this->v2();
        $diff = $v1->diff($v2);
        $this->assertEquals([self::QUOTE_KEY, 1, 2], [$diff->machineKey, $diff->fromVersion, $diff->toVersion]);
        $this->assertEquals(['sla'], $diff->addedRegions);
        $this->assertEquals(['sla.idle', 'sla.running', 'sla.met', 'sla.breached', 'sla.stopped'], $diff->addedStates);
        $this->assertCount(21, $diff->changedStates, 'os 21 estados que ganharam código');
        $this->assertTrue(!in_array('pending.none', $diff->changedStates, true), 'pending.none sem código: não muda');
        $this->assertEquals(['sla_start', 'sla_not_applicable', 'sla_met', 'sla_breached', 'sla_stopped'], $diff->addedTransitions);
        $this->assertEquals([], [...$diff->removedRegions, ...$diff->removedStates, ...$diff->removedTransitions, ...$diff->changedTransitions, ...$diff->changedRegions]);
        $this->assertFalse($diff->isEmpty());
        $this->assertTrue($v1->diff($this->v1())->isEmpty(), 'mesma definição: vazio');
        $back = $v2->diff($v1);
        $this->assertEquals(['sla'], $back->removedRegions);
        $this->assertCount(5, $back->removedTransitions);

        $a = Spec::machine('d', 1)
            ->region('main', 'a')
                ->state('a')->on('GO', 'b', 'go')->guard('payloadHas', ['field' => 'x'])->on('SKIP', 'c', 'skip')
                ->state('b')->on('GO', 'c', 'go_b')
                ->finalState('c')
            ->build();
        $b = Spec::machine('d', 2)
            ->region('main', 'b')
                ->state('a')->code(1)->on('GO', 'c', 'go')->guard('payloadHas', ['field' => 'y'])->on('NEW', 'c', 'new')
                ->finalState('c')
            ->region('extra', 'z')->state('z')
            ->build();
        $d = $a->diff($b);
        $this->assertEquals(['extra'], $d->addedRegions);
        $this->assertEquals(['main'], $d->changedRegions, 'inicial trocado');
        $this->assertEquals(['extra.z'], $d->addedStates);
        $this->assertEquals(['main.b'], $d->removedStates);
        $this->assertEquals(['main.a'], $d->changedStates, 'código acrescentado');
        $this->assertEquals(['new'], $d->addedTransitions);
        $this->assertEquals(['skip', 'go_b'], $d->removedTransitions);
        $this->assertEquals(['go'], $d->changedTransitions, 'alvo e parâmetro do guard');
        $this->assertEquals(['added' => ['new'], 'removed' => ['skip', 'go_b'], 'changed' => ['go']], $d->toArray()['transitions']);
        $this->assertEquals(['machine_key', 'from_version', 'to_version', 'regions', 'states', 'transitions'], array_keys($d->toArray()));
        $this->assertThrows(InvalidArgumentException::class, fn() => $a->diff($this->v1()), 'chaves diferentes');
    }

    // ==========================================
    // L2: the spec guard
    // ==========================================

    private function testSpecGuard(): void
    {
        $plain = new GuardRegistry();
        $this->assertFalse($plain->has(GuardRegistry::SPEC));
        $guards = (new GuardRegistry())->enableSpecGuard();
        $this->assertTrue($guards->has(GuardRegistry::SPEC));
        $ref = GuardRegistry::specRef(Spec::property('amount', Spec::lessThan(1000))->because('Above the fast-track limit.')->withCode('FAST_LIMIT'));
        $this->assertEquals('spec', $ref->name);
        $this->assertEquals(['v' => 1, 'spec' => ['t' => 'property', 'p' => 'amount', 's' => ['t' => 'lt', 'v' => 1000], 'because' => 'Above the fast-track limit.', 'code' => 'FAST_LIMIT']], $ref->params['document']);

        $machine = Spec::machine('fast', 1)
            ->region('main', 'a')
                ->state('a')->on('GO', 'b')->guard($ref)
                ->state('b')->deadline('PT1H', 'TICK', pausedWhile: GuardRegistry::specRef(Spec::property('hold', Spec::equalTo(true))))->on('TICK', 'c')
                ->state('c')->on('BACK', 'a')->guard(GuardRegistry::specRef(Spec::not(Spec::property('blocked', Spec::equalTo(true)))->and(Spec::isNotNull())))
            ->build();
        $core = Spec::stateMachine($guards);
        $t0 = new DateTimeImmutable('2026-10-21T09:00:00Z');
        $start = $core->initialSnapshot($machine, null, $t0)->snapshot;

        foreach (['array' => ['amount' => 500, 'hold' => false], 'object' => (object) ['amount' => 500, 'hold' => false]] as $kind => $context) {
            $step = $core->transition($machine, $start, Event::external('GO', $t0), $context);
            $this->assertTrue($step->isAccepted(), "contexto {$kind}: aceito");
            $this->assertEquals('2026-10-21 10:00:00.000000', PersistenceSupport::toText($step->snapshot->dueAt('main')), "contexto {$kind}: prazo corre");
        }
        $refused = $core->transition($machine, $start, Event::external('GO', $t0), ['amount' => 5000]);
        $this->assertFalse($refused->isAccepted());
        $failure = $refused->toArray()['reason']['failures'][0];
        $this->assertEquals(['Above the fast-track limit.', 'FAST_LIMIT', 'context.amount'], [$failure['message'], $failure['code'], $failure['property']], 'a falha nomeia context.amount, com because/withCode');
        foreach (['array' => ['amount' => 5, 'hold' => true], 'object' => (object) ['amount' => 5, 'hold' => true]] as $kind => $context) {
            $paused = $core->transition($machine, $start, Event::external('GO', $t0), $context);
            $this->assertTrue($paused->isAccepted() && $paused->snapshot->pausedRemaining('main') === 3600, "contexto {$kind}: a pausa (isSatisfiedBy) lê o contexto pelo caminho");
        }
        $noContext = $core->transition($machine, $start, Event::external('GO', $t0), null);
        $this->assertFalse($noContext->isAccepted(), 'sem contexto: recusado');
        $this->assertTrue($noContext->toArray()['reason']['error'] === true, 'sem contexto: erro de avaliação, não falha da regra');

        $atC = new MachineSnapshot('fast', 1, ['main' => 'c']);
        $this->assertTrue($core->transition($machine, $atC, Event::external('BACK', $t0), ['blocked' => false])->isAccepted(), 'not/and enraizados no contexto');
        $this->assertFalse($core->transition($machine, $atC, Event::external('BACK', $t0), ['blocked' => true])->isAccepted());
        $this->assertFalse($core->transition($machine, $atC, Event::external('BACK', $t0), null)->isAccepted(), 'isNotNull lê o próprio contexto');

        // The validator reports what the codec refuses
        $bad = static fn(array $params): MachineDefinition => Spec::machine('bad', 1)
            ->region('main', 'a')->state('a')->on('GO', 'b')->guard('spec', $params)->finalState('b')->build();
        foreach ([
            'sem documento' => [],
            'documento texto' => ['document' => 'x'],
            'tipo desconhecido' => ['document' => ['v' => 1, 'spec' => ['t' => 'nope']]],
            'versão de formato' => ['document' => ['v' => 2, 'spec' => ['t' => 'isNull']]],
            'chave a mais' => ['document' => ['v' => 1, 'spec' => ['t' => 'isNull'], 'x' => 1]],
            'subconsulta' => ['document' => ['v' => 1, 'spec' => ['t' => 'inSubquery', 'table' => 'users']]],
        ] as $what => $params) {
            $report = \Antevemus\ASpecification\StateMachine\MachineDefinitionValidator::analyze($bad($params), $guards);
            $this->assertEquals([MachineDefinitionError::KIND_GUARD_BUILD_FAILED], $report->errorKinds(), "spec {$what}: guard_build_failed");
        }
        $missing = \Antevemus\ASpecification\StateMachine\MachineDefinitionValidator::analyze($bad([]), $guards);
        $this->assertTrue(str_contains($missing->errors[0]->message, 'expects the parameter "document"'), 'spec sem documento: a mensagem diz o que falta');
        $this->assertThrows(SpecificationCodecException::class, fn() => GuardRegistry::specRef(Spec::property('a', Spec::equalTo(1))->and(Spec::isNotNull()), new SpecificationCodec(maxNodes: 1)), 'specRef usa o codec dado');
        $pausing = Spec::machine('pz', 1)
            ->region('main', 'a')
                ->state('a')->deadline('PT1H', 'TICK', pausedWhile: GuardRegistry::specRef(Spec::property('hold', Spec::equalTo(true))->and(Spec::not(Spec::property('override', Spec::equalTo(true))))))->on('TICK', 'b')
                ->finalState('b')
            ->build();
        foreach ([[['hold' => true, 'override' => false], 3600], [['hold' => true, 'override' => true], null], [['hold' => false, 'override' => false], null]] as [$context, $expected]) {
            $paused = $core->initialSnapshot($pausing, $context, $t0);
            $this->assertTrue($paused->isAccepted() && $paused->snapshot->pausedRemaining('main') === $expected, 'and/not enraizados numa pausa com contexto array: ' . json_encode($context));
        }
        $report = \Antevemus\ASpecification\StateMachine\MachineDefinitionValidator::analyze($machine, $plain);
        $this->assertEquals([MachineDefinitionError::KIND_UNKNOWN_GUARD], array_values(array_unique($report->errorKinds())), 'sem enableSpecGuard: guard desconhecido');
        $this->assertThrows(NonSerializableSpecificationException::class, fn() => GuardRegistry::specRef(new PredicateSpecification(static fn(): bool => true)), 'callback não vira documento');
        $tiny = (new GuardRegistry())->enableSpecGuard(new SpecificationCodec(maxNodes: 2));
        $report = \Antevemus\ASpecification\StateMachine\MachineDefinitionValidator::analyze($machine, $tiny);
        $this->assertTrue(in_array(MachineDefinitionError::KIND_GUARD_BUILD_FAILED, $report->errorKinds(), true), 'os limites do codec dado valem');

        // Decidable: the algebra sees two disjoint conditions over the same path (no ordering warning)
        $disjoint = Spec::machine('dj', 1)
            ->region('main', 'a')
                ->state('a')
                    ->on('GO', 'b', 'small')->guard(GuardRegistry::specRef(Spec::property('amount', Spec::lessThan(1000))))
                    ->on('GO', 'c', 'large')->guard(GuardRegistry::specRef(Spec::property('amount', Spec::greaterThanOrEqualTo(1000))))
                ->finalState('b')
                ->finalState('c')
            ->build();
        $overlap = Spec::machine('ov', 1)
            ->region('main', 'a')
                ->state('a')
                    ->on('GO', 'b', 'small')->guard(GuardRegistry::specRef(Spec::property('amount', Spec::lessThan(1000))))
                    ->on('GO', 'c', 'large')->guard(GuardRegistry::specRef(Spec::property('amount', Spec::lessThan(2000))))
                ->finalState('b')
                ->finalState('c')
            ->build();
        $warnings = static fn(MachineDefinition $d): array => \Antevemus\ASpecification\StateMachine\MachineDefinitionValidator::analyze($d, $guards)->warningKinds();
        $this->assertEquals([], $warnings($disjoint), 'condições disjuntas: sem aviso de ordem');
        $this->assertTrue($warnings($overlap) !== [], 'condições sobrepostas: aviso de ordem');
    }

    // ==========================================
    // L2: drafts (one contract, InMemory and PDO)
    // ==========================================

    /**
     * @param Closure(): (IMachineDefinitionRepository&IMachineDefinitionDrafts) $factory
     */
    private function runDraftContract(string $label, Closure $factory): void
    {
        $repository = $factory();
        $guards = $this->quoteGuards();
        $key = 'contract_quote';
        $at = new DateTimeImmutable('2026-10-21T09:00:00-03:00');

        $this->assertTrue($repository->draft($key, $guards) === null, "{$label}: sem rascunho");
        $this->assertThrows(DefinitionDraftConflictException::class, fn() => $repository->discardDraft($key, 1), "{$label}: descartar sem rascunho");
        $this->assertThrows(DefinitionDraftConflictException::class, fn() => $repository->publish($key, 1, $guards, null, 'x', 'ana', $at), "{$label}: publicar sem rascunho");
        $e = $this->assertThrows(InvalidArgumentException::class, fn() => $repository->saveDraft($key, ['key' => 'other'], null, 'ana', $at), "{$label}: outra chave");
        $this->assertTrue(str_contains($e->getMessage(), '("other")'), "{$label}: a mensagem cita a chave");
        $e = $this->assertThrows(InvalidArgumentException::class, fn() => $repository->saveDraft($key, ['key' => 5], null, 'ana', $at), "{$label}: chave não texto");
        $this->assertTrue(str_contains($e->getMessage(), '(int)'), "{$label}: a mensagem cita o tipo");
        $this->assertThrows(InvalidMachineDefinitionException::class, fn() => $repository->saveDraft(' bad', [], null, 'ana', $at), "{$label}: chave de máquina inválida");
        $this->assertThrows(InvalidMachineDefinitionException::class, fn() => $repository->saveDraft($key, ['regions' => [new \stdClass()]], null, 'ana', $at), "{$label}: array não JSON");
        $this->assertTrue($repository->draft($key, $guards) === null, "{$label}: nada gravado pelas recusas");

        // A half-done graph is saved and reported
        $half = ['regions' => [[
            'name' => 'progress',
            'states' => [['name' => 'registering', 'code' => 1, 'transitions' => [['id' => 'complete', 'event' => 'COMPLETE', 'target' => 'done']]]],
        ]]];
        $info = $repository->saveDraft($key, $half, null, 'ana', $at);
        $this->assertEquals([$key, 1, null, 'ana', '2026-10-21 12:00:00.000000'], [$info->machineKey, $info->revision, $info->basedOnVersion, $info->updatedBy, PersistenceSupport::toText($info->updatedAt)], "{$label}: revisão 1, instante em UTC");
        $draft = $repository->draft($key, $guards);
        $this->assertTrue($draft !== null && $draft->revision() === 1 && $draft->definition === $half, "{$label}: o array volta como salvo");
        $this->assertEquals([MachineDefinitionError::KIND_MISSING_INITIAL, MachineDefinitionError::KIND_UNKNOWN_TARGET], $draft->report->errorKinds(), "{$label}: relatório com os erros");
        $this->assertEquals(['progress', 'registering', 'complete'], [$draft->report->errors[1]->region, $draft->report->errors[1]->state, $draft->report->errors[1]->transitionId], "{$label}: erro localizado");
        $this->assertFalse($draft->isPublishable());
        $malformed = $repository->saveDraft($key, ['regions' => [['name' => 'p', 'initial' => 'x', 'states' => [['name' => 'x', 'code' => 1.5]]]]], 1, 'ana', $at);
        $this->assertEquals([MachineDefinitionError::KIND_MALFORMED], $repository->draft($key, $guards)->report->errorKinds(), "{$label}: array que nem monta definição é reportado, não recusado");
        $this->assertEquals(2, $malformed->revision);

        // Compare-and-set
        $this->assertThrows(DefinitionDraftConflictException::class, fn() => $repository->saveDraft($key, $half, null, 'bob', $at), "{$label}: criar quando já existe");
        $conflict = $this->assertThrows(DefinitionDraftConflictException::class, fn() => $repository->saveDraft($key, $half, 1, 'bob', $at), "{$label}: revisão velha");
        $this->assertTrue($conflict instanceof ConcurrencyConflictException && $conflict->getMachineKey() === $key && $conflict->getExpectedRevision() === 1 && $conflict->getActualRevision() === 2, "{$label}: o conflito diz o que esperava e o que achou");
        $this->assertTrue(str_contains($conflict->getMessage(), 'expected revision 1, found revision 2'), "{$label}: mensagem do conflito");
        $this->assertThrows(DefinitionDraftConflictException::class, fn() => $repository->saveDraft('other_key', $half, 4, 'bob', $at), "{$label}: revisão esperada sem rascunho");
        $this->assertTrue($repository->draft('other_key') === null, "{$label}: nada criado");
        $this->assertThrows(DefinitionDraftConflictException::class, fn() => $repository->publish($key, 1, $guards, null, 'x', 'bob', $at), "{$label}: publicar revisão velha");
        $this->assertEquals(2, $repository->draft($key, $guards)?->revision(), "{$label}: nada mudou");

        // An error refuses the publication and writes nothing
        $e = $this->assertThrows(InvalidMachineDefinitionException::class, fn() => $repository->publish($key, 2, $guards, null, 'x', 'ana', $at), "{$label}: rascunho com erro não publica");
        $this->assertEquals([MachineDefinitionError::KIND_MALFORMED], $e->getErrorKinds());
        $this->assertTrue($repository->latestVersion($key) === null && $repository->draft($key, $guards)?->revision() === 2, "{$label}: nada gravado");

        // A valid draft: the version comes from the repository, the warnings come back
        $valid = $this->contractDefinition(99);
        $info = $repository->saveDraft($key, $valid, 2, 'ana', $at);
        $this->assertEquals(3, $info->revision);
        $draft = $repository->draft($key, $guards, ['notify']);
        $this->assertTrue($draft->isPublishable() && $draft->report->warningKinds() === ['dead_end_state'], "{$label}: publicável, com aviso");
        $this->assertEquals([MachineDefinitionError::KIND_UNKNOWN_ACTION], $repository->draft($key, $guards, [])->report->errorKinds(), "{$label}: ações conhecidas conferidas");
        $this->assertThrows(InvalidArgumentException::class, fn() => $repository->publish($key, 3, $guards, null, '  ', 'ana', $at), "{$label}: motivo em branco");
        $this->assertThrows(InvalidMachineDefinitionException::class, fn() => $repository->publish($key, 3, $guards, [], 'Actions', 'ana', $at), "{$label}: ação sem implementação recusa");
        $published = $repository->publish($key, 3, $guards, ['notify'], 'First version', 'ana', $at->modify('+1 hour'));
        $this->assertEquals(1, $published->version(), "{$label}: versão 1 (o version 99 do array é ignorado)");
        $this->assertEquals(['First version', 'ana', '2026-10-21 13:00:00.000000'], [$published->reason, $published->publishedBy, PersistenceSupport::toText($published->publishedAt)]);
        $this->assertEquals(['dead_end_state'], array_map(static fn($w): string => $w->kind, $published->warnings), "{$label}: avisos no resultado");
        $this->assertTrue($repository->draft($key, $guards) === null, "{$label}: rascunho removido");
        $this->assertEquals(1, $repository->latestVersion($key));
        $this->assertTrue($repository->has($key, 1) && !$repository->has($key, 2));
        $this->assertEquals($published->definition->fingerprint(), $repository->get($key, 1)->fingerprint());
        $this->assertTrue($repository->get($key, 1)->codeOf('progress', 'calculated') === '2', "{$label}: códigos publicados como declarados");

        // draftFrom: a draft from a published version
        $this->assertThrows(UnknownMachineDefinitionException::class, fn() => $repository->draftFrom($key, 7, null, 'ana', $at), "{$label}: versão inexistente");
        $from = $repository->draftFrom($key, 1, null, 'bob', $at);
        $this->assertEquals([1, 1, 'bob'], [$from->revision, $from->basedOnVersion, $from->updatedBy]);
        $this->assertEquals($repository->get($key, 1)->toArray(), $repository->draft($key, $guards)?->definition, "{$label}: o rascunho é a forma de array da versão");
        $this->assertThrows(DefinitionDraftConflictException::class, fn() => $repository->draftFrom($key, 1, null, 'bob', $at), "{$label}: draftFrom sobre rascunho existente exige a revisão");
        $edited = $repository->get($key, 1)->toArray();
        $edited['regions'][0]['states'][1]['transitions'][] = ['id' => 'reopen', 'event' => 'REOPEN', 'target' => 'registering'];
        $saved = $repository->saveDraft($key, $edited, 1, 'bob', $at);
        $this->assertEquals([2, 1], [$saved->revision, $saved->basedOnVersion], "{$label}: o salvamento mantém a versão de origem");
        $replaced = $repository->draftFrom($key, 1, 2, 'bob', $at);
        $this->assertEquals(3, $replaced->revision, "{$label}: draftFrom com a revisão substitui");
        $saved = $repository->saveDraft($key, $edited, 3, 'bob', $at);
        $v2 = $repository->publish($key, 4, $guards, null, 'Reopen', 'bob', $at);
        $this->assertEquals(2, $v2->version());
        $this->assertEquals(['reopen'], $repository->get($key, 1)->diff($v2->definition)->addedTransitions);

        // The guards of the report: the ones given, the built-ins without them
        $guarded = $this->contractDefinition(1);
        $guarded['regions'][0]['states'][0]['transitions'][0]['guard'] = ['guard' => 'hasRole', 'params' => ['roles' => []]];
        $info = $repository->saveDraft($key, $guarded, null, 'ana', $at);
        $this->assertTrue($repository->draft($key, $guards)?->isPublishable() === true, "{$label}: com os guards dados, publicável");
        $this->assertEquals([MachineDefinitionError::KIND_UNKNOWN_GUARD], $repository->draft($key)?->report->errorKinds(), "{$label}: sem guards, só os nativos");
        $repository->discardDraft($key, $info->revision);

        // Discard
        $info = $repository->saveDraft($key, ['regions' => []], null, 'ana', $at);
        $this->assertEquals(2, $info->basedOnVersion, "{$label}: rascunho novo parte da última versão");
        $this->assertThrows(DefinitionDraftConflictException::class, fn() => $repository->discardDraft($key, 9));
        $repository->discardDraft($key, 1);
        $this->assertTrue($repository->draft($key, $guards) === null, "{$label}: descartado");
        $this->assertEquals(2, $repository->latestVersion($key), "{$label}: o descarte não toca as versões");

        // register() and publish() share the versions; a published pair is immutable
        $repository->register($repository->get($key, 2));
        $changed = $repository->get($key, 2)->toArray();
        $changed['regions'][0]['states'][0]['code'] = 77;
        $e = $this->assertThrows(InvalidMachineDefinitionException::class, fn() => $repository->register(MachineDefinition::fromArray($changed)), "{$label}: outro conteúdo no mesmo par");
        $this->assertEquals([MachineDefinitionError::KIND_DUPLICATE], $e->getErrorKinds());
        $changed['version'] = 3;
        $repository->register(MachineDefinition::fromArray($changed));
        $info = $repository->saveDraft($key, $changed, null, 'ana', $at);
        $this->assertEquals(4, $repository->publish($key, $info->revision, $guards, null, 'After a register', 'ana', $at)->version(), "{$label}: a publicação segue a última versão registrada");

        // The calendars of the report and of the publication (2.7.0, business-time deadlines): a deadline naming
        // a calendar the registry does not know is reported by draft() and refused by publish(); null skips it
        $timed = $this->contractDefinition(1);
        $timed['regions'][0]['states'][1]['deadline'] = ['after' => 'P2D', 'event' => 'LATE', 'calendar' => 'business'];
        $timed['regions'][0]['states'][1]['transitions'] = [['id' => 'late', 'event' => 'LATE', 'target' => 'registering']];
        $info = $repository->saveDraft($key, $timed, null, 'ana', $at);
        $business = (new DeadlineCalendarRegistry())->register('business', new BusinessDayCalendar(new \DateTimeZone('America/Sao_Paulo'), HolidaySet::none()));
        $other = (new DeadlineCalendarRegistry())->register('continuous', new ContinuousCalendar());
        $this->assertEquals([MachineDefinitionError::KIND_UNKNOWN_CALENDAR], $repository->draft($key, $guards, null, $other)?->report->errorKinds(), "{$label}: draft() reporta o calendário desconhecido");
        $this->assertTrue($repository->draft($key, $guards, null, $business)?->isPublishable() === true, "{$label}: com o calendário registrado, publicável");
        $this->assertTrue($repository->draft($key, $guards)?->isPublishable() === true, "{$label}: sem registro de calendários, a conferência é pulada (como no validador)");
        $e = $this->assertThrows(InvalidMachineDefinitionException::class, fn() => $repository->publish($key, $info->revision, $guards, null, 'Business time', 'ana', $at, $other), "{$label}: publish() recusa o calendário desconhecido");
        $this->assertEquals([MachineDefinitionError::KIND_UNKNOWN_CALENDAR], $e->getErrorKinds());
        $this->assertEquals($info->revision, $repository->draft($key)?->revision(), "{$label}: nada gravado");
        $before = $repository->latestVersion($key);
        $timedVersion = $repository->publish($key, $info->revision, $guards, null, 'Business time', 'ana', $at, $business);
        $this->assertEquals($before + 1, $timedVersion->version(), "{$label}: publicado com o registro que conhece o calendário");
        $this->assertEquals('business', $repository->get($key, $timedVersion->version())->getRegion('progress')?->getState('calculated')?->deadline?->calendar, "{$label}: o nome do calendário volta da publicação");
    }

    /**
     * A small valid definition of the draft contract (with a dead-end state, for a warning).
     *
     * @return array<string, mixed>
     */
    private function contractDefinition(int $version): array
    {
        return [
            'key' => 'contract_quote',
            'version' => $version,
            'regions' => [[
                'name' => 'progress',
                'initial' => 'registering',
                'states' => [
                    ['name' => 'registering', 'code' => 1, 'transitions' => [['id' => 'complete', 'event' => 'COMPLETE', 'target' => 'calculated', 'actions' => [['action' => 'notify']]]]],
                    ['name' => 'calculated', 'code' => '2'],
                ],
            ]],
        ];
    }

    // ==========================================
    // L2: by-state-name migration and migrateAll()
    // ==========================================

    /**
     * @param Closure(): MachinePersistence $persistenceFactory
     */
    private function testByStateNameAndMigrateAll(string $label, Closure $persistenceFactory, ?IMachineDefinitionRepository $repository = null): void
    {
        [$v1, $v2, $v3] = $this->flowVersions();
        $repository ??= new InMemoryMachineDefinitionRepository();
        foreach ([$v1, $v2, $v3] as $definition) {
            $repository->register($definition);
        }
        $persistence = $persistenceFactory();
        $runner = new MachineRunner(Spec::stateMachine(), $repository, $persistence);
        $t0 = new DateTimeImmutable('2026-10-21T09:00:00Z');
        foreach (['i-1', 'i-2', 'i-3', 'i-4', 'i-5'] as $n => $id) {
            $runner->start($id, 'flow', 1, null, $t0->modify("+{$n} minutes"), tenant: $id === 'i-4' ? 'acme' : null);
        }
        $runner->send('i-2', Event::external('GO', $t0->modify('+1 hour')));
        $due = $runner->load('i-2')->snapshot->dueAt('main');
        $this->assertTrue($due !== null, "{$label}: i-2 em b, com prazo");

        $this->assertThrows(MachineMigrationException::class, fn() => (new ByStateNameMigration())->migrate($runner->load('i-1')->snapshot), "{$label}: sem destino, migrate() recusa");
        $this->assertThrows(MachineMigrationException::class, fn() => $runner->migrateAll('flow', 1, 2, $t0), "{$label}: sem migração registrada");
        $runner->registerMigration('flow', 1, 2, MachineMigrations::byStateName());
        $runner->registerMigration('flow', 2, 3, MachineMigrations::byStateName());

        $lister = $persistence->instances;
        $this->assertTrue($lister instanceof IMachineInstanceLister, "{$label}: o store lista instâncias");
        \assert($lister instanceof IMachineInstanceLister);
        $this->assertEquals(['i-1', 'i-2', 'i-3', 'i-4', 'i-5'], $lister->idsByMachineVersion('flow', 1, 10));
        $this->assertEquals(['i-2', 'i-3'], $lister->idsByMachineVersion('flow', 1, 2, 'i-1'), "{$label}: página depois de i-1");
        $this->assertEquals([], $lister->idsByMachineVersion('flow', 2, 10));
        $this->assertEquals([], $lister->idsByMachineVersion('other', 1, 10));
        $this->assertThrows(InvalidArgumentException::class, fn() => $lister->idsByMachineVersion('flow', 1, 0));

        $at = $t0->modify('+2 hours');
        $batches = [];
        do {
            $report = $runner->migrateAll('flow', 1, 2, $at, 'admin', 2);
            $batches[] = array_map(static fn($m): string => $m->instanceId . ($m->isMigrated() ? '' : '!'), $report);
        } while ($report !== []);
        $this->assertEquals([['i-1', 'i-2'], ['i-3', 'i-4'], ['i-5'], []], $batches, "{$label}: lotes de 2 até esvaziar");
        foreach (['i-1', 'i-2', 'i-3', 'i-4', 'i-5'] as $id) {
            $instance = $runner->load($id);
            $this->assertEquals(2, $instance->getMachineVersion(), "{$label}: {$id} na v2");
        }
        $migrated = $runner->load('i-2');
        $this->assertEquals('b', $migrated->snapshot->stateOf('main'), "{$label}: mesmo estado pelo nome");
        $this->assertEquals(PersistenceSupport::toText($due), PersistenceSupport::toText($migrated->snapshot->dueAt('main') ?? $t0), "{$label}: prazo materializado mantido");
        $this->assertEquals(3, $migrated->version, "{$label}: versão otimista + 1");
        $this->assertTrue($runner->load('i-4')->tenant === 'acme', "{$label}: tenant mantido na migração");
        $rows = $persistence->history->findByInstance('i-2');
        $last = $rows[count($rows) - 1];
        $this->assertEquals([MachineRunner::MIGRATE_EVENT, 'b', 'b', 'admin', ['from_version' => 1, 'to_version' => 2]], [$last->eventType, $last->fromState, $last->toState, $last->actor, $last->payload], "{$label}: histórico da migração");
        $this->assertTrue($runner->send('i-1', Event::external('SKIP', $at))->isAccepted(), "{$label}: a transição nova da v2 vale depois da migração");

        // v3 removed state b: i-2 is refused and stays; the others move
        $this->assertEquals(['main.b'], $v2->diff($v3)->removedStates);
        $report = $runner->migrateAll('flow', 2, 3, $at, 'admin');
        $skipped = array_values(array_filter($report, static fn($m): bool => $m->isSkipped()));
        $this->assertCount(1, $skipped, "{$label}: só i-2 pulada");
        $this->assertEquals('i-2', $skipped[0]->instanceId);
        $this->assertTrue($skipped[0]->instance === null && str_contains((string) $skipped[0]->skipped, 'does not declare'), "{$label}: o motivo vem da conferência do destino");
        $this->assertEquals(2, $runner->load('i-2')->getMachineVersion(), "{$label}: i-2 continua na v2");
        $this->assertEquals(['i-2'], array_map(static fn($m): string => $m->instanceId, $runner->migrateAll('flow', 2, 3, $at, 'admin')), "{$label}: a próxima rodada tenta de novo");
        $this->assertEquals([], $runner->migrateAll('flow', 2, 3, $at, 'admin', 100, 'i-2'), "{$label}: depois de i-2 não sobra nada");
    }

    /**
     * Versions 1, 2 (a new transition) and 3 (state b removed) of the flow of the migration tests.
     *
     * @return array{MachineDefinition, MachineDefinition, MachineDefinition}
     */
    private function flowVersions(): array
    {
        $v1 = Spec::machine('flow', 1)
            ->region('main', 'a')
                ->state('a')->code(1)->on('GO', 'b')
                ->state('b')->code(2)->deadline('P1D', 'EXPIRE')->on('GO', 'c')->on('EXPIRE', 'c')
                ->finalState('c')->code(3)
            ->build();
        $v2 = Spec::machine('flow', 2)
            ->region('main', 'a')
                ->state('a')->code(1)->on('GO', 'b')->on('SKIP', 'c')
                ->state('b')->code(22)->deadline('P1D', 'EXPIRE')->on('GO', 'c')->on('EXPIRE', 'c')
                ->finalState('c')->code(3)
            ->build();
        $v3 = Spec::machine('flow', 3)
            ->region('main', 'a')
                ->state('a')->code(1)->on('GO', 'c')->on('SKIP', 'c')
                ->finalState('c')->code(3)
            ->build();

        return [$v1, $v2, $v3];
    }

    private function testMigrateAllSkipsAndRefusals(): void
    {
        [$v1, $v2] = $this->flowVersions();
        $repository = new InMemoryMachineDefinitionRepository([$v1, $v2]);
        $t0 = new DateTimeImmutable('2026-10-21T09:00:00Z');

        $plain = new Module40PlainStore(new InMemoryMachineInstanceStore());
        $runner = new MachineRunner(Spec::stateMachine(), $repository, $this->persistenceOver($plain));
        $runner->registerMigration('flow', 1, 2, MachineMigrations::byStateName());
        $runner->start('p-1', 'flow', 1, null, $t0);
        $e = $this->assertThrows(StateMachineException::class, fn() => $runner->migrateAll('flow', 1, 2, $t0), 'store que não lista: recusa clara');
        $this->assertTrue(str_contains($e->getMessage(), 'IMachineInstanceLister'));

        $conflicting = new Module40ConflictingStore(new InMemoryMachineInstanceStore());
        $runner = new MachineRunner(Spec::stateMachine(), $repository, $this->persistenceOver($conflicting));
        $runner->registerMigration('flow', 1, 2, MachineMigrations::byStateName());
        foreach (['c-1', 'c-2', 'c-3'] as $id) {
            $runner->start($id, 'flow', 1, null, $t0);
        }
        $conflicting->conflictOn = 'c-2';
        $report = $runner->migrateAll('flow', 1, 2, $t0);
        $this->assertEquals([true, false, true], array_map(static fn($m): bool => $m->isMigrated(), $report), 'conflito pula e segue');
        $this->assertTrue(str_contains((string) $report[1]->skipped, 'Concurrency conflict'));
        $conflicting->conflictOn = null;
        $this->assertEquals([true], array_map(static fn($m): bool => $m->isMigrated(), $runner->migrateAll('flow', 1, 2, $t0)), 'a próxima rodada migra');
        $runner->start('c-4', 'flow', 1, null, $t0);
        $conflicting->missing = 'c-4';
        $gone = $runner->migrateAll('flow', 1, 2, $t0);
        $this->assertTrue(count($gone) === 1 && $gone[0]->isSkipped() && str_contains((string) $gone[0]->skipped, 'does not exist'), 'instância listada e sumida: pulada');
        $conflicting->missing = null;

        // An invalid target is refused once, before the batch
        $broken = MachineDefinition::fromArray(['key' => 'flow', 'version' => 9, 'regions' => [['name' => 'main', 'states' => [['name' => 'a']]]]]);
        $repository->register($broken);
        $runner->registerMigration('flow', 2, 9, MachineMigrations::byStateName());
        $this->assertThrows(InvalidMachineDefinitionException::class, fn() => $runner->migrateAll('flow', 2, 9, $t0), 'destino recusado pelo validador');
        $this->assertEquals(2, $runner->load('c-1')->getMachineVersion(), 'nada migrado');
        $runner->registerMigration('flow', 8, 9, MachineMigrations::byStateName());
        $this->assertThrows(InvalidMachineDefinitionException::class, fn() => $runner->migrateAll('flow', 8, 9, $t0), 'destino recusado mesmo sem instâncias na origem');
    }

    private function testByStateNameKeepsTheTargetOrderAndThePause(): void
    {
        $v1 = Spec::machine('ord', 1)
            ->region('x', 'a')->state('a')->deadline('P1D', 'EXPIRE', pausedWhile: 'y.hold')->on('EXPIRE', 'z')->finalState('z')
            ->region('y', 'free')->state('free')->on('HOLD', 'hold')->state('hold')->on('FREE', 'free')
            ->build();
        $v2 = Spec::machine('ord', 2)
            ->region('y', 'free')->state('free')->on('HOLD', 'hold')->state('hold')->on('FREE', 'free')
            ->region('x', 'a')->state('a')->deadline('P1D', 'EXPIRE', pausedWhile: 'y.hold')->on('EXPIRE', 'z')->finalState('z')
            ->build();
        $runner = new MachineRunner(Spec::stateMachine(), new InMemoryMachineDefinitionRepository([$v1, $v2]), MachinePersistence::inMemory());
        $t0 = new DateTimeImmutable('2026-10-21T09:00:00Z');
        $runner->start('o-1', 'ord', 1, null, $t0);
        $runner->send('o-1', Event::external('HOLD', $t0->modify('+1 hour')));
        $runner->registerMigration('ord', 1, 2, MachineMigrations::byStateName());
        $migrated = $runner->migrate('o-1', 2, $t0->modify('+2 hours'))->snapshot;
        $this->assertEquals(['y', 'x'], $migrated->regions(), 'byStateName: as regiões na ordem do destino');
        $this->assertEquals(82800, $migrated->pausedRemaining('x'), 'byStateName: o saldo pausado mantido');
        $this->assertTrue($migrated->dueAt('x') === null);
        $direct = MachineMigrations::byStateName()->migrateTo(new MachineSnapshot('ord', 1, ['x' => 'a', 'y' => 'free', 'w' => 'q']), $v2);
        $this->assertEquals(['x', 'y', 'w'], $direct->regions(), 'regiões diferentes: a ordem fica, o runner recusa depois');
    }

    private function testDiffDetails(): void
    {
        foreach (['addedRegions', 'removedRegions', 'changedRegions', 'addedStates', 'removedStates', 'changedStates', 'addedTransitions', 'removedTransitions', 'changedTransitions'] as $field) {
            $diff = new MachineDefinitionDiff('k', 1, 2, ...[$field => ['x']]);
            $this->assertFalse($diff->isEmpty(), "diff com {$field}: não vazio");
        }
        $this->assertTrue((new MachineDefinitionDiff('k', 1, 2))->isEmpty());
        $full = new MachineDefinitionDiff('k', 1, 2, ['r+'], ['r-'], ['r~'], ['s+'], ['s-'], ['s~'], ['t+'], ['t-'], ['t~']);
        $this->assertEquals([
            'machine_key' => 'k', 'from_version' => 1, 'to_version' => 2,
            'regions' => ['added' => ['r+'], 'removed' => ['r-'], 'changed' => ['r~']],
            'states' => ['added' => ['s+'], 'removed' => ['s-'], 'changed' => ['s~']],
            'transitions' => ['added' => ['t+'], 'removed' => ['t-'], 'changed' => ['t~']],
        ], $full->toArray());

        // The same transition (id, content and state name) moved to another region is a change
        $a = Spec::machine('mv', 1)
            ->region('r1', 's')->state('s')->on('GO', 's', 't')
            ->region('r2', 's')->state('s')
            ->build();
        $b = Spec::machine('mv', 2)
            ->region('r1', 's')->state('s')
            ->region('r2', 's')->state('s')->on('GO', 's', 't')
            ->build();
        $this->assertEquals(['t'], $a->diff($b)->changedTransitions, 'transição que muda de região');
        $this->assertEquals([], $a->diff($b)->changedStates, 'os estados (sem as transições) não mudam');
    }

    private function testConflictException(): void
    {
        $none = new DefinitionDraftConflictException('k', null, 3);
        $this->assertEquals(['k', 'k', null, 3, 0, 3], [$none->getMachineKey(), $none->getInstanceId(), $none->getExpectedRevision(), $none->getActualRevision(), $none->getExpectedVersion(), $none->getActualVersion()]);
        $this->assertTrue(str_contains($none->getMessage(), 'machine "k": expected no draft, found revision 3.'), $none->getMessage());
        $gone = new DefinitionDraftConflictException('k', 2, null);
        $this->assertEquals([2, null, 2, 0], [$gone->getExpectedRevision(), $gone->getActualRevision(), $gone->getExpectedVersion(), $gone->getActualVersion()]);
        $this->assertTrue(str_contains($gone->getMessage(), 'expected revision 2, found no draft.'), $gone->getMessage());
        $this->assertEquals('custom', (new DefinitionDraftConflictException('k', 1, 1, 'custom'))->getMessage());
    }

    /**
     * The default page sizes (100) of the new reads: findDueForTenant(), idsByMachineVersion(), the
     * tenant findDue() of the runner and migrateAll(); numeric-looking ids in binary order.
     *
     * @param Closure(IMachineDefinitionRepository): MachinePersistence $persistenceFactory
     */
    private function testDefaultLimits(string $label, Closure $persistenceFactory): void
    {
        [$v1, $v2] = $this->flowVersions();
        $repository = new InMemoryMachineDefinitionRepository([$v1, $v2]);
        $persistence = $persistenceFactory($repository);
        $runner = new MachineRunner(Spec::stateMachine(), $repository, $persistence);
        $t0 = new DateTimeImmutable('2026-10-21T09:00:00Z');
        $ids = array_map(static fn(int $n): string => (string) $n, range(205, 100, -1)); // 106 ids, "205" down to "100"
        foreach ($ids as $id) {
            $runner->start($id, 'flow', 1, null, $t0, tenant: 't');
            $runner->send($id, Event::external('GO', $t0));
        }
        $store = $persistence->instances;
        \assert($store instanceof ITenantScopedInstanceStore && $store instanceof IMachineInstanceLister);
        $due = $t0->modify('+2 days');
        $this->assertCount(100, $store->findDueForTenant($due, 't'), "{$label}: findDueForTenant() lê 100 por padrão");
        $this->assertCount(100, $runner->findDue($due, tenant: 't'), "{$label}: findDue() do runner com tenant lê 100 por padrão");
        $listed = $store->idsByMachineVersion('flow', 1);
        $this->assertCount(100, $listed, "{$label}: idsByMachineVersion() lê 100 por padrão");
        $this->assertEquals(['100', '101', '102'], array_slice($listed, 0, 3), "{$label}: ids numéricos ficam texto, em ordem");
        $this->assertEquals(['104', '105'], $store->idsByMachineVersion('flow', 1, 2, '103'), "{$label}: página");
        $this->assertEquals(['100'], $store->idsByMachineVersion('flow', 1, 1), "{$label}: limite 1");
        $runner->registerMigration('flow', 1, 2, MachineMigrations::byStateName());
        $this->assertCount(100, $runner->migrateAll('flow', 1, 2, $due), "{$label}: migrateAll() migra 100 por padrão");
        $this->assertCount(6, $runner->migrateAll('flow', 1, 2, $due), "{$label}: o resto na rodada seguinte");
    }

    /** Options of PdoMachineDefinitionRepository and the statement errors it reports. */
    private function testRepositoryOptionsAndStatementErrors(): void
    {
        $pdo = $this->sqliteWith(self::DDL);
        $e = $this->assertThrows(InvalidArgumentException::class, fn() => new PdoMachineDefinitionRepository($pdo, '', ['nope' => 'x']));
        $this->assertTrue(str_contains($e->getMessage(), 'Expected: definition, draft.'), $e->getMessage());
        $e = $this->assertThrows(InvalidArgumentException::class, fn() => new PdoMachineDefinitionRepository($pdo, '', ['draft' => 5]));
        $this->assertTrue(str_contains($e->getMessage(), '"int" (role draft)'), $e->getMessage());
        $e = $this->assertThrows(InvalidArgumentException::class, fn() => new PdoMachineDefinitionRepository($pdo, '', [], ['nope' => 'x']));
        $this->assertTrue(str_contains($e->getMessage(), 'Expected one of: machine_key, version, definition, fingerprint, published_at, published_by, reason, based_on_version, revision, updated_at, updated_by.'), $e->getMessage());
        $e = $this->assertThrows(InvalidArgumentException::class, fn() => new PdoMachineDefinitionRepository($pdo, '', [], ['definition' => 5]));
        $this->assertTrue(str_contains($e->getMessage(), '"int" (canonical definition)'), $e->getMessage());
        $e = $this->assertThrows(InvalidArgumentException::class, fn() => new PdoMachineDefinitionRepository($pdo, '', [], ['definition' => 'a-b']));
        $this->assertTrue(str_contains($e->getMessage(), '"a-b" (canonical definition)'), $e->getMessage());

        $counting = new Module40CountingTransactions(new PdoTransactionManager($pdo));
        $repository = new PdoMachineDefinitionRepository($pdo, transactions: $counting);
        $info = $repository->saveDraft('k', ['key' => 'k'] + $this->contractDefinition(1), null, 'ana', new DateTimeImmutable());
        $repository->publish('k', $info->revision, $this->quoteGuards(), null, 'Counted', 'ana', new DateTimeImmutable());
        $this->assertEquals(2, $counting->count, 'o gerenciador de transações dado é o usado');
        $this->assertTrue($repository->latestVersion('k') === 1);

        $pdo->exec('DROP TABLE state_machine_definition_draft');
        $e = $this->assertThrows(MachinePersistenceException::class, fn() => $repository->draft('k'), 'tabela ausente (modo exceção)');
        $this->assertTrue(str_starts_with($e->getMessage(), 'Machine definition statement failed: ') && str_contains($e->getMessage(), 'no such table') && $e->getSqlState() === 'HY000' && $e->getPrevious() !== null, $e->getMessage());
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $e = $this->assertThrows(MachinePersistenceException::class, fn() => $repository->draft('k'), 'tabela ausente (modo silencioso)');
        $this->assertTrue(str_starts_with($e->getMessage(), 'Machine definition statement failed: [HY000] ') && str_contains($e->getMessage(), 'no such table') && $e->getSqlState() === 'HY000', $e->getMessage());
        $pdo->exec('CREATE TABLE state_machine_definition_draft (machine_key VARCHAR(120) NOT NULL PRIMARY KEY, definition TEXT NOT NULL, based_on_version INTEGER, revision INTEGER NOT NULL, updated_at VARCHAR(26) NOT NULL, updated_by VARCHAR(120))');
        $repository->saveDraft('z', [], null, 'ana', new DateTimeImmutable());
        $e = $this->assertThrows(DefinitionDraftConflictException::class, fn() => $repository->saveDraft('z', [], null, 'bob', new DateTimeImmutable()), 'INSERT recusado pela chave (modo silencioso): conflito');
        $this->assertTrue(str_contains($e->getMessage(), 'already exists'), $e->getMessage());
    }

    /** Details of the PDO instance store: tenant on a compare-and-set, malformed rows, renamed columns. */
    private function testPdoInstanceStoreDetails(): void
    {
        $pdo = $this->sqliteWith(self::DDL);
        $plain = new PdoMachineInstanceStore(new PdoMachineSchema($pdo));
        $t0 = new DateTimeImmutable('2026-10-21T09:00:00Z');
        $snapshot = new MachineSnapshot('k', 1, ['r' => 's']);
        $plain->insert(new MachineInstance('i-1', $snapshot, 1, $t0, $t0));
        $this->assertThrows(MachinePersistenceException::class, fn() => $plain->compareAndSet(new MachineInstance('i-1', $snapshot, 2, $t0, $t0, '7'), 1), 'CAS com tenant sem a coluna: recusado');
        $this->assertEquals(1, $plain->load('i-1')?->version, 'nada gravado');
        $this->assertThrows(MachinePersistenceException::class, fn() => $plain->findDueForTenant($t0, '7'), 'findDueForTenant sem a coluna');

        $pdo->exec("UPDATE state_machine_instance SET snapshot = '{broken' WHERE instance_id = 'i-1'");
        $this->assertThrows(MachinePersistenceException::class, fn() => $plain->load('i-1'), 'snapshot JSON inválido');
        $pdo->exec("UPDATE state_machine_instance SET snapshot = '{\"machine_key\": 1}' WHERE instance_id = 'i-1'");
        $e = $this->assertThrows(MachinePersistenceException::class, fn() => $plain->load('i-1'), 'snapshot malformado');
        $this->assertTrue(str_contains($e->getMessage(), 'is malformed') && $e->getPrevious() !== null, $e->getMessage());

        $renamed = new PDO('sqlite::memory:');
        $renamed->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::loadDdl($renamed, str_replace(['due_at', 'tenant'], ['deadline_at', 'unit_id'], (string) file_get_contents(self::DDL)));
        $definitions = new InMemoryMachineDefinitionRepository([$this->tenantQuote(1)]);
        $persistence = MachinePersistence::pdo($renamed, columns: ['due_at' => 'deadline_at', 'tenant' => 'unit_id', 'current_state_code' => 'current_state_code'], definitions: $definitions);
        $runner = new MachineRunner(Spec::stateMachine(), $definitions, $persistence);
        $runner->start('q', 'quote', 1, null, $t0, tenant: '7');
        $runner->send('q', Event::external('COMPLETE_CALCULATION', $t0), tenant: '7');
        $this->assertEquals(['q'], array_map(static fn(DueDeadline $d): string => $d->instanceId, $runner->findDue($t0->modify('+3 days'), tenant: '7')), 'colunas renomeadas: due_at e tenant pelo mapa');
        $this->assertEquals(['q'], array_map(static fn(DueDeadline $d): string => $d->instanceId, $runner->findDue($t0->modify('+3 days'))));
        $this->assertEquals('7', $renamed->query("SELECT unit_id FROM state_machine_region WHERE instance_id = 'q' AND region = 'progress'")->fetchColumn());
        $this->assertEquals(['tenant', 'current_state_code', 'from_state_code', 'to_state_code'], PdoMachineSchema::optionalColumns());
    }

    private function persistenceOver(IMachineInstanceStore&IInMemoryTransactional $store): MachinePersistence
    {
        $history = new InMemoryMachineHistoryStore();
        $outbox = new InMemoryEffectOutbox();
        $idempotency = new InMemoryIdempotencyStore();

        return new MachinePersistence($store, $history, $outbox, $idempotency, new InMemoryTransactionManager([$store, $history, $outbox, $idempotency]));
    }

    // ==========================================
    // L3: tenant
    // ==========================================

    private function testTenantOnInstanceAndDeadline(): void
    {
        $t0 = new DateTimeImmutable('2026-10-21T09:00:00-03:00');
        $snapshot = new MachineSnapshot('k', 1, ['r' => 's']);
        foreach (['', ' 7', '7 ', str_repeat('x', 121)] as $bad) {
            $this->assertThrows(InvalidArgumentException::class, fn() => new MachineInstance('i', $snapshot, 1, $t0, $t0, $bad), 'tenant inválido: "' . substr($bad, 0, 10) . '"');
        }
        $long = str_repeat('é', 120);
        $this->assertEquals($long, (new MachineInstance('i', $snapshot, 1, $t0, $t0, $long))->tenant, '120 caracteres (multibyte) cabem');
        $instance = new MachineInstance('i', $snapshot, 1, $t0, $t0, '7');
        $next = $instance->next($snapshot, $t0->modify('+1 hour'));
        $this->assertEquals(['7', 2], [$next->tenant, $next->version], 'next() mantém o tenant');
        $this->assertEquals('7', $instance->toArray()['tenant']);
        $due = new DueDeadline('i', 'k', 'r', 's', $t0, '7');
        $this->assertEquals(['7', '2026-10-21 12:00:00.000000'], [$due->toArray()['tenant'], $due->toArray()['due_at']]);

        foreach (['pgsql' => '"tenant"', 'mysql' => '`tenant`', 'sqlite' => '"tenant"'] as $dialect => $quoted) {
            $where = Spec::toSql(DueDeadline::specification($t0, 'k', 'unit-7'), $dialect);
            $this->assertTrue(str_contains($where->getSql(), $quoted), "{$dialect}: o SQL tem o predicado de tenant ({$where->getSql()})");
            $this->assertTrue(in_array('unit-7', $where->getParameters(), true), "{$dialect}: o tenant vai como parâmetro");
            $this->assertFalse(str_contains(Spec::toSql(DueDeadline::specification($t0, 'k'), $dialect)->getSql(), 'tenant'), "{$dialect}: sem tenant, sem predicado");
        }
    }

    /**
     * @param Closure(IMachineDefinitionRepository): MachinePersistence $persistenceFactory
     */
    private function testRunnerWithTenants(string $label, Closure $persistenceFactory): void
    {
        $quote = $this->tenantQuote(1);
        $repository = new InMemoryMachineDefinitionRepository([$quote, $this->tenantQuote(2)]);
        $persistence = $persistenceFactory($repository);
        $runner = new MachineRunner(Spec::stateMachine(), $repository, $persistence);
        $t0 = new DateTimeImmutable('2026-10-21T09:00:00-03:00');

        $this->assertThrows(InvalidArgumentException::class, fn() => $runner->start('a', 'quote', 1, null, $t0, tenant: ' 7'), "{$label}: tenant inválido");
        $this->assertThrows(MachineInstanceNotFoundException::class, fn() => $runner->load('a'), "{$label}: nada gravado");
        $runner->start('a', 'quote', 1, null, $t0, 'ana', '7');
        $runner->start('b', 'quote', 1, null, $t0, 'bob', '12');
        $runner->start('c', 'quote', 1, null, $t0, 'ops');
        $this->assertThrows(MachineInstanceAlreadyExistsException::class, fn() => $runner->start('a', 'quote', 1, null, $t0, tenant: '12'), "{$label}: o id é global");
        $this->assertEquals(['7', '12', null], [$runner->load('a')->tenant, $runner->load('b')->tenant, $runner->load('c')->tenant], "{$label}: tenant gravado");

        $calc = Event::external('COMPLETE_CALCULATION', $t0->modify('+1 hour'));
        foreach ([
            'send' => fn() => $runner->send('a', $calc, tenant: '12'),
            'load' => fn() => $runner->load('a', '12'),
            'can' => fn() => $runner->can('a', $calc, null, '12'),
            'nextEvents' => fn() => $runner->nextEvents('a', null, $t0, '12'),
            'migrate' => fn() => $runner->migrate('a', 2, $t0, tenant: '12'),
            'sem tenant' => fn() => $runner->load('c', '7'),
            'inexistente' => fn() => $runner->load('zz', '7'),
        ] as $what => $call) {
            $e = $this->assertThrows(MachineInstanceNotFoundException::class, $call, "{$label}: {$what} de outro tenant = inexistente");
            $this->assertTrue(str_contains($e->getMessage(), 'does not exist'), "{$label}: {$what}: a mesma mensagem de id inexistente");
        }
        $this->assertEquals(1, $runner->load('a')->version, "{$label}: nada mudou");
        $this->assertTrue($runner->can('a', $calc, null, '7')->isAccepted(), "{$label}: can do dono");
        $this->assertTrue(isset($runner->nextEvents('a', null, $t0, '7')['COMPLETE_CALCULATION']));
        $this->assertTrue($runner->send('a', $calc, null, 'ana', null, '7')->isAccepted(), "{$label}: send do dono");
        $runner->send('b', $calc, tenant: '12');
        $runner->send('c', $calc);
        $this->assertEquals(2, $runner->load('a', '7')->version);

        $late = $t0->modify('+3 days');
        $ids = static fn(array $due): array => array_map(static fn(DueDeadline $d): string => $d->instanceId . '@' . ($d->tenant ?? '-'), $due);
        $this->assertEquals(['a@7', 'b@12', 'c@-'], $ids($runner->findDue($late)), "{$label}: findDue global com o tenant de cada um");
        $this->assertEquals(['a@7'], $ids($runner->findDue($late, tenant: '7')));
        $this->assertEquals(['b@12'], $ids($runner->findDue($late, 'quote', 10, '12')));
        $this->assertEquals([], $runner->findDue($late, tenant: 'nobody'));
        $deliveries = $runner->deliverDue($late, actor: 'scheduler', tenant: '12');
        $this->assertEquals(['b'], array_map(static fn($d): string => $d->deadline->instanceId, $deliveries), "{$label}: deliverDue só do tenant");
        $this->assertTrue($deliveries[0]->isDelivered());
        $this->assertEquals('expired', $runner->load('b')->snapshot->stateOf('progress'));
        $this->assertEquals(['a@7', 'c@-'], $ids($runner->findDue($late)), "{$label}: os outros continuam vencidos");

        $runner->registerMigration('quote', 1, 2, MachineMigrations::byStateName());
        $this->assertEquals('7', $runner->migrate('a', 2, $late, 'ana', null, '7')->tenant, "{$label}: migrate do dono mantém o tenant");
        $this->assertTrue($runner->deliverDue($late, tenant: '7')[0]->isDelivered(), "{$label}: deliverDue com tenant depois da migração");
    }

    /** The quote of the tenant tests: a deadline after the calculation. */
    private function tenantQuote(int $version): MachineDefinition
    {
        return Spec::machine('quote', $version)
            ->region('progress', 'registering')
                ->state('registering')->code(1)->on('COMPLETE_CALCULATION', 'calculation_done')
                ->state('calculation_done')->code(2)->deadline('P2D', 'EXPIRE')->on('SEND_PROPOSAL', 'proposal_sent')->on('EXPIRE', 'expired')
                ->finalState('proposal_sent')->code(8)
                ->finalState('expired')->code(13)
            ->region('pending', 'none')
                ->state('none')->on('REGISTER_PENDING', 'open')
                ->state('open')->code(20)->on('RESOLVE_PENDING', 'none')
            ->build();
    }

    private function testTenantRequiresAScopedStore(): void
    {
        $repository = new InMemoryMachineDefinitionRepository([$this->tenantQuote(1)]);
        $runner = new MachineRunner(Spec::stateMachine(), $repository, $this->persistenceOver(new Module40PlainStore(new InMemoryMachineInstanceStore())));
        $t0 = new DateTimeImmutable('2026-10-21T09:00:00Z');
        $runner->start('p', 'quote', 1, null, $t0, tenant: '7');
        $this->assertEquals('7', $runner->load('p')->tenant, 'o store do app guarda a instância como recebe');
        foreach ([
            'send' => fn() => $runner->send('p', Event::external('COMPLETE_CALCULATION', $t0), tenant: '7'),
            'findDue' => fn() => $runner->findDue($t0, tenant: '7'),
            'deliverDue' => fn() => $runner->deliverDue($t0, tenant: '7'),
            'load' => fn() => $runner->load('p', '7'),
        ] as $what => $call) {
            $e = $this->assertThrows(StateMachineException::class, $call, "{$what}: tenant pedido a store sem ITenantScopedInstanceStore");
            $this->assertTrue(str_contains($e->getMessage(), 'ITenantScopedInstanceStore'), "{$what}: a mensagem nomeia a interface");
            $this->assertFalse($e instanceof MachineInstanceNotFoundException);
        }
        $this->assertTrue($runner->send('p', Event::external('COMPLETE_CALCULATION', $t0))->isAccepted(), 'sem tenant: como antes');
    }

    /**
     * RN-03: the isolation property must catch a store that ignores the tenant.
     */
    private function testIsolationNegativeControl(): void
    {
        $inner = new InMemoryMachineInstanceStore();
        $runner = new MachineRunner(Spec::stateMachine(), new InMemoryMachineDefinitionRepository([$this->isolationMachine()]), $this->persistenceOver($inner));
        $nows = $this->populateIsolation($runner, 46);
        $this->assertEquals([], $this->isolationViolations($inner, $nows), 'o InMemory não viola');
        $leaky = $this->isolationViolations(new Module40LeakyTenantStore($inner), $nows);
        $this->assertTrue(count($leaky) > 10, 'controle negativo: um store que ignora o tenant falha a propriedade (' . count($leaky) . ' violações)');
    }

    private function isolationMachine(): MachineDefinition
    {
        return Spec::machine('iso', 1)
            ->region('main', 'open')
                ->state('open')->deadline(event: 'EXPIRE', afterFrom: 'context.due')->on('EXPIRE', 'expired')->on('CLOSE', 'closed')
                ->finalState('expired')
                ->finalState('closed')
            ->region('side', 'idle')
                ->state('idle')->deadline(event: 'PING', afterFrom: 'context.ping')->on('PING', 'idle', 'side_ping')
            ->build();
    }

    /**
     * Starts instances of the isolation machine over three tenants and none, with random deadlines,
     * closing some; returns the instants to check.
     *
     * @return list<DateTimeImmutable>
     */
    private function populateIsolation(MachineRunner $runner, int $seed, int $count = 60): array
    {
        $random = new Randomizer(new Mt19937($seed));
        $t0 = new DateTimeImmutable('2026-10-01T00:00:00Z');
        $tenants = ['t1', 't2', 't3', null];
        for ($i = 1; $i <= $count; $i++) {
            $start = $t0->modify('+' . $random->getInt(0, 3000) . ' minutes');
            $context = ['due' => 'PT' . $random->getInt(1, 5000) . 'M', 'ping' => 'PT' . $random->getInt(1, 5000) . 'M'];
            $runner->start(sprintf('iso-%03d', $i), 'iso', 1, $context, $start, tenant: $tenants[$random->getInt(0, 3)]);
            if ($random->getInt(0, 4) === 0) {
                $runner->send(sprintf('iso-%03d', $i), Event::external('CLOSE', $start->modify('+1 minute')), $context);
            }
        }
        $nows = [];
        for ($k = 0; $k < 25; $k++) {
            $nows[] = $t0->modify('+' . $random->getInt(0, 9000) . ' minutes');
        }

        return $nows;
    }

    /**
     * For every instant and tenant: findDueForTenant() = findDue() filtered by the tenant, in the same
     * order, never another tenant; with a machine key too; loadForTenant() never crosses tenants.
     *
     * @param list<DateTimeImmutable> $nows
     * @return list<string> Violations
     */
    private function isolationViolations(IMachineInstanceStore&ITenantScopedInstanceStore $store, array $nows): array
    {
        $violations = [];
        foreach ($nows as $now) {
            $global = array_map(static fn(DueDeadline $d): array => $d->toArray(), $store->findDue($now, null, 10000));
            foreach (['t1', 't2', 't3', 'nobody'] as $tenant) {
                foreach ([null, 'iso'] as $key) {
                    $scoped = array_map(static fn(DueDeadline $d): array => $d->toArray(), $store->findDueForTenant($now, $tenant, $key, 10000));
                    $expected = array_values(array_filter($global, static fn(array $d): bool => ($d['tenant'] ?? null) === $tenant));
                    if ($scoped !== $expected) {
                        $violations[] = sprintf('%s %s %s: %d found, %d expected', PersistenceSupport::toText($now), $tenant, $key ?? '*', count($scoped), count($expected));
                    }
                }
            }
        }
        for ($i = 1; $i <= 60; $i++) {
            $id = sprintf('iso-%03d', $i);
            $owner = $store->load($id)?->tenant;
            foreach (['t1', 't2', 't3'] as $tenant) {
                $loaded = $store->loadForTenant($id, $tenant);
                if (($loaded !== null) !== ($owner === $tenant)) {
                    $violations[] = "{$id} loaded for {$tenant} (owner " . ($owner ?? 'none') . ')';
                }
            }
        }

        return $violations;
    }

    // ==========================================
    // PDO legs (SQLite always; PostgreSQL, MySQL and MariaDB by environment)
    // ==========================================

    /**
     * @param Closure(): PDO $connect A new connection to the same database at each call
     */
    private function pdoLeg(string $family, Closure $connect): void
    {
        $pdo = $connect();
        $label = "PDO/{$family}";

        $this->resetSchema($pdo, self::DDL);
        $this->testDefinitionRoundTrip($family, $pdo, $family === 'sqlite' ? 60 : 20);
        $this->testTampering($family, $pdo);
        $this->runDraftContract($label, static fn(): PdoMachineDefinitionRepository => new PdoMachineDefinitionRepository($pdo));
        $this->testConcurrentPublication($family, $connect);

        $this->resetSchema($pdo, self::DDL);
        $mapped = static fn(IMachineDefinitionRepository $d): MachinePersistence => MachinePersistence::pdo($pdo, columns: PdoMachineSchema::COLUMNS_SINCE_2_7, definitions: $d);
        $this->testRunnerWithTenants($label, $mapped);
        $this->resetSchema($pdo, self::DDL);
        $definitions = new PdoMachineDefinitionRepository($pdo);
        $this->testByStateNameAndMigrateAll($label, static fn(): MachinePersistence => MachinePersistence::pdo($pdo, columns: PdoMachineSchema::COLUMNS_SINCE_2_7, definitions: $definitions), $definitions);
        $this->resetSchema($pdo, self::DDL);
        $this->testCodeProjection($label, $pdo);
        $this->resetSchema($pdo, self::DDL);
        $this->testIsolationInMemoryEqualsPdo($label, $pdo);
        $this->resetSchema($pdo, self::DDL);
        $this->testAcceptance($label, $pdo);
        $this->testDdlOf26($family, $pdo);
        $this->dropSchema($pdo);
    }

    /**
     * RN-02 (definição PDO): generated machines (codes of the three kinds, deadlines with and without
     * pause) and the real fixtures come back from the database with the same fingerprint and array form,
     * by register() and by draft + publish(); registering again is a no-op, another content under the
     * pair is refused.
     */
    private function testDefinitionRoundTrip(string $family, PDO $pdo, int $count): void
    {
        $writer = new PdoMachineDefinitionRepository($pdo);
        $guards = $this->quoteGuards();
        $at = new DateTimeImmutable('2026-10-21T09:00:00Z');
        $definitions = [$this->v1(), $this->v2()];
        for ($seed = 1; $seed <= $count; $seed++) {
            $definitions[] = MachineDefinition::fromArray(self::randomMachine($seed * 31, "rt_{$seed}"));
        }
        $published = 0;
        foreach ($definitions as $n => $definition) {
            $writer->register($definition);
            $writer->register($definition);
            $reader = new PdoMachineDefinitionRepository($pdo);
            $read = $reader->get($definition->key, $definition->version);
            $this->assertEquals($definition->fingerprint(), $read->fingerprint(), "{$family}: {$definition->key} volta com o mesmo fingerprint");
            $this->assertTrue(DefinitionSupport::canonicalize($definition->toArray()) === DefinitionSupport::canonicalize($read->toArray()), "{$family}: {$definition->key} volta com a mesma forma de array canônica (tipos dos códigos inclusive)");
            $this->assertEquals($definition->version, $reader->latestVersion($definition->key));

            // The same content published through a draft under another key
            $key = "pub_{$n}";
            $array = $definition->toArray();
            unset($array['key'], $array['version']);
            $info = $writer->saveDraft($key, $array, null, 'gen', $at);
            $this->assertTrue($writer->draft($key, $guards)?->definition === $array, "{$family}: {$key}: o rascunho volta igual");
            $result = $writer->publish($key, $info->revision, $guards, null, 'Round trip', 'gen', $at);
            $expected = MachineDefinition::fromArray(['key' => $key, 'version' => 1] + $array);
            $this->assertEquals($expected->fingerprint(), (new PdoMachineDefinitionRepository($pdo))->get($key, 1)->fingerprint(), "{$family}: {$key}: publicado = o rascunho com chave e versão");
            $this->assertEquals($expected->fingerprint(), $result->definition->fingerprint());
            $published++;
        }
        $v2 = $this->v2()->toArray();
        $v2['regions'][0]['states'][0]['code'] = 99;
        $e = $this->assertThrows(InvalidMachineDefinitionException::class, fn() => $writer->register(MachineDefinition::fromArray($v2)), "{$family}: outro conteúdo no mesmo par");
        $this->assertEquals([MachineDefinitionError::KIND_DUPLICATE], $e->getErrorKinds());
        $text = (string) $pdo->query("SELECT definition FROM state_machine_definition WHERE machine_key = '" . self::QUOTE_KEY . "' AND version = 2")->fetchColumn();
        $this->assertTrue(strlen($text) > 4000, "{$family}: a definição real passa de VARCHAR(4000) (" . strlen($text) . ' bytes) e cabe no TEXT');
        fwrite(STDOUT, "    [046 RN-02 L2] {$family}: " . count($definitions) . " definições (as 2 fixtures reais + " . ($count) . " geradas) ida e volta por register() e por rascunho ({$published} publicações)\n");
    }

    /**
     * A row changed outside the repository never runs; RN-03: a repository that does not check the
     * fingerprint on read lets it through.
     */
    private function testTampering(string $family, PDO $pdo): void
    {
        $definition = $this->v1();
        $where = " WHERE machine_key = '" . self::QUOTE_KEY . "' AND version = 1";
        $original = (string) $pdo->query('SELECT definition FROM state_machine_definition' . $where)->fetchColumn();
        $fingerprint = (string) $pdo->query('SELECT fingerprint FROM state_machine_definition' . $where)->fetchColumn();
        $this->assertEquals($definition->fingerprint(), $fingerprint, "{$family}: a coluna guarda o fingerprint");
        $this->assertEquals(hash('sha256', $original), $fingerprint, "{$family}: o texto gravado é o JSON canônico");

        $array = json_decode($original, true);
        $array['regions'][0]['states'][0]['transitions'][0]['target'] = 'cancelled';
        $tampered = DefinitionSupport::canonicalJson($array);
        $update = $pdo->prepare('UPDATE state_machine_definition SET definition = :d' . $where);
        $update->execute([':d' => $tampered]);
        $e = $this->assertThrows(MachinePersistenceException::class, fn() => (new PdoMachineDefinitionRepository($pdo))->get(self::QUOTE_KEY, 1), "{$family}: linha adulterada recusada");
        $this->assertTrue(str_contains($e->getMessage(), 'fingerprint'), "{$family}: a mensagem fala do fingerprint");
        $trusting = (new Module40TrustingRepository($pdo))->get(self::QUOTE_KEY, 1);
        $this->assertFalse($trusting->fingerprint() === $fingerprint, "{$family}: controle negativo: sem a conferência, a linha adulterada passaria");
        $runner = new MachineRunner(Spec::stateMachine($this->quoteGuards()), new PdoMachineDefinitionRepository($pdo), MachinePersistence::inMemory());
        $this->assertThrows(MachinePersistenceException::class, fn() => $runner->start('x', self::QUOTE_KEY, 1, null, new DateTimeImmutable()), "{$family}: o runner não roda a linha adulterada");

        $update->execute([':d' => $original]);
        $this->assertEquals($fingerprint, (new PdoMachineDefinitionRepository($pdo))->get(self::QUOTE_KEY, 1)->fingerprint(), "{$family}: restaurada, volta a valer");
        $pdo->exec("UPDATE state_machine_definition SET fingerprint = '" . str_repeat('0', 64) . "'" . $where);
        $this->assertThrows(MachinePersistenceException::class, fn() => (new PdoMachineDefinitionRepository($pdo))->get(self::QUOTE_KEY, 1), "{$family}: fingerprint adulterado recusado");
        $pdo->exec("UPDATE state_machine_definition SET fingerprint = '{$fingerprint}'" . $where);

        $insert = $pdo->prepare('INSERT INTO state_machine_definition (machine_key, version, definition, fingerprint) VALUES (:k, :v, :d, :f)');
        $insert->execute([':k' => 'copied', ':v' => 1, ':d' => $original, ':f' => $fingerprint]);
        $e = $this->assertThrows(MachinePersistenceException::class, fn() => (new PdoMachineDefinitionRepository($pdo))->get('copied', 1), "{$family}: linha com a definição de outra máquina");
        $this->assertTrue(str_contains($e->getMessage(), 'holds machine'));
        $insert->execute([':k' => 'broken', ':v' => 1, ':d' => '{not json', ':f' => $fingerprint]);
        $this->assertThrows(MachinePersistenceException::class, fn() => (new PdoMachineDefinitionRepository($pdo))->get('broken', 1), "{$family}: JSON inválido");
        $insert->execute([':k' => 'malformed', ':v' => 1, ':d' => '{"key":"malformed","version":1,"regions":[]}', ':f' => $fingerprint]);
        $this->assertThrows(MachinePersistenceException::class, fn() => (new PdoMachineDefinitionRepository($pdo))->get('malformed', 1), "{$family}: definição que não monta");
        $this->assertThrows(UnknownMachineDefinitionException::class, fn() => (new PdoMachineDefinitionRepository($pdo))->get('nope', 1), "{$family}: par desconhecido");
        $this->assertFalse((new PdoMachineDefinitionRepository($pdo))->has('nope', 1));
        $this->assertTrue((new PdoMachineDefinitionRepository($pdo))->latestVersion('nope') === null);
    }

    /**
     * RN-02 (concorrência): two screens on two connections; one publication wins, the other gets a
     * conflict; and a publication that loses the version to another one in the middle of its
     * transaction (a guard factory publishes from the other side while the validator runs).
     *
     * @param Closure(): PDO $connect
     */
    private function testConcurrentPublication(string $family, Closure $connect): void
    {
        $a = $connect();
        $b = $connect();
        $screenA = new PdoMachineDefinitionRepository($a);
        $screenB = new PdoMachineDefinitionRepository($b);
        $guards = $this->quoteGuards();
        $at = new DateTimeImmutable('2026-10-21T09:00:00Z');
        $key = 'race';
        $definition = $this->contractDefinition(1);
        unset($definition['key']);

        $info = $screenA->saveDraft($key, $definition, null, 'ana', $at);
        $readByB = $screenB->draft($key, $guards);
        $this->assertTrue($readByB !== null && $readByB->revision() === $info->revision, "{$family}: B lê a mesma revisão por outra conexão");
        $this->assertEquals(1, $screenA->publish($key, $info->revision, $guards, null, 'A wins', 'ana', $at)->version());
        $this->assertThrows(DefinitionDraftConflictException::class, fn() => $screenB->publish($key, $readByB->revision(), $guards, null, 'B loses', 'bob', $at), "{$family}: a segunda publicação recebe conflito");
        $this->assertEquals(1, $screenB->latestVersion($key), "{$family}: uma versão só");

        $infoA = $screenA->saveDraft($key, $definition, null, 'ana', $at);
        $infoB = $screenB->saveDraft($key, $definition, $infoA->revision, 'bob', $at);
        $this->assertThrows(DefinitionDraftConflictException::class, fn() => $screenA->saveDraft($key, $definition, $infoA->revision, 'ana', $at), "{$family}: salvamento sobre revisão velha");
        $this->assertThrows(DefinitionDraftConflictException::class, fn() => $screenA->discardDraft($key, $infoA->revision), "{$family}: descarte de revisão velha");
        $this->assertEquals('bob', $screenA->draft($key, $guards)?->info->updatedBy, "{$family}: vale o de B");

        // The version taken in the middle of the transaction
        $other = $family === 'sqlite' ? new PdoMachineDefinitionRepository($a) : $screenB;
        $fired = false;
        $hooked = $this->quoteGuards()->register('raceHook', function (array $params) use (&$fired, $other, $key, $definition): ISpecification {
            if (!$fired) {
                $fired = true;
                $other->register(MachineDefinition::fromArray(['key' => $key, 'version' => 2] + $definition));
            }

            return Spec::alwaysTrue();
        });
        $withHook = $definition;
        $withHook['regions'][0]['states'][0]['transitions'][0]['guard'] = ['guard' => 'raceHook'];
        $info = $screenA->saveDraft($key, $withHook, $infoB->revision, 'ana', $at);
        $e = $this->assertThrows(DefinitionDraftConflictException::class, fn() => $screenA->publish($key, $info->revision, $hooked, null, 'Raced', 'ana', $at), "{$family}: a versão tomada no meio vira conflito");
        $this->assertTrue($fired && str_contains($e->getMessage(), 'published by someone else first'), "{$family}: a mensagem diz que outro publicou antes");
        $this->assertEquals($info->revision, $screenA->draft($key, $guards)?->revision(), "{$family}: o rascunho continua lá (transação desfeita)");
        $this->assertFalse($a->inTransaction(), "{$family}: nenhuma transação pendurada");
        if ($family === 'sqlite') {
            $this->assertEquals(1, $screenA->latestVersion($key), 'sqlite: a mesma conexão: a publicação do gancho foi desfeita junto');
        } else {
            $this->assertEquals(2, $screenA->latestVersion($key), "{$family}: a outra conexão confirmou a v2");
            $this->assertEquals(3, $screenA->publish($key, $info->revision, $hooked, null, 'Retry', 'ana', $at)->version(), "{$family}: a nova tentativa pega a v3");
        }

        // Joining a transaction already open on the connection: the owner decides
        $a->beginTransaction();
        $joined = $screenA->saveDraft('joined', $definition, null, 'ana', $at);
        $screenA->publish('joined', $joined->revision, $guards, null, 'Inside', 'ana', $at);
        $this->assertTrue($a->inTransaction(), "{$family}: a transação do dono continua aberta");
        $a->rollBack();
        $this->assertTrue((new PdoMachineDefinitionRepository($a))->latestVersion('joined') === null, "{$family}: o rollback do dono desfaz a publicação");
    }

    /** get() remembers each pair per instance (a published pair never changes); latestVersion() always reads. */
    private function testRepositoryMemoizesGet(): void
    {
        $pdo = new PdoRecordingPdo('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::loadDdl($pdo, (string) file_get_contents(self::DDL));
        $repository = new PdoMachineDefinitionRepository($pdo);
        $repository->register($this->v1());
        $reader = new PdoMachineDefinitionRepository($pdo);
        $pdo->reset();
        $first = $reader->get(self::QUOTE_KEY, 1);
        $this->assertCount(1, $pdo->statements, 'uma leitura');
        $this->assertTrue($reader->get(self::QUOTE_KEY, 1) === $first && $reader->has(self::QUOTE_KEY, 1), 'mesma instância, sem ir ao banco');
        $this->assertCount(1, $pdo->statements, 'o runner deixa de ir ao banco a cada evento');
        $reader->latestVersion(self::QUOTE_KEY);
        $reader->latestVersion(self::QUOTE_KEY);
        $this->assertCount(3, $pdo->statements, 'latestVersion() sempre lê');

        $this->assertThrows(InvalidArgumentException::class, fn() => new PdoMachineDefinitionRepository($pdo, 'bad schema'));
        $this->assertThrows(InvalidArgumentException::class, fn() => new PdoMachineDefinitionRepository($pdo, '', ['nope' => 'x']));
        $this->assertThrows(InvalidArgumentException::class, fn() => new PdoMachineDefinitionRepository($pdo, '', ['draft' => 'x;']));
        $this->assertThrows(InvalidArgumentException::class, fn() => new PdoMachineDefinitionRepository($pdo, '', [], ['nope' => 'x']));
        $this->assertThrows(InvalidArgumentException::class, fn() => new PdoMachineDefinitionRepository($pdo, '', [], ['fingerprint' => 'a-b']));
        $named = new PdoMachineDefinitionRepository($pdo, 'wf', ['definition' => 'machine_version'], ['definition' => 'body']);
        $this->assertEquals(['definition' => 'wf.machine_version', 'draft' => 'wf.state_machine_definition_draft'], $named->getTables());
        $this->assertEquals('body', $named->getColumns()['definition']);

        // Other table and column names, read and written
        $renamed = new PDO('sqlite::memory:');
        $renamed->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $renamed->exec('CREATE TABLE wf_definition (process_key VARCHAR(120) NOT NULL, version INTEGER NOT NULL, body TEXT NOT NULL, fingerprint CHAR(64) NOT NULL, published_at VARCHAR(26), published_by VARCHAR(120), reason VARCHAR(2000), PRIMARY KEY (process_key, version))');
        $renamed->exec('CREATE TABLE wf_draft (process_key VARCHAR(120) NOT NULL PRIMARY KEY, body TEXT NOT NULL, based_on_version INTEGER, revision INTEGER NOT NULL, updated_at VARCHAR(26) NOT NULL, updated_by VARCHAR(120))');
        $repository = new PdoMachineDefinitionRepository($renamed, '', ['definition' => 'wf_definition', 'draft' => 'wf_draft'], ['machine_key' => 'process_key', 'definition' => 'body']);
        $repository->register($this->v1());
        $info = $repository->saveDraft('k', ['key' => 'k'] + $this->contractDefinition(1), null, 'ana', new DateTimeImmutable());
        $this->assertTrue($repository->draft('k') !== null, 'rascunho em tabela e coluna renomeadas');
        $this->assertEquals(1, $repository->publish('k', $info->revision, $this->quoteGuards(), null, 'Renamed', 'ana', new DateTimeImmutable())->version());
        $this->assertEquals(self::FINGERPRINT_V1, (new PdoMachineDefinitionRepository($renamed, '', ['definition' => 'wf_definition', 'draft' => 'wf_draft'], ['machine_key' => 'process_key', 'definition' => 'body']))->get(self::QUOTE_KEY, 1)->fingerprint());
    }

    /**
     * RN-04: the code projection in SQL equals the codes the definition gives in memory, for the regions
     * and the history; integer codes as integers.
     */
    private function testCodeProjection(string $label, PDO $pdo): void
    {
        $repository = new PdoMachineDefinitionRepository($pdo);
        foreach ($this->flowVersions() as $definition) {
            $repository->register($definition);
        }
        $repository->register($this->tenantQuote(1));
        $this->assertThrows(InvalidArgumentException::class, fn() => MachinePersistence::pdo($pdo, columns: PdoMachineSchema::COLUMNS_SINCE_2_7), "{$label}: códigos mapeados sem o repositório: recusado na construção");
        $this->assertThrows(InvalidArgumentException::class, fn() => MachinePersistence::pdo($pdo, columns: ['from_state_code' => 'from_state_code']), "{$label}: só uma coluna de código do histórico também");
        $this->assertThrows(InvalidArgumentException::class, fn() => new PdoMachineInstanceStore(new PdoMachineSchema($pdo, columns: ['current_state_code' => 'current_state_code'])), "{$label}: o store de instâncias sozinho também recusa");
        $this->assertTrue(MachinePersistence::pdo($pdo)->rejections === null, "{$label}: sem log de rejeições por padrão");
        $tenantOnly = MachinePersistence::pdo($pdo, columns: ['tenant' => 'tenant']);
        $this->assertTrue($tenantOnly->instances instanceof ITenantScopedInstanceStore, "{$label}: só o tenant não precisa do repositório");

        $persistence = MachinePersistence::pdo($pdo, columns: PdoMachineSchema::COLUMNS_SINCE_2_7, definitions: $repository);
        $runner = new MachineRunner(Spec::stateMachine(), $repository, $persistence);
        $t0 = new DateTimeImmutable('2026-10-21T09:00:00Z');
        $runner->start('p-1', 'quote', 1, null, $t0, tenant: '7');
        $this->assertEquals('N', $pdo->query("SELECT done FROM state_machine_instance WHERE instance_id = 'p-1'")->fetchColumn(), "{$label}: done = N no insert");
        $runner->send('p-1', Event::external('COMPLETE_CALCULATION', $t0), tenant: '7');
        $runner->send('p-1', Event::external('REGISTER_PENDING', $t0), tenant: '7');
        $runner->start('f-1', 'flow', 1, null, $t0);
        $runner->send('f-1', Event::external('GO', $t0));
        $runner->registerMigration('flow', 1, 2, MachineMigrations::byStateName());
        $runner->migrate('f-1', 2, $t0);
        $runner->send('f-1', Event::internal('EXPIRE', $t0->modify('+2 days'), ['from_version' => 1])); // only a migration row reads from_version

        $checked = 0;
        foreach (['p-1' => $this->tenantQuote(1), 'f-1' => $this->flowVersions()[1]] as $id => $definition) {
            $snapshot = $runner->load($id)->snapshot;
            $expected = array_map(static fn($c) => $c === null ? null : DefinitionSupport::normalizeCode($c), $definition->codesOf($snapshot));
            $statement = $pdo->prepare('SELECT region, current_state_code FROM state_machine_region WHERE instance_id = :id ORDER BY sort_order');
            $statement->execute([':id' => $id]);
            $sql = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $sql[$row['region']] = $row['current_state_code'] === null ? null : DefinitionSupport::normalizeCode($row['current_state_code']);
                $checked++;
            }
            $this->assertTrue($sql === $expected, "{$label}: {$id}: projeção SQL = codesOf() (" . json_encode($sql) . ')');
        }
        $this->assertEquals(['progress' => 2, 'pending' => 20], $this->sqlCodes($pdo, 'p-1'), "{$label}: os ids legados no banco");

        $history = $pdo->query("SELECT instance_version, region, from_state, to_state, from_state_code, to_state_code FROM state_machine_history WHERE instance_id = 'f-1' ORDER BY instance_version, sort_order")->fetchAll(PDO::FETCH_NUM);
        $history = array_map(static fn(array $r): array => [(int) $r[0], $r[1], $r[2], $r[3], $r[4] === null ? null : DefinitionSupport::normalizeCode($r[4]), $r[5] === null ? null : DefinitionSupport::normalizeCode($r[5])], $history);
        $this->assertTrue($history === [
            [1, 'main', null, 'a', null, 1],
            [2, 'main', 'a', 'b', 1, 2],
            [3, 'main', 'b', 'b', 2, 22],
            [4, 'main', 'b', 'c', 22, 3],
        ], "{$label}: códigos no histórico, a origem da migração pela versão de saída (" . json_encode($history) . ')');
        $this->assertTrue($checked >= 3);
    }

    /**
     * @return array<string, int|string|null>
     */
    private function sqlCodes(PDO $pdo, string $id): array
    {
        $statement = $pdo->prepare('SELECT region, current_state_code FROM state_machine_region WHERE instance_id = :id ORDER BY sort_order');
        $statement->execute([':id' => $id]);
        $codes = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $codes[$row['region']] = $row['current_state_code'] === null ? null : DefinitionSupport::normalizeCode($row['current_state_code']);
        }

        return $codes;
    }

    /**
     * RN-02 (isolamento): the same random population in memory and in SQL; for every instant and tenant
     * the scoped search is the global one filtered, the two stores answer the same, nothing crosses.
     */
    private function testIsolationInMemoryEqualsPdo(string $label, PDO $pdo): void
    {
        $repository = new InMemoryMachineDefinitionRepository([$this->isolationMachine()]);
        $memory = MachinePersistence::inMemory();
        $sql = MachinePersistence::pdo($pdo, columns: PdoMachineSchema::COLUMNS_SINCE_2_7, definitions: $repository);
        $nowsMemory = $this->populateIsolation(new MachineRunner(Spec::stateMachine(), $repository, $memory), 4046);
        $nowsSql = $this->populateIsolation(new MachineRunner(Spec::stateMachine(), $repository, $sql), 4046);
        $this->assertTrue($nowsMemory == $nowsSql);
        \assert($memory->instances instanceof InMemoryMachineInstanceStore && $sql->instances instanceof ITenantScopedInstanceStore);
        $this->assertEquals([], $this->isolationViolations($memory->instances, $nowsMemory), "{$label}: InMemory isola");
        $this->assertEquals([], $this->isolationViolations($sql->instances, $nowsSql), "{$label}: PDO isola");
        $compared = 0;
        foreach ($nowsMemory as $now) {
            foreach (['t1', 't2', 't3'] as $tenant) {
                $m = array_map(static fn(DueDeadline $d): array => $d->toArray(), $memory->instances->findDueForTenant($now, $tenant, null, 10000));
                $s = array_map(static fn(DueDeadline $d): array => $d->toArray(), $sql->instances->findDueForTenant($now, $tenant, null, 10000));
                $this->assertTrue($m === $s, "{$label}: InMemory = PDO em " . PersistenceSupport::toText($now) . " para {$tenant}");
                $compared += count($m);
            }
            $this->assertTrue(
                array_map(static fn(DueDeadline $d): array => $d->toArray(), $memory->instances->findDue($now, null, 10000))
                === array_map(static fn(DueDeadline $d): array => $d->toArray(), $sql->instances->findDue($now, null, 10000)),
                "{$label}: findDue global InMemory = PDO, com o tenant de cada prazo"
            );
            $limited = $sql->instances->findDueForTenant($now, 't1', null, 2);
            $this->assertTrue(count($limited) <= 2);
        }
        $this->assertTrue($compared > 100, "{$label}: a propriedade compara prazos de verdade ({$compared})");
        fwrite(STDOUT, "    [046 RN-02 L3] {$label}: 60 instâncias × 4 tenants (um nulo) × 25 instantes, {$compared} prazos por tenant iguais em memória e SQL, nenhum cruzado\n");
    }

    /**
     * "DDL da 2.6 sem mapeamento roda o runner": a database created by the 2.6 script, the stores without
     * the new columns: start, send, deadline pause and resume, deliverDue, migration, history and outbox
     * work as before; a tenant is refused naming the column; then the additive migration of the DDL
     * header, as written, and the mapped stores work over the old rows.
     */
    private function testDdlOf26(string $family, PDO $pdo): void
    {
        $this->resetSchema($pdo, self::DDL_2_6);
        $ddl26 = (string) file_get_contents(self::DDL_2_6);
        $this->assertFalse(str_contains($ddl26, 'tenant') || str_contains($ddl26, 'state_machine_definition'), 'a fixture é o DDL da 2.6 (sem as colunas e tabelas novas)');

        $repository = new InMemoryMachineDefinitionRepository([...$this->flowVersions(), $this->tenantQuote(1), $this->pausingMachine()]);
        $runner = new MachineRunner(Spec::stateMachine(), $repository, MachinePersistence::pdo($pdo));
        $t0 = new DateTimeImmutable('2026-10-21T09:00:00Z');
        $runner->start('old-1', 'flow', 1, null, $t0, 'ana');
        $this->assertTrue($runner->send('old-1', Event::external('GO', $t0->modify('+1 hour')), actor: 'ana')->isAccepted(), "{$family} 2.6: send");
        $this->assertCount(1, $runner->findDue($t0->modify('+2 days')), "{$family} 2.6: findDue");
        $runner->start('old-2', 'pause', 1, null, $t0);
        $runner->send('old-2', Event::external('HOLD', $t0->modify('+1 hour')));
        $this->assertEquals(82800, $runner->load('old-2')->snapshot->pausedRemaining('main'), "{$family} 2.6: pausa");
        $runner->send('old-2', Event::external('RELEASE', $t0->modify('+5 hours')));
        $this->assertEquals('2026-10-22 13:00:00.000000', PersistenceSupport::toText($runner->load('old-2')->snapshot->dueAt('main') ?? $t0), "{$family} 2.6: retomada");
        $this->assertEquals(['old-1'], array_map(static fn($d): string => $d->deadline->instanceId, array_filter($runner->deliverDue($t0->modify('+2 days'), 'flow'), static fn($d): bool => $d->isDelivered())), "{$family} 2.6: deliverDue");
        $runner->start('old-3', 'flow', 1, null, $t0);
        $runner->registerMigration('flow', 1, 2, MachineMigrations::byStateName());
        $this->assertEquals(2, $runner->migrate('old-3', 2, $t0)->getMachineVersion(), "{$family} 2.6: migrate");
        $this->assertCount(3, $runner->getPersistence()->history->findByInstance('old-1'), "{$family} 2.6: histórico");
        $this->assertEquals(['old-1'], array_map(static fn($m): string => $m->instanceId, array_filter($runner->migrateAll('flow', 1, 2, $t0, limit: 10), static fn($m): bool => $m->isMigrated())), "{$family} 2.6: migrateAll");
        $this->assertCount(4, $runner->getPersistence()->history->findByInstance('old-1'), "{$family} 2.6: a migração no histórico");

        $e = $this->assertThrows(MachinePersistenceException::class, fn() => $runner->start('t-1', 'quote', 1, null, $t0, tenant: '7'), "{$family} 2.6: tenant sem a coluna recusado");
        $this->assertTrue(str_contains($e->getMessage(), 'tenant') && str_contains($e->getMessage(), 'COLUMNS_SINCE_2_7'), "{$family} 2.6: a mensagem nomeia a coluna e a constante");
        $this->assertThrows(MachineInstanceNotFoundException::class, fn() => $runner->load('t-1'), "{$family} 2.6: nada gravado");
        $this->assertThrows(MachinePersistenceException::class, fn() => $runner->findDue($t0, tenant: '7'), "{$family} 2.6: busca por tenant sem a coluna recusada");
        $this->assertThrows(MachinePersistenceException::class, fn() => $runner->load('old-1', '7'), "{$family} 2.6: carga por tenant sem a coluna recusada");

        // The additive migration, as the DDL header writes it, then the 2.7 script for the new tables
        $statements = self::migrationStatements();
        $this->assertCount(6, $statements, 'cinco ADD COLUMN e um CREATE UNIQUE INDEX na migration comentada do DDL');
        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
        self::loadDdl($pdo, (string) file_get_contents(self::DDL));
        $definitions = new PdoMachineDefinitionRepository($pdo);
        foreach ([...$this->flowVersions(), $this->tenantQuote(1), $this->pausingMachine()] as $definition) {
            $definitions->register($definition);
        }
        $mapped = new MachineRunner(Spec::stateMachine(), $definitions, MachinePersistence::pdo($pdo, columns: PdoMachineSchema::COLUMNS_SINCE_2_7, definitions: $definitions));
        $this->assertTrue($mapped->load('old-2')->tenant === null, "{$family} migrado: linha antiga sem tenant");
        $this->assertTrue($mapped->send('old-2', Event::external('HOLD', $t0->modify('+6 hours')))->isAccepted(), "{$family} migrado: linha antiga segue");
        $this->assertEquals(['main' => 2, 'hold' => null], $this->sqlCodes($pdo, 'old-2'), "{$family} migrado: o próximo passo grava o código");
        $mapped->start('t-1', 'quote', 1, null, $t0, tenant: '7');
        $mapped->send('t-1', Event::external('COMPLETE_CALCULATION', $t0), tenant: '7');
        $this->assertEquals(['t-1'], array_map(static fn(DueDeadline $d): string => $d->instanceId, $mapped->findDue($t0->modify('+3 days'), tenant: '7')), "{$family} migrado: tenant");
        $this->assertThrows(MachineInstanceNotFoundException::class, fn() => $mapped->load('old-3', '7'), "{$family} migrado: instância antiga não pertence a tenant nenhum");
        $this->dropSchema($pdo);
    }

    /** A deadline that pauses while the instance is on hold (2.6 behavior, through the 2.6 tables). */
    private function pausingMachine(): MachineDefinition
    {
        return Spec::machine('pause', 1)
            ->region('main', 'working')
                ->state('working')->code(2)->deadline('P1D', 'EXPIRE', pausedWhile: 'hold.on')->on('EXPIRE', 'late')
                ->finalState('late')
            ->region('hold', 'off')
                ->state('off')->on('HOLD', 'on')
                ->state('on')->on('RELEASE', 'off')
            ->build();
    }

    /**
     * The statements of the additive migration commented in the reference DDL.
     *
     * @return list<string>
     */
    private static function migrationStatements(): array
    {
        $ddl = (string) file_get_contents(self::DDL);
        $start = strpos($ddl, '-- Additive migration 2.6 -> 2.7.0');
        $statements = [];
        foreach (explode("\n", substr($ddl, (int) $start)) as $line) {
            if (preg_match('/^--\s+((?:ALTER TABLE|CREATE UNIQUE INDEX) .+);$/', $line, $match) === 1) {
                $statements[] = $match[1];
            }
        }

        return $statements;
    }

    // ==========================================
    // Acceptance (L1–L3): the quote machine of the consumer, two tenants, the synthetic sample
    // ==========================================

    private function testAcceptance(string $label, PDO $pdo): void
    {
        $definitions = new PdoMachineDefinitionRepository($pdo);
        $definitions->register($this->v1());
        $guards = $this->quoteGuards();
        $array = json_decode((string) file_get_contents(self::V2), true);
        $at = new DateTimeImmutable('2026-08-31T09:00:00-03:00');
        $info = $definitions->saveDraft(self::QUOTE_KEY, $array, null, 'admin', $at);
        $draft = $definitions->draft(self::QUOTE_KEY, $guards);
        $this->assertTrue($draft !== null && $draft->isPublishable(), "{$label}: v2 publicável");
        $published = $definitions->publish(self::QUOTE_KEY, $info->revision, $guards, null, 'Legacy codes and the SLA region', 'admin', $at);
        $this->assertEquals(2, $published->version(), "{$label}: v2 publicada pelo repositório PDO");
        $this->assertEquals($this->v2()->fingerprint(), $published->definition->fingerprint(), "{$label}: a v2 publicada é a fixture");
        $v2 = $definitions->get(self::QUOTE_KEY, 2);
        foreach (range(1, 21) as $code) {
            $found = $v2->stateByCode($code);
            $this->assertTrue($found !== null && $v2->codeOf($found['region'], $found['state']->name) === $code, "{$label}: código legado {$code}");
        }
        $this->assertEquals('open', $v2->stateByCode(20)['state']->name ?? null, "{$label}: pending.open = 20");

        $persistence = MachinePersistence::pdo($pdo, columns: PdoMachineSchema::COLUMNS_SINCE_2_7, definitions: $definitions);
        $runner = new MachineRunner(Spec::stateMachine($guards), $definitions, $persistence);
        $sample = json_decode((string) file_get_contents(self::SAMPLE), true);
        $stats = $this->replay($sample, $runner, $v2, $pdo, true, $label);

        $total = array_sum(array_map(static fn(array $p): int => count($p['steps']), $sample['processes']));
        fwrite(STDOUT, sprintf(
            "    [046 RN-06] %s: %d cotações, 2 tenants, %d passos comparados (projeção = id legado; SLA = oráculo em %d pontos, final %s), exclusões %s, %d truncados\n",
            $label,
            count($sample['processes']),
            $stats['compared'],
            $stats['slaChecks'],
            json_encode($stats['sla']),
            json_encode($stats['exclusions']),
            $stats['truncated']
        ));
        $this->assertEquals(['chain_break' => 1, 'duplicate' => 1, 'pre_fix_resolution' => 1], $stats['exclusions'], "{$label}: exclusões declaradas e contadas");
        $this->assertEquals($total, $stats['compared'] + array_sum($stats['exclusions']) + $stats['truncated'], "{$label}: todo passo é comparado, excluído ou truncado por uma exclusão");
        $this->assertEquals(102, $stats['compared'], "{$label}: passos comparados (fixture sintética)");
        $this->assertEquals(2, $stats['truncated'], "{$label}: passos depois de uma exclusão");
        // quote-901 ends paused (its pending resolution before the fix is excluded, so the item stays open)
        $this->assertEquals(['met' => 3, 'breached' => 6, 'stopped' => 0, 'running' => 1, 'idle' => 7], $stats['sla'], "{$label}: SLA final = oráculo");
    }

    /**
     * Replays a sample of the legacy history through the runner, in time order across processes, and
     * checks after each step that the projection of the lib (the code of `pending` when it is open, the
     * one of `progress` otherwise) is the legacy current state id; the SLA region against an independent
     * oracle in continuous time; the SQL projection; and that no tenant reaches another's instance.
     *
     * @param array<string, mixed> $sample
     * @return array{compared: int, truncated: int, exclusions: array<string, int>, sla: array<string, int>, slaChecks: int}
     */
    private function replay(array $sample, MachineRunner $runner, MachineDefinition $definition, ?PDO $pdo, bool $withSla, string $label): array
    {
        $fixDate = new DateTimeImmutable((string) $sample['pending_fix_date']);
        $timeline = [];
        foreach ($sample['processes'] as $p => $process) {
            $timeline[] = [new DateTimeImmutable($process['started_at']), $p, -1];
            foreach ($process['steps'] as $s => $step) {
                $timeline[] = [new DateTimeImmutable($step['at']), $p, $s];
            }
        }
        usort($timeline, static fn(array $a, array $b): int => [$a[0]->getTimestamp(), $a[1], $a[2]] <=> [$b[0]->getTimestamp(), $b[1], $b[2]]);

        $tenants = array_values(array_unique(array_map(static fn(array $p): string => (string) $p['tenant'], $sample['processes'])));
        $state = [];
        $stats = ['compared' => 0, 'truncated' => 0, 'exclusions' => [], 'sla' => [], 'slaChecks' => 0];
        $context = static fn(array $process): array => ['product' => $process['product'] ?? 'none'];
        $contextProvider = static function (DueDeadline $deadline, MachineInstance $instance) use ($sample, $context): array {
            foreach ($sample['processes'] as $process) {
                if ($process['id'] === $instance->instanceId) {
                    return $context($process);
                }
            }

            return [];
        };

        foreach ($timeline as [$at, $p, $s]) {
            foreach ($tenants as $tenant) {
                $runner->deliverDue($at, self::QUOTE_KEY, 1000, 'scheduler', $contextProvider, $tenant);
            }
            if ($withSla) {
                foreach ($state as &$tracked) {
                    $this->slaBreachIfDue($tracked, $at);
                }
                unset($tracked);
            }
            $process = $sample['processes'][$p];
            $id = (string) $process['id'];
            $tenant = (string) $process['tenant'];
            if ($s === -1) {
                $result = $runner->start($id, self::QUOTE_KEY, $definition->version, $context($process), $at, 'importer', $tenant);
                $this->assertTrue($result->isAccepted(), "{$label}: {$id} começa");
                $state[$id] = ['legacy' => 1, 'excluded' => false, 'last' => null, 'sla' => 'idle', 'remaining' => 0, 'resumeAt' => null, 'paused' => false, 'product' => $process['product'] ?? 'none'];
                continue;
            }
            $step = $process['steps'][$s];
            $tracked = &$state[$id];
            if ($tracked['excluded']) {
                $stats['truncated']++;
                unset($tracked);
                continue;
            }
            $last = $tracked['last'];
            $tracked['last'] = $step;
            if ($last !== null && $last['key'] === $step['key'] && $last['at'] === $step['at']) {
                $stats['exclusions']['duplicate'] = ($stats['exclusions']['duplicate'] ?? 0) + 1;
                unset($tracked);
                continue;
            }
            if ((int) $step['from'] !== $tracked['legacy']) {
                $stats['exclusions']['chain_break'] = ($stats['exclusions']['chain_break'] ?? 0) + 1;
                $tracked['excluded'] = true;
                unset($tracked);
                continue;
            }
            $snapshot = $runner->load($id, $tenant)->snapshot;
            $progressCode = DefinitionSupport::normalizeCode((string) $definition->codeOf('progress', $snapshot->stateOf('progress')));
            if ($step['key'] === 'resolve_pending' && $at < $fixDate && (int) $step['to'] !== $progressCode) {
                $stats['exclusions']['pre_fix_resolution'] = ($stats['exclusions']['pre_fix_resolution'] ?? 0) + 1;
                $tracked['excluded'] = true;
                unset($tracked);
                continue;
            }
            $transition = $definition->getTransition((string) $step['key']);
            if ($transition === null) {
                $stats['exclusions']['unmapped_transition'] = ($stats['exclusions']['unmapped_transition'] ?? 0) + 1;
                $tracked['excluded'] = true;
                unset($tracked);
                continue;
            }
            $result = $runner->send($id, Event::external($transition->event, $at), $context($process), 'user', null, $tenant);
            $this->assertTrue($result->isAccepted(), "{$label}: {$id} {$step['key']} em {$step['at']} aceito");
            $after = $runner->load($id, $tenant)->snapshot;
            $codes = $definition->codesOf($after);
            $projected = $after->stateOf('pending') === 'open' ? $codes['pending'] : $codes['progress'];
            $this->assertTrue($projected !== null && DefinitionSupport::normalizeCode($projected) === (int) $step['to'], "{$label}: {$id} depois de {$step['key']}: projeção " . json_encode($projected) . " = id legado {$step['to']}");
            if ($pdo !== null) {
                $sql = $this->sqlCodes($pdo, $id);
                $this->assertTrue($sql['progress'] === DefinitionSupport::normalizeCode((string) $codes['progress']) && $sql['pending'] === ($codes['pending'] === null ? null : DefinitionSupport::normalizeCode($codes['pending'])), "{$label}: {$id}: projeção SQL dos códigos");
            }
            $tracked['legacy'] = (int) $step['to'];
            $stats['compared']++;
            if ($withSla) {
                $this->slaStep($tracked, $transition->event, $at);
                $this->assertSla($tracked, $after, "{$label}: {$id} depois de {$step['key']}");
                $stats['slaChecks']++;
            }
            $other = $tenant === $tenants[0] ? $tenants[1] : $tenants[0];
            $this->assertThrows(MachineInstanceNotFoundException::class, fn() => $runner->load($id, $other), "{$label}: {$id} não existe para o tenant {$other}");
            unset($tracked);
        }

        // Every pending deadline at the end, then the final SLA against the oracle
        $end = end($timeline)[0]->modify('+30 days');
        foreach ($tenants as $tenant) {
            $runner->deliverDue($end, self::QUOTE_KEY, 1000, 'scheduler', $contextProvider, $tenant);
        }
        $stats['sla'] = ['met' => 0, 'breached' => 0, 'stopped' => 0, 'running' => 0, 'idle' => 0];
        foreach ($state as $id => $tracked) {
            if ($withSla) {
                $this->slaBreachIfDue($tracked, $end);
                $this->assertSla($tracked, $runner->load((string) $id)->snapshot, "{$label}: {$id} no fim");
                $stats['slaChecks']++;
            }
            $stats['sla'][$tracked['sla']]++;
        }
        ksort($stats['exclusions']);

        return $stats;
    }

    /**
     * The SLA oracle (continuous time, independent of the library): 2 days from the calculation of a
     * standard quote, paused while a pending item is open, met by the proposal, stopped by a cancel.
     *
     * @param array<string, mixed> $tracked
     */
    private function slaStep(array &$tracked, string $event, DateTimeImmutable $at): void
    {
        if ($tracked['sla'] === 'idle' && $event === 'COMPLETE_CALCULATION' && $tracked['product'] === 'standard') {
            $tracked['sla'] = 'running';
            $tracked['remaining'] = 2 * 86400;
            $tracked['resumeAt'] = $at;
            $tracked['paused'] = false;
            return;
        }
        if ($tracked['sla'] !== 'running') {
            return;
        }
        if ($event === 'REGISTER_PENDING' && !$tracked['paused']) {
            $tracked['remaining'] -= $at->getTimestamp() - $tracked['resumeAt']->getTimestamp();
            $tracked['paused'] = true;
        } elseif ($event === 'RESOLVE_PENDING' && $tracked['paused']) {
            $tracked['resumeAt'] = $at;
            $tracked['paused'] = false;
        } elseif ($event === 'SEND_PROPOSAL') {
            $tracked['sla'] = 'met';
        } elseif ($event === 'CANCEL') {
            $tracked['sla'] = 'stopped';
        }
    }

    /**
     * @param array<string, mixed> $tracked
     */
    private function slaBreachIfDue(array &$tracked, DateTimeImmutable $now): void
    {
        if ($tracked['sla'] === 'running' && !$tracked['paused'] && $tracked['resumeAt']->getTimestamp() + $tracked['remaining'] <= $now->getTimestamp()) {
            $tracked['sla'] = 'breached';
        }
    }

    /**
     * @param array<string, mixed> $tracked
     */
    private function assertSla(array $tracked, MachineSnapshot $snapshot, string $where): void
    {
        $this->assertEquals($tracked['sla'], $snapshot->stateOf('sla'), "{$where}: estado do SLA");
        if ($tracked['sla'] !== 'running') {
            return;
        }
        if ($tracked['paused']) {
            $this->assertEquals($tracked['remaining'], $snapshot->pausedRemaining('sla'), "{$where}: saldo pausado");
        } else {
            $this->assertEquals($tracked['resumeAt']->getTimestamp() + $tracked['remaining'], $snapshot->dueAt('sla')?->getTimestamp(), "{$where}: vencimento do SLA");
        }
    }

    /**
     * The same replay over the real anonymized sample, when the team of the consumer points to it (never
     * in git): ASPEC_VIRTTUS_SAMPLE=<file in the format of the synthetic one>, on SQLite.
     */
    private function testRealSampleWhenGiven(): void
    {
        $file = getenv('ASPEC_VIRTTUS_SAMPLE');
        if ($file === false || $file === '') {
            fwrite(STDOUT, "    [AVISO] ASPEC_VIRTTUS_SAMPLE não definido; replay da amostra real pulado (a sintética rodou).\n");
            return;
        }
        $this->assertTrue(is_file($file), 'ASPEC_VIRTTUS_SAMPLE aponta para um arquivo');
        $pdo = $this->sqliteWith(self::DDL);
        $definitions = new PdoMachineDefinitionRepository($pdo);
        $definitions->register($this->v1());
        $definitions->register($this->v2());
        $runner = new MachineRunner(Spec::stateMachine($this->quoteGuards()), $definitions, MachinePersistence::pdo($pdo, columns: PdoMachineSchema::COLUMNS_SINCE_2_7, definitions: $definitions));
        $sample = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $withSla = array_filter($sample['processes'], static fn(array $p): bool => isset($p['product'])) !== [];
        $stats = $this->replay($sample, $runner, $this->v2(), $pdo, $withSla, 'amostra real');
        fwrite(STDOUT, '    [046 RN-06] amostra real: ' . json_encode($stats) . "\n");
    }

    // ==========================================
    // Documentation: example 23 (transcribed; the transcription is checked against the page)
    // ==========================================

    private function testReadme23_1Codes(): void
    {
        // README 23.1 >>>
        $quote = Spec::machine('quote', 1)
            ->region('progress', 'registering')
                ->state('registering')->code(1)
                    ->on('COMPLETE_CALCULATION', 'calculation_done')
                ->state('calculation_done')->code(2)->deadline('P2D', 'EXPIRE')
                    ->on('SEND_PROPOSAL', 'proposal_sent')
                    ->on('EXPIRE', 'expired')
                ->finalState('proposal_sent')->code(8)
                ->finalState('expired')
            ->region('pending', 'none')
                ->state('none')
                    ->on('REGISTER_PENDING', 'open')
                ->state('open')->code(20)
                    ->on('RESOLVE_PENDING', 'none')
            ->build();

        $r1 = $quote->stateByCode(20)['region'];               // 'pending'
        $r2 = $quote->stateByCode('20')['state']->name;        // 'open': the text "20" is the same code as 20
        $r3 = $quote->codeOf('progress', 'calculation_done');  // 2

        $machine = Spec::stateMachine();
        $start = $machine->initialSnapshot($quote, null, new DateTimeImmutable('2026-10-21T09:00:00-03:00'));
        $step = $machine->transition($quote, $start->snapshot, Event::external('COMPLETE_CALCULATION', new DateTimeImmutable('2026-10-21T10:00:00-03:00')));
        $r4 = $quote->codesOf($step->snapshot);                // ['progress' => 2, 'pending' => null]
        // <<< README 23.1

        $this->assertEquals('pending', $r1, 'README 23.1: região');
        $this->assertEquals('open', $r2, 'README 23.1: "20" ≡ 20');
        $this->assertTrue($r3 === 2, 'README 23.1: codeOf');
        $this->assertTrue($r4 === ['progress' => 2, 'pending' => null], 'README 23.1: codesOf');
        $this->assertTranscribed(0, 'README 23.1');
    }

    private function testReadme23_2And23_3(): void
    {
        $pdo = $this->sqliteWith(self::DDL);
        $quote = Spec::machine('quote', 1)
            ->region('progress', 'registering')
                ->state('registering')->code(1)
                    ->on('COMPLETE_CALCULATION', 'calculation_done')
                ->state('calculation_done')->code(2)->deadline('P2D', 'EXPIRE')
                    ->on('SEND_PROPOSAL', 'proposal_sent')
                    ->on('EXPIRE', 'expired')
                ->finalState('proposal_sent')->code(8)
                ->finalState('expired')
            ->region('pending', 'none')
                ->state('none')
                    ->on('REGISTER_PENDING', 'open')
                ->state('open')->code(20)
                    ->on('RESOLVE_PENDING', 'none')
            ->build();

        // README 23.2 >>>
        $definitions = new PdoMachineDefinitionRepository($pdo);
        $definitions->register($quote);                     // version 1, shipped with the application (a no-op when it is there)
        $guards = Spec::guardRegistry()->enableSpecGuard(); // the `spec` guard is opt-in
        $at = new DateTimeImmutable('2026-10-21T09:00:00-03:00');

        $runner = new MachineRunner(Spec::stateMachine($guards), $definitions, MachinePersistence::pdo($pdo));
        $runner->start('q-1', 'quote', 1, null, $at);

        // The screen starts a draft from version 1 and adds a transition whose condition reads the context
        $info = $definitions->draftFrom('quote', 1, null, 'ana', $at);
        $draft = $definitions->draft('quote', $guards)->definition;
        $draft['regions'][0]['states'][0]['transitions'][] = [
            'id' => 'fast_track',
            'event' => 'FAST_TRACK',
            'target' => 'proposal_sent',
            'guard' => GuardRegistry::specRef(Spec::property('amount', Spec::lessThan(10000)))->toArray(),
        ];
        $draft['regions'][0]['states'][0]['transitions'][] = ['id' => 'broken', 'event' => 'BROKEN', 'target' => 'nowhere'];
        $info = $definitions->saveDraft('quote', $draft, $info->revision, 'ana', $at);
        $r1 = $definitions->draft('quote', $guards)->report->errorKinds(); // ['unknown_target']: saved, reported, never published

        array_pop($draft['regions'][0]['states'][0]['transitions']);
        $info = $definitions->saveDraft('quote', $draft, $info->revision, 'ana', $at);
        $published = $definitions->publish('quote', $info->revision, $guards, null, 'Fast track for small amounts', 'ana', $at);
        $r2 = $published->version();                               // 2: the repository gives the version, never the screen
        $r3 = $definitions->get('quote', 1)->diff($published->definition)->addedTransitions; // ['fast_track']

        // Live instances stay on version 1 until they are migrated; no state was removed, so by name
        $runner->registerMigration('quote', 1, 2, MachineMigrations::byStateName());
        $report = $runner->migrateAll('quote', 1, 2, $at->modify('+1 hour'), actor: 'ana');
        $r4 = $report[0]->isMigrated();                            // true
        $r5 = $runner->send('q-1', Event::external('FAST_TRACK', $at->modify('+2 hours')), ['amount' => 8000])->isAccepted(); // true
        // <<< README 23.2

        $this->assertEquals([MachineDefinitionError::KIND_UNKNOWN_TARGET], $r1, 'README 23.2: relatório do rascunho');
        $this->assertTrue($r2 === 2, 'README 23.2: versão dada pelo repositório');
        $this->assertEquals(['fast_track'], $r3, 'README 23.2: diff');
        $this->assertTrue($r4, 'README 23.2: migrateAll');
        $this->assertTrue($r5, 'README 23.2: a transição nova com o guard spec');
        $this->assertFalse($runner->send('q-1', Event::external('SEND_PROPOSAL', $at), ['amount' => 8000])->isAccepted(), 'README 23.2: q-1 já está em proposal_sent');
        $this->assertTranscribed(1, 'README 23.2');

        // README 23.3 >>>
        $persistence = MachinePersistence::pdo($pdo, columns: PdoMachineSchema::COLUMNS_SINCE_2_7, definitions: $definitions);
        $runner = new MachineRunner(Spec::stateMachine($guards), $definitions, $persistence);

        $runner->start('q-7-1', 'quote', null, null, $at, actor: 'ana', tenant: '7');    // agency 7, latest version
        $runner->start('q-12-1', 'quote', null, null, $at, actor: 'bob', tenant: '12');  // agency 12, the same definition
        $calculated = $at->modify('+1 hour');
        $r1 = $runner->send('q-7-1', Event::external('COMPLETE_CALCULATION', $calculated), tenant: '7')->isAccepted();   // true
        $r2 = $runner->send('q-12-1', Event::external('COMPLETE_CALCULATION', $calculated), tenant: '12')->isAccepted(); // true

        try {
            $runner->send('q-7-1', Event::external('SEND_PROPOSAL', $calculated), tenant: '12');
        } catch (MachineInstanceNotFoundException $e) {
            $r3 = $e->getMessage();                                // 'Machine instance "q-7-1" does not exist.'
        }

        $due = $runner->findDue($calculated->modify('+2 days'), tenant: '7');
        $r4 = array_map(fn($deadline) => $deadline->instanceId, $due); // ['q-7-1']: agency 12's deadline is not there
        $r5 = $runner->deliverDue($calculated->modify('+2 days'), tenant: '7')[0]->isDelivered(); // true

        $r6 = $pdo->query("SELECT current_state_code FROM state_machine_region WHERE instance_id = 'q-12-1' AND region = 'progress'")->fetchColumn(); // '2' ('2' in a VARCHAR column, 2 in an INTEGER one)
        // <<< README 23.3

        $this->assertTrue($r1 && $r2, 'README 23.3: cada imobiliária no seu');
        $this->assertEquals('Machine instance "q-7-1" does not exist.', $r3 ?? null, 'README 23.3: outro tenant = inexistente');
        $this->assertEquals(['q-7-1'], $r4, 'README 23.3: findDue do tenant');
        $this->assertTrue($r5, 'README 23.3: deliverDue do tenant');
        $this->assertTrue($r6 === '2', 'README 23.3: o código no banco (VARCHAR no SQLite)');
        $this->assertEquals('expired', $runner->load('q-7-1', '7')->snapshot->stateOf('progress'));
        $this->assertEquals('calculation_done', $runner->load('q-12-1', '12')->snapshot->stateOf('progress'), 'README 23.3: o prazo da 12 não foi entregue');
        $this->assertEquals(2, $runner->load('q-7-1')->getMachineVersion(), 'README 23.3: a última versão');
        $this->assertTranscribed(2, 'README 23.3');
    }

    private function testReadmeBlocksAreTheSameInBothLanguages(): void
    {
        $root = dirname(__DIR__, 2);
        $english = ReadmeBlocks::of($root . '/docs/en/' . self::README_PAGE . '.md', 23);
        $portuguese = ReadmeBlocks::of($root . '/docs/pt-BR/' . self::README_PAGE . '.md', 23);
        $this->assertCount(3, $english, 'três blocos PHP no exemplo 23 (EN)');
        $this->assertCount(3, $portuguese, 'três blocos PHP no exemplo 23 (pt-BR)');
        foreach ($english as $i => $block) {
            $this->assertEquals(ReadmeBlocks::codeTokens($block), ReadmeBlocks::codeTokens($portuguese[$i]), "README 23: bloco {$i} igual fora os comentários");
        }
        foreach (['en', 'pt-BR'] as $language) {
            $text = (string) file_get_contents($root . "/docs/{$language}/" . self::README_PAGE . '.md');
            foreach (['forward', 'Reversa', 'RN-0', 'docs/contratos', 'Virttus'] as $internal) {
                $this->assertFalse(stripos($text, $internal) !== false, "docs/{$language}: nada interno ({$internal})");
            }
        }
    }

    private function assertTranscribed(int $index, string $name): void
    {
        $source = (string) file_get_contents(__FILE__);
        $start = strpos($source, "// {$name} >>>\n");
        $end = strpos($source, "// <<< {$name}\n");
        $this->assertTrue($start !== false && $end !== false && $end > $start, "transcrição {$name} marcada");
        // The transcription may keep an expression the page shows bare as `$rN = <expression>`
        $tokens = ReadmeBlocks::codeTokens(substr($source, (int) $start, (int) $end - (int) $start));
        $transcribed = [];
        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            if (preg_match('/^\$r\d+$/', $tokens[$i]) === 1 && ($tokens[$i + 1] ?? null) === '=') {
                $i++;
                continue;
            }
            $transcribed[] = $tokens[$i];
        }
        $blocks = ReadmeBlocks::of(dirname(__DIR__, 2) . '/docs/en/' . self::README_PAGE . '.md', 23);
        $this->assertTrue(isset($blocks[$index]), "README 23: bloco {$index}");
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

    private function v1(): MachineDefinition
    {
        return MachineDefinition::fromJson((string) file_get_contents(self::V1));
    }

    private function v2(): MachineDefinition
    {
        return MachineDefinition::fromJson((string) file_get_contents(self::V2));
    }

    /** The guards of the quote machine: hasRole (as the application registers it) and the opt-in spec guard. */
    private function quoteGuards(): GuardRegistry
    {
        return Spec::guardRegistry()
            ->register('hasRole', static function (array $params): ISpecification {
                $roles = $params['roles'] ?? [];
                if ($roles === []) {
                    return Spec::alwaysTrue();
                }

                return Spec::property('context.role', Spec::in($roles))->withCode('ROLE_NOT_ALLOWED');
            })
            ->enableSpecGuard();
    }

    /** The machine whose fingerprint was measured on main (2.6.1) with the same builder calls. */
    private function builderMachine(): MachineDefinition
    {
        return Spec::machine('order', 1)
            ->region('main', 'draft')
                ->state('draft')->entry('notify', ['to' => 'owner'])
                    ->on('SUBMIT', 'review')->guard('hasRole', ['roles' => ['seller']])->action('log')
                ->state('review')->deadline('P3D', 'EXPIRE')
                    ->on('APPROVE', 'approved')->guard(GuardExpression::allOf(GuardExpression::ref('hasRole', ['roles' => ['manager']]), GuardExpression::not(GuardExpression::ref('inState', ['state' => 'flags.blocked']))))
                    ->on('EXPIRE', 'expired')
                ->finalState('approved')
                ->finalState('expired')
            ->region('flags', 'clear')
                ->state('clear')->on('BLOCK', 'blocked')
                ->state('blocked')->deadline(event: 'UNBLOCK', afterFrom: 'context.block_for', pausedWhile: 'main.review')->on('UNBLOCK', 'clear')
            ->build();
    }

    /**
     * A random valid machine (array form): 1 to 3 regions of 1 to 25 states, codes integer, canonical
     * integer text, other text or none (unique by construction), transitions guarded by inState of another
     * region, deadlines with and without pause, some final states.
     *
     * @return array<string, mixed>
     */
    private static function randomMachine(int $seed, string $key): array
    {
        $random = new Randomizer(new Mt19937($seed));
        $regionCount = $random->getInt(1, 3);
        $names = [];
        for ($i = 0; $i < $regionCount; $i++) {
            $names[$i] = array_map(static fn(int $k): string => "s{$k}", range(0, $random->getInt(1, 25) - 1));
        }
        $serial = 0;
        $regions = [];
        for ($i = 0; $i < $regionCount; $i++) {
            $states = [];
            foreach ($names[$i] as $k => $name) {
                $state = ['name' => $name];
                $final = $k > 0 && $random->getInt(1, 6) === 1;
                if ($final) {
                    $state['final'] = true;
                }
                $n = ++$serial * 3 + $seed % 3;
                $state += match ($random->getInt(0, 3)) {
                    0 => [],
                    1 => ['code' => $n],
                    2 => ['code' => (string) $n],
                    default => ['code' => "K-{$n}"],
                };
                if (!$final) {
                    $transitions = [];
                    $count = $random->getInt(0, 3);
                    for ($t = 0; $t < $count; $t++) {
                        $transition = ['id' => "r{$i}_{$name}_t{$t}", 'event' => 'E' . $random->getInt(0, 4), 'target' => $names[$i][$random->getInt(0, count($names[$i]) - 1)]];
                        if ($regionCount > 1 && $random->getInt(0, 2) === 0) {
                            $other = ($i + 1) % $regionCount;
                            $transition['guard'] = ['not' => ['guard' => 'inState', 'params' => ['state' => "r{$other}." . $names[$other][0]]]];
                        }
                        $transitions[] = $transition;
                    }
                    if ($transitions !== []) {
                        $state['transitions'] = $transitions;
                    }
                    if ($random->getInt(0, 3) === 0) {
                        $state['deadline'] = ['after' => 'PT' . $random->getInt(1, 72) . 'H', 'event' => 'TIMEOUT'];
                        if ($regionCount > 1 && $random->getInt(0, 1) === 0) {
                            $other = ($i + 1) % $regionCount;
                            $state['deadline']['pausedWhile'] = ['guard' => 'inState', 'params' => ['state' => "r{$other}." . $names[$other][0]]];
                        }
                    }
                }
                $states[] = $state;
            }
            $regions[] = ['name' => "r{$i}", 'initial' => 's0', 'states' => $states];
        }

        return ['key' => $key, 'version' => 1, 'regions' => $regions];
    }

    private function tempFile(): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'aspec40');
        $this->tempFiles[] = $file;

        return $file;
    }

    private function sqliteWith(string $ddl): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        self::loadDdl($pdo, (string) file_get_contents($ddl));

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

    private function resetSchema(PDO $pdo, string $ddl): void
    {
        $this->dropSchema($pdo);
        self::loadDdl($pdo, (string) file_get_contents($ddl));
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
