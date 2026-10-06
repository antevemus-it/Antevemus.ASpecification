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
 * DocumentGroupSpecificationBuilder - Boolean Compiler for Document Requirements
 *
 * Transforms a collection of document requirements (IDocumentRuleDefinition) into a unified
 * specification tree (ISpecification), combining ALL (And), ANY (Or), and ONE_OF_SET (Xor) rules.
 *
 * Features:
 * - Pluggable document presence evaluator (IDocumentPresenceEvaluator)
 * - Intelligent default mechanism for inspecting documents in domain entities or arrays
 * - Elegant resolution of alternative document sets (e.g. Tax ID or Passport or Driver License)
 * - Evaluation of conditional guard predicates (e.g., rental_type <> seasonal)
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class DocumentGroupSpecificationBuilder
{
    private IDocumentPresenceEvaluator $evaluator;

    /**
     * @param IDocumentPresenceEvaluator|null $evaluator Custom document presence evaluator
     */
    public function __construct(?IDocumentPresenceEvaluator $evaluator = null)
    {
        $this->evaluator = $evaluator ?? $this->createDefaultEvaluator();
    }

    /**
     * Compiles a list of document requirements into an executable composite specification.
     *
     * @param list<IDocumentRuleDefinition> $rules List of document requirements
     * @param array<string, mixed> $context Additional contextual metadata
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

        // Process alternative ANY sets (any document in set satisfies -> OR)
        foreach ($anySets as $setKey => $specsInSet) {
            if (count($specsInSet) === 1) {
                $allSpecs[] = $specsInSet[0];
            } else {
                $allSpecs[] = Spec::anyOf(...$specsInSet);
            }
        }

        // Process mutually exclusive ONE_OF_SET sets (exactly one document must be present -> XOR)
        foreach ($oneOfSets as $setKey => $entries) {
            $allSpecs[] = $this->createOneOfSetSpecification($setKey, $entries, $context);
        }

        if (count($allSpecs) === 1) {
            return $allSpecs[0];
        }

        return Spec::allOf(...$allSpecs);
    }

    /**
     * Creates a leaf specification evaluating presence of a specific document.
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

                $msg = sprintf('Required document missing: %s.', $this->docType);
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

                // Simple expression evaluator: key<>value or key=value
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
     * Creates a strict exclusivity specification (exactly one document must be present).
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
                    'Document set "%s" requires exactly one document present (present: %d).',
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
     * Provides a default intelligent evaluator for document presence checking.
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
