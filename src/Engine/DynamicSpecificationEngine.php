<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

use Antevemus\ASpecification\Contracts\Engine\IDynamicSpecificationEngine;
use Antevemus\ASpecification\Contracts\Engine\IRuleCatalog;
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
 * - Rich evaluation of domain entities returning a typed RuleEngineVerdict
 * - Automated triage of operational failures (HTTP 403 blocks, warnings, and audit logs)
 * - Transparent integration with ASpecification algebra and SpecificationResult
 *
 * @version    1.1.0
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
     * @param IRuleCatalog $catalog Provider/repository for rule catalog
     * @param IRuleSpecificationRegistry $registry Registry for rule specification handlers
     * @param DocumentGroupSpecificationBuilder|null $documentBuilder Optional document specification compiler
     */
    public function __construct(
        private readonly IRuleCatalog $catalog,
        private readonly IRuleSpecificationRegistry $registry,
        ?DocumentGroupSpecificationBuilder $documentBuilder = null
    ) {
        $this->documentBuilder = $documentBuilder ?? new DocumentGroupSpecificationBuilder();
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

        // 1. Load and compile business rules
        $rules = $this->catalog->findRules($escopo, $cenario, $context);
        foreach ($rules as $rule) {
            $specs[] = $this->registry->buildSpecification($rule);
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
