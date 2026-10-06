<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

use Antevemus\ASpecification\Contracts\Engine\IDocumentRuleDefinition;
use Antevemus\ASpecification\Contracts\Engine\IRuleCatalog;
use Antevemus\ASpecification\Contracts\Engine\IRuleDefinition;

/**
 * InMemoryRuleCatalog - In-Memory Rule and Document Catalog
 *
 * In-memory implementation of IRuleCatalog designed for unit testing, isolated
 * development environments, and rapid dynamic rule compilation.
 *
 * Features:
 * - Fluent in-memory storage of business rules and document requirements
 * - Filtering by operational scope, business scenario, and active status
 * - Native descending priority sorting
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class InMemoryRuleCatalog implements IRuleCatalog
{
    /** @var list<IRuleDefinition> */
    private array $rules = [];

    /** @var list<IDocumentRuleDefinition> */
    private array $documentRules = [];

    /**
     * @param list<IRuleDefinition> $rules Initial rules collection
     * @param list<IDocumentRuleDefinition> $documentRules Initial document requirements collection
     */
    public function __construct(array $rules = [], array $documentRules = [])
    {
        foreach ($rules as $rule) {
            $this->addRule($rule);
        }
        foreach ($documentRules as $docRule) {
            $this->addDocumentRule($docRule);
        }
    }

    /**
     * Adds a business rule to the catalog.
     *
     * @param IRuleDefinition $rule
     * @return self
     */
    public function addRule(IRuleDefinition $rule): self
    {
        $this->rules[] = $rule;
        return $this;
    }

    /**
     * Adds a document requirement to the catalog.
     *
     * @param IDocumentRuleDefinition $documentRule
     * @return self
     */
    public function addDocumentRule(IDocumentRuleDefinition $documentRule): self
    {
        $this->documentRules[] = $documentRule;
        return $this;
    }

    /** {@inheritdoc} */
    public function findRules(string $escopo, ?string $cenario = null, array $filters = []): array
    {
        $matched = [];

        foreach ($this->rules as $rule) {
            if (!$rule->isActive()) {
                continue;
            }

            $ruleEscopo = $rule->getEscopo();
            // Global rule (null scope) or matching scope
            if ($ruleEscopo !== null && $ruleEscopo !== '' && $ruleEscopo !== $escopo) {
                continue;
            }

            $ruleCenario = $rule->getCenario();
            // Rule without specific scenario (applies to all in scope) or matching scenario
            if ($ruleCenario !== null && $ruleCenario !== '' && $cenario !== null && $ruleCenario !== $cenario) {
                continue;
            }

            $matched[] = $rule;
        }

        // Sort by descending priority
        usort($matched, fn(IRuleDefinition $a, IRuleDefinition $b) => $b->getPrioridade() <=> $a->getPrioridade());

        return $matched;
    }

    /** {@inheritdoc} */
    public function findDocumentRules(string $escopo, ?string $cenario = null): array
    {
        $matched = [];

        foreach ($this->documentRules as $docRule) {
            if (!$docRule->isActive()) {
                continue;
            }

            // grupo_codigo may represent either scope or scenario
            $grupo = $docRule->getGrupoCodigo();
            if ($cenario !== null && $grupo === $cenario) {
                $matched[] = $docRule;
                continue;
            }

            if ($grupo === $escopo) {
                $matched[] = $docRule;
                continue;
            }

            // If prefixed by scope (e.g., "rental_contract_activation" for scope "rental_contract")
            if (str_starts_with($grupo, $escopo)) {
                $matched[] = $docRule;
            }
        }

        // Sort by order column
        usort($matched, fn(IDocumentRuleDefinition $a, IDocumentRuleDefinition $b) => $a->getOrdem() <=> $b->getOrdem());

        return $matched;
    }
}
