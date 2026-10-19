<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Engine;

/**
 * IRuleTypeCatalog - The Rules of One Type, Across Every Scenario, in One Read (2.5.0)
 *
 * IRuleCatalog::findRules() answers "which rules validate this scope in this scenario". An application
 * also asks "which rule of this type applies here" (a deadline, a limit, a coverage), and "which value
 * does each scenario use"; filtering findRules() by type per scenario costs one read per scenario.
 * findRulesByType() returns every active rule of one type of the scope, from every scenario, in one read,
 * and RulePrecedence picks the most specific one (forward 048, D4 and D5).
 *
 * Optional interface, implemented by InMemoryRuleCatalog and PdoRuleCatalog: IRuleCatalog is unchanged,
 * and RulePrecedence::findRule() falls back to findRules() for a catalog that does not implement this one
 * (the same answer, more rows read).
 *
 * @version    2.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IRuleTypeCatalog
{
    /**
     * The active rules of one type for the scope, from every scenario (the scope-global ones and the ones
     * bound to any scenario), in a stable order: priority descending, then `code` ascending (byte order).
     *
     * Scope and applicability follow IRuleCatalog::findRules(): a rule with a null or empty scope applies
     * to every scope; `$filters` reads only the reserved keys `product_code`, `plan_code` and
     * `reference_date` with the same semantics (a restricted rule applies only when the filter brings its
     * value; the validity window only when `reference_date` is given, or through the catalog's own
     * validity filter, such as PdoRuleCatalog::withAsOf()); the Portuguese keys of 1.6.x are refused. Only
     * the scenario is not filtered: the caller chooses (RulePrecedence).
     *
     * @param string $scope Operational scope
     * @param string $ruleType Technical rule type (exact match)
     * @param array<string, mixed> $filters Validation context; reserved keys product_code, plan_code, reference_date
     * @return list<IRuleDefinition>
     */
    public function findRulesByType(string $scope, string $ruleType, array $filters = []): array;
}
