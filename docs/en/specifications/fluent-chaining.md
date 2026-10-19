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
