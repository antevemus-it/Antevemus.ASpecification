# Declarative Method Calls (`MethodParameterizedSpecification`)

### 13. Declarative Method Calls (`MethodParameterizedSpecification`)

`where()` reads a property; `whereMethod()` / `Spec::calling()` call a public method of the candidate with arguments declared as data and apply a specification to the result. Unlike `must(closure)`, the method name and the arguments are data: the specification has structural `equals()`, a rule name and can be described in a catalog. A missing method is an evaluation error, never a silent `false`.

```php
use Antevemus\ASpecification\Spec;

final class Contract
{
    public function __construct(private DateTimeImmutable $endsAt, private int $installments) {}
    public function isEligibleFor(DateTimeInterface $date): bool { return $date <= $this->endsAt; }
    public function installmentsLeft(int $paid): int { return $this->installments - $paid; }
}

$eligible = Spec::specify(Contract::class)
    ->whereMethod('isEligibleFor', [new DateTimeImmutable('2026-12-01')], Spec::isTrue())
    ->and(Spec::calling('installmentsLeft', [3], Spec::greaterThan(0)));

$eligible->isSatisfiedBy(new Contract(new DateTimeImmutable('2027-06-30'), 12)); // true
$eligible->isSatisfiedBy(new Contract(new DateTimeImmutable('2026-06-30'), 12)); // false (not eligible)
$eligible->isSatisfiedBy(new Contract(new DateTimeImmutable('2027-06-30'), 3));  // false (nothing left)

// Declarative: method name and arguments are data, so equality is structural
Spec::calling('installmentsLeft', [3], Spec::greaterThan(0))
    ->equals(Spec::calling('installmentsLeft', [3], Spec::greaterThan(0)));      // true

// A missing method is an evaluation error, never a silent false
Spec::calling('isEligibleFor', [new DateTimeImmutable('2026-12-01')], Spec::isTrue())
    ->evaluate(new stdClass())->isError;                                          // true
```

The SQL and TCriteria visitors refuse a method call as a non-translatable leaf (like `must()`); the ALinq visitor compiles it, and since v2.4.0 `SpecificationCodec` writes it as data (`"t": "method"`, example 21). A date-time argument is passed by instant (since v2.1.0): the method receives a copy of the same class at the same instant, in the default time zone of PHP (`date_default_timezone_get()`), whatever zone it was declared in, so two calls that `equals()` declares equal also answer the same.
