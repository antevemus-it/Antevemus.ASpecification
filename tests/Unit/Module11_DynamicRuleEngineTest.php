<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\Engine\IRuleDefinition;
use Antevemus\ASpecification\Contracts\Engine\IRuleSpecificationHandler;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Engine\DocumentGroupSpecificationBuilder;
use Antevemus\ASpecification\Engine\DocumentRequirementMode;
use Antevemus\ASpecification\Engine\DocumentRuleDefinition;
use Antevemus\ASpecification\Engine\DynamicSpecificationEngine;
use Antevemus\ASpecification\Engine\Exceptions\InvalidConditionalExpressionException;
use Antevemus\ASpecification\Engine\Exceptions\MissingAlternativeSetException;
use Antevemus\ASpecification\Engine\Exceptions\MissingRuleHandlerException;
use Antevemus\ASpecification\Engine\Exceptions\RuleEngineException;
use Antevemus\ASpecification\Engine\InMemoryRuleCatalog;
use Antevemus\ASpecification\Engine\RuleAction;
use Antevemus\ASpecification\Engine\RuleBoundSpecification;
use Antevemus\ASpecification\Linq\ALinqSpecificationVisitor;
use Antevemus\ASpecification\Engine\RuleDefinition;
use Antevemus\ASpecification\Engine\RuleEngineVerdict;
use Antevemus\ASpecification\Engine\RuleSpecificationRegistry;
use Antevemus\ASpecification\Results\SpecificationResult;
use Antevemus\ASpecification\Criteria\Exceptions\NonTranslatableCriteriaException;
use Antevemus\ASpecification\Specifications\PredicateSpecification;
use Antevemus\ASpecification\Sql\Exceptions\NonTranslatableSpecificationException;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Tests\TestCase;
use InvalidArgumentException;
use RuntimeException;
use stdClass;

/**
 * Fixture: the Contract of README example 7.
 */
final class Module11Contract
{
    public function __construct(private int $occurrences) {}
    public function getOccurrencesCount(): int { return $this->occurrences; }
}

/**
 * Module11_DynamicRuleEngineTest - Suíte de Testes para o Motor Dinâmico de Regras e Documentos
 *
 * Valida a compilação orientada a catálogo (banco de dados/PostgreSQL), a álgebra booleana
 * de requisitos documentais (ALL, ANY, ONE_OF_SET), a triagem operacional de vereditos (RuleEngineVerdict)
 * e o isolamento de handlers plugáveis.
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Unit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class Module11_DynamicRuleEngineTest extends TestCase
{
    public function run(): void
    {
        $this->testRuleActionEnum();
        $this->testDocumentRequirementModeEnum();
        $this->testRuleDefinitionHydration();
        $this->testDocumentRuleDefinitionHydration();
        $this->testRuleSpecificationRegistry();
        $this->testDocumentGroupBuilderWithAllAndAny();
        $this->testDocumentGroupBuilderWithOneOfSet();
        $this->testDocumentGroupBuilderWithConditional();
        $this->testInMemoryRuleCatalog();
        $this->testDynamicEngineEndToEndSimulation();
        $this->testSpecFacadeIntegration();

        // Lote 1 (revisão 2026-10-07): o motor carimba a regra, erro de avaliação bloqueia,
        // catálogo não vaza entre escopos, matriz documental com condicionais e sets.
        $this->testEngineStampsRuleMetadataOnFailures();
        $this->testHandlerMetadataTakesPrecedenceOverRule();
        $this->testEvaluationErrorIsAlwaysBlockingInVerdict();
        $this->testVerdictFailureCodesKeepAlignmentAndWarnOnlySemantics();
        $this->testCatalogDoesNotLeakDocumentsBetweenScopes();
        $this->testCatalogWithoutScenarioAppliesOnlyGlobalRules();
        $this->testOneOfSetIgnoresNonApplicableConditionalDocuments();
        $this->testAnyOrOneOfSetWithoutSetKeyIsRejected();
        $this->testDocumentRuleWithoutScopeIsRejected();
        $this->testAnySetFailureIsAggregated();
        $this->testUnparseableConditionalExpressionIsRejected();
        $this->testCompiledRuleSpecificationIsTranslatableByVisitors();

        // Forward 014 (README Promises I): R1 must() — o exemplo 7 do README roda como escrito.
        $this->testReadmeExample7RunsAsWritten();
        $this->testMustInlinePredicateContract();

        // Lote de correção #20-#29 (2026-10-07), BUG-20261007-ZB6A: filtros de aplicabilidade de findRules().
        $this->testFindRulesHonoursProductAndPlanFilters();
        $this->testFindRulesHonoursValidityWindow();
        $this->testRuleDefinitionHydratesApplicabilityColumnsIntoParametros();
    }

    /**
     * Forward 014 RN-02 / RN-10: transcrição fiel do exemplo 7 do README (EN, v1.2.0 linhas 233-268).
     * Antes do must(): "Call to undefined method AbstractCompositeSpecification@anonymous::must()".
     */
    private function testReadmeExample7RunsAsWritten(): void
    {
        $myCatalogRepository = new InMemoryRuleCatalog();
        $myCatalogRepository->addRule(new RuleDefinition(
            codigo: 'max_occ',
            nome: 'Max occurrences per contract',
            tipoRegra: 'max_occurrences_per_contract',
            acaoAoViolar: RuleAction::BLOCK,
            valorInteiro: 2,
            fundamentoLegal: 'Art. X',
            mensagemViolacao: 'Too many occurrences',
            escopo: 'rental_contract',
            cenario: 'activation'
        ));
        $myCatalogRepository->addRule(new RuleDefinition(
            codigo: 'warn_occ',
            nome: 'Watch occurrences',
            tipoRegra: 'max_occurrences_per_contract',
            acaoAoViolar: RuleAction::WARN,
            valorInteiro: 1,
            mensagemViolacao: 'Watch occurrences',
            escopo: 'rental_contract'
        ));
        $contract = new Module11Contract(3);

        // 1. Configure the Registry with pluggable rule handlers
        $registry = Spec::ruleRegistry();
        $registry->registerClosure('max_occurrences_per_contract', function ($rule) {
            return Spec::specify(Module11Contract::class)
                ->must(fn($c) => $c->getOccurrencesCount() <= $rule->getValorInteiro(), $rule->getCodigo(), $rule->getMensagemViolacao());
        });

        // 2. Instantiate the Engine connected to the relational catalog repository
        $engine = Spec::engine($myCatalogRepository, $registry);

        // 3. Validate target entity for a specific scope and scenario
        $verdict = $engine->validate(
            target: $contract,
            escopo: 'rental_contract',
            cenario: 'activation'
        );

        $this->assertTrue($verdict->hasBlockingErrors(), 'README ex. 7: contrato com 3 ocorrências deve bloquear');
        $blocking = $verdict->getBlockingFailures();
        $this->assertCount(1, $blocking);
        $this->assertEquals('max_occ', $blocking[0]->code);
        $this->assertEquals('Too many occurrences', $blocking[0]->message);
        $this->assertEquals(['Art. X'], $verdict->getLegalBases());
        $this->assertTrue($verdict->hasWarnings());
        $this->assertEquals('warn_occ', $verdict->getWarningFailures()[0]->code);

        $ok = $engine->validate(target: new Module11Contract(1), escopo: 'rental_contract', cenario: 'activation');
        $this->assertFalse($ok->hasBlockingErrors());
        $this->assertFalse($ok->hasWarnings());
    }

    /**
     * Forward 014 RN-02: contrato de ISpecification::must().
     */
    private function testMustInlinePredicateContract(): void
    {
        // sem código nem mensagem: folha PredicateSpecification sem anotação
        $plain = Spec::specify(stdClass::class)->must(fn($o) => $o->n > 1);
        $this->assertTrue($plain->isSatisfiedBy((object) ['n' => 5]));
        $this->assertFalse($plain->isSatisfiedBy((object) ['n' => 0]));
        $res = $plain->evaluate((object) ['n' => 0]);
        $this->assertFalse($res->isSatisfied);
        $this->assertCount(1, $res->failures);
        $this->assertTrue($res->failures[0]->code === null, 'sem withCode() a falha não tem código');
        $this->assertTrue(str_contains($res->getReasons()[0], 'PredicateSpecification'));
        $this->assertInstanceOf(PredicateSpecification::class, $plain->getRightSide());
        $this->assertEquals(stdClass::class, $plain->getRightSide()->getType(), 'a folha herda o tipo declarado do composto');

        // com código e mensagem
        $annotated = Spec::specify(stdClass::class)->must(fn($o) => $o->n > 1, 'N_GT_1', 'n must exceed 1');
        $res = $annotated->evaluate((object) ['n' => 0]);
        $this->assertEquals(['N_GT_1'], $res->getCodes());
        $this->assertEquals(['n must exceed 1'], $res->getReasons());

        // exceção no predicado: propaga em isSatisfiedBy, vira erro em evaluate, NOT não aprova
        $throwing = Spec::specify(stdClass::class)->must(fn($o) => throw new RuntimeException('boom'), 'X');
        $this->assertThrows(RuntimeException::class, fn() => $throwing->isSatisfiedBy((object) []));
        $err = $throwing->evaluate((object) []);
        $this->assertTrue($err->isError);
        $this->assertFalse($err->isSatisfied);
        $this->assertFalse(Spec::not($throwing)->evaluate((object) [])->isSatisfied, 'NOT nunca aprova um erro');

        // encadeia depois de where()/and() e em qualquer spec
        $chained = Spec::specify(stdClass::class)
            ->where('n', Spec::greaterThan(0))
            ->must(fn($o) => $o->n % 2 === 0, 'EVEN', 'n must be even');
        $this->assertTrue($chained->isSatisfiedBy((object) ['n' => 4]));
        $this->assertFalse($chained->isSatisfiedBy((object) ['n' => 3]));
        $this->assertEquals(['EVEN'], $chained->evaluate((object) ['n' => 3])->getCodes());
        $leafChain = Spec::property('n', Spec::greaterThan(0))->must(fn($o) => $o->n < 10, 'LT10');
        $this->assertTrue($leafChain->isSatisfiedBy((object) ['n' => 5]));
        $this->assertEquals(['LT10'], $leafChain->evaluate((object) ['n' => 50])->getCodes());

        // equals(): closure por identidade
        $fn = fn($o) => true;
        $this->assertTrue((new PredicateSpecification($fn))->equals(new PredicateSpecification($fn)));
        $this->assertFalse((new PredicateSpecification($fn))->equals(new PredicateSpecification(fn($o) => true)));

        // ALinq compila; SQL e TCriteria recusam com a mensagem da folha
        $predicate = ALinqSpecificationVisitor::createPredicate($chained);
        $this->assertTrue($predicate((object) ['n' => 4]));
        $this->assertFalse($predicate((object) ['n' => 3]));
        $sqlEx = $this->assertThrows(NonTranslatableSpecificationException::class, fn() => Spec::toSql($leafChain, 'pgsql'));
        $this->assertTrue(str_contains($sqlEx->getMessage(), 'must()'), $sqlEx->getMessage());
        if (!class_exists(\Adianti\Database\TCriteria::class)) {
            foreach (glob(__DIR__ . '/../Stubs/Adianti/*.php') as $stub) {
                require_once $stub;
            }
        }
        $critEx = $this->assertThrows(NonTranslatableCriteriaException::class, fn() => Spec::toCriteria($leafChain));
        $this->assertTrue(str_contains($critEx->getMessage(), 'must()'), $critEx->getMessage());
        $this->assertThrows(NonTranslatableCriteriaException::class, fn() => Spec::toCriteria(Spec::not($leafChain)));
    }

    /**
     * §1.1: o handler devolve a spec crua (sem because/withCode/metadata), como o README ensina.
     * O motor deve carimbar ação, código, mensagem e fundamento legal da regra.
     */
    private function testEngineStampsRuleMetadataOnFailures(): void
    {
        $catalog = new InMemoryRuleCatalog();
        $registry = new RuleSpecificationRegistry();

        $catalog->addRule(new RuleDefinition(
            codigo: 'comp_residencia_90',
            nome: 'Comprovante com mais de 90 dias',
            tipoRegra: 'dias_maximos_comprovante',
            acaoAoViolar: RuleAction::WARN,
            valorInteiro: 90,
            fundamentoLegal: 'Política Interna 7.2',
            mensagemViolacao: 'Comprovante de residência vencido.',
            escopo: 'contrato_locacao'
        ));

        // Handler cru: nenhum encanamento de ação/base legal.
        $registry->registerClosure('dias_maximos_comprovante', fn(IRuleDefinition $r): ISpecification =>
            Spec::property('diasCompResidencia', Spec::lessThanOrEqualTo($r->getValorInteiro() ?? 90))
        );

        $engine = new DynamicSpecificationEngine($catalog, $registry);
        $verdict = $engine->validate((object) ['diasCompResidencia' => 120], 'contrato_locacao');

        $this->assertFalse($verdict->isSatisfied());
        $this->assertFalse($verdict->hasBlockingErrors(), 'Regra WARN não pode cair como BLOCK');
        $this->assertTrue($verdict->hasWarnings(), 'Regra WARN deve virar warning no veredito');
        $this->assertEquals(['Política Interna 7.2'], $verdict->getLegalBases());
        $this->assertEquals(['comp_residencia_90'], $verdict->getFailureCodes());

        $failure = $verdict->getWarningFailures()[0];
        $this->assertEquals('Comprovante de residência vencido.', $failure->message);
        $this->assertEquals('alertar', $failure->metadata['acao']);
        $this->assertEquals('comp_residencia_90', $failure->metadata['regra']);
        $this->assertEquals('dias_maximos_comprovante', $failure->metadata['tipo_regra']);
        $this->assertTrue(isset($failure->metadata['mensagem_original']), 'A mensagem técnica da folha fica preservada no metadata');

        // Regra que passa continua passando (o carimbo só age sobre falhas).
        $ok = $engine->validate((object) ['diasCompResidencia' => 10], 'contrato_locacao');
        $this->assertTrue($ok->isSatisfied());
    }

    /**
     * §1.1: o que o handler gravou (because/withCode/metadata) tem precedência sobre a regra.
     */
    private function testHandlerMetadataTakesPrecedenceOverRule(): void
    {
        $catalog = new InMemoryRuleCatalog();
        $registry = new RuleSpecificationRegistry();

        $catalog->addRule(new RuleDefinition(
            codigo: 'regra_warn',
            nome: 'Regra alertar no catálogo',
            tipoRegra: 'handler_personalizado',
            acaoAoViolar: RuleAction::WARN,
            fundamentoLegal: 'Base do catálogo',
            mensagemViolacao: 'Mensagem do catálogo',
            escopo: 'x'
        ));

        $registry->registerClosure('handler_personalizado', function (IRuleDefinition $r): ISpecification {
            return new class extends AbstractSpecification {
                public function getType(): string { return 'mixed'; }
                public function isSatisfiedBy(mixed $c): bool { return false; }
                public function evaluate(mixed $c): SpecificationResult {
                    return SpecificationResult::failure(
                        'Mensagem do handler',
                        'CODIGO_DO_HANDLER',
                        metadata: ['acao' => 'bloquear', 'fundamento_legal' => 'Base do handler']
                    );
                }
            };
        });

        $verdict = (new DynamicSpecificationEngine($catalog, $registry))->validate(new stdClass(), 'x');

        $this->assertTrue($verdict->hasBlockingErrors(), 'acao do handler (bloquear) vence a da regra (alertar)');
        $this->assertFalse($verdict->hasWarnings());
        $failure = $verdict->getBlockingFailures()[0];
        $this->assertEquals('Mensagem do handler', $failure->message);
        $this->assertEquals('CODIGO_DO_HANDLER', $failure->code);
        $this->assertEquals(['Base do handler'], $verdict->getLegalBases());
        // O vínculo com a regra do catálogo continua rastreável.
        $this->assertEquals('regra_warn', $failure->metadata['regra']);
    }

    /**
     * §2.1 + §1.1: erro de avaliação (propriedade inexistente) nunca vira WARN/LOG; é BLOCK.
     */
    private function testEvaluationErrorIsAlwaysBlockingInVerdict(): void
    {
        $catalog = new InMemoryRuleCatalog();
        $registry = new RuleSpecificationRegistry();

        $catalog->addRule(new RuleDefinition(
            codigo: 'regra_log',
            nome: 'Regra só log',
            tipoRegra: 'propriedade_inexistente',
            acaoAoViolar: RuleAction::LOG,
            escopo: 'x'
        ));
        $registry->registerClosure('propriedade_inexistente', fn(IRuleDefinition $r): ISpecification =>
            Spec::property('naoExiste', Spec::greaterThan(1))
        );

        $verdict = (new DynamicSpecificationEngine($catalog, $registry))->validate(new stdClass(), 'x');

        $this->assertTrue($verdict->hasEvaluationErrors());
        $this->assertTrue($verdict->hasBlockingErrors(), 'Erro de avaliação é BLOCK mesmo em regra LOG');
        $this->assertFalse($verdict->hasLogs());
        $this->assertFalse($verdict->hasWarnings());
        $this->assertEquals(['regra_log'], $verdict->getFailureCodes());
    }

    /**
     * §3.4: getFailureCodes() alinhado com as falhas (null explícito) e isSatisfied() falso com só WARN.
     */
    private function testVerdictFailureCodesKeepAlignmentAndWarnOnlySemantics(): void
    {
        $result = SpecificationResult::combine(
            SpecificationResult::failure('a', 'A', metadata: ['acao' => 'alertar']),
            SpecificationResult::failure('b', null, metadata: ['acao' => 'alertar']),
            SpecificationResult::failure('c', 'C', metadata: ['acao' => 'apenas_log'])
        );
        $verdict = RuleEngineVerdict::fromSpecificationResult($result);

        $this->assertCount(3, $verdict->getAllFailures());
        $this->assertEquals(['A', null, 'C'], $verdict->getFailureCodes());

        // Só WARN/LOG: não está "satisfeito", mas pode prosseguir.
        $this->assertFalse($verdict->isSatisfied());
        $this->assertFalse($verdict->hasBlockingErrors());
        $this->assertTrue($verdict->canProceed());
        $this->assertFalse($verdict->hasEvaluationErrors());
    }

    /**
     * §1.4 + §3.4: documentos de um escopo/cenário não vazam para outro.
     */
    private function testCatalogDoesNotLeakDocumentsBetweenScopes(): void
    {
        $catalog = new InMemoryRuleCatalog();

        // Dois escopos com cenário de mesmo nome: o escopo é declarado, o grupo é só identificador.
        $catalog->addDocumentRule(new DocumentRuleDefinition('ativacao', 'doc_contrato', 'contrato', cenario: 'ativacao'));
        $catalog->addDocumentRule(new DocumentRuleDefinition('ativacao', 'doc_sinistro', 'sinistro', cenario: 'ativacao'));
        // Documento global do escopo (sem cenário).
        $catalog->addDocumentRule(new DocumentRuleDefinition('contrato', 'doc_global_contrato', 'contrato'));
        // Cenários do mesmo escopo e um escopo com nome parecido: nada casa por prefixo nem por grupo.
        $catalog->addDocumentRule(new DocumentRuleDefinition('sinistro_ocupado', 'termo_ocupado', 'sinistro', cenario: 'sinistro:ocupado'));
        $catalog->addDocumentRule(new DocumentRuleDefinition('sinistro_desocupado', 'chaves_desocupado', 'sinistro', cenario: 'sinistro:desocupado'));
        $catalog->addDocumentRule(new DocumentRuleDefinition('contrato', 'doc_outro_escopo', 'contratos_x'));
        // Grupo com nome de escopo alheio: o grupo nunca decide o escopo.
        $catalog->addDocumentRule(new DocumentRuleDefinition('contrato', 'doc_grupo_enganoso', 'sinistro'));

        $docs = array_map(fn($d) => $d->getCodigoTipoDocumento(), $catalog->findDocumentRules('contrato', 'ativacao'));
        sort($docs);
        $this->assertEquals(['doc_contrato', 'doc_global_contrato'], $docs, 'contrato/ativacao não pode ver doc_sinistro nem contratos_x');

        $docs = array_map(fn($d) => $d->getCodigoTipoDocumento(), $catalog->findDocumentRules('sinistro', 'ativacao'));
        sort($docs);
        $this->assertEquals(['doc_grupo_enganoso', 'doc_sinistro'], $docs, 'global do escopo sinistro entra em qualquer cenário de sinistro');

        $docs = array_map(fn($d) => $d->getCodigoTipoDocumento(), $catalog->findDocumentRules('sinistro', 'sinistro:ocupado'));
        sort($docs);
        $this->assertEquals(['doc_grupo_enganoso', 'termo_ocupado'], $docs, 'sinistro:ocupado não pode receber os documentos de sinistro:desocupado');

        $docs = array_map(fn($d) => $d->getCodigoTipoDocumento(), $catalog->findDocumentRules('contrato'));
        $this->assertEquals(['doc_global_contrato'], $docs, 'escopo contrato sem cenário: só o global, nunca contratos_x nem o grupo chamado contrato de outro escopo');

        $docs = array_map(fn($d) => $d->getCodigoTipoDocumento(), $catalog->findDocumentRules('contrato', 'sinistro:ocupado'));
        $this->assertEquals(['doc_global_contrato'], $docs, 'cenário de outro escopo não traz nada além do global');
    }

    /**
     * §1.5: validar sem cenário aplica só as regras globais do escopo, não as de todos os cenários.
     */
    private function testCatalogWithoutScenarioAppliesOnlyGlobalRules(): void
    {
        $catalog = new InMemoryRuleCatalog();
        $catalog->addRule(new RuleDefinition(codigo: 'global_escopo', nome: 'g', tipoRegra: 't', escopo: 'contrato'));
        $catalog->addRule(new RuleDefinition(codigo: 'so_ativacao', nome: 'a', tipoRegra: 't', escopo: 'contrato', cenario: 'ativacao'));
        $catalog->addRule(new RuleDefinition(codigo: 'so_cancelamento', nome: 'c', tipoRegra: 't', escopo: 'contrato', cenario: 'cancelamento'));

        $codes = array_map(fn($r) => $r->getCodigo(), $catalog->findRules('contrato'));
        $this->assertEquals(['global_escopo'], $codes, 'sem cenário = só regras sem cenário');

        $codes = array_map(fn($r) => $r->getCodigo(), $catalog->findRules('contrato', 'ativacao'));
        sort($codes);
        $this->assertEquals(['global_escopo', 'so_ativacao'], $codes);
    }

    /**
     * §3.4: documento condicionado cuja condição não se aplica é "não aplicável", não "presente".
     */
    private function testOneOfSetIgnoresNonApplicableConditionalDocuments(): void
    {
        $builder = new DocumentGroupSpecificationBuilder();
        $spec = $builder->build([
            new DocumentRuleDefinition('analise', 'holerite', 'contrato', DocumentRequirementMode::ONE_OF_SET, 'renda'),
            new DocumentRuleDefinition('analise', 'declaracao_ir', 'contrato', DocumentRequirementMode::ONE_OF_SET, 'renda', 'tipo<>pf'),
        ]);

        // Pessoa física com holerite: só o holerite se aplica, 1 presente -> satisfeito
        $this->assertTrue($spec->isSatisfiedBy((object) ['tipo' => 'pf', 'documentos' => ['holerite']]));
        // Pessoa física sem nada: 0 presentes -> reprovado (antes aprovava, contando a condicional como presente)
        $this->assertFalse($spec->isSatisfiedBy((object) ['tipo' => 'pf', 'documentos' => []]));
        // Pessoa jurídica com os dois: 2 presentes -> reprovado
        $this->assertFalse($spec->isSatisfiedBy((object) ['tipo' => 'pj', 'documentos' => ['holerite', 'declaracao_ir']]));
        // Pessoa jurídica só com declaração: 1 -> satisfeito
        $this->assertTrue($spec->isSatisfiedBy((object) ['tipo' => 'pj', 'documentos' => ['declaracao_ir']]));

        // Set em que nenhum documento se aplica: nada é exigido
        $specTodosCondicionais = $builder->build([
            new DocumentRuleDefinition('analise', 'a', 'contrato', DocumentRequirementMode::ONE_OF_SET, 's', 'tipo=pj'),
            new DocumentRuleDefinition('analise', 'b', 'contrato', DocumentRequirementMode::ONE_OF_SET, 's', 'tipo=pj'),
        ]);
        $this->assertTrue($specTodosCondicionais->isSatisfiedBy((object) ['tipo' => 'pf', 'documentos' => []]));
    }

    /**
     * §3.4 (decisão de 2026-10-07 sobre o 18_regra_negocio.sql): ANY ou ONE_OF_SET sem
     * codigo_set_alternativas é erro de catálogo (chk_gdot_set) e é recusado na compilação,
     * nunca agrupado por adivinhação. ALL sem set continua válido.
     */
    private function testAnyOrOneOfSetWithoutSetKeyIsRejected(): void
    {
        $builder = new DocumentGroupSpecificationBuilder();

        $e = $this->assertThrows(MissingAlternativeSetException::class, fn() => $builder->build([
            new DocumentRuleDefinition('ativacao', 'rg', 'contrato', DocumentRequirementMode::ANY),
            new DocumentRuleDefinition('ativacao', 'cnh', 'contrato', DocumentRequirementMode::ANY),
        ]));
        $this->assertInstanceOf(RuleEngineException::class, $e);
        $this->assertEquals('any', $e->mode);
        $this->assertEquals('rg', $e->documentType);
        $this->assertEquals('ativacao', $e->groupCode);

        $e = $this->assertThrows(MissingAlternativeSetException::class, fn() => $builder->build([
            new DocumentRuleDefinition('analise', 'holerite', 'contrato', DocumentRequirementMode::ONE_OF_SET, '  '),
        ]));
        $this->assertEquals('one_of_set', $e->mode);

        // ALL sem set é a forma normal
        $spec = $builder->build([new DocumentRuleDefinition('ativacao', 'rg', 'contrato', DocumentRequirementMode::ALL)]);
        $this->assertTrue($spec->isSatisfiedBy((object) ['documentos' => ['rg']]));
    }

    /**
     * Decisão de 2026-10-07 (18_regra_negocio.sql: escopo NOT NULL): requisito documental sem escopo
     * não existe; o grupo é só identificador e nunca substitui o escopo.
     */
    private function testDocumentRuleWithoutScopeIsRejected(): void
    {
        $this->assertThrows(InvalidArgumentException::class, fn() => new DocumentRuleDefinition('ativacao', 'rg', ''));
        $this->assertThrows(InvalidArgumentException::class, fn() => new DocumentRuleDefinition('ativacao', 'rg', '   '));
        $this->assertThrows(InvalidArgumentException::class, fn() => DocumentRuleDefinition::fromArray([
            'grupo_codigo' => 'contrato_locacao_ativacao',
            'codigo_tipo_documento' => 'rg',
        ]));
        $this->assertThrows(InvalidArgumentException::class, fn() => DocumentRuleDefinition::fromArray([
            'grupo_codigo' => 'contrato_locacao_ativacao',
            'codigo_tipo_documento' => 'rg',
            'escopo' => '',
        ]));

        $def = DocumentRuleDefinition::fromArray([
            'grupo_codigo' => 'sinistro_ocupado',
            'codigo_tipo_documento' => 'declaracao_debitos',
            'escopo' => 'sinistro',
            'cenario' => 'sinistro:ocupado',
        ]);
        $this->assertEquals('sinistro', $def->getEscopo());
        $this->assertEquals('sinistro:ocupado', $def->getCenario());
    }

    /**
     * §3.4: set ANY que falha emite UMA falha agregada listando os documentos aceitos.
     */
    private function testAnySetFailureIsAggregated(): void
    {
        $builder = new DocumentGroupSpecificationBuilder();
        $spec = $builder->build([
            new DocumentRuleDefinition('ativacao', 'rg', 'contrato', DocumentRequirementMode::ANY, 'identidade'),
            new DocumentRuleDefinition('ativacao', 'cnh', 'contrato', DocumentRequirementMode::ANY, 'identidade'),
        ]);

        $result = $spec->evaluate((object) ['documentos' => []]);
        $this->assertFalse($result->isSatisfied);
        $this->assertCount(1, $result->failures, 'um requisito, uma falha');
        $failure = $result->failures[0];
        $this->assertEquals('DOC_SET_IDENTIDADE', $failure->code);
        $this->assertEquals(['rg', 'cnh'], $failure->metadata['documentos_aceitos']);
        $this->assertEquals(['DOC_RG', 'DOC_CNH'], $failure->metadata['codigos']);
        $this->assertEquals('bloquear', $failure->metadata['acao']);

        $verdict = RuleEngineVerdict::fromSpecificationResult($result);
        $this->assertCount(1, $verdict->getBlockingFailures());
    }

    /**
     * §3.4: expressão condicional que o avaliador não entende é recusada na compilação, nunca exigida em silêncio.
     */
    private function testUnparseableConditionalExpressionIsRejected(): void
    {
        $builder = new DocumentGroupSpecificationBuilder();
        $rules = [
            new DocumentRuleDefinition('ativacao', 'doc', 'contrato', DocumentRequirementMode::ALL, null, 'tipo_locacao IN (a, b)'),
        ];

        $e = $this->assertThrows(InvalidConditionalExpressionException::class, fn() => $builder->build($rules));
        $this->assertInstanceOf(RuleEngineException::class, $e);
    }

    private function testRuleActionEnum(): void
    {
        $block = RuleAction::BLOCK;
        $warn = RuleAction::WARN;
        $log = RuleAction::LOG;

        $this->assertTrue($block->isBlocking());
        $this->assertFalse($block->isWarning());
        $this->assertFalse($block->isLogOnly());

        $this->assertTrue($warn->isWarning());
        $this->assertFalse($warn->isBlocking());

        $this->assertTrue($log->isLogOnly());
        $this->assertFalse($log->isBlocking());

        $this->assertEquals(RuleAction::BLOCK, RuleAction::fromOrDefault('bloquear'));
        $this->assertEquals(RuleAction::WARN, RuleAction::fromOrDefault('alertar'));
        $this->assertEquals(RuleAction::LOG, RuleAction::fromOrDefault('apenas_log'));
        $this->assertEquals(RuleAction::BLOCK, RuleAction::fromOrDefault('desconhecido'));
        $this->assertEquals(RuleAction::WARN, RuleAction::fromOrDefault(null, RuleAction::WARN));
    }

    private function testDocumentRequirementModeEnum(): void
    {
        $this->assertEquals(DocumentRequirementMode::ALL, DocumentRequirementMode::fromOrDefault('all'));
        $this->assertEquals(DocumentRequirementMode::ANY, DocumentRequirementMode::fromOrDefault('any'));
        $this->assertEquals(DocumentRequirementMode::ONE_OF_SET, DocumentRequirementMode::fromOrDefault('one_of_set'));
        $this->assertEquals(DocumentRequirementMode::ALL, DocumentRequirementMode::fromOrDefault('invalido'));
    }

    private function testRuleDefinitionHydration(): void
    {
        $row = [
            'codigo' => 'global_max_ocorrencias_3',
            'nome' => 'Máximo 3 ocorrências por contrato',
            'descricao' => 'Limite operacional de sinistros simultâneos.',
            'tipo_regra' => 'max_ocorrencias_por_contrato',
            'acao_ao_violar' => 'bloquear',
            'valor_inteiro' => '3',
            'fundamento_legal' => 'Política de Risco Art. 12',
            'mensagem_violacao' => 'Este contrato já possui 3 ocorrências.',
            'prioridade' => 100,
            'active' => 'Y',
        ];

        $def = RuleDefinition::fromArray($row);

        $this->assertEquals('global_max_ocorrencias_3', $def->getCodigo());
        $this->assertEquals('Máximo 3 ocorrências por contrato', $def->getNome());
        $this->assertEquals('max_ocorrencias_por_contrato', $def->getTipoRegra());
        $this->assertEquals(RuleAction::BLOCK, $def->getAcaoAoViolar());
        $this->assertEquals(3, $def->getValorInteiro());
        $this->assertEquals('Política de Risco Art. 12', $def->getFundamentoLegal());
        $this->assertEquals('Este contrato já possui 3 ocorrências.', $def->getMensagemViolacao());
        $this->assertEquals(100, $def->getPrioridade());
        $this->assertTrue($def->isActive());
    }

    private function testDocumentRuleDefinitionHydration(): void
    {
        $row = [
            'grupo_codigo' => 'contrato_locacao_ativacao',
            'codigo_tipo_documento' => 'cnh_locatario',
            'regra_obrigatoriedade' => 'any',
            'codigo_set_alternativas' => 'identidade',
            'condicional_expressao' => 'tipo_locacao<>residencial_temporada',
            'ordem' => 70,
            'active' => 'Y',
            'escopo' => 'contrato_locacao',
        ];

        $def = DocumentRuleDefinition::fromArray($row);

        $this->assertEquals('contrato_locacao_ativacao', $def->getGrupoCodigo());
        $this->assertEquals('contrato_locacao', $def->getEscopo());
        $this->assertTrue($def->getCenario() === null);
        $this->assertEquals('cnh_locatario', $def->getCodigoTipoDocumento());
        $this->assertEquals(DocumentRequirementMode::ANY, $def->getRegraObrigatoriedade());
        $this->assertEquals('identidade', $def->getCodigoSetAlternativas());
        $this->assertEquals('tipo_locacao<>residencial_temporada', $def->getCondicionalExpressao());
        $this->assertEquals(70, $def->getOrdem());
        $this->assertTrue($def->isActive());
    }

    private function testRuleSpecificationRegistry(): void
    {
        $registry = new RuleSpecificationRegistry();

        // 1. Testa registro por Closure
        $registry->registerClosure('limite_aluguel_maximo', function (IRuleDefinition $rule): ISpecification {
            $max = $rule->getValorDecimal() ?? 10000.0;
            return new class($max, $rule) extends AbstractSpecification {
                public function __construct(private readonly float $max, private readonly IRuleDefinition $rule) {}
                public function getType(): string { return 'mixed'; }
                public function isSatisfiedBy(mixed $candidate): bool {
                    return ($candidate->aluguel ?? 0.0) <= $this->max;
                }
                public function evaluate(mixed $candidate): SpecificationResult {
                    if ($this->isSatisfiedBy($candidate)) {
                        return SpecificationResult::satisfied();
                    }
                    return SpecificationResult::failure('Aluguel excede teto.', $this->rule->getCodigo(), metadata: ['acao' => 'bloquear']);
                }
            };
        });

        $this->assertTrue($registry->hasHandler('limite_aluguel_maximo'));
        $this->assertFalse($registry->hasHandler('regra_inexistente'));

        $ruleDef = new RuleDefinition(
            codigo: 'teto_aluguel_5000',
            nome: 'Teto de aluguel',
            tipoRegra: 'limite_aluguel_maximo',
            valorDecimal: 5000.0
        );

        $spec = $registry->buildSpecification($ruleDef);
        $objOk = (object) ['aluguel' => 4500.0];
        $objFail = (object) ['aluguel' => 6000.0];

        $this->assertTrue($spec->isSatisfiedBy($objOk));
        $this->assertFalse($spec->isSatisfiedBy($objFail));

        // 2. Testa lançamento de exceção para handler não registrado
        $unregisteredRule = new RuleDefinition(
            codigo: 'inexistente',
            nome: 'Regra sem handler',
            tipoRegra: 'tipo_nao_cadastrado'
        );

        $this->assertThrows(MissingRuleHandlerException::class, function () use ($registry, $unregisteredRule) {
            $registry->buildSpecification($unregisteredRule);
        });

        // 3. Testa registro via classe implementando IRuleSpecificationHandler
        $handlerClass = new class implements IRuleSpecificationHandler {
            public function supports(string $tipoRegra): bool {
                return $tipoRegra === 'regra_classe';
            }
            public function build(IRuleDefinition $rule): ISpecification {
                return Spec::alwaysTrue();
            }
        };

        $registry->register($handlerClass);
        $this->assertTrue($registry->hasHandler('regra_classe'));
    }

    private function testDocumentGroupBuilderWithAllAndAny(): void
    {
        $builder = new DocumentGroupSpecificationBuilder();

        // Monta regras: cnpj_imobiliaria (all), contrato_assinado (all), identidade (any: rg ou cnh)
        $docRules = [
            new DocumentRuleDefinition('ativacao', 'cnpj_imobiliaria', 'contrato', DocumentRequirementMode::ALL),
            new DocumentRuleDefinition('ativacao', 'contrato_assinado', 'contrato', DocumentRequirementMode::ALL),
            new DocumentRuleDefinition('ativacao', 'rg_locatario', 'contrato', DocumentRequirementMode::ANY, 'identidade'),
            new DocumentRuleDefinition('ativacao', 'cnh_locatario', 'contrato', DocumentRequirementMode::ANY, 'identidade'),
        ];

        $spec = $builder->build($docRules);

        // Cenário 1: Todos os obrigatórios + CNH presente (identidade satisfeita)
        $candidato1 = (object) [
            'documentos' => ['cnpj_imobiliaria', 'contrato_assinado', 'cnh_locatario']
        ];
        $this->assertTrue($spec->isSatisfiedBy($candidato1));
        $verdict1 = $spec->evaluate($candidato1);
        $this->assertTrue($verdict1->isSatisfied);

        // Cenário 2: Faltou contrato_assinado (falha de ALL)
        $candidato2 = (object) [
            'documentos' => ['cnpj_imobiliaria', 'cnh_locatario']
        ];
        $this->assertFalse($spec->isSatisfiedBy($candidato2));
        $verdict2 = $spec->evaluate($candidato2);
        $this->assertFalse($verdict2->isSatisfied);
        $this->assertEquals(['DOC_CONTRATO_ASSINADO'], $verdict2->getCodes());

        // Cenário 3: Faltou identidade (nem RG nem CNH)
        $candidato3 = (object) [
            'documentos' => ['cnpj_imobiliaria', 'contrato_assinado']
        ];
        $this->assertFalse($spec->isSatisfiedBy($candidato3));
    }

    private function testDocumentGroupBuilderWithOneOfSet(): void
    {
        $builder = new DocumentGroupSpecificationBuilder();

        // ONE_OF_SET: exige exatamente 1 tipo de comprovante de renda
        $docRules = [
            new DocumentRuleDefinition('analise', 'holerite', 'contrato', DocumentRequirementMode::ONE_OF_SET, 'comprovante_renda'),
            new DocumentRuleDefinition('analise', 'declaracao_ir', 'contrato', DocumentRequirementMode::ONE_OF_SET, 'comprovante_renda'),
        ];

        $spec = $builder->build($docRules);

        // Apenas holerite -> Satisfeito (1 de 2)
        $cand1 = (object) ['documentos' => ['holerite']];
        $this->assertTrue($spec->isSatisfiedBy($cand1));

        // Ambos presentes -> Reprovado (2 de 2, violou exclusividade)
        $cand2 = (object) ['documentos' => ['holerite', 'declaracao_ir']];
        $this->assertFalse($spec->isSatisfiedBy($cand2));

        // Nenhum presente -> Reprovado (0 de 2)
        $cand3 = (object) ['documentos' => []];
        $this->assertFalse($spec->isSatisfiedBy($cand3));
    }

    private function testDocumentGroupBuilderWithConditional(): void
    {
        $builder = new DocumentGroupSpecificationBuilder();

        // declaração Art. 43 só é exigida se tipo_locacao <> residencial_temporada
        $docRules = [
            new DocumentRuleDefinition(
                grupoCodigo: 'ativacao',
                codigoTipoDocumento: 'declaracao_art43',
                escopo: 'contrato',
                regraObrigatoriedade: DocumentRequirementMode::ALL,
                condicionalExpressao: 'tipo_locacao<>residencial_temporada'
            ),
        ];

        $spec = $builder->build($docRules);

        // Locação residencial tradicional sem declaração -> REPROVADO
        $candTradicional = (object) [
            'tipo_locacao' => 'residencial_urbano',
            'documentos' => []
        ];
        $this->assertFalse($spec->isSatisfiedBy($candTradicional));

        // Locação para temporada sem declaração -> APROVADO (condição dispensada)
        $candTemporada = (object) [
            'tipo_locacao' => 'residencial_temporada',
            'documentos' => []
        ];
        $this->assertTrue($spec->isSatisfiedBy($candTemporada));
    }

    private function testInMemoryRuleCatalog(): void
    {
        $catalog = new InMemoryRuleCatalog();

        $catalog->addRule(new RuleDefinition(
            codigo: 'regra_global',
            nome: 'Regra Global',
            tipoRegra: 'global_tipo',
            prioridade: 10
        ));

        $catalog->addRule(new RuleDefinition(
            codigo: 'regra_sinistro',
            nome: 'Regra de Sinistro',
            tipoRegra: 'sinistro_tipo',
            prioridade: 50,
            escopo: 'sinistro'
        ));

        $catalog->addDocumentRule(new DocumentRuleDefinition(
            grupoCodigo: 'sinistro_ocupado',
            codigoTipoDocumento: 'declaracao_debitos',
            escopo: 'sinistro',
            ordem: 1,
            cenario: 'sinistro_ocupado'
        ));

        // Busca no escopo contrato_locacao -> apenas a global
        $rulesContrato = $catalog->findRules('contrato_locacao');
        $this->assertCount(1, $rulesContrato);
        $this->assertEquals('regra_global', $rulesContrato[0]->getCodigo());

        // Busca no escopo sinistro -> global + sinistro (ordenado por prioridade decrescente)
        $rulesSinistro = $catalog->findRules('sinistro');
        $this->assertCount(2, $rulesSinistro);
        $this->assertEquals('regra_sinistro', $rulesSinistro[0]->getCodigo()); // prioridade 50 vem antes de 10

        // Busca documentos de sinistro_ocupado
        $docs = $catalog->findDocumentRules('sinistro', 'sinistro_ocupado');
        $this->assertCount(1, $docs);
        $this->assertEquals('declaracao_debitos', $docs[0]->getCodigoTipoDocumento());
    }

    private function testDynamicEngineEndToEndSimulation(): void
    {
        $catalog = new InMemoryRuleCatalog();
        $registry = new RuleSpecificationRegistry();

        // 1. Configura Regra de Negócio: Proibição de Dupla Garantia (Art. 43, II)
        $catalog->addRule(new RuleDefinition(
            codigo: 'global_art43_ii_dupla',
            nome: 'Art. 43, II — Proibição de Dupla Garantia',
            tipoRegra: 'valida_ausencia_dupla_garantia_art43_ii',
            acaoAoViolar: RuleAction::BLOCK,
            fundamentoLegal: 'Lei 8.245/91 Art. 43, II',
            mensagemViolacao: 'Dupla garantia (Art. 43, II) — contrato não pode ser ativado.',
            escopo: 'contrato_locacao'
        ));

        // 2. Configura Regra com Ação Alerta: Comprovante de Residência > 90 dias
        $catalog->addRule(new RuleDefinition(
            codigo: 'global_expira_comp_residencia_90',
            nome: 'Comprovante com mais de 90 dias',
            tipoRegra: 'expirar_documento_apos_dias',
            acaoAoViolar: RuleAction::WARN,
            valorInteiro: 90,
            mensagemViolacao: 'Comprovante de residência com mais de 90 dias.',
            escopo: 'contrato_locacao'
        ));

        // 3. Configura Regra com Ação Log: Registro de Temporada
        $catalog->addRule(new RuleDefinition(
            codigo: 'global_art49_temporada',
            nome: 'Auditoria de Temporada',
            tipoRegra: 'permite_antecipacao_temporada_art49',
            acaoAoViolar: RuleAction::LOG,
            fundamentoLegal: 'Lei 8.245/91 Art. 49',
            escopo: 'contrato_locacao'
        ));

        // 4. Configura Requisitos Documentais para ativação do contrato
        $catalog->addDocumentRule(new DocumentRuleDefinition(
            grupoCodigo: 'contrato_locacao_ativacao',
            codigoTipoDocumento: 'contrato_locacao_assinado',
            escopo: 'contrato_locacao',
            regraObrigatoriedade: DocumentRequirementMode::ALL,
            cenario: 'contrato_locacao_ativacao'
        ));

        // Registra Handlers no Registry
        $registry->registerClosure('valida_ausencia_dupla_garantia_art43_ii', function (IRuleDefinition $r): ISpecification {
            return new class($r) extends AbstractSpecification {
                public function __construct(private readonly IRuleDefinition $r) {}
                public function getType(): string { return 'mixed'; }
                public function isSatisfiedBy(mixed $c): bool {
                    return count($c->garantias ?? []) <= 1;
                }
                public function evaluate(mixed $c): SpecificationResult {
                    if ($this->isSatisfiedBy($c)) {
                        return SpecificationResult::satisfied();
                    }
                    return SpecificationResult::failure(
                        $this->r->getMensagemViolacao() ?? 'Dupla garantia detectada.',
                        $this->r->getCodigo(),
                        metadata: [
                            'acao' => $this->r->getAcaoAoViolar()->value,
                            'fundamento_legal' => $this->r->getFundamentoLegal()
                        ]
                    );
                }
            };
        });

        $registry->registerClosure('expirar_documento_apos_dias', function (IRuleDefinition $r): ISpecification {
            return new class($r) extends AbstractSpecification {
                public function __construct(private readonly IRuleDefinition $r) {}
                public function getType(): string { return 'mixed'; }
                public function isSatisfiedBy(mixed $c): bool {
                    return ($c->diasCompResidencia ?? 0) <= ($this->r->getValorInteiro() ?? 90);
                }
                public function evaluate(mixed $c): SpecificationResult {
                    if ($this->isSatisfiedBy($c)) {
                        return SpecificationResult::satisfied();
                    }
                    return SpecificationResult::failure(
                        $this->r->getMensagemViolacao() ?? 'Documento expirado.',
                        $this->r->getCodigo(),
                        metadata: ['acao' => $this->r->getAcaoAoViolar()->value]
                    );
                }
            };
        });

        $registry->registerClosure('permite_antecipacao_temporada_art49', function (IRuleDefinition $r): ISpecification {
            return new class($r) extends AbstractSpecification {
                public function __construct(private readonly IRuleDefinition $r) {}
                public function getType(): string { return 'mixed'; }
                public function isSatisfiedBy(mixed $c): bool {
                    return true; // sempre passa, apenas loga se violar algo
                }
                public function evaluate(mixed $c): SpecificationResult {
                    return SpecificationResult::satisfied();
                }
            };
        });

        $engine = new DynamicSpecificationEngine($catalog, $registry);

        // CASO A: Contrato Perfeito
        $contratoValido = (object) [
            'garantias' => ['garantia_fianca'],
            'diasCompResidencia' => 30,
            'documentos' => ['contrato_locacao_assinado'],
        ];

        $verdictValido = $engine->validate($contratoValido, 'contrato_locacao', 'contrato_locacao_ativacao');
        $this->assertTrue($verdictValido->isSatisfied());
        $this->assertFalse($verdictValido->hasBlockingErrors());
        $this->assertFalse($verdictValido->hasWarnings());
        $this->assertCount(0, $verdictValido);

        // CASO B: Contrato com Violação de Bloqueio (Dupla Garantia: Fiador + Fiança) + Alerta (doc 120 dias)
        $contratoInvalido = (object) [
            'garantias' => ['garantia_fianca', 'fiador'],
            'diasCompResidencia' => 120,
            'documentos' => ['contrato_locacao_assinado'],
        ];

        $verdictInvalido = $engine->validate($contratoInvalido, 'contrato_locacao', 'contrato_locacao_ativacao');
        $this->assertFalse($verdictInvalido->isSatisfied());
        $this->assertTrue($verdictInvalido->hasBlockingErrors());
        $this->assertTrue($verdictInvalido->hasWarnings());

        // Verifica particionamento das falhas
        $this->assertCount(1, $verdictInvalido->getBlockingFailures());
        $this->assertCount(1, $verdictInvalido->getWarningFailures());
        $this->assertEquals(['global_art43_ii_dupla', 'global_expira_comp_residencia_90'], $verdictInvalido->getFailureCodes());
        $this->assertEquals(['Lei 8.245/91 Art. 43, II'], $verdictInvalido->getLegalBases());
    }

    private function testSpecFacadeIntegration(): void
    {
        $registry = Spec::ruleRegistry();
        $this->assertInstanceOf(RuleSpecificationRegistry::class, $registry);

        $engine = Spec::engine();
        $this->assertInstanceOf(DynamicSpecificationEngine::class, $engine);

        // Validação vazia default avalia como AlwaysTrue
        $verdict = $engine->validate(new stdClass(), 'default');
        $this->assertTrue($verdict->isSatisfied());
    }

    /**
     * Lote 1 (consolidação): a spec compilada pelo motor (RuleBoundSpecification) continua
     * traduzível pelos três visitors (SQL, TCriteria, ALinq), inclusive sob NOT e dentro de AND.
     */
    private function testCompiledRuleSpecificationIsTranslatableByVisitors(): void
    {
        $rule = new RuleDefinition(
            codigo: 'valor_minimo',
            nome: 'Valor mínimo',
            tipoRegra: 'valor_minimo',
            acaoAoViolar: RuleAction::WARN,
            escopo: 'x'
        );
        $bound = new RuleBoundSpecification(Spec::property('valor', Spec::greaterThan(10)), $rule);
        $other = new RuleBoundSpecification(Spec::property('ativo', Spec::equalTo(true)), $rule);

        // SQL
        $this->assertEquals('"valor" > :p1', Spec::toSql($bound, 'pgsql')->toSql(), 'RuleBound deve traduzir para SQL');
        $this->assertEquals('NOT ("valor" > :p1)', Spec::toSql(Spec::not($bound), 'pgsql')->toSql(), 'NOT(RuleBound) deve traduzir para SQL');
        $this->assertEquals('("valor" > :p1 AND "ativo" = TRUE)', Spec::toSql(Spec::allOf($bound, $other), 'pgsql')->toSql(), 'AND de RuleBound deve traduzir para SQL');

        // TCriteria (stubs do Adianti carregados sob demanda, como no Módulo 13)
        if (!class_exists(\Adianti\Database\TCriteria::class)) {
            foreach (glob(__DIR__ . '/../Stubs/Adianti/*.php') as $stub) {
                require_once $stub;
            }
        }
        $dump = Spec::toCriteria($bound)->dump();
        $this->assertTrue(str_contains($dump, 'valor > 10'), 'RuleBound deve traduzir para TCriteria: ' . $dump);
        $dumpNot = Spec::toCriteria(Spec::not($bound))->dump();
        $this->assertTrue(str_contains($dumpNot, 'valor'), 'NOT(RuleBound) deve traduzir para TCriteria: ' . $dumpNot);

        // ALinq
        $predicate = ALinqSpecificationVisitor::createPredicate(Spec::allOf($bound, $other));
        $this->assertTrue($predicate((object) ['valor' => 11, 'ativo' => true]), 'predicado ALinq de RuleBound deve aceitar');
        $this->assertFalse($predicate((object) ['valor' => 5, 'ativo' => true]), 'predicado ALinq de RuleBound deve recusar');
    }

    /**
     * BUG-20261007-ZB6A (#28), RN-17 (adendo à spec 011): findRules() honra os filtros de aplicabilidade
     * codigo_produto e codigo_plano, lidos de getParametros(), com semântica AND espelhando escopo/cenário.
     * Reprodução: antes, findRules('contrato', null, ['codigo_produto' => 'A']) devolvia também a regra do produto B
     * e, sem filtro, devolvia as regras restritas a produto.
     */
    private function testFindRulesHonoursProductAndPlanFilters(): void
    {
        $catalog = new InMemoryRuleCatalog();
        $catalog->addRule(new RuleDefinition(codigo: 'R_GLOBAL', nome: 'g', tipoRegra: 't', escopo: 'contrato', prioridade: 1));
        $catalog->addRule(new RuleDefinition(codigo: 'R_PROD_A', nome: 'a', tipoRegra: 't', escopo: 'contrato', prioridade: 2, parametros: ['codigo_produto' => 'A']));
        $catalog->addRule(new RuleDefinition(codigo: 'R_PROD_B', nome: 'b', tipoRegra: 't', escopo: 'contrato', prioridade: 2, parametros: ['codigo_produto' => 'B']));
        $catalog->addRule(new RuleDefinition(codigo: 'R_PLAN_A1', nome: 'a1', tipoRegra: 't', escopo: 'contrato', prioridade: 3, parametros: ['codigo_produto' => 'A', 'codigo_plano' => 'P1']));

        $codes = fn(array $filters) => array_map(fn($r) => $r->getCodigo(), $catalog->findRules('contrato', null, $filters));

        // Reprodução
        $this->assertEquals(['R_PROD_A', 'R_GLOBAL'], $codes(['codigo_produto' => 'A']), 'produto A: global + restrita a A, ordenadas por prioridade');
        $this->assertEquals(['R_GLOBAL'], $codes([]), 'sem produto: regras restritas a produto não se aplicam');

        // Regressão da semântica AND
        $this->assertEquals(['R_PROD_B', 'R_GLOBAL'], $codes(['codigo_produto' => 'B']));
        $this->assertEquals(['R_PLAN_A1', 'R_PROD_A', 'R_GLOBAL'], $codes(['codigo_produto' => 'A', 'codigo_plano' => 'P1']));
        $this->assertEquals(['R_PROD_A', 'R_GLOBAL'], $codes(['codigo_produto' => 'A', 'codigo_plano' => 'P2']), 'plano diferente exclui a regra restrita a P1');
        $this->assertEquals(['R_GLOBAL'], $codes(['codigo_plano' => 'P1']), 'plano sem produto: a regra restrita a produto A não casa');
        $this->assertEquals(['R_GLOBAL'], $codes(['foo' => 'bar']), 'chave desconhecida é ignorada');
        $this->assertEquals(['R_GLOBAL'], $codes(['codigo_produto' => null]), 'filtro nulo equivale a ausente');

        // O motor repassa o contexto: só as regras aplicáveis entram no veredito
        $registry = new RuleSpecificationRegistry();
        $registry->registerClosure('t', fn(IRuleDefinition $r): ISpecification => Spec::alwaysFalse());
        $engine = new DynamicSpecificationEngine($catalog, $registry);
        $this->assertEquals(['R_PROD_A', 'R_GLOBAL'], $engine->validate(new stdClass(), 'contrato', null, ['codigo_produto' => 'A'])->getFailureCodes());
        $this->assertEquals(['R_GLOBAL'], $engine->validate(new stdClass(), 'contrato')->getFailureCodes());
    }

    /**
     * BUG-20261007-ZB6A (#28), RN-17 item 3: data_referencia filtra pela vigência da regra
     * (data_inicio_vigencia <= data_referencia e data_fim_vigencia nula ou >= data_referencia);
     * sem data_referencia a vigência não é avaliada.
     */
    private function testFindRulesHonoursValidityWindow(): void
    {
        $catalog = new InMemoryRuleCatalog();
        $catalog->addRule(new RuleDefinition(codigo: 'R_SEMPRE', nome: 's', tipoRegra: 't', escopo: 'contrato', prioridade: 1));
        $catalog->addRule(new RuleDefinition(codigo: 'R_2026H1', nome: 'h1', tipoRegra: 't', escopo: 'contrato', prioridade: 2,
            parametros: ['data_inicio_vigencia' => '2026-01-01', 'data_fim_vigencia' => '2026-06-30']));
        $catalog->addRule(new RuleDefinition(codigo: 'R_DESDE_JUL', nome: 'j', tipoRegra: 't', escopo: 'contrato', prioridade: 3,
            parametros: ['data_inicio_vigencia' => new \DateTimeImmutable('2026-07-01')]));

        $codes = fn(array $filters) => array_map(fn($r) => $r->getCodigo(), $catalog->findRules('contrato', null, $filters));

        $this->assertEquals(['R_DESDE_JUL', 'R_2026H1', 'R_SEMPRE'], $codes([]), 'sem data_referencia a vigência não é avaliada');
        $this->assertEquals(['R_2026H1', 'R_SEMPRE'], $codes(['data_referencia' => '2026-03-15']));
        $this->assertEquals(['R_2026H1', 'R_SEMPRE'], $codes(['data_referencia' => new \DateTimeImmutable('2026-06-30 23:59:59')]), 'fim de vigência inclusivo, por dia');
        $this->assertEquals(['R_DESDE_JUL', 'R_SEMPRE'], $codes(['data_referencia' => '2026-07-01']), 'início inclusivo; fim nulo = aberta');
        $this->assertEquals(['R_SEMPRE'], $codes(['data_referencia' => '2025-12-31']), 'antes de toda vigência');
        $this->assertThrows(InvalidArgumentException::class, fn() => $codes(['data_referencia' => 'ontem à noite']), 'data inválida é erro de configuração, não filtro silencioso');
    }

    /**
     * BUG-20261007-ZB6A (#28), RN-17 item 4: fromArray() copia as colunas de aplicabilidade da linha
     * (codigo_produto, codigo_plano, data_inicio_vigencia, data_fim_vigencia) para parametros;
     * parametros explícito prevalece; coluna nula ou vazia não é copiada.
     */
    private function testRuleDefinitionHydratesApplicabilityColumnsIntoParametros(): void
    {
        $def = RuleDefinition::fromArray([
            'codigo' => 'r1', 'nome' => 'n', 'tipo_regra' => 't',
            'codigo_produto' => 'A', 'codigo_plano' => 'P1',
            'data_inicio_vigencia' => '2026-01-01', 'data_fim_vigencia' => null,
        ]);
        $this->assertEquals(['codigo_produto' => 'A', 'codigo_plano' => 'P1', 'data_inicio_vigencia' => '2026-01-01'], $def->getParametros());

        $explicit = RuleDefinition::fromArray([
            'codigo' => 'r2', 'nome' => 'n', 'tipo_regra' => 't',
            'codigo_produto' => 'A', 'parametros' => ['codigo_produto' => 'Z', 'limite' => 3],
        ]);
        $this->assertEquals(['codigo_produto' => 'Z', 'limite' => 3], $explicit->getParametros(), 'parametros explícito prevalece sobre a coluna');

        $plain = RuleDefinition::fromArray(['codigo' => 'r3', 'nome' => 'n', 'tipo_regra' => 't', 'codigo_produto' => '']);
        $this->assertEquals([], $plain->getParametros(), 'coluna vazia não vira restrição');
    }
}
