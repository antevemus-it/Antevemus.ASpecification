<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Engine;

/**
 * ILookupOnlyRuleTypes - Rule Types That Hold a Value to Look Up and Validate Nothing (2.5.0)
 *
 * A catalog often mixes rules that validate (a maximum, a deadline, a forbidden index) with rows that
 * only hold a parameter read by the application (a payment window, a notice period, a switch). A rule
 * type declared "lookup only" by the registry has no specification: the engine skips its rules when it
 * compiles a scope, instead of failing the whole compilation with MissingRuleHandlerException, and
 * reports them through DynamicSpecificationEngine::getSkippedRules() (forward 048, D1-D3).
 *
 * The declaration is explicit and lives in code, never in the catalog: a rule type nobody declared and
 * nobody handles is still a MissingRuleHandlerException (a mistyped `rule_type`, or the forgotten handler
 * of a blocking rule, never becomes an approval). A type cannot be both lookup only and handled; a rule of
 * a lookup-only type that carries a `spec_json` is still a validation (the document wins over the type).
 *
 * Optional interface, implemented by RuleSpecificationRegistry: IRuleSpecificationRegistry is unchanged,
 * and the engine reads this one only when the registry implements it.
 *
 * @version    2.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface ILookupOnlyRuleTypes
{
    /**
     * Whether the rule type was declared lookup only (it holds a value and validates nothing).
     *
     * @param string $ruleType Technical rule type key (compared without surrounding blanks)
     * @return bool
     */
    public function isLookupOnly(string $ruleType): bool;
}
