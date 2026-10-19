# TCriteria Builder (Adianti Framework Database Bridge - Module 13)

### 9. TCriteria Builder (Adianti Framework Database Bridge - Module 13)

Translate pure domain specifications into native `Adianti\Database\TCriteria` and `TFilter` instances, applying **De Morgan's Laws** for nested negations and maintaining strict Boolean precedence:

```php
use Antevemus\ASpecification\Spec;

// 1. Pure domain specification with conjunction, disjunction, and negation
$spec = Spec::property('active', Spec::equalTo(true))
    ->and(
        Spec::property('salary', Spec::greaterThan(5000))
            ->or(Spec::property('city', Spec::wildcard('New*')))
    )
    ->and(Spec::property('status', Spec::not(Spec::equalTo('CANCELLED'))));

// 2. Direct compilation with column mapping and query properties
$criteria = Spec::toCriteria(
    specification: $spec,
    fieldMap: [
        'active' => 'st_active',
        'salary' => 'vl_salary',
        'city'   => 'ds_city',
        'status' => 'tp_status'
    ],
    properties: [
        'order'     => 'vl_salary',
        'direction' => 'desc',
        'limit'     => 50,
        'offset'    => 0
    ]
);

echo $criteria->dump();
// '((st_active = TRUE AND (vl_salary > 5000 OR ds_city LIKE 'New%')) AND tp_status <> 'CANCELLED')'

// 3. Direct invocation from any ISpecification instance
$criteriaFromInstance = $spec->toCriteria();
```

---

Since v2.5.0: comparisons that ignore case and accents for a declared database (`withAccentFolding()`, `Spec::toCriteria(..., accentFolding:)`), written as `toSql()` writes them, and subqueries (`inSubquery()`, `existsIn()`) instead of `NOESC:(SELECT ...)`; both need a connection with `prep = 1` (example 22.1). Values that start with `(SELECT`, contain `{session.` or start with `NOESC:` remain refused.
