<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

use Antevemus\ASpecification\Contracts\Engine\IDynamicSpecificationEngine;
use Antevemus\ASpecification\Contracts\Engine\IRuleCatalog;
use Antevemus\ASpecification\Contracts\Engine\IRuleDefinition;
use Antevemus\ASpecification\Contracts\Engine\IRuleSpecificationRegistry;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Spec;

/**
 * DynamicSpecificationEngine - Execution and Dynamic Compilation Engine for Specifications
 *
 * Focal orchestrator that retrieves rules and documents from the catalog,
 * compiles high-performance specification trees, and evaluates entities via the Notification Pattern.
 *
 * Features:
 * - Unified compilation of business rules and mandatory document requirements
 * - Every compiled rule is bound to its catalog definition (RuleBoundSpecification): action,
 *   code, message and legal basis reach the verdict without handler boilerplate
 * - Rich evaluation of domain entities returning a typed RuleEngineVerdict
 * - Automated triage of operational failures (HTTP 403 blocks, warnings, and audit logs)
 * - Transparent integration with ASpecification algebra and SpecificationResult
 * - Catalog validation mode (1.6.0): one RuleCompilationWarning per compiled rule that is
 *   structurally a contradiction (never satisfied) or a tautology (never fails); reported through
 *   getCompilationWarnings(), never blocking, never part of the verdict
 *
 * @version    1.6.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class DynamicSpecificationEngine implements IDynamicSpecificationEngine
{
    private DocumentGroupSpecificationBuilder $documentBuilder;

    /**
     * Whether compileSpecification() inspects each compiled rule (catalog validation mode).
     */
    private bool $catalogValidation;

    /**
     * Warnings of the last compilation (empty when catalog validation is disabled).
     *
     * @var list<RuleCompilationWarning>
     */
    private array $compilationWarnings = [];

    /**
     * @param IRuleCatalog $catalog Provider/repository for rule catalog
     * @param IRuleSpecificationRegistry $registry Registry for rule specification handlers
     * @param DocumentGroupSpecificationBuilder|null $documentBuilder Optional document specification compiler
     * @param bool $catalogValidation Enables the catalog validation mode (1.6.0): each compiled rule is
     *                                inspected for structural contradiction/tautology
     */
    public function __construct(
        private readonly IRuleCatalog $catalog,
        private readonly IRuleSpecificationRegistry $registry,
        ?DocumentGroupSpecificationBuilder $documentBuilder = null,
        bool $catalogValidation = false
    ) {
        $this->documentBuilder = $documentBuilder ?? new DocumentGroupSpecificationBuilder();
        $this->catalogValidation = $catalogValidation;
    }

    /**
     * Returns a copy of this engine with the catalog validation mode enabled (or disabled).
     *
     * In this mode every compileSpecification() (and therefore every validate()) inspects each rule
     * compiled by its handler and records a RuleCompilationWarning when the rule is structurally a
     * contradiction (it can never be satisfied) or a tautology (it can never fail). The warnings are
     * only reported through getCompilationWarnings(): compilation never fails because of them and the
     * compiled specification, its evaluation and the verdict are unchanged (forward 019 RN-11).
     *
     * @param bool $enabled
     * @return static
     */
    public function withCatalogValidation(bool $enabled = true): static
    {
        $clone = clone $this;
        $clone->catalogValidation = $enabled;
        $clone->compilationWarnings = [];

        return $clone;
    }

    /**
     * Whether the catalog validation mode is enabled.
     */
    public function isCatalogValidationEnabled(): bool
    {
        return $this->catalogValidation;
    }

    /**
     * Warnings recorded by the last compileSpecification()/validate() call in catalog validation mode,
     * one per degenerate rule, in catalog order. Empty when the mode is disabled.
     *
     * @return list<RuleCompilationWarning>
     */
    public function getCompilationWarnings(): array
    {
        return $this->compilationWarnings;
    }

    /** {@inheritdoc} */
    public function validate(
        object|array $target,
        string $escopo,
        ?string $cenario = null,
        array $context = []
    ): RuleEngineVerdict {
        $specification = $this->compileSpecification($escopo, $cenario, $context);
        $result = $specification->evaluate($target);

        return RuleEngineVerdict::fromSpecificationResult($result);
    }

    /** {@inheritdoc} */
    public function compileSpecification(
        string $escopo,
        ?string $cenario = null,
        array $context = []
    ): ISpecification {
        $specs = [];
        $this->compilationWarnings = [];

        // 1. Load and compile business rules
        $rules = $this->catalog->findRules($escopo, $cenario, $context);
        foreach ($rules as $rule) {
            $compiled = $this->registry->buildSpecification($rule);
            if ($this->catalogValidation) {
                $warning = RuleCompilationWarning::inspect($compiled, $rule);
                if ($warning !== null) {
                    $this->compilationWarnings[] = $warning;
                }
            }
            $specs[] = $this->bindRule($compiled, $rule);
        }

        // 2. Load and compile mandatory document requirements
        $docRules = $this->catalog->findDocumentRules($escopo, $cenario);
        if (!empty($docRules)) {
            $specs[] = $this->documentBuilder->build($docRules, $context);
        }

        if (empty($specs)) {
            return Spec::alwaysTrue();
        }

        if (count($specs) === 1) {
            return $specs[0];
        }

        return Spec::allOf(...$specs);
    }

    /**
     * Binds a handler-compiled specification to its catalog rule, so that every failure carries
     * the rule's action, code, message and legal basis (handler data takes precedence).
     *
     * @param ISpecification $specification Specification returned by the rule handler
     * @param IRuleDefinition $rule Catalog rule
     * @return ISpecification
     */
    protected function bindRule(ISpecification $specification, IRuleDefinition $rule): ISpecification
    {
        if ($specification instanceof RuleBoundSpecification) {
            return $specification;
        }

        return new RuleBoundSpecification($specification, $rule);
    }

    /** {@inheritdoc} */
    public function getCatalog(): IRuleCatalog
    {
        return $this->catalog;
    }

    /** {@inheritdoc} */
    public function getRegistry(): IRuleSpecificationRegistry
    {
        return $this->registry;
    }
}
