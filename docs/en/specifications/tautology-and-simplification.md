# Tautology, Contradiction & Simplification

### 12. Tautology, Contradiction & Simplification

Every specification answers two structural questions, `isTautology()` and `isContradiction()`. A `true` is a proof from the shape of the tree; a `false` means "not proven" (there is no SAT solving, and `evaluate()` is untouched). The rule engine can report the catalog rules that can never pass or never fail:

```php
use Antevemus\ASpecification\Engine\InMemoryRuleCatalog;
use Antevemus\ASpecification\Engine\RuleDefinition;
use Antevemus\ASpecification\Spec;

$active   = Spec::property('status', Spec::equalTo('ACTIVE'));
$inactive = Spec::property('status', Spec::equalTo('INACTIVE'));

Spec::isContradiction($active->and($active->not()));         // true  (A ∧ ¬A)
Spec::isTautology($active->or($active->not()));              // true  (A ∨ ¬A)
$active->and($inactive)->isContradiction();                  // true  (disjoint leaves, same property)
Spec::in()->isContradiction();                               // true  (the empty set)
Spec::greaterThan(5)->or(Spec::lessThan(10))->isTautology(); // false: not proven (no SAT solving)

// Rule engine: report the rules that can never pass (or never fail), without blocking
$catalog = new InMemoryRuleCatalog();
$catalog->addRule(new RuleDefinition(code: 'R-12', name: 'Active and inactive', ruleType: 'status_both', scope: 'rental_contract'));
$registry = Spec::ruleRegistry()->registerClosure('status_both', fn() => $active->and($inactive));

$engine = Spec::engine($catalog, $registry)->withCatalogValidation();
$engine->compileSpecification('rental_contract');
foreach ($engine->getCompilationWarnings() as $warning) {
    echo $warning->ruleCode . ': ' . $warning->kind, PHP_EOL;   // "R-12: contradiction"
}
```

`simplify()` (since 1.9.0) returns an equivalent, smaller specification. It applies, bottom-up until nothing changes: identity (`A ∧ true = A`, `A ∨ false = A`), annihilation (`A ∧ false = false`, `A ∨ true = true`), double negation, idempotence (`A ∧ A = A`, by `equals()`), flattening of nested `and`/`or`, the contradictions and tautologies proven above (collapsed to `alwaysFalse()`/`alwaysTrue()`) and subsumption (`A ∧ B = A` and `A ∨ B = B` when `A` is a special case of `B`). The input is never modified, and simplifying a simplified tree returns it unchanged. A node annotated with `because()`/`withCode()` is a unit: it is simplified inside but never removed, merged or replaced by a constant, so the verdict and the annotated messages stay the same.

```php
use Antevemus\ASpecification\Engine\InMemoryRuleCatalog;
use Antevemus\ASpecification\Engine\RuleDefinition;
use Antevemus\ASpecification\Spec;

$active = Spec::property('status', Spec::equalTo('ACTIVE'));
$adult  = Spec::property('age', Spec::greaterThanOrEqualTo(18))->because('The tenant must be an adult.')->withCode('TENANT_MINOR');

// A redundant tree, as composed rules often end up: ¬¬A ∧ true ∧ B ∧ (status in ACTIVE, PENDING)
$tree = Spec::not(Spec::not($active))
    ->and(Spec::alwaysTrue())
    ->and($adult)
    ->and(Spec::property('status', Spec::in('ACTIVE', 'PENDING')));  // implied by status = ACTIVE

$simple = $tree->simplify();                                   // same as Spec::simplify($tree)
$simple->equals($active->and($adult));                         // true: double negation, identity and implied leaf removed
Spec::simplify($simple) === $simple;                           // true: nothing left to simplify

// Same truth value, and the annotated failure is still reported
$minor = (object) ['status' => 'ACTIVE', 'age' => 16];
$simple->isSatisfiedBy($minor) === $tree->isSatisfiedBy($minor); // true
$simple->evaluate($minor)->failures[0]->code;                  // 'TENANT_MINOR'

// Proven contradictions and tautologies become constants, but an annotated node is never dropped
Spec::simplify($active->and($active->not()));                  // alwaysFalse()
Spec::simplify($active->or($active->not()));                   // alwaysTrue()
Spec::simplify($adult->and(Spec::alwaysFalse()))->evaluate($minor)->getCodes(); // ['TENANT_MINOR']

// Rule engine: opt-in (off by default); every rule binding is kept and simplified inside
$catalog = new InMemoryRuleCatalog();
$catalog->addRule(new RuleDefinition(code: 'R-30', name: 'Active tenant', ruleType: 'active_tenant', scope: 'rental_contract'));
$registry = Spec::ruleRegistry()->registerClosure('active_tenant', fn() => Spec::not(Spec::not($active))->and(Spec::alwaysTrue()));

$engine = Spec::engine($catalog, $registry)->withSimplification();
(string) $engine->compileSpecification('rental_contract');     // 'Rule[R-30]((AlwaysTrueSpecification WHERE status: EqualSpecification))'
$engine->validate((object) ['status' => 'CLOSED'], 'rental_contract')->getFailureCodes(); // ['R-30']
```

Two limits, both stated in the docblocks. The equivalence holds for **non-null candidates**, the same premise as `isTautology()`/`isContradiction()`: `AND`/`OR`/`NOT` reject `null` whatever their operands, and the node left after simplification may not (`alwaysTrue()->and(alwaysTrue())` rejects `null`, `alwaysTrue()` accepts it). And it is an equivalence of **truth value only**: removing a redundant branch may remove one that would have raised an exception, and the default messages of unannotated nodes may change; only annotated messages and codes are guaranteed. Nothing simplifies by itself: the SQL, TCriteria and ALinq visitors compile the tree as given, and the rule engine simplifies only when asked.

**The algebra never promises more than the evaluation delivers** (since v2.1.0). `equals()`, subsumption, disjointness, `isTautology()`/`isContradiction()`, `simplify()`, the deduplication of `and()`/`or()` and the partition lookup are checked by a soundness bench against the evaluation of every concrete specification class over boundary candidates (Code Quality); where they disagreed, the algebra now proves less. Three consequences are worth knowing. **Date-times are compared by instant**: `equalTo()`, `notEqual()` and `in()` compare a `DateTimeInterface` by its instant, whatever its class and time zone, as the relational leaves, `at()` and the SQL visitor already did, so `Spec::equalTo(new DateTimeImmutable('2025-01-01 10:00', new DateTimeZone('UTC')))` accepts `new DateTime('2025-01-01 07:00', new DateTimeZone('America/Sao_Paulo'))` in memory as in the database (up to 2.0.0 memory compared instances); other objects are compared by identity, in the evaluation and in `equals()`. And **integer bounds are not closed**: the relational leaves accept any number, so `greaterThan(10)` and `lessThan(11)` are not disjoint (`10.5` satisfies both) and their conjunction is not simplified away, unless both leaves are int-typed (since v2.9.0: example 12.1 below shows how to get the closing back). And **`NAN` is not a candidate of the ordered leaves** (since v2.2.0): `greaterThan()`, `greaterThanOrEqualTo()`, `lessThan()` and `lessThanOrEqualTo()` raise `IncompatibleTypeException` for it, as they do for a text against a number, and refuse it as a bound (`InvalidArgumentException`), because `NAN < v`, `NAN > v` and `NAN == v` are all false and `not(lessThan(v))` would accept what `greaterThanOrEqualTo(v)` refuses; `equalTo()`, `notEqual()` and `in()` keep answering it (`NAN` is equal to nothing). And **a typed default value refuses `null`** (since v2.2.0): `isBlankString()` is "a text that is empty or whitespace", `defaultNumber()` "a number that is zero", `defaultValueOfType('bool')` `false` and `('array')` `[]`, as `default(T)` of a non-nullable type; `null` is the default of some type, so only the untyped `defaultValue()` accepts it, and two typed defaults of different types are disjoint for every candidate.

### 12.1. Getting the Closing Back: Integer Leaves (Module 44)

When the domain is made of integers (installments, months, quantities), make the leaves int-typed: `asInteger()` on a leaf, or `Spec::int()` for a whole rule (example 1.1). An int-typed leaf refuses every candidate that is not an int, `12.0` included, so between two int-typed leaves `x > 10` is `x >= 11` and `x < 11` is `x <= 10`: disjointness, subsumption, `isContradiction()` and `simplify()` prove again what they proved up to v2.0.0, and this time the promise holds, because the evaluation refuses `10.5`. Against a leaf that is not int-typed nothing is closed, as before; a bound at the end of the int range (`PHP_INT_MAX`, `PHP_INT_MIN`) is left open. `simplify()` still never adds a node: `x > 10 ∧ x < 12` stays a conjunction, it does not become `x = 11`.

```php
use Antevemus\ASpecification\Spec;

// Leaves that accept floats are not closed: 10.5 satisfies both
Spec::greaterThan(10)->isDisjointWith(Spec::lessThan(11));                // false

// Int-typed on both sides, x > 10 is x >= 11: the closing is back
$over10 = Spec::int()->greaterThan(10);
$under11 = Spec::lessThan(11)->asInteger();
$over10->isDisjointWith($under11);                                        // true
Spec::int()->greaterThanOrEqualTo(11)->isGeneralizationOf($over10);       // true: the same integers
Spec::isContradiction($over10->and($under11));                            // true
Spec::simplify($over10->and($under11));                                   // alwaysFalse()
Spec::simplify($over10->or(Spec::int()->greaterThanOrEqualTo(11)))
    ->equals(Spec::int()->greaterThanOrEqualTo(11));                      // true: one leaf left

// The promise holds because the evaluation refuses what lies between the integers
$over10->evaluate(10.5)->isError;                                         // true
$under11->evaluate(10.5)->isError;                                        // true

// Only between two int-typed leaves: against a leaf that accepts floats nothing is closed
$over10->isDisjointWith(Spec::lessThan(11));                              // false
```
