<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

use Antevemus\ASpecification\Contracts\Engine\IDocumentPresenceEvaluator;
use Antevemus\ASpecification\Contracts\Engine\IDocumentRuleDefinition;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Engine\Exceptions\InvalidConditionalExpressionException;
use Antevemus\ASpecification\Engine\Exceptions\MissingAlternativeSetException;
use Antevemus\ASpecification\Spec;

/**
 * DocumentGroupSpecificationBuilder - Boolean Compiler for Document Requirements
 *
 * Transforms a collection of document requirements (IDocumentRuleDefinition) into a unified
 * specification tree (ISpecification), combining ALL (And), ANY (at least one of a set) and
 * ONE_OF_SET (exactly one of a set) rules.
 *
 * Features:
 * - Pluggable document presence evaluator (IDocumentPresenceEvaluator)
 * - Intelligent default mechanism for inspecting documents in domain entities or arrays
 * - Alternative document sets (e.g. Tax ID or Passport or Driver License); an ANY / ONE_OF_SET rule
 *   without `codigo_set_alternativas` is rejected at compilation (MissingAlternativeSetException),
 *   mirroring the relational catalog constraint, never grouped by guesswork
 * - Conditional guard predicates (e.g., rental_type <> seasonal): a document whose guard does not
 *   hold is NOT APPLICABLE and leaves the set count; an unsupported guard expression is rejected at
 *   compilation (InvalidConditionalExpressionException), never silently applied
 * - One aggregated failure per failing set, listing the accepted documents
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
     * @throws InvalidConditionalExpressionException When a rule carries an unsupported guard expression
     * @throws MissingAlternativeSetException When an ANY / ONE_OF_SET rule has no alternative set
     */
    public function build(array $rules, array $context = []): ISpecification
    {
        $activeRules = array_filter($rules, fn(IDocumentRuleDefinition $r) => $r->isActive());

        if (empty($activeRules)) {
            return Spec::alwaysTrue();
        }

        $allSpecs = [];
        /** @var array<string, list<array{rule: IDocumentRuleDefinition, spec: DocumentLeafSpecification}>> $anySets */
        $anySets = [];
        /** @var array<string, list<array{rule: IDocumentRuleDefinition, spec: DocumentLeafSpecification}>> $oneOfSets */
        $oneOfSets = [];

        foreach ($activeRules as $rule) {
            $leafSpec = $this->createDocumentLeafSpec($rule, $context);
            $entry = ['rule' => $rule, 'spec' => $leafSpec];

            match ($rule->getRegraObrigatoriedade()) {
                DocumentRequirementMode::ALL => $allSpecs[] = $leafSpec,
                DocumentRequirementMode::ANY => $anySets[$this->resolveSetKey($rule)][] = $entry,
                DocumentRequirementMode::ONE_OF_SET => $oneOfSets[$this->resolveSetKey($rule)][] = $entry,
            };
        }

        // Alternative ANY sets: at least one applicable document of the set must be present
        foreach ($anySets as $setKey => $entries) {
            $allSpecs[] = $this->createAnySetSpecification((string) $setKey, $entries, $context);
        }

        // Exclusive ONE_OF_SET sets: exactly one applicable document of the set must be present
        foreach ($oneOfSets as $setKey => $entries) {
            $allSpecs[] = $this->createOneOfSetSpecification((string) $setKey, $entries, $context);
        }

        if (count($allSpecs) === 1) {
            return $allSpecs[0];
        }

        return Spec::allOf(...$allSpecs);
    }

    /**
     * Resolves the set an ANY / ONE_OF_SET rule belongs to: its declared `codigo_set_alternativas`.
     * A rule in one of those modes without a set is a catalog error and is refused, exactly as the
     * relational catalog refuses it (`regra_obrigatoriedade = 'all' OR codigo_set_alternativas IS NOT NULL`).
     *
     * @param IDocumentRuleDefinition $rule
     * @return string
     * @throws MissingAlternativeSetException
     */
    protected function resolveSetKey(IDocumentRuleDefinition $rule): string
    {
        $declared = $rule->getCodigoSetAlternativas();
        if ($declared !== null && trim($declared) !== '') {
            return trim($declared);
        }

        throw new MissingAlternativeSetException(
            $rule->getRegraObrigatoriedade()->value,
            $rule->getCodigoTipoDocumento(),
            $rule->getGrupoCodigo()
        );
    }

    /**
     * Creates a leaf specification evaluating presence of a specific document.
     *
     * @param IDocumentRuleDefinition $rule
     * @param array<string, mixed> $context
     * @return DocumentLeafSpecification
     * @throws InvalidConditionalExpressionException
     */
    protected function createDocumentLeafSpec(IDocumentRuleDefinition $rule, array $context): DocumentLeafSpecification
    {
        return new DocumentLeafSpecification($rule, $this->evaluator, $context);
    }

    /**
     * Creates an alternative-set specification (at least one applicable document present).
     *
     * @param string $setKey
     * @param list<array{rule: IDocumentRuleDefinition, spec: DocumentLeafSpecification}> $entries
     * @param array<string, mixed> $context
     * @return ISpecification
     */
    protected function createAnySetSpecification(string $setKey, array $entries, array $context): ISpecification
    {
        return new DocumentSetSpecification(
            $setKey,
            DocumentRequirementMode::ANY,
            array_map(static fn(array $e): DocumentLeafSpecification => $e['spec'], array_values($entries))
        );
    }

    /**
     * Creates a strict exclusivity specification (exactly one applicable document present).
     *
     * @param string $setKey
     * @param list<array{rule: IDocumentRuleDefinition, spec: DocumentLeafSpecification}> $entries
     * @param array<string, mixed> $context
     * @return ISpecification
     */
    protected function createOneOfSetSpecification(string $setKey, array $entries, array $context): ISpecification
    {
        return new DocumentSetSpecification(
            $setKey,
            DocumentRequirementMode::ONE_OF_SET,
            array_map(static fn(array $e): DocumentLeafSpecification => $e['spec'], array_values($entries))
        );
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
