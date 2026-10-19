<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

use Antevemus\ASpecification\Contracts\Engine\IRuleCatalog;
use Antevemus\ASpecification\Contracts\Engine\IRuleDefinition;
use Antevemus\ASpecification\Contracts\Engine\IRuleTypeCatalog;

/**
 * RulePrecedence - The Most Specific Rule of a Type (2.5.0, forward 048, D5)
 *
 * A catalog may hold several active rules of one type for a scope: a generic one and one per scenario, or
 * one restricted to a product or a plan. The application wants the one that applies; IRuleCatalog
 * findRules() keeps its order (priority first, so a specific rule may come after a generic one with a
 * higher priority, which is the order of the failures in a verdict), and this class answers the question
 * with an explicit precedence, the same over any catalog:
 *
 * 1. the requested scenario over a scope-global rule (a rule of another scenario never applies);
 * 2. a plan restriction over a product restriction over no restriction (the rules come from a catalog
 *    with the filters of the validation, so a restriction present on a rule is one the filters met);
 * 3. the higher priority;
 * 4. the smaller `code` in byte order (strcmp): the code is the primary key of the catalog, so no tie
 *    remains and the answer never depends on the order in which the rules were read or added.
 *
 * Pure and static: it reads only the rules given (inactive rules and rules of other types are ignored).
 * findRule() reads them from a catalog in one query when the catalog implements IRuleTypeCatalog, or
 * through findRules() filtered by type otherwise (the same answer, more rows read).
 *
 * <code>
 * $rule = RulePrecedence::findRule($catalog, 'claim', 'claim_deadline', 'occupied');   // the scenario's, or the generic one
 * $byScenario = RulePrecedence::byScenario($catalog->findRulesByType('claim', 'debt_coverage'), 'debt_coverage');
 * </code>
 *
 * @version    2.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class RulePrecedence
{
    private function __construct()
    {
    }

    /**
     * The most specific active rule of the type for the scenario, or null when none applies.
     *
     * With a scenario, the candidates are the scope-global rules and the rules of that exact scenario;
     * with null (or ''), only the scope-global ones, as in IRuleCatalog::findRules().
     *
     * @param iterable<IRuleDefinition> $rules Rules read from a catalog (any order)
     * @param string $ruleType Technical rule type (exact match)
     * @param string|null $scenario Requested scenario; null = scope-global rules only
     * @return IRuleDefinition|null
     */
    public static function mostSpecific(iterable $rules, string $ruleType, ?string $scenario): ?IRuleDefinition
    {
        $scenario = ($scenario === null || $scenario === '') ? null : $scenario;
        $best = null;
        foreach ($rules as $rule) {
            if (!$rule->isActive() || $rule->getRuleType() !== $ruleType) {
                continue;
            }
            $ruleScenario = self::scenarioOf($rule);
            if ($ruleScenario !== null && $ruleScenario !== $scenario) {
                continue;
            }
            if ($best === null || self::compare($rule, $best) < 0) {
                $best = $rule;
            }
        }

        return $best;
    }

    /**
     * The most specific active rule of the type of each scenario that has one, by scenario (the scenarios
     * in byte order, as scenariosOf()); scope-global rules are not listed.
     *
     * @param iterable<IRuleDefinition> $rules Rules read from a catalog (any order)
     * @param string $ruleType Technical rule type (exact match)
     * @return array<string, IRuleDefinition>
     */
    public static function byScenario(iterable $rules, string $ruleType): array
    {
        $rules = is_array($rules) ? $rules : iterator_to_array($rules, false);
        $byScenario = [];
        foreach (self::scenariosOf($rules, $ruleType) as $scenario) {
            $rule = self::mostSpecific($rules, $ruleType, $scenario);
            if ($rule !== null) {
                $byScenario[$scenario] = $rule;
            }
        }

        return $byScenario;
    }

    /**
     * The distinct scenarios (not null, not empty) of the active rules of the type, in byte order.
     *
     * @param iterable<IRuleDefinition> $rules
     * @param string $ruleType Technical rule type (exact match)
     * @return list<string>
     */
    public static function scenariosOf(iterable $rules, string $ruleType): array
    {
        $scenarios = [];
        foreach ($rules as $rule) {
            $scenario = self::scenarioOf($rule);
            if ($scenario !== null && $rule->isActive() && $rule->getRuleType() === $ruleType) {
                $scenarios[$scenario] = $scenario;
            }
        }
        usort($scenarios, 'strcmp');   // usort() also renumbers the list

        return $scenarios;
    }

    /**
     * The most specific rule of the type for the scope and the scenario, read from the catalog with the
     * applicability filters (`product_code`, `plan_code`, `reference_date`).
     *
     * @param IRuleCatalog $catalog Any catalog; one implementing IRuleTypeCatalog is read in one query
     * @param string $scope Operational scope
     * @param string $ruleType Technical rule type (exact match)
     * @param string|null $scenario Requested scenario; null = scope-global rules only
     * @param array<string, mixed> $filters Validation context (reserved keys only)
     * @return IRuleDefinition|null
     */
    public static function findRule(
        IRuleCatalog $catalog,
        string $scope,
        string $ruleType,
        ?string $scenario = null,
        array $filters = []
    ): ?IRuleDefinition {
        $rules = $catalog instanceof IRuleTypeCatalog
            ? $catalog->findRulesByType($scope, $ruleType, $filters)
            : $catalog->findRules($scope, $scenario, $filters);

        return self::mostSpecific($rules, $ruleType, $scenario);
    }

    /**
     * Orders two candidate rules by precedence: negative when $a is more specific than $b.
     */
    public static function compare(IRuleDefinition $a, IRuleDefinition $b): int
    {
        return (self::scenarioOf($b) !== null) <=> (self::scenarioOf($a) !== null)
            ?: self::restrictionLevel($b) <=> self::restrictionLevel($a)
            ?: $b->getPriority() <=> $a->getPriority()
            ?: strcmp($a->getCode(), $b->getCode());
    }

    /**
     * 2 = restricted to a plan, 1 = restricted to a product, 0 = no restriction.
     */
    private static function restrictionLevel(IRuleDefinition $rule): int
    {
        $parameters = $rule->getParameters();
        if (self::isRestricted($parameters[InMemoryRuleCatalog::FILTER_PLAN] ?? null)) {
            return 2;
        }

        return self::isRestricted($parameters[InMemoryRuleCatalog::FILTER_PRODUCT] ?? null) ? 1 : 0;
    }

    private static function isRestricted(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }

    private static function scenarioOf(IRuleDefinition $rule): ?string
    {
        $scenario = $rule->getScenario();

        return ($scenario === null || $scenario === '') ? null : $scenario;
    }
}
