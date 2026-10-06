<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Engine;

use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Engine\RuleEngineVerdict;

/**
 * IDynamicSpecificationEngine - Master Orchestrator for Dynamic Specification Engine
 *
 * Coordinates catalog rule retrieval, Boolean specification tree compilation,
 * execution against the target entity, and operational verdict generation.
 *
 * Features:
 * - Unified rule and document requirement validation in a single command
 * - Isolated compilation of composite specifications for inspection or testing
 * - Direct access to rule catalog and handler registry
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IDynamicSpecificationEngine
{
    /**
     * Evaluates the target against all active rules and document requirements for the specified scope and scenario.
     *
     * @param object|array $target Domain entity or data structure under evaluation
     * @param string $escopo Operational scope (e.g. 'rental_contract', 'claim')
     * @param string|null $cenario Optional business scenario
     * @param array<string, mixed> $context Additional contextual metadata
     * @return RuleEngineVerdict
     */
    public function validate(
        object|array $target,
        string $escopo,
        ?string $cenario = null,
        array $context = []
    ): RuleEngineVerdict;

    /**
     * Compiles and returns the unified composite specification without executing it immediately.
     *
     * @param string $escopo Operational scope
     * @param string|null $cenario Optional business scenario
     * @param array<string, mixed> $context Additional contextual metadata
     * @return ISpecification
     */
    public function compileSpecification(
        string $escopo,
        ?string $cenario = null,
        array $context = []
    ): ISpecification;

    /**
     * Returns the associated rule catalog.
     *
     * @return IRuleCatalog
     */
    public function getCatalog(): IRuleCatalog;

    /**
     * Returns the rule specification handler registry.
     *
     * @return IRuleSpecificationRegistry
     */
    public function getRegistry(): IRuleSpecificationRegistry;
}
