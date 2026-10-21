# Natural Language Fluent Chaining

### 1. Natural Language Fluent Chaining

Inspired by Domian Java's syntax, combine business rules fluently and expressively:

```php
use function Antevemus\ASpecification\DSL\specify;
use function Antevemus\ASpecification\DSL\is;
use function Antevemus\ASpecification\DSL\not;
use function Antevemus\ASpecification\DSL\isBefore;
use function Antevemus\ASpecification\DSL\isAfterOrAt;
use function Antevemus\ASpecification\DSL\anyOf;

// Specification: Female customers who have been members for over a year,
// OR male customers older than 10 years of age.
$loyalFemale = specify(Customer::class)
    ->where('gender', is('FEMALE'))
    ->and('membershipDate', isBefore($oneYearAgo));

$seniorMale = specify(Customer::class)
    ->where('gender', is('MALE'))
    ->and('birthDate', not(isAfterOrAt($tenYearsAgo)));

$spec = anyOf($loyalFemale, $seniorMale);

// Standard Boolean evaluation
if ($spec->isSatisfiedBy($customer)) {
    // Domain rule satisfied!
}
```

### 1.1. Integer Leaves: `asInteger()` and `Spec::int()` (Module 44)

Since v2.9.0 a relational leaf can be int-typed. `asInteger()` returns a copy of the leaf whose `getType()` is `'int'`: it refuses every candidate that is not an int with `IncompatibleTypeException` (a float, `12.0` included, a numeric text, a boolean), as `declare(strict_types=1)` refuses them for an `int` parameter, and its bound must be an int (`greaterThan(10.5)->asInteger()` and `greaterThan(10.0)->asInteger()` are refused with `InvalidArgumentException`). `null` keeps the answer of the leaf. `Spec::int()` is the same thing for a whole rule about integers: `greaterThan()`, `greaterThanOrEqualTo()`, `lessThan()`, `lessThanOrEqualTo()`, `between(int, int)`, `equalTo()`, `notEqualTo()` and `in()`, each one the leaf of the general factory made int-typed. The conversion belongs to the input boundary (a mapper, a form, `json_decode()`, which already gives an int for `12`), not to the rule.

```php
use Antevemus\ASpecification\Spec;
use function Antevemus\ASpecification\DSL\greaterThanOrEqualTo;

// A rule about whole months: the int-typed leaf refuses what is not an int
$minimumPeriod = Spec::int()->greaterThanOrEqualTo(12);          // same as Spec::greaterThanOrEqualTo(12)->asInteger()
$minimumPeriod->getType();                                       // 'int'
$minimumPeriod->isSatisfiedBy(12);                               // true
$minimumPeriod->isSatisfiedBy(6);                                // false
$minimumPeriod->evaluate(12.0)->isError;                         // true: a float, even without a fractional part
$minimumPeriod->evaluate(10.5)->isError;                         // true
$minimumPeriod->evaluate('12')->isError;                         // true: a numeric text; convert it where it is read
greaterThanOrEqualTo(12)->asInteger()->equals($minimumPeriod);   // true: the DSL function returns the leaf too
Spec::greaterThanOrEqualTo(12)->equals($minimumPeriod);          // false: that one accepts 12.5

$rule = Spec::property('adjustmentPeriodMonths', $minimumPeriod)
    ->and(Spec::property('installments', Spec::int()->between(1, 12)));
$rule->isSatisfiedBy((object) ['adjustmentPeriodMonths' => 12, 'installments' => 10]);   // true
$rule->evaluate((object) ['adjustmentPeriodMonths' => '12', 'installments' => 10])->isError; // true
Spec::toSql($rule, 'pgsql')->getSql();                           // the SQL of the same rule without the integer mode
```

- **The return types.** `Spec::equalTo()`, `equal()`, `notEqual()`, `greaterThan()`, `greaterThanOrEqualTo()`, `lessThan()` and `lessThanOrEqualTo()`, the DSL functions of the same names and those methods of `Spec::getFactory()` declare their leaf class (`GreaterThanSpecification`, ...) instead of `ISpecification`. Return types are covariant, so no caller breaks, and the IDE and static analysis now see `asInteger()`. The factory interfaces (`IComparisonSpecificationFactory`) are unchanged, so their implementations do not break either.
- **The translations do not change.** The SQL of every dialect, the `TCriteria` and the ALinq filter of an int-typed leaf are those of the same leaf without the mode: the column of the database already holds integers. ALinq evaluates the leaf itself, so a collection with `12.0` raises as `isSatisfiedBy()` does.
- **The algebra.** Two int-typed leaves close their bounds against each other (`x > 10` is `x >= 11`); example 12.1 shows what that gives back. A serialized int-typed leaf carries `"int": true` (`{"t": "gte", "v": 12, "int": true}`, example 21) and is read back int-typed.
