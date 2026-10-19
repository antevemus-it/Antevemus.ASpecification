# A Rule Engine for a Real Consumer: Lookup-Only Types, the Most Specific Rule and Target Labels

### 22. A Rule Engine for a Real Consumer: Lookup-Only Types, the Most Specific Rule and Target Labels (Module 36)

Since v2.5.0 the rule engine covers three things a consumer of the catalog wrote by hand (forward 048, measured at the Virttus). **Lookup-only rule types**: a catalog mixes rules that validate with rows that only hold a value the application reads (a notice window, a payment day, a switch); `registerLookupOnly()` declares those types in code, the engine skips their rules instead of failing the whole scope with `MissingRuleHandlerException`, and `getSkippedRules()` lists them. **The most specific rule of a type**: `RulePrecedence` picks, among the active rules of a type, the one of the requested scenario over the scope-global one, then a plan restriction over a product restriction over none, then the higher priority, then the smaller `code`; `findRulesByType()` reads every scenario of a type in one query. **A human label for the target**: `labelled()` reports every failure against a text built for the user, without a subclass.

```php
use Antevemus\ASpecification\Engine\DynamicSpecificationEngine;
use Antevemus\ASpecification\Engine\InMemoryRuleCatalog;
use Antevemus\ASpecification\Engine\RuleDefinition;
use Antevemus\ASpecification\Engine\RulePrecedence;
use Antevemus\ASpecification\Engine\RuleSpecificationRegistry;
use Antevemus\ASpecification\Spec;

$catalog = new InMemoryRuleCatalog([
    new RuleDefinition(code: 'max_claims', name: 'Ongoing claims', ruleType: 'claim_max', intValue: 2, violationMessage: 'Too many ongoing claims.', scope: 'claim'),
    // Values the application reads: they validate nothing
    new RuleDefinition(code: 'notice_days', name: 'Notice window', ruleType: 'claim_notice', intValue: 30, scope: 'claim'),
    new RuleDefinition(code: 'notice_days_vacant', name: 'Notice window, vacant', ruleType: 'claim_notice', intValue: 45, scope: 'claim', scenario: 'vacant'),
]);
$facts = ['ongoing' => 3];

$registry = (new RuleSpecificationRegistry())
    ->registerClosure('claim_max', fn(RuleDefinition $rule) => Spec::property('ongoing', Spec::lessThanOrEqualTo($rule->getIntValue()))
        ->withCode($rule->getCode())
        ->because($rule->getViolationMessage())
        ->labelled("Ongoing claims: {$facts['ongoing']} (maximum {$rule->getIntValue()})"))
    ->registerLookupOnly('claim_notice');                       // without it: MissingRuleHandlerException

$engine = new DynamicSpecificationEngine($catalog, $registry);
$verdict = $engine->validate($facts, 'claim');
$failure = $verdict->getBlockingFailures()[0];
$failure->property;                                             // 'Ongoing claims: 3 (maximum 2)'
$failure->metadata['property_path'];                            // 'ongoing'
$engine->getSkippedRules()[0]->getCode();                       // 'notice_days'

// The most specific rule of a type: the scenario's, else the scope-global one
RulePrecedence::findRule($catalog, 'claim', 'claim_notice', 'vacant')->getIntValue();     // 45
RulePrecedence::findRule($catalog, 'claim', 'claim_notice', 'occupied')->getIntValue();   // 30
$byScenario = RulePrecedence::byScenario($catalog->findRulesByType('claim', 'claim_notice'), 'claim_notice');
array_map(fn(RuleDefinition $rule): int => $rule->getIntValue(), $byScenario);           // ['vacant' => 45]: one read
```

- **Lookup-only types** (`ILookupOnlyRuleTypes`, implemented by `RuleSpecificationRegistry`). The declaration lives in code, never in the catalog: a column "this type validates nothing" would let whoever edits a row switch a validation off. A type nobody declared and nobody handles is still a `MissingRuleHandlerException` (a mistyped `rule_type`, or the forgotten handler of a blocking rule, never becomes an approval). A type cannot be lookup only and handled at once: a closure for a lookup-only type, or the reverse, is refused when it is registered (`RuleEngineException`), and a class handler whose `supports()` accepts a lookup-only type is an "ambiguous rule type" when a rule of it is compiled. A rule of a lookup-only type that carries a `spec_json` (example 21) is a validation: the document wins over the type. `getSkippedRules()` is filled by every compilation, whatever the catalog validation mode, in catalog order.
- **Precedence** (`RulePrecedence`, static and pure). `mostSpecific($rules, $type, $scenario)` over rules already read; `findRule($catalog, $scope, $type, $scenario, $filters)` reads them in one query from a catalog implementing `IRuleTypeCatalog` (`InMemoryRuleCatalog`, `PdoRuleCatalog`), or through `findRules()` filtered by type for any other catalog (the same answer, more rows read); `byScenario()` gives the most specific rule of each scenario (scope-global rules left out) and `scenariosOf()` the scenarios, in byte order. The tie on `code` (the primary key) makes the answer independent of the order in which rules were added or read: before, the in-memory catalog broke ties by insertion and the PDO one by `code`. `findRulesByType($scope, $type, $filters)` applies the same scope, product, plan and validity filters as `findRules()`, without the scenario, ordered by priority then `code`. `IRuleCatalog` gains no method, and `findRules()` keeps its order (the order of the failures in a verdict).
- **Labels** (`LabelledSpecification`; `$spec->labelled($label)` or `Spec::labelled($label, $spec)`). Each failure of the result, rule failure or evaluation error, gets `property` = the label and `metadata['label']`; the technical property moves to `metadata['property_path']`. The answer, `isError` and the exception never change, and the label is never SQL: `toSql()`, `toCriteria()`, the ALinq compiler and the algebra see the labelled specification. Nested labels: the innermost wins (`allOf($a->labelled('A'), $b->labelled('B'))->labelled('Group')` reports A and B, and Group only names failures that had no label). `because()`/`withCode()` on the label replace message and code of each rule failure, never of an evaluation error. `simplify()` keeps the node, the codec writes it as `{"t": "labelled", "label": "...", "s": {...}}` (a document that 2.4.0 does not read), and a bound rule reported once (no failure with a code) lists two or more distinct labels in `metadata['labels']`.
