# Getting Started

## 📋 Requirements

- **PHP**: `^8.2` (tested on PHP 8.2 and 8.4). PHP 8.4 is required only by the optional ALinq integration (`Antevemus.AlinqCollection`, Module 14), which the library detects at runtime.
- **PHP Extensions**:
  - `ext-json` (for JSON entity serialization; required)
  - `ext-mbstring` (required since v1.5.0: Unicode-aware case-insensitive string specifications, `mb_strtolower`)
  - `ext-intl` *(optional: `TextComparison::IgnoreCaseAndAccents`, the text comparison that also ignores accents, examples 20 and 20.1; without it that option is refused with `MissingExtensionException` and everything else works)*
  - `ext-sysvsem` *(optional: `SysVSemaphoreSynchronizer`, the reader/writer lock shared between processes through SysV IPC semaphores, Linux/Unix)*
  - `ext-curl` *(optional: `HttpDataSource`, the concurrent HTTP fetching of the `DataResolver`, Module 21; without it an HTTP source is refused when registered and `CallableDataSource` keeps working)*
  - `ext-pdo` *(optional, with the driver of your database: `PdoRepository` (Modules 22, 25 and 26), the PDO stores of the state machine, `PdoRuleCatalog` and the PDO domain event outbox and trigger state store (Modules 23 and 24))*
- **Dependency**: one, from the same author, with no dependencies of its own: [`antevemus/adeep-property-access`](https://github.com/antevemus-it/Antevemus.ADeepPropertyAccess) `^1.0` (since v2.0.0), the property accessor shared with ALinq, so that both libraries read the same value from the same candidate (example 10.1). Composer installs it.
- **Optional package**: `antevemus/alinq-collection` `^2.2.0` for `ALinqBridge` (typed `IALinqCollection`/`IALinqLazyCollection` returns), `ALinqSpecificationVisitor` and O(1) lazy streaming over `IRepository::iterate()` (requires PHP 8.4; ALinq reads a non-public property only when it is marked `#[Specifiable]`, the same rule as this package, and its 2.0 resolves properties through the same shared accessor).

> [!NOTE]
> **Upgrading from 1.x?** Read [UPGRADE-2.0.md](https://github.com/antevemus-it/Antevemus.ASpecification/blob/main/UPGRADE-2.0.md) first: `.bin` files of the PHP-native serializer and rule catalogs with Portuguese values are converted on 1.14.x, before the upgrade.

## 🚀 Installation

Install via Composer:

```bash
composer require antevemus/aspecification
```

## First Use

A rule is an object: compose it, ask it whether a candidate satisfies it, get every failure with its code and message without an exception, and compile the same rule to SQL. After `composer require`, with Composer's autoloader loaded:

```php
use Antevemus\ASpecification\Spec;

// Two business rules, each with its own message and code
$adult = Spec::property('age', Spec::greaterThanOrEqualTo(18))
    ->because('Customer must be of legal age.')
    ->withCode('CLI_001');
$active = Spec::property('status', Spec::equalTo('ACTIVE'))
    ->because('Only active accounts can receive a credit line.')
    ->withCode('CLI_002');
$creditRule = $adult->and($active);

$customer = (object) ['age' => 17, 'status' => 'ACTIVE'];   // any object: an entity, a DTO, a stdClass

$creditRule->isSatisfiedBy($customer);                      // false

$result = $creditRule->evaluate($customer);                 // no exception: a result with every failure
count($result->failures);                                   // 1
$result->failures[0]->code;                                 // 'CLI_001'
$result->failures[0]->message;                              // 'Customer must be of legal age.'

// The same rule as a parameterized WHERE clause (15 drivers, 10 SQL dialects)
$where = Spec::toSql($creditRule, 'pgsql');
$where->getSql();         // '(("age" IS NOT NULL AND "age" >= :p1) AND ("status" IS NOT NULL AND "status" = :p2))'
$where->getParameters();  // [':p1' => 18, ':p2' => 'ACTIVE']
```

Next: the [notification pattern](../specifications/notification-pattern.md) (what to show when a rule cannot be evaluated), [fluent chaining](../specifications/fluent-chaining.md) (rules written as sentences) and the [SQL query visitor](../sql/query-visitor.md) (every dialect).
