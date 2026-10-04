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
use Antevemus\ASpecification\Engine\Exceptions\MissingRuleHandlerException;
use Antevemus\ASpecification\Engine\InMemoryRuleCatalog;
use Antevemus\ASpecification\Engine\RuleAction;
use Antevemus\ASpecification\Engine\RuleDefinition;
use Antevemus\ASpecification\Engine\RuleEngineVerdict;
use Antevemus\ASpecification\Engine\RuleSpecificationRegistry;
use Antevemus\ASpecification\Results\SpecificationResult;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Tests\TestCase;
use stdClass;

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
        ];

        $def = DocumentRuleDefinition::fromArray($row);

        $this->assertEquals('contrato_locacao_ativacao', $def->getGrupoCodigo());
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
            new DocumentRuleDefinition('ativacao', 'cnpj_imobiliaria', DocumentRequirementMode::ALL),
            new DocumentRuleDefinition('ativacao', 'contrato_assinado', DocumentRequirementMode::ALL),
            new DocumentRuleDefinition('ativacao', 'rg_locatario', DocumentRequirementMode::ANY, 'identidade'),
            new DocumentRuleDefinition('ativacao', 'cnh_locatario', DocumentRequirementMode::ANY, 'identidade'),
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
            new DocumentRuleDefinition('analise', 'holerite', DocumentRequirementMode::ONE_OF_SET, 'comprovante_renda'),
            new DocumentRuleDefinition('analise', 'declaracao_ir', DocumentRequirementMode::ONE_OF_SET, 'comprovante_renda'),
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
            ordem: 1
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
            regraObrigatoriedade: DocumentRequirementMode::ALL
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
}
