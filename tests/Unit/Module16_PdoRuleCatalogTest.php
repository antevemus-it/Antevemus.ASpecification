<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\Contracts\Engine\IDocumentRuleDefinition;
use Antevemus\ASpecification\Contracts\Engine\IRuleCatalog;
use Antevemus\ASpecification\Contracts\Engine\IRuleDefinition;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Engine\DocumentRuleDefinition;
use Antevemus\ASpecification\Engine\DynamicSpecificationEngine;
use Antevemus\ASpecification\Engine\Exceptions\RuleEngineException;
use Antevemus\ASpecification\Engine\InMemoryRuleCatalog;
use Antevemus\ASpecification\Engine\PdoRuleCatalog;
use Antevemus\ASpecification\Engine\RuleAction;
use Antevemus\ASpecification\Engine\RuleDefinition;
use Antevemus\ASpecification\Engine\RuleEngineVerdict;
use Antevemus\ASpecification\Engine\RuleSpecificationRegistry;
use Antevemus\ASpecification\Results\SpecificationFailure;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Tests\TestCase;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

/**
 * Module16_PdoRuleCatalogTest - Catálogo Relacional de Regras (PdoRuleCatalog)
 *
 * Forward 017 RN-06 (decisão D6 a, "espelho fiel"): o DDL de referência
 * `resources/sql/rule_catalog.ansi.sql` roda num SQLite em memória e o PdoRuleCatalog responde
 * exatamente como o InMemoryRuleCatalog alimentado com as mesmas linhas: mesmas regras, mesmos
 * documentos, mesma ordem e o mesmo veredito do DynamicSpecificationEngine (códigos, mensagens,
 * triagem por ação e severidade).
 *
 * Forward 022 (1.7.0): o DDL de referência passou ao inglês (`business_rule`,
 * `document_requirement_group`, `document_requirement`). O DDL português da 1.6.1 vive só como
 * fixture (`tests/Fixtures/rule_catalog.legacy-pt-1.6.1.ansi.sql`) e prova que os presets
 * `legacyPortugueseTables()`/`legacyPortugueseColumns()` leem o esquema antigo sem migração, com o
 * mesmo veredito do esquema novo.
 *
 * @version    1.7.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Unit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class Module16_PdoRuleCatalogTest extends TestCase
{
    private const DDL = __DIR__ . '/../../resources/sql/rule_catalog.ansi.sql';

    /** Portuguese reference DDL of 1.6.1, kept only as a test fixture (forward 022 RN-06/RN-07). */
    private const LEGACY_DDL = __DIR__ . '/../Fixtures/rule_catalog.legacy-pt-1.6.1.ansi.sql';

    public function run(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            fwrite(STDOUT, "    [AVISO] pdo_sqlite ausente; testes do PdoRuleCatalog pulados.\n");
            $this->assertTrue(true);
            return;
        }

        $this->testReferenceDdlLoadsInSqlite();
        $this->testConstructorValidatesIdentifiers();
        $this->testFindRulesMirrorsInMemoryContract();
        $this->testFindDocumentRulesJoinsGroupAndType();
        $this->testEngineVerdictIsEquivalentToInMemoryCatalog();
        $this->testWithAsOfFiltersValidityInSql();
        $this->testFiltersOutsideTheWhitelistNeverReachSql();
        $this->testParametersJsonIsDecodedAndValidated();
        $this->testSchemaAndTableNamesAreConfigurable();
        $this->testColumnMapIsValidatedAndApplied();
        $this->testLegacyPortugueseSchemaGivesTheSameVerdict();
        $this->testReadmeStyleExample();
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /**
     * Rule rows as stored in the table (parameters as JSON text). Kept in `code` order so that the
     * in-memory catalog breaks priority ties the same way as the SQL `ORDER BY priority DESC, code`.
     *
     * @return list<array<string, mixed>>
     */
    private static function ruleRows(): array
    {
        $base = [
            'description' => null, 'int_value' => null, 'decimal_value' => null, 'text_value' => null,
            'legal_basis' => null, 'violation_message' => null, 'condition_expression' => null,
            'scope' => 'contrato_locacao', 'scenario' => null, 'parameters' => null,
            'product_code' => null, 'plan_code' => null,
            'valid_from' => null, 'valid_until' => null, 'active' => 'Y',
        ];

        $rows = [
            ['code' => 'aluguel_maximo_plano_a1', 'name' => 'Aluguel máximo do plano A1', 'rule_type' => 'aluguel_maximo_aceito',
                'violation_action' => 'warn', 'decimal_value' => 3000, 'priority' => 65,
                'product_code' => 'PROD_A', 'plan_code' => 'PLANO_A1', 'violation_message' => 'Aluguel acima do teto do plano A1.'],
            ['code' => 'aluguel_maximo_produto_a', 'name' => 'Aluguel máximo do produto A', 'rule_type' => 'aluguel_maximo_aceito',
                'violation_action' => 'block', 'decimal_value' => 5000.5, 'priority' => 60, 'product_code' => 'PROD_A'],
            ['code' => 'auditoria_temporada', 'name' => 'Auditoria de temporada', 'rule_type' => 'registro_auditoria',
                'violation_action' => 'log', 'priority' => 70, 'legal_basis' => 'Lei 8.245/91 Art. 49'],
            ['code' => 'empate_a', 'name' => 'Empate A', 'rule_type' => 'registro_auditoria', 'violation_action' => 'log', 'priority' => 10],
            ['code' => 'empate_b', 'name' => 'Empate B', 'rule_type' => 'registro_auditoria', 'violation_action' => 'log', 'priority' => 10],
            ['code' => 'global_art43_ii_dupla', 'name' => 'Art. 43, II — Proibição de Dupla Garantia',
                'rule_type' => 'valida_ausencia_dupla_garantia_art43_ii', 'violation_action' => 'block', 'priority' => 90,
                'legal_basis' => 'Lei 8.245/91 Art. 43, II', 'violation_message' => 'Dupla garantia (Art. 43, II) — contrato não pode ser ativado.'],
            ['code' => 'global_expira_comp_residencia_90', 'name' => 'Comprovante de Residência expira em 90 dias',
                'rule_type' => 'expirar_documento_apos_dias', 'violation_action' => 'warn', 'int_value' => 90,
                'text_value' => 'comp_residencia_locatario', 'priority' => 80, 'violation_message' => 'Comprovante de residência com mais de 90 dias.'],
            ['code' => 'global_max_ocorrencias_3', 'name' => 'Máximo 3 ocorrências por contrato', 'rule_type' => 'max_ocorrencias_por_contrato',
                'violation_action' => 'block', 'int_value' => 3, 'priority' => 100, 'scope' => null,
                'violation_message' => 'Este contrato já possui 3 ocorrências em aberto.'],
            ['code' => 'limite_parametrizado_ativacao', 'name' => 'Limite de fiadores na ativação', 'rule_type' => 'limite_parametrizado',
                'violation_action' => 'block', 'priority' => 50, 'scenario' => 'contrato_locacao_ativacao',
                'parameters' => '{"campo": "fiadores", "limite": 2}'],
            ['code' => 'regra_cenario_outro', 'name' => 'Regra da renovação', 'rule_type' => 'max_ocorrencias_por_contrato',
                'violation_action' => 'block', 'int_value' => 0, 'priority' => 30, 'scenario' => 'contrato_locacao_renovacao'],
            ['code' => 'regra_inativa', 'name' => 'Regra inativa', 'rule_type' => 'max_ocorrencias_por_contrato',
                'violation_action' => 'block', 'int_value' => 0, 'priority' => 200, 'active' => 'N'],
            ['code' => 'regra_sinistro', 'name' => 'Regra do sinistro', 'rule_type' => 'max_ocorrencias_por_contrato',
                'violation_action' => 'block', 'int_value' => 0, 'priority' => 100, 'scope' => 'sinistro'],
            ['code' => 'regra_vigencia_2026h1', 'name' => 'Regra do primeiro semestre', 'rule_type' => 'max_ocorrencias_por_contrato',
                'violation_action' => 'block', 'int_value' => 1, 'priority' => 40,
                'valid_from' => '2026-01-01', 'valid_until' => '2026-06-30'],
        ];

        return array_map(static fn(array $row): array => array_merge($base, $row), $rows);
    }

    /** @return list<array<string, mixed>> */
    private static function groupRows(): array
    {
        return [
            ['id' => 1, 'code' => 'contrato_locacao_ativacao', 'scope' => 'contrato_locacao', 'scenario' => 'contrato_locacao_ativacao', 'sort_order' => 10, 'active' => 'Y'],
            ['id' => 2, 'code' => 'contrato_locacao_base', 'scope' => 'contrato_locacao', 'scenario' => null, 'sort_order' => 5, 'active' => 'Y'],
            ['id' => 3, 'code' => 'sinistro_ocupado', 'scope' => 'sinistro', 'scenario' => 'sinistro:ocupado', 'sort_order' => 20, 'active' => 'Y'],
            ['id' => 4, 'code' => 'grupo_inativo', 'scope' => 'contrato_locacao', 'scenario' => null, 'sort_order' => 1, 'active' => 'N'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function groupTypeRows(): array
    {
        $base = ['alternative_set_code' => null, 'condition_expression' => null, 'requirement_mode' => 'all', 'active' => 'Y'];
        $rows = [
            ['id' => 1, 'group_id' => 1, 'document_type_code' => 'cnpj_imobiliaria', 'sort_order' => 10],
            ['id' => 2, 'group_id' => 1, 'document_type_code' => 'declaracao_art43', 'sort_order' => 20, 'condition_expression' => 'tipo_locacao<>residencial_temporada'],
            ['id' => 3, 'group_id' => 1, 'document_type_code' => 'cpf_locatario', 'sort_order' => 50, 'requirement_mode' => 'any', 'alternative_set_code' => 'identidade'],
            ['id' => 4, 'group_id' => 1, 'document_type_code' => 'rg_locatario', 'sort_order' => 60, 'requirement_mode' => 'any', 'alternative_set_code' => 'identidade'],
            ['id' => 5, 'group_id' => 1, 'document_type_code' => 'tipo_inativo', 'sort_order' => 70, 'active' => 'N'],
            ['id' => 6, 'group_id' => 2, 'document_type_code' => 'contrato_locacao_assinado', 'sort_order' => 10],
            ['id' => 7, 'group_id' => 3, 'document_type_code' => 'declaracao_debitos', 'sort_order' => 10],
            ['id' => 8, 'group_id' => 4, 'document_type_code' => 'doc_de_grupo_inativo', 'sort_order' => 10],
        ];

        return array_map(static fn(array $row): array => array_merge($base, $row), $rows);
    }

    /**
     * Creates an in-memory SQLite database with the reference DDL and the fixture rows.
     */
    private static function seededPdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        self::loadDdl($pdo, (string) file_get_contents(self::DDL));

        foreach (self::ruleRows() as $row) {
            self::insert($pdo, 'business_rule', $row);
        }
        foreach (self::groupRows() as $row) {
            self::insert($pdo, 'document_requirement_group', $row);
        }
        foreach (self::groupTypeRows() as $row) {
            self::insert($pdo, 'document_requirement', $row);
        }

        return $pdo;
    }

    /**
     * Runs a DDL script: drops `--` comment lines and executes each `;`-terminated statement.
     */
    private static function loadDdl(PDO $pdo, string $ddl): void
    {
        $lines = array_filter(
            explode("\n", $ddl),
            static fn(string $line): bool => !str_starts_with(ltrim($line), '--')
        );
        foreach (explode(';', implode("\n", $lines)) as $statement) {
            if (trim($statement) !== '') {
                $pdo->exec($statement);
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function insert(PDO $pdo, string $table, array $row): void
    {
        $columns = array_keys($row);
        $statement = $pdo->prepare(sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', array_map(static fn(string $c): string => ':' . $c, $columns))
        ));
        foreach ($row as $column => $value) {
            $statement->bindValue(':' . $column, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        }
        $statement->execute();
    }

    /**
     * The same rows, fed to the in-memory catalog through the canonical hydrators.
     */
    private static function equivalentInMemoryCatalog(): InMemoryRuleCatalog
    {
        $catalog = new InMemoryRuleCatalog();
        foreach (self::ruleRows() as $row) {
            $row['parameters'] = $row['parameters'] !== null ? json_decode($row['parameters'], true) : [];
            $catalog->addRule(RuleDefinition::fromArray($row));
        }

        $groups = [];
        foreach (self::groupRows() as $group) {
            $groups[$group['id']] = $group;
        }
        $types = self::groupTypeRows();
        // Insertion order (group order, group code, type id) = SQL tie-break after the stable sort by sort_order.
        usort($types, static function (array $a, array $b) use ($groups): int {
            $ga = $groups[$a['group_id']];
            $gb = $groups[$b['group_id']];
            return [$ga['sort_order'], $ga['code'], $a['id']] <=> [$gb['sort_order'], $gb['code'], $b['id']];
        });
        foreach ($types as $type) {
            $group = $groups[$type['group_id']];
            $catalog->addDocumentRule(DocumentRuleDefinition::fromArray([
                'group_code' => $group['code'],
                'scope' => $group['scope'],
                'scenario' => $group['scenario'],
                'document_type_code' => $type['document_type_code'],
                'requirement_mode' => $type['requirement_mode'],
                'alternative_set_code' => $type['alternative_set_code'],
                'condition_expression' => $type['condition_expression'],
                'sort_order' => $type['sort_order'],
                'active' => ($group['active'] === 'Y' && $type['active'] === 'Y') ? 'Y' : 'N',
            ]));
        }

        return $catalog;
    }

    private static function registry(): RuleSpecificationRegistry
    {
        $registry = new RuleSpecificationRegistry();
        $registry->registerClosure('max_ocorrencias_por_contrato', fn(IRuleDefinition $r): ISpecification =>
            Spec::property('ocorrencias', Spec::lessThanOrEqualTo($r->getIntValue() ?? 0)));
        $registry->registerClosure('valida_ausencia_dupla_garantia_art43_ii', fn(IRuleDefinition $r): ISpecification =>
            Spec::property('garantias', Spec::lessThanOrEqualTo(1)));
        $registry->registerClosure('expirar_documento_apos_dias', fn(IRuleDefinition $r): ISpecification =>
            Spec::property('diasCompResidencia', Spec::lessThanOrEqualTo($r->getIntValue() ?? 90)));
        $registry->registerClosure('registro_auditoria', fn(IRuleDefinition $r): ISpecification =>
            Spec::property('auditado', Spec::equalTo(true)));
        $registry->registerClosure('aluguel_maximo_aceito', fn(IRuleDefinition $r): ISpecification =>
            Spec::property('aluguel', Spec::lessThanOrEqualTo($r->getDecimalValue() ?? 0.0)));
        $registry->registerClosure('limite_parametrizado', fn(IRuleDefinition $r): ISpecification =>
            Spec::property((string) $r->getParameters()['campo'], Spec::lessThanOrEqualTo($r->getParameters()['limite'])));

        return $registry;
    }

    /** @return array<string, object> */
    private static function targets(): array
    {
        return [
            'perfeito' => (object) [
                'ocorrencias' => 0, 'garantias' => 1, 'diasCompResidencia' => 30, 'aluguel' => 1000, 'auditado' => true,
                'fiadores' => 1, 'tipo_locacao' => 'residencial',
                'documentos' => ['cnpj_imobiliaria', 'declaracao_art43', 'cpf_locatario', 'contrato_locacao_assinado', 'declaracao_debitos'],
            ],
            'violador' => (object) [
                'ocorrencias' => 4, 'garantias' => 2, 'diasCompResidencia' => 120, 'aluguel' => 6000, 'auditado' => false,
                'fiadores' => 3, 'tipo_locacao' => 'residencial_temporada', 'documentos' => [],
            ],
            'intermediario' => (object) [
                'ocorrencias' => 1, 'garantias' => 1, 'diasCompResidencia' => 91, 'aluguel' => 4000, 'auditado' => false,
                'fiadores' => 2, 'tipo_locacao' => 'residencial', 'documentos' => ['cnpj_imobiliaria', 'rg_locatario', 'cpf_locatario'],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function filterCases(): array
    {
        return [
            [],
            ['product_code' => 'PROD_A'],
            ['product_code' => 'PROD_A', 'plan_code' => 'PLANO_A1'],
            ['plan_code' => 'PLANO_A1'],
            ['product_code' => 'PROD_B'],
            ['reference_date' => '2026-03-15'],
            ['reference_date' => new DateTimeImmutable('2026-07-01'), 'product_code' => 'PROD_A'],
            ['code' => 'global_art43_ii_dupla', 'scope' => 'sinistro', 'active' => 'N'],
            ['product_code' => null],
        ];
    }

    /** @return list<array{string, string|null}> */
    private static function scopeCases(): array
    {
        return [
            ['contrato_locacao', null],
            ['contrato_locacao', 'contrato_locacao_ativacao'],
            ['contrato_locacao', 'contrato_locacao_renovacao'],
            ['sinistro', null],
            ['sinistro', 'sinistro:ocupado'],
            ['inexistente', null],
        ];
    }

    /** @return list<string> */
    private static function ruleCodes(IRuleCatalog $catalog, string $scope, ?string $scenario, array $filters): array
    {
        return array_map(static fn(IRuleDefinition $r): string => $r->getCode(), $catalog->findRules($scope, $scenario, $filters));
    }

    /** @return list<string> */
    private static function documentSignatures(IRuleCatalog $catalog, string $scope, ?string $scenario): array
    {
        return array_map(
            static fn(IDocumentRuleDefinition $d): string => implode('|', [
                $d->getGroupCode(), $d->getScope(), $d->getScenario() ?? '-', $d->getDocumentTypeCode(),
                $d->getRequirementMode()->value, $d->getAlternativeSetCode() ?? '-',
                $d->getConditionExpression() ?? '-', (string) $d->getSortOrder(),
            ]),
            $catalog->findDocumentRules($scope, $scenario)
        );
    }

    /**
     * Everything a consumer reads from a verdict, in order.
     *
     * @return array<string, mixed>
     */
    private static function verdictSummary(RuleEngineVerdict $verdict): array
    {
        $codes = static fn(array $failures): array => array_map(static fn(SpecificationFailure $f): ?string => $f->code, $failures);

        return [
            'satisfied' => $verdict->isSatisfied(),
            'blocking' => $codes($verdict->getBlockingFailures()),
            'warnings' => $codes($verdict->getWarningFailures()),
            'logs' => $codes($verdict->getLogFailures()),
            'codes' => $verdict->getFailureCodes(),
            'messages' => $verdict->getMessages(),
            'legal' => $verdict->getLegalBases(),
            'severities' => array_map(
                static fn(SpecificationFailure $f): ?string => $f->getSeverity()?->value,
                $verdict->getAllFailures()
            ),
        ];
    }

    // -----------------------------------------------------------------
    // Tests
    // -----------------------------------------------------------------

    private function testReferenceDdlLoadsInSqlite(): void
    {
        $this->assertTrue(is_file(self::DDL), 'resources/sql/rule_catalog.ansi.sql deve existir');
        $ddl = (string) file_get_contents(self::DDL);
        $this->assertFalse(str_contains($ddl, '.business_rule'), 'o DDL de referência não qualifica schema');

        $pdo = self::seededPdo();
        $this->assertEquals(13, (int) $pdo->query('SELECT COUNT(*) FROM business_rule')->fetchColumn());
        $this->assertEquals(4, (int) $pdo->query('SELECT COUNT(*) FROM document_requirement_group')->fetchColumn());
        $this->assertEquals(8, (int) $pdo->query('SELECT COUNT(*) FROM document_requirement')->fetchColumn());

        // As restrições do DDL valem (espelho do módulo).
        $this->assertThrows(\PDOException::class, fn() => self::insert($pdo, 'business_rule', [
            'code' => 'acao_invalida', 'name' => 'x', 'rule_type' => 't', 'violation_action' => 'ignorar',
        ]));
        $this->assertThrows(\PDOException::class, fn() => self::insert($pdo, 'document_requirement', [
            'id' => 99, 'group_id' => 1, 'document_type_code' => 'x', 'requirement_mode' => 'any',
        ]), 'any sem set de alternativas é recusado pelo CHECK');

        // Todas as colunas lidas pelo catálogo existem no DDL.
        $columns = array_map(
            static fn(array $c): string => $c['name'],
            $pdo->query('PRAGMA table_info(business_rule)')->fetchAll(PDO::FETCH_ASSOC)
        );
        foreach (PdoRuleCatalog::RULE_COLUMNS as $column) {
            $this->assertTrue(in_array($column, $columns, true), "coluna {$column} ausente do DDL");
        }
    }

    private function testConstructorValidatesIdentifiers(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $catalog = new PdoRuleCatalog($pdo);
        $this->assertEquals(PdoRuleCatalog::DEFAULT_TABLES, $catalog->getTables());

        $qualified = new PdoRuleCatalog($pdo, 'rental_guarantee', ['rule' => 'minhas_regras']);
        $this->assertEquals([
            'rule' => 'rental_guarantee.minhas_regras',
            'group' => 'rental_guarantee.document_requirement_group',
            'requirement' => 'rental_guarantee.document_requirement',
        ], $qualified->getTables(), 'papéis ausentes mantêm o nome padrão');

        $this->assertThrows(InvalidArgumentException::class, fn() => new PdoRuleCatalog($pdo, 'x; DROP TABLE y'));
        $this->assertThrows(InvalidArgumentException::class, fn() => new PdoRuleCatalog($pdo, '', ['rule' => 'business_rule --']));
        $this->assertThrows(InvalidArgumentException::class, fn() => new PdoRuleCatalog($pdo, '', ['rule' => '"rule"']));
        $this->assertThrows(InvalidArgumentException::class, fn() => new PdoRuleCatalog($pdo, '', ['regras' => 'x']), 'papel desconhecido');
    }

    private function testFindRulesMirrorsInMemoryContract(): void
    {
        $pdoCatalog = new PdoRuleCatalog(self::seededPdo());
        $memory = self::equivalentInMemoryCatalog();

        // Expectativas explícitas (não basta os dois concordarem).
        $this->assertEquals(
            ['global_max_ocorrencias_3', 'global_art43_ii_dupla', 'global_expira_comp_residencia_90', 'auditoria_temporada',
                'aluguel_maximo_produto_a', 'limite_parametrizado_ativacao', 'regra_vigencia_2026h1', 'empate_a', 'empate_b'],
            self::ruleCodes($pdoCatalog, 'contrato_locacao', 'contrato_locacao_ativacao', ['product_code' => 'PROD_A']),
            'escopo global (NULL) + escopo + cenário + produto, por prioridade desc e código'
        );
        $this->assertEquals(
            ['global_max_ocorrencias_3', 'global_art43_ii_dupla', 'global_expira_comp_residencia_90', 'auditoria_temporada',
                'regra_vigencia_2026h1', 'empate_a', 'empate_b'],
            self::ruleCodes($pdoCatalog, 'contrato_locacao', null, []),
            'sem cenário: só globais do escopo; sem produto: regras restritas não se aplicam; inativa nunca'
        );
        $this->assertEquals(['global_max_ocorrencias_3', 'regra_sinistro'], self::ruleCodes($pdoCatalog, 'sinistro', null, []), 'empate de prioridade desfeito por código');
        $this->assertEquals(['global_max_ocorrencias_3'], self::ruleCodes($pdoCatalog, 'inexistente', null, []), 'regra de escopo nulo é global');

        // Hidratação: tipos, ação, parâmetros e colunas de aplicabilidade em parameters (RN-17).
        $byCode = [];
        foreach ($pdoCatalog->findRules('contrato_locacao', 'contrato_locacao_ativacao', ['product_code' => 'PROD_A', 'plan_code' => 'PLANO_A1']) as $rule) {
            $byCode[$rule->getCode()] = $rule;
        }
        $this->assertEquals(5000.5, $byCode['aluguel_maximo_produto_a']->getDecimalValue());
        $this->assertEquals(['product_code' => 'PROD_A'], $byCode['aluguel_maximo_produto_a']->getParameters());
        $this->assertEquals('warn', $byCode['aluguel_maximo_plano_a1']->getViolationAction()->value);
        $this->assertEquals(['campo' => 'fiadores', 'limite' => 2], $byCode['limite_parametrizado_ativacao']->getParameters());
        $this->assertEquals(['valid_from' => '2026-01-01', 'valid_until' => '2026-06-30'], $byCode['regra_vigencia_2026h1']->getParameters());
        $this->assertEquals(3, $byCode['global_max_ocorrencias_3']->getIntValue());
        $this->assertTrue($byCode['global_max_ocorrencias_3']->getScope() === null);
        $this->assertEquals('Lei 8.245/91 Art. 43, II', $byCode['global_art43_ii_dupla']->getLegalBasis());

        // Equivalência em toda a matriz escopo × cenário × filtros.
        foreach (self::scopeCases() as [$scope, $scenario]) {
            foreach (self::filterCases() as $filters) {
                $this->assertEquals(
                    self::ruleCodes($memory, $scope, $scenario, $filters),
                    self::ruleCodes($pdoCatalog, $scope, $scenario, $filters),
                    sprintf('findRules(%s, %s, %s) diverge do catálogo em memória', $scope, $scenario ?? 'null', json_encode($filters))
                );
            }
        }

        // reference_date inválida: mesmo erro de configuração do catálogo em memória.
        $this->assertThrows(InvalidArgumentException::class, fn() => $pdoCatalog->findRules('contrato_locacao', null, ['reference_date' => 'ontem à noite']));
    }

    private function testFindDocumentRulesJoinsGroupAndType(): void
    {
        $pdoCatalog = new PdoRuleCatalog(self::seededPdo());
        $memory = self::equivalentInMemoryCatalog();

        $this->assertEquals(
            [
                'contrato_locacao_base|contrato_locacao|-|contrato_locacao_assinado|all|-|-|10',
                'contrato_locacao_ativacao|contrato_locacao|contrato_locacao_ativacao|cnpj_imobiliaria|all|-|-|10',
                'contrato_locacao_ativacao|contrato_locacao|contrato_locacao_ativacao|declaracao_art43|all|-|tipo_locacao<>residencial_temporada|20',
                'contrato_locacao_ativacao|contrato_locacao|contrato_locacao_ativacao|cpf_locatario|any|identidade|-|50',
                'contrato_locacao_ativacao|contrato_locacao|contrato_locacao_ativacao|rg_locatario|any|identidade|-|60',
            ],
            self::documentSignatures($pdoCatalog, 'contrato_locacao', 'contrato_locacao_ativacao'),
            'join grupo ⋈ tipo; grupo e tipo inativos ficam de fora; ordem do tipo'
        );
        $this->assertEquals(
            ['contrato_locacao_base|contrato_locacao|-|contrato_locacao_assinado|all|-|-|10'],
            self::documentSignatures($pdoCatalog, 'contrato_locacao', null),
            'sem cenário: só documentos globais do escopo'
        );
        $this->assertEquals([], self::documentSignatures($pdoCatalog, 'sinistro', null), 'documento de cenário não vaza para a validação sem cenário');
        $this->assertCount(1, $pdoCatalog->findDocumentRules('sinistro', 'sinistro:ocupado'));
        $this->assertEquals([], self::documentSignatures($pdoCatalog, 'endosso', 'sinistro:ocupado'), 'documentos nunca vazam entre escopos');

        foreach (self::scopeCases() as [$scope, $scenario]) {
            $this->assertEquals(
                self::documentSignatures($memory, $scope, $scenario),
                self::documentSignatures($pdoCatalog, $scope, $scenario),
                sprintf('findDocumentRules(%s, %s) diverge do catálogo em memória', $scope, $scenario ?? 'null')
            );
        }
    }

    /**
     * Cenário de aceitação do forward 017 (RN-06): o mesmo conjunto de regras no InMemoryRuleCatalog
     * e no PdoRuleCatalog produz o mesmo veredito do DynamicSpecificationEngine, na mesma ordem.
     */
    private function testEngineVerdictIsEquivalentToInMemoryCatalog(): void
    {
        $registry = self::registry();
        $fromDatabase = new DynamicSpecificationEngine(new PdoRuleCatalog(self::seededPdo()), $registry);
        $fromMemory = new DynamicSpecificationEngine(self::equivalentInMemoryCatalog(), $registry);

        $nonTrivial = 0;
        foreach (self::targets() as $name => $target) {
            foreach (self::scopeCases() as [$scope, $scenario]) {
                foreach (self::filterCases() as $filters) {
                    $expected = self::verdictSummary($fromMemory->validate($target, $scope, $scenario, $filters));
                    $actual = self::verdictSummary($fromDatabase->validate($target, $scope, $scenario, $filters));
                    $this->assertEquals($expected, $actual, sprintf(
                        'veredito diverge para %s em (%s, %s, %s)',
                        $name,
                        $scope,
                        $scenario ?? 'null',
                        json_encode($filters)
                    ));
                    if ($actual['blocking'] !== [] && $actual['warnings'] !== [] && $actual['logs'] !== []) {
                        $nonTrivial++;
                    }
                }
            }
        }
        $this->assertTrue($nonTrivial > 0, 'a matriz precisa exercitar vereditos com bloqueio, alerta e log ao mesmo tempo');

        // Um caso concreto, com a ordem de prioridade e as severidades esperadas.
        $verdict = $fromDatabase->validate(self::targets()['violador'], 'contrato_locacao', 'contrato_locacao_ativacao', ['product_code' => 'PROD_A']);
        $summary = self::verdictSummary($verdict);
        $this->assertEquals(
            ['global_max_ocorrencias_3', 'global_art43_ii_dupla', 'global_expira_comp_residencia_90', 'auditoria_temporada',
                'aluguel_maximo_produto_a', 'limite_parametrizado_ativacao', 'regra_vigencia_2026h1', 'empate_a', 'empate_b',
                'DOC_CONTRATO_LOCACAO_ASSINADO', 'DOC_CNPJ_IMOBILIARIA', 'DOC_SET_IDENTIDADE'],
            $summary['codes']
        );
        $this->assertEquals(['global_expira_comp_residencia_90'], $summary['warnings']);
        $this->assertEquals(['auditoria_temporada', 'empate_a', 'empate_b'], $summary['logs']);
        $this->assertEquals(
            ['error', 'error', 'warning', 'info', 'error', 'error', 'error', 'info', 'info', 'error', 'error', 'error'],
            $summary['severities']
        );
        $this->assertTrue($verdict->hasBlockingErrors());
    }

    private function testWithAsOfFiltersValidityInSql(): void
    {
        $catalog = new PdoRuleCatalog(self::seededPdo());
        $this->assertTrue($catalog->getAsOf() === null);

        $march = $catalog->withAsOf(new DateTimeImmutable('2026-03-15 18:00'));
        $this->assertTrue($march !== $catalog && $catalog->getAsOf() === null, 'withAsOf() devolve cópia, o original não muda');
        $this->assertTrue(in_array('regra_vigencia_2026h1', self::ruleCodes($march, 'contrato_locacao', null, []), true));

        $july = $catalog->withAsOf(new DateTimeImmutable('2026-07-01'));
        $this->assertFalse(in_array('regra_vigencia_2026h1', self::ruleCodes($july, 'contrato_locacao', null, []), true), 'fora da vigência');

        $lastDay = $catalog->withAsOf(new DateTimeImmutable('2026-06-30 23:59:59'));
        $this->assertTrue(in_array('regra_vigencia_2026h1', self::ruleCodes($lastDay, 'contrato_locacao', null, []), true), 'fim inclusivo, por dia');

        $firstDay = $catalog->withAsOf(new DateTimeImmutable('2026-01-01'));
        $this->assertTrue(in_array('regra_vigencia_2026h1', self::ruleCodes($firstDay, 'contrato_locacao', null, []), true), 'início inclusivo');

        // Sem withAsOf a linha chega com as datas e o contrato em memória decide por reference_date.
        $this->assertTrue(in_array('regra_vigencia_2026h1', self::ruleCodes($catalog, 'contrato_locacao', null, []), true));
        $this->assertFalse(in_array('regra_vigencia_2026h1', self::ruleCodes($catalog, 'contrato_locacao', null, ['reference_date' => '2026-07-01']), true));
    }

    private function testFiltersOutsideTheWhitelistNeverReachSql(): void
    {
        $catalog = new PdoRuleCatalog(self::seededPdo());
        $memory = self::equivalentInMemoryCatalog();

        $hostile = [
            'product_code' => "PROD_A' OR '1'='1",
            "plan_code = plan_code OR 1=1 --" => 'x',
            'name' => 'qualquer',
        ];
        $this->assertEquals(
            self::ruleCodes($memory, 'contrato_locacao', null, $hostile),
            self::ruleCodes($catalog, 'contrato_locacao', null, $hostile),
            'valor vai por parâmetro e chave fora da lista branca é ignorada'
        );
        $this->assertFalse(
            in_array('aluguel_maximo_produto_a', self::ruleCodes($catalog, 'contrato_locacao', null, $hostile), true),
            'o valor hostil não casa com produto nenhum'
        );
        $this->assertEquals(['product_code', 'plan_code'], PdoRuleCatalog::FILTER_COLUMNS);
    }

    private function testParametersJsonIsDecodedAndValidated(): void
    {
        $pdo = self::seededPdo();
        $catalog = new PdoRuleCatalog($pdo);

        // Chaves de aplicabilidade dentro do JSON são descartadas: a coluna é a fonte.
        $pdo->exec("UPDATE business_rule SET parameters = '{\"limite\": 2, \"campo\": \"fiadores\", \"product_code\": \"PROD_Z\"}' WHERE code = 'limite_parametrizado_ativacao'");
        $rules = $catalog->findRules('contrato_locacao', 'contrato_locacao_ativacao');
        $limit = array_values(array_filter($rules, static fn(IRuleDefinition $r): bool => $r->getCode() === 'limite_parametrizado_ativacao'));
        $this->assertCount(1, $limit, 'a chave product_code do JSON não restringe a regra');
        $this->assertEquals(['limite' => 2, 'campo' => 'fiadores'], $limit[0]->getParameters());

        $pdo->exec("UPDATE business_rule SET parameters = '' WHERE code = 'limite_parametrizado_ativacao'");
        $this->assertCount(9, $catalog->findRules('contrato_locacao', 'contrato_locacao_ativacao', ['product_code' => 'PROD_A']), 'parameters vazio = sem parâmetros');

        $pdo->exec("UPDATE business_rule SET parameters = '{nao e json' WHERE code = 'limite_parametrizado_ativacao'");
        $error = $this->assertThrows(RuleEngineException::class, fn() => $catalog->findRules('contrato_locacao', 'contrato_locacao_ativacao'));
        $this->assertTrue(str_contains($error->getMessage(), 'limite_parametrizado_ativacao'), $error->getMessage());

        $pdo->exec("UPDATE business_rule SET parameters = '[1, 2]' WHERE code = 'limite_parametrizado_ativacao'");
        $this->assertThrows(RuleEngineException::class, fn() => $catalog->findRules('contrato_locacao', 'contrato_locacao_ativacao'), 'lista JSON não é objeto');

        // Erro do driver vira RuleEngineException com a causa preservada.
        $missing = new PdoRuleCatalog($pdo, '', ['rule' => 'tabela_inexistente']);
        $driverError = $this->assertThrows(RuleEngineException::class, fn() => $missing->findRules('contrato_locacao'));
        $this->assertInstanceOf(\PDOException::class, $driverError->getPrevious());
    }

    private function testSchemaAndTableNamesAreConfigurable(): void
    {
        $pdo = self::seededPdo();
        $pdo->exec("ATTACH DATABASE ':memory:' AS catalogo");
        $pdo->exec('CREATE TABLE catalogo.regras AS SELECT * FROM main.business_rule');
        $pdo->exec('CREATE TABLE catalogo.grupos AS SELECT * FROM main.document_requirement_group');
        $pdo->exec('CREATE TABLE catalogo.grupo_tipos AS SELECT * FROM main.document_requirement');
        $pdo->exec("DELETE FROM catalogo.regras WHERE code <> 'global_max_ocorrencias_3'");

        $catalog = new PdoRuleCatalog($pdo, 'catalogo', ['rule' => 'regras', 'group' => 'grupos', 'requirement' => 'grupo_tipos']);
        $this->assertEquals(['global_max_ocorrencias_3'], self::ruleCodes($catalog, 'contrato_locacao', null, []), 'lê da tabela qualificada pelo schema');
        $this->assertCount(5, $catalog->findDocumentRules('contrato_locacao', 'contrato_locacao_ativacao'));
    }

    /**
     * Forward 022 RN-06: mapa coluna canônica → coluna real, validado pela mesma regex de identificador.
     */
    private function testColumnMapIsValidatedAndApplied(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $default = new PdoRuleCatalog($pdo);
        $this->assertEquals(PdoRuleCatalog::canonicalColumns(), array_keys($default->getColumns()));
        $this->assertEquals(PdoRuleCatalog::canonicalColumns(), array_values($default->getColumns()), 'sem mapa, coluna real = canônica');

        $partial = new PdoRuleCatalog($pdo, '', [], ['code' => 'rule_code', 'scope' => 'domain']);
        $this->assertEquals('rule_code', $partial->getColumns()['code']);
        $this->assertEquals('domain', $partial->getColumns()['scope']);
        $this->assertEquals('name', $partial->getColumns()['name'], 'coluna ausente do mapa mantém o nome canônico');

        $this->assertThrows(InvalidArgumentException::class, fn() => new PdoRuleCatalog($pdo, '', [], ['codigo' => 'codigo']), 'chave do mapa é a coluna canônica (inglês)');
        $this->assertThrows(InvalidArgumentException::class, fn() => new PdoRuleCatalog($pdo, '', [], ['code' => 'code; DROP TABLE x']));
        $this->assertThrows(InvalidArgumentException::class, fn() => new PdoRuleCatalog($pdo, '', [], ['code' => '"code"']));
        $this->assertThrows(InvalidArgumentException::class, fn() => new PdoRuleCatalog($pdo, '', [], ['code' => 42]));

        // Os presets só trazem identificadores válidos e colunas canônicas conhecidas.
        $legacy = new PdoRuleCatalog($pdo, 'rental_guarantee', PdoRuleCatalog::legacyPortugueseTables(), PdoRuleCatalog::legacyPortugueseColumns());
        $this->assertEquals([
            'rule' => 'rental_guarantee.regra_negocio',
            'group' => 'rental_guarantee.grupo_documento_obrigatorio',
            'requirement' => 'rental_guarantee.grupo_documento_obrigatorio_tipo',
        ], $legacy->getTables());
        $this->assertEquals('acao_ao_violar', $legacy->getColumns()['violation_action']);
        $this->assertEquals('active', $legacy->getColumns()['active'], 'active e id não mudaram de nome');

        // Papéis de tabela em português (1.6.x) continuam aceitos; o papel em inglês vence.
        $byLegacyRole = new PdoRuleCatalog($pdo, '', ['regra' => 'minhas_regras', 'grupo_tipo' => 'meus_tipos']);
        $this->assertEquals(['rule' => 'minhas_regras', 'group' => 'document_requirement_group', 'requirement' => 'meus_tipos'], $byLegacyRole->getTables());
        $both = new PdoRuleCatalog($pdo, '', ['regra' => 'perde', 'rule' => 'vence']);
        $this->assertEquals('vence', $both->getTables()['rule']);
    }

    /**
     * Forward 022 RN-06/RN-08: o DDL português da 1.6.1 (fixture), lido com legacyPortugueseTables()
     * e legacyPortugueseColumns(), com as ações gravadas em português, produz exatamente o mesmo
     * resultado do DDL novo em inglês em toda a matriz alvo × escopo × cenário × filtros.
     */
    private function testLegacyPortugueseSchemaGivesTheSameVerdict(): void
    {
        $this->assertTrue(is_file(self::LEGACY_DDL), 'a fixture do DDL português da 1.6.1 deve existir');
        $this->assertFalse(
            str_contains((string) file_get_contents(self::DDL), 'CREATE TABLE regra_negocio'),
            'o DDL de referência é um só, em inglês; o português fica só como fixture'
        );

        $toLegacy = PdoRuleCatalog::legacyPortugueseColumns();
        $legacyValues = array_flip(RuleAction::LEGACY_PORTUGUESE_VALUES);
        $translate = static function (array $row) use ($toLegacy, $legacyValues): array {
            $out = [];
            foreach ($row as $column => $value) {
                if ($column === 'violation_action' && is_string($value)) {
                    $value = $legacyValues[$value];
                }
                $out[$toLegacy[$column] ?? $column] = $value;
            }
            return $out;
        };

        $legacyPdo = new PDO('sqlite::memory:');
        $legacyPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $legacyPdo->exec('PRAGMA foreign_keys = ON');
        self::loadDdl($legacyPdo, (string) file_get_contents(self::LEGACY_DDL));
        $tables = PdoRuleCatalog::legacyPortugueseTables();
        foreach (self::ruleRows() as $row) {
            self::insert($legacyPdo, $tables['rule'], $translate($row));
        }
        foreach (self::groupRows() as $row) {
            self::insert($legacyPdo, $tables['group'], $translate($row));
        }
        foreach (self::groupTypeRows() as $row) {
            self::insert($legacyPdo, $tables['requirement'], $translate($row));
        }
        $this->assertEquals('bloquear', $legacyPdo->query("SELECT acao_ao_violar FROM regra_negocio WHERE codigo = 'global_max_ocorrencias_3'")->fetchColumn(), 'a tabela antiga guarda a ação em português');

        $legacyCatalog = new PdoRuleCatalog($legacyPdo, '', PdoRuleCatalog::legacyPortugueseTables(), PdoRuleCatalog::legacyPortugueseColumns());
        $englishCatalog = new PdoRuleCatalog(self::seededPdo());
        $registry = self::registry();
        $fromLegacy = new DynamicSpecificationEngine($legacyCatalog, $registry);
        $fromEnglish = new DynamicSpecificationEngine($englishCatalog, $registry);

        foreach (self::scopeCases() as [$scope, $scenario]) {
            $this->assertEquals(
                self::documentSignatures($englishCatalog, $scope, $scenario),
                self::documentSignatures($legacyCatalog, $scope, $scenario),
                sprintf('findDocumentRules(%s, %s) diverge entre o esquema português e o inglês', $scope, $scenario ?? 'null')
            );
            foreach (self::filterCases() as $filters) {
                $this->assertEquals(
                    self::ruleCodes($englishCatalog, $scope, $scenario, $filters),
                    self::ruleCodes($legacyCatalog, $scope, $scenario, $filters),
                    sprintf('findRules(%s, %s, %s) diverge entre os esquemas', $scope, $scenario ?? 'null', json_encode($filters))
                );
                foreach (self::targets() as $name => $target) {
                    $this->assertEquals(
                        self::verdictSummary($fromEnglish->validate($target, $scope, $scenario, $filters)),
                        self::verdictSummary($fromLegacy->validate($target, $scope, $scenario, $filters)),
                        sprintf('veredito de %s diverge entre os esquemas em (%s, %s, %s)', $name, $scope, $scenario ?? 'null', json_encode($filters))
                    );
                }
            }
        }

        // Hidratação pelo esquema antigo: valores canônicos, ação em inglês, aplicabilidade em inglês.
        $byCode = [];
        foreach ($legacyCatalog->findRules('contrato_locacao', 'contrato_locacao_ativacao', ['product_code' => 'PROD_A', 'plan_code' => 'PLANO_A1']) as $rule) {
            $byCode[$rule->getCode()] = $rule;
        }
        $this->assertEquals('warn', $byCode['aluguel_maximo_plano_a1']->getViolationAction()->value);
        $this->assertEquals(['product_code' => 'PROD_A', 'plan_code' => 'PLANO_A1'], $byCode['aluguel_maximo_plano_a1']->getParameters());
        $this->assertEquals(['campo' => 'fiadores', 'limite' => 2], $byCode['limite_parametrizado_ativacao']->getParameters());

        // Os filtros de contexto em português (1.6.x) ainda são lidos, no SQL e na passada em memória.
        foreach ([$legacyCatalog, $englishCatalog] as $catalog) {
            $this->assertEquals(
                self::ruleCodes($catalog, 'contrato_locacao', 'contrato_locacao_ativacao', ['product_code' => 'PROD_A', 'plan_code' => 'PLANO_A1', 'reference_date' => '2026-07-01']),
                self::ruleCodes($catalog, 'contrato_locacao', 'contrato_locacao_ativacao', ['codigo_produto' => 'PROD_A', 'codigo_plano' => 'PLANO_A1', 'data_referencia' => '2026-07-01'])
            );
        }

        // A chave de aplicabilidade em português dentro do JSON também é descartada (a coluna é a fonte).
        $legacyPdo->exec("UPDATE regra_negocio SET parametros = '{\"campo\": \"fiadores\", \"limite\": 2, \"codigo_produto\": \"PROD_Z\"}' WHERE codigo = 'limite_parametrizado_ativacao'");
        $limit = array_values(array_filter(
            $legacyCatalog->findRules('contrato_locacao', 'contrato_locacao_ativacao'),
            static fn(IRuleDefinition $r): bool => $r->getCode() === 'limite_parametrizado_ativacao'
        ));
        $this->assertCount(1, $limit);
        $this->assertEquals(['campo' => 'fiadores', 'limite' => 2], $limit[0]->getParameters());
    }

    /**
     * Transcrição do bloco do PdoRuleCatalog no exemplo 7 do README.pt-BR (o do README em inglês é o mesmo, com nomes de variável em inglês).
     */
    private function testReadmeStyleExample(): void
    {
        $pdo = self::seededPdo(); // new PDO($dsn, $user, $password) against the tables of resources/sql/rule_catalog.ansi.sql

        $registry = Spec::ruleRegistry();
        $registry->registerClosure('max_ocorrencias_por_contrato', fn($rule) =>
            Spec::property('ocorrencias', Spec::lessThanOrEqualTo($rule->getIntValue())));
        foreach (['valida_ausencia_dupla_garantia_art43_ii', 'expirar_documento_apos_dias', 'registro_auditoria', 'aluguel_maximo_aceito', 'limite_parametrizado'] as $type) {
            $registry->registerClosure($type, fn($rule) => Spec::alwaysTrue());
        }
        $contrato = (object) ['ocorrencias' => 4, 'documentos' => ['contrato_locacao_assinado']];

        // Opcional: lê só as regras vigentes numa data (janela de vigência filtrada no SQL)
        $catalogo = (new PdoRuleCatalog($pdo))->withAsOf(new DateTimeImmutable('2026-10-09'));

        // Tabelas criadas com o esquema em português da v1.5/v1.6 (regra_negocio...) continuam legíveis, sem migração
        $catalogoLegado = new PdoRuleCatalog(
            $pdo,
            'rental_guarantee',
            PdoRuleCatalog::legacyPortugueseTables(),
            PdoRuleCatalog::legacyPortugueseColumns()
        );

        $verdict = Spec::engine($catalogo, $registry)->validate(
            target: $contrato,
            scope: 'contrato_locacao',
            context: ['product_code' => 'PROD_A']
        );

        $this->assertTrue($verdict->hasBlockingErrors());
        $this->assertEquals(['global_max_ocorrencias_3'], $verdict->getFailureCodes());
        $this->assertEquals(['Este contrato já possui 3 ocorrências em aberto.'], $verdict->getMessages());
        $this->assertEquals('rental_guarantee.regra_negocio', $catalogoLegado->getTables()['rule']);
    }
}
