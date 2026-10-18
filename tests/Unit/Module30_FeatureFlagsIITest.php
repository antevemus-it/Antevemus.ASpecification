<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\FeatureFlags\Exceptions\FeatureFlagStoreException;
use Antevemus\ASpecification\FeatureFlags\Exceptions\InvalidFeatureFlagReasonException;
use Antevemus\ASpecification\FeatureFlags\Exceptions\StaleFeatureFlagException;
use Antevemus\ASpecification\FeatureFlags\Exceptions\UndeclaredFeatureFlagException;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagChange;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagChangeKind;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagDefinition;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagEvaluation;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagReason;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagRegistry;
use Antevemus\ASpecification\FeatureFlags\FeatureFlags;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagState;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagView;
use Antevemus\ASpecification\FeatureFlags\IFeatureFlagStore;
use Antevemus\ASpecification\FeatureFlags\InMemoryFeatureFlagErrorReporter;
use Antevemus\ASpecification\FeatureFlags\InMemoryFeatureFlagStore;
use Antevemus\ASpecification\FeatureFlags\PdoFeatureFlagStore;
use Antevemus\ASpecification\Persistence\ITransactionManager;
use Antevemus\ASpecification\Specifications\PredicateSpecification;
use Antevemus\ASpecification\Tests\Support\ReadmeBlocks;
use Antevemus\ASpecification\Tests\TestCase;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Module30Unit - subject of the feature flag II tests (a real estate agency with its unit id and plan).
 *
 * @version 2.3.0
 */
final class Module30Unit
{
    public function __construct(
        public readonly int|string|null $unitId,
        public readonly string $plan = 'basic'
    ) {
    }
}

/**
 * Module30SpyStore - decorator that records every call and can run another writer once, right before
 * the version compare-and-set (the race between the read and the write of a change).
 *
 * @version 2.3.0
 */
final class Module30SpyStore implements IFeatureFlagStore
{
    /** @var list<string> */
    public array $calls = [];

    public ?Closure $beforeVersionWrite = null;

    public function __construct(private readonly IFeatureFlagStore $inner)
    {
    }

    public function loadAll(): array
    {
        $this->calls[] = 'loadAll';

        return $this->inner->loadAll();
    }

    public function find(string $code): ?FeatureFlagState
    {
        $this->calls[] = 'find';

        return $this->inner->find($code);
    }

    public function insertIfAbsent(FeatureFlagDefinition $definition, DateTimeImmutable $at, string|int|null $actor): bool
    {
        $this->calls[] = 'insertIfAbsent';

        return $this->inner->insertIfAbsent($definition, $at, $actor);
    }

    public function compareAndSetEnabled(string $code, bool $expected, bool $enabled, DateTimeImmutable $at, string|int|null $actor): bool
    {
        $this->calls[] = 'compareAndSetEnabled';

        return $this->inner->compareAndSetEnabled($code, $expected, $enabled, $at, $actor);
    }

    public function supportsValidity(): bool
    {
        $this->calls[] = 'supportsValidity';

        return $this->inner->supportsValidity();
    }

    public function compareAndSetValidity(string $code, int $expectedVersion, ?DateTimeImmutable $from, ?DateTimeImmutable $until, DateTimeImmutable $at, string|int|null $actor): bool
    {
        $this->calls[] = 'compareAndSetValidity';
        $this->interleave();

        return $this->inner->compareAndSetValidity($code, $expectedVersion, $from, $until, $at, $actor);
    }

    public function supportsRollout(): bool
    {
        $this->calls[] = 'supportsRollout';

        return $this->inner->supportsRollout();
    }

    public function compareAndSetRollout(string $code, int $expectedVersion, ?int $percent, DateTimeImmutable $at, string|int|null $actor): bool
    {
        $this->calls[] = 'compareAndSetRollout';
        $this->interleave();

        return $this->inner->compareAndSetRollout($code, $expectedVersion, $percent, $at, $actor);
    }

    public function supportsSubjects(): bool
    {
        $this->calls[] = 'supportsSubjects';

        return $this->inner->supportsSubjects();
    }

    public function addSubject(string $code, string $subjectKey): bool
    {
        $this->calls[] = 'addSubject';

        return $this->inner->addSubject($code, $subjectKey);
    }

    public function removeSubject(string $code, string $subjectKey): bool
    {
        $this->calls[] = 'removeSubject';

        return $this->inner->removeSubject($code, $subjectKey);
    }

    public function appendChange(FeatureFlagChange $change): void
    {
        $this->calls[] = 'appendChange';
        $this->inner->appendChange($change);
    }

    public function history(?string $code = null, int $limit = 50): array
    {
        $this->calls[] = 'history';

        return $this->inner->history($code, $limit);
    }

    public function getTransactionManager(): ITransactionManager
    {
        $this->calls[] = 'getTransactionManager';

        return $this->inner->getTransactionManager();
    }

    private function interleave(): void
    {
        $other = $this->beforeVersionWrite;
        $this->beforeVersionWrite = null;
        if ($other !== null) {
            $other($this->inner);
        }
    }
}

/**
 * Module 30 - Feature flags II: the validity window through the service (marco 24d) and the percentage
 * rollout (marco 24c), forward 040, 2.3.0.
 *
 * - D1/D2/D3: setValidity() with reason, history (kind Validity, detail), compare-and-set by version
 *   (expected version given or read in the transaction), `from > until` refused before the store is
 *   touched, undeclared flag refused, no-op refused, store without the window refused with a message
 *   that names the columns
 * - D4/D5/D6: setRollout(), the xxh3 bucket (golden values: a contract), the evaluation order (switch,
 *   window, list, code target, rollout), OUTSIDE_ROLLOUT, a null subject (or one without a key) out of a
 *   rollout, the bucket in the metadata
 * - D7/D8: all() with window and rollout; FeatureEnabledSpecification carries the new reason
 * - RN-01: without window and rollout the evaluation answers as before; a history row without kind
 *   reads as Toggle (Subject with a subject key); a 2.1.0 table keeps working untouched, and the
 *   additive migration of the reference DDL brings it to 2.3.0
 * - RN-02: two changes from the same version, the second is stale (window, rollout, window x toggle,
 *   window x rollout, a writer between the read and the write)
 * - RN-03: 10 000 synthetic subjects within +-1 % of 1, 10, 25, 50 and 90 %; the same buckets in two
 *   separate PHP processes; independent buckets for two flags
 * - RN-04: InMemoryFeatureFlagStore and PdoFeatureFlagStore (SQLite in memory; PostgreSQL 17, MySQL 8.4
 *   and MariaDB 11.4 when ASPEC_PDO_{PGSQL,MYSQL,MARIADB}_DSN are set, as run_portability.sh does)
 * - RN-05: the pilot (list) -> 30-day window -> rollout 25 % -> 100 %, with the history read in order,
 *   and testDocumentationExample(), the block proposed for the README
 *
 * @version 2.3.0
 */
class Module30_FeatureFlagsIITest extends TestCase
{
    /** Reference DDL shipped with the package (2.3.0). */
    private const DDL = __DIR__ . '/../../resources/sql/feature_flags.ansi.sql';

    /** The reference DDL as shipped in 2.1.0 (and 2.2.0), for the migration test. */
    private const DDL_2_1_0 = __DIR__ . '/../Fixtures/feature_flags/feature_flags_2_1_0.ansi.sql';

    private const RENEWAL = 'renewal_notice';
    private const PENDING = 'pending_notice';
    private const GOLD = 'gold_feature';

    private const REASON = 'Approved by the product board';

    public function run(): void
    {
        $this->testValueObjects();
        $this->testBucketContract();
        $this->testRolloutDistribution();
        $this->testBucketsAcrossProcesses();
        $this->testInMemoryWithoutWindowOrRollout();

        $this->runContract('InMemory', static fn(): IFeatureFlagStore => new InMemoryFeatureFlagStore());

        if (!extension_loaded('pdo_sqlite')) {
            fwrite(STDOUT, "    [AVISO] pdo_sqlite ausente; o Módulo 30 roda só a bateria em memória.\n");
            return;
        }

        $this->assertTrue(is_file(self::DDL), 'DDL de referência das feature flags deve existir');
        $this->assertTrue(is_file(self::DDL_2_1_0), 'DDL da 2.1.0 (fixture) deve existir');
        $this->runContract('PDO/sqlite', fn(): IFeatureFlagStore => new PdoFeatureFlagStore($this->sqlite(), columns: PdoFeatureFlagStore::COLUMNS_SINCE_2_3));
        $this->testPdoWithoutTheNewColumns();
        $this->testPdoStrictReads();
        $this->testPdoMigrationFrom210($this->sqliteEmpty(), 'sqlite');
        $this->testDocumentationExample();
        $this->testReadme19_1BlocksAreTheSameInBothLanguages();

        foreach (['pgsql' => 'PostgreSQL', 'mysql' => 'MySQL', 'mariadb' => 'MariaDB'] as $family => $label) {
            $pdo = $this->connectFromEnvironment($family);
            if ($pdo === null) {
                fwrite(STDOUT, "    [AVISO] ASPEC_PDO_" . strtoupper($family) . "_DSN não definido; RN-04 em {$label} pulado.\n");
                continue;
            }
            $this->runContract("PDO/{$family}", function () use ($pdo): IFeatureFlagStore {
                $this->resetSchema($pdo, self::DDL);

                return new PdoFeatureFlagStore($pdo, columns: PdoFeatureFlagStore::COLUMNS_SINCE_2_3);
            });
            $this->dropSchema($pdo);
            $this->testPdoMigrationFrom210($pdo, $family);
            $this->dropSchema($pdo);
            fwrite(STDOUT, "    [RN-04] {$label} " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . ": janela, rollout e migração aditiva OK\n");
        }
    }

    ///////////////////////////////////////////////////////////////////////////
    // Objetos de valor
    ///////////////////////////////////////////////////////////////////////////

    private function testValueObjects(): void
    {
        $at = new DateTimeImmutable('2026-10-18 10:00:00', new DateTimeZone('UTC'));

        // Tipo da mudança: sem tipo, a chave de sujeito decide (linhas da 2.1.0)
        $this->assertTrue((new FeatureFlagChange('a_flag', false, true, 'r', 'ui', $at))->kind === FeatureFlagChangeKind::Toggle, 'sem tipo nem sujeito = Toggle');
        $subject = new FeatureFlagChange('a_flag', false, true, 'r', 'ui', $at, null, '7');
        $this->assertTrue($subject->kind === FeatureFlagChangeKind::Subject && $subject->isSubjectChange(), 'sem tipo com sujeito = Subject');
        $validity = new FeatureFlagChange('a_flag', true, true, 'r', 'ui', $at, null, null, FeatureFlagChangeKind::Validity, 'cleared');
        $this->assertFalse($validity->isSubjectChange(), 'Validity não é troca de sujeito');
        $this->assertEquals('cleared', $validity->detail);
        $this->assertEquals(null, (new FeatureFlagChange('a_flag', false, true, 'r', 'ui', $at))->detail, 'detalhe nulo por padrão');
        $needs = $this->assertThrows(InvalidArgumentException::class, fn() => new FeatureFlagChange('a_flag', false, true, 'r', 'ui', $at, null, null, FeatureFlagChangeKind::Subject), 'Subject sem chave recusado');
        $this->assertEquals('A subject change of feature flag "a_flag" needs a subject key.', $needs->getMessage());
        $carry = $this->assertThrows(InvalidArgumentException::class, fn() => new FeatureFlagChange('a_flag', false, true, 'r', 'ui', $at, null, '7', FeatureFlagChangeKind::Rollout), 'Rollout com chave recusado');
        $this->assertEquals('A rollout change of feature flag "a_flag" cannot carry a subject key.', $carry->getMessage());
        $this->assertEquals(['toggle', 'subject', 'validity', 'rollout'], array_map(static fn(FeatureFlagChangeKind $k): string => $k->value, FeatureFlagChangeKind::cases()), 'valores gravados do tipo');

        // Estado: percentual de 0 a 100
        $this->assertEquals(null, (new FeatureFlagState('a_flag'))->rolloutPercent, 'sem rollout por padrão');
        $this->assertEquals(0, (new FeatureFlagState('a_flag', rolloutPercent: 0))->rolloutPercent);
        $this->assertEquals(100, (new FeatureFlagState('a_flag', rolloutPercent: 100))->rolloutPercent);
        $this->assertThrows(InvalidArgumentException::class, fn() => new FeatureFlagState('a_flag', rolloutPercent: 101), 'percentual acima de 100');
        $this->assertThrows(InvalidArgumentException::class, fn() => new FeatureFlagState('a_flag', rolloutPercent: -1), 'percentual negativo');

        // Motivo novo e metadados
        $this->assertFalse(FeatureFlagReason::OUTSIDE_ROLLOUT->isOn(), 'OUTSIDE_ROLLOUT desliga');
        $this->assertEquals('DEFAULT', FeatureFlagReason::OUTSIDE_ROLLOUT->toOpenFeatureReason(), 'OUTSIDE_ROLLOUT → DEFAULT');
        $this->assertEquals(null, FeatureFlagReason::OUTSIDE_ROLLOUT->toOpenFeatureErrorCode());
        $this->assertEquals('outside_rollout', FeatureFlagReason::OUTSIDE_ROLLOUT->value);
        $metadata = (new FeatureFlagEvaluation('a_flag', FeatureFlagReason::OUTSIDE_ROLLOUT, '7', null, 7342, 25))->toMetadata();
        $this->assertEquals(7342, $metadata['bucket'], 'bucket nos metadados');
        $this->assertEquals(25, $metadata['rollout_percent'], 'percentual nos metadados');
        $plain = (new FeatureFlagEvaluation('a_flag', FeatureFlagReason::ENABLED))->toMetadata();
        $this->assertFalse(array_key_exists('bucket', $plain) || array_key_exists('rollout_percent', $plain), 'sem rollout, sem bucket nos metadados');
        $zero = (new FeatureFlagEvaluation('a_flag', FeatureFlagReason::OUTSIDE_ROLLOUT, '7', null, 0, 0))->toMetadata();
        $this->assertTrue($zero['bucket'] === 0 && $zero['rollout_percent'] === 0, 'bucket 0 e percentual 0 aparecem');

        // Listagem: janela e percentual, nulos sem linha
        $from = new DateTimeImmutable('2026-11-01', new DateTimeZone('UTC'));
        $view = new FeatureFlagView('a_flag', null, new FeatureFlagState('a_flag', true, validFrom: $from, rolloutPercent: 30));
        $this->assertTrue($view->validFrom == $from && $view->validUntil === null && $view->rolloutPercent === 30, 'a linha da listagem mostra janela e percentual');
        $empty = new FeatureFlagView('a_flag', null, null);
        $this->assertTrue($empty->validFrom === null && $empty->validUntil === null && $empty->rolloutPercent === null, 'sem linha, nulos');
    }

    ///////////////////////////////////////////////////////////////////////////
    // D5: o bucket é contrato
    ///////////////////////////////////////////////////////////////////////////

    private function testBucketContract(): void
    {
        // Valores dourados: mudar o hash muda estes números, e isso é MAJOR (D5)
        $golden = [
            ['renewal_notice', '7'],
            ['renewal_notice', '8'],
            ['renewal_notice', 'agency-42'],
            ['pending_notice', '7'],
            ['new_checkout', 'customer:1001'],
            ['new_checkout', ''],
        ];
        foreach ($golden as [$code, $key]) {
            $expected = (int) (hexdec(substr(hash('xxh3', $code . "\0" . $key), 0, 8)) % 10000);
            $this->assertEquals($expected, FeatureFlags::bucket($code, $key), "bucket({$code}, {$key}) = xxh3 → 8 hex → mod 10000");
        }
        $this->assertEquals(self::GOLDEN_BUCKETS, array_map(static fn(array $pair): int => FeatureFlags::bucket($pair[0], $pair[1]), $golden), 'valores dourados do bucket (contrato da 2.x)');
        $this->assertEquals(10000, FeatureFlags::ROLLOUT_BUCKETS);
        $this->assertFalse(FeatureFlags::bucket('renewal_notice', '7') === FeatureFlags::bucket('renewal_notice7', ''), 'o separador \0 distingue código e chave');

        $min = PHP_INT_MAX;
        $max = PHP_INT_MIN;
        for ($i = 0; $i < 20000; $i++) {
            $bucket = FeatureFlags::bucket('range_probe', (string) $i);
            $min = min($min, $bucket);
            $max = max($max, $bucket);
        }
        $this->assertTrue($min >= 0 && $max <= 9999, 'bucket entre 0 e 9999');
        $this->assertTrue($min < 10 && $max > 9989, 'os extremos são alcançados');
    }

    /** Golden values of testBucketContract(), measured on 2026-10-18 (PHP 8.4, xxh3). */
    private const GOLDEN_BUCKETS = [8912, 7352, 1968, 847, 8780, 4614];

    ///////////////////////////////////////////////////////////////////////////
    // RN-03: distribuição, monotonicidade e independência
    ///////////////////////////////////////////////////////////////////////////

    private function testRolloutDistribution(): void
    {
        $subjects = 10000;
        $buckets = [];
        $other = [];
        for ($i = 0; $i < $subjects; $i++) {
            $buckets[$i] = FeatureFlags::bucket(self::RENEWAL, 'agency-' . $i);
            $other[$i] = FeatureFlags::bucket('another_flag', 'agency-' . $i);
        }

        $measured = [];
        foreach ([1, 10, 25, 50, 90] as $percent) {
            $in = count(array_filter($buckets, static fn(int $b): bool => $b < $percent * 100));
            $fraction = $in / $subjects;
            $measured[$percent] = $fraction;
            $this->assertTrue(abs($fraction - $percent / 100) <= 0.01, sprintf('RN-03: %d%% → %.4f dentro de ±1%%', $percent, $fraction));
        }
        fwrite(STDOUT, sprintf(
            "    [RN-03] 10 000 sujeitos: %s\n",
            implode(', ', array_map(static fn(int $p, float $f): string => sprintf('%d%% → %.2f%%', $p, $f * 100), array_keys($measured), $measured))
        ));

        // Pelo serviço: a avaliação concorda com o bucket, sujeito a sujeito
        $store = (new InMemoryFeatureFlagStore())->put(new FeatureFlagState(self::RENEWAL, true, rolloutPercent: 25));
        $flags = new FeatureFlags($this->registry(), $store);
        $agree = 0;
        $on = 0;
        for ($i = 0; $i < $subjects; $i++) {
            $evaluation = $flags->evaluate(self::RENEWAL, 'agency-' . $i);
            $on += $evaluation->value ? 1 : 0;
            $agree += ($evaluation->value === ($buckets[$i] < 2500) && $evaluation->bucket === $buckets[$i]) ? 1 : 0;
        }
        $this->assertEquals($subjects, $agree, 'RN-03: a avaliação segue o bucket em todos os sujeitos');
        $this->assertTrue(abs($on / $subjects - 0.25) <= 0.01, 'RN-03: 25% pelo serviço');

        // Fronteira: o bucket igual a p * 100 já está fora de p% e dentro de p + 1 %
        $edge = null;
        for ($i = 0; $edge === null; $i++) {
            $bucket = FeatureFlags::bucket(self::RENEWAL, 'edge-' . $i);
            if ($bucket % 100 === 0 && $bucket > 0) {
                $edge = ['edge-' . $i, intdiv($bucket, 100)];
            }
        }
        [$edgeKey, $edgePercent] = $edge;
        $edgeStore = (new InMemoryFeatureFlagStore())->put(new FeatureFlagState(self::RENEWAL, true, rolloutPercent: $edgePercent));
        $this->assertTrue((new FeatureFlags($this->registry(), $edgeStore))->evaluate(self::RENEWAL, $edgeKey)->reason === FeatureFlagReason::OUTSIDE_ROLLOUT, "bucket {$edgePercent}00 fora de {$edgePercent}%");
        $edgeStore->put(new FeatureFlagState(self::RENEWAL, true, rolloutPercent: $edgePercent + 1));
        $this->assertTrue((new FeatureFlags($this->registry(), $edgeStore))->evaluate(self::RENEWAL, $edgeKey)->reason === FeatureFlagReason::TARGETING_MATCH, "bucket {$edgePercent}00 dentro de " . ($edgePercent + 1) . '%');

        // Subir o percentual só acrescenta sujeitos
        $lost = 0;
        foreach ($buckets as $bucket) {
            foreach ([[1, 10], [10, 25], [25, 50], [50, 90], [90, 100]] as [$low, $high]) {
                $lost += ($bucket < $low * 100 && !($bucket < $high * 100)) ? 1 : 0;
            }
        }
        $this->assertEquals(0, $lost, 'monotônico: quem entra em p% fica em q% > p%');

        // Flags diferentes: buckets independentes (correlação baixa; 50% × 50% ≈ 25% nos dois)
        $correlation = self::pearson($buckets, $other);
        $both = 0;
        for ($i = 0; $i < $subjects; $i++) {
            $both += ($buckets[$i] < 5000 && $other[$i] < 5000) ? 1 : 0;
        }
        $this->assertTrue(abs($correlation) < 0.03, sprintf('RN-03: correlação entre flags %.4f < 0,03', $correlation));
        $this->assertTrue(abs($both / $subjects - 0.25) <= 0.02, sprintf('RN-03: 50%% × 50%% = %.4f ≈ 25%%', $both / $subjects));
        fwrite(STDOUT, sprintf("    [RN-03] duas flags: correlação %.4f; nos dois rollouts de 50%%: %.2f%%\n", $correlation, $both / $subjects * 100));
    }

    /**
     * @param array<int, int> $x
     * @param array<int, int> $y
     */
    private static function pearson(array $x, array $y): float
    {
        $n = count($x);
        $mx = array_sum($x) / $n;
        $my = array_sum($y) / $n;
        $sxy = 0.0;
        $sxx = 0.0;
        $syy = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dx = $x[$i] - $mx;
            $dy = $y[$i] - $my;
            $sxy += $dx * $dy;
            $sxx += $dx * $dx;
            $syy += $dy * $dy;
        }

        return $sxy / sqrt($sxx * $syy);
    }

    /** RN-03: o mesmo sujeito dá o mesmo bucket em dois processos PHP separados. */
    private function testBucketsAcrossProcesses(): void
    {
        if (!function_exists('proc_open')) {
            fwrite(STDOUT, "    [AVISO] proc_open indisponível; RN-03 entre processos pulado.\n");
            return;
        }
        $keys = [];
        for ($i = 0; $i < 200; $i++) {
            $keys[] = 'agency-' . ($i * 37);
        }
        $local = array_map(static fn(string $key): int => FeatureFlags::bucket(self::RENEWAL, $key), $keys);

        $script = sprintf(
            'require %s; $out = []; foreach (json_decode($argv[1], true) as $k) { $out[] = %s::bucket(%s, $k); } echo json_encode([getmypid(), $out]);',
            var_export(dirname(__DIR__) . '/bootstrap.php', true),
            '\\' . FeatureFlags::class,
            var_export(self::RENEWAL, true)
        );
        $pids = [];
        foreach ([1, 2] as $run) {
            [$pid, $remote] = $this->runPhp($script, json_encode($keys, JSON_THROW_ON_ERROR));
            $pids[] = $pid;
            $this->assertEquals($local, $remote, "RN-03: processo {$run} dá os mesmos buckets");
        }
        $this->assertTrue($pids[0] !== $pids[1] && $pids[0] !== getmypid(), 'RN-03: dois processos distintos deste');
    }

    /**
     * Runs a PHP script in a separate process and decodes its JSON output.
     *
     * @return array{0: int, 1: list<int>}
     */
    private function runPhp(string $script, string $argument): array
    {
        $process = proc_open(
            [PHP_BINARY, '-d', 'xdebug.mode=off', '-r', $script, $argument],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['XDEBUG_MODE' => 'off'] + getenv()
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start a PHP process.');
        }
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        $decoded = json_decode($output, true);
        if ($status !== 0 || !is_array($decoded)) {
            throw new RuntimeException(sprintf('PHP process failed (%d): %s %s', $status, $output, $errors));
        }

        return [(int) $decoded[0], array_map('intval', $decoded[1])];
    }

    ///////////////////////////////////////////////////////////////////////////
    // Bateria de contrato (RN-04): memória e PDO, os mesmos resultados
    ///////////////////////////////////////////////////////////////////////////

    /**
     * @param Closure(): IFeatureFlagStore $store A fresh store for each battery
     */
    private function runContract(string $label, Closure $store): void
    {
        $this->contractValidity($label, $store());
        $this->contractValidityRefusals($label, $store());
        $this->contractVersionConcurrency($label, $store());
        $this->contractRollout($label, $store());
        $this->contractEvaluationOrder($label, $store());
        $this->contractListing($label, $store());
        $this->contractPilotToRollout($label, $store());
    }

    private function registry(): FeatureFlagRegistry
    {
        return new FeatureFlagRegistry([
            new FeatureFlagDefinition(self::RENEWAL, 'Renewal notice to agencies', origin: 'task 0033', subjectKey: 'unitId'),
            new FeatureFlagDefinition(self::PENDING, 'Pending items notice', origin: 'task 0031'),
            new FeatureFlagDefinition(
                self::GOLD,
                'Gold plan feature',
                target: new PredicateSpecification(static fn(Module30Unit $unit): bool => $unit->plan === 'gold'),
                subjectKey: 'unitId'
            ),
        ]);
    }

    private static function utc(string $instant): DateTimeImmutable
    {
        return new DateTimeImmutable($instant, new DateTimeZone('UTC'));
    }

    /**
     * @return array{0: FeatureFlags, 1: object}
     */
    private function service(IFeatureFlagStore $store, string $now = '2026-10-18 10:00:00'): array
    {
        $clock = new class (self::utc($now)) {
            public function __construct(public DateTimeImmutable $now)
            {
            }
        };

        return [new FeatureFlags($this->registry(), $store, clock: static fn(): DateTimeImmutable => $clock->now), $clock];
    }

    private function contractValidity(string $label, IFeatureFlagStore $store): void
    {
        [$flags, $clock] = $this->service($store);
        $this->assertTrue($store->supportsValidity(), "{$label}: guarda a janela");
        $on = $flags->toggle(self::RENEWAL, true, 'Campaign approved by marketing', 42);
        $this->assertEquals(2, $on->version, "{$label}: a linha nasce na versão 1 e ligar incrementa");

        $from = self::utc('2026-11-01 00:00:00');
        $until = self::utc('2026-12-31 23:59:59');
        $clock->now = $clock->now->modify('+1 minute');
        $state = $flags->setValidity(self::RENEWAL, $from, $until, "  Campaign   window\n of the year end ", 42);
        $this->assertTrue($state->validFrom == $from && $state->validUntil == $until, "{$label}: janela gravada e relida");
        $this->assertEquals(3, $state->version, "{$label}: a janela incrementa a versão");
        $this->assertTrue($state->enabled, "{$label}: a janela não mexe no interruptor");
        $this->assertTrue($flags->evaluate(self::RENEWAL)->reason === FeatureFlagReason::OUTSIDE_VALIDITY, "{$label}: a mesma instância vê a janela (antes do início)");
        $this->assertTrue($flags->withAsOf($from)->isEnabled(self::RENEWAL), "{$label}: início inclusivo");
        $this->assertTrue($flags->withAsOf($until)->isEnabled(self::RENEWAL), "{$label}: fim inclusivo");
        $this->assertFalse($flags->withAsOf($until->modify('+1 second'))->isEnabled(self::RENEWAL), "{$label}: depois do fim");

        $history = $flags->history(self::RENEWAL);
        $this->assertCount(2, $history, "{$label}: ligar + janela");
        $row = $history[0];
        $this->assertTrue($row->kind === FeatureFlagChangeKind::Validity, "{$label}: tipo Validity");
        $this->assertEquals('2026-11-01T00:00:00+00:00 → 2026-12-31T23:59:59+00:00', $row->detail, "{$label}: detalhe da janela em UTC");
        $this->assertEquals('Campaign window of the year end', $row->reason, "{$label}: motivo normalizado");
        $this->assertTrue($row->oldEnabled === true && $row->newEnabled === true, "{$label}: o interruptor fica como estava");
        $this->assertEquals('42', $row->changedBy);
        $this->assertEquals(FeatureFlags::ORIGIN_UI, $row->origin);
        $this->assertTrue($row->changedAt == $clock->now, "{$label}: instante da troca");
        $this->assertFalse($row->isSubjectChange());
        $this->assertTrue($history[1]->kind === FeatureFlagChangeKind::Toggle && $history[1]->detail === null, "{$label}: ligar é Toggle sem detalhe");

        // Mesma janela de novo: nada a fazer, recusado como obsoleto, sem histórico
        $same = $this->assertThrows(StaleFeatureFlagException::class, fn() => $flags->setValidity(self::RENEWAL, $from, $until, 'Same window clicked twice'), "{$label}: mesma janela = obsoleto");
        $this->assertTrue(str_contains($same->getMessage(), 'already has this validity window'), "{$label}: mensagem da janela repetida");
        $this->assertCount(2, $flags->history(self::RENEWAL), "{$label}: sem linha nova");

        // Fuso do chamador: o detalhe sai em UTC; limite aberto
        $saoPaulo = new DateTimeImmutable('2026-11-10 21:00:00', new DateTimeZone('America/Sao_Paulo'));
        $state = $flags->setValidity(self::RENEWAL, $saoPaulo, null, 'Open ended from mid November', 'ops', FeatureFlags::ORIGIN_SCRIPT);
        $this->assertTrue($state->validFrom == $saoPaulo && $state->validUntil === null, "{$label}: só início");
        $this->assertEquals('2026-11-11T00:00:00+00:00 → open', $flags->history(self::RENEWAL, 1)[0]->detail, "{$label}: fuso convertido e fim aberto");
        $this->assertEquals(FeatureFlags::ORIGIN_SCRIPT, $flags->history(self::RENEWAL, 1)[0]->origin);
        $flags->setValidity(self::RENEWAL, null, $until, 'Only an end for the campaign');
        $this->assertEquals('open → 2026-12-31T23:59:59+00:00', $flags->history(self::RENEWAL, 1)[0]->detail, "{$label}: início aberto");

        // Um instante só (início = fim) é aceito
        $flags->setValidity(self::RENEWAL, $from, $from, 'A single instant window');
        $this->assertTrue($flags->withAsOf($from)->isEnabled(self::RENEWAL) && !$flags->withAsOf($from->modify('+1 second'))->isEnabled(self::RENEWAL), "{$label}: início = fim");

        // Limpar
        $state = $flags->setValidity(self::RENEWAL, null, null, 'Campaign is permanent now');
        $this->assertTrue($state->validFrom === null && $state->validUntil === null, "{$label}: janela limpa");
        $this->assertEquals('cleared', $flags->history(self::RENEWAL, 1)[0]->detail, "{$label}: detalhe 'cleared'");
        $this->assertTrue($flags->evaluate(self::RENEWAL)->reason === FeatureFlagReason::ENABLED, "{$label}: sem janela = como antes (RN-01)");
        $same = $this->assertThrows(StaleFeatureFlagException::class, fn() => $flags->setValidity(self::RENEWAL, null, null, 'Clearing twice by mistake'), "{$label}: limpar sem janela = obsoleto");
        $this->assertTrue(str_contains($same->getMessage(), 'has no validity window'), "{$label}: mensagem de limpar sem janela");

        // Declarada sem linha: nasce desligada com a janela; histórico old = null
        $state = $flags->setValidity(self::PENDING, $from, $until, 'Schedule before the launch', 7);
        $this->assertFalse($state->enabled, "{$label}: nasce desligada");
        $this->assertEquals(2, $state->version, "{$label}: criada (1) e janela (2)");
        $pending = $flags->history(self::PENDING);
        $this->assertCount(1, $pending);
        $this->assertTrue($pending[0]->oldEnabled === null && $pending[0]->newEnabled === false && $pending[0]->kind === FeatureFlagChangeKind::Validity, "{$label}: old nulo = não existia");
        $this->assertTrue($flags->withAsOf($from)->evaluate(self::PENDING)->reason === FeatureFlagReason::DISABLED, "{$label}: desligada vence a janela");
        $this->assertThrows(StaleFeatureFlagException::class, fn() => $flags->setValidity(self::GOLD, null, null, 'Nothing to clear here'), "{$label}: limpar a de quem não tem linha = obsoleto");
        $this->assertEquals(null, $store->find(self::GOLD), "{$label}: a linha criada na tentativa foi desfeita");
    }

    private function contractValidityRefusals(string $label, IFeatureFlagStore $inner): void
    {
        $store = new Module30SpyStore($inner);
        [$flags] = $this->service($store);
        $from = self::utc('2026-12-01 00:00:00');
        $until = self::utc('2026-11-01 00:00:00');

        $refusals = [
            'início depois do fim' => [InvalidArgumentException::class, fn() => $flags->setValidity(self::RENEWAL, $from, $until, self::REASON)],
            'um microssegundo depois' => [InvalidArgumentException::class, fn() => $flags->setValidity(self::RENEWAL, $until->modify('+1 microsecond'), $until, self::REASON)],
            'não declarada' => [UndeclaredFeatureFlagException::class, fn() => $flags->setValidity('never_declared', null, null, self::REASON)],
            'código inválido' => [InvalidArgumentException::class, fn() => $flags->setValidity('Bad Code', null, $until, self::REASON)],
            'motivo curto' => [InvalidFeatureFlagReasonException::class, fn() => $flags->setValidity(self::RENEWAL, $until, $from, 'short')],
            'origem vazia' => [InvalidArgumentException::class, fn() => $flags->setValidity(self::RENEWAL, $until, $from, self::REASON, origin: ' ')],
            'rollout acima de 100' => [InvalidArgumentException::class, fn() => $flags->setRollout(self::RENEWAL, 101, self::REASON)],
            'rollout negativo' => [InvalidArgumentException::class, fn() => $flags->setRollout(self::RENEWAL, -1, self::REASON)],
            'rollout não declarada' => [UndeclaredFeatureFlagException::class, fn() => $flags->setRollout('never_declared', 10, self::REASON)],
            'rollout motivo curto' => [InvalidFeatureFlagReasonException::class, fn() => $flags->setRollout(self::RENEWAL, 10, 'tiny')],
            'rollout origem vazia' => [InvalidArgumentException::class, fn() => $flags->setRollout(self::RENEWAL, 10, self::REASON, origin: '')],
        ];
        foreach ($refusals as $what => [$class, $call]) {
            $store->calls = [];
            $error = $this->assertThrows($class, $call, "{$label}: recusado: {$what}");
            $this->assertEquals([], $store->calls, "{$label}: {$what}: recusado antes de tocar o store");
            if ($what === 'início depois do fim') {
                $this->assertTrue(str_contains($error->getMessage(), '2026-12-01T00:00:00+00:00 > 2026-11-01T00:00:00+00:00'), "{$label}: a mensagem mostra os dois limites");
            }
            if ($what === 'não declarada') {
                $this->assertTrue($error instanceof UndeclaredFeatureFlagException && $error->getFlagCode() === 'never_declared');
            }
        }
        $this->assertEquals([], $inner->history(), "{$label}: nada gravado pelas recusas");
        $this->assertEquals(null, $inner->find(self::RENEWAL), "{$label}: nenhuma linha criada pelas recusas");

        // Os limites aceitos: 0 e 100
        $flags->setRollout(self::RENEWAL, 0, 'Prepared, nobody yet');
        $this->assertEquals(0, $inner->find(self::RENEWAL)?->rolloutPercent, "{$label}: 0% aceito");
        $flags->setRollout(self::RENEWAL, 100, 'Everyone with a key now');
        $this->assertEquals(100, $inner->find(self::RENEWAL)?->rolloutPercent, "{$label}: 100% aceito");
    }

    private function contractVersionConcurrency(string $label, IFeatureFlagStore $inner): void
    {
        $store = new Module30SpyStore($inner);
        [$flags] = $this->service($store);
        $november = [self::utc('2026-11-01 00:00:00'), self::utc('2026-11-30 23:59:59')];
        $december = [self::utc('2026-12-01 00:00:00'), self::utc('2026-12-31 23:59:59')];

        // Store: CAS pela versão, uma linha ou nada
        $flags->toggle(self::RENEWAL, true, 'On before the concurrency checks');
        $version = (int) $inner->find(self::RENEWAL)?->version;
        $at = self::utc('2026-10-18 12:00:00');
        $this->assertTrue($inner->compareAndSetValidity(self::RENEWAL, $version, $november[0], $november[1], $at, 1), "{$label}: store: versão certa grava");
        $this->assertFalse($inner->compareAndSetValidity(self::RENEWAL, $version, $december[0], $december[1], $at, 2), "{$label}: store: mesma versão de novo = nada");
        $this->assertTrue($inner->find(self::RENEWAL)?->validFrom == $november[0], "{$label}: store: a primeira janela fica");
        $this->assertFalse($inner->compareAndSetRollout(self::RENEWAL, $version, 10, $at, 2), "{$label}: store: rollout com versão velha = nada");
        $this->assertTrue($inner->compareAndSetRollout(self::RENEWAL, $version + 1, 10, $at, 2), "{$label}: store: rollout com a versão nova grava");
        $this->assertEquals($version + 2, $inner->find(self::RENEWAL)?->version, "{$label}: store: cada escrita incrementa");
        $this->assertFalse($inner->compareAndSetValidity('no_such_flag', 1, null, null, $at, null), "{$label}: store: sem linha = nada");
        $this->assertFalse($inner->compareAndSetRollout('no_such_flag', 1, 5, $at, null), "{$label}: store: sem linha = nada (rollout)");

        // Serviço: duas trocas de janela a partir da mesma versão (a tela de dois operadores)
        $read = (int) $flags->all()[0]->state?->version;
        $flags->setValidity(self::RENEWAL, $december[0], $december[1], 'Operator A moves it to December', 'a', expectedVersion: $read);
        $stale = $this->assertThrows(StaleFeatureFlagException::class, fn() => $flags->setValidity(self::RENEWAL, $november[0], $november[1], 'Operator B keeps November', 'b', expectedVersion: $read), "{$label}: RN-02: segunda janela da mesma versão = obsoleta");
        $this->assertTrue($stale instanceof StaleFeatureFlagException && $stale->getFlagCode() === self::RENEWAL);
        $this->assertTrue($inner->find(self::RENEWAL)?->validFrom == $december[0], "{$label}: RN-02: a janela de A fica");
        $this->assertEquals('a', $flags->history(self::RENEWAL, 1)[0]->changedBy, "{$label}: RN-02: só A no histórico");

        // Rollout: idem
        $read = (int) $inner->find(self::RENEWAL)?->version;
        $flags->setRollout(self::RENEWAL, 30, 'Operator A widens to 30', 'a', expectedVersion: $read);
        $this->assertThrows(StaleFeatureFlagException::class, fn() => $flags->setRollout(self::RENEWAL, 20, 'Operator B narrows to 20', 'b', expectedVersion: $read), "{$label}: RN-02: segundo rollout da mesma versão = obsoleto");
        $this->assertEquals(30, $inner->find(self::RENEWAL)?->rolloutPercent);

        // Cruzados: o interruptor (CAS pelo estado) também incrementa a versão
        $read = (int) $inner->find(self::RENEWAL)?->version;
        $off = $flags->toggle(self::RENEWAL, false, 'Operator A turns it off');
        $this->assertEquals($read + 1, $off->version, "{$label}: RN-02: desligar incrementa a versão");
        $this->assertThrows(StaleFeatureFlagException::class, fn() => $flags->setValidity(self::RENEWAL, $november[0], $november[1], 'Operator B edits the window', expectedVersion: $read), "{$label}: RN-02: janela × interruptor");
        $this->assertThrows(StaleFeatureFlagException::class, fn() => $flags->setRollout(self::RENEWAL, 40, 'Operator B edits the rollout', expectedVersion: $read), "{$label}: RN-02: rollout × interruptor");
        $read = (int) $inner->find(self::RENEWAL)?->version;
        $flags->setRollout(self::RENEWAL, 45, 'Operator A edits the rollout', expectedVersion: $read);
        $this->assertThrows(StaleFeatureFlagException::class, fn() => $flags->setValidity(self::RENEWAL, $november[0], $november[1], 'Operator B edits the window', expectedVersion: $read), "{$label}: RN-02: janela × rollout");
        $this->assertTrue($flags->toggle(self::RENEWAL, true, 'Toggle compares the switch, not the version')->enabled, "{$label}: o interruptor continua pelo estado");

        // Outro escritor entre a leitura e a escrita da própria troca (sem versão esperada)
        $store->beforeVersionWrite = static function (IFeatureFlagStore $other): void {
            $other->compareAndSetEnabled(self::RENEWAL, true, false, self::utc('2026-10-18 13:00:00'), 'intruder');
        };
        $before = count($inner->history(self::RENEWAL, 100));
        $this->assertThrows(StaleFeatureFlagException::class, fn() => $flags->setValidity(self::RENEWAL, $november[0], $november[1], 'Raced by another writer'), "{$label}: RN-02: escritor no meio = obsoleto (CAS da versão)");
        $this->assertTrue($inner->find(self::RENEWAL)?->validFrom == $december[0], "{$label}: a janela não mudou");
        $this->assertCount($before, $inner->history(self::RENEWAL, 100), "{$label}: nenhuma linha de histórico");
        $store->beforeVersionWrite = static function (IFeatureFlagStore $other): void {
            $other->compareAndSetEnabled(self::RENEWAL, true, false, self::utc('2026-10-18 13:00:00'), 'intruder');
        };
        $this->assertThrows(StaleFeatureFlagException::class, fn() => $flags->setRollout(self::RENEWAL, 60, 'Raced by another writer'), "{$label}: RN-02: idem no rollout");
        $this->assertEquals(45, $inner->find(self::RENEWAL)?->rolloutPercent, "{$label}: o rollout não mudou");
        $this->assertTrue($flags->isEnabled(self::RENEWAL, 'agency-0') === (FeatureFlags::bucket(self::RENEWAL, 'agency-0') < 4500), "{$label}: a instância relê depois da recusa");
    }

    private function contractRollout(string $label, IFeatureFlagStore $store): void
    {
        [$flags] = $this->service($store);
        $this->assertTrue($store->supportsRollout(), "{$label}: guarda o rollout");
        $flags->toggle(self::RENEWAL, true, 'On for the rollout checks');
        [$inKey, $outKey] = self::keysAround(self::RENEWAL, 25);

        $state = $flags->setRollout(self::RENEWAL, 25, 'Start the rollout at a quarter', 9);
        $this->assertEquals(25, $state->rolloutPercent, "{$label}: 25% gravado e relido");
        $this->assertTrue($state->enabled, "{$label}: o rollout não mexe no interruptor");
        $row = $flags->history(self::RENEWAL, 1)[0];
        $this->assertTrue($row->kind === FeatureFlagChangeKind::Rollout && $row->detail === 'none → 25%', "{$label}: histórico Rollout 'none → 25%'");
        $this->assertTrue($row->oldEnabled === true && $row->newEnabled === true && $row->changedBy === '9');

        $in = $flags->evaluate(self::RENEWAL, $inKey);
        $this->assertTrue($in->value && $in->reason === FeatureFlagReason::TARGETING_MATCH, "{$label}: bucket abaixo de 2500 = dentro");
        $this->assertEquals(FeatureFlags::bucket(self::RENEWAL, $inKey), $in->bucket, "{$label}: o bucket vai na avaliação");
        $this->assertEquals(25, $in->toMetadata()['rollout_percent']);
        $out = $flags->evaluate(self::RENEWAL, new Module30Unit($outKey));
        $this->assertTrue(!$out->value && $out->reason === FeatureFlagReason::OUTSIDE_ROLLOUT, "{$label}: bucket a partir de 2500 = OUTSIDE_ROLLOUT");
        $this->assertEquals(FeatureFlags::bucket(self::RENEWAL, $outKey), $out->toMetadata()['bucket'], "{$label}: bucket nos metadados");
        $this->assertEquals('DEFAULT', $out->toMetadata()['open_feature_reason']);
        $this->assertEquals($outKey, $out->subjectKey, "{$label}: chave lida pelo caminho declarado");
        $this->assertTrue($flags->evaluate(self::RENEWAL)->reason === FeatureFlagReason::NOT_IN_TARGET, "{$label}: sem sujeito = NOT_IN_TARGET");
        $this->assertTrue($flags->evaluate(self::RENEWAL, new \stdClass())->reason === FeatureFlagReason::NOT_IN_TARGET, "{$label}: sujeito sem chave = NOT_IN_TARGET");
        $this->assertTrue($flags->evaluate(self::RENEWAL, new Module30Unit(null))->reason === FeatureFlagReason::NOT_IN_TARGET, "{$label}: chave nula = NOT_IN_TARGET");
        $this->assertTrue($flags->evaluate(self::PENDING)->reason === FeatureFlagReason::DISABLED, "{$label}: a outra flag não é afetada");

        // FeatureEnabledSpecification: o motivo novo nos metadados da falha (D8)
        $spec = $flags->spec(self::RENEWAL);
        $failure = $spec->evaluate(new Module30Unit($outKey));
        $this->assertFalse($failure->isSatisfied || $failure->isError, "{$label}: fora do rollout = falha, não erro");
        $this->assertEquals('outside_rollout', $failure->failures[0]->metadata['reason'], "{$label}: motivo na falha da spec");
        $this->assertEquals(FeatureFlags::bucket(self::RENEWAL, $outKey), $failure->failures[0]->metadata['bucket'], "{$label}: bucket na falha da spec");
        $this->assertTrue($spec->isSatisfiedBy(new Module30Unit($inKey)) && $spec->not()->isSatisfiedBy(new Module30Unit($outKey)), "{$label}: compõe");

        $flags->setRollout(self::RENEWAL, 50, 'Half of the agencies now');
        $this->assertEquals('25% → 50%', $flags->history(self::RENEWAL, 1)[0]->detail, "{$label}: '25% → 50%'");
        $same = $this->assertThrows(StaleFeatureFlagException::class, fn() => $flags->setRollout(self::RENEWAL, 50, 'Same percentage twice'), "{$label}: mesmo percentual = obsoleto");
        $this->assertTrue(str_contains($same->getMessage(), 'is already at 50%'), "{$label}: mensagem do percentual repetido");
        $flags->setRollout(self::RENEWAL, 0, 'Pause the rollout for everyone');
        $this->assertTrue($flags->evaluate(self::RENEWAL, $inKey)->reason === FeatureFlagReason::OUTSIDE_ROLLOUT, "{$label}: 0% = ninguém");
        $flags->setRollout(self::RENEWAL, 100, 'Everyone with a key');
        $this->assertTrue($flags->isEnabled(self::RENEWAL, $outKey) && $flags->isEnabled(self::RENEWAL, $inKey), "{$label}: 100% = todos com chave");
        $this->assertTrue($flags->evaluate(self::RENEWAL)->reason === FeatureFlagReason::NOT_IN_TARGET, "{$label}: 100% ainda pede sujeito");
        $state = $flags->setRollout(self::RENEWAL, null, 'Rollout over, plain switch again');
        $this->assertEquals(null, $state->rolloutPercent, "{$label}: rollout limpo");
        $this->assertEquals('100% → none', $flags->history(self::RENEWAL, 1)[0]->detail, "{$label}: '100% → none'");
        $this->assertTrue($flags->evaluate(self::RENEWAL)->reason === FeatureFlagReason::ENABLED, "{$label}: sem rollout = como antes (RN-01)");
        $same = $this->assertThrows(StaleFeatureFlagException::class, fn() => $flags->setRollout(self::RENEWAL, null, 'Clearing twice by mistake'), "{$label}: limpar sem rollout = obsoleto");
        $this->assertTrue(str_contains($same->getMessage(), 'has no rollout'), "{$label}: mensagem de limpar sem rollout");

        // Uma troca recusada também faz a instância reler (refresh no finally)
        $flags->evaluate(self::RENEWAL);
        $version = (int) $store->find(self::RENEWAL)?->version;
        $store->compareAndSetRollout(self::RENEWAL, $version, 0, self::utc('2026-10-18 12:00:00'), 'script');
        $this->assertThrows(StaleFeatureFlagException::class, fn() => $flags->setRollout(self::RENEWAL, 0, 'Zero was set by a script'), "{$label}: 0% já gravado por fora");
        $this->assertTrue($flags->evaluate(self::RENEWAL, $inKey)->reason === FeatureFlagReason::OUTSIDE_ROLLOUT, "{$label}: depois da recusa a instância vê a escrita de fora");

        $kinds = array_map(static fn(FeatureFlagChange $c): string => $c->kind->value, $flags->history(self::RENEWAL));
        $this->assertEquals(['rollout', 'rollout', 'rollout', 'rollout', 'rollout', 'toggle'], $kinds, "{$label}: histórico em ordem, mais recente primeiro");
    }

    private function contractEvaluationOrder(string $label, IFeatureFlagStore $store): void
    {
        [$flags, $clock] = $this->service($store);
        [$inKey, $outKey] = self::keysAround(self::RENEWAL, 50);
        [$goldIn, $goldOut] = self::keysAround(self::GOLD, 50);

        // Rollout com a flag desligada: DISABLED vence
        $flags->setRollout(self::RENEWAL, 50, 'Prepared before switching on');
        $this->assertTrue($flags->evaluate(self::RENEWAL, $inKey)->reason === FeatureFlagReason::DISABLED, "{$label}: D6: desligada antes de tudo");
        $flags->toggle(self::RENEWAL, true, 'Switch on with the rollout');

        // Fora da janela: OUTSIDE_VALIDITY antes do rollout
        $flags->setValidity(self::RENEWAL, $clock->now->modify('+1 day'), null, 'Starts tomorrow');
        $this->assertTrue($flags->evaluate(self::RENEWAL, $inKey)->reason === FeatureFlagReason::OUTSIDE_VALIDITY, "{$label}: D6: vigência antes do rollout");
        $this->assertTrue($flags->evaluate(self::RENEWAL)->reason === FeatureFlagReason::OUTSIDE_VALIDITY, "{$label}: D6: vigência antes do sujeito");
        $flags->setValidity(self::RENEWAL, null, null, 'Starts now after all');

        // Lista + rollout: fora da lista = NOT_IN_TARGET mesmo com bucket dentro; na lista com bucket fora = OUTSIDE_ROLLOUT
        $flags->addSubject(self::RENEWAL, $outKey, 'Pilot agency, unlucky bucket');
        $this->assertTrue($flags->evaluate(self::RENEWAL, $inKey)->reason === FeatureFlagReason::NOT_IN_TARGET, "{$label}: D6: lista antes do rollout");
        $this->assertEquals(null, $flags->evaluate(self::RENEWAL, $inKey)->bucket, "{$label}: fora da lista, nem calcula bucket");
        $this->assertTrue($flags->evaluate(self::RENEWAL, $outKey)->reason === FeatureFlagReason::OUTSIDE_ROLLOUT, "{$label}: D6: na lista, fora do rollout");
        $flags->addSubject(self::RENEWAL, $inKey, 'Pilot agency, lucky bucket');
        $this->assertTrue($flags->evaluate(self::RENEWAL, $inKey)->reason === FeatureFlagReason::TARGETING_MATCH, "{$label}: D6: na lista e no rollout");

        // Alvo em código + rollout
        $flags->toggle(self::GOLD, true, 'Gold feature on');
        $flags->setRollout(self::GOLD, 50, 'Half of the gold agencies');
        $this->assertTrue($flags->evaluate(self::GOLD, new Module30Unit($goldIn, 'basic'))->reason === FeatureFlagReason::NOT_IN_TARGET, "{$label}: D6: alvo em código antes do rollout");
        $this->assertTrue($flags->evaluate(self::GOLD, new Module30Unit($goldOut, 'gold'))->reason === FeatureFlagReason::OUTSIDE_ROLLOUT, "{$label}: D6: alvo ok, rollout fora");
        $this->assertTrue($flags->evaluate(self::GOLD, new Module30Unit($goldIn, 'gold'))->reason === FeatureFlagReason::TARGETING_MATCH, "{$label}: D6: alvo e rollout");

        // Sem janela nem percentual: como na 2.2.0 (RN-01)
        $this->assertTrue($flags->evaluate(self::PENDING)->reason === FeatureFlagReason::DISABLED);
        $flags->toggle(self::PENDING, true, 'Plain switch on for everyone');
        $plain = $flags->evaluate(self::PENDING, 'anyone');
        $this->assertTrue($plain->reason === FeatureFlagReason::ENABLED && $plain->bucket === null, "{$label}: RN-01: sem rollout, ENABLED e sem bucket");
    }

    private function contractListing(string $label, IFeatureFlagStore $store): void
    {
        [$flags] = $this->service($store);
        $from = self::utc('2026-11-01 00:00:00');
        $flags->setValidity(self::RENEWAL, $from, null, 'Window for the listing');
        $flags->setRollout(self::RENEWAL, 35, 'Rollout for the listing');
        $views = $flags->all();
        $this->assertEquals([self::RENEWAL, self::PENDING, self::GOLD], array_map(static fn(FeatureFlagView $v): string => $v->code, $views), "{$label}: ordem do registro");
        $this->assertTrue($views[0]->validFrom == $from && $views[0]->validUntil === null && $views[0]->rolloutPercent === 35, "{$label}: D7: janela e percentual na listagem");
        $this->assertTrue($views[1]->validFrom === null && $views[1]->rolloutPercent === null, "{$label}: D7: sem linha, nulos");
    }

    /**
     * RN-05: o piloto por lista vira janela de 30 dias, depois rollout de 25% e 100%; o histórico conta a
     * história em ordem.
     */
    private function contractPilotToRollout(string $label, IFeatureFlagStore $store): void
    {
        [$flags, $clock] = $this->service($store, '2026-11-02 09:00:00');
        $tick = static function () use ($clock): void {
            $clock->now = $clock->now->modify('+1 hour');
        };

        // 1. Piloto: só as imobiliárias 7 e 8
        $flags->addSubject(self::RENEWAL, 7, 'Pilot with agency seven', 'po');
        $tick();
        $flags->addSubject(self::RENEWAL, 8, 'Pilot with agency eight', 'po');
        $tick();
        $flags->toggle(self::RENEWAL, true, 'Pilot starts today', 'po');
        $this->assertTrue($flags->isEnabled(self::RENEWAL, new Module30Unit(7)) && !$flags->isEnabled(self::RENEWAL, new Module30Unit(9)), "{$label}: RN-05: piloto");

        // 2. Janela de 30 dias para o lançamento
        $tick();
        $start = $clock->now;
        $flags->setValidity(self::RENEWAL, $start, $start->modify('+30 days'), 'Launch window of thirty days', 'po');

        // 3. Fim do piloto: lista vazia, rollout de 25%
        $tick();
        $flags->removeSubject(self::RENEWAL, 7, 'Pilot over for seven', 'po');
        $tick();
        $flags->removeSubject(self::RENEWAL, 8, 'Pilot over for eight', 'po');
        $tick();
        $flags->setRollout(self::RENEWAL, 25, 'First quarter of the agencies', 'po');
        $on = 0;
        for ($unit = 1; $unit <= 2000; $unit++) {
            $evaluation = $flags->evaluate(self::RENEWAL, new Module30Unit($unit));
            $on += $evaluation->value ? 1 : 0;
            if ($evaluation->value !== (FeatureFlags::bucket(self::RENEWAL, (string) $unit) < 2500)) {
                $this->assertTrue(false, "{$label}: RN-05: unidade {$unit} fora do bucket");
            }
        }
        $this->assertTrue($on > 400 && $on < 600, "{$label}: RN-05: cerca de um quarto de 2000 ({$on})");

        // 4. Todo mundo
        $tick();
        $flags->setRollout(self::RENEWAL, 100, 'Every agency now', 'po');
        $this->assertTrue($flags->isEnabled(self::RENEWAL, new Module30Unit(1234)), "{$label}: RN-05: 100%");

        // 5. Depois dos 30 dias, a janela fecha
        $clock->now = $start->modify('+30 days +1 second');
        $this->assertTrue($flags->evaluate(self::RENEWAL, new Module30Unit(7))->reason === FeatureFlagReason::OUTSIDE_VALIDITY, "{$label}: RN-05: a janela fecha");

        $story = array_reverse($flags->history(self::RENEWAL));
        $this->assertEquals(
            ['subject', 'subject', 'toggle', 'validity', 'subject', 'subject', 'rollout', 'rollout'],
            array_map(static fn(FeatureFlagChange $c): string => $c->kind->value, $story),
            "{$label}: RN-05: histórico lido em ordem"
        );
        $this->assertEquals(
            [null, null, null, '2026-11-02T12:00:00+00:00 → 2026-12-02T12:00:00+00:00', null, null, 'none → 25%', '25% → 100%'],
            array_map(static fn(FeatureFlagChange $c): ?string => $c->detail, $story),
            "{$label}: RN-05: detalhes"
        );
        $this->assertEquals(['7', '8', null, null, '7', '8', null, null], array_map(static fn(FeatureFlagChange $c): ?string => $c->subjectKey, $story));
        $this->assertEquals([true, true, true, true, false, false, true, true], array_map(static fn(FeatureFlagChange $c): bool => $c->newEnabled, $story), "{$label}: RN-05: newEnabled de cada linha");
        $at = array_map(static fn(FeatureFlagChange $c): int => $c->changedAt->getTimestamp(), $story);
        $sorted = $at;
        sort($sorted);
        $this->assertEquals($sorted, $at, "{$label}: RN-05: instantes crescentes");
    }

    /**
     * Two subject keys of a flag: one inside a rollout of $percent and one outside.
     *
     * @return array{0: string, 1: string}
     */
    private static function keysAround(string $code, int $percent): array
    {
        $in = null;
        $out = null;
        for ($i = 0; $in === null || $out === null; $i++) {
            $key = (string) (1000 + $i);
            if (FeatureFlags::bucket($code, $key) < $percent * 100) {
                $in ??= $key;
            } else {
                $out ??= $key;
            }
        }

        return [$in, $out];
    }

    ///////////////////////////////////////////////////////////////////////////
    // Stores sem janela, sem versão ou sem rollout
    ///////////////////////////////////////////////////////////////////////////

    private function testInMemoryWithoutWindowOrRollout(): void
    {
        $plain = new InMemoryFeatureFlagStore(validity: false, rollout: false);
        $this->assertFalse($plain->supportsValidity() || $plain->supportsRollout(), 'memória sem janela nem rollout');
        $flags = new FeatureFlags($this->registry(), $plain);
        $window = $this->assertThrows(FeatureFlagStoreException::class, fn() => $flags->setValidity(self::RENEWAL, null, self::utc('2026-12-31'), self::REASON), 'sem janela: recusado');
        $this->assertTrue(str_contains($window->getMessage(), 'valid_from'), 'a mensagem cita a coluna da vigência');
        $rollout = $this->assertThrows(FeatureFlagStoreException::class, fn() => $flags->setRollout(self::RENEWAL, 10, self::REASON), 'sem rollout: recusado');
        $this->assertTrue(str_contains($rollout->getMessage(), 'rollout_percent'), 'a mensagem cita a coluna do rollout');
        $this->assertEquals(null, $plain->find(self::RENEWAL), 'nenhuma linha criada');
        $this->assertThrows(FeatureFlagStoreException::class, fn() => $plain->compareAndSetValidity(self::RENEWAL, 1, null, null, self::utc('2026-10-18'), null));
        $this->assertThrows(FeatureFlagStoreException::class, fn() => $plain->compareAndSetRollout(self::RENEWAL, 1, 5, self::utc('2026-10-18'), null));
        $this->assertThrows(InvalidArgumentException::class, fn() => $plain->put(new FeatureFlagState(self::RENEWAL, true, validUntil: self::utc('2026-12-31'))), 'put com janela recusado');
        $this->assertThrows(InvalidArgumentException::class, fn() => $plain->put(new FeatureFlagState(self::RENEWAL, true, validFrom: self::utc('2026-12-31'))), 'put só com início recusado');
        $plain->put(new FeatureFlagState(self::PENDING, true));
        $this->assertTrue($plain->find(self::PENDING)?->enabled === true, 'put sem janela nem rollout aceito no store que não os guarda');
        $this->assertThrows(InvalidArgumentException::class, fn() => $plain->put(new FeatureFlagState(self::RENEWAL, true, rolloutPercent: 5)), 'put com rollout recusado');
        $flags->toggle(self::RENEWAL, true, 'The switch keeps working');
        $this->assertTrue($flags->isEnabled(self::RENEWAL, 'x'), 'o interruptor continua');

        // put() sem versão: versão 1, como o default do DDL; com versão: mantida
        $store = new InMemoryFeatureFlagStore();
        $store->put(new FeatureFlagState(self::PENDING, true));
        $this->assertEquals(1, $store->find(self::PENDING)?->version, 'put sem versão = versão 1');
        $store->put(new FeatureFlagState(self::GOLD, true, version: 7, rolloutPercent: 20));
        $this->assertEquals(7, $store->find(self::GOLD)?->version, 'put com versão = mantida');
        $this->assertEquals(20, $store->find(self::GOLD)?->rolloutPercent, 'put com rollout');
        (new FeatureFlags($this->registry(), $store))->toggle(self::GOLD, false, 'Off keeps the rollout');
        $this->assertTrue($store->find(self::GOLD)?->version === 8 && $store->find(self::GOLD)?->rolloutPercent === 20, 'o interruptor incrementa e preserva o rollout');
    }

    /**
     * RN-01/RN-04: o PdoFeatureFlagStore sem COLUMNS_SINCE_2_3 lê e grava como na 2.2.0 (sem rollout,
     * histórico sem tipo); coluna de vigência ou de versão ausente recusa a janela com a coluna na mensagem.
     */
    private function testPdoWithoutTheNewColumns(): void
    {
        $pdo = $this->sqlite();
        $store = new PdoFeatureFlagStore($pdo);
        $this->assertTrue($store->supportsValidity(), 'PDO padrão: janela suportada');
        $this->assertFalse($store->supportsRollout(), 'PDO padrão: rollout só com COLUMNS_SINCE_2_3');
        $this->assertEquals(null, $store->getColumns()['flag']['rollout_percent'], 'rollout_percent ausente por padrão');
        $this->assertEquals(null, $store->getColumns()['change']['change_kind'], 'change_kind ausente por padrão');
        $this->assertEquals(null, $store->getColumns()['change']['detail'], 'detail ausente por padrão');
        $this->assertEquals([...PdoFeatureFlagStore::FLAG_COLUMNS, ...PdoFeatureFlagStore::FLAG_OPTIONAL_COLUMNS, 'rollout_percent'], PdoFeatureFlagStore::canonicalColumns()['flag'], 'colunas canônicas da tabela de flags');
        $this->assertEquals([...PdoFeatureFlagStore::CHANGE_COLUMNS, 'change_kind', 'detail'], PdoFeatureFlagStore::canonicalColumns()['change'], 'colunas canônicas do histórico');
        $this->assertEquals(PdoFeatureFlagStore::canonicalColumns()['flag'], array_keys($store->getColumns()['flag']), 'o mapa resolvido tem toda coluna canônica');
        $this->assertEquals(PdoFeatureFlagStore::canonicalColumns()['change'], array_keys($store->getColumns()['change']));

        $flags = new FeatureFlags($this->registry(), $store);
        $flags->toggle(self::RENEWAL, true, 'Switch on in a 2.1.0 table');
        $error = $this->assertThrows(FeatureFlagStoreException::class, fn() => $flags->setRollout(self::RENEWAL, 10, self::REASON), 'sem a coluna: rollout recusado');
        $this->assertTrue(str_contains($error->getMessage(), 'rollout_percent'), 'a mensagem cita rollout_percent');
        $direct = $this->assertThrows(FeatureFlagStoreException::class, fn() => $store->compareAndSetRollout(self::RENEWAL, 1, 10, self::utc('2026-10-18'), null));
        $this->assertTrue(str_contains($direct->getMessage(), 'feature_flag has no rollout_percent column'), 'o store cita a tabela e a coluna');

        // A janela funciona; a linha do histórico sai como hoje (sem tipo nem detalhe) e volta como Toggle
        $flags->setValidity(self::RENEWAL, self::utc('2026-11-01'), null, 'Window in a table without kinds');
        $row = $flags->history(self::RENEWAL, 1)[0];
        $this->assertTrue($row->kind === FeatureFlagChangeKind::Toggle && $row->detail === null && $row->oldEnabled === true && $row->newEnabled === true, 'sem change_kind: gravada como hoje, lida como Toggle');
        $raw = $pdo->query("SELECT change_kind, detail FROM feature_flag_change ORDER BY changed_at DESC, change_id DESC")->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals(['change_kind' => 'toggle', 'detail' => null], $raw, 'a coluna existente recebe o default do DDL');

        // Coluna de vigência mapeada para null: recusado, citando a coluna
        $noWindow = new PdoFeatureFlagStore($pdo, columns: ['flag' => ['valid_from' => null]]);
        $this->assertFalse($noWindow->supportsValidity(), 'sem valid_from: sem janela');
        $refused = $this->assertThrows(FeatureFlagStoreException::class, fn() => (new FeatureFlags($this->registry(), $noWindow))->setValidity(self::RENEWAL, null, self::utc('2026-12-31'), self::REASON), 'RN-04: sem coluna de vigência, setValidity recusado');
        $this->assertTrue(str_contains($refused->getMessage(), 'valid_from'), 'RN-04: a mensagem cita a coluna');
        $direct = $this->assertThrows(FeatureFlagStoreException::class, fn() => $noWindow->compareAndSetValidity(self::RENEWAL, 1, null, null, self::utc('2026-10-18'), null));
        $this->assertTrue(str_contains($direct->getMessage(), 'has no valid_from column'), 'o store cita só a coluna que falta');
        $noVersion = new PdoFeatureFlagStore($pdo, columns: array_replace_recursive(PdoFeatureFlagStore::COLUMNS_SINCE_2_3, ['flag' => ['version' => null]]));
        $this->assertFalse($noVersion->supportsValidity() || $noVersion->supportsRollout(), 'sem version: nem janela nem rollout (CAS pela versão)');
        $direct = $this->assertThrows(FeatureFlagStoreException::class, fn() => $noVersion->compareAndSetRollout(self::RENEWAL, 1, 5, self::utc('2026-10-18'), null));
        $this->assertTrue(str_contains($direct->getMessage(), 'no version column'), 'o store cita version');
        $this->assertThrows(InvalidArgumentException::class, fn() => (new PdoFeatureFlagStore($pdo, columns: PdoFeatureFlagStore::COLUMNS_SINCE_2_3))->compareAndSetRollout(self::RENEWAL, 1, 101, self::utc('2026-10-18'), null), 'o store também recusa > 100');

        // Carimbos de atualização quando mapeados; linha excluída logicamente não muda
        $pdo->exec('ALTER TABLE feature_flag ADD COLUMN updated_at VARCHAR(26)');
        $pdo->exec('ALTER TABLE feature_flag ADD COLUMN updated_by VARCHAR(120)');
        $pdo->exec('ALTER TABLE feature_flag ADD COLUMN deleted_at VARCHAR(26)');
        $stamped = new PdoFeatureFlagStore($pdo, columns: ['flag' => ['updated_at' => 'updated_at', 'updated_by' => 'updated_by', 'deleted_at' => 'deleted_at'] + PdoFeatureFlagStore::COLUMNS_SINCE_2_3['flag']] + PdoFeatureFlagStore::COLUMNS_SINCE_2_3);
        $version = (int) $stamped->find(self::RENEWAL)?->version;
        $this->assertTrue($stamped->compareAndSetRollout(self::RENEWAL, $version, 15, self::utc('2026-10-18 08:00:00'), 77));
        $this->assertEquals(['updated_at' => '2026-10-18 08:00:00.000000', 'updated_by' => '77'], $pdo->query("SELECT updated_at, updated_by FROM feature_flag WHERE code = 'renewal_notice'")->fetch(PDO::FETCH_ASSOC), 'updated_* carimbados');
        $pdo->exec("UPDATE feature_flag SET deleted_at = '2026-10-18 09:00:00.000000' WHERE code = 'renewal_notice'");
        $this->assertFalse($stamped->compareAndSetValidity(self::RENEWAL, $version + 1, null, null, self::utc('2026-10-18'), null), 'excluída logicamente não muda');
    }

    /** Leitura estrita: percentual fora de 0..100 e tipo de mudança desconhecido são erro, nunca um valor silencioso. */
    private function testPdoStrictReads(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE ff (code VARCHAR(80) PRIMARY KEY, name VARCHAR(200), enabled CHAR(1), version INTEGER, pct VARCHAR(10))');
        $pdo->exec('CREATE TABLE ffc (change_id VARCHAR(26) PRIMARY KEY, flag_code VARCHAR(80), subject_key VARCHAR(190), old_enabled CHAR(1), new_enabled CHAR(1), reason VARCHAR(1000), origin VARCHAR(20), changed_at VARCHAR(26), changed_by VARCHAR(120), kind VARCHAR(20), detail VARCHAR(400))');
        $pdo->exec("INSERT INTO ff VALUES ('renewal_notice', 'R', 'Y', 1, ' 40 ')");
        $store = new PdoFeatureFlagStore(
            $pdo,
            tables: ['flag' => 'ff', 'change' => 'ffc', 'subject' => null],
            columns: [
                'flag' => ['description' => null, 'category' => null, 'audience' => null, 'notes' => null, 'valid_from' => null, 'valid_until' => null, 'enabled_at' => null, 'enabled_by' => null, 'disabled_at' => null, 'disabled_by' => null, 'rollout_percent' => 'pct'],
                'change' => ['change_kind' => 'kind', 'detail' => 'detail'],
            ]
        );
        $this->assertEquals(40, $store->find(self::RENEWAL)?->rolloutPercent, 'percentual em texto com espaços');
        foreach (['101', '-1', '12.5', 'half', ''] as $bad) {
            $pdo->prepare('UPDATE ff SET pct = ?')->execute([$bad]);
            if ($bad === '') {
                $this->assertThrows(FeatureFlagStoreException::class, fn() => $store->loadAll(), 'percentual vazio é erro');
                continue;
            }
            $error = $this->assertThrows(FeatureFlagStoreException::class, fn() => $store->loadAll(), "percentual '{$bad}' é erro");
            $this->assertTrue(str_contains($error->getMessage(), "holds '{$bad}'"), 'a mensagem mostra o valor lido');
            $this->assertTrue(str_contains($error->getMessage(), 'pct (rollout_percent)'), 'a mensagem cita a coluna real e a canônica');
        }
        $reporter = new InMemoryFeatureFlagErrorReporter();
        $this->assertTrue((new FeatureFlags($this->registry(), $store, $reporter))->evaluate(self::RENEWAL, 'x')->reason === FeatureFlagReason::ERROR, 'percentual ilegível = ERROR (falha fechada)');
        $this->assertCount(1, $reporter->entries());
        $pdo->exec('UPDATE ff SET pct = NULL');

        $row = "INSERT INTO ffc (change_id, flag_code, subject_key, old_enabled, new_enabled, reason, origin, changed_at, kind, detail) VALUES (?, 'renewal_notice', ?, 'Y', 'Y', 'r', 'ui', ?, ?, ?)";
        $insert = $pdo->prepare($row);
        $insert->execute(['01', null, '2026-10-18 10:00:01', ' ROLLOUT ', '10% → 20%']);
        $insert->execute(['02', '7', '2026-10-18 10:00:02', 'toggle', null]);
        $insert->execute(['03', null, '2026-10-18 10:00:03', null, null]);
        $history = $store->history(self::RENEWAL);
        $this->assertEquals(['toggle', 'subject', 'rollout'], array_map(static fn(FeatureFlagChange $c): string => $c->kind->value, $history), "tipo nulo = Toggle; 'toggle' com sujeito = Subject; maiúsculas aceitas");
        $this->assertEquals('10% → 20%', $history[2]->detail);
        $insert->execute(['04', null, '2026-10-18 10:00:04', 'flip', null]);
        $error = $this->assertThrows(FeatureFlagStoreException::class, fn() => $store->history(self::RENEWAL), 'tipo desconhecido é erro');
        $this->assertTrue(str_contains($error->getMessage(), "holds 'flip'"), 'a mensagem mostra o tipo lido');
        $this->assertTrue(str_contains($error->getMessage(), 'kind (change_kind)'), 'a mensagem cita a coluna');
        $pdo->exec("DELETE FROM ffc WHERE change_id = '04'");
        $insert->execute(['05', null, '2026-10-18 10:00:05', 'subject', null]);
        $error = $this->assertThrows(FeatureFlagStoreException::class, fn() => $store->history(self::RENEWAL), 'Subject sem chave é erro');
        $this->assertEquals("A history row of kind 'subject' needs a subject key.", $error->getMessage());
        $pdo->exec("DELETE FROM ffc WHERE change_id = '05'");
        $insert->execute(['06', '9', '2026-10-18 10:00:06', 'validity', null]);
        $error = $this->assertThrows(FeatureFlagStoreException::class, fn() => $store->history(self::RENEWAL), 'Validity com chave é erro');
        $this->assertEquals("A history row of kind 'validity' cannot carry a subject key.", $error->getMessage());
    }

    /**
     * RN-01/RN-06: uma tabela da 2.1.0 continua funcionando sem migrar; a migration aditiva comentada no DDL
     * de referência a leva à 2.3.0, e as linhas antigas leem como Toggle (ou Subject, com chave).
     */
    private function testPdoMigrationFrom210(PDO $pdo, string $family): void
    {
        $this->resetSchema($pdo, self::DDL_2_1_0);
        $old = new PdoFeatureFlagStore($pdo);
        $before = new FeatureFlags($this->registry(), $old);
        $before->toggle(self::RENEWAL, true, 'Switched on under 2.1.0');
        $before->addSubject(self::RENEWAL, 7, 'Pilot under 2.1.0');
        $before->setValidity(self::RENEWAL, null, self::utc('2099-12-31'), 'Window set with 2.3.0 on a 2.1.0 table');
        $this->assertTrue($before->isEnabled(self::RENEWAL, 7), "{$family}: tabela da 2.1.0 sem migrar: lida e gravada");

        $statements = self::migrationStatements();
        $this->assertCount(4, $statements, 'quatro instruções na migration comentada do DDL');
        foreach (array_slice($statements, 0, 3) as $statement) {
            $pdo->exec($statement);
        }
        $new = new PdoFeatureFlagStore($pdo, columns: PdoFeatureFlagStore::COLUMNS_SINCE_2_3);
        $flags = new FeatureFlags($this->registry(), $new);
        $kinds = array_map(static fn(FeatureFlagChange $c): string => $c->kind->value, array_reverse($flags->history(self::RENEWAL)));
        $this->assertEquals(['toggle', 'subject', 'toggle'], $kinds, "{$family}: linhas antigas: Toggle, e Subject pela chave (antes do UPDATE)");
        $pdo->exec($statements[3]);
        $this->assertEquals('subject', $pdo->query("SELECT change_kind FROM feature_flag_change WHERE subject_key IS NOT NULL")->fetchColumn(), "{$family}: o UPDATE marca as linhas de sujeito");

        $flags->setRollout(self::RENEWAL, 25, 'Rollout after the migration');
        $this->assertEquals(25, $new->find(self::RENEWAL)?->rolloutPercent, "{$family}: rollout depois da migration");
        $this->assertTrue($flags->history(self::RENEWAL, 1)[0]->kind === FeatureFlagChangeKind::Rollout && $flags->history(self::RENEWAL, 1)[0]->detail === 'none → 25%', "{$family}: histórico com tipo e detalhe");
        $this->assertTrue($flags->evaluate(self::RENEWAL, 7)->reason === (FeatureFlags::bucket(self::RENEWAL, '7') < 2500 ? FeatureFlagReason::TARGETING_MATCH : FeatureFlagReason::OUTSIDE_ROLLOUT), "{$family}: lista + rollout depois da migration");

        $check = null;
        try {
            $pdo->exec("UPDATE feature_flag SET rollout_percent = 101 WHERE code = 'renewal_notice'");
        } catch (\PDOException $e) {
            $check = $e;
        }
        $this->assertTrue($check !== null, "{$family}: o CHECK da migration recusa 101");
    }

    /**
     * The statements of the additive migration commented in the reference DDL.
     *
     * @return list<string>
     */
    private static function migrationStatements(): array
    {
        $ddl = (string) file_get_contents(self::DDL);
        $start = strpos($ddl, '-- Additive migration 2.1.0 -> 2.3.0');
        $statements = [];
        foreach (explode("\n", substr($ddl, (int) $start)) as $line) {
            if (preg_match('/^--\s+((?:ALTER TABLE|UPDATE) .+);$/', $line, $match) === 1) {
                $statements[] = $match[1];
            }
        }

        return $statements;
    }

    ///////////////////////////////////////////////////////////////////////////
    // RN-05: exemplo pronto para o README
    ///////////////////////////////////////////////////////////////////////////

    private function testDocumentationExample(): void
    {
        $pdo = $this->sqlite(); // the tables of resources/sql/feature_flags.ansi.sql (2.3.0)

        // --- README ---
        $registry = new FeatureFlagRegistry([
            new FeatureFlagDefinition('renewal_notice', 'Renewal notice to agencies', subjectKey: 'unitId'),
        ]);
        $store = new PdoFeatureFlagStore($pdo, columns: PdoFeatureFlagStore::COLUMNS_SINCE_2_3);
        $flags = new FeatureFlags($registry, $store);

        $flags->toggle('renewal_notice', true, 'Launch approved by the board', actor: 42);

        // Only from November 1st to December 31st (both inclusive)
        $flags->setValidity(
            'renewal_notice',
            new DateTimeImmutable('2026-11-01T00:00:00Z'),
            new DateTimeImmutable('2026-12-31T23:59:59Z'),
            'Year-end campaign',
            actor: 42,
        );

        // A quarter of the agencies first: a stable bucket per agency (0 to 9999)
        $flags->setRollout('renewal_notice', 25, 'Gradual launch, first quarter', actor: 42);

        $agency = new Module30Unit(unitId: 7);
        $november = $flags->withAsOf(new DateTimeImmutable('2026-11-15T12:00:00Z'));
        $evaluation = $november->evaluate('renewal_notice', $agency);
        $r1 = $evaluation->reason;    // TARGETING_MATCH when the bucket is below 2500, OUTSIDE_ROLLOUT otherwise
        $r2 = $evaluation->bucket;    // 8912: the same number in every request, process and machine
        $r3 = $flags->withAsOf(new DateTimeImmutable('2027-01-01T00:00:00Z'))->evaluate('renewal_notice', $agency)->reason; // OUTSIDE_VALIDITY

        $flags->setRollout('renewal_notice', 100, 'Every agency now', actor: 42);
        $r4 = $flags->withAsOf(new DateTimeImmutable('2026-11-15T12:00:00Z'))->isEnabled('renewal_notice', $agency); // true

        $r5 = array_map(
            fn(FeatureFlagChange $change): string => $change->kind->value . ($change->detail !== null ? ': ' . $change->detail : ''),
            $flags->history('renewal_notice'),
        );
        // ['rollout: 25% → 100%', 'rollout: none → 25%', 'validity: 2026-11-01T00:00:00+00:00 → 2026-12-31T23:59:59+00:00', 'toggle']
        // --- /README ---

        $bucket = FeatureFlags::bucket('renewal_notice', '7');
        $this->assertTrue($r1 === ($bucket < 2500 ? FeatureFlagReason::TARGETING_MATCH : FeatureFlagReason::OUTSIDE_ROLLOUT), 'README: o bucket decide o rollout');
        $this->assertTrue($r1 === FeatureFlagReason::OUTSIDE_ROLLOUT, 'README: a 7 cai fora do primeiro quarto');
        $this->assertEquals(8912, $r2, 'README: o bucket da avaliação (o número do comentário)');
        $this->assertEquals($bucket, $r2);
        $this->assertTrue($r3 === FeatureFlagReason::OUTSIDE_VALIDITY, 'README: fora da janela');
        $this->assertTrue($r4, 'README: 100%');
        $this->assertEquals(['rollout: 25% → 100%', 'rollout: none → 25%', 'validity: 2026-11-01T00:00:00+00:00 → 2026-12-31T23:59:59+00:00', 'toggle'], $r5, 'README: histórico');
    }

    /** README 19.1 (2.3.0): o bloco PHP é igual nos dois idiomas, fora os comentários. */
    private function testReadme19_1BlocksAreTheSameInBothLanguages(): void
    {
        $root = dirname(__DIR__, 2);
        $english = ReadmeBlocks::of($root . '/README.md', '19.1');
        $portuguese = ReadmeBlocks::of($root . '/README.pt-BR.md', '19.1');
        $this->assertCount(1, $english, 'um bloco PHP no exemplo 19.1 (EN)');
        $this->assertCount(1, $portuguese, 'um bloco PHP no exemplo 19.1 (pt-BR)');
        $this->assertEquals(ReadmeBlocks::codeTokens($english[0]), ReadmeBlocks::codeTokens($portuguese[0]), 'README 19.1: bloco igual fora os comentários');
        $this->assertTrue(str_contains($english[0], "setRollout('renewal_notice', 25, 'Gradual launch, first quarter'"), 'README 19.1: o bloco é o exemplo executado acima');
    }

    ///////////////////////////////////////////////////////////////////////////
    // Bancos
    ///////////////////////////////////////////////////////////////////////////

    private function sqlite(): PDO
    {
        $pdo = $this->sqliteEmpty();
        self::loadDdl($pdo, (string) file_get_contents(self::DDL));

        return $pdo;
    }

    private function sqliteEmpty(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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
        foreach (['feature_flag_subject', 'feature_flag_change', 'feature_flag'] as $table) {
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
