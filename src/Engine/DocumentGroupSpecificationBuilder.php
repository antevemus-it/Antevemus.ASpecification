<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\Engine\IDocumentPresenceEvaluator;
use Antevemus\ASpecification\Contracts\Engine\IDocumentRuleDefinition;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Results\SpecificationFailure;
use Antevemus\ASpecification\Results\SpecificationResult;
use Antevemus\ASpecification\Spec;

/**
 * DocumentGroupSpecificationBuilder - Compilador Booleano de Requisitos Documentais
 *
 * Transforma uma coleção de requisitos de documentos (IDocumentRuleDefinition) em uma árvore
 * unificada de especificações (ISpecification), combinando regras ALL (And), ANY (Or) e ONE_OF_SET (Xor).
 *
 * Funcionalidades:
 * - Avaliador de presença documental plugável (IDocumentPresenceEvaluator)
 * - Mecanismo padrão inteligente para introspecção de documentos em entidades ou arrays
 * - Resolução elegante de alternativas com sets (ex: CPF ou RG ou CNH)
 * - Avaliação de predicados condicionais de guarda (ex: tipo_locacao <> temporada)
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class DocumentGroupSpecificationBuilder
{
    private IDocumentPresenceEvaluator $evaluator;

    /**
     * @param IDocumentPresenceEvaluator|null $evaluator Avaliador customizado de documentos
     */
    public function __construct(?IDocumentPresenceEvaluator $evaluator = null)
    {
        $this->evaluator = $evaluator ?? $this->createDefaultEvaluator();
    }

    /**
     * Compila uma lista de requisitos de documentos em uma especificação composta executável.
     *
     * @param list<IDocumentRuleDefinition> $rules Lista de requisitos documentais
     * @param array<string, mixed> $context Metadados adicionais de contexto
     * @return ISpecification
     */
    public function build(array $rules, array $context = []): ISpecification
    {
        $activeRules = array_filter($rules, fn(IDocumentRuleDefinition $r) => $r->isActive());

        if (empty($activeRules)) {
            return Spec::alwaysTrue();
        }

        $allSpecs = [];
        $anySets = [];
        $oneOfSets = [];

        foreach ($activeRules as $rule) {
            $docType = $rule->getCodigoTipoDocumento();
            $setKey = $rule->getCodigoSetAlternativas() ?? $docType;

            $leafSpec = $this->createDocumentLeafSpec($rule, $context);

            match ($rule->getRegraObrigatoriedade()) {
                DocumentRequirementMode::ALL => $allSpecs[] = $leafSpec,
                DocumentRequirementMode::ANY => $anySets[$setKey][] = $leafSpec,
                DocumentRequirementMode::ONE_OF_SET => $oneOfSets[$setKey][] = [
                    'rule' => $rule,
                    'spec' => $leafSpec,
                ],
            };
        }

        // Processa sets alternativos ANY (qualquer um do set satisfaz -> OR)
        foreach ($anySets as $setKey => $specsInSet) {
            if (count($specsInSet) === 1) {
                $allSpecs[] = $specsInSet[0];
            } else {
                $allSpecs[] = Spec::anyOf(...$specsInSet);
            }
        }

        // Processa sets exclusivos ONE_OF_SET (exatamente um documento deve estar presente)
        foreach ($oneOfSets as $setKey => $entries) {
            $allSpecs[] = $this->createOneOfSetSpecification($setKey, $entries, $context);
        }

        if (count($allSpecs) === 1) {
            return $allSpecs[0];
        }

        return Spec::allOf(...$allSpecs);
    }

    /**
     * Cria a especificação folha que avalia a presença de um documento específico.
     *
     * @param IDocumentRuleDefinition $rule
     * @param array<string, mixed> $context
     * @return ISpecification
     */
    protected function createDocumentLeafSpec(IDocumentRuleDefinition $rule, array $context): ISpecification
    {
        $evaluator = $this->evaluator;
        $docType = $rule->getCodigoTipoDocumento();
        $condicao = $rule->getCondicionalExpressao();

        return new class($docType, $rule, $evaluator, $condicao, $context) extends AbstractSpecification {
            public function __construct(
                private readonly string $docType,
                private readonly IDocumentRuleDefinition $rule,
                private readonly IDocumentPresenceEvaluator $evaluator,
                private readonly ?string $condicao,
                private readonly array $context
            ) {
            }

            /** {@inheritdoc} */
            public function getType(): string
            {
                return 'mixed';
            }

            /** {@inheritdoc} */
            public function isSatisfiedBy(mixed $candidate): bool
            {
                if (!$this->isConditionMet($candidate)) {
                    return true;
                }

                if (!is_object($candidate) && !is_array($candidate)) {
                    return false;
                }

                return $this->evaluator->hasDocument($candidate, $this->docType, $this->context);
            }

            /** {@inheritdoc} */
            public function evaluate(mixed $candidate): SpecificationResult
            {
                if (!$this->isConditionMet($candidate)) {
                    return SpecificationResult::satisfied();
                }

                if ($this->isSatisfiedBy($candidate)) {
                    return SpecificationResult::satisfied();
                }

                $msg = sprintf('Documento obrigatório ausente: %s.', $this->docType);
                $code = 'DOC_' . strtoupper($this->docType);

                return SpecificationResult::failure(
                    message: $msg,
                    code: $code,
                    ruleName: 'DocumentRule:' . $this->rule->getGrupoCodigo(),
                    property: 'documentos.' . $this->docType,
                    metadata: [
                        'acao' => 'bloquear',
                        'tipo_documento' => $this->docType,
                        'grupo' => $this->rule->getGrupoCodigo(),
                        'regra' => $this->rule->getRegraObrigatoriedade()->value,
                    ]
                );
            }

            private function isConditionMet(mixed $candidate): bool
            {
                if ($this->condicao === null || trim($this->condicao) === '') {
                    return true;
                }

                // Avaliador de expressão simples: chave<>valor ou chave=valor
                $expr = trim($this->condicao);
                if (preg_match('/^([a-zA-Z0-9_\-]+)\s*(<>|!=|=)\s*([a-zA-Z0-9_\-]+)$/', $expr, $matches)) {
                    $prop = $matches[1];
                    $op = $matches[2];
                    $expected = $matches[3];

                    $actual = null;
                    if (is_object($candidate)) {
                        $actual = $candidate->{$prop} ?? (method_exists($candidate, 'get' . ucfirst($prop)) ? $candidate->{'get' . ucfirst($prop)}() : null);
                    } elseif (is_array($candidate)) {
                        $actual = $candidate[$prop] ?? null;
                    }

                    $actualStr = (string) ($actual ?? '');
                    if ($op === '=' && $actualStr !== $expected) {
                        return false;
                    }
                    if (($op === '<>' || $op === '!=') && $actualStr === $expected) {
                        return false;
                    }
                }

                return true;
            }
        };
    }

    /**
     * Cria uma especificação de exclusividade estrita (exatamente um documento deve estar presente).
     *
     * @param string $setKey
     * @param list<array{rule: IDocumentRuleDefinition, spec: ISpecification}> $entries
     * @param array<string, mixed> $context
     * @return ISpecification
     */
    protected function createOneOfSetSpecification(string $setKey, array $entries, array $context): ISpecification
    {
        return new class($setKey, $entries) extends AbstractSpecification {
            public function __construct(
                private readonly string $setKey,
                private readonly array $entries
            ) {
            }

            /** {@inheritdoc} */
            public function getType(): string
            {
                return 'mixed';
            }

            /** {@inheritdoc} */
            public function isSatisfiedBy(mixed $candidate): bool
            {
                $satisfiedCount = 0;
                foreach ($this->entries as $entry) {
                    if ($entry['spec']->isSatisfiedBy($candidate)) {
                        $satisfiedCount++;
                    }
                }
                return $satisfiedCount === 1;
            }

            /** {@inheritdoc} */
            public function evaluate(mixed $candidate): SpecificationResult
            {
                $satisfiedCount = 0;
                foreach ($this->entries as $entry) {
                    if ($entry['spec']->isSatisfiedBy($candidate)) {
                        $satisfiedCount++;
                    }
                }

                if ($satisfiedCount === 1) {
                    return SpecificationResult::satisfied();
                }

                $msg = sprintf(
                    'O conjunto de documentos "%s" exige a presença de exatamente um documento (presentes: %d).',
                    $this->setKey,
                    $satisfiedCount
                );

                return SpecificationResult::failure(
                    message: $msg,
                    code: 'DOC_SET_' . strtoupper($this->setKey),
                    ruleName: 'DocumentRuleSet:' . $this->setKey,
                    property: 'documentos_set.' . $this->setKey,
                    metadata: [
                        'acao' => 'bloquear',
                        'set_alternativas' => $this->setKey,
                        'esperado' => 1,
                        'recebido' => $satisfiedCount,
                    ]
                );
            }
        };
    }

    /**
     * Fornece um avaliador padrão inteligente para verificação de documentos.
     *
     * @return IDocumentPresenceEvaluator
     */
    protected function createDefaultEvaluator(): IDocumentPresenceEvaluator
    {
        return new class implements IDocumentPresenceEvaluator {
            /** {@inheritdoc} */
            public function hasDocument(object|array $target, string $codigoTipoDocumento, array $context = []): bool
            {
                $tipo = strtolower(trim($codigoTipoDocumento));

                if (is_object($target)) {
                    if (method_exists($target, 'hasDocument')) {
                        return (bool) $target->hasDocument($tipo);
                    }
                    if (method_exists($target, 'hasDocumento')) {
                        return (bool) $target->hasDocumento($tipo);
                    }
                    if (method_exists($target, 'getDocumentos')) {
                        $docs = $target->getDocumentos();
                        if (is_array($docs)) {
                            return in_array($tipo, array_map('strtolower', $docs), true)
                                || isset($docs[$tipo]);
                        }
                    }
                    if (isset($target->documentos) && is_array($target->documentos)) {
                        return in_array($tipo, array_map('strtolower', $target->documentos), true)
                            || isset($target->documentos[$tipo]);
                    }
                }

                if (is_array($target)) {
                    if (isset($target['documentos']) && is_array($target['documentos'])) {
                        return in_array($tipo, array_map('strtolower', $target['documentos']), true)
                            || isset($target['documentos'][$tipo]);
                    }
                    if (isset($target[$tipo])) {
                        return !empty($target[$tipo]);
                    }
                }

                return false;
            }
        };
    }
}
