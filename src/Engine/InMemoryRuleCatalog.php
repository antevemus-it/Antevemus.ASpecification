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
 * - Exact scope/scenario matching for documents (no cross-scope leakage); null scenario = scope-global only
 * - Native descending priority sorting
 *
 * @version    1.2.0
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
            // Rule without scenario applies to the whole scope. A rule bound to a scenario only
            // applies when that exact scenario is requested: validating without a scenario means
            // "scope-global rules only", never "every scenario at once".
            if ($ruleCenario !== null && $ruleCenario !== '' && ($cenario === null || $ruleCenario !== $cenario)) {
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

            if ($this->documentRuleMatches($docRule, $escopo, $cenario)) {
                $matched[] = $docRule;
            }
        }

        // Sort by order column
        usort($matched, fn(IDocumentRuleDefinition $a, IDocumentRuleDefinition $b) => $a->getOrdem() <=> $b->getOrdem());

        return $matched;
    }

    /**
     * Decides whether a document requirement belongs to the requested scope and scenario.
     *
     * Matching is exact on the declared scope and scenario: the scope must be equal; a declared
     * scenario must be equal to the requested one; a null scenario applies to the whole scope.
     * Validating without a scenario (null) only returns scope-global documents. The group code is
     * an identifier and never takes part in matching, so nothing can leak between scopes.
     *
     * @param IDocumentRuleDefinition $docRule
     * @param string $escopo
     * @param string|null $cenario
     * @return bool
     */
    protected function documentRuleMatches(IDocumentRuleDefinition $docRule, string $escopo, ?string $cenario): bool
    {
        if ($docRule->getEscopo() !== $escopo) {
            return false;
        }

        $docCenario = $docRule->getCenario();
        if ($docCenario === null || $docCenario === '') {
            return true;
        }

        return $cenario !== null && $docCenario === $cenario;
    }
}
