# Antevemus ASpecification

<p align="left">
  <strong>🇺🇸 English</strong> &nbsp;|&nbsp; <a href="README.pt-BR.md">🇧🇷 Português (Brasil)</a>
</p>

[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php&logoColor=white)](https://php.net)
[![Latest Version](https://img.shields.io/badge/Release-v1.4.3-blue.svg)](https://github.com/antevemus-it/Antevemus.ASpecification/releases/tag/v1.4.3)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Tests](https://img.shields.io/badge/Tests-16%2F16%20Suites%20Pass%20(2870%20Assertions)%20%2B%20Domian%20Parity%20213-success)](tests/run_all.php)
[![Architecture](https://img.shields.io/badge/Architecture-DDD%20%7C%20Evans%20%26%20Fowler%20Specification-orange)](http://www.martinfowler.com/apsupp/spec.pdf)
[![Upstream: Domian](https://img.shields.io/badge/Origin-Domian%20(Apache%202.0)-brightgreen)](https://domian.sourceforge.net/index.html)
[![Synergy: ALinq](https://img.shields.io/badge/Synergy-Antevemus.AlinqCollection-purple)](https://github.com/antevemus-it/Antevemus.AlinqCollection)

> **Enterprise Specification Pattern Framework for PHP 8.2+** (PHP 8.4 only for the optional ALinq integration)  
> Independent, unofficial PHP reimplementation and evolution of the acclaimed Java [Domian](https://domian.sourceforge.net/index.html) framework, grounded in the seminal paper [Specifications](http://www.martinfowler.com/apsupp/spec.pdf) by Eric Evans and Martin Fowler. Enhanced with natural-language fluent chaining, zero-exception Notification Pattern with rich diagnostic telemetry, relational database Dynamic Rule Engine, multi-DBMS SQL Query Visitor (15 drivers, 10 SQL dialects), Adianti Framework TCriteria Builder, Directed Acyclic Graph (DAG) partitioned repositories, hybrid persistence, and high-concurrency IPC primitives.

---

## 🏛️ Origins & Theoretical Foundation

**Antevemus ASpecification** was engineered on solid software craftsmanship principles and Domain-Driven Design (DDD):

1. **The Seminal Paper**:
   Based on the classic paper [The Specifications Pattern (PDF)](http://www.martinfowler.com/apsupp/spec.pdf) by **Eric Evans and Martin Fowler (2002)**, which formalized encapsulating business predicates and domain constraints into first-class composable objects for:
   - **Object Validation**: Verify whether candidate entities satisfy domain constraints.
   - **Selection & Filtering**: Query entities from repositories without leaking SQL or persistence concerns into the domain layer.
   - **Constraint Satisfaction**: Specify preconditions required to instantiate or transition entities between domain lifecycle states.

2. **The Upstream Origin: Domian (Java)**:
   This library is an independent, unofficial PHP port and modern evolution of the [Domian (Domain-Driven Design for Java)](https://domian.sourceforge.net/index.html) framework, originally authored by **Eirik Torske** and **Bjørn Nordlund** under the **Apache License, Version 2.0**. It is not affiliated with, maintained by or endorsed by the Domian authors: the design, public API and semantics follow Domian's public sources, and the PHP code was written by Antevemus. Domian pioneered unifying Boolean algebra, Venn diagram set theory, collection-scoped specifications, Directed Acyclic Graph (DAG) partitioned repositories, and concurrent synchronization.

---

## 🌟 Core Features

- 🎯 **Fluent Chaining & Natural Domain DSL**: Compose highly readable domain rules as natural prose sentences (`Spec::specify(Customer::class)->where('gender', is('FEMALE'))->and('membershipDate', isBefore($oneYearAgo))`).
- 🛡️ **Zero-Exception Notification Pattern**: Evaluate rules without throwing control-flow exceptions via `evaluate()`, producing a `SpecificationResult` containing aggregated `SpecificationFailure` instances, custom business error codes (`withCode()`), human-readable rationales (`because()`), and contextual metadata.
- ⚡ **Multi-DBMS SQL Query Visitor (Module 12)**: Direct compile-time translation of pure domain ASTs into secure parameterized `WHERE` clauses (`:p1`, `:p2`) across 15 database drivers mapped onto 10 SQL dialects (PostgreSQL, MySQL, SQL Server, Oracle, Firebird, SQLite, IBM Db2, IBM Informix, DuckDB and ANSI-92), with `paginate()` on every dialect.
- 🔗 **TCriteria Builder & Adianti Database Bridge (Module 13)**: Seamless compilation of domain specifications into native `Adianti\Database\TCriteria` and `TFilter` objects, with automatic De Morgan logic inversion for negations, strict parenthesis precedence, and chained pagination (`limit`, `offset`), sorting (`orderBy`, `direction`) and grouping (`groupBy`).
- 🧩 **Dynamic Rule Engine & Document Matrix (Module 11)**: Dynamic runtime rule compilation driven by relational database catalogs (`RuleDefinition`), operational severity triage (`BLOCK`, `WARN`, `LOG`), and document requirement Boolean algebra (`ALL`, `ANY`, `ONE_OF_SET`).
- ✂️ **Partial Satisfaction (`remainderUnsatisfiedBy`)**: Isolate at runtime precisely which sub-clauses failed for a given candidate entity.
- 📐 **Complete Boolean & Venn Set Algebra**: Rigorous logical composition (`AND`, `OR`, `NOT`, `NOR / Joint Denial`), Venn set subsumption calculus (`isGeneralizationOf`, `isSpecialCaseOf`, `isDisjointWith`) and structural tautology/contradiction detection (`isTautology()`, `isContradiction()`): it proves `A ∧ ¬A`, `A ∨ ¬A`, conjunctions of disjoint operands, `in([])` and the absorbing identities (`alwaysTrue()`/`alwaysFalse()`); it is conservative (`false` means "not proven", there is no SAT solving), and the rule engine reports degenerate catalog rules as `RuleCompilationWarning` (example 12).
- 🗄️ **Repository Architecture & DAG Partitioning**:
  - $O(1)$ early branch pruning in query search trees through specification disjointness, over a materialised topological index of the DAG (`findAll` 45 % to 77 % faster than the recursive walk, see `tests/Benchmark/REPORT-dag-index.md`).
  - TTL and auto-prune on volatile repositories (`withTtl()`, `prune()`, injectable clock), inherited across partitions.
  - In-memory (`InMemoryRepository`), null (`NullRepository`), and test-double (`FakePartitionRepository`) implementations.
  - Decoupled disk storage (`FilePerEntityRepository`, `SingleFileRepository`) with pluggable serialization (`JsonEntitySerializer`; `PhpNativeEntitySerializer` is deprecated since v1.5.0 and removed in v2.0.0, because it is the only `unserialize()` in the library: load an existing `.bin` with it and store with the JSON serializer to migrate).
  - Hybrid $L1$ (RAM) + $L2$ (Disk) tiering via `InMemoryAndFileRepository`.
- 🔒 **Concurrency & Atomic Locking**: `ISynchronizer` primitives wired into every repository (shared readers, exclusive writer, reentrant per execution context): the in-process `SemaphoreSynchronizer` (permit counter, Fiber-aware) and atomic file locks (`flock`) across processes for the file repositories. `SysVSemaphoreSynchronizer` (`ext-sysvsem`) shares the same reader/writer lock between processes through SysV IPC semaphores.
- ⏱️ **Nanosecond Benchmarking & Diagnostics**: High-resolution stopwatches (`StopWatch` via `hrtime`) and hierarchical diagnostic utilities (`InstrumentationUtils`).

---

## 🎯 What Sets Us Apart

While most PHP specification libraries stop at primitive Boolean checks (`isSatisfiedBy: bool`) and the original Java Domian framework relied heavily on runtime reflection and memory-only collections, **Antevemus ASpecification** was designed for the rigorous demands of enterprise microservices, high-throughput APIs, and mission-critical legacy modernization:

| Capability / Feature | Domian (Original Java) | Typical PHP Specification Libs | **Antevemus ASpecification** |
| :--- | :---: | :---: | :---: |
| **Notification Pattern (Zero Exceptions)** | ❌ Boolean only | ❌ Boolean only or control-flow exceptions | ✅ `evaluate()`, aggregated `SpecificationFailure`, error codes & rationales |
| **Operational Severity Triage** | ❌ None | ❌ None | ✅ `BLOCK` (fatal), `WARN` (operational alert), `LOG` (audit trail) |
| **Multi-DBMS SQL Query Visitor** | ❌ None | ❌ Rare / single engine only | ✅ **15 drivers, 10 dialects** (PostgreSQL, MySQL, SQL Server, Oracle, Firebird, SQLite, Db2, Informix, DuckDB, ANSI) |
| **Adianti Framework Database Bridge** | ❌ Not applicable | ❌ None | ✅ Native `TCriteria` & `TFilter`, automatic De Morgan inversion, strict precedence |
| **ALinq Synergy & Functional LINQ** | ❌ None | ❌ None | ✅ Direct AST compiler for short-circuit LINQ predicates & `ALinqBridge` |
| **Deep Nested Property Resolution** | ⚠️ Strict reflection | ⚠️ Public getters only | ✅ **Dot-notation** (`PropertyAccessor`) for deep objects and associative arrays |
| **Database-Driven Dynamic Rule Engine** | ❌ None | ❌ None | ✅ Dynamic compilation from relational catalog tables (`RuleDefinition`) |
| **Document Matrix Requirement Algebra** | ❌ None | ❌ None | ✅ `ALL`, `ANY`, `ONE_OF_SET` aggregation modes with rich verdict reports |
| **Fluent Chaining & Natural Domain DSL** | ⚠️ Basic | ⚠️ Partial | ✅ Natural English syntax (`Spec::specify()->where()->and()->or()`) + helpers (`prop()`, `is()`, `not()`) |
| **Venn Set Subsumption & Remainder** | ✅ Complete | ❌ Absent in almost all libs | ✅ `isGeneralizationOf`, `isSpecialCaseOf`, `isDisjointWith`, `remainderUnsatisfiedBy` |
| **DAG Partitioning $O(1)$** | ✅ Supported | ❌ Extremely rare | ✅ Partitioned repositories with early disjoint branch pruning |
| **Concurrency & Locking** | ⚠️ Java Threads / Locks | ❌ Unsupported | ✅ Synchronized repositories (reentrant `SemaphoreSynchronizer`, Fiber-aware) `SysVSemaphoreSynchronizer` on SysV IPC between processes, and atomic `flock` file locks |
| **Modern Architecture** | ⚠️ Java 6/7 Generics | ⚠️ PHP 7.x legacy | ✅ **PHP 8.2+** native (Enums, First-class callables, Readonly, 8 segregated traits) |

---

## 📋 Requirements

- **PHP**: `^8.2` (tested on PHP 8.2 and 8.4). PHP 8.4 is required only by the optional ALinq integration (`Antevemus.AlinqCollection`, Module 14), which the library detects at runtime.
- **PHP Extensions**:
  - `ext-json` (for JSON entity serialization; required)
  - `ext-mbstring` (required since v1.5.0: Unicode-aware case-insensitive string specifications, `mb_strtolower`)
  - `ext-sysvsem` *(optional: `SysVSemaphoreSynchronizer`, the reader/writer lock shared between processes through SysV IPC semaphores, Linux/Unix)*
- **Optional package**: `antevemus/alinq-collection` `^1.3` for `ALinqBridge` (typed `IALinqCollection`/`IALinqLazyCollection` returns), `ALinqSpecificationVisitor` and O(1) lazy streaming over `IRepository::iterate()` (requires PHP 8.4).

---

## 🚀 Installation

Install via Composer:

```bash
composer require antevemus/aspecification
```

---

## 💡 Practical Examples

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

---

### 2. Zero-Exception Notification Pattern & Diagnostic Telemetry

In enterprise workflows, REST APIs, and UI forms, use `evaluate()` to accumulate all validation failures without interrupting execution:

```php
$adultSpec = Spec::property('age', Spec::greaterThanOrEqualTo(18))
    ->because('Customer must be of legal age.')
    ->withCode('CLI_001');

$activeSpec = Spec::property('status', Spec::equalTo('ACTIVE'))
    ->because('Only active accounts can receive a credit line.')
    ->withCode('CLI_002');

$creditRule = $adultSpec->and($activeSpec);

$result = $creditRule->evaluate($customer);

if (!$result->isSatisfied) {
    echo "Validation failed (" . count($result->failures) . " errors found):\n";
    foreach ($result->failures as $failure) {
        echo sprintf(" - [%s] %s: %s\n", $failure->code, $failure->property, $failure->message);
    }
}
```

---

### 3. Partial Satisfaction (`remainderUnsatisfiedBy`)

Pinpoint at runtime precisely which sub-rules failed for a given candidate:

```php
$onboardingSpec = Spec::specify(User::class)
    ->where('emailVerified', Spec::isTrue())
    ->and('termsAccepted', Spec::isTrue())
    ->and('profileComplete', Spec::isTrue());

$remainder = $onboardingSpec->remainderUnsatisfiedBy($user);

if ($remainder !== null) {
    // $remainder contains ONLY the specific clauses not yet satisfied!
    echo "Pending onboarding requirements: " . (string) $remainder;
}
```

---

### 4. Directed Acyclic Graph (DAG) Partitioned Repositories

Partition collections into logical branches indexed by specification predicates:

```php
use Antevemus\ASpecification\Repositories\InMemoryRepository;

$rootRepo = new InMemoryRepository();

// Create an exclusive partition for VIP customers
$vipSpec = Spec::property('vip', Spec::equalTo(true));
$vipPartition = $rootRepo->makePartition($vipSpec);

// Insert into the partitioned repository
// ($vipCustomer implements IEntity, e.g. by extending Entities\AbstractUUIDEntity)
$vipPartition->put($vipCustomer);

// Queries benefit from O(1) early branch pruning of disjoint predicates
$results = $vipPartition->findAll(Spec::property('balance', Spec::greaterThan(1000)));
```

---

### 5. Decoupled Persistence & Hybrid L1/L2 Cache Tiering

```php
use Antevemus\ASpecification\Repositories\File\InMemoryAndFileRepository;
use Antevemus\ASpecification\Repositories\Serialization\JsonEntitySerializer;

// High-speed RAM read performance (L1) with durable disk persistence (L2)
$repo = InMemoryAndFileRepository::create(
    storagePath: '/var/data/customers.json',
    serializer: new JsonEntitySerializer(Customer::class)
);

$repo->put($newCustomer); // Stored in RAM and synchronized to disk atomically
```

---

### 6. High-Precision Benchmarking & Telemetry

```php
use Antevemus\ASpecification\Helpers\StopWatch;
use Antevemus\ASpecification\Helpers\InstrumentationUtils;

$watch = StopWatch::createStarted();

$entities = $repo->findAll($complexSpec);

$watch->stop();
echo "Query executed in: " . $watch->formatElapsed() . "\n";
echo InstrumentationUtils::formatMemoryUsage() . "\n";
```

---

### 7. Dynamic Rule Engine & Relational Database Catalogs (Module 11)

Compile dynamic rules and mandatory document matrices directly from relational database tables (e.g. PostgreSQL, MySQL):

> **Vocabulary note.** The rule-engine data contract is Portuguese by design (`escopo`, `cenario`, `acao`, `fundamento_legal`, `getValorInteiro()`, `getMensagemViolacao()`, actions `bloquear`/`alertar`/`apenas_log`): it mirrors the relational catalog schema it hydrates from, a Brazilian legal domain. Orchestration and verdict APIs (`validate()`, `hasBlockingErrors()`, `canProceed()`) are English. English aliases for the data contract are not planned.

```php
use Antevemus\ASpecification\Spec;

// 1. Configure the Registry with pluggable rule handlers
$registry = Spec::ruleRegistry();
$registry->registerClosure('max_occurrences_per_contract', function ($rule) {
    return Spec::specify(Contract::class)
        ->must(fn($c) => $c->getOccurrencesCount() <= $rule->getValorInteiro(), $rule->getCodigo(), $rule->getMensagemViolacao());
});

// 2. Instantiate the Engine connected to the relational catalog repository
$engine = Spec::engine($myCatalogRepository, $registry);

// 3. Validate target entity for a specific scope and scenario
$verdict = $engine->validate(
    target: $contract,
    escopo: 'rental_contract',
    cenario: 'activation'
);

if ($verdict->hasBlockingErrors()) {
    return response()->json([
        'status' => 403,
        'errors' => $verdict->getBlockingFailures(),
        'legal_bases' => $verdict->getLegalBases(),
    ], 403);
}

if ($verdict->hasWarnings()) {
    NotificationService::dispatch($verdict->getWarningFailures());
}
```

---

### 8. SQL Query Visitor & Multi-DBMS (`WHERE` Parameterization)

Translate pure domain specification trees directly into parameterized, injection-proof `WHERE` clauses with automatic identifier quoting and native dialect functions:

```php
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Sql\SqlDialect;

// 1. Build pure domain specification
$spec = Spec::property('active', Spec::equalTo(true))
    ->and(
        Spec::property('salary', Spec::greaterThan(5000))
            ->or(Spec::property('city', Spec::wildcard('New*')))
    );

// 2. Compile to PostgreSQL with domain-to-column mapping
$whereClause = Spec::toSql(
    specification: $spec,
    dialect: SqlDialect::POSTGRESQL,
    fieldMapper: [
        'active' => 'is_active',
        'salary' => 'val_salary',
        'city'   => 'txt_city'
    ]
);

echo $whereClause->getSql();
// '("is_active" = TRUE AND ("val_salary" > :p1 OR "txt_city" LIKE :p2))'
// (booleans are rendered by the dialect; the remaining values are bound)

print_r($whereClause->getBindings());
// [':p1' => 5000, ':p2' => 'New%']

// 3. First-class support for 15 database drivers across 10 SQL dialects (SQL Server, Oracle, Firebird, MySQL, SQLite, Db2, Informix, DuckDB, etc.)
$whereSqlServer = Spec::toSql($spec, SqlDialect::SQLSRV);
// '([active] = 1 AND ([salary] > :p1 OR [city] LIKE :p2))'

$whereMySql = Spec::toSql($spec, SqlDialect::MYSQL);
// '(`active` = 1 AND (`salary` > :p1 OR BINARY `city` LIKE :p2))'
```

---

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

### 10. ALinq Synergy & Fluent LINQ Collections (Module 14)

Native high-performance interoperability with the **[`Antevemus.AlinqCollection`](https://github.com/antevemus-it/Antevemus.AlinqCollection)** framework:

#### 10.1 Deep Property Resolution with Dot-Notation (`PropertyAccessor`)
Evaluate nested properties on objects (with getters or Boolean methods) and multidimensional associative arrays without reflection bottlenecks:

```php
use Antevemus\ASpecification\Spec;

$vipInNY = Spec::property('address.city', Spec::equalTo('New York'))
    ->and(Spec::property('profile.score', Spec::greaterThan(90)));

$user = (object)[
    'address' => (object)['city' => 'New York'],
    'profile' => ['score' => 95]
];

$result = $vipInNY->evaluate($user);
// $result->isSatisfied === true
```

#### 10.2 AST Compiler to LINQ Predicates (`ALinqSpecificationVisitor`)
Compile specification trees into a zero-overhead native `Closure(mixed $candidate): bool` using short-circuit logical operators (`&&`, `||`, `!`):

```php
use Antevemus\ASpecification\Linq\ALinqSpecificationVisitor;

$predicate = ALinqSpecificationVisitor::createPredicate($vipInNY);

// Use directly in ALinq collections or native array_filter
$approved = $myAlinqCollection->where($predicate);
```

#### 10.3 Fluent In-Memory Repositories with `ALinqBridge`
Connect repositories and iterables directly to LINQ pipelines (sorting, pagination, grouping, and aggregations):

```php
use Antevemus\ASpecification\Linq\ALinqBridge;

// 1. Fluent filtering on any iterable
$techItems = ALinqBridge::filter($products, $specTech)
    ->orderByDescending(fn($p) => $p->price)
    ->take(10)
    ->toArray();

// 2. Strongly typed query on InMemoryRepository returning an ALinqCollection
$topCustomers = $inMemoryRepo->findAsLinqCollection($specApproved)
    ->orderBy(fn($c) => $c->getPoints())
    ->take(5)
    ->toArray();

// 3. Aggregate metrics directly
$avgPoints = $inMemoryRepo->asLinqCollection()->average(fn($c) => $c->getPoints());
```

#### 10.4 Generator-Based Streaming & Lazy Evaluation (`ALinqLazyCollection`) with O(1) Memory
Process massive, infinite, or file-backed data streams with constant memory footprint by pairing specifications with lazy generators:

```php
use Antevemus\ASpecification\Linq\ALinqBridge;
use Antevemus\ASpecification\Spec;

// 1. Stream massive dataset through specification filter with constant O(1) RAM
$largeLogStream = static function(): \Generator {
    $handle = fopen('system_events.log', 'rb');
    while (($line = fgets($handle)) !== false) {
        yield json_decode($line, true);
    }
    fclose($handle);
};

// Elements are pulled and evaluated lazily on demand
$criticalEvents = Spec::filterLazy($largeLogStream, $specHighSeverity)
    ->take(100)
    ->toArray();

// 2. Stream directly from InMemoryRepository with findAsLazyCollection()
$activeCustomers = $inMemoryRepo->findAsLazyCollection($specActive)
    ->select(fn($c) => $c->getEmail())
    ->take(50)
    ->toArray();
```

---

### 11. Declarative Attributes Engine (`#[AssertSpec]`, `#[ValidateRule]`) (Module 15)

In PHP 8.2+, annotate Data Transfer Objects (DTOs), Form Requests, Value Objects, and Domain Entities directly with specifications:

```php
use Antevemus\ASpecification\Attributes\AssertSpec;
use Antevemus\ASpecification\Attributes\ValidateRule;
use Antevemus\ASpecification\Spec;

#[AssertSpec(CustomerMustBeActiveSpec::class, message: 'Customer account is suspended', code: 'CUST_SUSPENDED')]
class RegisterCustomerDto
{
    #[ValidateRule('not_blank', message: 'Name cannot be empty')]
    public string $name;

    #[ValidateRule('>=', value: 18, message: 'Customer must be at least 18 years old', code: 'UNDERAGE')]
    public int $age;

    #[ValidateRule('email', message: 'Invalid corporate email format')]
    public string $email;

    public function __construct(string $name, int $age, string $email)
    {
        $this->name = $name;
        $this->age = $age;
        $this->email = $email;
    }
}

$dto = new RegisterCustomerDto('Alice Smith', 16, 'alice@example.com');

// 1. Non-throwing Notification Pattern validation
$result = Spec::validateAttributes($dto);
if (!$result->isSatisfied) {
    foreach ($result->failures as $failure) {
        echo "Violation [{$failure->code}]: {$failure->message} (at {$failure->ruleName})\n";
    }
}

// 2. Strict throwing assertion
try {
    Spec::assertAttributes($dto);
} catch (\Antevemus\ASpecification\Attributes\Exceptions\AttributeValidationException $e) {
    // Thrown automatically on validation failure with structured details
    $failures = $e->getResult()->failures;
}
```

### 12. Tautology & Contradiction Detection

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
$catalog->addRule(new RuleDefinition(codigo: 'R-12', nome: 'Active and inactive', tipoRegra: 'status_both', escopo: 'rental_contract'));
$registry = Spec::ruleRegistry()->registerClosure('status_both', fn() => $active->and($inactive));

$engine = Spec::engine($catalog, $registry)->withCatalogValidation();
$engine->compileSpecification('rental_contract');
foreach ($engine->getCompilationWarnings() as $warning) {
    echo $warning->ruleCode . ': ' . $warning->kind, PHP_EOL;   // "R-12: contradiction"
}
```

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

The SQL and TCriteria visitors refuse a method call as a non-translatable leaf (like `must()`); the ALinq visitor compiles it.

---

## 🏗️ Directory Structure

```
src/
├── Attributes/                # PHP 8 Declarative Attributes Engine (Module 15)
│   ├── AssertSpec.php        # Specification reference attribute
│   ├── ValidateRule.php      # Inline rule attribute
│   ├── AttributeValidator.php # High-performance reflection evaluator
│   └── Exceptions/           # AttributeValidationException
├── Contracts/                 # Segregated formal interfaces (ISP)
│   ├── Entities/             # IEntity, ITransientEntity
│   ├── Factory/              # ITypeSpecificationFactory, IComparison..., ILogical...
│   ├── Repositories/         # IRepository, IPartitionRepository, IPersistent...
│   ├── Concurrent/           # ISynchronizer
│   ├── Helpers/              # IStopWatch, IInstrumentationUtils
│   ├── Engine/               # IRuleDefinition, IDocumentRuleDefinition, IRuleCatalog...
│   └── Sql/                  # ISqlDialect, IFieldMapper, ISqlWhereClause
├── Entities/                  # Base entities, UUIDs, and random ID generators
├── Specifications/            # Concrete rule implementations
│   ├── Comparison/           # Equal, GreaterThan, LessThan, RelationalOperator
│   ├── Logical/              # AlwaysTrue, AlwaysFalse, JointDenial (NOR), DefaultValue
│   ├── String/               # Regex, Wildcard, DateString, EnumName
│   ├── Collection/           # CollectionSpecification, AllEntities, Unique
│   └── Reflection/           # MethodParameterizedSpecification (declarative method call; field access is where())
├── Repositories/              # Concrete repositories and DAG partitioning
│   ├── File/                 # SingleFileRepository, FilePerEntityRepository
│   └── Serialization/        # JsonEntitySerializer, PhpNativeEntitySerializer
├── Concurrent/                # SemaphoreSynchronizer, FileLockSynchronizer
├── Engine/                    # DynamicSpecificationEngine, RuleEngineVerdict, RuleCompilationWarning, PdoRuleCatalog, Builders
│   ├── Exceptions/           # RuleEngineException, MissingRuleHandlerException
│   ├── RuleAction.php        # Enum: BLOCK, WARN, LOG
│   └── DocumentRequirementMode.php # Enum: ALL, ANY, ONE_OF_SET
├── Sql/                       # SQL Query Visitor & Multi-DBMS Engine (Module 12)
│   ├── Dialects/             # Abstract, Ansi, PostgreSql, MySql, SqlServer, Oracle, Firebird, Sqlite
│   ├── Exceptions/           # SqlVisitorException, NonTranslatableSpecificationException...
│   ├── SqlDialect.php        # Enum of all drivers: sqlsrv, mssql, oracle, oci, mysql, etc.
│   ├── SqlQueryVisitor.php   # GoF Visitor compiling pure AST into parameterized SQL
│   ├── SqlWhereClause.php    # Safe WHERE clause with bound PDO parameters
│   └── FieldMapper.php       # Semantic property-to-column mapper
├── Criteria/                  # TCriteria Builder & Adianti Database Bridge (Module 13)
│   ├── Exceptions/           # CriteriaBuilderException, NonTranslatableCriteriaException
│   ├── CriteriaSpecificationVisitor.php # GoF Visitor compiling AST to TCriteria/TFilter
│   └── TCriteriaBuilder.php  # Fluent builder with pagination, sorting, and field mapping
├── Linq/                      # ALinq Synergy & Fluent Collections (Module 14)
│   ├── ALinqBridge.php       # Bridge between repositories and ALinq collections
│   └── ALinqSpecificationVisitor.php # AST compiler for short-circuit functional predicates
├── Factory/                   # Unified SpecificationFactory (~140 methods)
│   └── Traits/               # 8 Modular Traits (Type, Comparison, Logical, Special, String, Date, Collection, Wrapper)
├── Results/                   # SpecificationResult, SpecificationFailure
├── Helpers/                   # PropertyAccessor (dot-notation), StopWatch, InstrumentationUtils
├── DSL/                       # Global helper functions for fluent domain syntax
└── Spec.php                   # Unified static facade
```

---

## 🧪 Code Quality & Automated Tests

The library features comprehensive unit and integration test coverage with zero regressions:

```bash
php tests/run_all.php
```

Report below as printed by the runner, with its Portuguese labels translated; the duration depends on the machine:

```text
====================================================================
 ANTEVEMUS ASPECIFICATION - MASTER TEST RUNNER & REGRESSION WATCH
====================================================================

• [SUITE] Module 1: Specifications & Boolean Algebra... ✅ PASS (284 assertions)
• [SUITE] Module 2: Entities & Domain Identifiers... ✅ PASS (59 assertions)
• [SUITE] Module 3: In-Memory Repositories & Base... ✅ PASS (90 assertions)
• [SUITE] Module 4: DAG Partitioning Architecture... ✅ PASS (94 assertions)
• [SUITE] Module 5: File Persistence & Hybrid Decorator... ✅ PASS (115 assertions)
• [SUITE] Module 6: Concurrency Utilities & RW-Lock... ✅ PASS (97 assertions)
• [SUITE] Module 7: Predicates, Factories, Helpers & Visitor... ✅ PASS (61 assertions)
• [SUITE] Module 8: Notification Pattern & SpecificationResult... ✅ PASS (133 assertions)
• [SUITE] Module 9: Facade Spec, Fluent Chaining & DSL... ✅ PASS (203 assertions)
• [SUITE] Module 10: Java Parity, Telemetry & Remainder... ✅ PASS (65 assertions)
• [SUITE] Module 11: Dynamic Rule Engine & Document Matrix... ✅ PASS (252 assertions)
• [SUITE] Module 12: SQL Query Visitor & Multi-DBMS Dialects... ✅ PASS (459 assertions)
• [SUITE] Module 13: TCriteria Builder & Adianti Database Bridge... ✅ PASS (186 assertions)
• [SUITE] Module 14: ALinq Synergy & Fluent LINQ Collections... ✅ PASS (256 assertions)
• [SUITE] Module 15: Declarative PHP 8.4 Attributes (#[AssertSpec])... ✅ PASS (213 assertions)
• [SUITE] Module 16: Relational Rule Catalog (PdoRuleCatalog)... ✅ PASS (303 assertions)

====================================================================
 FINAL RESULT: 16/16 SUITES PASSED (100% PASS)
 TOTAL ASSERTIONS: 2870 | DURATION: ~2s | REGRESSIONS: 0
====================================================================
```

- **Domian Parity suite:** `tests/Parity/` transcribes the Domian (Java) tests for the specification algebra, the partition DAG, the synchronizers, entities and utilities (213 PHPUnit tests, 2 043 assertions; `vendor/bin/phpunit` runs 231 tests and 4 936 assertions in total). The audit behind it is in `docs/PARIDADE-DOMIAN-2026-10-09.md`.
- **Public API Documentation:** 1,200+ methods documented via structured corporate PHPDoc blocks.
- **Java (Domian) Traceability:** conceptual and architectural parity with the upstream framework (public API and class names follow Domian; see the provenance table in [NOTICE.md](NOTICE.md)).
- **Modular Factory Architecture:** `SpecificationFactory` decomposed into 8 domain-specialized traits.
- **ALinq Synergy:** Native integration with [`Antevemus.AlinqCollection`](https://github.com/antevemus-it/Antevemus.AlinqCollection): the `ALinqSpecificationVisitor` compiler, `PropertyAccessor` dot-notation resolution and the `InMemoryRepository` bridge methods.
- **Multi-DBMS Compatibility:** 15 drivers (10 SQL dialects) certified with proper identifier quoting and bound prepared statements.
- **Adianti Database Bridge:** Full translation to `TCriteria`/`TFilter` with De Morgan logic inversion and strict precedence.

---

## 🗺️ Roadmap & Next Steps

**Antevemus ASpecification** is actively maintained and continuously evolving. For the full strategic roadmap and timeline:

👉 **[View the complete ROADMAP.md](ROADMAP.md)**

Key highlights & upcoming roadmap:
1. **English DocBlock Internationalization** (Shipped in v1.1.0)
2. **PHP 8.4 Declarative Attributes** (`#[AssertSpec]`, `#[ValidateRule]`) (Shipped in v1.1.0)
3. **ALinq Lazy Streaming Pipeline & O(1) RAM Evaluation** (Shipped in v1.1.0)
4. **Review-Driven Hardening: Error State, Strict Typing, Rule Binding & Document Matrix** (Shipped in v1.2.0)
5. **README Promises I: Every Documented Example Runs** (Shipped in v1.3.0)
5a. **Domian Parity: Subsumption Algebra, Partition DAG, Synchronized Repositories, Entities & Utilities** (Shipped in v1.4.4)
6. **README Promises II: Inter-Process, Unicode, Lazy Sources, Relational Catalog & `IN`** (Shipped in v1.5.0)
7. **Announced-but-Unshipped Backlog I: ULID / UUID v7 Identities & Failure Severity** (Shipped in v1.5.0)
8. **PHP-Native Serializer Retirement, Step 1: Deprecation** (Shipped in v1.5.0)
9. **`MethodParameterizedSpecification`: Declarative Method Calls** (Shipped in v1.6.0)
10. **Tautology & Contradiction Detection** (Shipped in v1.6.0)
11. **Announced-but-Unshipped Backlog II: TTL, Partition DAG Index & Three SQL Dialects** (Shipped in v1.6.0)
12. **Specification `simplify()`** (v1.6.x)
13. **PHP-Native Serializer Retirement, Step 2: Removal & JSON-Only Envelope** (v2.0.0)
14. **Shared Property Accessor with ALinq** (v2.0.0)
15. **PHP Fibers & Non-Blocking Async Specification Runner** (v2.0.0)
16. **Distributed Specification Cache** (PSR-6 / PSR-16 / Redis)
17. **GraphQL AST & OpenAPI 3.1 Query Compilers**
18. **Doctrine ORM & Laravel Eloquent Query Visitors**
19. **Reactive Domain Event Sourcing Triggers**
20. **AI-Assisted Specification Synthesizer**
21. **PDO-Backed Repository** (the Domian `HibernateRepository`)

---

## ⚖️ Upstream Attribution, Licensing & Acknowledgments

**Antevemus ASpecification** expresses its deepest respect and gratitude to the pioneer authors who established the theoretical and practical foundations of this pattern:

- **Eirik Torske** (Project Administrator & Developer) and **Bjørn Nordlund** (Contributor), creators of the **[Domian (Java)](https://domian.sourceforge.net/)** framework, whose pioneering work in Boolean algebra, Venn set calculus (`isGeneralizationOf`, `isSpecialCaseOf`, `isDisjointWith`), and partitioned repository architectures served as the inspiring foundation for this PHP 8.2+ implementation.
- **Eric Evans** and **Martin Fowler**, for authoring the seminal paper *[Specifications (2002)](http://www.martinfowler.com/apsupp/spec.pdf)* and foundational works on Domain-Driven Design (DDD).

### Apache License 2.0 Compliance
The upstream **Domian** framework is distributed under the terms of the **[Apache License, Version 2.0](http://www.apache.org/licenses/LICENSE-2.0)** (Copyright 2006-2010 the original author or authors, as stated in the Domian sources; developers Eirik Torske and Bjørn Nordlund). In full compliance with Section 4 of said license:
- Original authorship and copyright notices are fully preserved.
- The [NOTICE.md](NOTICE.md) and [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md) files contain the formal provenance and full third-party license texts.
- This independent PHP implementation is made available to the global open-source community under the **MIT** license. It is not a clean-room rewrite: it was written with Domian's public sources at hand, and the files that follow a specific Domian class or reuse a sentence of its documentation say so in their DocBlock, keeping the upstream attribution as Section 4 of the Apache License requires.

---

## 📚 References & Bibliography

- **[Domian Specification Framework](https://domian.sourceforge.net/index.html)**: Original Java framework authored by Eirik Torske.
- **[The Specifications Pattern - Eric Evans & Martin Fowler](http://www.martinfowler.com/apsupp/spec.pdf)**: Seminal paper introducing the pattern.
- **[Domain-Driven Design: Tackling Complexity in the Heart of Software](https://www.domainlanguage.com/ddd/)**: Reference work by Eric Evans on rich domain modeling.
- **[Design Patterns: Elements of Reusable Object-Oriented Software](https://en.wikipedia.org/wiki/Design_Patterns)**: GoF reference patterns (Composite, Decorator, Visitor, Factory).

---

## 📄 License

Distributed under the **MIT** license. See [LICENSE](LICENSE), [NOTICE.md](NOTICE.md), and [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md) for more information.

---

## 👨‍💻 Authors & Corporate Maintenance

**Heliton Junior (CTO)**  
- Email: [contato@antevemus.com.br](mailto:contato@antevemus.com.br)  
- Website: [antevemus.com.br](https://antevemus.com.br)  
- Organization: **Antevemus Soluções Inovadoras em TI Ltda.**
