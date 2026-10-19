<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

use Antevemus\ASpecification\Contracts\Engine\IDynamicSpecificationEngine;
use Antevemus\ASpecification\Contracts\Engine\ILookupOnlyRuleTypes;
use Antevemus\ASpecification\Contracts\Engine\IRuleCatalog;
use Antevemus\ASpecification\Contracts\Engine\IRuleDefinition;
use Antevemus\ASpecification\Contracts\Engine\IRuleSpecificationRegistry;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Engine\Exceptions\RuleEngineException;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\SpecificationSimplifier;

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
 * - English parameter names since 1.7.0 (`scope:`, `scenario:`)
 * - Opt-in simplification (1.9.0): withSimplification() makes compileSpecification() (and
 *   therefore validate()) return SpecificationSimplifier::simplify() of the compiled tree. Disabled
 *   by default: the compiled tree is never rewritten unless asked
 * - Lookup-only rule types (2.5.0, forward 048, D2): when the registry implements ILookupOnlyRuleTypes,
 *   the rules of a type it declared lookup only, without `spec_json`, hold a value for the application
 *   and are skipped (no specification, no failure); getSkippedRules() lists them after every
 *   compilation, in catalog order. A rule of an undeclared type without handler is still a
 *   MissingRuleHandlerException, and a lookup-only type a handler supports is an "ambiguous rule type"
 *   RuleEngineException
 *
 * @version    2.5.0
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
     * Whether compileSpecification() simplifies the compiled tree (1.9.0, opt-in).
     */
    private bool $simplification = false;

    /**
     * Warnings of the last compilation (empty when catalog validation is disabled).
     *
     * @var list<RuleCompilationWarning>
     */
    private array $compilationWarnings = [];

    /**
     * Rules of a lookup-only type skipped by the last compilation (2.5.0).
     *
     * @var list<IRuleDefinition>
     */
    private array $skippedRules = [];

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
        $clone->skippedRules = [];

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
     * Returns a copy of this engine with the simplification of the compiled tree enabled (or disabled)
     * (1.9.0, forward 025 D6). Disabled by default.
     *
     * When enabled, compileSpecification() returns SpecificationSimplifier::simplify() of the tree it
     * compiled, and validate() evaluates that simplified tree. Each rule binding is kept (simplified
     * only inside), as is every node annotated with because()/withCode(), so the verdict and the
     * reported rule failures do not change; equivalence is guaranteed in truth value only (a removed
     * redundant branch may have been one that would raise). Catalog validation warnings are computed
     * on each rule as compiled by its handler, before any simplification.
     *
     * Offered as a wither, like withCatalogValidation(), instead of a new parameter of
     * compileSpecification(), so that the signature of IDynamicSpecificationEngine and of this class
     * (which subclasses may override) does not change.
     *
     * @param bool $enabled
     * @return static
     */
    public function withSimplification(bool $enabled = true): static
    {
        $clone = clone $this;
        $clone->simplification = $enabled;

        return $clone;
    }

    /**
     * Whether compileSpecification() simplifies the compiled tree (1.9.0).
     */
    public function isSimplificationEnabled(): bool
    {
        return $this->simplification;
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

    /**
     * Rules skipped by the last compileSpecification()/validate() call because their type was declared
     * lookup only (2.5.0, forward 048, D2), in catalog order. Filled by every compilation, whatever the
     * catalog validation mode, and emptied at the start of each one. Empty when the registry does not
     * implement ILookupOnlyRuleTypes.
     *
     * @return list<IRuleDefinition>
     */
    public function getSkippedRules(): array
    {
        return $this->skippedRules;
    }

    /** {@inheritdoc} */
    public function validate(
        object|array $target,
        string $scope,
        ?string $scenario = null,
        array $context = []
    ): RuleEngineVerdict {
        $specification = $this->compileSpecification($scope, $scenario, $context);
        $result = $specification->evaluate($target);

        return RuleEngineVerdict::fromSpecificationResult($result);
    }

    /** {@inheritdoc} */
    public function compileSpecification(
        string $scope,
        ?string $scenario = null,
        array $context = []
    ): ISpecification {
        $specs = [];
        $this->compilationWarnings = [];
        $this->skippedRules = [];

        // 1. Load and compile business rules
        $rules = $this->catalog->findRules($scope, $scenario, $context);
        foreach ($rules as $rule) {
            if ($this->isLookupOnlyRule($rule)) {
                $this->skippedRules[] = $rule;
                continue;
            }
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
        $docRules = $this->catalog->findDocumentRules($scope, $scenario);
        if (!empty($docRules)) {
            $specs[] = $this->documentBuilder->build($docRules, $context);
        }

        if (empty($specs)) {
            return Spec::alwaysTrue();
        }

        $compiled = count($specs) === 1 ? $specs[0] : Spec::allOf(...$specs);

        return $this->simplification ? SpecificationSimplifier::simplify($compiled) : $compiled;
    }

    /**
     * Whether a rule holds only a value to look up (2.5.0, forward 048, D2): its type was declared lookup
     * only by a registry implementing ILookupOnlyRuleTypes and it has no `spec_json` (a document is a
     * validation whatever its type, forward 041, D7).
     *
     * @throws RuleEngineException When the registry also has a handler for the lookup-only type
     */
    private function isLookupOnlyRule(IRuleDefinition $rule): bool
    {
        if (!$this->registry instanceof ILookupOnlyRuleTypes || $rule->getSpecJson() !== null) {
            return false;
        }
        $ruleType = $rule->getRuleType();
        if (!$this->registry->isLookupOnly($ruleType)) {
            return false;
        }
        if ($this->registry->hasHandler($ruleType)) {
            throw new RuleEngineException(sprintf(
                'Ambiguous rule type "%s" (rule %s): it was declared lookup only and a handler supports it.',
                trim($ruleType),
                $rule->getCode()
            ));
        }

        return true;
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
