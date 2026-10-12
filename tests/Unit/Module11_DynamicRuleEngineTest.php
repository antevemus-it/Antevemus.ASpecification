<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\Engine\IDocumentRuleDefinition;
use Antevemus\ASpecification\Contracts\Engine\IRuleDefinition;
use Antevemus\ASpecification\Contracts\Engine\IRuleSpecificationHandler;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Engine\DocumentGroupSpecificationBuilder;
use Antevemus\ASpecification\Engine\DocumentRequirementMode;
use Antevemus\ASpecification\Engine\DocumentRuleDefinition;
use Antevemus\ASpecification\Engine\DynamicSpecificationEngine;
use Antevemus\ASpecification\Engine\Exceptions\InvalidConditionalExpressionException;
use Antevemus\ASpecification\Engine\Exceptions\LegacyVocabularyException;
use Antevemus\ASpecification\Engine\Exceptions\MissingAlternativeSetException;
use Antevemus\ASpecification\Engine\Exceptions\MissingRuleHandlerException;
use Antevemus\ASpecification\Engine\Exceptions\RuleEngineException;
use Antevemus\ASpecification\Engine\InMemoryRuleCatalog;
use Antevemus\ASpecification\Engine\RuleAction;
use Antevemus\ASpecification\Engine\RuleBoundSpecification;
use Antevemus\ASpecification\Engine\RuleCompilationWarning;
use Antevemus\ASpecification\Linq\ALinqSpecificationVisitor;
use Antevemus\ASpecification\Engine\RuleDefinition;
use Antevemus\ASpecification\Engine\RuleEngineVerdict;
use Antevemus\ASpecification\Engine\RuleSpecificationRegistry;
use Antevemus\ASpecification\Results\FailureSeverity;
use Antevemus\ASpecification\Results\SpecificationFailure;
use Antevemus\ASpecification\Results\SpecificationResult;
use Antevemus\ASpecification\Contracts\Engine\IDocumentPresenceEvaluator;
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
 * Forward 022 (1.7.0): vocabulário canônico em inglês. Forward 033 D10 (2.0.0): o português, que
 * seguiu como legado da 1.7.0 à 1.14.x, é recusado (getters removidos; chaves, valores e filtros em
 * português lançam LegacyVocabularyException apontando o UPGRADE-2.0.md).
 *
 * @version    2.0.0
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
        $this->testRuleDefinitionHydratesApplicabilityColumnsIntoParameters();

        // Forward 020 RN-04 (1.5.0): FailureSeverity preenchida pelo motor a partir de RuleAction.
        $this->testFailureSeverityContract();
        $this->testEngineStampsSeverityFromRuleAction();
        $this->testSeverityFollowsEffectiveActionAndEvaluationErrors();
        $this->testDocumentFailuresAreErrorSeverity();

        // Forward 019 RN-11 (1.6.0): RuleCompilationWarning no modo de validação do catálogo.
        $this->testCatalogValidationWarnsDegenerateRules();

        // Forward 022 (1.7.0): inglês canônico. Forward 033 D10 (2.0.0): o português, legado até a
        // 1.14.x, é recusado com LegacyVocabularyException apontando o UPGRADE-2.0.md.
        $this->testEnglishVocabularyIsCanonical();
        $this->testPortugueseGettersWereRemoved();
        $this->testFromArrayRefusesPortugueseKeys();
        $this->testRuleActionRefusesPortugueseValues();
        $this->testPortugueseFilterAndRestrictionKeysAreRefused();
        $this->testFailureMetadataAndDocumentPathsAreEnglish();
    }

    /**
     * Forward 022 RN-01/RN-02/RN-05: as interfaces só têm os nomes em inglês; RuleAction tem os valores
     * em inglês; o motor carimba a ação em inglês; a exceção de handler ausente expõe nomes em inglês.
     */
    private function testEnglishVocabularyIsCanonical(): void
    {
        $portuguese = ['getCodigo', 'getNome', 'getDescricao', 'getTipoRegra', 'getAcaoAoViolar', 'getValorInteiro',
            'getValorDecimal', 'getValorTexto', 'getFundamentoLegal', 'getMensagemViolacao', 'getCondicionalExpressao',
            'getPrioridade', 'getEscopo', 'getCenario', 'getParametros', 'getGrupoCodigo', 'getCodigoTipoDocumento',
            'getRegraObrigatoriedade', 'getCodigoSetAlternativas', 'getOrdem'];
        foreach ([IRuleDefinition::class, IDocumentRuleDefinition::class] as $interface) {
            foreach ($portuguese as $method) {
                $this->assertFalse(method_exists($interface, $method), "{$interface} não declara mais {$method}()");
            }
        }
        foreach (['getCode', 'getName', 'getDescription', 'getRuleType', 'getViolationAction', 'getIntValue', 'getDecimalValue',
            'getTextValue', 'getLegalBasis', 'getViolationMessage', 'getConditionExpression', 'getPriority', 'getScope',
            'getScenario', 'getParameters', 'isActive'] as $method) {
            $this->assertTrue(method_exists(IRuleDefinition::class, $method), "IRuleDefinition::{$method}()");
        }
        foreach (['getGroupCode', 'getScope', 'getScenario', 'getDocumentTypeCode', 'getRequirementMode',
            'getAlternativeSetCode', 'getConditionExpression', 'getSortOrder', 'isActive'] as $method) {
            $this->assertTrue(method_exists(IDocumentRuleDefinition::class, $method), "IDocumentRuleDefinition::{$method}()");
        }

        $this->assertEquals(['block', 'warn', 'log'], array_map(static fn(RuleAction $a): string => $a->value, RuleAction::cases()));
        $this->assertEquals(['scope', 'scenario', 'context'], array_map(
            static fn(\ReflectionParameter $p): string => $p->getName(),
            array_slice((new \ReflectionMethod(DynamicSpecificationEngine::class, 'validate'))->getParameters(), 1)
        ));
        $this->assertEquals('documentTypeCode', (new \ReflectionMethod(IDocumentPresenceEvaluator::class, 'hasDocument'))->getParameters()[1]->getName());

        $e = new MissingRuleHandlerException('tipo_x', 'R1');
        $this->assertEquals('tipo_x', $e->ruleType);
        $this->assertEquals('R1', $e->ruleCode);
    }

    /**
     * Forward 033 D10 (2.0.0): os getters em português, depreciados na 1.7.0, saíram das classes.
     */
    private function testPortugueseGettersWereRemoved(): void
    {
        $removed = [
            RuleDefinition::class => ['getCodigo', 'getNome', 'getDescricao', 'getTipoRegra', 'getAcaoAoViolar', 'getValorInteiro',
                'getValorDecimal', 'getValorTexto', 'getFundamentoLegal', 'getMensagemViolacao', 'getCondicionalExpressao',
                'getPrioridade', 'getEscopo', 'getCenario', 'getParametros'],
            DocumentRuleDefinition::class => ['getGrupoCodigo', 'getCodigoTipoDocumento', 'getEscopo', 'getCenario',
                'getRegraObrigatoriedade', 'getCodigoSetAlternativas', 'getCondicionalExpressao', 'getOrdem'],
        ];
        foreach ($removed as $class => $methods) {
            foreach ($methods as $method) {
                $this->assertFalse(method_exists($class, $method), "{$class}::{$method}() foi removido na 2.0.0");
            }
        }
    }

    /**
     * Confere uma recusa do vocabulário em português: a exceção certa, o nome em português, o
     * substituto em inglês e o guia de atualização na mensagem.
     */
    private function assertLegacyRefused(\Closure $call, string $legacy, string $replacement, string $label): LegacyVocabularyException
    {
        $e = $this->assertThrows(LegacyVocabularyException::class, $call, "{$label}: deveria recusar \"{$legacy}\"");
        $this->assertEquals($legacy, $e->legacy, "{$label}: nome recusado");
        $this->assertEquals($replacement, $e->replacement, "{$label}: substituto em inglês");
        $this->assertTrue(str_contains($e->getMessage(), 'UPGRADE-2.0.md') && str_contains($e->getMessage(), "\"{$legacy}\""), "{$label}: " . $e->getMessage());
        $this->assertTrue($e instanceof RuleEngineException, "{$label}: é uma RuleEngineException");

        return $e;
    }

    /**
     * Forward 033 D10: fromArray() recusa cada chave em português (sinônimos inclusive), mesmo com a
     * inglesa ao lado, e as chaves de aplicabilidade em português dentro de parameters; o inglês
     * continua hidratando como antes. Nunca ignora em silêncio.
     */
    private function testFromArrayRefusesPortugueseKeys(): void
    {
        $english = RuleDefinition::fromArray([
            'code' => 'R1', 'name' => 'Regra', 'description' => 'd', 'rule_type' => 't', 'violation_action' => 'warn',
            'int_value' => '3', 'decimal_value' => '1.5', 'text_value' => 'x', 'legal_basis' => 'Art. 1',
            'violation_message' => 'm', 'condition_expression' => 'a=b', 'priority' => 7, 'scope' => 'contrato',
            'scenario' => 'ativacao', 'parameters' => ['k' => 1], 'active' => 'Y',
            'product_code' => 'A', 'plan_code' => 'P1', 'valid_from' => '2026-01-01', 'valid_until' => '2026-12-31',
        ]);
        $this->assertEquals(RuleAction::WARN, $english->getViolationAction());
        $this->assertEquals(
            ['k' => 1, 'product_code' => 'A', 'plan_code' => 'P1', 'valid_from' => '2026-01-01', 'valid_until' => '2026-12-31'],
            $english->getParameters()
        );

        $base = ['code' => 'R1', 'name' => 'n', 'rule_type' => 't'];
        $legacyRuleKeys = RuleDefinition::LEGACY_KEYS + array_flip(RuleDefinition::LEGACY_APPLICABILITY_COLUMNS);
        $this->assertCount(22, $legacyRuleKeys, 'as 22 chaves portuguesas da regra (sinônimos inclusive)');
        foreach ($legacyRuleKeys as $legacy => $replacement) {
            $e = $this->assertLegacyRefused(fn() => RuleDefinition::fromArray($base + [$legacy => 'x']), $legacy, $replacement, "regra[{$legacy}]");
            $this->assertTrue(str_contains($e->getMessage(), 'catalog row "R1"'), 'a mensagem nomeia a linha');
            // Mesmo com a chave em inglês presente (até a 1.14 o inglês vencia e o português era ignorado)
            $this->assertLegacyRefused(fn() => RuleDefinition::fromArray($base + [$replacement => 'y', $legacy => 'x']), $legacy, $replacement, "regra[{$legacy}] com o inglês");
        }
        foreach (RuleDefinition::LEGACY_APPLICABILITY_COLUMNS as $replacement => $legacy) {
            $this->assertLegacyRefused(fn() => RuleDefinition::fromArray($base + ['parameters' => [$legacy => 'A']]), $legacy, $replacement, "parameters[{$legacy}]");
        }

        $docBase = ['group_code' => 'g', 'document_type_code' => 'rg', 'scope' => 'contrato'];
        $this->assertEquals('g', DocumentRuleDefinition::fromArray($docBase)->getGroupCode());
        $this->assertCount(14, DocumentRuleDefinition::LEGACY_KEYS);
        foreach (DocumentRuleDefinition::LEGACY_KEYS as $legacy => $replacement) {
            $this->assertLegacyRefused(fn() => DocumentRuleDefinition::fromArray($docBase + [$legacy => 'x']), $legacy, $replacement, "documento[{$legacy}]");
        }

        // Sem aviso em runtime: a recusa é exceção, não notice.
        $notices = [];
        set_error_handler(static function (int $errno, string $message) use (&$notices): bool {
            $notices[] = $message;
            return true;
        });
        try {
            RuleDefinition::fromArray($base);
        } finally {
            restore_error_handler();
        }
        $this->assertEquals([], $notices);
    }

    /**
     * Forward 033 D10: chaves de metadado só em inglês; o handler que grava `acao` é recusado, o
     * caminho e as chaves da falha de documento são ingleses, e o portador de documentos em português
     * (->documentos, ['documentos'], hasDocumento(), getDocumentos()) é recusado, não lido como vazio.
     */
    private function testFailureMetadataAndDocumentPathsAreEnglish(): void
    {
        $catalog = new InMemoryRuleCatalog();
        $catalog->addRule(new RuleDefinition(code: 'R-EN', name: 'Idade mínima', ruleType: 'min_age', violationAction: RuleAction::WARN, legalBasis: 'Lei X', scope: 's'));
        $registry = Spec::ruleRegistry()->registerClosure('min_age', fn() => Spec::property('age', Spec::greaterThanOrEqualTo(18)));
        $verdict = Spec::engine($catalog, $registry)->validate((object) ['age' => 16], 's');
        $failure = $verdict->getWarningFailures()[0];
        foreach (['action' => 'warn', 'rule_code' => 'R-EN', 'rule_type' => 'min_age', 'rule_name' => 'Idade mínima', 'legal_basis' => 'Lei X'] as $key => $value) {
            $this->assertEquals($value, $failure->metadata[$key] ?? null, "metadata[{$key}]");
        }
        $this->assertTrue(array_key_exists('original_message', $failure->metadata), 'original_message');
        $this->assertTrue(array_key_exists('original_messages', $failure->metadata) && array_key_exists('original_failures', $failure->metadata));
        foreach (['acao', 'regra', 'tipo_regra', 'nome_regra', 'prioridade', 'mensagem_original', 'fundamento_legal', 'mensagens_originais', 'falhas_originais'] as $legacy) {
            $this->assertFalse(array_key_exists($legacy, $failure->metadata), "a duplicata portuguesa {$legacy} saiu na 2.0.0");
        }

        // Handler que ainda grava a ação ou o fundamento em português: recusado, não triado pela ação da regra
        foreach ([['acao', 'action', ['acao' => 'warn']], ['acao_ao_violar', 'violation_action', ['acao_ao_violar' => 'warn']], ['alertar', 'warn', ['action' => 'alertar']]] as [$legacy, $replacement, $metadata]) {
            $handlerRegistry = Spec::ruleRegistry()->registerClosure('min_age', fn() => new class ($metadata) extends AbstractSpecification {
                /** @param array<string, mixed> $metadata */
                public function __construct(private readonly array $metadata) {}
                public function getType(): string { return 'mixed'; }
                public function isSatisfiedBy(mixed $c): bool { return false; }
                public function evaluate(mixed $c): SpecificationResult { return SpecificationResult::failure('handler', 'H', metadata: $this->metadata); }
            });
            $this->assertLegacyRefused(fn() => Spec::engine($catalog, $handlerRegistry)->validate((object) ['age' => 16], 's'), $legacy, $replacement, "handler {$legacy}");
        }

        $doc = new DocumentRuleDefinition(groupCode: 'G', scope: 's', documentTypeCode: 'rg', requirementMode: DocumentRequirementMode::ALL);
        $docCatalog = new InMemoryRuleCatalog();
        $docCatalog->addDocumentRule($doc);
        $engine = Spec::engine($docCatalog);
        $docFailure = $engine->validate((object) ['documents' => []], 's')->getBlockingFailures()[0];
        $this->assertEquals('documents.rg', $docFailure->property, 'caminho da falha de documento em inglês');
        $this->assertEquals(['action' => 'block', 'document_type' => 'rg', 'group_code' => 'G', 'requirement_mode' => 'all'], $docFailure->metadata, 'só as chaves em inglês');
        $this->assertTrue($engine->validate((object) ['documents' => ['rg']], 's')->isSatisfied(), "presença lida de ->documents");
        $this->assertTrue($engine->validate(['documents' => ['rg']], 's')->isSatisfied(), "presença lida de ['documents']");

        $holders = [
            'documentos' => [(object) ['documentos' => ['rg']], 'documents'],
            "['documentos']" => [['documentos' => ['rg']], 'documents'],
            'hasDocumento()' => [new class { public function hasDocumento(string $t): bool { return true; } }, 'hasDocument()'],
            'getDocumentos()' => [new class { public function getDocumentos(): array { return ['rg']; } }, 'getDocuments()'],
        ];
        foreach ($holders as $label => [$holder, $replacement]) {
            $legacy = $label === "['documentos']" ? 'documentos' : $label;
            $verdict = $engine->validate($holder, 's');
            $this->assertTrue($verdict->hasEvaluationErrors(), "portador {$label}: erro de avaliação (bloqueia), nunca \"documento ausente\" em silêncio");
            $this->assertLegacyRefused(fn() => (new DocumentGroupSpecificationBuilder())->build([$doc])->isSatisfiedBy($holder), $legacy, $replacement, "portador {$label}");
        }
        $sets = new DocumentRuleDefinition(groupCode: 'G', scope: 's', documentTypeCode: 'rg', requirementMode: DocumentRequirementMode::ANY, alternativeSetCode: 'id');
        $setFailure = (new DocumentGroupSpecificationBuilder())->build([$sets])->evaluate((object) ['documents' => []])->failures[0];
        $this->assertEquals(['action', 'alternative_set', 'mode', 'accepted_documents', 'codes', 'present', 'expected', 'received'], array_keys($setFailure->metadata), 'set: só as chaves em inglês');
    }

    /**
     * Forward 033 D10: os valores de RuleAction em português são recusados (inclusive em caixa e espaço
     * diferentes), em fromOrDefault(), em fromMetadata() e na triagem do veredito; valor desconhecido
     * continua caindo no padrão.
     */
    private function testRuleActionRefusesPortugueseValues(): void
    {
        $this->assertEquals(['block', 'warn', 'log'], array_map(static fn(RuleAction $a): string => $a->value, RuleAction::cases()));
        $this->assertEquals(RuleAction::WARN, RuleAction::fromOrDefault('WARN'));
        $this->assertEquals(RuleAction::LOG, RuleAction::fromOrDefault(' log '));
        $this->assertEquals(RuleAction::LOG, RuleAction::fromOrDefault('desconhecido', RuleAction::LOG), 'valor desconhecido cai no padrão, como antes');
        $this->assertEquals(RuleAction::BLOCK, RuleAction::fromOrDefault(null));

        foreach (['bloquear' => 'block', 'alertar' => 'warn', ' APENAS_LOG ' => 'log', 'Alertar' => 'warn'] as $legacy => $replacement) {
            $this->assertLegacyRefused(fn() => RuleAction::fromOrDefault($legacy, RuleAction::LOG), $legacy, $replacement, "fromOrDefault({$legacy})");
        }
        $this->assertLegacyRefused(fn() => RuleDefinition::fromArray(['code' => 'R', 'name' => 'n', 'rule_type' => 't', 'violation_action' => 'alertar']), 'alertar', 'warn', 'coluna violation_action');

        $this->assertEquals(RuleAction::WARN, RuleAction::fromMetadata(['action' => 'warn']));
        $this->assertEquals(RuleAction::LOG, RuleAction::fromMetadata(['violation_action' => RuleAction::LOG]));
        $this->assertEquals(RuleAction::WARN, RuleAction::fromMetadata([], RuleAction::WARN));
        $this->assertLegacyRefused(fn() => RuleAction::fromMetadata(['acao' => 'warn']), 'acao', 'action', 'metadado acao');

        foreach ([[['acao' => 'warn'], 'acao', 'action'], [['action' => 'apenas_log'], 'apenas_log', 'log'], [['fundamento_legal' => 'Lei X'], 'fundamento_legal', 'legal_basis']] as [$metadata, $legacy, $replacement]) {
            $this->assertLegacyRefused(
                fn() => RuleEngineVerdict::fromSpecificationResult(SpecificationResult::failure('a', 'A', metadata: $metadata)),
                $legacy,
                $replacement,
                'veredito ' . json_encode($metadata)
            );
        }
        $verdict = RuleEngineVerdict::fromSpecificationResult(SpecificationResult::combine(
            SpecificationResult::failure('a', 'A', metadata: ['action' => 'warn', 'legal_basis' => 'Lei X']),
            SpecificationResult::failure('b', 'B', metadata: ['action' => 'log']),
            SpecificationResult::failure('c', 'C', metadata: ['action' => 'block'])
        ));
        $this->assertEquals([['A'], ['B'], ['C']], [
            array_map(static fn(SpecificationFailure $f): ?string => $f->code, $verdict->getWarningFailures()),
            array_map(static fn(SpecificationFailure $f): ?string => $f->code, $verdict->getLogFailures()),
            array_map(static fn(SpecificationFailure $f): ?string => $f->code, $verdict->getBlockingFailures()),
        ], 'triagem pelos valores em inglês');
        $this->assertEquals(['Lei X'], $verdict->getLegalBases());
    }

    /**
     * Forward 033 D10: as chaves de filtro (contexto) em português e as restrições em português dentro
     * de parameters (regra montada em código) são recusadas, mesmo sem nenhuma regra que as leria; o
     * inglês continua filtrando como antes.
     */
    private function testPortugueseFilterAndRestrictionKeysAreRefused(): void
    {
        $catalog = new InMemoryRuleCatalog();
        $catalog->addRule(new RuleDefinition(code: 'R_GLOBAL', name: 'g', ruleType: 't', scope: 'contrato', priority: 1));
        $catalog->addRule(new RuleDefinition(code: 'R_EN', name: 'en', ruleType: 't', scope: 'contrato', priority: 2,
            parameters: ['product_code' => 'A', 'valid_until' => '2026-06-30']));
        $codes = fn(InMemoryRuleCatalog $c, array $filters) => array_map(fn($r) => $r->getCode(), $c->findRules('contrato', null, $filters));

        $this->assertEquals(['R_EN', 'R_GLOBAL'], $codes($catalog, ['product_code' => 'A']));
        $this->assertEquals(['R_GLOBAL'], $codes($catalog, ['product_code' => 'A', 'reference_date' => '2026-07-01']));
        foreach (InMemoryRuleCatalog::LEGACY_FILTER_KEYS as $replacement => $legacy) {
            $this->assertLegacyRefused(fn() => $codes($catalog, [$legacy => 'A']), $legacy, $replacement, "filtro {$legacy}");
            $this->assertLegacyRefused(fn() => $codes($catalog, [$replacement => 'A', $legacy => 'A']), $legacy, $replacement, "filtro {$legacy} com o inglês");
            $this->assertLegacyRefused(fn() => $codes(new InMemoryRuleCatalog(), [$legacy => 'A']), $legacy, $replacement, "filtro {$legacy} sem regras");
        }
        $this->assertLegacyRefused(
            fn() => Spec::engine($catalog, Spec::ruleRegistry()->registerClosure('t', fn() => Spec::alwaysTrue()))->validate(new stdClass(), 'contrato', null, ['codigo_produto' => 'A']),
            'codigo_produto',
            'product_code',
            'contexto do validate()'
        );

        $withLegacy = new InMemoryRuleCatalog([new RuleDefinition(code: 'R_PT', name: 'pt', ruleType: 't', scope: 'contrato',
            parameters: ['codigo_produto' => 'A'])]);
        $e = $this->assertLegacyRefused(fn() => $codes($withLegacy, ['product_code' => 'A']), 'codigo_produto', 'product_code', 'restrição em parameters');
        $this->assertTrue(str_contains($e->getMessage(), 'rule "R_PT"'), 'nomeia a regra');

        $this->assertEquals('product_code', InMemoryRuleCatalog::FILTER_PRODUCT);
        $this->assertEquals('reference_date', InMemoryRuleCatalog::FILTER_REFERENCE_DATE);
        $this->assertEquals(['product_code', 'plan_code', 'valid_from', 'valid_until'], RuleDefinition::APPLICABILITY_COLUMNS);
    }

    /**
     * Forward 014 RN-02 / RN-10: transcrição fiel do exemplo 7 do README (EN, v1.2.0 linhas 233-268).
     * Antes do must(): "Call to undefined method AbstractCompositeSpecification@anonymous::must()".
     */
    private function testReadmeExample7RunsAsWritten(): void
    {
        $myCatalogRepository = new InMemoryRuleCatalog();
        $myCatalogRepository->addRule(new RuleDefinition(
            code: 'max_occ',
            name: 'Max occurrences per contract',
            ruleType: 'max_occurrences_per_contract',
            violationAction: RuleAction::BLOCK,
            intValue: 2,
            legalBasis: 'Art. X',
            violationMessage: 'Too many occurrences',
            scope: 'rental_contract',
            scenario: 'activation'
        ));
        $myCatalogRepository->addRule(new RuleDefinition(
            code: 'warn_occ',
            name: 'Watch occurrences',
            ruleType: 'max_occurrences_per_contract',
            violationAction: RuleAction::WARN,
            intValue: 1,
            violationMessage: 'Watch occurrences',
            scope: 'rental_contract'
        ));
        $contract = new Module11Contract(3);

        // 1. Configure the Registry with pluggable rule handlers
        $registry = Spec::ruleRegistry();
        $registry->registerClosure('max_occurrences_per_contract', function ($rule) {
            return Spec::specify(Module11Contract::class)
                ->must(fn($c) => $c->getOccurrencesCount() <= $rule->getIntValue(), $rule->getCode(), $rule->getViolationMessage());
        });

        // 2. Instantiate the Engine connected to the relational catalog repository
        $engine = Spec::engine($myCatalogRepository, $registry);

        // 3. Validate target entity for a specific scope and scenario
        $verdict = $engine->validate(
            target: $contract,
            scope: 'rental_contract',
            scenario: 'activation'
        );

        $this->assertTrue($verdict->hasBlockingErrors(), 'README ex. 7: contrato com 3 ocorrências deve bloquear');
        $blocking = $verdict->getBlockingFailures();
        $this->assertCount(1, $blocking);
        $this->assertEquals('max_occ', $blocking[0]->code);
        $this->assertEquals('Too many occurrences', $blocking[0]->message);
        $this->assertEquals(['Art. X'], $verdict->getLegalBases());
        $this->assertTrue($verdict->hasWarnings());
        $this->assertEquals('warn_occ', $verdict->getWarningFailures()[0]->code);

        $ok = $engine->validate(target: new Module11Contract(1), scope: 'rental_contract', scenario: 'activation');
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
            code: 'comp_residencia_90',
            name: 'Comprovante com mais de 90 dias',
            ruleType: 'dias_maximos_comprovante',
            violationAction: RuleAction::WARN,
            intValue: 90,
            legalBasis: 'Política Interna 7.2',
            violationMessage: 'Comprovante de residência vencido.',
            scope: 'contrato_locacao'
        ));

        // Handler cru: nenhum encanamento de ação/base legal.
        $registry->registerClosure('dias_maximos_comprovante', fn(IRuleDefinition $r): ISpecification =>
            Spec::property('diasCompResidencia', Spec::lessThanOrEqualTo($r->getIntValue() ?? 90))
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
        $this->assertEquals('warn', $failure->metadata['action']);
        $this->assertEquals('comp_residencia_90', $failure->metadata['rule_code']);
        $this->assertEquals('dias_maximos_comprovante', $failure->metadata['rule_type']);
        $this->assertTrue(isset($failure->metadata['original_message']), 'A mensagem técnica da folha fica preservada no metadata');

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
            code: 'regra_warn',
            name: 'Regra alertar no catálogo',
            ruleType: 'handler_personalizado',
            violationAction: RuleAction::WARN,
            legalBasis: 'Base do catálogo',
            violationMessage: 'Mensagem do catálogo',
            scope: 'x'
        ));

        $registry->registerClosure('handler_personalizado', function (IRuleDefinition $r): ISpecification {
            return new class extends AbstractSpecification {
                public function getType(): string { return 'mixed'; }
                public function isSatisfiedBy(mixed $c): bool { return false; }
                public function evaluate(mixed $c): SpecificationResult {
                    return SpecificationResult::failure(
                        'Mensagem do handler',
                        'CODIGO_DO_HANDLER',
                        metadata: ['action' => 'block', 'legal_basis' => 'Base do handler']
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
        $this->assertEquals('regra_warn', $failure->metadata['rule_code']);
    }

    /**
     * §2.1 + §1.1: erro de avaliação (propriedade inexistente) nunca vira WARN/LOG; é BLOCK.
     */
    private function testEvaluationErrorIsAlwaysBlockingInVerdict(): void
    {
        $catalog = new InMemoryRuleCatalog();
        $registry = new RuleSpecificationRegistry();

        $catalog->addRule(new RuleDefinition(
            code: 'regra_log',
            name: 'Regra só log',
            ruleType: 'propriedade_inexistente',
            violationAction: RuleAction::LOG,
            scope: 'x'
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
            SpecificationResult::failure('a', 'A', metadata: ['action' => 'warn']),
            SpecificationResult::failure('b', null, metadata: ['action' => 'warn']),
            SpecificationResult::failure('c', 'C', metadata: ['action' => 'log'])
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
        $catalog->addDocumentRule(new DocumentRuleDefinition('ativacao', 'doc_contrato', 'contrato', scenario: 'ativacao'));
        $catalog->addDocumentRule(new DocumentRuleDefinition('ativacao', 'doc_sinistro', 'sinistro', scenario: 'ativacao'));
        // Documento global do escopo (sem cenário).
        $catalog->addDocumentRule(new DocumentRuleDefinition('contrato', 'doc_global_contrato', 'contrato'));
        // Cenários do mesmo escopo e um escopo com nome parecido: nada casa por prefixo nem por grupo.
        $catalog->addDocumentRule(new DocumentRuleDefinition('sinistro_ocupado', 'termo_ocupado', 'sinistro', scenario: 'sinistro:ocupado'));
        $catalog->addDocumentRule(new DocumentRuleDefinition('sinistro_desocupado', 'chaves_desocupado', 'sinistro', scenario: 'sinistro:desocupado'));
        $catalog->addDocumentRule(new DocumentRuleDefinition('contrato', 'doc_outro_escopo', 'contratos_x'));
        // Grupo com nome de escopo alheio: o grupo nunca decide o escopo.
        $catalog->addDocumentRule(new DocumentRuleDefinition('contrato', 'doc_grupo_enganoso', 'sinistro'));

        $docs = array_map(fn($d) => $d->getDocumentTypeCode(), $catalog->findDocumentRules('contrato', 'ativacao'));
        sort($docs);
        $this->assertEquals(['doc_contrato', 'doc_global_contrato'], $docs, 'contrato/ativacao não pode ver doc_sinistro nem contratos_x');

        $docs = array_map(fn($d) => $d->getDocumentTypeCode(), $catalog->findDocumentRules('sinistro', 'ativacao'));
        sort($docs);
        $this->assertEquals(['doc_grupo_enganoso', 'doc_sinistro'], $docs, 'global do escopo sinistro entra em qualquer cenário de sinistro');

        $docs = array_map(fn($d) => $d->getDocumentTypeCode(), $catalog->findDocumentRules('sinistro', 'sinistro:ocupado'));
        sort($docs);
        $this->assertEquals(['doc_grupo_enganoso', 'termo_ocupado'], $docs, 'sinistro:ocupado não pode receber os documentos de sinistro:desocupado');

        $docs = array_map(fn($d) => $d->getDocumentTypeCode(), $catalog->findDocumentRules('contrato'));
        $this->assertEquals(['doc_global_contrato'], $docs, 'escopo contrato sem cenário: só o global, nunca contratos_x nem o grupo chamado contrato de outro escopo');

        $docs = array_map(fn($d) => $d->getDocumentTypeCode(), $catalog->findDocumentRules('contrato', 'sinistro:ocupado'));
        $this->assertEquals(['doc_global_contrato'], $docs, 'cenário de outro escopo não traz nada além do global');
    }

    /**
     * §1.5: validar sem cenário aplica só as regras globais do escopo, não as de todos os cenários.
     */
    private function testCatalogWithoutScenarioAppliesOnlyGlobalRules(): void
    {
        $catalog = new InMemoryRuleCatalog();
        $catalog->addRule(new RuleDefinition(code: 'global_escopo', name: 'g', ruleType: 't', scope: 'contrato'));
        $catalog->addRule(new RuleDefinition(code: 'so_ativacao', name: 'a', ruleType: 't', scope: 'contrato', scenario: 'ativacao'));
        $catalog->addRule(new RuleDefinition(code: 'so_cancelamento', name: 'c', ruleType: 't', scope: 'contrato', scenario: 'cancelamento'));

        $codes = array_map(fn($r) => $r->getCode(), $catalog->findRules('contrato'));
        $this->assertEquals(['global_escopo'], $codes, 'sem cenário = só regras sem cenário');

        $codes = array_map(fn($r) => $r->getCode(), $catalog->findRules('contrato', 'ativacao'));
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
        $this->assertTrue($spec->isSatisfiedBy((object) ['tipo' => 'pf', 'documents' => ['holerite']]));
        // Pessoa física sem nada: 0 presentes -> reprovado (antes aprovava, contando a condicional como presente)
        $this->assertFalse($spec->isSatisfiedBy((object) ['tipo' => 'pf', 'documents' => []]));
        // Pessoa jurídica com os dois: 2 presentes -> reprovado
        $this->assertFalse($spec->isSatisfiedBy((object) ['tipo' => 'pj', 'documents' => ['holerite', 'declaracao_ir']]));
        // Pessoa jurídica só com declaração: 1 -> satisfeito
        $this->assertTrue($spec->isSatisfiedBy((object) ['tipo' => 'pj', 'documents' => ['declaracao_ir']]));

        // Set em que nenhum documento se aplica: nada é exigido
        $specTodosCondicionais = $builder->build([
            new DocumentRuleDefinition('analise', 'a', 'contrato', DocumentRequirementMode::ONE_OF_SET, 's', 'tipo=pj'),
            new DocumentRuleDefinition('analise', 'b', 'contrato', DocumentRequirementMode::ONE_OF_SET, 's', 'tipo=pj'),
        ]);
        $this->assertTrue($specTodosCondicionais->isSatisfiedBy((object) ['tipo' => 'pf', 'documents' => []]));
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
        $this->assertTrue($spec->isSatisfiedBy((object) ['documents' => ['rg']]));
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
            'group_code' => 'contrato_locacao_ativacao',
            'document_type_code' => 'rg',
        ]));
        $this->assertThrows(InvalidArgumentException::class, fn() => DocumentRuleDefinition::fromArray([
            'group_code' => 'contrato_locacao_ativacao',
            'document_type_code' => 'rg',
            'scope' => '',
        ]));

        $def = DocumentRuleDefinition::fromArray([
            'group_code' => 'sinistro_ocupado',
            'document_type_code' => 'declaracao_debitos',
            'scope' => 'sinistro',
            'scenario' => 'sinistro:ocupado',
        ]);
        $this->assertEquals('sinistro', $def->getScope());
        $this->assertEquals('sinistro:ocupado', $def->getScenario());
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

        $result = $spec->evaluate((object) ['documents' => []]);
        $this->assertFalse($result->isSatisfied);
        $this->assertCount(1, $result->failures, 'um requisito, uma falha');
        $failure = $result->failures[0];
        $this->assertEquals('DOC_SET_IDENTIDADE', $failure->code);
        $this->assertEquals(['rg', 'cnh'], $failure->metadata['accepted_documents']);
        $this->assertEquals(['DOC_RG', 'DOC_CNH'], $failure->metadata['codes']);
        $this->assertEquals('block', $failure->metadata['action']);

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

        $this->assertEquals(RuleAction::BLOCK, RuleAction::fromOrDefault('block'));
        $this->assertEquals(RuleAction::WARN, RuleAction::fromOrDefault('warn'));
        $this->assertEquals(RuleAction::LOG, RuleAction::fromOrDefault('log'));
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
            'code' => 'global_max_ocorrencias_3',
            'name' => 'Máximo 3 ocorrências por contrato',
            'description' => 'Limite operacional de sinistros simultâneos.',
            'rule_type' => 'max_ocorrencias_por_contrato',
            'violation_action' => 'block',
            'int_value' => '3',
            'legal_basis' => 'Política de Risco Art. 12',
            'violation_message' => 'Este contrato já possui 3 ocorrências.',
            'priority' => 100,
            'active' => 'Y',
        ];

        $def = RuleDefinition::fromArray($row);

        $this->assertEquals('global_max_ocorrencias_3', $def->getCode());
        $this->assertEquals('Máximo 3 ocorrências por contrato', $def->getName());
        $this->assertEquals('max_ocorrencias_por_contrato', $def->getRuleType());
        $this->assertEquals(RuleAction::BLOCK, $def->getViolationAction());
        $this->assertEquals(3, $def->getIntValue());
        $this->assertEquals('Política de Risco Art. 12', $def->getLegalBasis());
        $this->assertEquals('Este contrato já possui 3 ocorrências.', $def->getViolationMessage());
        $this->assertEquals(100, $def->getPriority());
        $this->assertTrue($def->isActive());
    }

    private function testDocumentRuleDefinitionHydration(): void
    {
        $row = [
            'group_code' => 'contrato_locacao_ativacao',
            'document_type_code' => 'cnh_locatario',
            'requirement_mode' => 'any',
            'alternative_set_code' => 'identidade',
            'condition_expression' => 'tipo_locacao<>residencial_temporada',
            'sort_order' => 70,
            'active' => 'Y',
            'scope' => 'contrato_locacao',
        ];

        $def = DocumentRuleDefinition::fromArray($row);

        $this->assertEquals('contrato_locacao_ativacao', $def->getGroupCode());
        $this->assertEquals('contrato_locacao', $def->getScope());
        $this->assertTrue($def->getScenario() === null);
        $this->assertEquals('cnh_locatario', $def->getDocumentTypeCode());
        $this->assertEquals(DocumentRequirementMode::ANY, $def->getRequirementMode());
        $this->assertEquals('identidade', $def->getAlternativeSetCode());
        $this->assertEquals('tipo_locacao<>residencial_temporada', $def->getConditionExpression());
        $this->assertEquals(70, $def->getSortOrder());
        $this->assertTrue($def->isActive());
    }

    private function testRuleSpecificationRegistry(): void
    {
        $registry = new RuleSpecificationRegistry();

        // 1. Testa registro por Closure
        $registry->registerClosure('limite_aluguel_maximo', function (IRuleDefinition $rule): ISpecification {
            $max = $rule->getDecimalValue() ?? 10000.0;
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
                    return SpecificationResult::failure('Aluguel excede teto.', $this->rule->getCode(), metadata: ['action' => 'block']);
                }
            };
        });

        $this->assertTrue($registry->hasHandler('limite_aluguel_maximo'));
        $this->assertFalse($registry->hasHandler('regra_inexistente'));

        $ruleDef = new RuleDefinition(
            code: 'teto_aluguel_5000',
            name: 'Teto de aluguel',
            ruleType: 'limite_aluguel_maximo',
            decimalValue: 5000.0
        );

        $spec = $registry->buildSpecification($ruleDef);
        $objOk = (object) ['aluguel' => 4500.0];
        $objFail = (object) ['aluguel' => 6000.0];

        $this->assertTrue($spec->isSatisfiedBy($objOk));
        $this->assertFalse($spec->isSatisfiedBy($objFail));

        // 2. Testa lançamento de exceção para handler não registrado
        $unregisteredRule = new RuleDefinition(
            code: 'inexistente',
            name: 'Regra sem handler',
            ruleType: 'tipo_nao_cadastrado'
        );

        $this->assertThrows(MissingRuleHandlerException::class, function () use ($registry, $unregisteredRule) {
            $registry->buildSpecification($unregisteredRule);
        });

        // 3. Testa registro via classe implementando IRuleSpecificationHandler
        $handlerClass = new class implements IRuleSpecificationHandler {
            public function supports(string $ruleType): bool {
                return $ruleType === 'regra_classe';
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
            'documents' => ['cnpj_imobiliaria', 'contrato_assinado', 'cnh_locatario']
        ];
        $this->assertTrue($spec->isSatisfiedBy($candidato1));
        $verdict1 = $spec->evaluate($candidato1);
        $this->assertTrue($verdict1->isSatisfied);

        // Cenário 2: Faltou contrato_assinado (falha de ALL)
        $candidato2 = (object) [
            'documents' => ['cnpj_imobiliaria', 'cnh_locatario']
        ];
        $this->assertFalse($spec->isSatisfiedBy($candidato2));
        $verdict2 = $spec->evaluate($candidato2);
        $this->assertFalse($verdict2->isSatisfied);
        $this->assertEquals(['DOC_CONTRATO_ASSINADO'], $verdict2->getCodes());

        // Cenário 3: Faltou identidade (nem RG nem CNH)
        $candidato3 = (object) [
            'documents' => ['cnpj_imobiliaria', 'contrato_assinado']
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
        $cand1 = (object) ['documents' => ['holerite']];
        $this->assertTrue($spec->isSatisfiedBy($cand1));

        // Ambos presentes -> Reprovado (2 de 2, violou exclusividade)
        $cand2 = (object) ['documents' => ['holerite', 'declaracao_ir']];
        $this->assertFalse($spec->isSatisfiedBy($cand2));

        // Nenhum presente -> Reprovado (0 de 2)
        $cand3 = (object) ['documents' => []];
        $this->assertFalse($spec->isSatisfiedBy($cand3));
    }

    private function testDocumentGroupBuilderWithConditional(): void
    {
        $builder = new DocumentGroupSpecificationBuilder();

        // declaração Art. 43 só é exigida se tipo_locacao <> residencial_temporada
        $docRules = [
            new DocumentRuleDefinition(
                groupCode: 'ativacao',
                documentTypeCode: 'declaracao_art43',
                scope: 'contrato',
                requirementMode: DocumentRequirementMode::ALL,
                conditionExpression: 'tipo_locacao<>residencial_temporada'
            ),
        ];

        $spec = $builder->build($docRules);

        // Locação residencial tradicional sem declaração -> REPROVADO
        $candTradicional = (object) [
            'tipo_locacao' => 'residencial_urbano',
            'documents' => []
        ];
        $this->assertFalse($spec->isSatisfiedBy($candTradicional));

        // Locação para temporada sem declaração -> APROVADO (condição dispensada)
        $candTemporada = (object) [
            'tipo_locacao' => 'residencial_temporada',
            'documents' => []
        ];
        $this->assertTrue($spec->isSatisfiedBy($candTemporada));
    }

    private function testInMemoryRuleCatalog(): void
    {
        $catalog = new InMemoryRuleCatalog();

        $catalog->addRule(new RuleDefinition(
            code: 'regra_global',
            name: 'Regra Global',
            ruleType: 'global_tipo',
            priority: 10
        ));

        $catalog->addRule(new RuleDefinition(
            code: 'regra_sinistro',
            name: 'Regra de Sinistro',
            ruleType: 'sinistro_tipo',
            priority: 50,
            scope: 'sinistro'
        ));

        $catalog->addDocumentRule(new DocumentRuleDefinition(
            groupCode: 'sinistro_ocupado',
            documentTypeCode: 'declaracao_debitos',
            scope: 'sinistro',
            sortOrder: 1,
            scenario: 'sinistro_ocupado'
        ));

        // Busca no escopo contrato_locacao -> apenas a global
        $rulesContrato = $catalog->findRules('contrato_locacao');
        $this->assertCount(1, $rulesContrato);
        $this->assertEquals('regra_global', $rulesContrato[0]->getCode());

        // Busca no escopo sinistro -> global + sinistro (ordenado por prioridade decrescente)
        $rulesSinistro = $catalog->findRules('sinistro');
        $this->assertCount(2, $rulesSinistro);
        $this->assertEquals('regra_sinistro', $rulesSinistro[0]->getCode()); // prioridade 50 vem antes de 10

        // Busca documentos de sinistro_ocupado
        $docs = $catalog->findDocumentRules('sinistro', 'sinistro_ocupado');
        $this->assertCount(1, $docs);
        $this->assertEquals('declaracao_debitos', $docs[0]->getDocumentTypeCode());
    }

    private function testDynamicEngineEndToEndSimulation(): void
    {
        $catalog = new InMemoryRuleCatalog();
        $registry = new RuleSpecificationRegistry();

        // 1. Configura Regra de Negócio: Proibição de Dupla Garantia (Art. 43, II)
        $catalog->addRule(new RuleDefinition(
            code: 'global_art43_ii_dupla',
            name: 'Art. 43, II — Proibição de Dupla Garantia',
            ruleType: 'valida_ausencia_dupla_garantia_art43_ii',
            violationAction: RuleAction::BLOCK,
            legalBasis: 'Lei 8.245/91 Art. 43, II',
            violationMessage: 'Dupla garantia (Art. 43, II) — contrato não pode ser ativado.',
            scope: 'contrato_locacao'
        ));

        // 2. Configura Regra com Ação Alerta: Comprovante de Residência > 90 dias
        $catalog->addRule(new RuleDefinition(
            code: 'global_expira_comp_residencia_90',
            name: 'Comprovante com mais de 90 dias',
            ruleType: 'expirar_documento_apos_dias',
            violationAction: RuleAction::WARN,
            intValue: 90,
            violationMessage: 'Comprovante de residência com mais de 90 dias.',
            scope: 'contrato_locacao'
        ));

        // 3. Configura Regra com Ação Log: Registro de Temporada
        $catalog->addRule(new RuleDefinition(
            code: 'global_art49_temporada',
            name: 'Auditoria de Temporada',
            ruleType: 'permite_antecipacao_temporada_art49',
            violationAction: RuleAction::LOG,
            legalBasis: 'Lei 8.245/91 Art. 49',
            scope: 'contrato_locacao'
        ));

        // 4. Configura Requisitos Documentais para ativação do contrato
        $catalog->addDocumentRule(new DocumentRuleDefinition(
            groupCode: 'contrato_locacao_ativacao',
            documentTypeCode: 'contrato_locacao_assinado',
            scope: 'contrato_locacao',
            requirementMode: DocumentRequirementMode::ALL,
            scenario: 'contrato_locacao_ativacao'
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
                        $this->r->getViolationMessage() ?? 'Dupla garantia detectada.',
                        $this->r->getCode(),
                        metadata: [
                            'action' => $this->r->getViolationAction()->value,
                            'legal_basis' => $this->r->getLegalBasis()
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
                    return ($c->diasCompResidencia ?? 0) <= ($this->r->getIntValue() ?? 90);
                }
                public function evaluate(mixed $c): SpecificationResult {
                    if ($this->isSatisfiedBy($c)) {
                        return SpecificationResult::satisfied();
                    }
                    return SpecificationResult::failure(
                        $this->r->getViolationMessage() ?? 'Documento expirado.',
                        $this->r->getCode(),
                        metadata: ['action' => $this->r->getViolationAction()->value]
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
            'documents' => ['contrato_locacao_assinado'],
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
            'documents' => ['contrato_locacao_assinado'],
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
            code: 'valor_minimo',
            name: 'Valor mínimo',
            ruleType: 'valor_minimo',
            violationAction: RuleAction::WARN,
            scope: 'x'
        );
        $bound = new RuleBoundSpecification(Spec::property('valor', Spec::greaterThan(10)), $rule);
        $other = new RuleBoundSpecification(Spec::property('ativo', Spec::equalTo(true)), $rule);

        // SQL (1.11.0 mudou: cada comparação leva a guarda de nulo "col" IS NOT NULL AND ...)
        $this->assertEquals('("valor" IS NOT NULL AND "valor" > :p1)', Spec::toSql($bound, 'pgsql')->toSql(), 'RuleBound deve traduzir para SQL');
        $this->assertEquals('NOT (("valor" IS NOT NULL AND "valor" > :p1))', Spec::toSql(Spec::not($bound), 'pgsql')->toSql(), 'NOT(RuleBound) deve traduzir para SQL');
        $this->assertEquals('(("valor" IS NOT NULL AND "valor" > :p1) AND ("ativo" IS NOT NULL AND "ativo" = TRUE))', Spec::toSql(Spec::allOf($bound, $other), 'pgsql')->toSql(), 'AND de RuleBound deve traduzir para SQL');

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
     * Reprodução: antes, findRules('contrato', null, ['product_code' => 'A']) devolvia também a regra do produto B
     * e, sem filtro, devolvia as regras restritas a produto.
     */
    private function testFindRulesHonoursProductAndPlanFilters(): void
    {
        $catalog = new InMemoryRuleCatalog();
        $catalog->addRule(new RuleDefinition(code: 'R_GLOBAL', name: 'g', ruleType: 't', scope: 'contrato', priority: 1));
        $catalog->addRule(new RuleDefinition(code: 'R_PROD_A', name: 'a', ruleType: 't', scope: 'contrato', priority: 2, parameters: ['product_code' => 'A']));
        $catalog->addRule(new RuleDefinition(code: 'R_PROD_B', name: 'b', ruleType: 't', scope: 'contrato', priority: 2, parameters: ['product_code' => 'B']));
        $catalog->addRule(new RuleDefinition(code: 'R_PLAN_A1', name: 'a1', ruleType: 't', scope: 'contrato', priority: 3, parameters: ['product_code' => 'A', 'plan_code' => 'P1']));

        $codes = fn(array $filters) => array_map(fn($r) => $r->getCode(), $catalog->findRules('contrato', null, $filters));

        // Reprodução
        $this->assertEquals(['R_PROD_A', 'R_GLOBAL'], $codes(['product_code' => 'A']), 'produto A: global + restrita a A, ordenadas por prioridade');
        $this->assertEquals(['R_GLOBAL'], $codes([]), 'sem produto: regras restritas a produto não se aplicam');

        // Regressão da semântica AND
        $this->assertEquals(['R_PROD_B', 'R_GLOBAL'], $codes(['product_code' => 'B']));
        $this->assertEquals(['R_PLAN_A1', 'R_PROD_A', 'R_GLOBAL'], $codes(['product_code' => 'A', 'plan_code' => 'P1']));
        $this->assertEquals(['R_PROD_A', 'R_GLOBAL'], $codes(['product_code' => 'A', 'plan_code' => 'P2']), 'plano diferente exclui a regra restrita a P1');
        $this->assertEquals(['R_GLOBAL'], $codes(['plan_code' => 'P1']), 'plano sem produto: a regra restrita a produto A não casa');
        $this->assertEquals(['R_GLOBAL'], $codes(['foo' => 'bar']), 'chave desconhecida é ignorada');
        $this->assertEquals(['R_GLOBAL'], $codes(['product_code' => null]), 'filtro nulo equivale a ausente');

        // O motor repassa o contexto: só as regras aplicáveis entram no veredito
        $registry = new RuleSpecificationRegistry();
        $registry->registerClosure('t', fn(IRuleDefinition $r): ISpecification => Spec::alwaysFalse());
        $engine = new DynamicSpecificationEngine($catalog, $registry);
        $this->assertEquals(['R_PROD_A', 'R_GLOBAL'], $engine->validate(new stdClass(), 'contrato', null, ['product_code' => 'A'])->getFailureCodes());
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
        $catalog->addRule(new RuleDefinition(code: 'R_SEMPRE', name: 's', ruleType: 't', scope: 'contrato', priority: 1));
        $catalog->addRule(new RuleDefinition(code: 'R_2026H1', name: 'h1', ruleType: 't', scope: 'contrato', priority: 2,
            parameters: ['valid_from' => '2026-01-01', 'valid_until' => '2026-06-30']));
        $catalog->addRule(new RuleDefinition(code: 'R_DESDE_JUL', name: 'j', ruleType: 't', scope: 'contrato', priority: 3,
            parameters: ['valid_from' => new \DateTimeImmutable('2026-07-01')]));

        $codes = fn(array $filters) => array_map(fn($r) => $r->getCode(), $catalog->findRules('contrato', null, $filters));

        $this->assertEquals(['R_DESDE_JUL', 'R_2026H1', 'R_SEMPRE'], $codes([]), 'sem data_referencia a vigência não é avaliada');
        $this->assertEquals(['R_2026H1', 'R_SEMPRE'], $codes(['reference_date' => '2026-03-15']));
        $this->assertEquals(['R_2026H1', 'R_SEMPRE'], $codes(['reference_date' => new \DateTimeImmutable('2026-06-30 23:59:59')]), 'fim de vigência inclusivo, por dia');
        $this->assertEquals(['R_DESDE_JUL', 'R_SEMPRE'], $codes(['reference_date' => '2026-07-01']), 'início inclusivo; fim nulo = aberta');
        $this->assertEquals(['R_SEMPRE'], $codes(['reference_date' => '2025-12-31']), 'antes de toda vigência');
        $this->assertThrows(InvalidArgumentException::class, fn() => $codes(['reference_date' => 'ontem à noite']), 'data inválida é erro de configuração, não filtro silencioso');
    }

    /**
     * BUG-20261007-ZB6A (#28), RN-17 item 4: fromArray() copia as colunas de aplicabilidade da linha
     * (codigo_produto, codigo_plano, data_inicio_vigencia, data_fim_vigencia) para parametros;
     * parametros explícito prevalece; coluna nula ou vazia não é copiada.
     */
    private function testRuleDefinitionHydratesApplicabilityColumnsIntoParameters(): void
    {
        $def = RuleDefinition::fromArray([
            'code' => 'r1', 'name' => 'n', 'rule_type' => 't',
            'product_code' => 'A', 'plan_code' => 'P1',
            'valid_from' => '2026-01-01', 'valid_until' => null,
        ]);
        $this->assertEquals(['product_code' => 'A', 'plan_code' => 'P1', 'valid_from' => '2026-01-01'], $def->getParameters());

        $explicit = RuleDefinition::fromArray([
            'code' => 'r2', 'name' => 'n', 'rule_type' => 't',
            'product_code' => 'A', 'parameters' => ['product_code' => 'Z', 'limite' => 3],
        ]);
        $this->assertEquals(['product_code' => 'Z', 'limite' => 3], $explicit->getParameters(), 'parametros explícito prevalece sobre a coluna');

        $plain = RuleDefinition::fromArray(['code' => 'r3', 'name' => 'n', 'rule_type' => 't', 'product_code' => '']);
        $this->assertEquals([], $plain->getParameters(), 'coluna vazia não vira restrição');
    }

    /**
     * Forward 020 RN-04: FailureSeverity (ERROR, WARNING, INFO); SpecificationFailure ganha
     * `?FailureSeverity $severity = null` no fim do construtor (compatível com as chamadas posicionais
     * da 1.4.x) e getSeverity(); RuleAction mapeia para a severidade.
     */
    private function testFailureSeverityContract(): void
    {
        $this->assertEquals(['error', 'warning', 'info'], array_map(static fn(FailureSeverity $s): string => $s->value, FailureSeverity::cases()));
        $this->assertEquals(FailureSeverity::ERROR, RuleAction::BLOCK->toSeverity());
        $this->assertEquals(FailureSeverity::WARNING, RuleAction::WARN->toSeverity());
        $this->assertEquals(FailureSeverity::INFO, RuleAction::LOG->toSeverity());

        // Assinatura da 1.4.x continua válida e a severidade fica nula fora do motor.
        $legacy = new SpecificationFailure('m', 'C', 'R', 'p', ['k' => 1]);
        $this->assertTrue($legacy->getSeverity() === null);
        $this->assertTrue(SpecificationResult::failure('m', 'C')->failures[0]->getSeverity() === null);
        $this->assertTrue(Spec::property('n', Spec::greaterThan(1))->evaluate((object) ['n' => 0])->failures[0]->getSeverity() === null, 'spec comum não classifica');

        $classified = new SpecificationFailure('m', 'C', severity: FailureSeverity::WARNING);
        $this->assertEquals(FailureSeverity::WARNING, $classified->getSeverity());
        $this->assertEquals(FailureSeverity::WARNING, $classified->withProperty('campo')->getSeverity(), 'withProperty() preserva a severidade');
        $this->assertEquals('campo', $classified->withProperty('campo')->property);
        $this->assertEquals(FailureSeverity::INFO, $legacy->withSeverity(FailureSeverity::INFO)->getSeverity());
        $this->assertEquals(['k' => 1], $legacy->withSeverity(FailureSeverity::INFO)->metadata, 'withSeverity() preserva o restante');
        $this->assertEquals(FailureSeverity::ERROR, SpecificationResult::failure('m', severity: FailureSeverity::ERROR)->failures[0]->getSeverity());
        $this->assertEquals('[C] m', (string) $classified, '__toString inalterado');
    }

    /**
     * Cenário de aceitação do forward 020: regras bloquear, alertar e apenas_log violadas produzem falhas
     * ERROR, WARNING e INFO; hasBlockingErrors() continua true só pela regra bloquear.
     */
    private function testEngineStampsSeverityFromRuleAction(): void
    {
        $catalog = new InMemoryRuleCatalog();
        $catalog->addRule(new RuleDefinition(code: 'R_BLOCK', name: 'b', ruleType: 'maximo', violationAction: RuleAction::BLOCK, intValue: 1, priority: 3, scope: 'x'));
        $catalog->addRule(new RuleDefinition(code: 'R_WARN', name: 'w', ruleType: 'maximo', violationAction: RuleAction::WARN, intValue: 1, priority: 2, scope: 'x'));
        $catalog->addRule(new RuleDefinition(code: 'R_LOG', name: 'l', ruleType: 'maximo_codificado', violationAction: RuleAction::LOG, intValue: 1, priority: 1, scope: 'x'));

        $registry = new RuleSpecificationRegistry();
        // Handler cru (falha colapsada pelo motor) ...
        $registry->registerClosure('maximo', fn(IRuleDefinition $r): ISpecification =>
            Spec::property('n', Spec::lessThanOrEqualTo($r->getIntValue())));
        // ... e handler com código próprio (falha carimbada pelo motor).
        $registry->registerClosure('maximo_codificado', fn(IRuleDefinition $r): ISpecification =>
            Spec::property('n', Spec::lessThanOrEqualTo($r->getIntValue()))->withCode('HANDLER_LOG'));
        $engine = new DynamicSpecificationEngine($catalog, $registry);

        $verdict = $engine->validate((object) ['n' => 5], 'x');
        $severities = array_map(static fn($f): ?FailureSeverity => $f->getSeverity(), $verdict->getAllFailures());
        $this->assertEquals([FailureSeverity::ERROR, FailureSeverity::WARNING, FailureSeverity::INFO], $severities);
        $this->assertEquals(['R_BLOCK', 'R_WARN', 'HANDLER_LOG'], $verdict->getFailureCodes());
        $this->assertTrue($verdict->hasBlockingErrors());
        $this->assertEquals(FailureSeverity::ERROR, $verdict->getBlockingFailures()[0]->getSeverity());
        $this->assertEquals(FailureSeverity::WARNING, $verdict->getWarningFailures()[0]->getSeverity());
        $this->assertEquals(FailureSeverity::INFO, $verdict->getLogFailures()[0]->getSeverity());

        // Sem a regra bloquear, alertar e apenas_log não bloqueiam (hasBlockingErrors inalterado).
        $soft = new InMemoryRuleCatalog();
        $soft->addRule(new RuleDefinition(code: 'R_WARN', name: 'w', ruleType: 'maximo', violationAction: RuleAction::WARN, intValue: 1, scope: 'x'));
        $soft->addRule(new RuleDefinition(code: 'R_LOG', name: 'l', ruleType: 'maximo', violationAction: RuleAction::LOG, intValue: 1, scope: 'x'));
        $softVerdict = (new DynamicSpecificationEngine($soft, $registry))->validate((object) ['n' => 5], 'x');
        $this->assertFalse($softVerdict->hasBlockingErrors());
        $this->assertEquals(
            [FailureSeverity::WARNING, FailureSeverity::INFO],
            array_map(static fn($f): ?FailureSeverity => $f->getSeverity(), $softVerdict->getAllFailures())
        );
    }

    /**
     * A severidade nunca discorda do balde do veredito: a ação do handler vence a da regra, erro de
     * avaliação é ERROR mesmo em regra apenas_log, e severidade explícita do handler é preservada.
     */
    private function testSeverityFollowsEffectiveActionAndEvaluationErrors(): void
    {
        $catalog = new InMemoryRuleCatalog();
        $catalog->addRule(new RuleDefinition(code: 'R_WARN_HANDLER_BLOCK', name: 'a', ruleType: 'handler_bloqueia', violationAction: RuleAction::WARN, priority: 4, scope: 'x'));
        $catalog->addRule(new RuleDefinition(code: 'R_LOG_ERRO', name: 'b', ruleType: 'propriedade_inexistente', violationAction: RuleAction::LOG, priority: 3, scope: 'x'));
        $catalog->addRule(new RuleDefinition(code: 'R_EXPLICITA', name: 'c', ruleType: 'severidade_explicita', violationAction: RuleAction::BLOCK, priority: 2, scope: 'x'));

        $registry = new RuleSpecificationRegistry();
        $registry->registerClosure('handler_bloqueia', fn(IRuleDefinition $r): ISpecification => new class extends AbstractSpecification {
            public function getType(): string { return 'mixed'; }
            public function isSatisfiedBy(mixed $c): bool { return false; }
            public function evaluate(mixed $c): SpecificationResult {
                return SpecificationResult::failure('handler', 'H_BLOCK', metadata: ['action' => 'block']);
            }
        });
        $registry->registerClosure('propriedade_inexistente', fn(IRuleDefinition $r): ISpecification =>
            Spec::property('naoExiste', Spec::greaterThan(1)));
        $registry->registerClosure('severidade_explicita', fn(IRuleDefinition $r): ISpecification => new class extends AbstractSpecification {
            public function getType(): string { return 'mixed'; }
            public function isSatisfiedBy(mixed $c): bool { return false; }
            public function evaluate(mixed $c): SpecificationResult {
                return SpecificationResult::failure('explícita', 'H_INFO', severity: FailureSeverity::INFO);
            }
        });

        $verdict = (new DynamicSpecificationEngine($catalog, $registry))->validate(new stdClass(), 'x');
        $byCode = [];
        foreach ($verdict->getAllFailures() as $failure) {
            $byCode[$failure->code] = $failure->getSeverity();
        }

        $this->assertEquals(FailureSeverity::ERROR, $byCode['H_BLOCK'], 'acao do handler (bloquear) vence a da regra (alertar)');
        $this->assertTrue(in_array($verdict->getAllFailures()[0], $verdict->getBlockingFailures(), true), 'e cai no mesmo balde');
        $this->assertEquals(FailureSeverity::ERROR, $byCode['R_LOG_ERRO'], 'erro de avaliação é ERROR mesmo em regra apenas_log');
        $this->assertEquals(FailureSeverity::INFO, $byCode['H_INFO'], 'severidade explícita do handler é preservada');
        $this->assertTrue($verdict->hasEvaluationErrors());
    }

    /**
     * Requisitos documentais sempre bloqueiam: falhas e erros de avaliação documentais são ERROR.
     */
    private function testDocumentFailuresAreErrorSeverity(): void
    {
        $catalog = new InMemoryRuleCatalog();
        $catalog->addDocumentRule(new DocumentRuleDefinition(groupCode: 'g', documentTypeCode: 'rg', scope: 'x'));
        $catalog->addDocumentRule(new DocumentRuleDefinition(groupCode: 'g', documentTypeCode: 'cpf', scope: 'x', requirementMode: DocumentRequirementMode::ANY, alternativeSetCode: 'id'));
        $catalog->addDocumentRule(new DocumentRuleDefinition(groupCode: 'g', documentTypeCode: 'cnh', scope: 'x', requirementMode: DocumentRequirementMode::ANY, alternativeSetCode: 'id'));

        $verdict = Spec::engine($catalog)->validate((object) ['documents' => []], 'x');
        $this->assertEquals(['DOC_RG', 'DOC_SET_ID'], $verdict->getFailureCodes());
        $this->assertEquals(
            [FailureSeverity::ERROR, FailureSeverity::ERROR],
            array_map(static fn($f): ?FailureSeverity => $f->getSeverity(), $verdict->getAllFailures())
        );

        $throwing = new class implements IDocumentPresenceEvaluator {
            public function hasDocument(object|array $target, string $codigoTipoDocumento, array $context = []): bool
            {
                throw new RuntimeException('repositório de documentos indisponível');
            }
        };
        $engine = new DynamicSpecificationEngine($catalog, new RuleSpecificationRegistry(), new DocumentGroupSpecificationBuilder($throwing));
        $errorVerdict = $engine->validate((object) [], 'x');
        $this->assertTrue($errorVerdict->hasEvaluationErrors());
        $this->assertTrue(count($errorVerdict->getAllFailures()) > 0);
        foreach ($errorVerdict->getAllFailures() as $failure) {
            $this->assertEquals(FailureSeverity::ERROR, $failure->getSeverity(), 'erro de avaliação documental é ERROR');
        }
    }

    /**
     * Forward 019 §3 (cenário "motor de regras") e RN-11: em modo de validação do catálogo, cada
     * regra compilada que é contradição (nunca satisfeita) ou tautologia (nunca falha) gera um
     * RuleCompilationWarning; a compilação não falha e o veredito não muda.
     */
    private function testCatalogValidationWarnsDegenerateRules(): void
    {
        $catalog = new InMemoryRuleCatalog();
        $registry = new RuleSpecificationRegistry();

        $catalog->addRule(new RuleDefinition(code: 'r_contra', name: 'Status A e B', ruleType: 'status_ab', scope: 'x'));
        $catalog->addRule(new RuleDefinition(code: 'r_ok', name: 'Valor positivo', ruleType: 'positivo', scope: 'x'));
        $catalog->addRule(new RuleDefinition(code: 'r_tauto', name: 'Ativo ou não', ruleType: 'ativo_ou_nao', scope: 'x'));

        $registry->registerClosure('status_ab', fn(IRuleDefinition $r): ISpecification =>
            Spec::property('status', Spec::equalTo('A'))->and(Spec::property('status', Spec::equalTo('B'))));
        $registry->registerClosure('positivo', fn(IRuleDefinition $r): ISpecification =>
            Spec::property('valor', Spec::greaterThan(0)));
        $active = Spec::property('ativo', Spec::isTrue());
        $registry->registerClosure('ativo_ou_nao', fn(IRuleDefinition $r): ISpecification => $active->or($active->not()));

        // Modo desligado (default): nenhuma inspeção
        $plain = new DynamicSpecificationEngine($catalog, $registry);
        $this->assertFalse($plain->isCatalogValidationEnabled());
        $plain->compileSpecification('x');
        $this->assertEquals([], $plain->getCompilationWarnings());

        // Modo ligado: um aviso por regra degenerada, na ordem do catálogo; compilação não falha
        $engine = $plain->withCatalogValidation();
        $this->assertTrue($engine->isCatalogValidationEnabled());
        $this->assertFalse($plain->isCatalogValidationEnabled(), 'withCatalogValidation() devolve cópia');
        $compiled = $engine->compileSpecification('x');
        $this->assertInstanceOf(ISpecification::class, $compiled);
        $warnings = $engine->getCompilationWarnings();
        $this->assertCount(2, $warnings);
        $this->assertInstanceOf(RuleCompilationWarning::class, $warnings[0]);
        $this->assertEquals('r_contra', $warnings[0]->ruleCode);
        $this->assertEquals('status_ab', $warnings[0]->ruleType);
        $this->assertTrue($warnings[0]->isContradiction());
        $this->assertEquals(RuleCompilationWarning::KIND_CONTRADICTION, $warnings[0]->kind);
        $this->assertTrue(str_contains((string) $warnings[0], 'r_contra'));
        $this->assertEquals('r_tauto', $warnings[1]->ruleCode);
        $this->assertTrue($warnings[1]->isTautology());
        $this->assertTrue($warnings[1]->specification->isTautology());

        // O veredito é o mesmo com e sem o modo: o aviso só é reportado, nunca bloqueia nem entra no veredito
        $candidate = (object) ['status' => 'A', 'valor' => 5, 'ativo' => true];
        $withMode = $engine->validate($candidate, 'x');
        $withoutMode = $plain->validate($candidate, 'x');
        $this->assertEquals($withoutMode->getFailureCodes(), $withMode->getFailureCodes());
        $this->assertEquals(['r_contra'], $withMode->getFailureCodes(), 'a regra contraditória falha sempre');
        $this->assertCount(2, $engine->getCompilationWarnings(), 'validate() também compila com o modo');

        // Construtor também liga o modo; aviso atravessa RuleBoundSpecification devolvida pelo handler
        $boundRegistry = new RuleSpecificationRegistry();
        $boundCatalog = new InMemoryRuleCatalog();
        $rule = new RuleDefinition(code: 'r_bound', name: 'Vazio', ruleType: 'vazio', scope: 'y');
        $boundCatalog->addRule($rule);
        $boundRegistry->registerClosure('vazio', fn(IRuleDefinition $r): ISpecification =>
            new RuleBoundSpecification(Spec::property('tipo', Spec::in()), $r));
        $byConstructor = new DynamicSpecificationEngine($boundCatalog, $boundRegistry, null, true);
        $byConstructor->compileSpecification('y');
        $this->assertCount(1, $byConstructor->getCompilationWarnings());
        $this->assertTrue($byConstructor->getCompilationWarnings()[0]->isContradiction());

        // Catálogo sem regras degeneradas: lista vazia, e a lista é zerada a cada compilação
        $engine->compileSpecification('escopo_sem_regras');
        $this->assertEquals([], $engine->getCompilationWarnings());
        $this->assertEquals([], $plain->withCatalogValidation(false)->getCompilationWarnings());
    }
}
