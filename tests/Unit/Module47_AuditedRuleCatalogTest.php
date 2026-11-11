<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\Cache\CachedRuleCatalog;
use Antevemus\ASpecification\Cache\PdoCatalogVersion;
use Antevemus\ASpecification\Contracts\Engine\IRuleCatalogStore;
use Antevemus\ASpecification\Contracts\Engine\IVersionedRuleDefinition;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Engine\DynamicSpecificationEngine;
use Antevemus\ASpecification\Engine\Exceptions\InvalidRuleChangeReasonException;
use Antevemus\ASpecification\Engine\Exceptions\RuleCatalogDriftException;
use Antevemus\ASpecification\Engine\Exceptions\RuleCatalogEditorException;
use Antevemus\ASpecification\Engine\Exceptions\RuleCatalogStoreException;
use Antevemus\ASpecification\Engine\Exceptions\RuleChangeRefusedException;
use Antevemus\ASpecification\Engine\Exceptions\RuleNotFoundException;
use Antevemus\ASpecification\Engine\Exceptions\StaleRuleChangeException;
use Antevemus\ASpecification\Engine\InMemoryRuleCatalog;
use Antevemus\ASpecification\Engine\InMemoryRuleCatalogStore;
use Antevemus\ASpecification\Engine\PdoRuleCatalog;
use Antevemus\ASpecification\Engine\PdoRuleCatalogStore;
use Antevemus\ASpecification\Engine\RuleAction;
use Antevemus\ASpecification\Engine\RuleBoundSpecification;
use Antevemus\ASpecification\Engine\RuleCatalogEditor;
use Antevemus\ASpecification\Engine\RuleChangeCandidate;
use Antevemus\ASpecification\Engine\RuleChangeKind;
use Antevemus\ASpecification\Engine\RuleDefinition;
use Antevemus\ASpecification\Engine\RuleDrift;
use Antevemus\ASpecification\Engine\RuleDriftKind;
use Antevemus\ASpecification\Engine\RuleRevision;
use Antevemus\ASpecification\Engine\RuleSnapshot;
use Antevemus\ASpecification\Engine\RuleSpecificationRegistry;
use Antevemus\ASpecification\FeatureFlags\Exceptions\InvalidFeatureFlagReasonException;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagRegistry;
use Antevemus\ASpecification\FeatureFlags\FeatureFlags;
use Antevemus\ASpecification\FeatureFlags\InMemoryFeatureFlagErrorReporter;
use Antevemus\ASpecification\FeatureFlags\InMemoryFeatureFlagStore;
use Antevemus\ASpecification\Results\SpecificationResult;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Tests\Support\ArrayCache;
use Antevemus\ASpecification\Tests\Support\CountingSpecification;
use Antevemus\ASpecification\Tests\Support\FailingRuleCatalogStore;
use Antevemus\ASpecification\Tests\Support\PdoRecordingPdo;
use Antevemus\ASpecification\Tests\Support\ReadmeBlocks;
use Antevemus\ASpecification\Tests\TestCase;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;
use Throwable;

/**
 * Module47_AuditedRuleCatalogTest - Forward 060 (2.13.0): audited, versioned writes to the rule catalog.
 *
 * - D1/D2/D3: RuleCatalogEditor::create(), update(), setActive() over InMemoryRuleCatalogStore and
 *   PdoRuleCatalogStore (the same contract battery, runContract()): canonical keys only, values of the right
 *   type, merge of update(), no-op refused, compare-and-set of the version with and without expectedVersion;
 * - D4/D5/RN-06: one history row per version with the full photo, its hash, the kind and the changed columns; the
 *   canonical photo pinned by a golden text and hash (a change of the form is MAJOR) and computed again by an oracle
 *   of the test; the same photo in memory and in every database;
 * - D6: the baseline of a rule that existed before the migration (or whose version was raised by hand), written by
 *   its first change or by adopt();
 * - D7: the reason and the origin follow the rules of FeatureFlags (the two normalizations compared);
 * - D8/RN-02: the change guard, evaluated before writing; refused or not evaluable, nothing is written, baseline
 *   included; a failure of the store after the row and the baseline rolls everything back;
 * - D9/D10: RuleDefinition::getVersion(), PdoRuleCatalog::COLUMNS_SINCE_2_13, `rule_version` in the failures only
 *   when the rule has a version (the metadata of 2.12.0 otherwise), DynamicSpecificationEngine::getCompiledRules();
 * - D11: findDrift() for the four kinds (no history, version ahead, modified, deleted) and the refusal of a change
 *   over a modified row;
 * - D12: a CachedRuleCatalog with PdoCatalogVersion::forRuleCatalog(changeTable: ...) sees a change of the editor at
 *   once; the measurement that MAX(version) of the rule table does not;
 * - D13/RN-01: a database of the 2.12.0 DDL is refused with a message that points to the header of the DDL, and the
 *   SQL of PdoRuleCatalog without the mapping is the one of 2.12.0;
 * - D15: random sequences of create/update/setActive/adopt against a model of the test (seeds SEEDS), on memory, on
 *   SQLite and, with ASPEC_PDO_{PGSQL,MYSQL,MARIADB}_DSN, on PostgreSQL 17, MySQL 8.4 and MariaDB 11.4, the
 *   transcripts of the databases equal to the one of memory; two connections changing the same rule (RN-04);
 * - RN-05: no message of the editor repeats a value, a reason or a received value;
 * - D16: examples 25 to 25.3 of docs/<language>/rule-engine/audited-changes.md run as written, the same code in both
 *   languages.
 *
 * @version    2.13.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Unit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class Module47_AuditedRuleCatalogTest extends TestCase
{
    private const DDL = __DIR__ . '/../../resources/sql/rule_catalog.ansi.sql';

    /** Seeds of the property sequences (D15): fixed, registered in the forward and in quality/tests.md. */
    private const SEEDS = [20261111, 20261112, 20261113];

    private const STEPS_MEMORY = 400;

    private const STEPS_DATABASE = 150;

    private const REASON = 'Approved by the credit committee';

    /** A value that must never reach a message (RN-05). */
    private const SECRET = 'SECRET-VALUE-060-7f3a';

    /** The photo of golden() (D5): the form is a contract, changing it is a MAJOR change. */
    private const GOLDEN_SNAPSHOT = '{"active":true,"code":"golden","condition_expression":null,"decimal_value":"12.5","description":"Teto do aluguel / ç","int_value":5000,"legal_basis":"Lei 8.245/91, art. 43","name":"Golden","parameters":{"a":{"x":1.0,"y":[3,1]},"b":"c/d"},"plan_code":"G1","priority":3,"product_code":"P1","rule_type":"max_rent","scenario":null,"scope":"contract","spec_json":null,"text_value":null,"valid_from":"2026-01-01","valid_until":"2026-12-31","version":1,"violation_action":"warn","violation_message":null}';

    private const GOLDEN_HASH = 'sha256:b7a6e6f9a04de45267a7a46b2d245d6a13eed7ab55f76be440c399c98b83fd72';

    /** Columns update() may change in the sequences, with the values they draw from (pairs keep the row consistent). */
    private const CHOICES = [
        'name' => ['Alpha', 'Beta rule', 'Teto ç', 'Δelta'],
        'description' => [null, 'A description', 'Uma descrição'],
        'int_value' => [null, 0, 5000, -3, 123456],
        'decimal_value' => [null, '12.5', '0.0001', '-3', '1000'],
        'priority' => [0, 1, 10, -1],
        'scope' => [null, 'contract', 'claim'],
        'violation_action' => ['block', 'warn', 'log'],
        'text_value' => [null, 'text', 'a/b'],
        'legal_basis' => [null, 'Lei 8.245/91'],
        'spec_json' => [null, '{"v":1,"spec":{"t":"isNull"}}'],
    ];

    private const PARAMETERS = [null, ['b' => 1, 'a' => [3, 2]], ['x' => 'y/z', 'n' => 1.5], ['k' => ['z' => true, 'a' => null]]];

    private const WINDOWS = [[null, null], ['2026-01-01', null], ['2026-01-01', '2026-12-31'], [null, '2026-12-31']];

    private const PRODUCTS = [[null, null], ['P1', null], ['P1', 'G1'], ['P2', 'G2']];

    /** @var list<string> Messages of every exception the battery saw, checked for the secret (RN-05) */
    private array $messages = [];

    private int $tick = 0;

    public function run(): void
    {
        $this->testInputValidation();
        $this->testReasonAndOriginFollowTheFeatureFlags();
        $this->testSnapshotIsCanonicalAndStable();
        $this->testRevisionIntegrity();
        $this->testVersionedDefinition();
        $this->testEngineStampsTheVersionOnlyWhenThereIsOne();
        $this->testCachedCatalogCarriesTheVersion();
        $this->testInputEdges();
        $this->testStoredValueEdges();
        $this->testMemoryStoreDetails();
        $this->runContract('memória', fn(): array => $this->memoryEnvironment());
        $memoryTranscripts = [];
        foreach (self::SEEDS as $seed) {
            $memoryTranscripts[$seed] = $this->runSequence($this->memoryEnvironment(), $seed, self::STEPS_MEMORY, 'memória');
        }

        if (!extension_loaded('pdo_sqlite')) {
            fwrite(STDOUT, "    [AVISO] pdo_sqlite ausente; o Módulo 47 roda só a parte em memória.\n");
            $this->assertNoSecretInMessages();
            return;
        }

        $this->assertTrue(is_file(self::DDL), 'DDL de referência do catálogo deve existir');
        $this->testCatalogSqlWithoutTheMappingIsThatOf2_12();
        $this->runDatabase('sqlite', 'SQLite', fn(): PDO => self::sqliteEmpty(), $memoryTranscripts);
        $this->testConcurrencyOnSqliteFiles();
        $this->testExample25BlocksAreTheSameInBothLanguages();
        $this->testExample25RunsAsWritten();

        foreach (['pgsql' => 'PostgreSQL', 'mysql' => 'MySQL', 'mariadb' => 'MariaDB'] as $family => $label) {
            if (self::connectFromEnvironment($family) === null) {
                fwrite(STDOUT, "    [AVISO] ASPEC_PDO_" . strtoupper($family) . "_DSN não definido; Módulo 47 em {$label} pulado.\n");
                continue;
            }
            $this->runDatabase($family, $label, static fn(): PDO => self::connectFromEnvironment($family) ?? throw new RuntimeException('no connection'), $memoryTranscripts);
            $first = self::connectFromEnvironment($family);
            $second = self::connectFromEnvironment($family);
            $this->testConcurrency($first, $second, $family);
            self::dropSchema($first);
            fwrite(STDOUT, "    [060] {$label} " . $first->getAttribute(PDO::ATTR_SERVER_VERSION) . ": editor auditado, linha de base, deriva, guarda, cache, concorrência e paridade com a memória OK\n");
        }

        $this->assertNoSecretInMessages();
    }

    // =====================================================================
    // D2 / RN-05: the row given to the editor
    // =====================================================================

    private function testInputValidation(): void
    {
        $editor = new RuleCatalogEditor(new InMemoryRuleCatalogStore(), $this->clock());
        $base = ['code' => 'r1', 'name' => 'Rule', 'rule_type' => 'max_rent'];
        $invalid = [
            'unknown column' => ['color' => 'red'],
            'Portuguese key' => ['nome' => self::SECRET],
            'Portuguese applicability key' => ['codigo_produto' => self::SECRET],
            'version' => ['version' => 7],
            'name not text' => ['name' => 12],
            'blank name' => ['name' => '  '],
            'int_value text' => ['int_value' => self::SECRET],
            'int_value float' => ['int_value' => 1.5],
            'priority null' => ['priority' => null],
            'decimal text' => ['decimal_value' => self::SECRET],
            'decimal exponent' => ['decimal_value' => '1e3'],
            'decimal infinite' => ['decimal_value' => INF],
            'parameters list' => ['parameters' => [self::SECRET]],
            'parameters text' => ['parameters' => self::SECRET],
            'parameters applicability' => ['parameters' => ['product_code' => self::SECRET]],
            'parameters legacy applicability' => ['parameters' => ['codigo_plano' => self::SECRET]],
            'parameters not JSON' => ['parameters' => ['bad' => "\xB1" . self::SECRET]],
            'valid_from not a date' => ['valid_from' => self::SECRET],
            'valid_from impossible' => ['valid_from' => '2026-02-30'],
            'valid_from with time' => ['valid_from' => '2026-02-01 10:00:00'],
            'window reversed' => ['valid_from' => '2026-12-31', 'valid_until' => '2026-01-01'],
            'plan without product' => ['plan_code' => self::SECRET],
            'action unknown' => ['violation_action' => self::SECRET],
            'action Portuguese' => ['violation_action' => 'bloquear'],
            'action not text' => ['violation_action' => 1],
            'active not boolean' => ['active' => 'Y'],
            'text not text' => ['legal_basis' => 5],
        ];
        foreach ($invalid as $label => $values) {
            $e = $this->assertThrows(InvalidArgumentException::class, fn() => $editor->create($values + $base, self::REASON), "create: {$label}");
            $this->messages[] = $e->getMessage();
        }
        foreach (['no name' => ['code' => 'r1', 'rule_type' => 't'], 'no type' => ['code' => 'r1', 'name' => 'n'], 'no code' => ['name' => 'n', 'rule_type' => 't'], 'code with spaces' => ['code' => ' r1'] + $base, 'code not text' => ['code' => 5] + $base] as $label => $row) {
            $this->messages[] = $this->assertThrows(InvalidArgumentException::class, fn() => $editor->create($row, self::REASON), "create: {$label}")->getMessage();
        }
        $this->assertTrue($editor->history() === [], 'nada gravado pelas entradas recusadas');
        $this->assertEquals(
            'Rule "r1": unknown column "color". Expected one of: ' . implode(', ', RuleCatalogEditor::INPUT_COLUMNS) . '.',
            $this->assertThrows(InvalidArgumentException::class, fn() => $editor->create(['color' => 'red'] + $base, self::REASON))->getMessage(),
            'mensagem da coluna desconhecida'
        );
        $this->assertEquals(
            'Rule "r1": column "nome" is Portuguese vocabulary of the rule engine, renamed "name" in 1.7.0 and refused since 2.0.0 (see UPGRADE-2.0.md).',
            $this->assertThrows(InvalidArgumentException::class, fn() => $editor->create(['nome' => 'x'] + $base, self::REASON))->getMessage(),
            'mensagem da chave em português'
        );
        $this->assertEquals(
            'Rule "r1": column int_value needs an integer or null.',
            $this->assertThrows(InvalidArgumentException::class, fn() => $editor->create(['int_value' => self::SECRET] + $base, self::REASON))->getMessage(),
            'mensagem cita regra e coluna, não o valor'
        );

        // Accepted forms: RuleAction, integer and decimal strings, DateTimeInterface, a blank spec_json
        $created = $editor->create($base + [
            'violation_action' => RuleAction::LOG,
            'int_value' => '-42',
            'priority' => '7',
            'decimal_value' => '12.5000',
            'valid_from' => new DateTimeImmutable('2026-03-04 23:59:59', new DateTimeZone('America/Sao_Paulo')),
            'valid_until' => '2026-12-31',
            'spec_json' => '   ',
            'product_code' => '',
            'plan_code' => 'G1',
            'parameters' => [],
        ], self::REASON);
        $row = $created->row();
        $this->assertEquals(['log', -42, 7, '12.5', '2026-03-04', null, null, '', 'G1'], [$row['violation_action'], $row['int_value'], $row['priority'], $row['decimal_value'], $row['valid_from'], $row['spec_json'], $row['parameters'], $row['product_code'], $row['plan_code']], 'formas aceitas e canônicas');
        $this->assertEquals('warn', $editor->update('r1', ['violation_action' => ' WARN '], self::REASON)->row()['violation_action'], 'ação lida como o catálogo lê (caixa e espaços)');

        // update(): active, a changed code and the version are refused; the same code is accepted
        foreach (['active' => ['active' => false], 'code changed' => ['code' => 'r2'], 'version' => ['version' => 3], 'unknown' => ['color' => 1]] as $label => $changes) {
            $this->messages[] = $this->assertThrows(InvalidArgumentException::class, fn() => $editor->update('r1', $changes, self::REASON), "update: {$label}")->getMessage();
        }
        $this->assertEquals(3, $editor->update('r1', ['code' => 'r1', 'name' => 'Renamed'], self::REASON)->version, 'o mesmo código é aceito');
        $this->assertThrows(InvalidArgumentException::class, fn() => $editor->update(' r1', ['name' => 'x'], self::REASON), 'código com espaços');
        $this->assertThrows(InvalidArgumentException::class, fn() => $editor->setActive('', true, self::REASON), 'código vazio');
        $this->assertThrows(InvalidArgumentException::class, fn() => $editor->adopt('', self::REASON), 'código vazio em adopt()');

        // A store without spec_json refuses a body, accepts null
        $noBody = new RuleCatalogEditor(new InMemoryRuleCatalogStore(specJson: false), $this->clock());
        $this->messages[] = $this->assertThrows(InvalidArgumentException::class, fn() => $noBody->create($base + ['spec_json' => '{"v":1,"spec":{"t":"isNull"}}'], self::REASON), 'loja sem spec_json')->getMessage();
        $this->assertEquals(1, $noBody->create($base + ['spec_json' => null], self::REASON)->version, 'spec_json nulo numa loja sem a coluna');
        $this->assertThrows(InvalidArgumentException::class, fn() => (new InMemoryRuleCatalogStore(specJson: false))->put($base + ['spec_json' => 'x']), 'put() sem spec_json');
        $this->assertThrows(InvalidArgumentException::class, fn() => (new InMemoryRuleCatalogStore())->put($base + ['color' => 'x']), 'put() com coluna desconhecida');
        $this->assertThrows(InvalidArgumentException::class, fn() => new RuleCatalogEditor(new InMemoryRuleCatalogStore(), null, 0), 'mínimo do motivo');
        $this->assertThrows(InvalidArgumentException::class, fn() => new RuleCatalogEditor(new InMemoryRuleCatalogStore(), null, 10, 9), 'máximo abaixo do mínimo');
        $this->assertTrue((new RuleCatalogEditor(new InMemoryRuleCatalogStore(), null, 1, 1))->normalizeReason(' x ') === 'x', 'limites iguais');
    }

    // =====================================================================
    // D7: the reason and the origin of the feature flags
    // =====================================================================

    private function testReasonAndOriginFollowTheFeatureFlags(): void
    {
        $this->assertEquals([FeatureFlags::ORIGIN_UI, FeatureFlags::ORIGIN_MIGRATION, FeatureFlags::ORIGIN_SCRIPT], [RuleCatalogEditor::ORIGIN_UI, RuleCatalogEditor::ORIGIN_MIGRATION, RuleCatalogEditor::ORIGIN_SCRIPT], 'mesmas origens');
        $this->assertEquals([FeatureFlags::DEFAULT_REASON_MIN, FeatureFlags::DEFAULT_REASON_MAX, FeatureFlags::DEFAULT_HISTORY_LIMIT], [RuleCatalogEditor::DEFAULT_REASON_MIN, RuleCatalogEditor::DEFAULT_REASON_MAX, RuleCatalogEditor::DEFAULT_HISTORY_LIMIT], 'mesmos limites');
        $random = new Randomizer(new Mt19937(self::SEEDS[0]));
        $pieces = ['a', 'B', ' ', "\t", "\n", "\u{00A0}", 'ç', 'Δ', '  ', "\r\n", 'x', '.'];
        foreach ([[10, 1000], [1, 3], [5, 5], [2, 40]] as [$min, $max]) {
            $flags = new FeatureFlags(new FeatureFlagRegistry(), new InMemoryFeatureFlagStore(), new InMemoryFeatureFlagErrorReporter(), null, $min, $max);
            $editor = new RuleCatalogEditor(new InMemoryRuleCatalogStore(), null, $min, $max);
            for ($i = 0; $i < 300; $i++) {
                $reason = '';
                for ($n = $random->getInt(0, 45); $n > 0; $n--) {
                    $reason .= $pieces[$random->getInt(0, count($pieces) - 1)];
                }
                $this->assertEquals(self::normalized(fn() => $flags->normalizeReason($reason)), self::normalized(fn() => $editor->normalizeReason($reason)), "motivo igual ao das flags ({$min}..{$max}, #{$i})");
            }
        }
        $editor = new RuleCatalogEditor(new InMemoryRuleCatalogStore(), $this->clock());
        $this->assertThrows(InvalidArgumentException::class, fn() => $editor->create(['code' => 'o', 'name' => 'n', 'rule_type' => 't'], self::REASON, null, '  '), 'origem vazia');
        $revision = $editor->create(['code' => 'o', 'name' => 'n', 'rule_type' => 't'], "  Approved \n by   the\tboard ", 42, '  backoffice ');
        $this->assertEquals(['Approved by the board', 'backoffice', '42'], [$revision->reason, $revision->origin, $revision->changedBy], 'motivo normalizado, origem aparada, ator como texto');
        $this->assertEquals('ui', $editor->update('o', ['name' => 'm'], self::REASON)->origin, 'origem padrão ui');
        $this->assertTrue($editor->adopt(null, self::REASON) === [], 'adopt() sem nada a adotar');
        $reasonError = $this->assertThrows(InvalidRuleChangeReasonException::class, fn() => $editor->update('o', ['name' => 'k'], 'short ' . "\n"));
        $this->assertEquals('Give the reason of the change (at least 10 characters).', $reasonError->getMessage(), 'mensagem do motivo curto');
        $this->assertEquals('The reason is too long (at most 1000 characters).', $this->assertThrows(InvalidRuleChangeReasonException::class, fn() => $editor->update('o', ['name' => 'k'], str_repeat(self::SECRET, 60)))->getMessage(), 'mensagem do motivo longo');
    }

    /** The normalized reason, or the class of the exception and its message (the flags and the editor must agree). */
    private static function normalized(Closure $normalize): string
    {
        try {
            return 'ok:' . $normalize();
        } catch (InvalidRuleChangeReasonException | InvalidFeatureFlagReasonException $e) {
            return 'refused:' . $e->getMessage();
        }
    }

    // =====================================================================
    // D5 / RN-06: the canonical photo
    // =====================================================================

    private function testSnapshotIsCanonicalAndStable(): void
    {
        $revision = (new RuleCatalogEditor(new InMemoryRuleCatalogStore(), $this->clock()))->create(self::golden(), self::REASON);
        $this->assertEquals(self::GOLDEN_SNAPSHOT, $revision->snapshot, 'a foto canônica é contrato (D5)');
        $this->assertEquals(self::GOLDEN_HASH, $revision->snapshotHash, 'o hash da foto é contrato (D5)');
        $this->assertEquals('sha256:' . hash('sha256', self::GOLDEN_SNAPSHOT), $revision->snapshotHash, 'sha256 do texto');
        $this->assertEquals(self::oracleSnapshot(self::oracleRow(self::golden())), $revision->snapshot, 'a foto do oráculo do teste');
        $this->assertEquals(71, strlen($revision->snapshotHash), 'cabe em VARCHAR(71)');

        // The same row in the forms of each database gives the same photo
        $forms = [
            'texto do PostgreSQL' => ['decimal_value' => '12.5000', 'active' => 'Y', 'parameters' => '{"b":"c/d","a":{"y":[3,1],"x":1.0}}', 'int_value' => '5000', 'priority' => '3', 'version' => '1', 'valid_from' => '2026-01-01', 'violation_action' => 'warn'],
            'números do SQLite' => ['decimal_value' => 12.5, 'active' => 'Y ', 'parameters' => '{"a":{"x":1.0,"y":[3,1]},"b":"c\/d"}', 'int_value' => 5000, 'priority' => 3, 'version' => 1, 'valid_from' => '2026-01-01 00:00:00', 'violation_action' => ' WARN'],
            'memória' => ['decimal_value' => '12.5', 'active' => true, 'parameters' => ['b' => 'c/d', 'a' => ['y' => [3, 1], 'x' => 1.0]], 'int_value' => 5000, 'priority' => 3, 'version' => 1, 'valid_from' => new DateTimeImmutable('2026-01-01'), 'violation_action' => RuleAction::WARN],
        ];
        $stored = self::stored(self::oracleRow(self::golden()));
        foreach ($forms as $label => $form) {
            $this->assertEquals(self::GOLDEN_SNAPSHOT, RuleSnapshot::encode(RuleSnapshot::fromStored($form + $stored)), "mesma foto: {$label}");
        }
        $decimals = ['12.5000' => '12.5', '-0.0000' => '0', '.5' => '0.5', '-.5' => '-0.5', '1000' => '1000', '0.0001' => '0.0001'];
        foreach ($decimals as $raw => $canonical) {
            $this->assertEquals($canonical, RuleSnapshot::fromStored(['decimal_value' => $raw] + $stored)['decimal_value'], "decimal {$raw}");
        }
        $this->assertEquals('0.1', RuleSnapshot::canonicalDecimal(0.1), 'float de 15 dígitos');
        $this->assertEquals('5', RuleSnapshot::canonicalDecimal(5), 'inteiro');
        $this->assertTrue(RuleSnapshot::canonicalDecimal(null) === null, 'nulo');
        $this->assertTrue(RuleSnapshot::fromStored(['parameters' => '  ', 'spec_json' => ' '] + $stored)['parameters'] === null, 'parâmetros em branco = nulo');
        $this->assertTrue(RuleSnapshot::fromStored(['parameters' => '{}'] + $stored)['parameters'] === null, 'objeto vazio = nulo');
        $this->assertTrue(RuleSnapshot::fromStored(['spec_json' => ' '] + $stored)['spec_json'] === null, 'spec_json em branco = sem corpo');
        $this->assertEquals('block', RuleSnapshot::fromStored(['violation_action' => null] + $stored)['violation_action'], 'ação nula = block, como o catálogo');
        $this->assertEquals(0, RuleSnapshot::fromStored(['priority' => null] + $stored)['priority'], 'prioridade nula = 0');
        $this->assertEquals(['a' => [3, 1], 'b' => ['c' => 1, 'd' => 2]], RuleSnapshot::canonicalParameters(['b' => ['d' => 2, 'c' => 1], 'a' => [3, 1]]), 'objetos ordenados, listas mantidas');
        $this->assertTrue(RuleSnapshot::canonicalParameters([]) === null, 'sem parâmetros');
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, '{"b":1}');
        rewind($stream);
        $this->assertEquals(['b' => 1], RuleSnapshot::fromStored(['parameters' => $stream] + $stored)['parameters'], 'LOB lido como texto');
        $this->assertEquals(RuleSnapshot::COLUMNS, array_keys(RuleSnapshot::fromStored(['code' => 'x', 'name' => 'n', 'rule_type' => 't', 'version' => 1, 'active' => 'N'])), 'colunas em ordem');
        $sorted = RuleSnapshot::COLUMNS;
        sort($sorted, SORT_STRING);
        $this->assertEquals($sorted, RuleSnapshot::COLUMNS, 'ordem alfabética');

        // Unreadable stored values: the rule and the column, never the value
        $unreadable = [
            'active' => self::SECRET, 'int_value' => self::SECRET, 'priority' => 1.5, 'version' => 0, 'decimal_value' => self::SECRET,
            'parameters' => '[1,2]', 'valid_until' => self::SECRET, 'violation_action' => self::SECRET,
        ];
        foreach ($unreadable as $column => $value) {
            $e = $this->assertThrows(RuleCatalogStoreException::class, fn() => RuleSnapshot::fromStored([$column => $value] + $stored), "valor ilegível em {$column}");
            $this->assertTrue(str_contains($e->getMessage(), $column) && $e->getRuleCode() === 'golden', "a mensagem cita a coluna {$column}");
            $this->messages[] = $e->getMessage();
        }
        $this->messages[] = $this->assertThrows(RuleCatalogStoreException::class, fn() => RuleSnapshot::fromStored(['parameters' => '{' . self::SECRET] + $stored), 'JSON inválido')->getMessage();
        $this->assertThrows(RuleCatalogStoreException::class, fn() => RuleSnapshot::fromStored(['version' => null] + $stored), 'sem versão');
        $this->assertThrows(RuleCatalogStoreException::class, fn() => RuleSnapshot::fromStored(['code' => ''] + $stored), 'sem código');
        $this->assertThrows(RuleCatalogStoreException::class, fn() => RuleSnapshot::encode(['code' => 'x']), 'foto sem as colunas');
        $this->messages[] = $this->assertThrows(RuleCatalogStoreException::class, fn() => RuleSnapshot::encode(['name' => "\xB1" . self::SECRET] + RuleSnapshot::fromStored($stored)), 'texto que não é UTF-8')->getMessage();
        $this->assertThrows(RuleCatalogStoreException::class, fn() => RuleSnapshot::decode('{"code":"x"}'), 'foto sem as colunas em ordem');
        $this->assertThrows(RuleCatalogStoreException::class, fn() => RuleSnapshot::decode('not json'), 'foto que não é JSON');
        $this->assertEquals(RuleSnapshot::fromStored($stored), RuleSnapshot::decode(self::GOLDEN_SNAPSHOT), 'decode() é o inverso de encode()');
    }

    // =====================================================================
    // RuleRevision::isIntact() and definition()
    // =====================================================================

    private function testRevisionIntegrity(): void
    {
        $revision = (new RuleCatalogEditor(new InMemoryRuleCatalogStore(), $this->clock()))->create(self::golden(), self::REASON, 'ana');
        $this->assertTrue($revision->isIntact(), 'revisão íntegra');
        $copy = static fn(array $changes): RuleRevision => new RuleRevision(
            $changes['code'] ?? $revision->code,
            $changes['version'] ?? $revision->version,
            $revision->kind,
            $changes['snapshot'] ?? $revision->snapshot,
            $changes['hash'] ?? $revision->snapshotHash,
            [],
            $revision->reason,
            $revision->origin,
            $revision->changedAt,
            $revision->changedBy
        );
        $tampered = str_replace('"int_value":5000', '"int_value":9000', $revision->snapshot);
        $this->assertFalse($copy(['snapshot' => $tampered])->isIntact(), 'foto editada à mão');
        $this->assertTrue($copy(['snapshot' => $tampered, 'hash' => RuleSnapshot::hash($tampered)])->isIntact(), 'foto e hash editados juntos ainda descrevem a mesma regra e versão');
        $this->assertFalse($copy(['hash' => 'sha256:' . str_repeat('0', 64)])->isIntact(), 'hash editado');
        $this->assertFalse($copy(['code' => 'other'])->isIntact(), 'foto de outra regra');
        $this->assertFalse($copy(['version' => 2])->isIntact(), 'foto de outra versão');
        $this->assertFalse($copy(['snapshot' => 'x', 'hash' => RuleSnapshot::hash('x')])->isIntact(), 'foto que não é foto');
        $definition = $revision->definition();
        $this->assertTrue($definition instanceof IVersionedRuleDefinition && $definition->getVersion() === 1, 'definição com a versão');
        $this->assertEquals(['a' => ['x' => 1.0, 'y' => [3, 1]], 'b' => 'c/d', 'product_code' => 'P1', 'plan_code' => 'G1', 'valid_from' => '2026-01-01', 'valid_until' => '2026-12-31'], $definition->getParameters(), 'parâmetros como o catálogo os entrega');
        $this->assertEquals([12.5, 5000, RuleAction::WARN, 3], [$definition->getDecimalValue(), $definition->getIntValue(), $definition->getViolationAction(), $definition->getPriority()], 'tipos da definição');
        $this->assertEquals(
            ['a' => 1, 'product_code' => 'P1', 'plan_code' => 'G1', 'valid_from' => '2026-01-01', 'valid_until' => '2026-12-31'],
            RuleSnapshot::definition(['parameters' => ['a' => 1, 'product_code' => 'X', 'codigo_plano' => 'Y']] + RuleSnapshot::fromStored(self::stored(self::oracleRow(self::golden()))))->getParameters(),
            'chaves de aplicabilidade nos parâmetros descartadas, como o catálogo'
        );
    }

    // =====================================================================
    // D9: the version of a definition
    // =====================================================================

    private function testVersionedDefinition(): void
    {
        $this->assertTrue(RuleDefinition::fromArray(['code' => 'a', 'name' => 'A', 'rule_type' => 't'])->getVersion() === null, 'sem versão');
        $this->assertTrue(RuleDefinition::fromArray(['code' => 'a', 'name' => 'A', 'rule_type' => 't', 'version' => ''])->getVersion() === null, 'versão vazia');
        $this->assertEquals(4, RuleDefinition::fromArray(['code' => 'a', 'name' => 'A', 'rule_type' => 't', 'version' => '4'])->getVersion(), 'versão lida do texto');
        $this->assertEquals(5, (new RuleDefinition('a', 'A', 't', version: 5))->getVersion(), 'construtor');
        $this->assertTrue(new RuleDefinition('a', 'A', 't') instanceof IVersionedRuleDefinition, 'RuleDefinition implementa a interface');
    }

    // =====================================================================
    // D10: the version in the verdict and the rules compiled
    // =====================================================================

    private function testEngineStampsTheVersionOnlyWhenThereIsOne(): void
    {
        $plain = new RuleDefinition('plain', 'Plain', 'max', legalBasis: 'Art. 1', intValue: 10, scope: 'contract');
        $versioned = new RuleDefinition('versioned', 'Versioned', 'max', legalBasis: 'Art. 2', intValue: 10, scope: 'contract', version: 3);
        $raw = static fn($rule): ISpecification => Spec::property('rent', Spec::lessThanOrEqualTo($rule->getIntValue()));
        $expected = ['action' => 'block', 'rule_code' => 'plain', 'rule_type' => 'max', 'rule_name' => 'Plain', 'priority' => 0, 'original_message' => null, 'legal_basis' => 'Art. 1'];

        $failure = (new RuleBoundSpecification($raw($plain), $plain))->evaluate(['rent' => 20])->failures[0];
        $this->assertFalse(array_key_exists('rule_version', $failure->metadata), 'sem versão, sem rule_version (RN-01)');
        $this->assertEquals(array_keys($expected + ['original_messages' => 1, 'original_failures' => 1]), array_slice(array_keys($failure->metadata), 0, 9), 'chaves da 2.12.0, na ordem da 2.12.0');
        $versionedFailure = (new RuleBoundSpecification($raw($versioned), $versioned))->evaluate(['rent' => 20])->failures[0];
        $this->assertEquals(3, $versionedFailure->metadata['rule_version'], 'rule_version na falha reportada uma vez');
        $this->assertEquals(['action', 'rule_code', 'rule_type', 'rule_name', 'priority', 'original_message', 'legal_basis', 'rule_version'], array_slice(array_keys($versionedFailure->metadata), 0, 8), 'rule_version depois das chaves da regra');
        $coded = (new RuleBoundSpecification(Spec::property('rent', Spec::lessThanOrEqualTo(10))->withCode('RENT'), $versioned))->evaluate(['rent' => 20])->failures[0];
        $this->assertEquals([3, 'RENT'], [$coded->metadata['rule_version'], $coded->code], 'falha customizada carimbada');
        $throwing = new CountingSpecification(static fn(): bool => throw new RuntimeException('boom'));
        $error = (new RuleBoundSpecification($throwing, $versioned))->evaluate(['rent' => 1])->failures[0];
        $this->assertEquals(3, $error->metadata['rule_version'], 'erro de avaliação carimbado');
        $this->assertTrue((new RuleBoundSpecification(Spec::alwaysTrue(), $versioned))->evaluate([])->isSatisfied, 'satisfeita não muda');
        $noVersionInterface = new RuleBoundSpecification($raw($plain), new \Antevemus\ASpecification\Tests\Support\Module47PlainRule());
        $this->assertFalse(array_key_exists('rule_version', $noVersionInterface->evaluate(['rent' => 20])->failures[0]->metadata), 'IRuleDefinition sem a interface nova');

        $lookup = new RuleDefinition('lookup', 'Lookup', 'table_value', scope: 'contract', version: 9);
        $passes = new RuleDefinition('passes', 'Passes', 'max', intValue: 100, scope: 'contract', version: 2);
        $registry = (new RuleSpecificationRegistry())->registerClosure('max', $raw)->registerLookupOnly('table_value');
        $engine = new DynamicSpecificationEngine(new InMemoryRuleCatalog([$versioned, $passes, $lookup, $plain]), $registry);
        $this->assertTrue($engine->getCompiledRules() === [], 'vazio antes da primeira compilação');
        $verdict = $engine->validate(['rent' => 50], 'contract');
        $this->assertEquals(['versioned@3', 'passes@2', 'plain@-'], array_map(static fn($r): string => $r->getCode() . '@' . ($r->getVersion() ?? '-'), $engine->getCompiledRules()), 'regras compiladas, satisfeitas incluídas, na ordem do catálogo');
        $this->assertEquals(['lookup'], array_map(static fn($r): string => $r->getCode(), $engine->getSkippedRules()), 'a de consulta fica em getSkippedRules()');
        $this->assertEquals([3], array_values(array_filter(array_map(static fn($f) => $f->metadata['rule_version'] ?? null, $verdict->getAllFailures()))), 'só a regra versionada carimba a versão');
        $engine->compileSpecification('claim');
        $this->assertTrue($engine->getCompiledRules() === [], 'esvaziada a cada compilação');
        $engine->compileSpecification('contract');
        $this->assertCount(3, $engine->getCompiledRules());
        $this->assertTrue($engine->withCatalogValidation()->getCompiledRules() === [], 'a cópia começa vazia');
    }

    // =====================================================================
    // D9: the cached catalog keeps the version
    // =====================================================================

    private function testCachedCatalogCarriesTheVersion(): void
    {
        $cache = new ArrayCache();
        $catalog = CachedRuleCatalog::decorate(new InMemoryRuleCatalog([
            new RuleDefinition('plain', 'Plain', 'max', scope: 'contract'),
            new RuleDefinition('versioned', 'Versioned', 'max', scope: 'contract', version: 4),
        ]), $cache, 3600);
        $catalog->findRules('contract');
        $hit = $catalog->findRules('contract');
        $this->assertEquals(['plain@-', 'versioned@4'], array_map(static fn($r): string => $r->getCode() . '@' . ($r->getVersion() ?? '-'), $hit), 'acerto do cache com a versão');
        $this->assertEquals(4, $catalog->findRuleByCode('versioned')?->getVersion(), 'findRuleByCode() pelo cache');
        $this->assertEquals(4, $catalog->findRuleByCode('versioned')?->getVersion(), 'acerto de findRuleByCode()');
        $entries = array_values(array_filter(array_map(static fn(string $k) => $cache->get($k), $cache->keys()), static fn($v): bool => is_array($v) && isset($v['rules'])));
        $this->assertFalse(array_key_exists('version', $entries[0]['rules'][0]), 'a entrada de uma regra sem versão é a da 2.12.0');
        $this->assertEquals(4, $entries[0]['rules'][1]['version'], 'a versão gravada');
        foreach ($cache->keys() as $key) {
            $value = $cache->get($key);
            if (is_array($value) && isset($value['rules'])) {
                $value['rules'][1]['version'] = 'four';
                $cache->plant($key, $value);
            }
        }
        $this->assertEquals(4, $catalog->findRules('contract')[1]->getVersion(), 'versão de tipo errado = falta no cache, a regra vem do catálogo');
    }

    // =====================================================================
    // The contract battery: memory and every database
    // =====================================================================

    /**
     * @param Closure(): array{store: IRuleCatalogStore, seed: Closure, tamper: Closure, bump: Closure, delete: Closure, pdo: ?PDO} $fresh
     */
    private function runContract(string $label, Closure $fresh): void
    {
        $this->contractCreateUpdateSetActive($label, $fresh());
        $this->contractBaselineAndAdopt($label, $fresh());
        $this->contractDrift($label, $fresh());
        $this->contractGuard($label, $fresh());
        $this->contractRollback($label, $fresh());
        $this->contractHistoryOrder($label, $fresh());
        $this->contractStoreDirect($label, $fresh());
    }

    private function contractCreateUpdateSetActive(string $label, array $env): void
    {
        $editor = new RuleCatalogEditor($env['store'], $this->clock());
        $v1 = $editor->create(self::golden() + ['spec_json' => '{"v":1,"spec":{"t":"isNull"}}'], "  Approved \n by the board ", 42);
        $this->assertEquals([1, RuleChangeKind::Create, [], 'Approved by the board', 'ui', '42'], [$v1->version, $v1->kind, $v1->changedFields, $v1->reason, $v1->origin, $v1->changedBy], "{$label}: create");
        $this->assertTrue($v1->isIntact() && $v1->changeId !== null && strlen($v1->changeId) === 26, "{$label}: íntegra, com ULID");
        $this->assertEquals('{"v":1,"spec":{"t":"isNull"}}', $v1->row()['spec_json'], "{$label}: spec_json na foto");
        $this->assertEquals($v1->snapshot, RuleSnapshot::encode($env['store']->findRow('golden')), "{$label}: a linha é a foto");
        $this->assertEquals(self::revisionKey($v1), self::revisionKey($editor->revision('golden', 1)), "{$label}: revision(1) relida igual");
        $this->assertEquals(self::revisionKey($v1), self::revisionKey($editor->current('golden')), "{$label}: current()");
        $this->assertTrue($editor->revision('golden', 2) === null && $editor->current('none') === null, "{$label}: sem versão 2, sem regra");
        $stale = $this->assertThrows(StaleRuleChangeException::class, fn() => $editor->create(self::golden(), self::REASON), "{$label}: create repetido");
        $this->assertEquals('golden', $stale->getRuleCode(), "{$label}: código na exceção");

        $v2 = $editor->update('golden', ['name' => 'Golden II', 'int_value' => 6000, 'description' => 'Teto do aluguel / ç'], self::REASON, 'bia', RuleCatalogEditor::ORIGIN_SCRIPT, 1);
        $this->assertEquals([2, RuleChangeKind::Update, ['int_value', 'name'], 'script', 'bia'], [$v2->version, $v2->kind, $v2->changedFields, $v2->origin, $v2->changedBy], "{$label}: update com colunas mudadas em ordem, a igual fora");
        $this->assertEquals(5000, $editor->revision('golden', 1)->definition()->getIntValue(), "{$label}: a versão 1 continua como era");
        $this->assertEquals(6000, $editor->revision('golden', 2)->definition()->getIntValue(), "{$label}: versão 2");
        $stale = $this->assertThrows(StaleRuleChangeException::class, fn() => $editor->update('golden', ['int_value' => 7000], self::REASON, null, 'ui', 1), "{$label}: versão esperada velha");
        $this->assertEquals('Rule "golden" changed since it was read (version 1, now 2): reload and try again.', $stale->getMessage(), "{$label}: mensagem da versão");
        $this->assertThrows(StaleRuleChangeException::class, fn() => $editor->update('golden', ['int_value' => 7000], self::REASON, null, 'ui', 3), "{$label}: versão esperada à frente");
        $noOp = $this->assertThrows(StaleRuleChangeException::class, fn() => $editor->update('golden', ['int_value' => 6000, 'name' => 'Golden II'], self::REASON), "{$label}: mudança sem efeito");
        $this->assertEquals('The change leaves rule "golden" as it is (version 2): nothing to record.', $noOp->getMessage(), "{$label}: mensagem sem efeito");
        $this->assertThrows(StaleRuleChangeException::class, fn() => $editor->update('golden', [], self::REASON), "{$label}: nada a mudar");
        $this->assertThrows(StaleRuleChangeException::class, fn() => $editor->update('golden', ['decimal_value' => '12.50'], self::REASON), "{$label}: decimal igual em outra forma");
        $this->assertThrows(StaleRuleChangeException::class, fn() => $editor->update('golden', ['parameters' => ['b' => 'c/d', 'a' => ['y' => [3, 1], 'x' => 1.0]]], self::REASON), "{$label}: parâmetros iguais em outra ordem");
        $this->assertThrows(InvalidArgumentException::class, fn() => $editor->update('golden', ['product_code' => null], self::REASON), "{$label}: plano sem produto depois do merge");
        $this->assertEquals(2, $editor->current('golden')->version, "{$label}: nada gravado pelas recusas");

        $v3 = $editor->setActive('golden', false, self::REASON, null, 'ui', 2);
        $this->assertEquals([3, RuleChangeKind::Deactivate, ['active']], [$v3->version, $v3->kind, $v3->changedFields], "{$label}: desativar");
        $this->assertEquals('Rule "golden" is already inactive (version 3): reload and try again.', $this->assertThrows(StaleRuleChangeException::class, fn() => $editor->setActive('golden', false, self::REASON), "{$label}: já inativa")->getMessage());
        $v4 = $editor->setActive('golden', true, self::REASON);
        $this->assertEquals([4, RuleChangeKind::Activate], [$v4->version, $v4->kind], "{$label}: ativar");
        $this->assertEquals('Rule "golden" is already active (version 4): reload and try again.', $this->assertThrows(StaleRuleChangeException::class, fn() => $editor->setActive('golden', true, self::REASON))->getMessage());
        $this->assertThrows(StaleRuleChangeException::class, fn() => $editor->setActive('golden', false, self::REASON, null, 'ui', 3), "{$label}: setActive com versão velha");

        $notFound = $this->assertThrows(RuleNotFoundException::class, fn() => $editor->update('missing', ['name' => 'x'], self::REASON), "{$label}: update sem regra");
        $this->assertEquals('There is no rule "missing" in the catalog (active or not).', $notFound->getMessage());
        $this->assertThrows(RuleNotFoundException::class, fn() => $editor->setActive('missing', true, self::REASON), "{$label}: setActive sem regra");
        $this->assertThrows(RuleNotFoundException::class, fn() => $editor->adopt('missing', self::REASON), "{$label}: adopt sem regra");
        $this->assertEquals([4, 3, 2, 1], array_map(static fn(RuleRevision $r): int => $r->version, $editor->history('golden')), "{$label}: histórico do mais recente ao mais antigo");
        $this->assertEquals([4, 3], array_map(static fn(RuleRevision $r): int => $r->version, $editor->history('golden', 2)), "{$label}: limite");
        $this->assertThrows(InvalidArgumentException::class, fn() => $editor->history(null, 0), "{$label}: limite abaixo de 1");
        $this->assertTrue($editor->findDrift() === [], "{$label}: sem deriva");
        $this->assertTrue($editor->current('golden')->changedAt == $v4->changedAt && $editor->current('golden')->changedAt->format('u') !== '000000', "{$label}: instante com microssegundos relido igual");
        if ($env['pdo'] !== null) {
            $catalog = new PdoRuleCatalog($env['pdo'], columns: [...PdoRuleCatalog::COLUMNS_SINCE_2_4, ...PdoRuleCatalog::COLUMNS_SINCE_2_13]);
            $this->assertTrue($catalog->findRuleByCode('golden') == $v4->definition(), "{$label}: a revisão é a regra que o catálogo lê");
            $this->assertEquals(4, $catalog->findRules('contract', null, ['product_code' => 'P1', 'plan_code' => 'G1'])[0]->getVersion(), "{$label}: findRules() com a versão");
            $this->assertTrue((new PdoRuleCatalog($env['pdo']))->findRuleByCode('golden')?->getVersion() === null, "{$label}: sem o mapeamento a versão não é lida (RN-01)");
        }
    }

    private function contractBaselineAndAdopt(string $label, array $env): void
    {
        $editor = new RuleCatalogEditor($env['store'], $this->clock());
        $seeded = self::oracleRow(['code' => 'seeded', 'name' => 'Seeded', 'rule_type' => 'max_rent', 'int_value' => 100, 'parameters' => ['b' => 2, 'a' => 1]]);
        ($env['seed'])(self::stored($seeded));
        ($env['seed'])(self::stored(self::oracleRow(['code' => 'other', 'name' => 'Other', 'rule_type' => 'max_rent'])));
        ($env['seed'])(self::stored(self::oracleRow(['code' => 'third', 'name' => 'Third', 'rule_type' => 'max_rent', 'active' => false])));
        $this->assertEquals(['other:no_history:1:-', 'seeded:no_history:1:-', 'third:no_history:1:-'], self::driftKeys($editor->findDrift()), "{$label}: regras de antes da migração sem histórico");
        $this->assertEquals(['seeded:no_history:1:-'], self::driftKeys($editor->findDrift('seeded')), "{$label}: deriva de uma regra");
        $this->assertTrue($editor->current('seeded') === null, "{$label}: current() sem histórico");

        $v2 = $editor->update('seeded', ['int_value' => 200], self::REASON, 'ana', 'ui', 1);
        $history = $editor->history('seeded');
        $this->assertEquals(['2:update', '1:baseline'], array_map(static fn(RuleRevision $r): string => $r->version . ':' . $r->kind->value, $history), "{$label}: linha de base antes da mudança (D6)");
        $this->assertEquals(self::oracleSnapshot($seeded), $history[1]->snapshot, "{$label}: a linha de base é a foto da versão atual");
        $this->assertEquals([self::REASON, 'ui', 'ana', []], [$history[1]->reason, $history[1]->origin, $history[1]->changedBy, $history[1]->changedFields], "{$label}: mesmo motivo, origem e autor");
        $this->assertTrue($history[1]->changedAt == $v2->changedAt && strcmp((string) $history[1]->changeId, (string) $v2->changeId) < 0, "{$label}: mesma transação, chave anterior");

        $adopted = $editor->adopt(null, self::REASON);
        $this->assertEquals(['other:1:baseline', 'third:1:baseline'], array_map(static fn(RuleRevision $r): string => $r->code . ':' . $r->version . ':' . $r->kind->value, $adopted), "{$label}: adopt() de todas, ativas ou não, nada mais");
        $this->assertEquals('migration', $adopted[0]->origin, "{$label}: origem padrão de adopt()");
        $this->assertTrue($editor->adopt(null, self::REASON) === [] && $editor->adopt('other', self::REASON) === [], "{$label}: adopt() repetido não grava");
        $this->assertTrue($editor->findDrift() === [], "{$label}: tudo adotado");
        $this->assertTrue(RuleSnapshot::encode($env['store']->findRow('other')) === $editor->current('other')->snapshot, "{$label}: adopt() não mudou a linha");
    }

    private function contractDrift(string $label, array $env): void
    {
        $editor = new RuleCatalogEditor($env['store'], $this->clock());
        $editor->create(['code' => 'a', 'name' => 'A', 'rule_type' => 'max_rent', 'int_value' => 1], self::REASON);
        $editor->create(['code' => 'b', 'name' => 'B', 'rule_type' => 'max_rent'], self::REASON);
        $editor->create(['code' => 'c', 'name' => 'C', 'rule_type' => 'max_rent'], self::REASON);

        // VersionAhead: the version raised by hand; adopt() records the baseline of the new version
        ($env['bump'])('a');
        $this->assertEquals(['a:version_ahead:2:1'], self::driftKeys($editor->findDrift()), "{$label}: versão à frente");
        $this->assertEquals(['a:2:baseline'], array_map(static fn(RuleRevision $r): string => $r->code . ':' . $r->version . ':' . $r->kind->value, $editor->adopt('a', self::REASON)), "{$label}: adopt() da versão à frente");
        ($env['bump'])('a');
        $v4 = $editor->update('a', ['int_value' => 2], self::REASON);
        $this->assertEquals(['4:update', '3:baseline', '2:baseline', '1:create'], array_map(static fn(RuleRevision $r): string => $r->version . ':' . $r->kind->value, $editor->history('a')), "{$label}: a mudança grava a linha de base da versão à frente");
        $this->assertEquals(4, $v4->version);

        // Modified: an UPDATE by hand at the same version is refused, and nothing is written
        ($env['tamper'])('b', 'name', self::SECRET);
        $this->assertEquals(['b:modified:1:1'], self::driftKeys($editor->findDrift()), "{$label}: linha mudada à mão");
        $drift = $this->assertThrows(RuleCatalogDriftException::class, fn() => $editor->update('b', ['int_value' => 5], self::REASON), "{$label}: mudança sobre linha derivada");
        $this->assertTrue($drift->getDrift() == new RuleDrift('b', RuleDriftKind::Modified, 1, 1) && $drift->getRuleCode() === 'b', "{$label}: a deriva na exceção");
        $this->messages[] = $drift->getMessage();
        $this->assertThrows(RuleCatalogDriftException::class, fn() => $editor->setActive('b', false, self::REASON), "{$label}: setActive sobre linha derivada");
        $this->assertTrue($editor->adopt('b', self::REASON) === [], "{$label}: adopt() não cobre a linha mudada à mão");
        $this->assertCount(1, $editor->history('b'), "{$label}: nada gravado");
        ($env['bump'])('b');
        $this->assertEquals(['b:version_ahead:2:1'], self::driftKeys($editor->findDrift('b')), "{$label}: subir a versão à mão registra a mudança");
        $this->assertEquals(['3:update', '2:baseline', '1:create'], array_map(static fn(RuleRevision $r): string => $r->version . ':' . $r->kind->value, [$editor->update('b', ['int_value' => 5], self::REASON), ...array_slice($editor->history('b'), 1)]), "{$label}: e a próxima mudança grava sua linha de base");
        $this->assertTrue(str_contains($editor->revision('b', 2)->snapshot, self::SECRET), "{$label}: a linha de base guarda o que foi feito à mão");

        // A version lowered by hand is Modified too
        $editor->update('c', ['int_value' => 1], self::REASON);
        ($env['tamper'])('c', 'version', 1);
        $this->assertEquals(['c:modified:1:2'], self::driftKeys($editor->findDrift('c')), "{$label}: versão abaixada à mão");

        // Deleted: the history without a row; creating it again is refused
        ($env['delete'])('a');
        $this->assertEquals(['a:deleted:-:4', 'c:modified:1:2'], self::driftKeys($editor->findDrift()), "{$label}: linha apagada à mão");
        $this->assertEquals(['a:deleted:-:4'], self::driftKeys($editor->findDrift('a')), "{$label}: apagada, por código");
        $deleted = $this->assertThrows(RuleCatalogDriftException::class, fn() => $editor->create(['code' => 'a', 'name' => 'A', 'rule_type' => 'max_rent'], self::REASON), "{$label}: recriar com o mesmo código");
        $this->assertEquals(RuleDriftKind::Deleted, $deleted->getDrift()->kind);
        $this->assertTrue($editor->findDrift('nothing') === [], "{$label}: código desconhecido");
        $this->assertTrue(RuleDriftKind::NoHistory->needsBaseline() && RuleDriftKind::VersionAhead->needsBaseline() && !RuleDriftKind::Modified->needsBaseline() && !RuleDriftKind::Deleted->needsBaseline(), 'needsBaseline()');
    }

    private function contractGuard(string $label, array $env): void
    {
        ($env['seed'])(self::stored(self::oracleRow(['code' => 'profile', 'name' => 'Profile', 'rule_type' => 'profile', 'active' => false])));
        $seen = [];
        $guard = new CountingSpecification(static function (mixed $candidate) use (&$seen): bool {
            $seen[] = $candidate;

            return !($candidate instanceof RuleChangeCandidate) || $candidate->kind !== RuleChangeKind::Activate || $candidate->toVersion >= 3;
        });
        $plain = new RuleCatalogEditor($env['store'], $this->clock());
        $editor = $plain->withChangeGuard($guard->withCode('LEGAL_OPINION_REQUIRED')->because('No favourable opinion for this version'));
        $this->assertTrue($plain->getChangeGuard() === null && $editor->getChangeGuard() !== null, "{$label}: withChangeGuard() é imutável");

        $refused = $this->assertThrows(RuleChangeRefusedException::class, fn() => $editor->setActive('profile', true, self::REASON, 'ana', 'ui'), "{$label}: guarda recusa");
        $this->assertEquals('The change of rule "profile" (version 1 to 2) was refused by the change guard: LEGAL_OPINION_REQUIRED.', $refused->getMessage(), "{$label}: mensagem com os códigos");
        $this->assertEquals(['LEGAL_OPINION_REQUIRED'], $refused->getResult()->getCodes(), "{$label}: o resultado da guarda");
        $candidate = $refused->getCandidate();
        $this->assertEquals([RuleChangeKind::Activate, 'profile', 1, 2, ['active'], 'ana', 'ui', self::REASON, false, true, 1, 2], [$candidate->kind, $candidate->code, $candidate->fromVersion, $candidate->toVersion, $candidate->changedFields, $candidate->actor, $candidate->origin, $candidate->reason, $candidate->before?->isActive(), $candidate->after->isActive(), $candidate->before?->getVersion(), $candidate->after->getVersion()], "{$label}: o candidato");
        $this->assertTrue($editor->history('profile') === [] && $env['store']->findRow('profile')['version'] === 1, "{$label}: recusada não grava nada, nem linha de base (RN-02)");
        $this->assertEquals(['profile:no_history:1:-'], self::driftKeys($editor->findDrift('profile')), "{$label}: continua sem histórico");

        $this->assertEquals(2, $editor->update('profile', ['description' => 'Reviewed'], self::REASON)->version, "{$label}: guarda satisfeita grava");
        $this->assertEquals(RuleChangeKind::Update, end($seen)->kind);
        $this->assertEquals(3, $editor->setActive('profile', true, self::REASON)->version, "{$label}: ativa na versão com parecer");
        $created = $editor->create(['code' => 'fresh', 'name' => 'Fresh', 'rule_type' => 'profile'], self::REASON);
        $this->assertTrue(end($seen)->before === null && end($seen)->fromVersion === null && end($seen)->toVersion === 1 && end($seen)->changedFields === [] && $created->version === 1, "{$label}: candidato de create");

        $throwing = $plain->withChangeGuard(new CountingSpecification(static fn(): bool => throw new RuntimeException(self::SECRET)));
        $error = $this->assertThrows(RuleChangeRefusedException::class, fn() => $throwing->update('profile', ['description' => 'Again'], self::REASON), "{$label}: guarda que lança");
        $this->assertEquals('The change of rule "profile" (version 3 to 4) was refused: the change guard could not be evaluated.', $error->getMessage(), "{$label}: mensagem do erro da guarda");
        $this->assertTrue($error->getPrevious() instanceof RuntimeException && $error->getResult()->isError, "{$label}: a exceção da avaliação é a anterior");
        $this->assertEquals(3, $env['store']->findRow('profile')['version'], "{$label}: nada gravado");
        $errorResult = $plain->withChangeGuard(new class () extends \Antevemus\ASpecification\AbstractSpecification {
            public function getType(): string
            {
                return 'mixed';
            }

            public function isSatisfiedBy(mixed $candidate): bool
            {
                return true;
            }

            public function evaluate(mixed $candidate): SpecificationResult
            {
                return SpecificationResult::error(new RuntimeException('inner'), 'Guard');
            }
        });
        $resultError = $this->assertThrows(RuleChangeRefusedException::class, fn() => $errorResult->setActive('profile', false, self::REASON), "{$label}: resultado de erro");
        $this->assertTrue(str_contains($resultError->getMessage(), 'could not be evaluated') && $resultError->getPrevious()?->getMessage() === 'inner', "{$label}: resultado de erro recusa com a anterior");
        $uncoded = $plain->withChangeGuard(Spec::alwaysFalse());
        $this->assertEquals('The change of rule "fresh" (version 1 to 2) was refused by the change guard.', $this->assertThrows(RuleChangeRefusedException::class, fn() => $uncoded->update('fresh', ['name' => 'F'], self::REASON))->getMessage(), "{$label}: guarda sem código");
        $this->messages[] = $error->getMessage();
    }

    private function contractRollback(string $label, array $env): void
    {
        ($env['seed'])(self::stored(self::oracleRow(['code' => 'old', 'name' => 'Old', 'rule_type' => 'max_rent'])));
        $failing = new FailingRuleCatalogStore($env['store']);
        $editor = new RuleCatalogEditor($failing, $this->clock());
        $this->assertThrows(RuntimeException::class, fn() => $editor->update('old', ['int_value' => 9], self::REASON), "{$label}: falha depois da linha e da linha de base");
        $this->assertEquals([1, null], [$env['store']->findRow('old')['version'], $env['store']->findRow('old')['int_value']], "{$label}: a linha voltou (RN-02)");
        $this->assertTrue($env['store']->history('old') === [], "{$label}: nem a linha de base ficou (RN-02)");
        $this->assertThrows(RuntimeException::class, fn() => $editor->create(['code' => 'new', 'name' => 'New', 'rule_type' => 'max_rent'], self::REASON), "{$label}: falha no create");
        $this->assertTrue($env['store']->findRow('new') === null, "{$label}: a regra criada voltou");
        $failing->armed = false;
        $this->assertEquals(2, $editor->update('old', ['int_value' => 9], self::REASON)->version, "{$label}: sem a falha, grava");
        $failing->vanishAfterNextChange();
        $this->assertEquals('Rule "old" disappeared after its change.', $this->assertThrows(RuleCatalogStoreException::class, fn() => $editor->update('old', ['int_value' => 10], self::REASON))->getMessage(), "{$label}: a linha sumiu depois do compare-and-set");
        $this->assertEquals(2, $env['store']->findRow('old')['version'], "{$label}: e nada ficou");
    }

    private function contractHistoryOrder(string $label, array $env): void
    {
        $editor = new RuleCatalogEditor($env['store'], $this->clock());
        $editor->create(['code' => 'x', 'name' => 'X', 'rule_type' => 't'], self::REASON);
        $editor->create(['code' => 'y', 'name' => 'Y', 'rule_type' => 't'], self::REASON);
        $editor->update('x', ['name' => 'X2'], self::REASON);
        $this->assertEquals(['x:2', 'y:1', 'x:1'], array_map(static fn(RuleRevision $r): string => $r->code . ':' . $r->version, $editor->history()), "{$label}: histórico de todas, do mais recente");
        $this->assertEquals(['x:2'], array_map(static fn(RuleRevision $r): string => $r->code . ':' . $r->version, $editor->history(null, 1)), "{$label}: limite em todas");
        $fixed = new RuleCatalogEditor($env['store'], static fn(): DateTimeImmutable => new DateTimeImmutable('2030-01-01 00:00:00.000000 UTC'));
        $fixed->update('y', ['name' => 'Y2'], self::REASON);
        $fixed->update('x', ['name' => 'X3'], self::REASON);
        $this->assertEquals(['x:3', 'y:2'], array_map(static fn(RuleRevision $r): string => $r->code . ':' . $r->version, $editor->history(null, 2)), "{$label}: mesmo instante, a chave desempata");
        $this->assertEquals(['x', 'y'], array_keys($env['store']->lastRevisions()), "{$label}: últimas revisões por código");
        $this->assertEquals(['x', 'y'], array_map(static fn(array $row): string => $row['code'], $env['store']->findRows()), "{$label}: linhas por código");
    }

    // =====================================================================
    // Edges of the input, of the stored values and of the stores (each assertion kills a mutant of D15)
    // =====================================================================

    private function testInputEdges(): void
    {
        $editor = new RuleCatalogEditor(new InMemoryRuleCatalogStore(), $this->clock());
        $base = ['code' => 'e1', 'name' => 'Edge', 'rule_type' => 'max_rent'];
        foreach ([
            'rule_type blank' => ['rule_type' => '  '], 'rule_type not text' => ['rule_type' => 12], 'valid_until not a date' => ['valid_until' => self::SECRET],
            'int with suffix' => ['int_value' => '12abc'], 'int with prefix' => ['int_value' => 'abc12'], 'int with newline' => ['int_value' => "12\n"],
            'date with prefix' => ['valid_from' => 'x2026-01-01'], 'date with space' => ['valid_from' => '2026-01-01 '], 'not a leap day' => ['valid_from' => '2026-02-29'],
            'decimal with newline' => ['decimal_value' => "1.5\n"], 'parameters valid_until' => ['parameters' => ['valid_until' => '2026-01-01']],
            'parameters plan_code' => ['parameters' => ['plan_code' => 'G']], 'parameters data_fim_vigencia' => ['parameters' => ['data_fim_vigencia' => '2026-01-01']],
        ] as $label => $values) {
            $this->messages[] = $this->assertThrows(InvalidArgumentException::class, fn() => $editor->create($values + $base, self::REASON), "create: {$label}")->getMessage();
        }
        $this->assertEquals('Rule "e1": the version is kept by the editor (pass expectedVersion to change the version a screen read).', $this->assertThrows(InvalidArgumentException::class, fn() => $editor->create(['version' => 2] + $base, self::REASON))->getMessage(), 'mensagem da versão');
        $this->assertEquals('Rule "e1": column decimal_value needs a number or null.', $this->assertThrows(InvalidArgumentException::class, fn() => $editor->create(['decimal_value' => 'abc'] + $base, self::REASON))->getMessage(), 'mensagem do decimal');
        $this->assertEquals('Rule "e1": column violation_action holds Portuguese vocabulary of the rule engine, refused since 2.0.0 (use block, warn or log).', $this->assertThrows(InvalidArgumentException::class, fn() => $editor->create(['violation_action' => ' Alertar '] + $base, self::REASON))->getMessage(), 'mensagem da ação em português');
        $this->assertEquals('Rule "e1": column valid_until is before valid_from.', $this->assertThrows(InvalidArgumentException::class, fn() => $editor->create(['valid_from' => '2026-01-02', 'valid_until' => '2026-01-01'] + $base, self::REASON))->getMessage(), 'mensagem da janela');
        $created = $editor->create($base + ['int_value' => '-7', 'decimal_value' => 5, 'valid_from' => '2024-02-29', 'valid_until' => '2024-02-29', 'priority' => 0], self::REASON);
        $this->assertEquals([-7, '5', '2024-02-29', '2024-02-29', 'block'], [$created->row()['int_value'], $created->row()['decimal_value'], $created->row()['valid_from'], $created->row()['valid_until'], $created->row()['violation_action']], 'inteiro negativo, decimal inteiro, dia bissexto, janela de um dia, ação padrão');
        $this->assertThrows(StaleRuleChangeException::class, fn() => $editor->update('e1', ['priority' => '0'], self::REASON), 'prioridade igual em texto');
        $this->assertThrows(StaleRuleChangeException::class, fn() => $editor->update('e1', ['spec_json' => '   '], self::REASON), 'corpo em branco igual a nenhum');
        $this->assertThrows(StaleRuleChangeException::class, fn() => $editor->update('e1', ['decimal_value' => '5.000'], self::REASON), 'decimal igual em outra forma');
        $this->assertTrue(RuleSnapshot::isoDate('2024-02-29') === '2024-02-29' && RuleSnapshot::isoDate('2026-02-29') === null && RuleSnapshot::isoDate('2026-1-01') === null && RuleSnapshot::isoDate('26-01-01') === null, 'isoDate()');

        $e = $this->assertThrows(StaleRuleChangeException::class, fn() => $editor->create($base, self::REASON));
        $this->assertTrue($e->getCode() === 0 && $e->getPrevious() === null && $e instanceof RuleCatalogEditorException, 'exceções do editor: código 0, sem anterior');
        $integrity = new RuleCatalogStoreException('m', 'r', null, '23505');
        $this->assertTrue($integrity->isIntegrityViolation() && $integrity->getSqlState() === '23505' && $integrity->getRuleCode() === 'r', 'SQLSTATE 23 = integridade');
        $this->assertFalse((new RuleCatalogStoreException('m', null, null, '42P01'))->isIntegrityViolation(), 'outra classe');
        $this->assertFalse((new RuleCatalogStoreException('m'))->isIntegrityViolation(), 'sem SQLSTATE');
    }

    private function testStoredValueEdges(): void
    {
        $stored = self::stored(self::oracleRow(self::golden()));
        $this->assertEquals('5', RuleSnapshot::fromStored(['name' => 5] + $stored)['name'], 'texto lido como texto');
        $this->assertEquals(-7, RuleSnapshot::fromStored(['int_value' => '-7'] + $stored)['int_value'], 'inteiro em texto');
        $this->assertEquals('block', RuleSnapshot::fromStored(['violation_action' => '  '] + $stored)['violation_action'], 'ação em branco = block');
        $this->assertEquals('2026-01-01', RuleSnapshot::fromStored(['valid_from' => '2026-01-01T10:00:00'] + $stored)['valid_from'], 'data com hora (T)');
        $this->assertEquals('2024-02-29', RuleSnapshot::fromStored(['valid_from' => '2024-02-29 00:00:00'] + $stored)['valid_from'], 'data com hora (espaço)');
        foreach (['int_value' => ['12x', 'x12', "12\n", ' 12'], 'decimal_value' => ['1e3', ' 12', "1.5\n"], 'valid_from' => ['2026-01-01x', 'x2026-01-01', '2026-02-29', "2026-01-01\n"]] as $column => $values) {
            foreach ($values as $value) {
                $this->assertThrows(RuleCatalogStoreException::class, fn() => RuleSnapshot::fromStored([$column => $value] + $stored), "ilegível: {$column}");
            }
        }
        $this->assertEquals('Rule "golden": column parameters is not valid JSON.', $this->assertThrows(RuleCatalogStoreException::class, fn() => RuleSnapshot::fromStored(['parameters' => '{"a":'] + $stored))->getMessage(), 'mensagem do JSON');
        $this->assertEquals('Rule "golden": column int_value holds a value the editor cannot read (expected an integer).', $this->assertThrows(RuleCatalogStoreException::class, fn() => RuleSnapshot::fromStored(['int_value' => 1.5] + $stored))->getMessage(), 'mensagem do inteiro');
    }

    private function testMemoryStoreDetails(): void
    {
        $transactions = new \Antevemus\ASpecification\StateMachine\Persistence\InMemory\InMemoryTransactionManager();
        $this->assertTrue((new InMemoryRuleCatalogStore($transactions))->getTransactionManager() === $transactions, 'gerente de transação dado');
        $store = new InMemoryRuleCatalogStore();
        $store->put(['code' => 'p', 'name' => 'P', 'rule_type' => 't', 'spec_json' => '{"v":1}']);
        $this->assertEquals([1, 0, true, 'block', '{"v":1}'], [$store->findRow('p')['version'], $store->findRow('p')['priority'], $store->findRow('p')['active'], $store->findRow('p')['violation_action'], $store->findRow('p')['spec_json']], 'padrões do DDL em put()');
        $this->assertTrue((new InMemoryRuleCatalogStore(specJson: false))->put(['code' => 'p', 'name' => 'P', 'rule_type' => 't', 'spec_json' => null])->findRow('p') !== null, 'spec_json nulo numa loja sem a coluna');
    }

    /**
     * The store contract below the editor: order, lost compare-and-set, raced inserts, the default limit.
     */
    private function contractStoreDirect(string $label, array $env): void
    {
        $store = $env['store'];
        ($env['seed'])(self::stored(self::oracleRow(['code' => 'b', 'name' => 'B', 'rule_type' => 't'])));
        ($env['seed'])(self::stored(self::oracleRow(['code' => 'a', 'name' => 'A', 'rule_type' => 't'])));
        $this->assertEquals(['a', 'b'], array_map(static fn(array $row): string => $row['code'], $store->findRows()), "{$label}: linhas por código, qualquer que seja a ordem de inserção");
        $this->assertFalse($store->compareAndSetRow('a', 7, ['name' => 'X']), "{$label}: compare-and-set com versão errada");
        $this->assertFalse($store->compareAndSetRow('none', 1, ['name' => 'X']), "{$label}: compare-and-set sem linha");
        $this->assertEquals(['A', 1], [$store->findRow('a')['name'], $store->findRow('a')['version']], "{$label}: nada mudou");
        $this->assertFalse($store->insertRow($store->findRow('a')), "{$label}: insert de código existente = false");
        $editor = new RuleCatalogEditor($store, $this->clock());
        $editor->create(['code' => 'z', 'name' => 'Z', 'rule_type' => 't'], self::REASON);
        $first = $editor->create(['code' => 'y', 'name' => 'Y', 'rule_type' => 't'], self::REASON);
        $this->assertEquals(['y', 'z'], array_keys($store->lastRevisions()), "{$label}: últimas revisões por código");
        $this->assertFalse($store->appendRevision($first), "{$label}: a mesma versão duas vezes = false");
        for ($i = 0; $i < 51; $i++) {
            $editor->update('y', ['int_value' => $i], self::REASON);
        }
        $this->assertCount(50, $store->history('y'), "{$label}: limite padrão de 50");
        $this->assertCount(52, $store->history('y', 100), "{$label}: 52 linhas");
        $this->assertEquals('The history limit must be at least 1, 0 given.', $this->assertThrows(InvalidArgumentException::class, fn() => $store->history(null, 0))->getMessage(), "{$label}: mensagem do limite");
        $this->assertEquals(52, $store->lastRevisions('y')['y']->version, "{$label}: a última é a maior");
    }

    /**
     * What only the PDO store does: readiness of every call, the statements it runs, failures, column maps.
     */
    private function testPdoStoreDetails(PDO $pdo, string $family): void
    {
        self::dropSchema($pdo);
        self::loadDdl($pdo, (string) file_get_contents(self::DDL));
        $pdo->exec("INSERT INTO business_rule (code, name, rule_type) VALUES ('legacy', 'Legacy', 't')");
        $editor = new RuleCatalogEditor(PdoRuleCatalogStore::forCatalog($pdo, new PdoRuleCatalog($pdo)), $this->clock());
        $calls = [
            'create' => fn() => $editor->create(['code' => 'n', 'name' => 'N', 'rule_type' => 't'], self::REASON),
            'setActive' => fn() => $editor->setActive('legacy', false, self::REASON),
            'adopt' => fn() => $editor->adopt(null, self::REASON),
            'current' => fn() => $editor->current('legacy'),
            'revision' => fn() => $editor->revision('legacy', 1),
            'history' => fn() => $editor->history(),
            'findDrift' => fn() => $editor->findDrift(),
        ];
        foreach ($calls as $method => $call) {
            $this->assertTrue(str_contains($this->assertThrows(RuleCatalogStoreException::class, $call)->getMessage(), 'has no column version'), "{$family}: {$method}() confere a migração antes");
        }
        self::dropSchema($pdo);
        self::resetSchema($pdo);

        $store = PdoRuleCatalogStore::forCatalog($pdo, new PdoRuleCatalog($pdo));
        $this->assertTrue($store->supportsSpecJson() === false, "{$family}: sem spec_json mapeado");
        $plain = new RuleCatalogEditor($store, $this->clock());
        $created = $plain->create(['code' => 'w', 'name' => 'W', 'rule_type' => 't', 'violation_action' => 'warn', 'text_value' => 'kept', 'valid_until' => '2026-12-31'], self::REASON);
        $this->assertEquals(['warn', 'kept', '2026-12-31'], [$created->row()['violation_action'], $created->row()['text_value'], $created->row()['valid_until']], "{$family}: as colunas depois de spec_json são gravadas mesmo sem ela");
        $bad = ['plan_code' => 'G'] + RuleSnapshot::fromStored(['code' => 'bad', 'name' => 'Bad', 'rule_type' => 't', 'version' => 1, 'active' => 'Y']);
        $check = $this->assertThrows(RuleCatalogStoreException::class, fn() => $store->insertRow($bad), "{$family}: CHECK do DDL num insert");
        $this->assertTrue($check->getSqlState() !== null && $check->getPrevious() instanceof \PDOException && str_starts_with($check->getMessage(), 'The rule catalog store could not insert the rule "bad" (SQLSTATE ' . $check->getSqlState() . ')'), "{$family}: mensagem com operação, código e SQLSTATE: " . $check->getMessage());
        $this->assertThrows(RuleCatalogStoreException::class, fn() => $store->compareAndSetRow('w', 1, ['plan_code' => 'G']), "{$family}: CHECK num compare-and-set é falha, não corrida perdida");
        $broken = new PdoRuleCatalogStore($pdo, '', ['change' => 'missing_history']);
        $missing = $this->assertThrows(RuleCatalogStoreException::class, fn() => $broken->history(), "{$family}: tabela de histórico ausente");
        $this->assertEquals(sprintf('The rule catalog store could not read the history of the rules (SQLSTATE %s); the driver error is the previous exception.', (string) $missing->getSqlState()), $missing->getMessage(), "{$family}: mensagem sem código de regra");
        $this->assertTrue($missing->getSqlState() !== null && $missing->getRuleCode() === null, "{$family}: SQLSTATE do driver");
        $pdo->exec("INSERT INTO business_rule_change (change_id, rule_code, version, change_kind, snapshot, snapshot_hash, reason, origin, changed_at) VALUES ('0', 'odd', 1, 'update', '{}', 'h', 'r', 'ui', 'not an instant')");
        $this->assertTrue(str_contains($this->assertThrows(RuleCatalogStoreException::class, fn() => $store->findRevision('odd', 1))->getMessage(), 'changed_at that is not an instant'), "{$family}: instante ilegível no histórico");
        $pdo->exec("DELETE FROM business_rule_change WHERE rule_code = 'odd'");

        // A column map with renamed columns: read under the canonical alias, written to the real column
        $pdo->exec('ALTER TABLE business_rule RENAME COLUMN name TO rule_name');
        $renamed = new RuleCatalogEditor(PdoRuleCatalogStore::forCatalog($pdo, new PdoRuleCatalog($pdo, columns: ['name' => 'rule_name', 'version' => 'version'])), $this->clock());
        $this->assertEquals('Renamed', $renamed->update('w', ['name' => 'Renamed'], self::REASON)->row()['name'], "{$family}: coluna renomeada lida e gravada");
        $this->assertEquals('Renamed', $pdo->query("SELECT rule_name FROM business_rule WHERE code = 'w'")->fetchColumn(), "{$family}: na coluna real");
        $this->assertTrue($renamed->findDrift() === [], "{$family}: sem deriva pela coluna renomeada");

        // Column maps and messages of the constructor
        $this->assertEquals('nome', PdoRuleCatalogStore::forCatalog($pdo, new PdoRuleCatalog($pdo, columns: ['name' => 'nome']))->getColumns()['name'], "{$family}: forCatalog() leva todas as colunas da regra");
        $this->assertEquals('nome', (new PdoRuleCatalogStore($pdo, '', [], ['spec_json' => null, 'name' => 'nome']))->getColumns()['name'], "{$family}: o mapa continua depois de spec_json nulo");
        $this->assertEquals('Unknown rule catalog store column "body". Expected one of: ' . implode(', ', [...PdoRuleCatalog::RULE_COLUMNS, 'version', 'spec_json']) . '.', $this->assertThrows(InvalidArgumentException::class, fn() => new PdoRuleCatalogStore($pdo, '', [], ['body' => 'b']))->getMessage(), "{$family}: mensagem da coluna desconhecida");
        $this->assertEquals('Rule catalog store column "name" is required and cannot be absent.', $this->assertThrows(InvalidArgumentException::class, fn() => new PdoRuleCatalogStore($pdo, '', [], ['name' => null]))->getMessage(), "{$family}: coluna obrigatória");
        $this->assertTrue(str_contains($this->assertThrows(InvalidArgumentException::class, fn() => new PdoRuleCatalogStore($pdo, '', [], ['name' => 5]))->getMessage(), '"int"'), "{$family}: nome real que não é texto");
        $this->assertTrue(str_contains($this->assertThrows(InvalidArgumentException::class, fn() => new PdoRuleCatalogStore($pdo, '', ['rule' => 5]))->getMessage(), '"int"'), "{$family}: tabela que não é texto");
        $transactions = new \Antevemus\ASpecification\Persistence\PdoTransactionManager($pdo);
        $this->assertTrue((new PdoRuleCatalogStore($pdo, transactions: $transactions))->getTransactionManager() === $transactions, "{$family}: gerente de transação dado");
        self::dropSchema($pdo);

        if ($family !== 'sqlite') {
            return;
        }
        // The statements: the probes run once, a change writes only its columns, the history is paginated
        $recording = new PdoRecordingPdo('sqlite::memory:');
        $recording->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::resetSchema($recording);
        $editor = new RuleCatalogEditor(PdoRuleCatalogStore::forCatalog($recording, new PdoRuleCatalog($recording)), $this->clock());
        $editor->create(['code' => 's', 'name' => 'S', 'rule_type' => 't', 'int_value' => 1], self::REASON);
        $recording->reset();
        $editor->update('s', ['int_value' => 2], self::REASON);
        $editor->history('s', 3);
        $this->assertCount(0, array_filter($recording->statements, static fn(string $sql): bool => str_contains($sql, 'WHERE 1 = 0')), 'as sondas não se repetem');
        $this->assertEquals(['UPDATE business_rule SET int_value = :v_int_value, version = version + 1 WHERE code = :code AND version = :expected_version'], array_values(array_filter($recording->statements, static fn(string $sql): bool => str_starts_with($sql, 'UPDATE'))), 'a mudança grava só as colunas mudadas');
        $this->assertTrue(str_ends_with((string) end($recording->statements), 'ORDER BY changed_at DESC, change_id DESC LIMIT 3'), 'o histórico paginado no SQL');
    }

    // =====================================================================
    // D15: random sequences against a model of the test
    // =====================================================================

    /**
     * @return list<string> The transcript: one line per step, compared between memory and every database
     */
    private function runSequence(array $env, int $seed, int $steps, string $label): array
    {
        $random = new Randomizer(new Mt19937($seed));
        $this->tick = 0;
        $editor = new RuleCatalogEditor($env['store'], $this->clock());
        $pool = ['r_a', 'r_b', 'r_c', 'r_d', 'r_e'];
        $model = [];
        $photos = [];
        foreach (['r_a', 'r_b'] as $code) {
            $model[$code] = self::oracleRow(['code' => $code, 'name' => 'Seeded ' . $code, 'rule_type' => 'max_rent', 'scope' => 'contract', 'int_value' => 100]);
            ($env['seed'])(self::stored($model[$code]));
        }
        $transcript = [];
        for ($step = 0; $step < $steps; $step++) {
            $code = $pool[$random->getInt(0, count($pool) - 1)];
            $roll = $random->getInt(0, 99);
            $op = $roll < 20 ? 'create' : ($roll < 60 ? 'update' : ($roll < 85 ? 'setActive' : 'adopt'));
            $where = "{$label} semente {$seed} passo {$step} ({$op} {$code})";
            $current = $model[$code] ?? null;
            $baselines = [];
            $expected = null;
            $after = null;

            if ($op === 'create') {
                $values = ['code' => $code, 'name' => self::pick($random, self::CHOICES['name']), 'rule_type' => 'max_rent'] + self::randomChanges($random, $random->getInt(0, 4));
                if ($random->getInt(0, 3) === 0) {
                    $values['active'] = false;
                }
                $call = fn(): array => [$editor->create($values, self::REASON, 'seq', 'ui')];
                $expected = $current !== null ? StaleRuleChangeException::class : RuleChangeKind::Create;
                $after = $current === null ? self::oracleRow($values) : null;
            } elseif ($op === 'adopt') {
                $one = $random->getInt(0, 1) === 1;
                $call = fn(): array => $editor->adopt($one ? $code : null, self::REASON, 'seq');
                if ($one && $current === null) {
                    $expected = RuleNotFoundException::class;
                } else {
                    $expected = RuleChangeKind::Baseline;
                    foreach ($one ? [$code => $current] : $model as $c => $row) {
                        if (!isset($photos[$c][$row['version']])) {
                            $baselines[] = $c;
                        }
                    }
                    sort($baselines, SORT_STRING);
                }
            } else {
                $expectedVersion = match ($random->getInt(0, 3)) {
                    0, 1 => null,
                    2 => $current['version'] ?? 1,
                    default => ($current['version'] ?? 1) + self::pick($random, [-2, -1, 1, 2]),
                };
                if ($op === 'update') {
                    $changes = $current !== null && $random->getInt(0, 6) === 0
                        ? array_intersect_key($current, self::randomChanges($random, $random->getInt(1, 3)))
                        : self::randomChanges($random, $random->getInt(1, 3));
                    $call = fn(): array => [$editor->update($code, $changes, self::REASON, 'seq', 'ui', $expectedVersion)];
                    $kind = RuleChangeKind::Update;
                } else {
                    $active = $random->getInt(0, 1) === 1;
                    $changes = ['active' => $active];
                    $call = fn(): array => [$editor->setActive($code, $active, self::REASON, 'seq', 'ui', $expectedVersion)];
                    $kind = $active ? RuleChangeKind::Activate : RuleChangeKind::Deactivate;
                }
                if ($current === null) {
                    $expected = RuleNotFoundException::class;
                } elseif ($expectedVersion !== null && $expectedVersion !== $current['version']) {
                    $expected = StaleRuleChangeException::class;
                } else {
                    $merged = self::oracleRow($changes + $current);
                    if (self::withoutVersion($merged) === self::withoutVersion($current)) {
                        $expected = StaleRuleChangeException::class;
                    } else {
                        $expected = $kind;
                        $after = ['version' => $current['version'] + 1] + $merged;
                        if (!isset($photos[$code][$current['version']])) {
                            $baselines[] = $code;
                        }
                    }
                }
            }

            try {
                $revisions = $call();
                $outcome = null;
            } catch (Throwable $e) {
                $revisions = [];
                $outcome = $e;
            }
            if (is_string($expected)) {
                $this->assertTrue($outcome instanceof $expected, sprintf('%s: esperava %s, veio %s', $where, $expected, $outcome === null ? 'sucesso' : get_class($outcome) . ' ' . $outcome->getMessage()));
                $transcript[] = sprintf('%d %s %s %s', $step, $op, $code, substr((string) strrchr($expected, '\\'), 1));
            } else {
                $this->assertTrue($outcome === null, sprintf('%s: esperava sucesso, veio %s', $where, $outcome === null ? '' : get_class($outcome) . ' ' . $outcome->getMessage()));
                if ($expected === RuleChangeKind::Baseline) {
                    $this->assertEquals($baselines, array_map(static fn(RuleRevision $r): string => $r->code, $revisions), "{$where}: linhas de base de adopt()");
                    foreach ($revisions as $revision) {
                        $photos[$revision->code][$revision->version] = self::oracleSnapshot($model[$revision->code]);
                    }
                } else {
                    $revision = $revisions[0];
                    foreach ($baselines as $c) {
                        $photos[$c][$current['version']] = self::oracleSnapshot($current);
                    }
                    $model[$code] = $after;
                    $photos[$code][$after['version']] = self::oracleSnapshot($after);
                    $this->assertEquals([$expected, $after['version']], [$revision->kind, $revision->version], "{$where}: tipo e versão, de 1 em 1");
                    $this->assertEquals(array_values(array_filter(RuleSnapshot::COLUMNS, static fn(string $c): bool => $c !== 'version' && ($current === null ? false : $after[$c] !== $current[$c]))), $revision->changedFields, "{$where}: colunas mudadas");
                }
                $transcript[] = sprintf('%d %s %s %s', $step, $op, $code, implode(' ', array_map(static fn(RuleRevision $r): string => $r->code . '@' . $r->version . ':' . $r->kind->value . ':' . substr($r->snapshotHash, 7, 16) . ':' . $r->changedAt->format('Y-m-d H:i:s.u'), $revisions)));
            }
            foreach ($revisions as $written) {
                $this->assertTrue($written->isIntact() && self::revisionKey($written) === self::revisionKey($editor->revision($written->code, $written->version)), "{$where}: {$written->code}@{$written->version} relida igual e íntegra");
            }
            $this->assertSequenceInvariants($editor, $env['store'], $model, $photos, $where, $step % 25 === 0 || $step === $steps - 1);
        }

        return $transcript;
    }

    /**
     * @param array<string, array<string, mixed>> $model
     * @param array<string, array<int, string>> $photos
     */
    private function assertSequenceInvariants(RuleCatalogEditor $editor, IRuleCatalogStore $store, array $model, array $photos, string $where, bool $deep): void
    {
        $expectedDrift = [];
        foreach (['r_a', 'r_b', 'r_c', 'r_d', 'r_e'] as $code) {
            $row = $store->findRow($code);
            if (!isset($model[$code])) {
                $this->assertTrue($row === null, "{$where}: {$code} não existe");
                continue;
            }
            $this->assertEquals(self::oracleSnapshot($model[$code]), RuleSnapshot::encode($row), "{$where}: a linha de {$code} é a do modelo");
            if (!isset($photos[$code][$model[$code]['version']])) {
                $expectedDrift[] = $code . ':no_history:' . $model[$code]['version'] . ':-';
            }
            if (!isset($photos[$code])) {
                continue;
            }
            $this->assertEquals(max(array_keys($photos[$code])), $editor->current($code)?->version, "{$where}: current() de {$code}");
            if (!$deep) {
                continue;
            }
            $history = $editor->history($code, 1000);
            $versions = array_map(static fn(RuleRevision $r): int => $r->version, $history);
            $expectedVersions = array_keys($photos[$code]);
            rsort($expectedVersions);
            $this->assertEquals($expectedVersions, $versions, "{$where}: uma linha por versão de {$code}, decrescente");
            $this->assertEquals(range(max($versions), min($versions)), $versions, "{$where}: versões de {$code} de 1 em 1");
            foreach ($history as $revision) {
                $this->assertTrue($revision->isIntact(), "{$where}: {$code}@{$revision->version} íntegra");
                $this->assertEquals($photos[$code][$revision->version], $revision->snapshot, "{$where}: foto de {$code}@{$revision->version}");
            }
            $top = $editor->revision($code, max($versions));
            $this->assertTrue($top !== null && $top->definition() == self::oracleDefinition(RuleSnapshot::decode($photos[$code][max($versions)])), "{$where}: revision()->definition() de {$code}");
        }
        $this->assertEquals($expectedDrift, self::driftKeys($editor->findDrift()), "{$where}: deriva = regras sem foto da versão atual");
    }

    /**
     * @return array<string, mixed>
     */
    private static function randomChanges(Randomizer $random, int $count): array
    {
        $columns = [...array_keys(self::CHOICES), 'parameters', 'window', 'product'];
        $changes = [];
        for ($i = 0; $i < $count; $i++) {
            $column = $columns[$random->getInt(0, count($columns) - 1)];
            if ($column === 'window') {
                [$changes['valid_from'], $changes['valid_until']] = self::pick($random, self::WINDOWS);
            } elseif ($column === 'product') {
                [$changes['product_code'], $changes['plan_code']] = self::pick($random, self::PRODUCTS);
            } elseif ($column === 'parameters') {
                $changes['parameters'] = self::pick($random, self::PARAMETERS);
            } else {
                $changes[$column] = self::pick($random, self::CHOICES[$column]);
            }
        }

        return $changes;
    }

    /**
     * @template T
     * @param list<T> $values
     * @return T
     */
    private static function pick(Randomizer $random, array $values): mixed
    {
        return $values[$random->getInt(0, count($values) - 1)];
    }

    // =====================================================================
    // Databases
    // =====================================================================

    /**
     * @param Closure(): PDO $connect A fresh connection to the database
     * @param array<int, list<string>> $memoryTranscripts
     */
    private function runDatabase(string $family, string $label, Closure $connect, array $memoryTranscripts): void
    {
        $this->testReadinessAndMigration($connect(), $family);
        $this->testPdoStoreDetails($connect(), $family);
        $this->runContract($label, function () use ($connect): array {
            $pdo = $connect();
            self::resetSchema($pdo);

            return self::pdoEnvironment($pdo);
        });
        foreach (self::SEEDS as $seed) {
            $pdo = $connect();
            self::resetSchema($pdo);
            $steps = $family === 'sqlite' ? self::STEPS_MEMORY : self::STEPS_DATABASE;
            $transcript = $this->runSequence(self::pdoEnvironment($pdo), $seed, $steps, $label);
            $this->assertEquals(array_slice($memoryTranscripts[$seed], 0, $steps), $transcript, "{$label}: transcrição da semente {$seed} igual à da memória (RN-06)");
            $catalog = new PdoRuleCatalog($pdo, columns: [...PdoRuleCatalog::COLUMNS_SINCE_2_4, ...PdoRuleCatalog::COLUMNS_SINCE_2_13]);
            $editor = new RuleCatalogEditor(PdoRuleCatalogStore::forCatalog($pdo, $catalog));
            foreach (['r_a', 'r_b', 'r_c', 'r_d', 'r_e'] as $code) {
                $current = $editor->current($code);
                $read = $catalog->findRuleByCode($code);
                if ($current !== null && $current->definition()->isActive()) {
                    $this->assertTrue($read == $current->definition(), "{$label}: semente {$seed}, {$code}: o catálogo lê a última revisão");
                }
            }
        }
        $pdo = $connect();
        self::resetSchema($pdo);
        $this->testCatalogVersionSeesTheChange($pdo, $label);
        self::dropSchema($pdo);
    }

    /**
     * D13 / RN-01: a database of the 2.12.0 DDL is refused with a clear message, and keeps being read as before.
     */
    private function testReadinessAndMigration(PDO $pdo, string $family): void
    {
        self::dropSchema($pdo);
        self::loadDdl($pdo, (string) file_get_contents(self::DDL));
        $pdo->exec("INSERT INTO business_rule (code, name, rule_type, scope) VALUES ('legacy', 'Legacy', 'max_rent', 'contract')");
        $catalog = new PdoRuleCatalog($pdo);
        $editor = new RuleCatalogEditor(PdoRuleCatalogStore::forCatalog($pdo, $catalog));
        $missingVersion = $this->assertThrows(RuleCatalogStoreException::class, fn() => $editor->update('legacy', ['name' => 'x'], self::REASON), "{$family}: sem a migração");
        $this->assertTrue(str_contains($missingVersion->getMessage(), 'has no column version') && str_contains($missingVersion->getMessage(), 'resources/sql/rule_catalog.ansi.sql'), "{$family}: a mensagem aponta o cabeçalho do DDL");
        $this->assertThrows(RuleCatalogStoreException::class, fn() => $editor->findDrift(), "{$family}: leitura também recusada");
        $this->assertTrue($catalog->findRuleByCode('legacy')?->getVersion() === null, "{$family}: o catálogo de 2.12.0 continua lendo");
        if ($family === 'pgsql') {
            $pdo->beginTransaction();
            $this->assertThrows(RuleCatalogStoreException::class, fn() => $editor->create(['code' => 'n', 'name' => 'n', 'rule_type' => 't'], self::REASON), 'pgsql: dentro de uma transação');
            $this->assertEquals(1, (int) $pdo->query('SELECT 1')->fetchColumn(), 'pgsql: a sonda falha sob savepoint, a transação continua utilizável');
            $pdo->rollBack();
        }

        [$alter, $create] = self::migration();
        $pdo->exec($alter);
        $this->assertEquals(1, (int) $pdo->query("SELECT version FROM business_rule WHERE code = 'legacy'")->fetchColumn(), "{$family}: regra existente na versão 1");
        $missingHistory = $this->assertThrows(RuleCatalogStoreException::class, fn() => $editor->update('legacy', ['name' => 'x'], self::REASON), "{$family}: sem a tabela de histórico");
        $this->assertTrue(str_contains($missingHistory->getMessage(), 'business_rule_change') && str_contains($missingHistory->getMessage(), 'migration 2.13.0'), "{$family}: a mensagem cita a tabela");
        $this->assertThrows(\PDOException::class, fn() => $pdo->exec("UPDATE business_rule SET version = 0 WHERE code = 'legacy'"), "{$family}: CHECK (version >= 1) da migração");
        $pdo->exec($create);
        $pdo->exec($create); // idempotent
        $this->assertEquals(['legacy:no_history:1:-'], self::driftKeys($editor->findDrift()), "{$family}: migrada, a regra existente não tem histórico");
        $this->assertEquals(2, $editor->update('legacy', ['name' => 'Legacy II'], self::REASON)->version, "{$family}: e o editor grava");
        $this->assertTrue((new PdoRuleCatalog($pdo))->findRuleByCode('legacy')?->getVersion() === null, "{$family}: sem COLUMNS_SINCE_2_13 a versão não é lida (RN-01)");
        $this->assertEquals(2, (new PdoRuleCatalog($pdo, columns: PdoRuleCatalog::COLUMNS_SINCE_2_13))->findRuleByCode('legacy')?->getVersion(), "{$family}: com o mapeamento, a versão");
        $this->assertThrows(\PDOException::class, fn() => $pdo->exec("INSERT INTO business_rule_change (change_id, rule_code, version, change_kind, snapshot, snapshot_hash, reason, origin, changed_at) VALUES ('X', 'legacy', 2, 'update', '{}', 'h', 'r', 'ui', 't')"), "{$family}: UNIQUE (rule_code, version)");
        $this->assertThrows(\PDOException::class, fn() => $pdo->exec("INSERT INTO business_rule_change (change_id, rule_code, version, change_kind, snapshot, snapshot_hash, reason, origin, changed_at) VALUES ('Y', 'legacy', 9, 'erase', '{}', 'h', 'r', 'ui', 't')"), "{$family}: CHECK do tipo");
        self::dropSchema($pdo);

        // The store refuses what it cannot map
        $this->assertThrows(InvalidArgumentException::class, fn() => new PdoRuleCatalogStore($pdo, 'bad schema'), 'schema');
        $this->assertThrows(InvalidArgumentException::class, fn() => new PdoRuleCatalogStore($pdo, '', ['history' => 'h']), 'papel desconhecido');
        $this->assertThrows(InvalidArgumentException::class, fn() => new PdoRuleCatalogStore($pdo, '', ['change' => 'a b']), 'tabela');
        $this->assertThrows(InvalidArgumentException::class, fn() => new PdoRuleCatalogStore($pdo, '', [], ['color' => 'c']), 'coluna desconhecida');
        $this->assertThrows(InvalidArgumentException::class, fn() => new PdoRuleCatalogStore($pdo, '', [], ['version' => null]), 'versão ausente');
        $this->assertThrows(InvalidArgumentException::class, fn() => new PdoRuleCatalogStore($pdo, '', [], ['name' => 'a b']), 'coluna real');
        $this->assertFalse((new PdoRuleCatalogStore($pdo, '', [], ['spec_json' => null]))->supportsSpecJson(), 'spec_json nulo = ausente');
        $store = new PdoRuleCatalogStore($pdo, 'main', ['rule' => 'rules', 'change' => 'changes'], ['code' => 'rule_code', 'spec_json' => 'body']);
        $this->assertEquals(['rule' => 'main.rules', 'change' => 'main.changes'], $store->getTables(), 'tabelas qualificadas');
        $this->assertEquals(['rule_code', 'body'], [$store->getColumns()['code'], $store->getColumns()['spec_json']], 'colunas mapeadas');
        $this->assertTrue($store->getPdo() === $pdo && $store->supportsSpecJson(), 'conexão e spec_json');
        $forCatalog = PdoRuleCatalogStore::forCatalog($pdo, new PdoRuleCatalog($pdo, 'main', ['rule' => 'regras'], ['code' => 'codigo', 'version' => 'versao', 'sort_order' => 'ordem']), 'historico');
        $this->assertEquals(['rule' => 'main.regras', 'change' => 'main.historico'], $forCatalog->getTables(), 'forCatalog(): schema e tabelas do catálogo');
        $this->assertEquals(['codigo', 'versao', false], [$forCatalog->getColumns()['code'], $forCatalog->getColumns()['version'], isset($forCatalog->getColumns()['sort_order'])], 'forCatalog(): colunas da regra do catálogo');
        $this->assertEquals('version', PdoRuleCatalogStore::forCatalog($pdo, new PdoRuleCatalog($pdo))->getColumns()['version'], 'forCatalog(): catálogo sem versão mapeada');
    }

    /**
     * RN-01: without the mapping, the SQL of PdoRuleCatalog is the one of 2.12.0; with it, the version is the last
     * column read. The message that refuses an unknown column is the one of 2.12.0.
     */
    private function testCatalogSqlWithoutTheMappingIsThatOf2_12(): void
    {
        $pdo = new PdoRecordingPdo('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::loadDdl($pdo, (string) file_get_contents(self::DDL));
        $pdo->exec(self::migration()[0]);
        $columns = 'code, name, description, rule_type, violation_action, int_value, decimal_value, text_value, legal_basis, violation_message, condition_expression, priority, scope, scenario, parameters, active, product_code, plan_code, valid_from, valid_until';
        $pdo->reset();
        (new PdoRuleCatalog($pdo))->findRuleByCode('x');
        (new PdoRuleCatalog($pdo, columns: PdoRuleCatalog::COLUMNS_SINCE_2_4))->findRuleByCode('x');
        (new PdoRuleCatalog($pdo, columns: [...PdoRuleCatalog::COLUMNS_SINCE_2_4, ...PdoRuleCatalog::COLUMNS_SINCE_2_13]))->findRuleByCode('x');
        (new PdoRuleCatalog($pdo, columns: ['version' => 'version', 'spec_json' => null]))->findRules('contract');
        $this->assertEquals([
            "SELECT {$columns} FROM business_rule WHERE active = 'Y' AND code = :code",
            "SELECT {$columns}, spec_json FROM business_rule WHERE active = 'Y' AND code = :code",
            "SELECT {$columns}, spec_json, version FROM business_rule WHERE active = 'Y' AND code = :code",
            "SELECT {$columns}, version FROM business_rule WHERE active = 'Y' AND (scope = :scope OR scope IS NULL OR scope = '') AND (scenario IS NULL OR scenario = '') AND (product_code IS NULL OR product_code = '') AND (plan_code IS NULL OR plan_code = '') ORDER BY priority DESC, code",
        ], $pdo->statements, 'SQL da 2.12.0 sem o mapeamento; a versão por último com ele');
        $catalog = new PdoRuleCatalog($pdo);
        $this->assertFalse(isset($catalog->getColumns()['version']), 'versão ausente por padrão');
        $this->assertFalse(isset((new PdoRuleCatalog($pdo, columns: ['version' => null]))->getColumns()['version']), 'null = ausente');
        $this->assertEquals('versao', (new PdoRuleCatalog($pdo, columns: ['version' => 'versao']))->getColumns()['version'], 'nome real');
        $this->assertThrows(InvalidArgumentException::class, fn() => new PdoRuleCatalog($pdo, columns: ['version' => 'a b']), 'identificador');
        $unknown = $this->assertThrows(InvalidArgumentException::class, fn() => new PdoRuleCatalog($pdo, columns: ['body' => 'body']));
        $this->assertEquals('Unknown rule catalog column "body". Expected one of: ' . implode(', ', [...PdoRuleCatalog::canonicalColumns(), 'spec_json']) . '.', $unknown->getMessage(), 'mensagem da 2.12.0 (RN-01)');
        $this->assertEquals(['version'], PdoRuleCatalog::RULE_COLUMNS_SINCE_2_13, 'colunas da 2.13.0');
    }

    /**
     * D12: a CachedRuleCatalog over the catalog sees a change of the editor at once with the history in the version;
     * the largest version of the rule table alone misses it (the measurement behind the choice).
     */
    private function testCatalogVersionSeesTheChange(PDO $pdo, string $label): void
    {
        $catalog = new PdoRuleCatalog($pdo, columns: PdoRuleCatalog::COLUMNS_SINCE_2_13);
        $store = PdoRuleCatalogStore::forCatalog($pdo, $catalog);
        $editor = new RuleCatalogEditor($store, $this->clock());
        $editor->create(['code' => 'low', 'name' => 'Low', 'rule_type' => 'max_rent', 'scope' => 'contract', 'int_value' => 1], self::REASON);
        $editor->create(['code' => 'high', 'name' => 'High', 'rule_type' => 'max_rent', 'scope' => 'contract'], self::REASON);
        $editor->update('high', ['int_value' => 1], self::REASON);
        $editor->update('high', ['int_value' => 2], self::REASON);

        $maxVersion = new PdoCatalogVersion($pdo, ['business_rule' => 'version']);
        $withHistory = PdoCatalogVersion::forRuleCatalog($pdo, $catalog, null, $store->getTables()['change']);
        $this->assertEquals(['SELECT COUNT(*) FROM business_rule', 'SELECT COUNT(*) FROM document_requirement_group', 'SELECT COUNT(*) FROM document_requirement', 'SELECT COUNT(*), MAX(change_id) FROM business_rule_change'], $withHistory->getQueries(), "{$label}: o sinal lê o histórico do editor");
        $this->assertEquals(3, count(PdoCatalogVersion::forRuleCatalog($pdo, $catalog)->getQueries()), "{$label}: sem changeTable, o sinal de 2.8.0");
        $before = [$maxVersion->catalogVersion(), $withHistory->catalogVersion()];

        $cache = new ArrayCache();
        $cached = CachedRuleCatalog::decorate($catalog, $cache, 3600, $withHistory);
        $this->assertEquals(1, $cached->findRuleByCode('low')?->getVersion(), "{$label}: lido pelo cache");
        $this->assertEquals(1, $cached->findRuleByCode('low')?->getVersion(), "{$label}: acerto do cache");
        $editor->update('low', ['int_value' => 5], self::REASON);
        $this->assertEquals($before[0], $maxVersion->catalogVersion(), "{$label}: medido: MAX(version) e a contagem não mudam quando a regra mudada não é a de maior versão");
        $this->assertTrue($before[1] !== $withHistory->catalogVersion(), "{$label}: o histórico muda o sinal");
        $after = $cached->findRuleByCode('low');
        $this->assertEquals([2, 5], [$after?->getVersion(), $after?->getIntValue()], "{$label}: o cache vê a mudança na hora (D12)");
        $this->assertEquals(['high@3', 'low@2'], array_map(static fn($r): string => $r->getCode() . '@' . $r->getVersion(), $cached->findRules('contract')), "{$label}: findRules() pelo cache com as versões");
        $stale = CachedRuleCatalog::decorate($catalog, new ArrayCache(), 3600, $maxVersion);
        $stale->findRuleByCode('low');
        $editor->update('low', ['int_value' => 6], self::REASON);
        $this->assertEquals(5, $stale->findRuleByCode('low')?->getIntValue(), "{$label}: medido: com MAX(version) o cache não vê a mudança");
    }

    // =====================================================================
    // RN-04: two connections changing the same rule
    // =====================================================================

    private function testConcurrencyOnSqliteFiles(): void
    {
        $path = sys_get_temp_dir() . '/aspec060-' . bin2hex(random_bytes(4)) . '.sqlite';
        try {
            $first = new PDO('sqlite:' . $path);
            $first->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $first->exec('PRAGMA journal_mode = WAL');
            $second = new PDO('sqlite:' . $path);
            $second->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $second->setAttribute(PDO::ATTR_TIMEOUT, 2);
            $first->setAttribute(PDO::ATTR_TIMEOUT, 2);
            $this->testConcurrency($first, $second, 'sqlite (arquivo, WAL)');
        } finally {
            foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    private function testConcurrency(PDO $first, PDO $second, string $label): void
    {
        self::resetSchema($first);
        $columns = [...PdoRuleCatalog::COLUMNS_SINCE_2_4, ...PdoRuleCatalog::COLUMNS_SINCE_2_13];
        $one = new RuleCatalogEditor(PdoRuleCatalogStore::forCatalog($first, new PdoRuleCatalog($first, columns: $columns)), $this->clock());
        $two = new RuleCatalogEditor(PdoRuleCatalogStore::forCatalog($second, new PdoRuleCatalog($second, columns: $columns)), $this->clock());
        $first->exec("INSERT INTO business_rule (code, name, rule_type, int_value) VALUES ('shared', 'Shared', 'max_rent', 1)");

        // Two screens read version 1; the first to save wins, the other is told to reload
        $this->assertEquals(2, $one->update('shared', ['int_value' => 2], self::REASON, 'ana', 'ui', 1)->version, "{$label}: a primeira tela grava");
        $this->assertThrows(StaleRuleChangeException::class, fn() => $two->update('shared', ['int_value' => 3], self::REASON, 'bia', 'ui', 1), "{$label}: a segunda recebe Stale (RN-04)");

        // Inside the transaction: the other connection commits between the read and the compare-and-set of the first
        $race = $one->withChangeGuard(new CountingSpecification(static function () use ($two): bool {
            $two->update('shared', ['int_value' => 10], 'Committed by the other connection', 'bia');

            return true;
        }));
        $lost = $this->assertThrows(StaleRuleChangeException::class, fn() => $race->update('shared', ['int_value' => 20], self::REASON, 'ana'), "{$label}: a perdedora da corrida recebe Stale (RN-04)");
        $this->assertEquals('Rule "shared" changed since it was read (version 2): reload and try again.', $lost->getMessage(), "{$label}: mensagem da corrida perdida");
        $history = $one->history('shared');
        $this->assertEquals(['3:update:bia:10', '2:update:ana:2', '1:baseline:ana:1'], array_map(static fn(RuleRevision $r): string => $r->version . ':' . $r->kind->value . ':' . $r->changedBy . ':' . $r->definition()->getIntValue(), $history), "{$label}: só a vencedora gravou; nunca as duas na mesma versão");
        $this->assertEquals(10, (int) $first->query("SELECT int_value FROM business_rule WHERE code = 'shared'")->fetchColumn(), "{$label}: a linha é a da vencedora");

        // A rule without history: the loser writes no baseline either
        $first->exec("INSERT INTO business_rule (code, name, rule_type) VALUES ('fresh', 'Fresh', 'max_rent')");
        $race = $one->withChangeGuard(new CountingSpecification(static function () use ($two): bool {
            $two->setActive('fresh', false, 'Committed by the other connection');

            return true;
        }));
        $this->assertThrows(StaleRuleChangeException::class, fn() => $race->update('fresh', ['int_value' => 1], self::REASON), "{$label}: corrida na primeira mudança");
        $this->assertEquals(['2:deactivate', '1:baseline'], array_map(static fn(RuleRevision $r): string => $r->version . ':' . $r->kind->value, $one->history('fresh')), "{$label}: uma linha de base só, a da vencedora");
        $this->assertTrue($one->findDrift() === [], "{$label}: sem deriva depois da corrida");

        // Two creates of the same code: one row, one history row
        $race = $one->withChangeGuard(new CountingSpecification(static function () use ($two): bool {
            $two->create(['code' => 'twin', 'name' => 'Twin', 'rule_type' => 'max_rent'], 'Created by the other connection');

            return true;
        }));
        $this->assertThrows(StaleRuleChangeException::class, fn() => $race->create(['code' => 'twin', 'name' => 'Twin', 'rule_type' => 'max_rent'], self::REASON), "{$label}: create concorrente");
        $this->assertCount(1, $one->history('twin'), "{$label}: um create só");
        self::dropSchema($first);
    }

    // =====================================================================
    // D16: examples 25 to 25.3 of the documentation
    // =====================================================================

    private function testExample25BlocksAreTheSameInBothLanguages(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['25', '25.1', '25.2', '25.3'] as $number) {
            $english = ReadmeBlocks::of($root . '/docs/en/rule-engine/audited-changes.md', $number);
            $portuguese = ReadmeBlocks::of($root . '/docs/pt-BR/rule-engine/audited-changes.md', $number);
            $this->assertCount(1, $english, "um bloco PHP no exemplo {$number} (EN)");
            $this->assertCount(1, $portuguese, "um bloco PHP no exemplo {$number} (pt-BR)");
            $this->assertEquals(ReadmeBlocks::codeTokens($english[0]), ReadmeBlocks::codeTokens($portuguese[0]), "exemplo {$number}: bloco igual fora os comentários");
        }
        $this->assertTrue(str_contains(ReadmeBlocks::of($root . '/docs/en/rule-engine/audited-changes.md', '25')[0], "\$editor = new RuleCatalogEditor(PdoRuleCatalogStore::forCatalog(\$pdo, \$catalog));"), 'exemplo 25: o bloco é o executado abaixo');
    }

    private function testExample25RunsAsWritten(): void
    {
        $pdo = self::sqliteEmpty();
        self::resetSchema($pdo); // the tables of resources/sql/rule_catalog.ansi.sql, after the migration 2.13.0 of its header
        $pdo->exec("INSERT INTO business_rule (code, name, rule_type, scope, int_value) VALUES ('min_income', 'Minimum income', 'min_income', 'contract', 3)");

        // --- 25 ---
        $catalog = new PdoRuleCatalog($pdo, columns: PdoRuleCatalog::COLUMNS_SINCE_2_13);
        $editor = new RuleCatalogEditor(PdoRuleCatalogStore::forCatalog($pdo, $catalog));

        $v1 = $editor->create(
            ['code' => 'max_rent', 'name' => 'Maximum rent', 'rule_type' => 'max_rent', 'scope' => 'contract', 'int_value' => 5000],
            'Ceiling approved by the credit committee',
            actor: 'ana'
        );
        $v2 = $editor->update('max_rent', ['int_value' => 6000], 'Ceiling raised after the market review', actor: 'bia', expectedVersion: $v1->version);

        $r1 = $v2->version;                                                   // 2
        $r2 = $v2->changedFields;                                             // ['int_value']
        $r3 = $editor->revision('max_rent', 1)?->definition()->getIntValue(); // 5000: version 1 as it was
        $r4 = $v2->isIntact();                                                // true: the hash is the hash of the photo
        $r5 = $catalog->findRuleByCode('max_rent')?->getVersion();            // 2

        try {
            $editor->update('max_rent', ['int_value' => 7000], 'A screen opened before the last change', expectedVersion: 1);
        } catch (StaleRuleChangeException $e) {
            $r6 = $e->getMessage(); // Rule "max_rent" changed since it was read (version 1, now 2): reload and try again.
        }
        // --- /25 ---

        $this->assertEquals([2, ['int_value'], 5000, true, 2], [$r1, $r2, $r3, $r4, $r5], 'exemplo 25');
        $this->assertEquals('Rule "max_rent" changed since it was read (version 1, now 2): reload and try again.', $r6 ?? null, 'exemplo 25: a tela velha');

        // --- 25.1 ---
        $adopted = $editor->adopt(null, 'Rules of the catalog before the audited path');
        $r1 = array_map(static fn(RuleRevision $baseline): string => $baseline->code . '@' . $baseline->version, $adopted); // ['min_income@1']

        $pdo->exec("UPDATE business_rule SET int_value = 4 WHERE code = 'min_income'"); // an UPDATE by hand
        $r2 = array_map(static fn(RuleDrift $drift): string => $drift->code . ':' . $drift->kind->value, $editor->findDrift()); // ['min_income:modified']
        try {
            $editor->update('min_income', ['int_value' => 5], 'Raise the minimum income');
        } catch (RuleCatalogDriftException $e) {
            $r3 = $e->getDrift()->kind; // RuleDriftKind::Modified: the row is not the photo of its version
        }
        $pdo->exec("UPDATE business_rule SET version = version + 1 WHERE code = 'min_income'"); // the change by hand gets its own version
        $r4 = $editor->update('min_income', ['int_value' => 5], 'Raise the minimum income')->version; // 3: the baseline of version 2 is written first
        // --- /25.1 ---

        $this->assertEquals([['min_income@1'], ['min_income:modified'], RuleDriftKind::Modified, 3], [$r1, $r2, $r3 ?? null, $r4], 'exemplo 25.1');
        $this->assertEquals(['3:update', '2:baseline', '1:baseline'], array_map(static fn(RuleRevision $r): string => $r->version . ':' . $r->kind->value, $editor->history('min_income')), 'exemplo 25.1: o histórico');

        // --- 25.2 ---
        $favourable = ['credit_profile' => [3]]; // the application's legal opinions: rule code => versions with a favourable one
        $guarded = $editor->withChangeGuard(
            Spec::specify(RuleChangeCandidate::class)->must(
                static fn(RuleChangeCandidate $change): bool => $change->kind !== RuleChangeKind::Activate
                    || in_array($change->toVersion, $favourable[$change->code] ?? [], true),
                'LEGAL_OPINION_REQUIRED',
                'This version of the rule has no favourable legal opinion'
            )
        );

        $guarded->create(['code' => 'credit_profile', 'name' => 'Credit profile', 'rule_type' => 'profile', 'scope' => 'contract', 'active' => false], 'Drafted by the risk team');
        try {
            $guarded->setActive('credit_profile', true, 'Go live before the opinion');
        } catch (RuleChangeRefusedException $e) {
            $r1 = $e->getResult()->getCodes(); // ['LEGAL_OPINION_REQUIRED']: nothing was written
        }
        $guarded->update('credit_profile', ['description' => 'Score of 600 or more'], 'Wording asked by the legal team');
        $r2 = $guarded->setActive('credit_profile', true, 'Favourable opinion for version 3')->version; // 3
        // --- /25.2 ---

        $this->assertEquals([['LEGAL_OPINION_REQUIRED'], 3], [$r1, $r2], 'exemplo 25.2');
        $this->assertCount(3, $editor->history('credit_profile'), 'exemplo 25.2: a recusa não gravou');

        // --- 25.3 ---
        $registry = new RuleSpecificationRegistry();
        $registry->registerClosure('max_rent', static fn($rule): ISpecification => Spec::property('rent', Spec::lessThanOrEqualTo($rule->getIntValue())));
        $registry->registerClosure('min_income', static fn($rule): ISpecification => Spec::property('income', Spec::greaterThanOrEqualTo($rule->getIntValue() * 1000)));
        $registry->registerClosure('profile', static fn($rule): ISpecification => Spec::property('score', Spec::greaterThanOrEqualTo(600)));
        $engine = new DynamicSpecificationEngine($catalog, $registry);

        $verdict = $engine->validate(['rent' => 6500, 'income' => 9000, 'score' => 720], 'contract');
        $r1 = $verdict->getBlockingFailures()[0]->metadata['rule_version']; // 2: max_rent failed in its version 2
        $r2 = array_map(
            static fn(IVersionedRuleDefinition $rule): string => $rule->getCode() . '@' . $rule->getVersion(),
            $engine->getCompiledRules()
        ); // ['credit_profile@3', 'max_rent@2', 'min_income@3']: every rule that applied, the satisfied ones too
        // --- /25.3 ---

        $this->assertEquals([2, ['credit_profile@3', 'max_rent@2', 'min_income@3']], [$r1, $r2], 'exemplo 25.3');
    }

    // =====================================================================
    // Support
    // =====================================================================

    private function assertNoSecretInMessages(): void
    {
        $this->assertTrue(count($this->messages) > 40, 'mensagens coletadas');
        foreach ($this->messages as $message) {
            $this->assertFalse(str_contains($message, self::SECRET) || str_contains($message, self::REASON), 'RN-05: a mensagem não repete valor nem motivo: ' . $message);
        }
    }

    /** A rule with every kind of value (D5). */
    private static function golden(): array
    {
        return [
            'code' => 'golden', 'name' => 'Golden', 'rule_type' => 'max_rent', 'violation_action' => 'warn',
            'description' => 'Teto do aluguel / ç', 'int_value' => 5000, 'decimal_value' => '12.5', 'legal_basis' => 'Lei 8.245/91, art. 43',
            'priority' => 3, 'scope' => 'contract', 'parameters' => ['b' => 'c/d', 'a' => ['y' => [3, 1], 'x' => 1.0]],
            'product_code' => 'P1', 'plan_code' => 'G1', 'valid_from' => '2026-01-01', 'valid_until' => '2026-12-31',
        ];
    }

    /**
     * The oracle of the test: the canonical row of given values, computed without the library.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function oracleRow(array $values): array
    {
        $row = $values + ['violation_action' => 'block', 'priority' => 0, 'active' => true, 'version' => 1];
        foreach (RuleSnapshot::COLUMNS as $column) {
            $row[$column] ??= null;
        }
        $row['parameters'] = $row['parameters'] === null || $row['parameters'] === [] ? null : self::sortKeys($row['parameters']);
        ksort($row, SORT_STRING);

        return $row;
    }

    private static function sortKeys(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortKeys($item);
            }
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    private static function oracleSnapshot(array $row): string
    {
        ksort($row, SORT_STRING);

        return json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private static function oracleDefinition(array $row): RuleDefinition
    {
        return RuleDefinition::fromArray(['parameters' => $row['parameters'] ?? []] + $row);
    }

    private static function withoutVersion(array $row): array
    {
        unset($row['version']);

        return $row;
    }

    /** A canonical row in the values a database holds (`active` as 'Y'/'N', parameters as JSON text). */
    private static function stored(array $row): array
    {
        $row['active'] = $row['active'] ? 'Y' : 'N';
        $row['parameters'] = $row['parameters'] === null ? null : json_encode($row['parameters'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

        return $row;
    }

    private static function revisionKey(?RuleRevision $revision): string
    {
        return $revision === null ? 'null' : implode('|', [$revision->code, $revision->version, $revision->kind->value, $revision->snapshot, $revision->snapshotHash, implode(',', $revision->changedFields), $revision->reason, $revision->origin, $revision->changedAt->format('Y-m-d H:i:s.u e'), (string) $revision->changedBy, (string) $revision->changeId]);
    }

    /**
     * @param list<RuleDrift> $drifts
     * @return list<string>
     */
    private static function driftKeys(array $drifts): array
    {
        return array_map(static fn(RuleDrift $d): string => sprintf('%s:%s:%s:%s', $d->code, $d->kind->value, $d->rowVersion ?? '-', $d->historyVersion ?? '-'), $drifts);
    }

    /** A clock that moves one second and one microsecond per change, from 2026-11-11 09:00 UTC. */
    private function clock(): Closure
    {
        return function (): DateTimeImmutable {
            $this->tick++;

            return (new DateTimeImmutable('2026-11-11 09:00:00.000000', new DateTimeZone('America/Sao_Paulo')))
                ->modify(sprintf('+%d seconds +%d microseconds', $this->tick, $this->tick));
        };
    }

    /**
     * @return array{store: IRuleCatalogStore, seed: Closure, tamper: Closure, bump: Closure, delete: Closure, pdo: ?PDO}
     */
    private function memoryEnvironment(): array
    {
        $store = new InMemoryRuleCatalogStore();

        return [
            'store' => $store,
            'seed' => static fn(array $row) => $store->put($row),
            'tamper' => static function (string $code, string $column, mixed $value) use ($store): void {
                $store->put([$column => $value] + $store->findRow($code));
            },
            'bump' => static function (string $code) use ($store): void {
                $row = $store->findRow($code);
                $store->put(['version' => $row['version'] + 1] + $row);
            },
            'delete' => static fn(string $code) => $store->remove($code),
            'pdo' => null,
        ];
    }

    /**
     * @return array{store: IRuleCatalogStore, seed: Closure, tamper: Closure, bump: Closure, delete: Closure, pdo: ?PDO}
     */
    private static function pdoEnvironment(PDO $pdo): array
    {
        $catalog = new PdoRuleCatalog($pdo, columns: [...PdoRuleCatalog::COLUMNS_SINCE_2_4, ...PdoRuleCatalog::COLUMNS_SINCE_2_13]);

        return [
            'store' => PdoRuleCatalogStore::forCatalog($pdo, $catalog),
            'seed' => static function (array $row) use ($pdo): void {
                $row = array_filter($row, static fn($v): bool => $v !== null);
                $statement = $pdo->prepare(sprintf('INSERT INTO business_rule (%s) VALUES (%s)', implode(', ', array_keys($row)), implode(', ', array_fill(0, count($row), '?'))));
                $statement->execute(array_values($row));
            },
            'tamper' => static fn(string $code, string $column, mixed $value) => $pdo->prepare("UPDATE business_rule SET {$column} = ? WHERE code = ?")->execute([$value, $code]),
            'bump' => static fn(string $code) => $pdo->prepare('UPDATE business_rule SET version = version + 1 WHERE code = ?')->execute([$code]),
            'delete' => static fn(string $code) => $pdo->prepare('DELETE FROM business_rule WHERE code = ?')->execute([$code]),
            'pdo' => $pdo,
        ];
    }

    /**
     * The two statements of the migration 2.13.0 of the header of the reference DDL.
     *
     * @return array{0: string, 1: string}
     */
    private static function migration(): array
    {
        $ddl = (string) file_get_contents(self::DDL);
        preg_match('/^--   (ALTER TABLE business_rule ADD COLUMN version [^;]+);/m', $ddl, $alter);
        preg_match('/^--   (CREATE TABLE IF NOT EXISTS business_rule_change \(.*?\n--   \));/ms', $ddl, $create);
        if (!isset($alter[1], $create[1])) {
            throw new RuntimeException('the migration 2.13.0 is not in the header of the DDL');
        }

        return [$alter[1], (string) preg_replace('/^--   /m', '', $create[1])];
    }

    private static function resetSchema(PDO $pdo): void
    {
        self::dropSchema($pdo);
        self::loadDdl($pdo, (string) file_get_contents(self::DDL));
        foreach (self::migration() as $statement) {
            $pdo->exec($statement);
        }
    }

    private static function dropSchema(PDO $pdo): void
    {
        foreach (['business_rule_change', 'document_requirement', 'document_requirement_group', 'business_rule'] as $table) {
            $pdo->exec('DROP TABLE IF EXISTS ' . $table);
        }
    }

    /** Runs a DDL script: drops `--` comment lines and executes each `;`-terminated statement. */
    private static function loadDdl(PDO $pdo, string $ddl): void
    {
        $lines = array_filter(explode("\n", $ddl), static fn(string $line): bool => !str_starts_with(ltrim($line), '--'));
        foreach (explode(';', implode("\n", $lines)) as $statement) {
            if (trim($statement) !== '') {
                $pdo->exec($statement);
            }
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
