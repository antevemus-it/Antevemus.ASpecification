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
 * @version    1.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Unit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class Module16_PdoRuleCatalogTest extends TestCase
{
    private const DDL = __DIR__ . '/../../resources/sql/rule_catalog.ansi.sql';

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
        $this->testParametrosJsonIsDecodedAndValidated();
        $this->testSchemaAndTableNamesAreConfigurable();
        $this->testReadmeStyleExample();
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /**
     * Rule rows as stored in the table (parametros as JSON text). Kept in `codigo` order so that the
     * in-memory catalog breaks priority ties the same way as the SQL `ORDER BY prioridade DESC, codigo`.
     *
     * @return list<array<string, mixed>>
     */
    private static function ruleRows(): array
    {
        $base = [
            'descricao' => null, 'valor_inteiro' => null, 'valor_decimal' => null, 'valor_texto' => null,
            'fundamento_legal' => null, 'mensagem_violacao' => null, 'condicional_expressao' => null,
            'escopo' => 'contrato_locacao', 'cenario' => null, 'parametros' => null,
            'codigo_produto' => null, 'codigo_plano' => null,
            'data_inicio_vigencia' => null, 'data_fim_vigencia' => null, 'active' => 'Y',
        ];

        $rows = [
            ['codigo' => 'aluguel_maximo_plano_a1', 'nome' => 'Aluguel máximo do plano A1', 'tipo_regra' => 'aluguel_maximo_aceito',
                'acao_ao_violar' => 'alertar', 'valor_decimal' => 3000, 'prioridade' => 65,
                'codigo_produto' => 'PROD_A', 'codigo_plano' => 'PLANO_A1', 'mensagem_violacao' => 'Aluguel acima do teto do plano A1.'],
            ['codigo' => 'aluguel_maximo_produto_a', 'nome' => 'Aluguel máximo do produto A', 'tipo_regra' => 'aluguel_maximo_aceito',
                'acao_ao_violar' => 'bloquear', 'valor_decimal' => 5000.5, 'prioridade' => 60, 'codigo_produto' => 'PROD_A'],
            ['codigo' => 'auditoria_temporada', 'nome' => 'Auditoria de temporada', 'tipo_regra' => 'registro_auditoria',
                'acao_ao_violar' => 'apenas_log', 'prioridade' => 70, 'fundamento_legal' => 'Lei 8.245/91 Art. 49'],
            ['codigo' => 'empate_a', 'nome' => 'Empate A', 'tipo_regra' => 'registro_auditoria', 'acao_ao_violar' => 'apenas_log', 'prioridade' => 10],
            ['codigo' => 'empate_b', 'nome' => 'Empate B', 'tipo_regra' => 'registro_auditoria', 'acao_ao_violar' => 'apenas_log', 'prioridade' => 10],
            ['codigo' => 'global_art43_ii_dupla', 'nome' => 'Art. 43, II — Proibição de Dupla Garantia',
                'tipo_regra' => 'valida_ausencia_dupla_garantia_art43_ii', 'acao_ao_violar' => 'bloquear', 'prioridade' => 90,
                'fundamento_legal' => 'Lei 8.245/91 Art. 43, II', 'mensagem_violacao' => 'Dupla garantia (Art. 43, II) — contrato não pode ser ativado.'],
            ['codigo' => 'global_expira_comp_residencia_90', 'nome' => 'Comprovante de Residência expira em 90 dias',
                'tipo_regra' => 'expirar_documento_apos_dias', 'acao_ao_violar' => 'alertar', 'valor_inteiro' => 90,
                'valor_texto' => 'comp_residencia_locatario', 'prioridade' => 80, 'mensagem_violacao' => 'Comprovante de residência com mais de 90 dias.'],
            ['codigo' => 'global_max_ocorrencias_3', 'nome' => 'Máximo 3 ocorrências por contrato', 'tipo_regra' => 'max_ocorrencias_por_contrato',
                'acao_ao_violar' => 'bloquear', 'valor_inteiro' => 3, 'prioridade' => 100, 'escopo' => null,
                'mensagem_violacao' => 'Este contrato já possui 3 ocorrências em aberto.'],
            ['codigo' => 'limite_parametrizado_ativacao', 'nome' => 'Limite de fiadores na ativação', 'tipo_regra' => 'limite_parametrizado',
                'acao_ao_violar' => 'bloquear', 'prioridade' => 50, 'cenario' => 'contrato_locacao_ativacao',
                'parametros' => '{"campo": "fiadores", "limite": 2}'],
            ['codigo' => 'regra_cenario_outro', 'nome' => 'Regra da renovação', 'tipo_regra' => 'max_ocorrencias_por_contrato',
                'acao_ao_violar' => 'bloquear', 'valor_inteiro' => 0, 'prioridade' => 30, 'cenario' => 'contrato_locacao_renovacao'],
            ['codigo' => 'regra_inativa', 'nome' => 'Regra inativa', 'tipo_regra' => 'max_ocorrencias_por_contrato',
                'acao_ao_violar' => 'bloquear', 'valor_inteiro' => 0, 'prioridade' => 200, 'active' => 'N'],
            ['codigo' => 'regra_sinistro', 'nome' => 'Regra do sinistro', 'tipo_regra' => 'max_ocorrencias_por_contrato',
                'acao_ao_violar' => 'bloquear', 'valor_inteiro' => 0, 'prioridade' => 100, 'escopo' => 'sinistro'],
            ['codigo' => 'regra_vigencia_2026h1', 'nome' => 'Regra do primeiro semestre', 'tipo_regra' => 'max_ocorrencias_por_contrato',
                'acao_ao_violar' => 'bloquear', 'valor_inteiro' => 1, 'prioridade' => 40,
                'data_inicio_vigencia' => '2026-01-01', 'data_fim_vigencia' => '2026-06-30'],
        ];

        return array_map(static fn(array $row): array => array_merge($base, $row), $rows);
    }

    /** @return list<array<string, mixed>> */
    private static function groupRows(): array
    {
        return [
            ['id' => 1, 'codigo' => 'contrato_locacao_ativacao', 'escopo' => 'contrato_locacao', 'cenario' => 'contrato_locacao_ativacao', 'ordem' => 10, 'active' => 'Y'],
            ['id' => 2, 'codigo' => 'contrato_locacao_base', 'escopo' => 'contrato_locacao', 'cenario' => null, 'ordem' => 5, 'active' => 'Y'],
            ['id' => 3, 'codigo' => 'sinistro_ocupado', 'escopo' => 'sinistro', 'cenario' => 'sinistro:ocupado', 'ordem' => 20, 'active' => 'Y'],
            ['id' => 4, 'codigo' => 'grupo_inativo', 'escopo' => 'contrato_locacao', 'cenario' => null, 'ordem' => 1, 'active' => 'N'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function groupTypeRows(): array
    {
        $base = ['codigo_set_alternativas' => null, 'condicional_expressao' => null, 'regra_obrigatoriedade' => 'all', 'active' => 'Y'];
        $rows = [
            ['id' => 1, 'grupo_id' => 1, 'codigo_tipo_documento' => 'cnpj_imobiliaria', 'ordem' => 10],
            ['id' => 2, 'grupo_id' => 1, 'codigo_tipo_documento' => 'declaracao_art43', 'ordem' => 20, 'condicional_expressao' => 'tipo_locacao<>residencial_temporada'],
            ['id' => 3, 'grupo_id' => 1, 'codigo_tipo_documento' => 'cpf_locatario', 'ordem' => 50, 'regra_obrigatoriedade' => 'any', 'codigo_set_alternativas' => 'identidade'],
            ['id' => 4, 'grupo_id' => 1, 'codigo_tipo_documento' => 'rg_locatario', 'ordem' => 60, 'regra_obrigatoriedade' => 'any', 'codigo_set_alternativas' => 'identidade'],
            ['id' => 5, 'grupo_id' => 1, 'codigo_tipo_documento' => 'tipo_inativo', 'ordem' => 70, 'active' => 'N'],
            ['id' => 6, 'grupo_id' => 2, 'codigo_tipo_documento' => 'contrato_locacao_assinado', 'ordem' => 10],
            ['id' => 7, 'grupo_id' => 3, 'codigo_tipo_documento' => 'declaracao_debitos', 'ordem' => 10],
            ['id' => 8, 'grupo_id' => 4, 'codigo_tipo_documento' => 'doc_de_grupo_inativo', 'ordem' => 10],
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
            self::insert($pdo, 'regra_negocio', $row);
        }
        foreach (self::groupRows() as $row) {
            self::insert($pdo, 'grupo_documento_obrigatorio', $row);
        }
        foreach (self::groupTypeRows() as $row) {
            self::insert($pdo, 'grupo_documento_obrigatorio_tipo', $row);
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
            $row['parametros'] = $row['parametros'] !== null ? json_decode($row['parametros'], true) : [];
            $catalog->addRule(RuleDefinition::fromArray($row));
        }

        $groups = [];
        foreach (self::groupRows() as $group) {
            $groups[$group['id']] = $group;
        }
        $types = self::groupTypeRows();
        // Insertion order (group order, group code, type id) = SQL tie-break after the stable sort by ordem.
        usort($types, static function (array $a, array $b) use ($groups): int {
            $ga = $groups[$a['grupo_id']];
            $gb = $groups[$b['grupo_id']];
            return [$ga['ordem'], $ga['codigo'], $a['id']] <=> [$gb['ordem'], $gb['codigo'], $b['id']];
        });
        foreach ($types as $type) {
            $group = $groups[$type['grupo_id']];
            $catalog->addDocumentRule(DocumentRuleDefinition::fromArray([
                'grupo_codigo' => $group['codigo'],
                'escopo' => $group['escopo'],
                'cenario' => $group['cenario'],
                'codigo_tipo_documento' => $type['codigo_tipo_documento'],
                'regra_obrigatoriedade' => $type['regra_obrigatoriedade'],
                'codigo_set_alternativas' => $type['codigo_set_alternativas'],
                'condicional_expressao' => $type['condicional_expressao'],
                'ordem' => $type['ordem'],
                'active' => ($group['active'] === 'Y' && $type['active'] === 'Y') ? 'Y' : 'N',
            ]));
        }

        return $catalog;
    }

    private static function registry(): RuleSpecificationRegistry
    {
        $registry = new RuleSpecificationRegistry();
        $registry->registerClosure('max_ocorrencias_por_contrato', fn(IRuleDefinition $r): ISpecification =>
            Spec::property('ocorrencias', Spec::lessThanOrEqualTo($r->getValorInteiro() ?? 0)));
        $registry->registerClosure('valida_ausencia_dupla_garantia_art43_ii', fn(IRuleDefinition $r): ISpecification =>
            Spec::property('garantias', Spec::lessThanOrEqualTo(1)));
        $registry->registerClosure('expirar_documento_apos_dias', fn(IRuleDefinition $r): ISpecification =>
            Spec::property('diasCompResidencia', Spec::lessThanOrEqualTo($r->getValorInteiro() ?? 90)));
        $registry->registerClosure('registro_auditoria', fn(IRuleDefinition $r): ISpecification =>
            Spec::property('auditado', Spec::equalTo(true)));
        $registry->registerClosure('aluguel_maximo_aceito', fn(IRuleDefinition $r): ISpecification =>
            Spec::property('aluguel', Spec::lessThanOrEqualTo($r->getValorDecimal() ?? 0.0)));
        $registry->registerClosure('limite_parametrizado', fn(IRuleDefinition $r): ISpecification =>
            Spec::property((string) $r->getParametros()['campo'], Spec::lessThanOrEqualTo($r->getParametros()['limite'])));

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
            ['codigo_produto' => 'PROD_A'],
            ['codigo_produto' => 'PROD_A', 'codigo_plano' => 'PLANO_A1'],
            ['codigo_plano' => 'PLANO_A1'],
            ['codigo_produto' => 'PROD_B'],
            ['data_referencia' => '2026-03-15'],
            ['data_referencia' => new DateTimeImmutable('2026-07-01'), 'codigo_produto' => 'PROD_A'],
            ['codigo' => 'global_art43_ii_dupla', 'escopo' => 'sinistro', 'active' => 'N'],
            ['codigo_produto' => null],
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
    private static function ruleCodes(IRuleCatalog $catalog, string $escopo, ?string $cenario, array $filters): array
    {
        return array_map(static fn(IRuleDefinition $r): string => $r->getCodigo(), $catalog->findRules($escopo, $cenario, $filters));
    }

    /** @return list<string> */
    private static function documentSignatures(IRuleCatalog $catalog, string $escopo, ?string $cenario): array
    {
        return array_map(
            static fn(IDocumentRuleDefinition $d): string => implode('|', [
                $d->getGrupoCodigo(), $d->getEscopo(), $d->getCenario() ?? '-', $d->getCodigoTipoDocumento(),
                $d->getRegraObrigatoriedade()->value, $d->getCodigoSetAlternativas() ?? '-',
                $d->getCondicionalExpressao() ?? '-', (string) $d->getOrdem(),
            ]),
            $catalog->findDocumentRules($escopo, $cenario)
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
        $this->assertFalse(str_contains($ddl, '.regra_negocio'), 'o DDL de referência não qualifica schema');

        $pdo = self::seededPdo();
        $this->assertEquals(13, (int) $pdo->query('SELECT COUNT(*) FROM regra_negocio')->fetchColumn());
        $this->assertEquals(4, (int) $pdo->query('SELECT COUNT(*) FROM grupo_documento_obrigatorio')->fetchColumn());
        $this->assertEquals(8, (int) $pdo->query('SELECT COUNT(*) FROM grupo_documento_obrigatorio_tipo')->fetchColumn());

        // As restrições do DDL valem (espelho do módulo).
        $this->assertThrows(\PDOException::class, fn() => self::insert($pdo, 'regra_negocio', [
            'codigo' => 'acao_invalida', 'nome' => 'x', 'tipo_regra' => 't', 'acao_ao_violar' => 'ignorar',
        ]));
        $this->assertThrows(\PDOException::class, fn() => self::insert($pdo, 'grupo_documento_obrigatorio_tipo', [
            'id' => 99, 'grupo_id' => 1, 'codigo_tipo_documento' => 'x', 'regra_obrigatoriedade' => 'any',
        ]), 'any sem set de alternativas é recusado pelo CHECK');

        // Todas as colunas lidas pelo catálogo existem no DDL.
        $columns = array_map(
            static fn(array $c): string => $c['name'],
            $pdo->query('PRAGMA table_info(regra_negocio)')->fetchAll(PDO::FETCH_ASSOC)
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

        $qualified = new PdoRuleCatalog($pdo, 'rental_guarantee', ['regra' => 'minhas_regras']);
        $this->assertEquals([
            'regra' => 'rental_guarantee.minhas_regras',
            'grupo' => 'rental_guarantee.grupo_documento_obrigatorio',
            'grupo_tipo' => 'rental_guarantee.grupo_documento_obrigatorio_tipo',
        ], $qualified->getTables(), 'papéis ausentes mantêm o nome padrão');

        $this->assertThrows(InvalidArgumentException::class, fn() => new PdoRuleCatalog($pdo, 'x; DROP TABLE y'));
        $this->assertThrows(InvalidArgumentException::class, fn() => new PdoRuleCatalog($pdo, '', ['regra' => 'regra_negocio --']));
        $this->assertThrows(InvalidArgumentException::class, fn() => new PdoRuleCatalog($pdo, '', ['regra' => '"regra"']));
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
            self::ruleCodes($pdoCatalog, 'contrato_locacao', 'contrato_locacao_ativacao', ['codigo_produto' => 'PROD_A']),
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

        // Hidratação: tipos, ação, parâmetros e colunas de aplicabilidade em parametros (RN-17).
        $byCode = [];
        foreach ($pdoCatalog->findRules('contrato_locacao', 'contrato_locacao_ativacao', ['codigo_produto' => 'PROD_A', 'codigo_plano' => 'PLANO_A1']) as $rule) {
            $byCode[$rule->getCodigo()] = $rule;
        }
        $this->assertEquals(5000.5, $byCode['aluguel_maximo_produto_a']->getValorDecimal());
        $this->assertEquals(['codigo_produto' => 'PROD_A'], $byCode['aluguel_maximo_produto_a']->getParametros());
        $this->assertEquals('alertar', $byCode['aluguel_maximo_plano_a1']->getAcaoAoViolar()->value);
        $this->assertEquals(['campo' => 'fiadores', 'limite' => 2], $byCode['limite_parametrizado_ativacao']->getParametros());
        $this->assertEquals(['data_inicio_vigencia' => '2026-01-01', 'data_fim_vigencia' => '2026-06-30'], $byCode['regra_vigencia_2026h1']->getParametros());
        $this->assertEquals(3, $byCode['global_max_ocorrencias_3']->getValorInteiro());
        $this->assertTrue($byCode['global_max_ocorrencias_3']->getEscopo() === null);
        $this->assertEquals('Lei 8.245/91 Art. 43, II', $byCode['global_art43_ii_dupla']->getFundamentoLegal());

        // Equivalência em toda a matriz escopo × cenário × filtros.
        foreach (self::scopeCases() as [$escopo, $cenario]) {
            foreach (self::filterCases() as $filters) {
                $this->assertEquals(
                    self::ruleCodes($memory, $escopo, $cenario, $filters),
                    self::ruleCodes($pdoCatalog, $escopo, $cenario, $filters),
                    sprintf('findRules(%s, %s, %s) diverge do catálogo em memória', $escopo, $cenario ?? 'null', json_encode($filters))
                );
            }
        }

        // data_referencia inválida: mesmo erro de configuração do catálogo em memória.
        $this->assertThrows(InvalidArgumentException::class, fn() => $pdoCatalog->findRules('contrato_locacao', null, ['data_referencia' => 'ontem à noite']));
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

        foreach (self::scopeCases() as [$escopo, $cenario]) {
            $this->assertEquals(
                self::documentSignatures($memory, $escopo, $cenario),
                self::documentSignatures($pdoCatalog, $escopo, $cenario),
                sprintf('findDocumentRules(%s, %s) diverge do catálogo em memória', $escopo, $cenario ?? 'null')
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
            foreach (self::scopeCases() as [$escopo, $cenario]) {
                foreach (self::filterCases() as $filters) {
                    $expected = self::verdictSummary($fromMemory->validate($target, $escopo, $cenario, $filters));
                    $actual = self::verdictSummary($fromDatabase->validate($target, $escopo, $cenario, $filters));
                    $this->assertEquals($expected, $actual, sprintf(
                        'veredito diverge para %s em (%s, %s, %s)',
                        $name,
                        $escopo,
                        $cenario ?? 'null',
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
        $verdict = $fromDatabase->validate(self::targets()['violador'], 'contrato_locacao', 'contrato_locacao_ativacao', ['codigo_produto' => 'PROD_A']);
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

        // Sem withAsOf a linha chega com as datas e o contrato em memória decide por data_referencia.
        $this->assertTrue(in_array('regra_vigencia_2026h1', self::ruleCodes($catalog, 'contrato_locacao', null, []), true));
        $this->assertFalse(in_array('regra_vigencia_2026h1', self::ruleCodes($catalog, 'contrato_locacao', null, ['data_referencia' => '2026-07-01']), true));
    }

    private function testFiltersOutsideTheWhitelistNeverReachSql(): void
    {
        $catalog = new PdoRuleCatalog(self::seededPdo());
        $memory = self::equivalentInMemoryCatalog();

        $hostile = [
            'codigo_produto' => "PROD_A' OR '1'='1",
            "codigo_plano = codigo_plano OR 1=1 --" => 'x',
            'nome' => 'qualquer',
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
        $this->assertEquals(['codigo_produto', 'codigo_plano'], PdoRuleCatalog::FILTER_COLUMNS);
    }

    private function testParametrosJsonIsDecodedAndValidated(): void
    {
        $pdo = self::seededPdo();
        $catalog = new PdoRuleCatalog($pdo);

        // Chaves de aplicabilidade dentro do JSON são descartadas: a coluna é a fonte.
        $pdo->exec("UPDATE regra_negocio SET parametros = '{\"limite\": 2, \"campo\": \"fiadores\", \"codigo_produto\": \"PROD_Z\"}' WHERE codigo = 'limite_parametrizado_ativacao'");
        $rules = $catalog->findRules('contrato_locacao', 'contrato_locacao_ativacao');
        $limit = array_values(array_filter($rules, static fn(IRuleDefinition $r): bool => $r->getCodigo() === 'limite_parametrizado_ativacao'));
        $this->assertCount(1, $limit, 'a chave codigo_produto do JSON não restringe a regra');
        $this->assertEquals(['limite' => 2, 'campo' => 'fiadores'], $limit[0]->getParametros());

        $pdo->exec("UPDATE regra_negocio SET parametros = '' WHERE codigo = 'limite_parametrizado_ativacao'");
        $this->assertCount(9, $catalog->findRules('contrato_locacao', 'contrato_locacao_ativacao', ['codigo_produto' => 'PROD_A']), 'parametros vazio = sem parâmetros');

        $pdo->exec("UPDATE regra_negocio SET parametros = '{nao e json' WHERE codigo = 'limite_parametrizado_ativacao'");
        $error = $this->assertThrows(RuleEngineException::class, fn() => $catalog->findRules('contrato_locacao', 'contrato_locacao_ativacao'));
        $this->assertTrue(str_contains($error->getMessage(), 'limite_parametrizado_ativacao'), $error->getMessage());

        $pdo->exec("UPDATE regra_negocio SET parametros = '[1, 2]' WHERE codigo = 'limite_parametrizado_ativacao'");
        $this->assertThrows(RuleEngineException::class, fn() => $catalog->findRules('contrato_locacao', 'contrato_locacao_ativacao'), 'lista JSON não é objeto');

        // Erro do driver vira RuleEngineException com a causa preservada.
        $missing = new PdoRuleCatalog($pdo, '', ['regra' => 'tabela_inexistente']);
        $driverError = $this->assertThrows(RuleEngineException::class, fn() => $missing->findRules('contrato_locacao'));
        $this->assertInstanceOf(\PDOException::class, $driverError->getPrevious());
    }

    private function testSchemaAndTableNamesAreConfigurable(): void
    {
        $pdo = self::seededPdo();
        $pdo->exec("ATTACH DATABASE ':memory:' AS catalogo");
        $pdo->exec('CREATE TABLE catalogo.regras AS SELECT * FROM main.regra_negocio');
        $pdo->exec('CREATE TABLE catalogo.grupos AS SELECT * FROM main.grupo_documento_obrigatorio');
        $pdo->exec('CREATE TABLE catalogo.grupo_tipos AS SELECT * FROM main.grupo_documento_obrigatorio_tipo');
        $pdo->exec("DELETE FROM catalogo.regras WHERE codigo <> 'global_max_ocorrencias_3'");

        $catalog = new PdoRuleCatalog($pdo, 'catalogo', ['regra' => 'regras', 'grupo' => 'grupos', 'grupo_tipo' => 'grupo_tipos']);
        $this->assertEquals(['global_max_ocorrencias_3'], self::ruleCodes($catalog, 'contrato_locacao', null, []), 'lê da tabela qualificada pelo schema');
        $this->assertCount(5, $catalog->findDocumentRules('contrato_locacao', 'contrato_locacao_ativacao'));
    }

    /**
     * Transcrição do exemplo proposto para o README (bloco do PdoRuleCatalog ao lado do catálogo em memória).
     */
    private function testReadmeStyleExample(): void
    {
        $pdo = self::seededPdo(); // new PDO($dsn, $user, $password) against the tables of resources/sql/rule_catalog.ansi.sql

        $registry = Spec::ruleRegistry();
        $registry->registerClosure('max_ocorrencias_por_contrato', fn($rule) =>
            Spec::property('ocorrencias', Spec::lessThanOrEqualTo($rule->getValorInteiro())));
        foreach (['valida_ausencia_dupla_garantia_art43_ii', 'expirar_documento_apos_dias', 'registro_auditoria', 'aluguel_maximo_aceito', 'limite_parametrizado'] as $type) {
            $registry->registerClosure($type, fn($rule) => Spec::alwaysTrue());
        }

        // Optional: read only the rules in force on a date (validity window filtered in SQL)
        $catalog = (new PdoRuleCatalog($pdo))->withAsOf(new DateTimeImmutable('2026-10-09'));

        $engine = Spec::engine($catalog, $registry);
        $verdict = $engine->validate(
            target: (object) ['ocorrencias' => 4, 'documentos' => ['contrato_locacao_assinado']],
            escopo: 'contrato_locacao'
        );

        $this->assertTrue($verdict->hasBlockingErrors());
        $this->assertEquals(['global_max_ocorrencias_3'], $verdict->getFailureCodes());
        $this->assertEquals(['Este contrato já possui 3 ocorrências em aberto.'], $verdict->getMessages());
    }
}
