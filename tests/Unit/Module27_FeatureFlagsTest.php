<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\FeatureFlags\Exceptions\FeatureFlagException;
use Antevemus\ASpecification\FeatureFlags\Exceptions\FeatureFlagStoreException;
use Antevemus\ASpecification\FeatureFlags\Exceptions\InvalidFeatureFlagReasonException;
use Antevemus\ASpecification\FeatureFlags\Exceptions\StaleFeatureFlagException;
use Antevemus\ASpecification\FeatureFlags\Exceptions\UndeclaredFeatureFlagException;
use Antevemus\ASpecification\FeatureFlags\FeatureEnabledSpecification;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagChange;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagDefinition;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagReason;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagRegistry;
use Antevemus\ASpecification\FeatureFlags\FeatureFlags;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagState;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagView;
use Antevemus\ASpecification\FeatureFlags\IFeatureFlagStore;
use Antevemus\ASpecification\FeatureFlags\InMemoryFeatureFlagErrorReporter;
use Antevemus\ASpecification\FeatureFlags\InMemoryFeatureFlagStore;
use Antevemus\ASpecification\FeatureFlags\PdoFeatureFlagStore;
use Antevemus\ASpecification\Persistence\InMemoryQueryLogger;
use Antevemus\ASpecification\Persistence\ITransactionManager;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\BooleanStorage;
use Antevemus\ASpecification\Specifications\PredicateSpecification;
use Antevemus\ASpecification\Tests\TestCase;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Antevemus\ASpecification\Tests\Support\ReadmeBlocks;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Module27Agency - subject of the feature flag tests (an agency with its unit id and plan).
 *
 * @version 2.1.0
 */
final class Module27Agency
{
    public function __construct(
        public readonly int|string|null $unitId,
        public readonly string $plan = 'basic'
    ) {
    }
}

/**
 * Module27Clock - settable clock of the feature flag tests.
 *
 * @version 2.1.0
 */
final class Module27Clock
{
    public DateTimeImmutable $now;

    public function __construct(string $now)
    {
        $this->now = new DateTimeImmutable($now, new DateTimeZone('UTC'));
    }

    public function advance(string $modifier = '+1 second'): void
    {
        $this->now = $this->now->modify($modifier);
    }

    public function closure(): Closure
    {
        return fn(): DateTimeImmutable => $this->now;
    }
}

/**
 * Module27ProbeStore - decorator that counts loads and can fail a load or a history write (2.3.0: also
 * delegates the four methods IFeatureFlagStore gained in forward 040).
 *
 * @version 2.3.0
 */
final class Module27ProbeStore implements IFeatureFlagStore
{
    public int $loads = 0;
    public ?Throwable $failLoad = null;
    public bool $failAppend = false;

    public function __construct(private readonly IFeatureFlagStore $inner)
    {
    }

    public function loadAll(): array
    {
        $this->loads++;
        if ($this->failLoad !== null) {
            throw $this->failLoad;
        }

        return $this->inner->loadAll();
    }

    public function find(string $code): ?FeatureFlagState
    {
        return $this->inner->find($code);
    }

    public function insertIfAbsent(FeatureFlagDefinition $definition, DateTimeImmutable $at, string|int|null $actor): bool
    {
        return $this->inner->insertIfAbsent($definition, $at, $actor);
    }

    public function compareAndSetEnabled(string $code, bool $expected, bool $enabled, DateTimeImmutable $at, string|int|null $actor): bool
    {
        return $this->inner->compareAndSetEnabled($code, $expected, $enabled, $at, $actor);
    }

    public function supportsValidity(): bool
    {
        return $this->inner->supportsValidity();
    }

    public function compareAndSetValidity(string $code, int $expectedVersion, ?DateTimeImmutable $from, ?DateTimeImmutable $until, DateTimeImmutable $at, string|int|null $actor): bool
    {
        return $this->inner->compareAndSetValidity($code, $expectedVersion, $from, $until, $at, $actor);
    }

    public function supportsRollout(): bool
    {
        return $this->inner->supportsRollout();
    }

    public function compareAndSetRollout(string $code, int $expectedVersion, ?int $percent, DateTimeImmutable $at, string|int|null $actor): bool
    {
        return $this->inner->compareAndSetRollout($code, $expectedVersion, $percent, $at, $actor);
    }

    public function supportsSubjects(): bool
    {
        return $this->inner->supportsSubjects();
    }

    public function addSubject(string $code, string $subjectKey): bool
    {
        return $this->inner->addSubject($code, $subjectKey);
    }

    public function removeSubject(string $code, string $subjectKey): bool
    {
        return $this->inner->removeSubject($code, $subjectKey);
    }

    public function appendChange(FeatureFlagChange $change): void
    {
        if ($this->failAppend) {
            throw new FeatureFlagStoreException('history table is full (test)');
        }
        $this->inner->appendChange($change);
    }

    public function history(?string $code = null, int $limit = 50): array
    {
        return $this->inner->history($code, $limit);
    }

    public function getTransactionManager(): ITransactionManager
    {
        return $this->inner->getTransactionManager();
    }
}

/**
 * Module 27 - Feature flags declared in code, with targeting and an audit trail (forward 034, 2.1.0).
 *
 * - D2: FeatureFlagDefinition (code in the format of the Virttus CHECK, texts, origin, target) and
 *   FeatureFlagRegistry (one place, invalid and duplicate codes refused, the Virttus REGISTRY shape)
 * - RN-02: the same contract battery runs against InMemoryFeatureFlagStore and PdoFeatureFlagStore
 *   over the reference DDL (SQLite in memory; PostgreSQL 17, MySQL 8.4 and MariaDB 11.4 when
 *   ASPEC_PDO_{PGSQL,MYSQL,MARIADB}_DSN are set, as run_portability.sh does), with the same results
 * - D5: toggle with reason (normalized, min/max), compare-and-set (StaleFeatureFlagException), history
 *   in the same transaction (a failed history write rolls the switch back), undeclared flag refused to
 *   turn on and allowed to turn off, declared flag without a row born off on its first change
 * - D6: validity window (both bounds inclusive, a date-only bound covers the day), subject list
 *   (add/remove with reason and history) and specification in code, combined with AND
 * - D7/D8: isEnabled/evaluate/spec, reasons and their OpenFeature mapping, failure metadata
 * - D9: fail closed (no table, broken store, target that throws) with the reporter called once per load
 * - D10: one load per instance (one SQL statement for any number of evaluations), refresh(), withAsOf()
 * - D11: all() (registry x store, texts of the code win, undeclared listed, last change), history()
 * - D3: PDO store options (identifiers validated, required columns, boolean storages, a shared history
 *   table with fixed scope values, a database-generated key, soft delete, local time stamps)
 * - RN-05: the README example (proposed "19. Declared Feature Flags") runs as written
 *
 * @version 2.1.0
 */
class Module27_FeatureFlagsTest extends TestCase
{
    /** Reference DDL shipped with the package. */
    private const DDL = __DIR__ . '/../../resources/sql/feature_flags.ansi.sql';

    private const RENEWAL = 'renewal_notice';
    private const PENDING = 'pending_notice';
    private const ORPHAN = 'legacy_orphan';

    public function run(): void
    {
        $this->testDefinitionAndRegistry();
        $this->testReasonMapping();
        $this->testEvaluationWithoutStoreAccess();
        $this->testInMemorySpecifics();

        $this->runContract('InMemory', fn(): array => $this->inMemoryBackend());

        if (!extension_loaded('pdo_sqlite')) {
            fwrite(STDOUT, "    [AVISO] pdo_sqlite ausente; o Módulo 27 roda só a bateria em memória.\n");
            return;
        }

        $this->assertTrue(is_file(self::DDL), 'DDL de referência das feature flags deve existir');
        $this->runContract('PDO/sqlite', fn(): array => $this->pdoBackend($this->sqlite(), 'sqlite'));
        $this->testPdoConfiguration();
        $this->testPdoOneStatementPerLoad();
        $this->testPdoValidityText();
        $this->testPdoStrictCharBoolean();
        $this->testPdoSharedAuditTable();
        $this->testReadmeExampleRunsAsWritten();
        $this->testReadme19BlocksAreTheSameInBothLanguages();

        foreach (['pgsql' => 'PostgreSQL', 'mysql' => 'MySQL', 'mariadb' => 'MariaDB'] as $family => $label) {
            $pdo = $this->connectFromEnvironment($family);
            if ($pdo === null) {
                fwrite(STDOUT, "    [AVISO] ASPEC_PDO_" . strtoupper($family) . "_DSN não definido; RN-02 em {$label} pulado.\n");
                continue;
            }
            $this->runContract("PDO/{$family}", function () use ($pdo, $family): array {
                $this->resetSchema($pdo);

                return $this->pdoBackend($pdo, $family);
            });
            $this->resetSchema($pdo);
            $this->testPdoSavepointKeepsTransaction($pdo, $family);
            fwrite(STDOUT, "    [RN-02] {$label} " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . ": bateria de contrato das feature flags OK\n");
            $this->dropSchema($pdo);
        }
    }

    ///////////////////////////////////////////////////////////////////////////
    // D2: declaração e registro
    ///////////////////////////////////////////////////////////////////////////

    private function testDefinitionAndRegistry(): void
    {
        foreach (['abc', 'renewal_notice', 'a12', 'x' . str_repeat('y', 79)] as $valid) {
            $this->assertTrue(FeatureFlagDefinition::isValidCode($valid), "código válido: {$valid}");
        }
        foreach (['ab', 'Abc', '1abc', '_abc', 'ab-c', 'ab c', 'x' . str_repeat('y', 80), ''] as $invalid) {
            $this->assertFalse(FeatureFlagDefinition::isValidCode($invalid), "código inválido: {$invalid}");
            $this->assertThrows(InvalidArgumentException::class, fn() => new FeatureFlagDefinition($invalid, 'Name'));
        }
        $this->assertThrows(InvalidArgumentException::class, fn() => new FeatureFlagDefinition('abc', '   '), 'nome vazio recusado');
        $this->assertThrows(InvalidArgumentException::class, fn() => new FeatureFlagDefinition('abc', 'Name', subjectKey: ' '), 'caminho do sujeito em branco recusado');

        $registry = new FeatureFlagRegistry([new FeatureFlagDefinition('zeta_flag', 'Zeta'), new FeatureFlagDefinition('alpha_flag', 'Alpha')]);
        $this->assertEquals(['zeta_flag', 'alpha_flag'], $registry->codes(), 'ordem de declaração mantida');
        $this->assertEquals(2, count($registry));
        $this->assertThrows(InvalidArgumentException::class, fn() => $registry->declare(new FeatureFlagDefinition('alpha_flag', 'Again')), 'código duplicado recusado');
        $this->assertThrows(InvalidArgumentException::class, fn() => new FeatureFlagRegistry(['not a definition']));
        $this->assertTrue($registry->has('alpha_flag'));
        $this->assertFalse($registry->has('beta_flag'));
        $this->assertEquals(null, $registry->get('beta_flag'));

        // O formato do REGISTRY da Virttus (chaves snake_case) entra direto
        $virttus = FeatureFlagRegistry::fromArray([
            'renovacao_aviso_imobiliaria' => [
                'name' => 'Aviso de vencimento/renovação às imobiliárias',
                'category' => 'notificacao_externa',
                'description' => 'Monitor diário de renovações.',
                'audience' => 'Imobiliárias',
                'impact_on' => 'As imobiliárias passarão a receber e-mail.',
                'impact_off' => 'As imobiliárias deixam de receber o aviso.',
                'origin' => 'task 0033',
            ],
            'pendencias_aviso_imobiliaria' => ['name' => 'Aviso diário de pendências às imobiliárias', 'origin' => 'task 0031'],
        ]);
        $definition = $virttus->get('renovacao_aviso_imobiliaria');
        $this->assertEquals('As imobiliárias passarão a receber e-mail.', $definition?->impactOn);
        $this->assertEquals('task 0033', $definition?->origin);
        $this->assertEquals('notificacao_externa', $definition?->category);
        $this->assertThrows(InvalidArgumentException::class, fn() => FeatureFlagRegistry::fromArray(['abc' => ['name' => 'A', 'impactOn' => 'typo']]), 'chave desconhecida recusada');
        $this->assertThrows(InvalidArgumentException::class, fn() => FeatureFlagRegistry::fromArray(['abc' => ['name' => 'A', 'target' => 'not a spec']]));
        $this->assertThrows(InvalidArgumentException::class, fn() => FeatureFlagRegistry::fromArray(['abc' => ['name' => 'A', 'subject_key' => 7]]));
    }

    ///////////////////////////////////////////////////////////////////////////
    // D8: motivos e o mapeamento para o OpenFeature
    ///////////////////////////////////////////////////////////////////////////

    private function testReasonMapping(): void
    {
        $expected = [
            'ENABLED' => [true, 'STATIC', null],
            'TARGETING_MATCH' => [true, 'TARGETING_MATCH', null],
            'DISABLED' => [false, 'DISABLED', null],
            'NOT_IN_TARGET' => [false, 'DEFAULT', null],
            'OUTSIDE_VALIDITY' => [false, 'DEFAULT', null],
            'OUTSIDE_ROLLOUT' => [false, 'DEFAULT', null], // forward 040, D6 (2.3.0)
            'UNKNOWN_FLAG' => [false, 'ERROR', 'FLAG_NOT_FOUND'],
            'ERROR' => [false, 'ERROR', 'GENERAL'],
        ];
        $this->assertCount(count($expected), FeatureFlagReason::cases(), 'oito motivos (D8; OUTSIDE_ROLLOUT desde a 2.3.0)');
        foreach (FeatureFlagReason::cases() as $reason) {
            [$on, $openFeature, $errorCode] = $expected[$reason->name];
            $this->assertEquals($on, $reason->isOn(), "{$reason->name}: valor");
            $this->assertEquals($openFeature, $reason->toOpenFeatureReason(), "{$reason->name}: motivo OpenFeature");
            $this->assertEquals($errorCode, $reason->toOpenFeatureErrorCode(), "{$reason->name}: código de erro OpenFeature");
        }
    }

    ///////////////////////////////////////////////////////////////////////////
    // Leitura sem tocar no store (chave não declarada) e serviço
    ///////////////////////////////////////////////////////////////////////////

    private function testEvaluationWithoutStoreAccess(): void
    {
        $probe = new Module27ProbeStore(new InMemoryFeatureFlagStore());
        $probe->failLoad = new RuntimeException('should not be read');
        $flags = new FeatureFlags(new FeatureFlagRegistry(), $probe);

        $evaluation = $flags->evaluate('nobody_declared');
        $this->assertFalse($evaluation->value);
        $this->assertTrue($evaluation->reason === FeatureFlagReason::UNKNOWN_FLAG, 'chave não declarada = UNKNOWN_FLAG');
        $this->assertEquals(0, $probe->loads, 'chave não declarada nem consulta o store');
        $this->assertFalse($flags->isEnabled('Not A Valid Code!'), 'código inválido na leitura: desligada, sem exceção');

        $this->assertThrows(InvalidArgumentException::class, fn() => new FeatureFlags(new FeatureFlagRegistry(), $probe, reasonMinLength: 0));
        $this->assertThrows(InvalidArgumentException::class, fn() => new FeatureFlags(new FeatureFlagRegistry(), $probe, reasonMinLength: 20, reasonMaxLength: 10));
    }

    private function testInMemorySpecifics(): void
    {
        $store = new InMemoryFeatureFlagStore(subjects: false);
        $this->assertFalse($store->supportsSubjects());
        $this->assertThrows(InvalidArgumentException::class, fn() => $store->put(new FeatureFlagState('abc', true, subjects: ['1'])));
        $this->assertThrows(InvalidArgumentException::class, fn() => $store->put(new FeatureFlagState('Bad Code')));

        $registry = new FeatureFlagRegistry([new FeatureFlagDefinition('abc', 'Abc')]);
        $flags = new FeatureFlags($registry, $store);
        $this->assertThrows(FeatureFlagException::class, fn() => $flags->addSubject('abc', 7, 'pilot for unit seven'), 'sem lista de sujeitos: addSubject recusado');
        $this->assertEquals([], $flags->history('abc'), 'nada gravado');
        $this->assertEquals(null, $store->find('abc'), 'a linha criada antes da recusa foi desfeita (rollback)');

        // Alvo cuja evaluate() lança (fora do contrato de AbstractSpecification): ERROR, nunca exceção
        $throwing = new class extends \Antevemus\ASpecification\AbstractSpecification {
            public function getType(): string
            {
                return 'mixed';
            }

            public function isSatisfiedBy(mixed $candidate): bool
            {
                throw new RuntimeException('target down');
            }

            public function evaluate(mixed $candidate): \Antevemus\ASpecification\Results\SpecificationResult
            {
                throw new RuntimeException('target down');
            }
        };
        $reporter = new InMemoryFeatureFlagErrorReporter();
        $targeted = new FeatureFlags(new FeatureFlagRegistry([new FeatureFlagDefinition('abc', 'Abc', target: $throwing)]), (new InMemoryFeatureFlagStore())->put(new FeatureFlagState('abc', true)), $reporter);
        $evaluation = $targeted->evaluate('abc', new Module27Agency(1));
        $this->assertTrue($evaluation->reason === FeatureFlagReason::ERROR, 'alvo que lança = ERROR');
        $this->assertEquals('target down', $reporter->entries()[0]['error']->getMessage(), 'e reportado');

        $view = new FeatureFlagView('abc', null, new FeatureFlagState(
            'abc',
            true,
            enabledAt: new DateTimeImmutable('2026-10-12 10:00:00'),
            enabledBy: '7',
            disabledAt: new DateTimeImmutable('2026-10-12 10:00:00'),
            disabledBy: '8'
        ));
        $this->assertEquals('7', $view->lastChangeBy, 'mesmo instante: vale o estado atual (ligada)');
        $this->assertEquals('abc', $view->name, 'sem nome na linha: o código');
        $this->assertFalse($view->declared);
    }

    ///////////////////////////////////////////////////////////////////////////
    // RN-02: bateria de contrato (mesmos resultados em memória e em PDO)
    ///////////////////////////////////////////////////////////////////////////

    /**
     * @param Closure(): array{store: IFeatureFlagStore, setValidity: Closure(string, ?DateTimeImmutable, ?DateTimeImmutable): void, broken: Closure(): IFeatureFlagStore, seedOrphan: Closure(string, bool): void} $backend
     */
    private function runContract(string $label, Closure $backend): void
    {
        $this->contractBornOffAndToggle($label, $backend());
        $this->contractReason($label, $backend());
        $this->contractUndeclared($label, $backend());
        $this->contractValidity($label, $backend());
        $this->contractSubjects($label, $backend());
        $this->contractSpecificationTarget($label, $backend());
        $this->contractFailClosed($label, $backend());
        $this->contractSameTransaction($label, $backend());
        $this->contractListingAndHistory($label, $backend());
    }

    private function registry(?\Antevemus\ASpecification\Contracts\ISpecification $target = null): FeatureFlagRegistry
    {
        return new FeatureFlagRegistry([
            new FeatureFlagDefinition(
                self::RENEWAL,
                'Renewal notice to agencies',
                'Daily renewal monitor also notifies each agency.',
                'external_notification',
                'Agencies',
                'Agencies start receiving the renewal e-mail.',
                'Agencies stop receiving the renewal e-mail.',
                'task 0033',
                $target,
                'unitId'
            ),
            new FeatureFlagDefinition(self::PENDING, 'Pending items notice', origin: 'task 0031'),
        ]);
    }

    /**
     * @param array<string, mixed> $backend
     */
    private function contractBornOffAndToggle(string $label, array $backend): void
    {
        $clock = new Module27Clock('2026-10-12 10:00:00');
        $store = new Module27ProbeStore($backend['store']);
        $flags = new FeatureFlags($this->registry(), $store, clock: $clock->closure());

        $evaluation = $flags->evaluate(self::RENEWAL);
        $this->assertFalse($evaluation->value, "{$label}: declarada sem linha nasce desligada");
        $this->assertTrue($evaluation->reason === FeatureFlagReason::DISABLED, "{$label}: motivo DISABLED");
        $this->assertEquals(null, $store->find(self::RENEWAL), "{$label}: ler não cria linha");

        // Ligar: nasce a linha (desligada) e liga, com histórico old = null (não existia)
        $state = $flags->toggle(self::RENEWAL, true, "  Pilot   approved\n by the board  ", 42);
        $this->assertTrue($state->enabled, "{$label}: ligada");
        $this->assertEquals('42', $state->enabledBy, "{$label}: quem ligou");
        $this->assertTrue($state->enabledAt == $clock->now, "{$label}: quando ligou");
        $this->assertEquals('Renewal notice to agencies', $state->name, "{$label}: a linha nasce com o nome do registro");
        $this->assertEquals('external_notification', $state->category, "{$label}: e a categoria");
        $this->assertTrue($flags->isEnabled(self::RENEWAL), "{$label}: a mesma instância vê a troca");
        $this->assertTrue($flags->evaluate(self::RENEWAL)->reason === FeatureFlagReason::ENABLED, "{$label}: sem alvo = ENABLED");

        $history = $flags->history(self::RENEWAL);
        $this->assertCount(1, $history, "{$label}: uma linha de histórico");
        $this->assertEquals(null, $history[0]->oldEnabled, "{$label}: old_enabled nulo = não existia");
        $this->assertTrue($history[0]->newEnabled, "{$label}: new_enabled");
        $this->assertEquals('Pilot approved by the board', $history[0]->reason, "{$label}: motivo normalizado");
        $this->assertEquals(FeatureFlags::ORIGIN_UI, $history[0]->origin, "{$label}: origem padrão");
        $this->assertEquals('42', $history[0]->changedBy, "{$label}: quem");
        $this->assertFalse($history[0]->isSubjectChange());

        // CAS: ligar de novo é recusado e não grava nada
        $clock->advance();
        $stale = $this->assertThrows(StaleFeatureFlagException::class, fn() => $flags->toggle(self::RENEWAL, true, 'Second click on a stale screen', 43), "{$label}: troca obsoleta recusada");
        $this->assertEquals(self::RENEWAL, $stale instanceof StaleFeatureFlagException ? $stale->getFlagCode() : null);
        $this->assertCount(1, $flags->history(self::RENEWAL), "{$label}: troca obsoleta não grava histórico");
        $this->assertEquals('42', $store->find(self::RENEWAL)?->enabledBy, "{$label}: troca obsoleta não carimba");

        // Desligar
        $clock->advance();
        $state = $flags->toggle(self::RENEWAL, false, 'Agencies complained about volume', 'ops-team', FeatureFlags::ORIGIN_SCRIPT);
        $this->assertFalse($state->enabled, "{$label}: desligada");
        $this->assertEquals('ops-team', $state->disabledBy, "{$label}: quem desligou");
        $this->assertEquals('42', $state->enabledBy, "{$label}: o carimbo de ligar fica");
        $this->assertFalse($flags->isEnabled(self::RENEWAL), "{$label}: desligada para a instância");
        $history = $flags->history(self::RENEWAL);
        $this->assertCount(2, $history);
        $this->assertTrue($history[0]->oldEnabled === true && $history[0]->newEnabled === false, "{$label}: mais recente primeiro, ligada → desligada");
        $this->assertEquals(FeatureFlags::ORIGIN_SCRIPT, $history[0]->origin);
        $this->assertThrows(StaleFeatureFlagException::class, fn() => $flags->toggle(self::RENEWAL, false, 'Turning off twice by mistake'), "{$label}: desligar desligada é obsoleto");

        // Desligar uma declarada SEM linha: obsoleto (já desligada) e a linha não fica criada
        $this->assertThrows(StaleFeatureFlagException::class, fn() => $flags->toggle(self::PENDING, false, 'Nothing to turn off here'), "{$label}: declarada sem linha já está desligada");
        $this->assertEquals(null, $store->find(self::PENDING), "{$label}: a linha criada na tentativa foi desfeita");

        $this->assertFalse($flags->isEnabled(self::RENEWAL), "{$label}: carregada depois da última troca");

        // Outra instância (outra request) sobre o mesmo store vê o estado; o actor nulo vira script
        $other = new FeatureFlags($this->registry(), $backend['store'], clock: $clock->closure());
        $this->assertFalse($other->isEnabled(self::RENEWAL));
        $clock->advance();
        $other->toggle(self::RENEWAL, true, 'Board approved the pilot again', null, 'tela');
        $this->assertEquals(null, $other->history(self::RENEWAL, 1)[0]->changedBy, "{$label}: actor nulo");
        $this->assertEquals('tela', $other->history(self::RENEWAL, 1)[0]->origin, "{$label}: origem livre do app");
        $this->assertFalse($flags->isEnabled(self::RENEWAL), "{$label}: a primeira instância guarda o que leu (D10)");
        $flags->refresh();
        $this->assertTrue($flags->isEnabled(self::RENEWAL), "{$label}: refresh() lê de novo");
    }

    /**
     * @param array<string, mixed> $backend
     */
    private function contractReason(string $label, array $backend): void
    {
        $flags = new FeatureFlags($this->registry(), $backend['store']);
        foreach (['', '         ', 'too short', "  a  b  c  d  "] as $short) {
            $this->assertThrows(InvalidFeatureFlagReasonException::class, fn() => $flags->toggle(self::RENEWAL, true, $short), "{$label}: motivo curto recusado");
        }
        $this->assertThrows(InvalidFeatureFlagReasonException::class, fn() => $flags->toggle(self::RENEWAL, true, str_repeat('x', 1001)), "{$label}: motivo longo recusado");
        $this->assertThrows(InvalidArgumentException::class, fn() => $flags->toggle(self::RENEWAL, true, 'A good enough reason', origin: '  '), "{$label}: origem vazia recusada");
        $this->assertThrows(InvalidArgumentException::class, fn() => $flags->toggle('Bad Code', true, 'A good enough reason'), "{$label}: código inválido recusado na troca");
        $this->assertEquals([], $flags->history(), "{$label}: nada gravado pelas recusas");
        $this->assertEquals(null, $backend['store']->find(self::RENEWAL), "{$label}: nenhuma linha criada pelas recusas");

        $flags->toggle(self::RENEWAL, true, '1234567890');
        $this->assertTrue($flags->isEnabled(self::RENEWAL), "{$label}: 10 caracteres exatos aceitos");
        $flags->toggle(self::RENEWAL, false, str_repeat('é', 1000));
        $this->assertEquals(1000, mb_strlen($flags->history(self::RENEWAL, 1)[0]->reason), "{$label}: 1000 caracteres (multibyte) aceitos");

        $strict = new FeatureFlags($this->registry(), $backend['store'], reasonMinLength: 20, reasonMaxLength: 30);
        $this->assertThrows(InvalidFeatureFlagReasonException::class, fn() => $strict->toggle(self::RENEWAL, true, 'only fifteen ch'), "{$label}: mínimo configurável");
        $this->assertThrows(InvalidFeatureFlagReasonException::class, fn() => $strict->toggle(self::RENEWAL, true, str_repeat('y', 31)), "{$label}: máximo configurável");
    }

    /**
     * @param array<string, mixed> $backend
     */
    private function contractUndeclared(string $label, array $backend): void
    {
        $backend['seedOrphan'](self::ORPHAN, true);
        $reporter = new InMemoryFeatureFlagErrorReporter();
        $flags = new FeatureFlags($this->registry(), $backend['store'], $reporter);

        $evaluation = $flags->evaluate(self::ORPHAN);
        $this->assertFalse($evaluation->value, "{$label}: ligada no banco mas não declarada = desligada");
        $this->assertTrue($evaluation->reason === FeatureFlagReason::UNKNOWN_FLAG, "{$label}: UNKNOWN_FLAG");
        $this->assertEquals([], $reporter->entries(), "{$label}: chave não declarada não é erro de leitura");

        $this->assertThrows(UndeclaredFeatureFlagException::class, fn() => $flags->toggle('never_declared', true, 'Turning on an unknown key'), "{$label}: ligar chave não declarada recusado");
        $this->assertThrows(UndeclaredFeatureFlagException::class, fn() => $flags->addSubject(self::ORPHAN, 1, 'Targeting an unknown key'), "{$label}: alvo em chave não declarada recusado");
        $this->assertEquals(null, $backend['store']->find('never_declared'), "{$label}: nada criado");

        // Desligar a órfã é permitido (limpeza), com histórico
        $state = $flags->toggle(self::ORPHAN, false, 'Cleanup of an orphan key', 1, FeatureFlags::ORIGIN_MIGRATION);
        $this->assertFalse($state->enabled, "{$label}: órfã desligada");
        $this->assertTrue($flags->history(self::ORPHAN)[0]->oldEnabled === true, "{$label}: histórico da órfã");
        $this->assertThrows(FeatureFlagException::class, fn() => $flags->toggle('never_stored', false, 'Turning off a ghost key'), "{$label}: nem declarada nem gravada");
    }

    /**
     * @param array<string, mixed> $backend
     */
    private function contractValidity(string $label, array $backend): void
    {
        $clock = new Module27Clock('2026-10-12 10:00:00');
        $flags = new FeatureFlags($this->registry(), $backend['store'], clock: $clock->closure());
        $flags->toggle(self::RENEWAL, true, 'Scheduled campaign window');

        $from = new DateTimeImmutable('2026-11-01 08:00:00', new DateTimeZone('UTC'));
        $until = new DateTimeImmutable('2026-11-30 18:00:00', new DateTimeZone('UTC'));
        $backend['setValidity'](self::RENEWAL, $from, $until);
        $flags->refresh();

        $this->assertTrue($flags->evaluate(self::RENEWAL)->reason === FeatureFlagReason::OUTSIDE_VALIDITY, "{$label}: antes da vigência");
        $this->assertFalse($flags->isEnabled(self::RENEWAL), "{$label}: antes da vigência desligada");
        $this->assertTrue($flags->withAsOf($from)->isEnabled(self::RENEWAL), "{$label}: início inclusivo");
        $this->assertTrue($flags->withAsOf($until)->isEnabled(self::RENEWAL), "{$label}: fim inclusivo");
        $this->assertFalse($flags->withAsOf($until->modify('+1 second'))->isEnabled(self::RENEWAL), "{$label}: depois do fim");
        $this->assertFalse($flags->withAsOf($from->modify('-1 second'))->isEnabled(self::RENEWAL), "{$label}: antes do início");
        $this->assertEquals(null, $flags->getAsOf(), "{$label}: withAsOf não muda o original");
        $clock->now = new DateTimeImmutable('2026-11-15 12:00:00', new DateTimeZone('UTC'));
        $this->assertTrue($flags->isEnabled(self::RENEWAL), "{$label}: o relógio dentro da vigência");

        $backend['setValidity'](self::RENEWAL, null, $from);
        $flags->refresh();
        $this->assertTrue($flags->evaluate(self::RENEWAL)->reason === FeatureFlagReason::OUTSIDE_VALIDITY, "{$label}: só fim, já passado");
        $backend['setValidity'](self::RENEWAL, $from, null);
        $flags->refresh();
        $this->assertTrue($flags->isEnabled(self::RENEWAL), "{$label}: só início, já passado");
        $this->assertFalse($flags->withAsOf(new DateTimeImmutable('2026-10-01'))->isEnabled(self::RENEWAL), "{$label}: só início, ainda não");
    }

    /**
     * @param array<string, mixed> $backend
     */
    private function contractSubjects(string $label, array $backend): void
    {
        $clock = new Module27Clock('2026-10-12 10:00:00');
        $flags = new FeatureFlags($this->registry(), $backend['store'], clock: $clock->closure());
        $seven = new Module27Agency(7);
        $eight = new Module27Agency(8);

        // Alvo antes de ligar: a linha nasce desligada e a lista vale quando ligar
        $flags->addSubject(self::RENEWAL, 7, 'Pilot with agency seven', 'board');
        $this->assertFalse($flags->isEnabled(self::RENEWAL, $seven), "{$label}: com lista mas desligada");
        $this->assertTrue($flags->evaluate(self::RENEWAL, $seven)->reason === FeatureFlagReason::DISABLED);
        $clock->advance();
        $flags->toggle(self::RENEWAL, true, 'Pilot starts today');
        $this->assertTrue($flags->isEnabled(self::RENEWAL, $seven), "{$label}: sujeito na lista");
        $match = $flags->evaluate(self::RENEWAL, $seven);
        $this->assertTrue($match->reason === FeatureFlagReason::TARGETING_MATCH, "{$label}: TARGETING_MATCH");
        $this->assertEquals('7', $match->subjectKey, "{$label}: chave lida pelo caminho declarado");
        $miss = $flags->evaluate(self::RENEWAL, $eight);
        $this->assertTrue($miss->reason === FeatureFlagReason::NOT_IN_TARGET, "{$label}: fora da lista");
        $this->assertFalse($flags->isEnabled(self::RENEWAL), "{$label}: com lista, sem sujeito = fora");
        $this->assertFalse($flags->isEnabled(self::RENEWAL, new Module27Agency(null)), "{$label}: chave nula = fora");
        $this->assertTrue($flags->isEnabled(self::RENEWAL, 7), "{$label}: sujeito inteiro é a própria chave");
        $this->assertTrue($flags->isEnabled(self::RENEWAL, '7'), "{$label}: sujeito texto é a própria chave");
        $this->assertTrue($flags->isEnabled(self::RENEWAL, ['unitId' => 7]), "{$label}: sujeito array pelo caminho");
        $this->assertFalse($flags->isEnabled(self::RENEWAL, new \stdClass()), "{$label}: sujeito sem a propriedade = fora");

        $clock->advance();
        $this->assertThrows(StaleFeatureFlagException::class, fn() => $flags->addSubject(self::RENEWAL, '7', 'Adding the same agency twice'), "{$label}: sujeito repetido = obsoleto");
        $this->assertThrows(StaleFeatureFlagException::class, fn() => $flags->removeSubject(self::RENEWAL, 8, 'Removing an absent agency'), "{$label}: remover ausente = obsoleto");
        $this->assertThrows(InvalidArgumentException::class, fn() => $flags->addSubject(self::RENEWAL, '  ', 'Blank subject key here'), "{$label}: chave em branco");
        $this->assertThrows(InvalidFeatureFlagReasonException::class, fn() => $flags->addSubject(self::RENEWAL, 9, 'short'), "{$label}: motivo do alvo");

        $flags->addSubject(self::RENEWAL, 8, 'Second agency joins the pilot', 'board');
        $this->assertTrue($flags->isEnabled(self::RENEWAL, $eight), "{$label}: segundo sujeito");
        $this->assertEquals(['7', '8'], $backend['store']->find(self::RENEWAL)?->subjects, "{$label}: lista gravada");

        $clock->advance();
        $flags->removeSubject(self::RENEWAL, 7, 'Agency seven left the pilot', 'board');
        $this->assertFalse($flags->isEnabled(self::RENEWAL, $seven), "{$label}: removido");
        $this->assertTrue($flags->isEnabled(self::RENEWAL, $eight));
        $clock->advance();
        $flags->removeSubject(self::RENEWAL, 8, 'Pilot over, release to everyone');
        $this->assertTrue($flags->evaluate(self::RENEWAL, $seven)->reason === FeatureFlagReason::ENABLED, "{$label}: lista vazia = sem restrição");
        $this->assertTrue($flags->isEnabled(self::RENEWAL), "{$label}: lista vazia, sem sujeito = ligada");

        $subjectRows = array_values(array_filter($flags->history(self::RENEWAL), static fn(FeatureFlagChange $c): bool => $c->isSubjectChange()));
        $this->assertCount(4, $subjectRows, "{$label}: quatro trocas de lista no histórico");
        $this->assertTrue($subjectRows[0]->subjectKey === '8' && $subjectRows[0]->oldEnabled === true && $subjectRows[0]->newEnabled === false, "{$label}: remoção = true → false");
        $this->assertTrue($subjectRows[3]->subjectKey === '7' && $subjectRows[3]->oldEnabled === false && $subjectRows[3]->newEnabled === true, "{$label}: inclusão = false → true");
        $this->assertEquals('board', $subjectRows[3]->changedBy);

        // Caminho do sujeito que não é texto nem inteiro: erro de alvo, desligada e reportado
        $reporter = new InMemoryFeatureFlagErrorReporter();
        $flags2 = new FeatureFlags($this->registry(), $backend['store'], $reporter);
        $flags2->addSubject(self::RENEWAL, 1, 'Pilot for the error case');
        $evaluation = $flags2->evaluate(self::RENEWAL, new Module27Agency('1'));
        $this->assertTrue($evaluation->value, "{$label}: chave texto");
        $error = $flags2->evaluate(self::RENEWAL, ['unitId' => ['nested']]);
        $this->assertTrue($error->reason === FeatureFlagReason::ERROR, "{$label}: chave de tipo errado = ERROR");
        $this->assertCount(1, $reporter->entries(), "{$label}: reportado");
    }

    /**
     * @param array<string, mixed> $backend
     */
    private function contractSpecificationTarget(string $label, array $backend): void
    {
        $gold = new PredicateSpecification(static fn(Module27Agency $agency): bool => $agency->plan === 'gold');
        $reporter = new InMemoryFeatureFlagErrorReporter();
        $flags = new FeatureFlags($this->registry($gold), $backend['store'], $reporter);
        $flags->toggle(self::RENEWAL, true, 'Gold agencies only');

        $this->assertTrue($flags->isEnabled(self::RENEWAL, new Module27Agency(1, 'gold')), "{$label}: especificação satisfeita");
        $this->assertTrue($flags->evaluate(self::RENEWAL, new Module27Agency(1, 'gold'))->reason === FeatureFlagReason::TARGETING_MATCH);
        $this->assertTrue($flags->evaluate(self::RENEWAL, new Module27Agency(1, 'basic'))->reason === FeatureFlagReason::NOT_IN_TARGET, "{$label}: especificação não satisfeita");
        $this->assertTrue($flags->evaluate(self::RENEWAL)->reason === FeatureFlagReason::NOT_IN_TARGET, "{$label}: alvo em código sem sujeito = fora");

        // Combinada com a lista (E)
        $flags->addSubject(self::RENEWAL, 2, 'Pilot with agency two');
        $this->assertFalse($flags->isEnabled(self::RENEWAL, new Module27Agency(1, 'gold')), "{$label}: gold fora da lista");
        $this->assertFalse($flags->isEnabled(self::RENEWAL, new Module27Agency(2, 'basic')), "{$label}: na lista mas não gold");
        $this->assertTrue($flags->isEnabled(self::RENEWAL, new Module27Agency(2, 'gold')), "{$label}: na lista e gold");

        // Especificação que não avalia (sujeito de outro tipo): ERROR, reportado, sem exceção
        $this->assertEquals([], $reporter->entries());
        $evaluation = $flags->evaluate(self::RENEWAL, ['unitId' => 2]);
        $this->assertTrue($evaluation->reason === FeatureFlagReason::ERROR, "{$label}: alvo que lança = ERROR");
        $this->assertTrue($evaluation->error !== null, "{$label}: a exceção vai na avaliação");
        $this->assertCount(1, $reporter->entries(), "{$label}: gancho chamado");
        $this->assertEquals(self::RENEWAL, $reporter->entries()[0]['code']);

        // spec(): compõe e reporta o motivo na falha
        $spec = $flags->spec(self::RENEWAL);
        $this->assertInstanceOf(FeatureEnabledSpecification::class, $spec);
        $this->assertTrue($spec->isSatisfiedBy(new Module27Agency(2, 'gold')));
        $active = new PredicateSpecification(static fn(Module27Agency $agency): bool => $agency->unitId !== 99);
        $this->assertTrue($spec->and($active)->isSatisfiedBy(new Module27Agency(2, 'gold')), "{$label}: compõe com and()");
        $this->assertTrue($spec->not()->isSatisfiedBy(new Module27Agency(2, 'basic')), "{$label}: compõe com not()");
        $result = $spec->evaluate(new Module27Agency(1, 'gold'));
        $this->assertFalse($result->isSatisfied);
        $this->assertFalse($result->isError, "{$label}: desligada é falha, não erro de avaliação");
        $metadata = $result->failures[0]->metadata;
        $this->assertEquals('not_in_target', $metadata['reason'], "{$label}: motivo nos metadados");
        $this->assertEquals('DEFAULT', $metadata['open_feature_reason']);
        $this->assertEquals('1', $metadata['subject_key']);
        $this->assertEquals('featureEnabled(' . self::RENEWAL . ')', $result->failures[0]->ruleName);
        $custom = $spec->because('Not part of the pilot')->withCode('PILOT')->evaluate(new Module27Agency(1, 'gold'));
        $this->assertEquals('Not part of the pilot', $custom->failures[0]->message);
        $this->assertEquals('PILOT', $custom->failures[0]->code);
        $this->assertTrue($spec->equals($flags->spec(self::RENEWAL)));
        $this->assertFalse($spec->equals($flags->spec(self::PENDING)));
        $this->assertEquals("featureEnabled('renewal_notice')", (string) $spec);
    }

    /**
     * @param array<string, mixed> $backend
     */
    private function contractFailClosed(string $label, array $backend): void
    {
        // Ligada de verdade no store bom...
        (new FeatureFlags($this->registry(), $backend['store']))->toggle(self::RENEWAL, true, 'On before the outage');

        // ...e lida por um store que falha (sem tabela, sem conexão)
        $reporter = new InMemoryFeatureFlagErrorReporter();
        $probe = new Module27ProbeStore($backend['broken']());
        $flags = new FeatureFlags($this->registry(), $probe, $reporter);
        for ($i = 0; $i < 25; $i++) {
            $this->assertFalse($flags->isEnabled(self::RENEWAL, new Module27Agency($i)), "{$label}: erro de leitura = desligada");
        }
        $evaluation = $flags->evaluate(self::PENDING);
        $this->assertTrue($evaluation->reason === FeatureFlagReason::ERROR, "{$label}: motivo ERROR");
        $this->assertTrue($evaluation->error instanceof Throwable, "{$label}: a exceção vai na avaliação");
        $this->assertEquals('GENERAL', $evaluation->toMetadata()['open_feature_error_code']);
        $this->assertEquals(1, $probe->loads, "{$label}: a falha também é guardada (uma leitura por instância)");
        $this->assertCount(1, $reporter->entries(), "{$label}: gancho chamado uma vez por carga");
        $this->assertEquals(self::RENEWAL, $reporter->entries()[0]['code'], "{$label}: com a chave que estava sendo avaliada");
        $this->assertTrue($flags->lastError() !== null, "{$label}: lastError() para a tela avisar");
        $views = $flags->all();
        $this->assertCount(2, $views, "{$label}: listagem mostra as declaradas");
        $this->assertFalse($views[0]->enabled, "{$label}: listadas desligadas");
        $this->assertFalse($flags->spec(self::RENEWAL)->isSatisfiedBy(new Module27Agency(1)), "{$label}: spec desligada");
        $this->assertTrue($flags->spec(self::RENEWAL)->not()->isSatisfiedBy(new Module27Agency(1)), "{$label}: falha fechada: not() da desligada");
        $this->assertEquals('error', $flags->spec(self::RENEWAL)->evaluate(new Module27Agency(1))->failures[0]->metadata['reason']);

        $flags->refresh();
        $this->assertFalse($flags->isEnabled(self::RENEWAL));
        $this->assertEquals(2, $probe->loads, "{$label}: refresh() tenta de novo");
        $this->assertCount(2, $reporter->entries(), "{$label}: e reporta de novo");

        // Um gancho que lança não muda o valor
        $throwing = new class implements \Antevemus\ASpecification\FeatureFlags\IFeatureFlagErrorReporter {
            public function report(?string $code, Throwable $error): void
            {
                throw new RuntimeException('logger down');
            }
        };
        $this->assertFalse((new FeatureFlags($this->registry(), $backend['broken'](), $throwing))->isEnabled(self::RENEWAL), "{$label}: gancho quebrado ignorado");

        // Sem gancho: desligada do mesmo jeito
        $this->assertFalse((new FeatureFlags($this->registry(), $backend['broken']()))->isEnabled(self::RENEWAL));

        // O store bom continua ligado (a falha era só de leitura)
        $this->assertTrue((new FeatureFlags($this->registry(), $backend['store']))->isEnabled(self::RENEWAL), "{$label}: o estado real não mudou");
    }

    /**
     * @param array<string, mixed> $backend
     */
    private function contractSameTransaction(string $label, array $backend): void
    {
        $probe = new Module27ProbeStore($backend['store']);
        $flags = new FeatureFlags($this->registry(), $probe);
        $probe->failAppend = true;
        $this->assertThrows(FeatureFlagStoreException::class, fn() => $flags->toggle(self::RENEWAL, true, 'History write will fail'), "{$label}: falha do histórico propaga");
        $this->assertEquals(null, $backend['store']->find(self::RENEWAL), "{$label}: sem histórico, nem a linha nem a troca ficam");
        $probe->failAppend = false;
        $flags->toggle(self::RENEWAL, true, 'History write works now');
        $probe->failAppend = true;
        $this->assertThrows(FeatureFlagStoreException::class, fn() => $flags->toggle(self::RENEWAL, false, 'History write fails again'));
        $this->assertTrue($backend['store']->find(self::RENEWAL)?->enabled === true, "{$label}: a troca foi desfeita com o histórico");
        $this->assertTrue($flags->isEnabled(self::RENEWAL), "{$label}: a instância relê depois da falha");
        $this->assertThrows(FeatureFlagStoreException::class, fn() => $flags->addSubject(self::RENEWAL, 5, 'Subject history fails'));
        $this->assertEquals([], $backend['store']->find(self::RENEWAL)?->subjects, "{$label}: sujeito desfeito com o histórico");
        $this->assertCount(1, $flags->history(self::RENEWAL), "{$label}: só a troca que deu certo");
    }

    /**
     * @param array<string, mixed> $backend
     */
    private function contractListingAndHistory(string $label, array $backend): void
    {
        $clock = new Module27Clock('2026-10-12 10:00:00');
        $backend['seedOrphan'](self::ORPHAN, false);
        $flags = new FeatureFlags($this->registry(), $backend['store'], clock: $clock->closure());
        $flags->toggle(self::RENEWAL, true, 'First switch on of the renewal', 10);
        $clock->advance();
        $flags->toggle(self::RENEWAL, false, 'First switch off of the renewal', 11);
        $clock->advance();
        $flags->toggle(self::PENDING, true, 'Pending notices go live', 12);

        $views = $flags->all();
        $this->assertEquals([self::RENEWAL, self::PENDING, self::ORPHAN], array_map(static fn(FeatureFlagView $v): string => $v->code, $views), "{$label}: registro na ordem, depois as não declaradas");
        [$renewal, $pending, $orphan] = $views;
        $this->assertTrue($renewal->declared && $renewal->stored && !$renewal->enabled, "{$label}: renewal declarada, gravada, desligada");
        $this->assertEquals('Agencies start receiving the renewal e-mail.', $renewal->impactOn, "{$label}: texto do registro");
        $this->assertEquals('11', $renewal->lastChangeBy, "{$label}: última troca = a de desligar");
        $this->assertTrue($renewal->lastChangeAt == $clock->now->modify('-1 second'), "{$label}: quando");
        $this->assertTrue($pending->enabled && $pending->lastChangeBy === '12');
        $this->assertFalse($orphan->declared, "{$label}: órfã não declarada");
        $this->assertEquals('Orphan from the database', $orphan->name, "{$label}: texto da linha para a não declarada");
        $this->assertEquals(null, $orphan->lastChangeAt, "{$label}: nunca trocada");

        $all = $flags->history();
        $this->assertCount(3, $all, "{$label}: histórico de todas");
        $this->assertEquals([self::PENDING, self::RENEWAL, self::RENEWAL], array_map(static fn(FeatureFlagChange $c): string => $c->code, $all), "{$label}: mais recente primeiro");
        $this->assertCount(2, $flags->history(null, 2), "{$label}: limite");
        $this->assertEquals('First switch off of the renewal', $flags->history(self::RENEWAL, 1)[0]->reason);
        $this->assertTrue($flags->history(self::RENEWAL, 1)[0]->changedAt == $clock->now->modify('-1 second'), "{$label}: instante do histórico");
        $this->assertThrows(InvalidArgumentException::class, fn() => $flags->history(null, 0));

        // Mesmo instante: a ordem de inserção decide
        $same = new FeatureFlags($this->registry(), $backend['store'], clock: fn(): DateTimeImmutable => new DateTimeImmutable('2026-12-01 00:00:00', new DateTimeZone('UTC')));
        $same->toggle(self::PENDING, false, 'Same instant, first change');
        $same->toggle(self::PENDING, true, 'Same instant, second change');
        $this->assertEquals('Same instant, second change', $same->history(self::PENDING, 1)[0]->reason, "{$label}: empate de instante resolvido pela inserção");
    }

    ///////////////////////////////////////////////////////////////////////////
    // Backends
    ///////////////////////////////////////////////////////////////////////////

    /**
     * @return array<string, mixed>
     */
    private function inMemoryBackend(): array
    {
        $store = new InMemoryFeatureFlagStore();

        return [
            'store' => $store,
            'setValidity' => static function (string $code, ?DateTimeImmutable $from, ?DateTimeImmutable $until) use ($store): void {
                $s = $store->find($code) ?? throw new RuntimeException('no row');
                $store->put(new FeatureFlagState($s->code, $s->enabled, $s->name, $s->description, $s->category, $s->audience, $s->notes, $from, $until, $s->enabledAt, $s->enabledBy, $s->disabledAt, $s->disabledBy, $s->version, $s->subjects));
            },
            'broken' => static function (): IFeatureFlagStore {
                $probe = new Module27ProbeStore(new InMemoryFeatureFlagStore());
                $probe->failLoad = new FeatureFlagStoreException('connection refused (test)');

                return $probe;
            },
            'seedOrphan' => static function (string $code, bool $enabled) use ($store): void {
                $store->put(new FeatureFlagState($code, $enabled, 'Orphan from the database'));
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pdoBackend(PDO $pdo, string $family): array
    {
        $store = new PdoFeatureFlagStore($pdo);

        return [
            'store' => $store,
            'setValidity' => static function (string $code, ?DateTimeImmutable $from, ?DateTimeImmutable $until) use ($pdo): void {
                $statement = $pdo->prepare('UPDATE feature_flag SET valid_from = ?, valid_until = ? WHERE code = ?');
                $statement->execute([$from?->format('Y-m-d H:i:s.u'), $until?->format('Y-m-d H:i:s.u'), $code]);
            },
            'broken' => static fn(): IFeatureFlagStore => new PdoFeatureFlagStore($pdo, tables: ['flag' => 'missing_feature_flag']),
            'seedOrphan' => static function (string $code, bool $enabled) use ($pdo): void {
                $statement = $pdo->prepare('INSERT INTO feature_flag (code, name, enabled) VALUES (?, ?, ?)');
                $statement->execute([$code, 'Orphan from the database', $enabled ? 'Y' : 'N']);
            },
        ];
    }

    private function sqlite(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::loadDdl($pdo, (string) file_get_contents(self::DDL));

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

    private function resetSchema(PDO $pdo): void
    {
        $this->dropSchema($pdo);
        self::loadDdl($pdo, (string) file_get_contents(self::DDL));
    }

    private function dropSchema(PDO $pdo): void
    {
        foreach (['feature_flag_subject', 'feature_flag_change', 'feature_flag', 'aspec_ff_flags', 'aspec_ff_audit'] as $table) {
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

    ///////////////////////////////////////////////////////////////////////////
    // D3: opções do PdoFeatureFlagStore
    ///////////////////////////////////////////////////////////////////////////

    private function testPdoConfiguration(): void
    {
        $pdo = $this->sqlite();
        $bad = [
            'schema' => fn() => new PdoFeatureFlagStore($pdo, 'bad schema'),
            'role' => fn() => new PdoFeatureFlagStore($pdo, tables: ['flags' => 'x']),
            'table' => fn() => new PdoFeatureFlagStore($pdo, tables: ['flag' => 'x; DROP TABLE y']),
            'flag table null' => fn() => new PdoFeatureFlagStore($pdo, tables: ['flag' => null]),
            'column role' => fn() => new PdoFeatureFlagStore($pdo, columns: ['flags' => []]),
            'column' => fn() => new PdoFeatureFlagStore($pdo, columns: ['flag' => ['codes' => 'x']]),
            'real column' => fn() => new PdoFeatureFlagStore($pdo, columns: ['flag' => ['code' => 'co de']]),
            'required column' => fn() => new PdoFeatureFlagStore($pdo, columns: ['flag' => ['enabled' => null]]),
            'required change column' => fn() => new PdoFeatureFlagStore($pdo, columns: ['change' => ['reason' => null]]),
            'subjects need subject_key in history' => fn() => new PdoFeatureFlagStore($pdo, columns: ['change' => ['subject_key' => null]]),
            'generated id needs the column' => fn() => new PdoFeatureFlagStore($pdo, columns: ['change' => ['change_id' => null]], databaseGeneratedChangeId: true),
            'scope column' => fn() => new PdoFeatureFlagStore($pdo, changeScope: ['bad col' => 'x']),
            'scope value' => fn() => new PdoFeatureFlagStore($pdo, changeScope: ['entity_type' => 1.5]),
            'scope over mapped column' => fn() => new PdoFeatureFlagStore($pdo, changeScope: ['reason' => 'x']),
            'boolean role' => fn() => new PdoFeatureFlagStore($pdo, booleans: ['subject' => BooleanStorage::Char]),
            'boolean pair' => fn() => new PdoFeatureFlagStore($pdo, booleans: ['flag' => ['yes', 'YES']]),
            'boolean pair size' => fn() => new PdoFeatureFlagStore($pdo, booleans: ['flag' => ['S']]),
            'time zone' => fn() => new PdoFeatureFlagStore($pdo, timezone: 'Mars/Olympus'),
            'format' => fn() => new PdoFeatureFlagStore($pdo, timestampFormat: ' '),
        ];
        foreach ($bad as $what => $build) {
            $this->assertThrows(InvalidArgumentException::class, $build, "configuração recusada: {$what}");
        }

        $store = new PdoFeatureFlagStore($pdo, 'main');
        $this->assertEquals('main.feature_flag', $store->getTables()['flag'], 'tabela qualificada pelo schema');
        $this->assertEquals(null, $store->getColumns()['flag']['deleted_at'], 'deleted_at ausente por padrão');
        $this->assertEquals('enabled', $store->getColumns()['flag']['enabled']);
        $this->assertTrue((new PdoFeatureFlagStore($pdo, tables: ['subject' => null], columns: ['change' => ['subject_key' => null]]))->supportsSubjects() === false);

        // Sem tabela de sujeitos: avaliação vê listas vazias; addSubject recusado sem gravar nada
        $noSubjects = new PdoFeatureFlagStore($pdo, tables: ['subject' => null]);
        $flags = new FeatureFlags($this->registry(), $noSubjects);
        $flags->toggle(self::RENEWAL, true, 'On for everyone here');
        $this->assertTrue($flags->isEnabled(self::RENEWAL, new Module27Agency(3)));
        $this->assertThrows(FeatureFlagStoreException::class, fn() => $flags->addSubject(self::RENEWAL, 3, 'No subject table here'));
        $this->assertCount(1, $flags->history(self::RENEWAL), 'recusa sem gravar histórico');
    }

    /** D10: uma instrução SQL por instância, para qualquer número de avaliações. */
    private function testPdoOneStatementPerLoad(): void
    {
        $pdo = $this->sqlite();
        $logger = new InMemoryQueryLogger();
        $store = (new PdoFeatureFlagStore($pdo))->withQueryLogger($logger, true);
        $this->assertTrue($store->getQueryLogger() === $logger);
        $writer = new FeatureFlags($this->registry(), $store);
        $writer->toggle(self::RENEWAL, true, 'On for the benchmark');
        $writer->addSubject(self::RENEWAL, 1, 'Agency one in the list');
        $this->assertTrue($logger->entries()[0]['parameters'] !== null, 'parâmetros logados quando pedido');

        $logger->clear();
        $flags = new FeatureFlags($this->registry(), $store);
        for ($i = 0; $i < 200; $i++) {
            $flags->isEnabled(self::RENEWAL, new Module27Agency($i % 3));
            $flags->isEnabled(self::PENDING);
        }
        $this->assertCount(1, $logger->entries(), 'D10: 400 avaliações = 1 instrução (flags LEFT JOIN sujeitos)');
        $this->assertTrue(str_contains($logger->statements()[0], 'LEFT JOIN feature_flag_subject'), 'a lista vem junto');
        $flags->refresh();
        $flags->isEnabled(self::RENEWAL);
        $this->assertCount(2, $logger->entries(), 'refresh() = mais uma');

        $quiet = (new PdoFeatureFlagStore($pdo))->withQueryLogger($logger);
        $logger->clear();
        $quiet->loadAll();
        $this->assertEquals(null, $logger->entries()[0]['parameters'], 'valores fora do log por padrão');
    }

    /** Vigência gravada só com a data: o dia inteiro, nos dois limites. */
    private function testPdoValidityText(): void
    {
        $pdo = $this->sqlite();
        $flags = new FeatureFlags($this->registry(), new PdoFeatureFlagStore($pdo));
        $flags->toggle(self::RENEWAL, true, 'Campaign of November');
        $pdo->exec("UPDATE feature_flag SET valid_from = '2026-11-01', valid_until = '2026-11-30' WHERE code = 'renewal_notice'");
        $flags->refresh();
        $utc = new DateTimeZone('UTC');
        $this->assertFalse($flags->withAsOf(new DateTimeImmutable('2026-10-31 23:59:59', $utc))->isEnabled(self::RENEWAL), 'véspera');
        $this->assertTrue($flags->withAsOf(new DateTimeImmutable('2026-11-01 00:00:00', $utc))->isEnabled(self::RENEWAL), 'primeiro instante do dia');
        $this->assertTrue($flags->withAsOf(new DateTimeImmutable('2026-11-30 23:59:59', $utc))->isEnabled(self::RENEWAL), 'último dia inteiro');
        $this->assertFalse($flags->withAsOf(new DateTimeImmutable('2026-12-01 00:00:00', $utc))->isEnabled(self::RENEWAL), 'dia seguinte');

        // Fuso do store: a data é o dia em São Paulo
        $local = new FeatureFlags($this->registry(), new PdoFeatureFlagStore($pdo, timezone: 'America/Sao_Paulo'));
        $this->assertTrue($local->withAsOf(new DateTimeImmutable('2026-12-01 02:59:59', $utc))->isEnabled(self::RENEWAL), '30/11 23:59:59 em São Paulo');
        $this->assertFalse($local->withAsOf(new DateTimeImmutable('2026-12-01 03:00:00', $utc))->isEnabled(self::RENEWAL), '01/12 00:00 em São Paulo');

        $pdo->exec("UPDATE feature_flag SET valid_from = 'not a date', valid_until = NULL WHERE code = 'renewal_notice'");
        $reporter = new InMemoryFeatureFlagErrorReporter();
        $broken = new FeatureFlags($this->registry(), new PdoFeatureFlagStore($pdo), $reporter);
        $this->assertTrue($broken->evaluate(self::RENEWAL)->reason === FeatureFlagReason::ERROR, 'data ilegível = ERROR');
        $this->assertTrue($reporter->entries()[0]['error'] instanceof FeatureFlagStoreException);
    }

    /** BooleanStorage::Char é lido estritamente: outro texto é erro (desligada e reportada), nunca falso silencioso. */
    private function testPdoStrictCharBoolean(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE feature_flag (code VARCHAR(80) PRIMARY KEY, name VARCHAR(200), enabled CHAR(1))');
        $pdo->exec("INSERT INTO feature_flag (code, name, enabled) VALUES ('renewal_notice', 'R', 'y'), ('pending_notice', 'P', 'X')");
        $store = new PdoFeatureFlagStore($pdo, tables: ['subject' => null], columns: ['flag' => [
            'description' => null, 'category' => null, 'audience' => null, 'notes' => null, 'valid_from' => null, 'valid_until' => null,
            'enabled_at' => null, 'enabled_by' => null, 'disabled_at' => null, 'disabled_by' => null, 'version' => null,
        ]]);
        $this->assertThrows(FeatureFlagStoreException::class, fn() => $store->loadAll(), "'X' não é Y nem N");
        $reporter = new InMemoryFeatureFlagErrorReporter();
        $flags = new FeatureFlags($this->registry(), $store, $reporter);
        $this->assertFalse($flags->isEnabled(self::RENEWAL), 'uma linha ilegível derruba a carga: desligada (falha fechada)');
        $this->assertCount(1, $reporter->entries());

        $pdo->exec("UPDATE feature_flag SET enabled = 'N' WHERE code = 'pending_notice'");
        $this->assertTrue($store->find(self::RENEWAL)?->enabled === true, "'y' minúsculo é verdadeiro");
        $this->assertTrue($store->compareAndSetEnabled(self::RENEWAL, true, false, new DateTimeImmutable(), null), "CAS casa 'y' minúsculo");
        $this->assertEquals('N', $pdo->query("SELECT enabled FROM feature_flag WHERE code = 'renewal_notice'")->fetchColumn());
    }

    /**
     * Tabela de chaves com id gerado, booleano nativo/inteiro, exclusão lógica e carimbos locais, e uma
     * trilha de auditoria compartilhada com outras configurações (valores fixos de escopo, 'true'/'false'
     * em texto, id gerado pelo banco): o formato do script 41 da Virttus, em nomes neutros.
     */
    private function testPdoSharedAuditTable(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE aspec_ff_flags (
            id INTEGER PRIMARY KEY AUTOINCREMENT, code VARCHAR(80) NOT NULL UNIQUE, name VARCHAR(200) NOT NULL, description TEXT,
            category VARCHAR(40) NOT NULL DEFAULT \'feature\', enabled INTEGER NOT NULL DEFAULT 0, audience VARCHAR(255), notes TEXT,
            enabled_at VARCHAR(20), enabled_by INTEGER, disabled_at VARCHAR(20), disabled_by INTEGER,
            created_at VARCHAR(20), created_by INTEGER, updated_at VARCHAR(20), updated_by INTEGER, deleted_at VARCHAR(20), deleted_by INTEGER)');
        $pdo->exec('CREATE TABLE aspec_ff_audit (
            id INTEGER PRIMARY KEY AUTOINCREMENT, entity_type VARCHAR(30) NOT NULL, entity_key VARCHAR(120) NOT NULL, field VARCHAR(80) NOT NULL,
            old_value TEXT, new_value TEXT, reason TEXT NOT NULL, origin VARCHAR(20) NOT NULL, changed_by INTEGER, changed_at VARCHAR(20) NOT NULL)');
        $pdo->exec("INSERT INTO aspec_ff_flags (code, name, enabled, created_at) VALUES ('renewal_notice', 'Seeded renewal', 0, '2026-10-12 08:00:00'), ('pending_notice', 'Seeded pending', 0, '2026-10-12 08:00:00'), ('removed_flag', 'Gone', 1, '2026-10-12 08:00:00')");
        $pdo->exec("UPDATE aspec_ff_flags SET deleted_at = '2026-10-12 09:00:00' WHERE code = 'removed_flag'");
        $pdo->exec("INSERT INTO aspec_ff_audit (entity_type, entity_key, field, old_value, new_value, reason, origin, changed_at) VALUES ('setting', 'renewal_notice', 'value', 'a', 'b', 'Another trail shares this table', 'tela', '2026-10-12 09:30:00')");

        $store = new PdoFeatureFlagStore(
            $pdo,
            tables: ['flag' => 'aspec_ff_flags', 'change' => 'aspec_ff_audit', 'subject' => null],
            columns: [
                'flag' => [
                    'valid_from' => null, 'valid_until' => null, 'version' => null,
                    'created_at' => 'created_at', 'created_by' => 'created_by', 'updated_at' => 'updated_at', 'updated_by' => 'updated_by',
                    'deleted_at' => 'deleted_at',
                ],
                'change' => ['change_id' => 'id', 'flag_code' => 'entity_key', 'subject_key' => null, 'old_enabled' => 'old_value', 'new_enabled' => 'new_value'],
            ],
            changeScope: ['entity_type' => 'feature_flag', 'field' => 'enabled'],
            booleans: ['flag' => BooleanStorage::Integer, 'change' => ['true', 'false']],
            timestampFormat: 'Y-m-d H:i:s',
            timezone: 'America/Sao_Paulo',
            databaseGeneratedChangeId: true
        );
        $clock = new Module27Clock('2026-10-12 15:00:00'); // 12:00 em São Paulo
        $flags = new FeatureFlags($this->registry(), $store, clock: $clock->closure());

        $this->assertEquals([self::PENDING, self::RENEWAL], array_keys($store->loadAll()), 'só as não excluídas');
        $flags->toggle(self::RENEWAL, true, 'Pilot approved in the meeting', 7, 'tela');
        $row = $pdo->query("SELECT enabled, enabled_at, enabled_by, updated_at, updated_by FROM aspec_ff_flags WHERE code = 'renewal_notice'")->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals(1, (int) $row['enabled'], 'inteiro 1');
        $this->assertEquals('2026-10-12 12:00:00', $row['enabled_at'], 'carimbo local no formato da tabela');
        $this->assertEquals(7, (int) $row['enabled_by']);
        $this->assertEquals('2026-10-12 12:00:00', $row['updated_at'], 'updated_at carimbado quando mapeado');
        $audit = $pdo->query("SELECT entity_type, entity_key, field, old_value, new_value, reason, origin, changed_by, changed_at FROM aspec_ff_audit WHERE entity_type = 'feature_flag'")->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals(
            ['entity_type' => 'feature_flag', 'entity_key' => 'renewal_notice', 'field' => 'enabled', 'old_value' => 'false', 'new_value' => 'true', 'reason' => 'Pilot approved in the meeting', 'origin' => 'tela', 'changed_by' => 7, 'changed_at' => '2026-10-12 12:00:00'],
            array_replace($audit, ['changed_by' => (int) $audit['changed_by']]),
            'linha da trilha compartilhada'
        );
        $history = $flags->history();
        $this->assertCount(1, $history, 'a linha da outra trilha não aparece');
        $this->assertTrue($history[0]->changedAt == $clock->now, 'instante relido no fuso do store');

        // Nasce na primeira troca, com created_* carimbados
        $clock->advance();
        $pdo->exec("DELETE FROM aspec_ff_flags WHERE code = 'pending_notice'");
        $flags->toggle(self::PENDING, true, 'Pending notices go live', 8, 'tela');
        $created = $pdo->query("SELECT category, created_at, created_by, enabled FROM aspec_ff_flags WHERE code = 'pending_notice'")->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals('feature', $created['category'], 'categoria vazia na definição: o default da tabela');
        $this->assertEquals('2026-10-12 12:00:01', $created['created_at']);
        $this->assertEquals(8, (int) $created['created_by']);
        $this->assertEquals(null, $flags->history(self::PENDING)[0]->oldEnabled, 'não existia');

        // Mesma segundo: o id gerado pelo banco desempata
        $flags->toggle(self::PENDING, false, 'Same second, turned off', 8, 'tela');
        $this->assertEquals('Same second, turned off', $flags->history(self::PENDING, 1)[0]->reason, 'id DESC desempata');

        // Linha excluída logicamente: não é lida e não é trocada
        $this->assertThrows(FeatureFlagException::class, fn() => $flags->toggle('removed_flag', false, 'Trying to touch a deleted row'));
        $this->assertEquals(1, (int) $pdo->query("SELECT enabled FROM aspec_ff_flags WHERE code = 'removed_flag'")->fetchColumn(), 'excluída intocada');

        // Valor da trilha fora de 'true'/'false' é erro, nunca falso silencioso
        $pdo->exec("INSERT INTO aspec_ff_audit (entity_type, entity_key, field, old_value, new_value, reason, origin, changed_at) VALUES ('feature_flag', 'renewal_notice', 'enabled', NULL, 'maybe', 'Corrupted row for the test', 'script', '2026-10-13 00:00:00')");
        $this->assertThrows(FeatureFlagStoreException::class, fn() => $flags->history(self::RENEWAL));
    }

    /**
     * No PostgreSQL, um INSERT recusado dentro de uma transação não pode abortá-la (savepoint); nos
     * outros, a recusa do banco chega como FeatureFlagStoreException de integridade.
     */
    private function testPdoSavepointKeepsTransaction(PDO $pdo, string $family): void
    {
        $store = new PdoFeatureFlagStore($pdo);
        $failure = null;
        $pdo->beginTransaction();
        try {
            try {
                $store->addSubject('no_such_flag', '1'); // a FK recusa: a chave não tem linha
            } catch (FeatureFlagStoreException $e) {
                $failure = $e;
            }
            $this->assertTrue($failure !== null && $failure->isIntegrityViolation(), "{$family}: FK recusada como violação de integridade");
            $this->assertEquals([], $store->loadAll(), "{$family}: a transação continua utilizável depois da recusa");
        } finally {
            $pdo->rollBack();
        }
    }

    ///////////////////////////////////////////////////////////////////////////
    // RN-05: exemplo do README (proposta "19. Declared Feature Flags"), executado como escrito
    ///////////////////////////////////////////////////////////////////////////

    /** README 19 (2.1.0): os dois blocos PHP são iguais nos dois idiomas, fora os comentários. */
    private function testReadme19BlocksAreTheSameInBothLanguages(): void
    {
        $root = dirname(__DIR__, 2);
        $english = ReadmeBlocks::of($root . '/README.md', 19);
        $portuguese = ReadmeBlocks::of($root . '/README.pt-BR.md', 19);
        $this->assertCount(2, $english, 'dois blocos PHP no exemplo 19 (EN)');
        $this->assertCount(2, $portuguese, 'dois blocos PHP no exemplo 19 (pt-BR)');
        foreach ($english as $i => $block) {
            $this->assertEquals(ReadmeBlocks::codeTokens($block), ReadmeBlocks::codeTokens($portuguese[$i]), "README 19: bloco {$i} igual fora os comentários");
        }
        $this->assertTrue(str_contains($english[0], "addSubject('renewal_notice', 7"), 'README 19: o bloco 0 é o exemplo executado abaixo');
    }

    private function testReadmeExampleRunsAsWritten(): void
    {
        $pdo = $this->sqlite(); // the tables of resources/sql/feature_flags.ansi.sql

        // --- README ---
        $registry = new FeatureFlagRegistry([
            new FeatureFlagDefinition(
                'renewal_notice',
                'Renewal notice to agencies',
                impactOn: 'Agencies start receiving the renewal e-mail.',
                impactOff: 'Agencies stop receiving it.',
                origin: 'task 0033',
                subjectKey: 'unitId',
            ),
        ]);
        $flags = new FeatureFlags($registry, new PdoFeatureFlagStore($pdo));

        $seven = new Module27Agency(unitId: 7);
        $r1 = $flags->isEnabled('renewal_notice', $seven);                       // false: every flag is born off

        $flags->toggle('renewal_notice', true, 'Pilot approved by the board', actor: 42);
        $flags->addSubject('renewal_notice', 7, 'Pilot: agency 7 only', actor: 42);

        $r2 = $flags->isEnabled('renewal_notice', $seven);                       // true
        $r3 = $flags->evaluate('renewal_notice', new Module27Agency(unitId: 8))->reason; // FeatureFlagReason::NOT_IN_TARGET
        $r4 = $flags->isEnabled('not_declared');                                 // false (UNKNOWN_FLAG)

        $notify = $flags->spec('renewal_notice')->and(new PredicateSpecification(fn(Module27Agency $a): bool => $a->plan !== 'trial'));
        $r5 = $notify->isSatisfiedBy($seven);                                    // true

        try {
            $flags->toggle('renewal_notice', true, 'Second click on a stale screen');
            $r6 = 'toggled';
        } catch (StaleFeatureFlagException) {
            $r6 = 'stale';                                                       // 'stale': it is already on
        }
        $r7 = $flags->history('renewal_notice')[0]->reason;                     // 'Pilot: agency 7 only'
        // --- /README ---

        $this->assertFalse($r1, 'README: nasce desligada');
        $this->assertTrue($r2, 'README: ligada para a 7');
        $this->assertTrue($r3 === FeatureFlagReason::NOT_IN_TARGET, 'README: a 8 fora do alvo');
        $this->assertFalse($r4, 'README: não declarada');
        $this->assertTrue($r5, 'README: composta');
        $this->assertEquals('stale', $r6, 'README: troca obsoleta');
        $this->assertEquals('Pilot: agency 7 only', $r7, 'README: histórico');
    }
}
