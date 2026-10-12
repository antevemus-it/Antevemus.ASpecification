# Antevemus ASpecification

<p align="left">
  <strong>🇺🇸 English</strong> &nbsp;|&nbsp; <a href="README.pt-BR.md">🇧🇷 Português (Brasil)</a>
</p>

[![CI](https://github.com/antevemus-it/Antevemus.ASpecification/actions/workflows/tests.yml/badge.svg)](https://github.com/antevemus-it/Antevemus.ASpecification/actions/workflows/tests.yml)
[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php&logoColor=white)](https://php.net)
[![Latest Version](https://img.shields.io/badge/Release-v2.0.0-blue.svg)](https://github.com/antevemus-it/Antevemus.ASpecification/releases/tag/v2.0.0)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Tests](https://img.shields.io/badge/Tests-26%2F26%20Suites%20Pass%20(11245%20Assertions)%20%2B%20Domian%20Parity%20219-success)](tests/run_all.php)
[![Architecture](https://img.shields.io/badge/Architecture-DDD%20%7C%20Evans%20%26%20Fowler%20Specification-orange)](http://www.martinfowler.com/apsupp/spec.pdf)
[![Upstream: Domian](https://img.shields.io/badge/Origin-Domian%20(Apache%202.0)-brightgreen)](https://domian.sourceforge.net/index.html)
[![Synergy: ALinq](https://img.shields.io/badge/Synergy-Antevemus.AlinqCollection-purple)](https://github.com/antevemus-it/Antevemus.AlinqCollection)

> **Enterprise Specification Pattern Framework for PHP 8.2+** (PHP 8.4 only for the optional ALinq integration)  
> Independent, unofficial PHP reimplementation and evolution of the acclaimed Java [Domian](https://domian.sourceforge.net/index.html) framework, grounded in the seminal paper [Specifications](http://www.martinfowler.com/apsupp/spec.pdf) by Eric Evans and Martin Fowler. Enhanced with natural-language fluent chaining, zero-exception Notification Pattern with rich diagnostic telemetry, relational database Dynamic Rule Engine, multi-DBMS SQL Query Visitor (15 drivers, 10 SQL dialects), Adianti Framework TCriteria Builder, Directed Acyclic Graph (DAG) partitioned repositories, hybrid persistence, high-concurrency IPC primitives, a specification-driven state machine, use cases with a result shared between both, concurrent resolution of the external data I/O-bound rules depend on, a repository over PDO whose queries are specifications compiled to SQL (with lifecycle columns, batch and set-based operations and relationships loaded in batch), and domain events emitted when an entity starts or stops satisfying a specification, on a write or by the mere passage of time.

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
- 📐 **Complete Boolean & Venn Set Algebra**: Rigorous logical composition (`AND`, `OR`, `NOT`, `NOR / Joint Denial`), Venn set subsumption calculus (`isGeneralizationOf`, `isSpecialCaseOf`, `isDisjointWith`) and structural tautology/contradiction detection (`isTautology()`, `isContradiction()`): it proves `A ∧ ¬A`, `A ∨ ¬A`, conjunctions of disjoint operands, `in([])` and the absorbing identities (`alwaysTrue()`/`alwaysFalse()`); it is conservative (`false` means "not proven", there is no SAT solving), and the rule engine reports degenerate catalog rules as `RuleCompilationWarning`; `simplify()` returns an equivalent, smaller tree (identity, double negation, idempotence, subsumption, proven contradictions and tautologies) without touching annotated nodes, with an opt-in in the rule engine (example 12).
- 🗄️ **Repository Architecture & DAG Partitioning**:
  - $O(1)$ early branch pruning in query search trees through specification disjointness, over a materialised topological index of the DAG (`findAll` 45 % to 77 % faster than the recursive walk, see `tests/Benchmark/REPORT-dag-index.md`).
  - TTL and auto-prune on volatile repositories (`withTtl()`, `prune()`, injectable clock), inherited across partitions.
  - In-memory (`InMemoryRepository`), null (`NullRepository`), and test-double (`FakePartitionRepository`) implementations, and a database one, `PdoRepository` (Module 22).
  - Decoupled disk storage (`FilePerEntityRepository`, `SingleFileRepository`) with pluggable entity serialization (`JsonEntitySerializer`) inside a JSON document owned by the repository, with the persistence metadata of each entity (reads, writes, first and last of each) written next to it and hydrated on load. Zero `unserialize()` since v2.0.0: the PHP-native serializer was removed, and a `.bin` file of 1.x is refused with the conversion recipe of [UPGRADE-2.0.md](UPGRADE-2.0.md), to run on 1.14.x.
  - Hybrid $L1$ (RAM) + $L2$ (Disk) tiering via `InMemoryAndFileRepository`.
- 🔒 **Concurrency & Atomic Locking**: `ISynchronizer` primitives wired into every repository (shared readers, exclusive writer, reentrant per execution context): the in-process `SemaphoreSynchronizer` (permit counter, Fiber-aware) and atomic file locks (`flock`) across processes for the file repositories. `SysVSemaphoreSynchronizer` (`ext-sysvsem`) shares the same reader/writer lock between processes through SysV IPC semaphores.
- ⏱️ **Nanosecond Benchmarking & Diagnostics**: High-resolution stopwatches (`StopWatch` via `hrtime`) and hierarchical diagnostic utilities (`InstrumentationUtils`).
- 🚦 **Spec-Driven State Machine (Modules 17 and 18)**: every guard is an `ISpecification`, so a denied transition says why (code, message, property, severity); parallel regions, region-level transitions, named actions (`exit` → transition → `entry`), persisted deadlines (a fixed duration, or a duration or an absolute instant read from the context, pausable with the balance kept while a condition holds); the definition is data (builder or JSON, versioned, fingerprinted, validated at load time); a pure `transition()`/`can()`/`nextEvents()` core and a single write port, `MachineRunner` (compare-and-set, idempotency key, append-only history, transactional outbox), over InMemory or PDO stores with a reference ANSI DDL (example 14).
- 🧭 **Use Cases (Module 19)**: `IUseCase` + `UseCaseRunner` (middlewares, `TransactionMiddleware`, request validation where only `ERROR` blocks), `UseCaseResult` with a closed domain status and HTTP only at the edge (`toHttpStatus()`), no exception or stack trace in the result, and `StateMachineEventUseCase` exposing a machine event as a use case through the shared `IOutcome` (example 15).
- ⚡ **Concurrent Data Resolution (Module 21)**: a rule that depends on external data (a bureau score, a fraud check, a balance) declares it with `Spec::external()` instead of calling the service; a `DataResolver` collects the declarations of the whole tree, fetches them concurrently through `curl_multi` (no dependency, works under PHP-FPM) and then evaluates the tree synchronously. A cost policy (`concurrent()`, `tiered()` with three-valued logic, `sequential()`) skips paid sources that can no longer change the verdict; requests are deduplicated; a failed fetch is an evaluation error of its leaf; parameters cannot change the host or path of a call (example 16).
- 🗄️ **Repository Over PDO (Module 22)**: `PdoRepository` implements `IRepository` over a table: a query is a specification compiled to parameterized SQL by the SQL visitor (any dialect), a write is a prepared statement in a transaction, `iterate()` streams the cursor in constant memory and `count()` builds no entity. Mapping by attributes (`#[Table]`, `#[Column]`, `#[Id]`, `#[Version]`) or by a builder; identifiers `auto`, `ulid`, `uuid7`, `assigned` and `max`; optimistic locking; a non-translatable specification is refused instead of filtering the table in memory (`withInMemoryFallback()` is the explicit opt-in); only properties a specification may read by name can be queried. The transaction port (`Persistence\ITransactionManager`) is shared with the state machine stores (example 17).
- 🧱 **PDO Repository Follow-ups (Modules 25 and 26)**: lifecycle columns (`#[CreatedAt]`, `#[UpdatedAt]`, `#[SoftDelete]` with `withTrashed()`/`onlyTrashed()`/`forceRemove()`), hooks on the repository (`beforePut`, `afterPut`, `beforeRemove`, `afterRemove`), a SQL log without parameter values by default (`IQueryLogger`), booleans stored as 1/0 and default time zones per mapping and per repository; `insertAll()` with multi-row `INSERT`s and `putAll()` with one existence `SELECT` per batch; `updateWhere()`, `select()` projections, `aggregate()->groupBy()->sum()/avg()/min()/max()/count()` and `Spec::inQuery()` subqueries; `#[ManyToOne]`/`#[OneToMany]` relationships loaded explicitly in batch with `with()` (one query per relation, never N+1), compositions saved and removed with their parent, and orphans removed only from collections that were loaded; `#[ManyToMany]` associations through a join table (only the links are written), `markLoaded()` for aggregates built by hand, `iterate()` with relations in pages that work on MySQL unbuffered queries, and `CHAR(1)` `'Y'`/`'N'` booleans read strictly (examples 17.1 to 17.3).
- 📡 **Domain Events From Specifications (Module 23)**: `SpecificationTrigger` binds a specification to an enter and an exit event; `TriggeringRepository` decorates any repository and publishes the crossings of every write to an outbox (in memory or PDO, reference DDL `resources/sql/domain_events.ansi.sql`) written in the same transaction, delivered after the commit by `DomainEventDispatcher` with a deterministic idempotency key and a payload without personal data; `MachineEventBridge` sends an event to the state machine (example 18).
- ⏰ **Domain Events From the Passage of Time (Module 24)**: `SpecificationTrigger::timed()` builds its specification from "now"; `TriggerSweeper`, run by a cron, finds the entities that crossed since their last recorded state (`ITriggerStateStore`, in memory or PDO, table `domain_event_trigger_state`) and publishes through the same outbox, one transaction and one compare-and-set per crossing; `baseline()` introduces a trigger over existing data without a flood of events; `TriggeringRepository` keeps the same state, so a write and a sweep never publish one crossing twice (example 18.1).

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
| **Deep Nested Property Resolution** | ⚠️ Strict reflection | ⚠️ Public getters only | ✅ **Dot-notation** (`PropertyAccess`, shared with ALinq) for deep objects and associative arrays |
| **Database-Driven Dynamic Rule Engine** | ❌ None | ❌ None | ✅ Dynamic compilation from relational catalog tables (`RuleDefinition`) |
| **Document Matrix Requirement Algebra** | ❌ None | ❌ None | ✅ `ALL`, `ANY`, `ONE_OF_SET` aggregation modes with rich verdict reports |
| **Fluent Chaining & Natural Domain DSL** | ⚠️ Basic | ⚠️ Partial | ✅ Natural English syntax (`Spec::specify()->where()->and()->or()`) + helpers (`prop()`, `is()`, `not()`) |
| **Venn Set Subsumption & Remainder** | ✅ Complete | ❌ Absent in almost all libs | ✅ `isGeneralizationOf`, `isSpecialCaseOf`, `isDisjointWith`, `remainderUnsatisfiedBy` |
| **DAG Partitioning $O(1)$** | ✅ Supported | ❌ Extremely rare | ✅ Partitioned repositories with early disjoint branch pruning |
| **State Machine With Explained Denials** | ❌ None | ❌ None | ✅ Guards are specifications: a rejection carries code, message and severity; outbox, idempotency and persisted deadlines |
| **Concurrency & Locking** | ⚠️ Java Threads / Locks | ❌ Unsupported | ✅ Synchronized repositories (reentrant `SemaphoreSynchronizer`, Fiber-aware) `SysVSemaphoreSynchronizer` on SysV IPC between processes, and atomic `flock` file locks |
| **Modern Architecture** | ⚠️ Java 6/7 Generics | ⚠️ PHP 7.x legacy | ✅ **PHP 8.2+** native (Enums, First-class callables, Readonly, 8 segregated traits) |

---

## 📋 Requirements

- **PHP**: `^8.2` (tested on PHP 8.2 and 8.4). PHP 8.4 is required only by the optional ALinq integration (`Antevemus.AlinqCollection`, Module 14), which the library detects at runtime.
- **PHP Extensions**:
  - `ext-json` (for JSON entity serialization; required)
  - `ext-mbstring` (required since v1.5.0: Unicode-aware case-insensitive string specifications, `mb_strtolower`)
  - `ext-sysvsem` *(optional: `SysVSemaphoreSynchronizer`, the reader/writer lock shared between processes through SysV IPC semaphores, Linux/Unix)*
  - `ext-curl` *(optional: `HttpDataSource`, the concurrent HTTP fetching of the `DataResolver`, Module 21; without it an HTTP source is refused when registered and `CallableDataSource` keeps working)*
  - `ext-pdo` *(optional, with the driver of your database: `PdoRepository` (Modules 22, 25 and 26), the PDO stores of the state machine, `PdoRuleCatalog` and the PDO domain event outbox and trigger state store (Modules 23 and 24))*
- **Dependency**: one, from the same author, with no dependencies of its own: [`antevemus/adeep-property-access`](https://github.com/antevemus-it/Antevemus.ADeepPropertyAccess) `^1.0` (since v2.0.0), the property accessor shared with ALinq, so that both libraries read the same value from the same candidate (example 10.1). Composer installs it.
- **Optional package**: `antevemus/alinq-collection` `^1.5.1 || ^2.0` for `ALinqBridge` (typed `IALinqCollection`/`IALinqLazyCollection` returns), `ALinqSpecificationVisitor` and O(1) lazy streaming over `IRepository::iterate()` (requires PHP 8.4; ALinq reads a non-public property only when it is marked `#[Specifiable]`, the same rule as this package, and its 2.0 resolves properties through the same shared accessor).

> **Upgrading from 1.x?** Read [UPGRADE-2.0.md](UPGRADE-2.0.md) first: `.bin` files of the PHP-native serializer and rule catalogs with Portuguese values are converted on 1.14.x, before the upgrade.

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

**Evaluation errors and what to show.** When a rule cannot be evaluated (a specification, a handler or a guard throws), `isError` is `true`, the failure carries the generic message `SpecificationResult::EVALUATION_ERROR_MESSAGE` with the rule's code, and the exception stays on `$result->exception`, never in the failures. The library does not log for you. Recommended practice: send `$result->exception` to your logger, taking care not to log more than needed (exception messages can hold credentials, personal data or SQL); show users only `$failure->message`, which is either your business message or the generic one; and never show `metadata`, which is diagnostic and may hold the evaluated value (`metadata['actual']`).

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

The document is JSON whatever the serializer, and every entity carries its persistence metadata (`__persistence_metadata`: read and write counters, first and last read and write) next to its data, so `getEntityMetaData()` answers the same in a new process (since v2.0.0).

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

> **Vocabulary note.** The rule engine is English end to end: getters (`getCode()`, `getIntValue()`, `getViolationMessage()`, `getLegalBasis()`), named parameters (`scope:`, `scenario:`), `fromArray()` keys and catalog columns (`code`, `rule_type`, `violation_action`, `product_code`, `valid_from`...), context keys (`product_code`, `plan_code`, `reference_date`), failure metadata (`action`, `rule_code`, `legal_basis`...) and the action values `block`/`warn`/`log`. The Portuguese vocabulary of v1.6, legacy from v1.7.0 to v1.14.x, is refused since v2.0.0 with `LegacyVocabularyException`, never ignored: a Portuguese key or action value in a catalog, a context key, a table role, a handler's metadata key. [UPGRADE-2.0.md](UPGRADE-2.0.md) has the key table and the SQL that migrates a catalog; `PdoRuleCatalog` still reads tables created with the old schema through two presets (below), which map names only.

```php
use Antevemus\ASpecification\Spec;

// 1. Configure the Registry with pluggable rule handlers
$registry = Spec::ruleRegistry();
$registry->registerClosure('max_occurrences_per_contract', function ($rule) {
    return Spec::specify(Contract::class)
        ->must(fn($c) => $c->getOccurrencesCount() <= $rule->getIntValue(), $rule->getCode(), $rule->getViolationMessage());
});

// 2. Instantiate the Engine connected to the relational catalog repository
$engine = Spec::engine($myCatalogRepository, $registry);

// 3. Validate target entity for a specific scope and scenario
$verdict = $engine->validate(
    target: $contract,
    scope: 'rental_contract',
    scenario: 'activation'
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

The catalog can live in relational tables: `PdoRuleCatalog` reads the tables of the reference DDL [`resources/sql/rule_catalog.ansi.sql`](resources/sql/rule_catalog.ansi.sql) (`business_rule`, `document_requirement_group`, `document_requirement`) in portable, parameterized ANSI SQL and answers exactly like the in-memory catalog. Table and column names are configurable (`$tables`, `$columns`: canonical column => real column):

```php
use Antevemus\ASpecification\Engine\PdoRuleCatalog;

// Optional: read only the rules in force on a date (validity window filtered in SQL)
$catalog = (new PdoRuleCatalog($pdo))->withAsOf(new DateTimeImmutable('2026-10-09'));

// Tables created with the Portuguese schema of v1.5/v1.6 (regra_negocio...) are read by name; their action values must be English since v2.0 (UPGRADE-2.0.md)
$legacyCatalog = new PdoRuleCatalog(
    $pdo,
    'rental_guarantee',
    PdoRuleCatalog::legacyPortugueseTables(),
    PdoRuleCatalog::legacyPortugueseColumns()
);

$verdict = Spec::engine($catalog, $registry)->validate(
    target: $contract,
    scope: 'rental_contract',
    context: ['product_code' => 'PROD_A']
);
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
// '(("is_active" IS NOT NULL AND "is_active" = TRUE) AND (("val_salary" IS NOT NULL AND "val_salary" > :p1) OR ("txt_city" IS NOT NULL AND "txt_city" LIKE :p2)))'
// (booleans are rendered by the dialect; the remaining values are bound; each comparison carries an
// IS NOT NULL guard, so a NOT over a NULL column gives the in-memory answer)

print_r($whereClause->getBindings());
// [':p1' => 5000, ':p2' => 'New%']

// 3. First-class support for 15 database drivers across 10 SQL dialects (SQL Server, Oracle, Firebird, MySQL, SQLite, Db2, Informix, DuckDB, etc.)
$whereSqlServer = Spec::toSql($spec, SqlDialect::SQLSRV);
// '(([active] IS NOT NULL AND [active] = 1) AND (([salary] IS NOT NULL AND [salary] > :p1) OR ([city] IS NOT NULL AND [city] LIKE :p2)))'

$whereMySql = Spec::toSql($spec, SqlDialect::MYSQL);
// '((`active` IS NOT NULL AND `active` = 1) AND ((`salary` IS NOT NULL AND `salary` > :p1) OR (`city` IS NOT NULL AND BINARY `city` LIKE :p2)))'
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

#### 10.1 Deep Property Resolution with Dot-Notation (`PropertyAccess`)
Evaluate nested properties on objects (with getters or Boolean methods) and multidimensional associative arrays without reflection bottlenecks. Since v2.0.0 the resolution lives in [`antevemus/adeep-property-access`](https://github.com/antevemus-it/Antevemus.ADeepPropertyAccess), shared with ALinq (one accessor, one contract test); `Helpers\PropertyAccessor` remains as a deprecated facade until v3.0:

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

Each segment resolves in this order, first match wins: array or `ArrayAccess` key; public method `getX()`, `x()`, `isX()`, `hasX()` without required parameters (never `__call()`, never a name starting with `__`); public initialized property or `__get` guarded by `__isset`; public property; and, last, a private or protected property **marked `#[Specifiable]`** by the author of the class (since v1.6.1). The property name may come from a request (a filter or an ordering built by a screen), so private state is reachable by name only when its author opts in. An unmarked non-public property behaves as a property that does not exist: the property specification is an evaluation error, `PropertyAccess::hasProperty()` answers `false`, and a delta (`updateWithDelta()`) that names it throws `InvalidArgumentException` unless the entity has a public setter for it.

```php
use Antevemus\ADeepPropertyAccess\PropertyAccess;
use Antevemus\ASpecification\Attributes\Specifiable;
use Antevemus\ASpecification\Spec;

final class Account
{
    #[Specifiable]
    private string $status = 'active';      // opted in: readable by name, writable by a delta
    private string $passwordHash = 'h4sh';  // not marked: invisible to a name
}

$account = new Account();

Spec::property('status', Spec::equalTo('active'))->isSatisfiedBy($account);        // true
PropertyAccess::hasProperty($account, 'passwordHash');                              // false
Spec::property('passwordHash', Spec::startsWith('h'))->evaluate($account)->isError; // true, as a missing property
```

`#[Specifiable]` on a class covers every property **declared by that class** (not those of its parent nor of its subclasses). `#[Antevemus\ALinq\Attributes\Specifiable]` and `#[Antevemus\ADeepPropertyAccess\Attributes\Specifiable]` are accepted as the same marker, by name.

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

### 14. Spec-Driven State Machine (Modules 17 and 18)

`Antevemus\ASpecification\StateMachine` is a state machine in which **every guard is an `ISpecification`**: when a transition is denied, the result says why, with the code, message, property and severity of the specification that blocked it. The API is event first (`send(event)`, the target is a consequence), the definition is data (stable key and integer version, built fluently or loaded from JSON), `transition()` is pure (it computes the new snapshot, the transitions taken and the effects, and executes nothing), and `MachineRunner` is the single write port (snapshot, history and outbox in one transaction). The snippets of this section build on each other.

#### 14.1 A Minimal Machine: Guards Are Specifications

```php
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\StateMachine\Event;

// Named guards: name => factory(array $params): ISpecification, evaluated against a TransitionCandidate
// (context = your domain object or array, event = type + payload + occurredAt, snapshot = current states)
$guards = Spec::guardRegistry()->register('hasRole', fn(array $params) =>
    Spec::property('context.role', Spec::in(...$params['roles']))
        ->because('Only a manager can approve.')
        ->withCode('ROLE_NOT_ALLOWED'));

$order = Spec::machine('purchase_order', 1)
    ->region('lifecycle', 'draft')
        ->state('draft')
            ->on('SUBMIT', 'submitted')
        ->state('submitted')
            ->on('APPROVE', 'approved')->guard('hasRole', ['roles' => ['manager']])
            ->on('SEND_BACK', 'draft')->guard('payloadHas', ['field' => 'motive'])   // built-in guard
        ->finalState('approved')
    ->build();

Spec::validateMachine($order, $guards);              // [] (throws on errors, returns the warnings)

$machine  = Spec::stateMachine($guards);
$now      = new DateTimeImmutable('2026-10-10 09:00'); // the machine never reads the clock
$snapshot = $machine->initialSnapshot($order, null, $now)->getSnapshot();

$result = $machine->transition($order, $snapshot, Event::external('SUBMIT', $now));
$result->isAccepted();                               // true
$snapshot = $result->getSnapshot();
$snapshot->stateOf('lifecycle');                     // 'submitted'

$denied = $machine->transition($order, $snapshot, Event::external('APPROVE', $now), ['role' => 'clerk']);
$denied->isRejected();                               // true: a denial is a result, never an exception
$denied->getSnapshot() === $snapshot;                // true: nothing changed
$denied->getReason()->failures[0]->code;             // 'ROLE_NOT_ALLOWED'
$denied->getReason()->failures[0]->message;          // 'Only a manager can approve.'

$machine->transition($order, $snapshot, Event::external('APPROVE', $now), ['role' => 'manager'])
    ->getSnapshot()->isDone();                       // true: the region is in a final state
```

An event that no transition of the current state answers is `unhandled` (no change, no exception). When several transitions answer the same event, they are tried in declaration order and the first whose guard passes wins; a guard that throws becomes an `ERROR` failure marked `evaluation_error`.

#### 14.2 Parallel Regions: a Pending Flag That Never Loses the Progress

A machine has one or more orthogonal regions, each with its own current state. A pending item modelled as a region of its own (`none ⇄ open`) can be registered and resolved from any step without a "pending" state in the main flow, so resolving it never has to guess where the process was. `inState('region.state')` reads another region, and a guard expression can carry its own message and code:

```php
use Antevemus\ASpecification\StateMachine\GuardExpression;

$quote = Spec::machine('quote', 1)
    ->region('progress', 'registering')
        ->state('registering')
            ->on('SEND_PROPOSAL', 'proposal_sent')
        ->state('proposal_sent')
            ->on('APPROVE', 'approved')
                ->guard(GuardExpression::not(GuardExpression::ref('inState', ['state' => 'pending.open']))
                    ->because('Resolve the open pending item first.')
                    ->withCode('QUOTE_PENDING_OPEN'))
            ->on('CANCEL', 'cancelled')
        ->finalState('approved')
        ->finalState('cancelled')
    ->region('pending', 'none')
        ->state('none')
            ->on('REGISTER_PENDING', 'open')->guard('payloadHas', ['field' => 'motive'])
        ->state('open')
            ->on('RESOLVE_PENDING', 'none')
    ->build();

$machine = Spec::stateMachine();
$state = $machine->initialSnapshot($quote, null, $now)->getSnapshot();
$state = $machine->transition($quote, $state, Event::external('SEND_PROPOSAL', $now))->getSnapshot();
$state = $machine->transition($quote, $state, Event::external('REGISTER_PENDING', $now, ['motive' => 'Missing ID']))->getSnapshot();

$state->stateOf('progress');                         // 'proposal_sent': registering a pending item moved only its region
$state->isIn('pending.open');                        // true
$machine->transition($quote, $state, Event::external('APPROVE', $now))
    ->getReason()->failures[0]->code;                // 'QUOTE_PENDING_OPEN'
$machine->transition($quote, $state, Event::external('CANCEL', $now))->isAccepted(); // true: CANCEL is not blocked

$state = $machine->transition($quote, $state, Event::external('RESOLVE_PENDING', $now))->getSnapshot();
$state->stateOf('progress');                         // 'proposal_sent': back where it was, nothing to remember
$machine->transition($quote, $state, Event::external('APPROVE', $now))->isAccepted(); // true
```

Guards read the snapshot **before** the event (the order of the regions never changes the result), and an event is all or nothing: if any region rejects it, no region moves. The machine is `done` when every region that declares a final state is in one; a region without final states (the pending flag) does not hold the machine open, and a done machine answers every event as `unhandled`.

#### 14.3 `can()` and `nextEvents()`: a Disabled Button With Its Reason

`can()` is `transition()` itself, with no side effect; `nextEvents()` tests every event the current states answer (as an external event with an empty payload), so an action panel can show a disabled button together with the reason. Continuing with the pending item still open:

```php
foreach ($machine->nextEvents($quote, $state, null, $now) as $type => $answer) {
    echo $type, ': ', $answer->isAccepted() ? 'enabled' : 'disabled (' . $answer->getReason()->failures[0]->message . ')', PHP_EOL;
}
// APPROVE: disabled (Resolve the open pending item first.)
// CANCEL: enabled
// RESOLVE_PENDING: enabled

$machine->can($quote, $state, Event::external('APPROVE', $now))
    ->getRejections()['progress']->failures[0]->code; // 'QUOTE_PENDING_OPEN' (the reason per rejecting region)
```

#### 14.4 The Definition Is Data: JSON and the Validator

Guards and actions are referenced by name and parameters, never by closures, so a definition round-trips through arrays and JSON without loss and has a deterministic fingerprint (SHA-256 of its canonical form) to detect a definition changed without a new version. The validator runs at load time: it refuses the definition (one exception listing every error) for a region without initial state, an unknown target or `inState` path, a guard without factory (or an action without handler, when the known action names are given), a final state with outgoing transitions; it warns, without blocking, about unreachable states, non-final states without exit, overlapping or shadowed guards and guards that always pass. The document requirement below (pending → delivered → validated, or expired after 30 days) is the `RequirementState` the 1.0.0 changelog announced, now expressed as a machine:

```php
use Antevemus\ASpecification\StateMachine\MachineDefinition;

$requirement = MachineDefinition::fromJson(<<<'JSON'
{
  "key": "document_requirement",
  "version": 1,
  "regions": [{
    "name": "lifecycle",
    "initial": "pending",
    "states": [
      {"name": "pending", "transitions": [
        {"event": "DELIVER", "target": "delivered", "guard": {"guard": "payloadHas", "params": {"field": "file"}}}
      ]},
      {"name": "delivered", "deadline": {"after": "P30D", "event": "EXPIRE"}, "transitions": [
        {"event": "VALIDATE", "target": "validated"},
        {"event": "EXPIRE", "target": "expired"}
      ]},
      {"name": "on_hold"},
      {"name": "validated", "final": true},
      {"name": "expired", "final": true}
    ]
  }]
}
JSON);

MachineDefinition::fromArray($requirement->toArray()) == $requirement;   // true: lossless round trip
$requirement->fingerprint();                         // 64 hex characters, stable across key order and runs
$requirement->getTransition('lifecycle.pending.DELIVER') !== null;       // true: ids are generated as region.state.EVENT

foreach (Spec::validateMachine($requirement) as $warning) {
    echo $warning->kind, ': ', $warning->state, PHP_EOL;
}
// unreachable_state: on_hold
// dead_end_state: on_hold
```

#### 14.5 The Runner: One Transaction, Idempotency, Outbox and Persisted Deadlines

`MachineRunner::send()` loads the instance and the definition version it was started with, checks the idempotency key, computes the transition, and writes the new snapshot (compare-and-set on its version: a concurrent change throws `ConcurrencyConflictException` and is never retried silently), one history row per region moved and the effects in the outbox, in **one** transaction. Effects (the named actions: `exit` → transition → `entry`) are delivered **after** the commit by an `EffectDispatcher`, each with its own idempotency key, in write order per instance; a failing handler never undoes the transition. A deadline is persisted (`due_at`), never a timer in memory: a cron asks for the due ones and delivers them through the same runner, and a late or repeated delivery is harmless.

```php
use Antevemus\ASpecification\StateMachine\Effect;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\InMemoryMachineDefinitionRepository;
use Antevemus\ASpecification\StateMachine\Persistence\MachinePersistence;
use Antevemus\ASpecification\StateMachine\Runner\EffectDispatcher;
use Antevemus\ASpecification\StateMachine\Runner\EffectHandlerRegistry;
use Antevemus\ASpecification\StateMachine\Runner\MachineRunner;

$document = Spec::machine('document', 1)
    ->region('lifecycle', 'pending')
        ->state('pending')
            ->on('DELIVER', 'delivered')->guard('payloadHas', ['field' => 'file'])->action('notify_reviewer')
        ->state('delivered')->deadline('P3D', 'EXPIRE')
            ->on('VALIDATE', 'validated')
            ->on('EXPIRE', 'expired')->action('notify_owner')
        ->finalState('validated')
        ->finalState('expired')
    ->build();

$sent = [];
$handlers = (new EffectHandlerRegistry())
    ->register('notify_reviewer', function (Effect $effect, string $idempotencyKey) use (&$sent): void { $sent[] = $effect->action; })
    ->register('notify_owner', function (Effect $effect, string $idempotencyKey) use (&$sent): void { $sent[] = $effect->action; });

$persistence = MachinePersistence::inMemory();
$runner = new MachineRunner(Spec::stateMachine(), new InMemoryMachineDefinitionRepository([$document]), $persistence, $handlers);
$runner->start('doc-42', 'document', 1, null, new DateTimeImmutable('2026-10-10 09:00'));

$deliver = Event::external('DELIVER', new DateTimeImmutable('2026-10-10 10:00'), ['file' => 'id.pdf'], 'upload-7f3a');
$runner->send('doc-42', $deliver, actor: 'alice')->isAccepted();  // true
$runner->send('doc-42', $deliver, actor: 'alice')->isAccepted();  // true: same key, the original result, nothing written twice
$runner->load('doc-42')->version;                                 // 2 (start + one accepted event)
count($persistence->history->findByInstance('doc-42'));           // 2 (append-only: start + DELIVER)

// After the commit (a worker or a cron): deliver the effects
(new EffectDispatcher($persistence->outbox, $handlers))->dispatchPending()->deliveredCount(); // 1
$sent;                                                            // ['notify_reviewer']

// Deadlines: find and deliver the due ones (the EXPIRE event is internal: it cannot be sent from outside)
$runner->findDue(new DateTimeImmutable('2026-10-12 10:00'));       // [] (due at 2026-10-13 10:00)
$deliveries = $runner->deliverDue(new DateTimeImmutable('2026-10-13 10:00'), actor: 'scheduler');
$deliveries[0]->isDelivered();                                    // true
$runner->load('doc-42')->snapshot->stateOf('lifecycle');          // 'expired'
```

`EffectHandlerRegistry::registerSync()` declares the rare effect that must be able to veto: it runs inside the transaction, before the commit, and an exception rolls the whole step back. Each instance stays pinned to the definition version it started with; moving it to a new version is explicit (`registerMigration()` + `migrate()`).

#### 14.6 PDO Persistence and the Reference DDL

`MachinePersistence::pdo()` gives the same ports over the tables of [`resources/sql/state_machine.ansi.sql`](resources/sql/state_machine.ansi.sql): portable SQL, `CREATE TABLE IF NOT EXISTS` (accepted by PostgreSQL, MySQL/MariaDB and SQLite), a primary key on every table, `UNIQUE (instance_id, idempotency_key)`, instants as fixed-width UTC text. The due deadlines are searched with a specification compiled to SQL by the SQL visitor. The InMemory and PDO stores run the same test battery (SQLite in memory in the suite).

```php
// Once, with your migration tool: resources/sql/state_machine.ansi.sql
$runner = new MachineRunner(
    Spec::stateMachine(),
    new InMemoryMachineDefinitionRepository([$document]),  // definitions ship with the application
    MachinePersistence::pdo($pdo),                         // same contract as inMemory()
    $handlers
);

// Tables with other names: table names by role (instance, region, history, outbox, idempotency,
// rejection), an optional schema and, when the columns differ, canonical column => real column
$persistence = MachinePersistence::pdo($pdo, tables: ['instance' => 'quote_state', 'history' => 'quote_state_history']);
```

> **JSON columns.** `snapshot`, `payload`, `params`, `reason` and `transition_result` are JSON stored as `VARCHAR(4000)`, the portable size that also fits Oracle. A larger event payload needs the column widened to `TEXT`/`JSONB`/`CLOB` in your copy of the DDL. If the PDO connection is already inside a transaction, the runner joins it and the owner of that transaction commits or rolls back.

> **Upgrading from 1.8.** Since 1.9.0 `state_machine_region` has a nullable `paused_remaining` column (the balance of a paused deadline, example 14.7). `CREATE TABLE IF NOT EXISTS` does not add a column to an existing table, so a database created with the 1.8 script needs, once, before the first deadline pause: `ALTER TABLE state_machine_region ADD COLUMN paused_remaining INTEGER;`. Until then, every definition that never pauses a deadline keeps working on the old table; the first pause fails and its step is rolled back.

#### 14.7 Deadlines From the Context: an SLA That Pauses While a Pending Item Is Open

A deadline has exactly one source: a fixed duration (`deadline('P3D', 'EXPIRE')`, as in 14.5), a duration read from the context (`afterFrom: 'context.path'`, an ISO-8601 string or a `DateInterval`), or the due instant itself read from the context (`atFrom: 'context.path'`, a `DateTimeInterface` or an ISO-8601 text with date, time and offset). The path is read with the dot notation of the `PropertyAccessor` when the state is entered, from the context given to that call, so the computation stays pure. A missing or invalid source never becomes a silently empty deadline: the event that enters the state is rejected with `SM_DEADLINE_SOURCE_INVALID` (an `ERROR` that names the path, never the value), and so is a start, which then writes nothing. `pausedWhile` takes a guard expression (a `'region.state'` string is a shorthand for `inState`), evaluated after every accepted step: when it starts to hold, the deadline pauses (no due instant, so no cron ever finds it, and the remaining whole seconds are kept); when it stops holding, the deadline resumes from that balance. Re-entering the state restarts the deadline and drops the balance. Below, the SLA is a region of its own (an absolute instant from the contract), paused while a pending flag (the parallel region of example 14.2) is open, and each stage lasts what the client's table says:

```php
use Antevemus\ASpecification\StateMachine\Persistence\DueDeadline;
use Antevemus\ASpecification\StateMachine\Persistence\MachineInstance;

$claim = Spec::machine('claim', 1)
    ->region('progress', 'triage')
        ->state('triage')->deadline(event: 'TRIAGE_CLOSED', afterFrom: 'context.deadlines.triage')
            ->on('START_ANALYSIS', 'analysis')
            ->on('TRIAGE_CLOSED', 'analysis')
        ->state('analysis')->deadline(event: 'ANALYSIS_OVERDUE', afterFrom: 'context.deadlines.analysis')
            ->on('APPROVE', 'approved')
            ->on('ANALYSIS_OVERDUE', 'late')
        ->state('late')
            ->on('APPROVE', 'approved')
        ->finalState('approved')
    ->region('pending', 'none')
        ->state('none')->on('REGISTER_PENDING', 'open')
        ->state('open')->on('RESOLVE_PENDING', 'none')
    ->region('sla', 'running')
        ->state('running')->deadline(event: 'SLA_BREACHED', atFrom: 'context.sla_due_at', pausedWhile: 'pending.open')
            ->on('SLA_BREACHED', 'breached')
        ->finalState('breached')
    ->build();

// The context of each claim: the SLA instant of the contract and the stage durations of the client
$contexts = ['claim-7' => ['sla_due_at' => '2026-11-09T18:00:00-03:00', 'deadlines' => ['triage' => 'P2D', 'analysis' => 'P5D']]];

$runner = new MachineRunner(Spec::stateMachine(), new InMemoryMachineDefinitionRepository([$claim]), MachinePersistence::inMemory());
$runner->start('claim-7', 'claim', 1, $contexts['claim-7'], new DateTimeImmutable('2026-10-10T09:00:00-03:00'))->isAccepted(); // true
$runner->load('claim-7')->snapshot->dueAt('sla')->format(DATE_ATOM);      // '2026-11-09T18:00:00-03:00' (atFrom)
$runner->load('claim-7')->snapshot->dueAt('progress')->format(DATE_ATOM); // '2026-10-12T09:00:00-03:00' (start + P2D, afterFrom)

// A missing source is a rejection, never a silently empty SLA: the start writes nothing
$missing = $runner->start('claim-8', 'claim', 1, ['deadlines' => ['triage' => 'P2D']], new DateTimeImmutable('2026-10-10T09:00:00-03:00'));
$missing->isRejected();                                                   // true
$missing->getReason()->failures[0]->code;                                 // 'SM_DEADLINE_SOURCE_INVALID'
$missing->getReason()->failures[0]->property;                             // 'context.sla_due_at'

// The cron: TRIAGE_CLOSED enters 'analysis', whose deadline reads the context, so the cron provides it
$deliveries = $runner->deliverDue(
    new DateTimeImmutable('2026-10-12T09:00:00-03:00'),
    contextProvider: fn(DueDeadline $due, MachineInstance $instance) => $contexts[$instance->instanceId]
);
$deliveries[0]->isDelivered();                                            // true (without the provider: rejected, retried on the next run)
$runner->load('claim-7')->snapshot->dueAt('progress')->format(DATE_ATOM); // '2026-10-17T09:00:00-03:00'

// A pending item opened 20 days before the SLA: the SLA pauses and keeps the balance
$runner->send('claim-7', Event::external('REGISTER_PENDING', new DateTimeImmutable('2026-10-20T18:00:00-03:00')), $contexts['claim-7']);
$snapshot = $runner->load('claim-7')->snapshot;
$snapshot->isPaused('sla');                                               // true
$snapshot->dueAt('sla');                                                  // null: a paused deadline is never due
$snapshot->pausedRemaining('sla');                                        // 1728000 (20 days, in whole seconds)
array_map(fn(DueDeadline $due) => $due->region, $runner->findDue(new DateTimeImmutable('2027-01-01T00:00:00-03:00'))); // ['progress']

// Resolved 5 days later: the SLA resumes with the balance, 5 days after the contract instant
$runner->send('claim-7', Event::external('RESOLVE_PENDING', new DateTimeImmutable('2026-10-25T18:00:00-03:00')), $contexts['claim-7']);
$runner->load('claim-7')->snapshot->dueAt('sla')->format(DATE_ATOM);      // '2026-11-14T18:00:00-03:00'
```

`deliverDue()` sends a null context unless given a `contextProvider` (it receives the due deadline and the loaded instance): a deadline transition entering a state whose deadline reads the context needs the instance's context, as here. A pause condition is any guard expression; one that throws rejects the step. The validator refuses a `pausedWhile` with an unknown guard or an `inState` path that does not exist, and warns when it references the deadline's own region (the pause would never end). The snapshot keeps the balance (`pausedRemaining()`, `paused_remaining` in its array form only while some region is paused, so a 1.8 snapshot loads unchanged), and `migrate()` refuses a migration that would leave a paused balance in a state whose deadline declares no `pausedWhile`, since it could never resume.

Deliberately out of scope (each can be modelled without it): actors, `invoke`/`spawn` (asynchronous work is an external job that sends an event back), eventless (`always`) transitions and `raise` (the caller sends the next event), nested states inside a region and history states (a parallel region, like the pending flag above, covers the real cases), and a machine-owned context (`assign`): the context is the caller's domain object. The history is a trail; the snapshot is the source of truth (no event sourcing).

### 15. Use Cases With a Result Shared With the Machine (Module 19)

A use case is one class with one method, `execute(object $request): UseCaseResult`. `UseCaseRunner` runs it through the middlewares, validates the request first (its `#[ValidateRule]`/`#[AssertSpec]` attributes plus any specification registered for its class) and only an `ERROR` failure blocks: `WARNING` and `INFO` come back with the success. The status is a closed domain enum (`SUCCESS`, `CREATED`, `ACCEPTED`, `NO_CHANGE`, `INVALID`, `REJECTED`, `NOT_FOUND`, `CONFLICT`, `FORBIDDEN`, `FAILED`); success is derived from it alone, and HTTP exists only in one edge method, `toHttpStatus()`. An unhandled exception becomes `FAILED` with a generic message and goes to an optional `IUseCaseErrorReporter`: no message, class, file, line or stack trace ever reaches the result. No mediator, container or handler discovery is embedded.

#### 15.1 `IUseCase`, `UseCaseRunner` and `TransactionMiddleware`

```php
use Antevemus\ASpecification\Attributes\ValidateRule;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\UseCase\IUseCase;
use Antevemus\ASpecification\UseCase\TransactionMiddleware;
use Antevemus\ASpecification\UseCase\UseCaseResult;
use Antevemus\ASpecification\UseCase\UseCaseStatus;

final class RegisterTenantRequest
{
    public function __construct(
        #[ValidateRule('not_blank', code: 'NAME_REQUIRED', message: 'The tenant name is required.')]
        public readonly string $name,
        #[ValidateRule('<=', value: 5000, code: 'RENT_REVIEW', message: 'Rent above 5 000 goes to manual review.', severity: 'WARNING')]
        public readonly int $rent,
    ) {}
}

/** @implements IUseCase<RegisterTenantRequest, int> */
final class RegisterTenant implements IUseCase
{
    public function __construct(private PDO $pdo) {}

    public function execute(object $request): UseCaseResult
    {
        $this->pdo->prepare('INSERT INTO tenant (name, rent) VALUES (?, ?)')->execute([$request->name, $request->rent]);

        return UseCaseResult::created((int) $this->pdo->lastInsertId());
    }
}

$useCases = Spec::useCaseRunner(middlewares: [
    new TransactionMiddleware($pdo->beginTransaction(...), $pdo->commit(...), $pdo->rollBack(...)),
]);

$created = $useCases->run(new RegisterTenant($pdo), new RegisterTenantRequest('Alice', 6200));
$created->isSuccess();                       // true: a WARNING never blocks (committed)
$created->getStatus();                       // UseCaseStatus::CREATED
$created->getWarnings()[0]->code;            // 'RENT_REVIEW'

$invalid = $useCases->run(new RegisterTenant($pdo), new RegisterTenantRequest('', 1200));
$invalid->getStatus();                       // UseCaseStatus::INVALID: execute() was not called (rolled back)
$invalid->getCodes();                        // ['NAME_REQUIRED']

// HTTP only at the edge of the application
$invalid->getStatus()->toHttpStatus();       // 400 (CREATED → 201, REJECTED → 422, CONFLICT → 409 ...)
```

`TransactionMiddleware` commits only a success and rolls back a failure status or an exception (which it rethrows for the runner to turn into `FAILED`). `UseCaseResult` and the machine's `TransitionResult` implement the same `Results\IOutcome` (`isSuccess()`, `getReason()`), and `UseCaseResult::fromTransition()` converts one into the other.

#### 15.2 A Machine Event as a Use Case

`StateMachineEventUseCase` (or `Spec::machineEventUseCase()`) exposes one event of a machine as a use case: the request is a `MachineEventRequest` (instance id, `occurredAt`, payload, optional idempotency key, actor and expected version), and the outcome is the runner's result converted: accepted → `SUCCESS` (payload = the new snapshot), rejected → `REJECTED` with the guards' codes, unhandled → `CONFLICT`, a stale version → `CONFLICT` with `SM_CONCURRENCY_CONFLICT`, an unknown instance → `NOT_FOUND`. With the quote machine of 14.2:

```php
use Antevemus\ASpecification\UseCase\MachineEventRequest;

$runner = new MachineRunner(Spec::stateMachine(), new InMemoryMachineDefinitionRepository([$quote]), MachinePersistence::inMemory());
$runner->start('quote-7', 'quote', 1, null, $now);

$useCases = Spec::useCaseRunner();
$send = fn(string $event, array $payload = []): UseCaseResult => $useCases->run(
    Spec::machineEventUseCase($runner, $event),
    new MachineEventRequest('quote-7', $now, $payload, actor: 'alice')
);

$send('SEND_PROPOSAL')->getStatus();                          // UseCaseStatus::SUCCESS
$send('REGISTER_PENDING', ['motive' => 'Missing ID'])->isSuccess(); // true
$send('APPROVE')->getCodes();                                 // ['QUOTE_PENDING_OPEN'] (status REJECTED, HTTP 422)
$send('SEND_PROPOSAL')->getStatus();                          // UseCaseStatus::CONFLICT: the event does not fit the current state
$send('RESOLVE_PENDING')->getPayload()->stateOf('progress');  // 'proposal_sent'

$useCases->run(Spec::machineEventUseCase($runner, 'CANCEL'), new MachineEventRequest('quote-99', $now))
    ->getStatus();                                            // UseCaseStatus::NOT_FOUND
```

### 16. Concurrent Data Resolution for I/O-Bound Specifications (Module 21)

A rule that depends on data held elsewhere (a credit bureau score, a fraud check, the balance in the core banking) does not call the service. `Spec::external()` **declares** the data it needs: a source name, parameters read from the candidate (dot paths), and an ordinary specification that decides on the value, with its own `because()`/`withCode()`. A `DataResolver` collects the declarations of the whole tree, fetches the data concurrently (`curl_multi`, no package dependency, works under PHP-FPM) and only then evaluates the tree, synchronously, as every specification is evaluated. Sources are registered by name: `HttpDataSource` (fixed base URL, a path template with `{placeholders}`, headers, its own time limit, a cost and a cache TTL) and `CallableDataSource` (any synchronous callable: a repository, an SDK, a gRPC client).

```php
use Antevemus\ASpecification\Resolution\DataResolver;
use Antevemus\ASpecification\Resolution\DataSourceRegistry;
use Antevemus\ASpecification\Resolution\HttpDataSource;
use Antevemus\ASpecification\Resolution\ResolutionPolicy;
use Antevemus\ASpecification\Spec;

$serasaUrl = 'https://bureau.example.com';         // owned by the source, never by the candidate
$fraudUrl  = 'https://fraud.example.com';
$bankUrl   = 'https://core-banking.example.com';

$registry = DataSourceRegistry::default()
    ->register('serasa.score', new HttpDataSource(
        baseUrl: $serasaUrl,
        path: '/serasa/score/{document}',
        parse: fn(int $status, array $headers, string $body): int => json_decode($body, true)['score'],
        headers: ['Authorization' => 'Bearer bureau-token'],
        timeout: 2.0,
        cost: 10,                                 // a paid bureau query
    ))
    ->register('fraud.flag', new HttpDataSource(
        baseUrl: $fraudUrl,
        path: '/fraud/check/{document}',
        parse: fn(int $status, array $headers, string $body): bool => json_decode($body, true)['flagged'],
        cost: 5,
    ))
    ->register('bank.balance', new HttpDataSource(
        baseUrl: $bankUrl,
        path: '/bank/balance/{account}',
        parse: fn(int $status, array $headers, string $body): float => json_decode($body, true)['balance'],
        cost: 1,                                  // our own core banking: cheap
    ));

// The rule declares the data it needs; it calls nothing
$credit = Spec::external('bank.balance', ['account' => 'account.number'],
        Spec::greaterThanOrEqualTo(1000)->because('Balance below 1,000.')->withCode('LOW_BALANCE'))
    ->and(Spec::external('fraud.flag', ['document' => 'cpf'], Spec::isFalse())->withCode('FRAUD_SUSPECTED'))
    ->and(Spec::external('serasa.score', ['document' => 'cpf'], Spec::greaterThanOrEqualTo(700))
        ->because('Credit score below 700.')->withCode('SCORE_LOW'));

$customer = (object) ['cpf' => '111.444.777-35', 'account' => (object) ['number' => 'A-1']];
$resolver = new DataResolver($registry);

// concurrent() (the default): the three calls at once, about the time of the slowest one
$resolver->evaluate($credit, $customer, ResolutionPolicy::concurrent())->isSatisfied;      // true

// tiered() (an explicit choice): cheapest first, stopping as soon as the verdict is decided
$poor = (object) ['cpf' => '222.333.444-05', 'account' => (object) ['number' => 'A-2']];
$data = $resolver->resolve($credit, $poor, ResolutionPolicy::tiered()); // balance 10: the AND is already false
count($data->getSkippedRequests());                   // 2: the fraud check and the bureau were not called
$data->evaluate($credit, $poor)->getCodes();          // ['LOW_BALANCE']

// Same verdict under every policy; concurrent() paid every source and reports every failure
$resolver->evaluate($credit, $poor, ResolutionPolicy::concurrent())->getCodes();          // ['LOW_BALANCE', 'SCORE_LOW']
$resolver->evaluate($credit, $poor, ResolutionPolicy::sequential())->isSatisfied;         // false
```

The policies: `concurrent()` (the default) fetches every request of the tree in one stage and reports every failure, as an evaluation outside the resolver does; `tiered()` (an explicit choice, when calls cost money) fetches by cost, cheapest first, and after each stage evaluates the tree in three-valued logic (true, false, unknown): once the verdict can no longer change (an `and` with a false operand, an `or` whose left operand is true) the more expensive sources are not called; `sequential()` fetches nothing beforehand and each leaf fetches when the evaluation reaches it. The verdict is the same under every policy and outside the resolver; under `tiered()`, a branch that was not fetched because it could not change the verdict reports no failure, so the list of failures can be shorter than with `concurrent()`. An `or` stops early only on its left operand: in the core an error on the left operand aborts the disjunction, so an unknown left operand cannot be skipped. Two leaves asking the same data (same source, same parameters) cause one call. A failed fetch is unknown, never a decision on its own.

```php
use Antevemus\ASpecification\Resolution\HttpDataSource;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Sql\Exceptions\NonTranslatableSpecificationException;

// Outside the resolver the same rule still works: each leaf fetches its own data through the
// default registry, synchronously (one call after the other, no deduplication)
$credit->evaluate($customer)->isSatisfied;                          // true

// A source that fails is an evaluation error of its leaf, never a silent "false"
$limitsUrl = 'https://limits.example.com';
$registry->register('credit.limit', new HttpDataSource(baseUrl: $limitsUrl, path: '/limits/{document}', cost: 2));
$limit = Spec::external('credit.limit', ['document' => 'cpf'], Spec::greaterThan(0));

$result = $resolver->evaluate($limit, $customer);                   // the service answers 503
$result->isError;                                                   // true
$result->failures[0]->message;                                      // 'The rule could not be evaluated.'
$result->exception->getStatusCode();                                // 503, for the log (the body is never kept)

// The visitors refuse the leaf: the data only exists when the tree is evaluated in PHP
try {
    $credit->toSql('pgsql');
} catch (NonTranslatableSpecificationException $e) {
    // filter in SQL with the other rules, evaluate the external part in PHP
}
```

Errors follow the 1.8.0 rule: a fetch that fails (an HTTP status outside 2xx, a transport error, the time limit of the source or of the whole resolution with `ResolutionPolicy::tiered(timeout: 3.0)`, a parser that throws, an unknown source) is an evaluation error of the leaf: a failure with the generic message, `evaluation_error = true`, and a `DataFetchException` in `$result->exception` with the status code, never the response body. There is no retry. A parameter value is percent-encoded into the URL template, so `/`, `?`, `#`, `..` or a whole URL in a document number cannot change the host or the path of the call; redirects are not followed. A cache between resolutions is optional (`new DataResolver($registry, new InMemoryDataCache())`, with the TTL of each source; failures are never cached). `ext-curl` is a `suggest`: without it an `HttpDataSource` is refused when registered and a `CallableDataSource` keeps working. The SQL, TCriteria and ALinq visitors refuse the leaf with their "non translatable" exception. State machine guards stay pure: a use case resolves the data before sending the event. Concurrency for non-HTTP sources (Fibers/Revolt) is a planned satellite package.

### 17. Repository Over PDO (Module 22)

`PdoRepository` is the `IRepository` of a database table: a query is a specification compiled to SQL by the SQL visitor (any of its dialects), a write is a prepared `INSERT`/`UPDATE`/`DELETE`, and `iterate()` streams the cursor. The author of the entity maps it to the table, with attributes or with a builder; a mapped property is read and written by reflection even when it is private (the mapping is the author's opt-in). The library generates no DDL; relationships are declared on the entity and loaded explicitly, in batch (example 17.3).

```php
use Antevemus\ASpecification\Entities\AbstractEntity;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Column;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\ColumnType;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Id;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\IdStrategy;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Table;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Version;
use Antevemus\ASpecification\Repositories\Pdo\PdoRepository;
use Antevemus\ASpecification\Spec;

#[Table('product')]
#[Version(property: 'version')]                   // optimistic locking on the counter AbstractEntity declares
class Product extends AbstractEntity
{
    #[Id(strategy: IdStrategy::Auto)]             // identity/auto-increment, read back after the INSERT
    private ?int $id = null;

    public function __construct(
        #[Column] public string $name,
        #[Column('unit_price', type: ColumnType::Decimal)] public string $price,   // exact decimal, as a string
        #[Column] public bool $active = true,
        #[Column] private string $cost = '0.00',  // mapped, but private without a getter: never queryable by name
    ) {
        parent::__construct();
    }

    public function getEntityId(): ?int
    {
        return $this->id;
    }
}

$pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE product (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(80) NOT NULL,
    unit_price TEXT NOT NULL, active INTEGER NOT NULL, cost TEXT NOT NULL, version INTEGER NOT NULL)');

$products = PdoRepository::forEntity($pdo, Product::class);   // the dialect comes from the PDO driver

$keyboard = new Product('Keyboard', '149.90', cost: '90.00');
$products->put($keyboard);                        // INSERT; the generated id comes back
$keyboard->getEntityId();                         // 1
$keyboard->getVersion();                          // 1
$products->putAll([new Product('Mouse', '59.90'), new Product('Cable', '19.90', active: false)]); // one transaction

$active = Spec::property('active', Spec::isTrue());
$products->count($active);                        // 2: SELECT COUNT(*), no entity is built
$products->findSingle(Spec::property('name', Spec::equalTo('Mouse')))->price;   // '59.90'
foreach ($products->iterate($active) as $product) {
    echo $product->name, PHP_EOL;                 // Keyboard, Mouse: one row at a time from the cursor
}
array_map(fn(Product $p): string => $p->name,
    $products->findPage($active, limit: 1, offset: 1, orderBy: ['name' => 'DESC']));   // ['Keyboard']
$products->toWhereClause($active)->toSql();       // '("active" IS NOT NULL AND "active" = 1)'
```

Optimistic locking: with a `#[Version]` counter, the `INSERT` writes 1 and every `UPDATE` increments it and only matches the row while it still holds the version the entity was read with.

```php
use Antevemus\ASpecification\Repositories\Pdo\Exceptions\OptimisticLockException;

$mine   = $products->findById(1);                 // both copies carry version 1
$theirs = $products->findById(1);
$theirs->price = '139.90';
$products->update($theirs);                       // UPDATE ... WHERE id = 1 AND version = 1
$theirs->getVersion();                            // 2

$mine->price = '129.90';
try {
    $products->update($mine);                     // still version 1: the UPDATE matches no row
} catch (OptimisticLockException $e) {
    // nothing was written: reload, apply the change again
}
$products->findById(1)->price;                    // '139.90'
```

The rules that matter, and the builder for a class that cannot carry attributes:

```php
use Antevemus\ASpecification\Repositories\Pdo\Mapping\EntityMapping;
use Antevemus\ASpecification\Specifications\PredicateSpecification;
use Antevemus\ASpecification\Sql\Exceptions\NonTranslatableSpecificationException;

// A specification the SQL visitor cannot translate is refused: never a silent full-table scan
$cheap = new PredicateSpecification(fn(Product $p): bool => (float) $p->price < 100);
try {
    $products->find($cheap);
} catch (NonTranslatableSpecificationException $e) {
    // express the rule with property specifications, or opt in below
}
count(PdoRepository::forEntity($pdo, Product::class)->withInMemoryFallback()->find($cheap)); // 2: reads the whole table

// Only a mapped property a specification may read by name (public, public getter or #[Specifiable])
try {
    $products->find(Spec::property('cost', Spec::greaterThan('50')));
} catch (NonTranslatableSpecificationException $e) {
    // "cost" is mapped but private without a getter: refused in SQL as it is in memory
}

// The same mapping, built explicitly
$mapping = EntityMapping::for(Product::class)->table('product')->id('id', IdStrategy::Auto)->version('version')
    ->column('name')->column('price', 'unit_price', ColumnType::Decimal)->column('active')->column('cost');
$mapping->equals(EntityMapping::fromAttributes(Product::class));  // true
$products = new PdoRepository($pdo, $mapping);
```

- **Queries.** Every value is a bound parameter; table and column names come only from the mapping, validated as plain identifiers and quoted by the dialect. A specification that cannot be translated (a closure, a method call, external data) throws `NonTranslatableSpecificationException`; nothing is filtered in memory unless `withInMemoryFallback()` was called, and then the whole table is read, so keep it for small tables. Only a property that is mapped **and** readable by name (public, a public getter or `#[Specifiable]`) can be queried, the rule the in-memory evaluation applies, so a private column is never a read oracle through SQL. `entityId` always means the identifier column.
- **Reads.** `find*()`, `iterate*()` and `findPage()` order by the identifier (`findPage()` appends it to the given order, so pages never overlap); `iterate()` is a generator over the cursor (constant memory); `count()` builds no entity; `findSingle()` asks for at most two rows and throws when there is more than one; `findById()` reads one row. Entities are built without calling their constructor. MySQL streams only with `PDO::MYSQL_ATTR_USE_BUFFERED_QUERY` set to `false` on the connection; with the default buffered mode the driver holds the whole result before the first row.
- **Writes** run in a transaction of the repository's `ITransactionManager` (by default a `PdoTransactionManager` on the same connection, which joins a transaction already open): `put()`/`update()` insert or update by the presence of the id and of the row, `putAll()` is all or nothing, `removeBy()` is one `DELETE ... WHERE`. A stale update or removal throws `OptimisticLockException` and writes nothing; a versioned copy whose row is gone is never re-inserted.
- **Identifiers**: `auto` (identity, serial, auto-increment; in PostgreSQL `INSERT ... RETURNING`, or `lastInsertId()` of the declared sequence), `ulid`, `uuid7`, `assigned`, and `max` (`SELECT COALESCE(MAX(id), 0) + 1` inside the write transaction, compatible with the `IDPOLICY = 'max'` of Adianti's `TRecord`). `max` is subject to a race between concurrent writers: two transactions can read the same maximum and the primary key rejects the second `INSERT`. Prefer `auto`, `ulid` or `uuid7` for new tables.
- **Null values.** A `NULL` column is a null value, and the database answers for it what the in-memory evaluation answers: `property('note', isNull())` matches the rows whose `note` is `NULL`; a comparison (`greaterThan()`, `startsWith()`, `equalTo('x')`, `in('a', 'b')`, ...) never matches them, and its negation `not(property('views', greaterThan(10)))` does. Each comparison is compiled as `(column IS NOT NULL AND ...)`, so it is false there, never SQL's unknown, and a `NOT` over it keeps the in-memory answer. An `and`/`or`/`not` written *inside* a property rejects a null value, as it rejects any null candidate: "no note, or note n2" is `anyOf(property('note', isNull()), property('note', equalTo('n2')))`, not `property('note', anyOf(isNull(), equalTo('n2')))`. `Spec::toSql()` and `Spec::toCriteria()` follow the same rule (changed in 1.11.0, see the CHANGELOG).
- **Text operators follow memory; plain equality follows the column.** Since v1.14.0, `startsWith`/`endsWith`/`contains`, the wildcards, `equalIgnoreCase` and `regex` select in the database the rows the in-memory evaluation accepts, on every column collation: on SQLite the case-sensitive form uses `GLOB` (its `LIKE` ignores ASCII case), and MySQL/MariaDB compare through `utf8mb4_bin` and give `REGEXP` the case rule as an inline flag, so `startsWith('mo')` does not match `Mouse` anywhere. Two limits remain. A case-insensitive comparison of non-ASCII letters (`É` and `é`) follows the database where it folds ASCII only (SQLite, PostgreSQL columns with the `"C"` collation). Plain `=` (`equals`) is the database's own comparison, so MySQL's default `_ci` collations ignore case and accents there: declare the column with a binary collation (`utf8mb4_bin`) for exact equality. Write a regex with the `u` modifier when it matches non-ASCII text (in memory, `.` without `u` is one byte). SQLite gives a `DECIMAL` column numeric affinity (`'59.90'` comes back as `'59.9'`), so the example declares it `TEXT`; comparisons on that column are then textual.
- **The transaction port is shared** (`Persistence\ITransactionManager`): the state machine's `PdoTransactionManager` is one, so one instance gives the repository, the domain event outbox (example 18) and the machine stores the same transaction.
- **Not in this version:** joins (relationships load in batch by `IN`, example 17.3), DDL generation, second-level cache, partitions (`makePartition()` throws `PartitioningNotSupportedException`). Lifecycle columns, soft delete, hooks and the SQL log are in example 17.1, batch and set-based operations in 17.2.

### 17.1. Lifecycle: Timestamps, Soft Delete, Hooks and the SQL Log (Module 25)

The bookkeeping an application usually writes by hand around a table, as options of the mapping and of the repository: creation and modification timestamps, a removal that marks the row instead of deleting it, callables around each write, a log of every statement, booleans stored as integers or as text and the time zone of columns that hold local time without an offset (the Adianti tables, for one). Without them nothing changes.

```php
use Antevemus\ASpecification\Persistence\InMemoryQueryLogger;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\BooleanStorage;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\CreatedAt;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\SoftDelete;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\UpdatedAt;

#[Table('ticket', timezone: 'America/Sao_Paulo', booleanAs: BooleanStorage::Integer)]   // local time without offset, flags as 1/0
#[SoftDelete('deleted_at')]                       // remove() marks the row instead of deleting it
class Ticket extends AbstractEntity
{
    #[Id(strategy: IdStrategy::Auto)]
    private ?int $id = null;

    #[CreatedAt] public ?DateTimeImmutable $createdAt = null;   // filled by the INSERT, never updated
    #[UpdatedAt] public ?DateTimeImmutable $updatedAt = null;   // set by every write of the row

    public function __construct(
        #[Column] public string $subject,
        #[Column] public bool $urgent = false,
    ) {
        parent::__construct();
    }

    public function getEntityId(): ?int
    {
        return $this->id;
    }
}

// $pdo of example 17
$pdo->exec('CREATE TABLE ticket (id INTEGER PRIMARY KEY AUTOINCREMENT, subject VARCHAR(80) NOT NULL,
    urgent SMALLINT NOT NULL, created_at TEXT, updated_at TEXT, deleted_at TEXT)');

$now = new DateTimeImmutable('2026-03-10 14:30:00.750 UTC');
$log = new InMemoryQueryLogger();
$tickets = PdoRepository::forEntity($pdo, Ticket::class)
    ->withClock(function () use (&$now): DateTimeImmutable { return $now; })   // the system clock by default
    ->withQueryLogger($log)                       // parameter values stay out unless logParameters: true
    ->beforePut(function (Ticket $ticket): void { // also afterPut(), beforeRemove(), afterRemove()
        if (trim($ticket->subject) === '') {
            throw new InvalidArgumentException('A ticket needs a subject.');
        }
    });

$ticket = new Ticket('Printer on fire', urgent: true);
$tickets->put($ticket);
$ticket->createdAt->format('Y-m-d H:i:s P');      // '2026-03-10 11:30:00 -03:00': in the mapping's zone, to the second
$pdo->query('SELECT created_at, urgent FROM ticket')->fetch(PDO::FETCH_NUM);   // ['2026-03-10 11:30:00', 1]
$log->entries()[0]['parameterCount'];             // 5
$log->entries()[0]['parameters'];                 // null: values can be personal data

$now = $now->modify('+2 hours');
$ticket->subject = 'Printer on fire, 3rd floor';
$tickets->put($ticket);
$ticket->updatedAt->format('H:i');                // '13:30' (createdAt keeps '11:30')
```

Removal marks the row; every read leaves the marked rows out, and two views see them:

```php
try {
    $tickets->put(new Ticket('  '));
} catch (InvalidArgumentException $e) {
    // the beforePut hook refused it inside the transaction: nothing was written
}

$tickets->remove($ticket);                        // UPDATE ... SET deleted_at: the row stays
$tickets->count(Spec::alwaysTrue());              // 0: find, iterate, count, findById, select, aggregate skip it
$tickets->withTrashed()->count(Spec::alwaysTrue());   // 1: a view that also sees the marked rows
$tickets->onlyTrashed()->findById(1)->subject;    // 'Printer on fire, 3rd floor'
$tickets->forceRemove($ticket);                   // DELETE: gone for real
$tickets->withTrashed()->count(Spec::alwaysTrue());   // 0
```

Legacy flags stored as text, such as the `CHAR(1)` `'Y'`/`'N'` columns of the Adianti tables, are read strictly:

```php
use Antevemus\ASpecification\Repositories\Pdo\Exceptions\DataIntegrityException;

#[Table('member')]
class Member extends AbstractEntity
{
    public function __construct(
        #[Id(strategy: IdStrategy::Assigned)] private int $id,
        #[Column(booleanAs: BooleanStorage::Char)] public bool $active = true,   // 'Y' / 'N'
        #[Column(booleanAs: BooleanStorage::Char, trueValue: 'S', falseValue: 'N')] public ?bool $admin = null,
    ) {
        parent::__construct();
    }

    public function getEntityId(): int
    {
        return $this->id;
    }
}

$pdo->exec('CREATE TABLE member (id INTEGER PRIMARY KEY, active CHAR(1) NOT NULL, admin CHAR(1))');
$members = PdoRepository::forEntity($pdo, Member::class);
$members->putAll([new Member(1), new Member(2, active: false, admin: true)]);
$pdo->query('SELECT active, admin FROM member WHERE id = 2')->fetch(PDO::FETCH_NUM);   // ['N', 'S']
$members->toWhereClause(Spec::property('active', Spec::isTrue()))->toSql();
// ("active" IS NOT NULL AND "active" IN ('Y', 'y')): every case form a read accepts

$pdo->exec("UPDATE member SET active = 'X' WHERE id = 1");   // a value the mapping does not declare
try {
    $members->findById(1);
} catch (DataIntegrityException $e) {
    $e->getStoredValue();                         // 'X': an integrity error, never read as false
}
```

- **Time zones.** A temporal column is written and read in the zone it declares (`#[Column(timezone: ...)]`), else in the default of the mapping (`#[Table(timezone: ...)]`, `EntityMapping::timezone()`), else in the default of the repository (`withTimezone()`), else in UTC.
- **Timestamps.** `#[CreatedAt]` is filled by the `INSERT` when the entity holds no value (an imported value is kept) and never updated; `#[UpdatedAt]` is set by the `INSERT`, every `UPDATE` and the soft removal. The instant comes from the repository's clock (`withClock()`, read once per call) and is truncated to the second, so the entity holds exactly what any timestamp column stores.
- **Soft delete.** `#[SoftDelete('deleted_at')]` on the class (the column alone; add `property:` to hydrate it) or on a nullable date property. `remove()` and `removeBy()` mark only live rows and increment the `#[Version]` counter like any write; `withTrashed()` and `onlyTrashed()` return views (copies of the repository); `forceRemove()` deletes. A marked row is removed for every reader: `TriggeringRepository` publishes the exit and `TriggerSweeper` sees it gone. `put()` of an entity whose row is marked behaves as after a `DELETE`: a versioned copy throws `OptimisticLockException`, an unversioned one is written back and visible again. `toWhereClause()` does not show the soft delete condition.
- **Hooks** belong to the repository (the entity stays a plain object) and run inside the write's transaction: an exception in a `before*` or an `after*` hook rolls the write back and propagates. `beforePut` runs before the identifier is generated and the timestamps are set. With a remove hook, `removeBy()` loads the matching entities and removes them one by one, so a hook is never bypassed; without one it stays one statement.
- **SQL log.** `IQueryLogger::log(string $sql, int $parameterCount, float $milliseconds, ?array $parameters)` receives every statement after it ran or failed; the values are passed only with `withQueryLogger($logger, logParameters: true)`. An exception thrown by a logger is ignored. `InMemoryQueryLogger` keeps at most 10 000 entries by default (`entries()`, `statements()`, `clear()`).
- **Booleans.** `BooleanStorage::Integer`, per column (`#[Column(booleanAs: ...)]`) or as the default of the mapping, writes 1/0 on every driver, PostgreSQL included (the `SMALLINT` flags of legacy schemas), and a specification compiles to `"urgent" = 1`; reading accepts both forms. `BooleanStorage::Char` writes text, `'Y'`/`'N'` unless `trueValue:`/`falseValue:` say otherwise (on the column, or on `#[Table]`/`EntityMapping::booleanAs()` as the default; 1 to 16 letters or digits, different whatever the case). It reads the two texts in any case (and without the spaces a `CHAR` column pads with) and `NULL` as `null`; any other stored value throws `DataIntegrityException` with the column and the value. A comparison compiles to every case form of the text (`IN ('Y', 'y')`; `LOWER(col) = 'false'` for a text of more than two letters), so the database finds exactly the rows a read accepts, and the null semantics of 1.11.0 still hold. `Native` stays the default.
- **`extract()` and `hydrate()`.** `EntityMapping::extract($entity)` gives the row of an entity (column => property value) and `hydrate($row)` builds an entity from one without calling its constructor, both without a database: the `toArray()`/`fromArray()` of an active record.

### 17.2. Batch Writes and Set-Based Operations (Module 25)

Writes and reads over many rows that do not build one entity at a time: a multi-row `INSERT`, an `UPDATE` by specification, a projection into arrays, grouped aggregates computed by the database and a subquery over another repository.

```php
use Antevemus\ASpecification\Entities\AbstractUlidEntity;

#[Table('sale')]
#[Id(strategy: IdStrategy::Ulid, column: 'id', property: 'entityId')]   // generated in PHP: the rows go in one INSERT
class Sale extends AbstractUlidEntity
{
    public function __construct(
        #[Column('product_id')] public int $productId,
        #[Column] public ?string $region,
        #[Column(type: ColumnType::Decimal)] public string $amount,
        #[Column] public int $quantity = 1,
    ) {
        parent::__construct();
    }
}

$pdo->exec('CREATE TABLE sale (id CHAR(26) PRIMARY KEY, product_id INTEGER NOT NULL, region VARCHAR(10),
    amount TEXT NOT NULL, quantity INTEGER NOT NULL)');
$log->clear();
$sales = PdoRepository::forEntity($pdo, Sale::class)->withQueryLogger($log);

$sales->insertAll([                               // new entities, without the existence SELECT of put()
    new Sale(1, 'north', '139.90'),
    new Sale(1, 'south', '279.80', quantity: 2),
    new Sale(2, 'north', '59.90'),
    new Sale(3, null, '19.90'),
]);                                               // 4
count($log->statements());                        // 1: one multi-row INSERT

$sales->updateWhere(Spec::property('region', Spec::isNull()), ['region' => 'west']);   // 1: one UPDATE, no entity loaded

iterator_to_array($sales->select(Spec::property('region', Spec::equalTo('north')), ['productId', 'amount']), false);
// [['productId' => 1, 'amount' => '139.90'], ['productId' => 2, 'amount' => '59.90']]: arrays, no entity built

$sales->aggregate(Spec::alwaysTrue())->groupBy('region')->sum('amount')->sum('quantity')->count()->get();
// [['region' => 'north', 'sum_amount' => '199.8', 'sum_quantity' => 2, 'count' => 2],
//  ['region' => 'south', 'sum_amount' => '279.8', 'sum_quantity' => 2, 'count' => 1],
//  ['region' => 'west', 'sum_amount' => '19.9', 'sum_quantity' => 1, 'count' => 1]]

// The sales of active products ($products of example 17): product_id IN (SELECT id FROM product WHERE ...)
$sales->count(Spec::inQuery('productId', $products, Spec::property('active', Spec::isTrue()), 'entityId'));   // 3
```

- **`insertAll()`** writes new entities in one transaction, in batches of `withBatchSize()` rows (1 000 by default) that never pass the driver's parameter limit; a row that already exists fails on its key and nothing of the call is kept. Identifiers generated in PHP go in the batch; `auto` identifiers come back by `RETURNING` on PostgreSQL and are inserted row by row on the other drivers (MySQL does not guarantee consecutive values for a multi-row `INSERT`). Measured with 10 000 entities: about 3 times faster than `put()` row by row in SQLite, 20 to 40 times in PostgreSQL and MySQL. **`putAll()`** keeps the upsert, but reads the existence of a whole batch with one `SELECT ... IN`.
- **`updateWhere()`** converts each value by the type of its column and refuses the identifier, the version counter, the lifecycle columns and unmapped properties before any SQL. It stamps `#[UpdatedAt]`, increments `#[Version]` (a copy loaded before becomes stale) and touches only live rows. It runs **no per-entity hook**, and `TriggeringRepository` does not see it (a `TriggerSweeper` notices the changes): use `putAll()` when hooks or triggers must run. On MySQL the returned count is the rows whose values changed (unless the connection sets `PDO::MYSQL_ATTR_FOUND_ROWS`), and MySQL refuses a subquery on the table being updated.
- **`select()`** is a generator of arrays keyed by property name, each value converted like a read value; **`aggregate()`** is immutable (`groupBy()`, `count()`, `sum()`, `avg()`, `min()`, `max()`, each with an optional alias) and follows SQL's null rules: `sum`/`avg`/`min`/`max` ignore `NULL`, the sum of nothing is `null`, not 0, `count()` counts rows and `count('prop')` non-null values, the `NULL` group comes first. A `Decimal` column gives canonical decimal text (`'199.8'`, not `'199.80'`; SQLite has no decimal arithmetic and computes in floating point, rounded to 15 significant digits). Both apply the read scope (soft delete); an aggregate needs a translatable specification even with the in-memory fallback.
- **`Spec::inQuery($property, $other, $where, $otherProperty)`** compiles to `col IN (SELECT othercol FROM other WHERE ... AND othercol IS NOT NULL)` when both repositories share the connection, with the read scope of `$other`; otherwise it cannot be translated. In memory the subquery runs once per instance of the specification and its values are kept: build a new one to see newer data. `whereRelation()` (example 17.3) builds it from a relationship.

### 17.3. Relationships: References, Compositions and Eager Loading (Module 26)

Relationships are declared on the entity and loaded **explicitly**, never lazily and without proxies. A reference (`#[ManyToOne]`) is an aggregation: it is never saved with the entity. A collection (`#[OneToMany]`) is read-only unless `cascade: true` makes it a composition, saved and removed with its parent. An association (`#[ManyToMany]`) goes through a join table: saving the owner writes only the links. The repositories of the related classes are given with `withRelated()`.

```php
use Antevemus\ASpecification\Repositories\Pdo\Mapping\ManyToOne;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\OneToMany;

#[Table('customer')]
class Customer extends AbstractEntity
{
    public function __construct(
        #[Id(strategy: IdStrategy::Assigned)] private int $id,
        #[Column] public string $name,
    ) {
        parent::__construct();
    }

    public function getEntityId(): int
    {
        return $this->id;
    }
}

#[Table('orders')]
#[SoftDelete('deleted_at')]
class Order extends AbstractEntity
{
    #[Id(strategy: IdStrategy::Auto)]
    private ?int $id = null;

    #[Column('customer_id')] public ?int $customerId = null;                       // the stored truth of the reference
    #[ManyToOne(Customer::class, 'customer_id')] public ?Customer $customer = null;   // aggregation: never cascaded

    /** @var list<OrderLine> */
    #[OneToMany(OrderLine::class, mappedBy: 'orderId', cascade: true)] public array $lines = [];   // composition

    public function __construct(#[Column] public string $number)
    {
        parent::__construct();
    }

    public function getEntityId(): ?int
    {
        return $this->id;
    }
}

#[Table('order_line')]
class OrderLine extends AbstractEntity
{
    #[Id(strategy: IdStrategy::Auto)]
    private ?int $id = null;

    #[Column('order_id')] public ?int $orderId = null;   // set by the cascade

    public function __construct(
        #[Column] public string $sku,
        #[Column] public int $quantity,
    ) {
        parent::__construct();
    }

    public function getEntityId(): ?int
    {
        return $this->id;
    }
}

$pdo->exec('CREATE TABLE customer (id INTEGER PRIMARY KEY, name VARCHAR(80) NOT NULL)');
$pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY AUTOINCREMENT, number VARCHAR(20) NOT NULL,
    customer_id INTEGER REFERENCES customer (id), deleted_at TEXT)');
$pdo->exec('CREATE TABLE order_line (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL REFERENCES orders (id),
    sku VARCHAR(20) NOT NULL, quantity INTEGER NOT NULL)');

$customers = PdoRepository::forEntity($pdo, Customer::class);
$lines = PdoRepository::forEntity($pdo, OrderLine::class);
$orders = PdoRepository::forEntity($pdo, Order::class)->withRelated($customers, $lines);

$customers->putAll([new Customer(1, 'Ana'), new Customer(2, 'Bruno')]);
foreach (range(1, 100) as $n) {
    $order = new Order("PO-{$n}");
    $order->customer = $customers->findById($n % 2 + 1);   // a reference needs an identifier: put it first
    $order->lines = [new OrderLine('KB-1', 1), new OrderLine('MS-2', $n)];
    $orders->put($order);                         // the order, then its lines (order_id set), in one transaction
}
```

Eager loading is a view, `with()`; each relation costs one query per batch of entities, never one per entity:

```php
$log->clear();
foreach ([$orders, $customers, $lines] as $repository) {
    $repository->withQueryLogger($log);
}
$all = $orders->with('lines', 'customer')->find(Spec::alwaysTrue());
count($all);                                      // 100
count($log->statements());                        // 3: the orders, then their lines and their customers by IN (...)
$all[0]->customer->name;                          // 'Bruno'
$all[0]->customer === $all[2]->customer;          // true: one instance per customer
count($all[0]->lines);                            // 2

// The filter through a relationship is a subquery on the foreign key
$orders->count($orders->whereRelation('customer', Spec::property('name', Spec::equalTo('Ana'))));   // 50
$orders->count($orders->whereRelation('lines', Spec::property('quantity', Spec::greaterThan(95))));   // 5
```

A collection that was not loaded is never taken as empty, and only a loaded one has orphans:

```php
$first = $orders->with('lines')->findById(1);
array_pop($first->lines);                         // a loaded collection: the line that left it is an orphan
$orders->put($first);                             // ...and is deleted
$lines->count(Spec::property('orderId', Spec::equalTo(1)));   // 1

$bare = $orders->findById(1);                     // no with(): lines and customer not loaded
$orders->isLoaded($bare, 'lines');                // false: [] here means "unknown", not "none"
$orders->put($bare);                              // removes no line, keeps customer_id
$lines->count(Spec::property('orderId', Spec::equalTo(1)));   // 1

$orders->remove($first);                          // Order has #[SoftDelete]: marked; OrderLine has none, its rows stay
$lines->count(Spec::property('orderId', Spec::equalTo(1)));   // 1
$orders->forceRemove($first);                     // a hard delete removes every line row first
$lines->count(Spec::property('orderId', Spec::equalTo(1)));   // 0
```

An association is never a composition: the targets live on their own, and a loaded collection only adds and removes links:

```php
use Antevemus\ASpecification\Repositories\Pdo\Mapping\ManyToMany;

#[Table('tag')]
class Tag extends AbstractEntity
{
    public function __construct(
        #[Id(strategy: IdStrategy::Assigned)] private int $id,
        #[Column] public string $name,
    ) {
        parent::__construct();
    }

    public function getEntityId(): int
    {
        return $this->id;
    }
}

#[Table('article')]
class Article extends AbstractEntity
{
    #[Id(strategy: IdStrategy::Auto)]
    private ?int $id = null;

    /** @var list<Tag> */
    #[ManyToMany(Tag::class, 'article_tag', joinColumn: 'article_id', inverseJoinColumn: 'tag_id')]
    public array $tags = [];                          // association: only the links are written

    public function __construct(#[Column] public string $title)
    {
        parent::__construct();
    }

    public function getEntityId(): ?int
    {
        return $this->id;
    }
}

$pdo->exec('CREATE TABLE tag (id INTEGER PRIMARY KEY, name VARCHAR(30) NOT NULL)');
$pdo->exec('CREATE TABLE article (id INTEGER PRIMARY KEY AUTOINCREMENT, title VARCHAR(80) NOT NULL)');
$pdo->exec('CREATE TABLE article_tag (article_id INTEGER NOT NULL REFERENCES article (id),
    tag_id INTEGER NOT NULL REFERENCES tag (id), PRIMARY KEY (article_id, tag_id))');

$tags = PdoRepository::forEntity($pdo, Tag::class);
$articles = PdoRepository::forEntity($pdo, Article::class)->withRelated($tags);
[$php, $sql, $orm] = [new Tag(1, 'php'), new Tag(2, 'sql'), new Tag(3, 'orm')];
$tags->putAll([$php, $sql, $orm]);                // the targets are written by their own repository

$article = new Article('Specifications over PDO');
$article->tags = [$php, $sql];
$articles->put($article);                         // the article, then two rows of article_tag

$log->clear();
$tags->withQueryLogger($log);
$articles->withQueryLogger($log);
$loaded = $articles->with('tags')->findById(1);
count($log->statements());                        // 3: the article, its links, its tags
$loaded->tags = [$sql, $orm];
$articles->put($loaded);                          // unlinks php, links orm: no tag row is written
$articles->count($articles->whereRelation('tags', Spec::property('name', Spec::equalTo('orm'))));   // 1
```

A collection built by hand can be declared loaded, and `iterate()` loads relations page by page:

```php
$edited = $articles->findById(1);                 // no with(): tags not loaded, put() would not touch them
$edited->tags = [$php];
$articles->markLoaded($edited, 'tags');           // DANGER: declared WHOLE, what it lacks is removed
$articles->put($edited);                          // links php, unlinks sql and orm
count($articles->with('tags')->findById(1)->tags);   // 1

$view = $articles->with('tags')->withBatchSize(500);
foreach ($view->iterate(Spec::alwaysTrue()) as $one) {
    $one->tags[0]->name;                          // 'php': pages of 500 by id, each read before its tags load
}
```

- **Eager loading.** `with('lines', 'customer', 'lines.product')` returns a view whose reads (`find*()`, `iterate*()`, `findSingle()`, `findPage()`, `findById()`) load those relations for each batch of `withBatchSize()` entities with one `SELECT ... WHERE fk IN (...)` per relation (two for an association: its join table, then its targets; more only past the driver's parameter limit), in the read scope of the related repository (its soft-removed rows are not loaded). A dotted path goes as deep as the relations go, one more query per segment and batch. With 1 000 orders of 10 items each: 2 queries for `with('items')` and 4 for `with('items.product', 'customer')`, against 1 001 for a loop per order, in SQLite, PostgreSQL and MySQL.
- **Not loaded is not empty.** A relation that was not loaded keeps its default (`[]`, `null`) and the repository knows it (`isLoaded()`; the state lives outside the entity). `put()` of a parent whose collection was not loaded removes no child, and a reference that was not loaded never clears the foreign key: only a reference loaded non-null and then set to `null` clears it.
- **References** are never cascaded: `put()` writes the identifier of the referenced object into the foreign key property and refuses an object without one.
- **Compositions.** `put()`, `putAll()` and `insertAll()` write the children after their parent, in its transaction (same connection required), setting the foreign key (and the child's back reference when `mappedBy` names one). Orphans are removed only when the collection was loaded (or the parent was just inserted), and only the children loaded with it that still belong to it, each with its version checked: a child added by someone else in between is never removed. Moving a child between two loaded parents is safe within one `putAll()`.
- **Removing a parent** removes its children first. A parent with `#[SoftDelete]` marks the children that have `#[SoftDelete]` and keeps the others (its row stays, so a revival finds them); a hard delete (`forceRemove()`, or a parent without soft delete) deletes every child row, marked or not, since no child row may outlive its parent's.
- **Domain events.** Register a `TriggeringRepository` with `withRelated()` and the cascades and orphans go through it, publishing the children's entries and exits (it reads through its `PdoRepository`); a bare `PdoRepository` publishes nothing and a `TriggerSweeper` notices. A `TriggeringRepository` over the parent reads its "before" without relations: its triggers should read columns.
- **`whereRelation('customer', $spec)`** is `customer_id IN (SELECT id FROM customer WHERE ...)` and **`whereRelation('lines', $spec)`** is `id IN (SELECT order_id FROM order_line WHERE ...)`, with the related repository's read scope; the foreign key property must be readable by a specification. A relation property is not a column: a specification cannot read it.
- **Associations.** `#[ManyToMany(Tag::class, 'article_tag', joinColumn: 'article_id', inverseJoinColumn: 'tag_id')]` (or `EntityMapping::manyToMany()`); the table and column names come only from the mapping, validated as identifiers, and the target repository must use the same connection. `put()`, `putAll()` and `insertAll()` of an owner whose collection was loaded (or that was just inserted) read its stored links once per batch, insert the new ones (multi-row) and delete those of the targets that left the collection (only targets loaded with it), never writing or deleting a target; a target without an identifier is refused. A collection that was **not** loaded never touches the join table, even when it holds targets. A soft-removed target is left out of a loaded collection and its link stays. A hard removal of the owner deletes its links first; a soft removal keeps them. `whereRelation('tags', $spec)` is `id IN (SELECT article_id FROM article_tag WHERE tag_id IN (SELECT id FROM tag WHERE ...))`. With 1 000 owners of 5 targets each, `with('tags')` is 3 queries in SQLite, PostgreSQL and MySQL.
- **`markLoaded($entity, 'lines', 'tags')`** declares collections built by hand (from a form, a cache) as loaded and **whole**: the next `put()` removes the orphans (`#[OneToMany]` composition) or the links (`#[ManyToMany]`, soft-removed targets aside) that the collection lacks, relative to what it holds at that `put()`. **Declaring an incomplete collection deletes what it lacks**, including a child or link someone else added: a collection loaded by `with()` never removes those. After the write the collection is loaded as usual. `markUnloaded()` makes a collection say nothing again.
- **`iterate()` with relations** pages by key, `WHERE ... AND id > :last ORDER BY id LIMIT batch`: each page is read whole and its cursor closed before its relations load, so it works on MySQL unbuffered queries (`PDO::MYSQL_ATTR_USE_BUFFERED_QUERY = false`) and holds one page of entities at a time (10 000 rows with `with('tags')` in pages of 500: about 1 MB at the peak, against 20 MB for `find()`, in SQLite). `find*()` and `findPage()` finish their read before loading. Without relations `iterate()` streams one cursor, as before.

### 18. Domain Events From Specifications (Module 23)

A trigger binds a specification to domain events: when an entity **starts** to satisfy it, the trigger emits its enter event; when it **stops**, its exit event. `TriggeringRepository` decorates any `IRepository`: on `put()`/`update()`/`updateWithDelta()` it reads the stored state of the entity by its identity, writes, evaluates every trigger on the state before and after, and publishes the crossings; on `remove()`/`removeBy()` it emits the exits. The production publisher writes to an outbox in the same transaction as the write, and `DomainEventDispatcher` delivers it after the commit, as the effects of the state machine.

```php
use Antevemus\ASpecification\DomainEvents\DomainEvent;
use Antevemus\ASpecification\DomainEvents\DomainEventDispatcher;
use Antevemus\ASpecification\DomainEvents\DomainEventHandlerRegistry;
use Antevemus\ASpecification\DomainEvents\Outbox\InMemoryDomainEventOutbox;
use Antevemus\ASpecification\DomainEvents\OutboxDomainEventPublisher;
use Antevemus\ASpecification\DomainEvents\SpecificationTrigger;
use Antevemus\ASpecification\DomainEvents\TriggeringRepository;
use Antevemus\ASpecification\DomainEvents\TriggerSet;
use Antevemus\ASpecification\Entities\AbstractEntity;
use Antevemus\ASpecification\Repositories\InMemoryRepository;
use Antevemus\ASpecification\Spec;

class Policy extends AbstractEntity
{
    public int $daysOverdue = 0;
    public bool $documentsValidated = false;
    public string $holderName = 'Maria Silva';    // personal data: never copied into an event

    public function __construct(private string $number)
    {
        parent::__construct();
    }

    public function getEntityId(): string
    {
        return $this->number;
    }
}

$triggers = new TriggerSet(
    new SpecificationTrigger('policy.delinquent', Spec::property('daysOverdue', Spec::greaterThan(30)),
        onEnter: 'delinquency.started', onExit: 'delinquency.ended'),
    new SpecificationTrigger('policy.documents', Spec::property('documentsValidated', Spec::isTrue()),
        onEnter: 'documents.validated'),
);

$outbox = new InMemoryDomainEventOutbox();        // PdoDomainEventOutbox in production
$policies = new TriggeringRepository(new InMemoryRepository(), $triggers, new OutboxDomainEventPublisher($outbox));

$policy = new Policy('P-1');
$policies->put($policy);                          // satisfies no trigger: no event
$policy->daysOverdue = 45;
$policies->update($policy);                       // starts to satisfy policy.delinquent
$policies->update($policy);                       // no crossing, no event
$policy->daysOverdue = 0;
$policies->update($policy);                       // stops satisfying it
$outbox->count();                                 // 2

$event = $outbox->findByEntity(Policy::class, 'P-1')[0]->event;
$event->name;                                     // 'delinquency.started'
$event->getPayload();                             // ['trigger' => 'policy.delinquent', 'entity_id' => 'P-1']

// After the commit (a worker or a cron): every event to its handlers, in write order per entity
$seen = [];
$handlers = (new DomainEventHandlerRegistry())
    ->register('delinquency.started', function (DomainEvent $event, string $idempotencyKey) use (&$seen): void { $seen[] = $event->name; })
    ->register('delinquency.ended', function (DomainEvent $event, string $idempotencyKey) use (&$seen): void { $seen[] = $event->name; });
(new DomainEventDispatcher($outbox, $handlers))->dispatchPending()->deliveredCount();   // 2
$seen;                                            // ['delinquency.started', 'delinquency.ended']
```

An event can move a state machine (example 14): `MachineEventBridge` is a ready-made handler that sends it to a `MachineRunner` as an internal event carrying the domain event's idempotency key, so a redelivery returns the original result and does not move the instance again.

```php
use Antevemus\ASpecification\DomainEvents\MachineEventBridge;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\InMemoryMachineDefinitionRepository;
use Antevemus\ASpecification\StateMachine\Persistence\MachinePersistence;
use Antevemus\ASpecification\StateMachine\Runner\MachineRunner;

// "Every document validated" moves the review without a click
$review = Spec::machine('policy_review', 1)
    ->region('lifecycle', 'waiting_documents')
        ->state('waiting_documents')
            ->on('VALIDATE', 'under_review')
        ->finalState('under_review')
    ->build();
$runner = new MachineRunner(Spec::stateMachine(), new InMemoryMachineDefinitionRepository([$review]), MachinePersistence::inMemory());
$runner->start('review-P-1', 'policy_review', 1, null, new DateTimeImmutable('2026-10-11 09:00'));

(new MachineEventBridge($runner, ['documents.validated' => 'VALIDATE'], fn(DomainEvent $e): ?string => 'review-' . $e->entityId))
    ->registerIn($handlers);

$policy->documentsValidated = true;
$policies->update($policy);                       // documents.validated goes to the outbox
(new DomainEventDispatcher($outbox, $handlers))->dispatchPending()->deliveredCount();   // 1
$runner->load('review-P-1')->snapshot->stateOf('lifecycle');                           // 'under_review'
```

Over the `PdoRepository` of example 17 the row and its events commit together when the repository and the outbox share the connection and the transaction manager. The decorator then reads the "before" with `Spec::property('entityId', Spec::equalTo($id))`, compiled to SQL, without configuration:

```php
use Antevemus\ASpecification\DomainEvents\Outbox\PdoDomainEventOutbox;
use Antevemus\ASpecification\Persistence\PdoTransactionManager;

// Once, with your migration tool: resources/sql/domain_events.ansi.sql
$transactions = new PdoTransactionManager($pdo);  // one unit of work for the row and its events
$catalog = new TriggeringRepository(
    PdoRepository::forEntity($pdo, Product::class, $transactions),
    new TriggerSet(new SpecificationTrigger('product.withdrawn', Spec::property('active', Spec::isFalse()), onEnter: 'product.withdrawn')),
    new OutboxDomainEventPublisher($events = new PdoDomainEventOutbox($pdo)),
    $transactions,
);

$mouse = $catalog->findSingle(Spec::property('name', Spec::equalTo('Mouse')));
$mouse->active = false;
$catalog->update($mouse);                         // UPDATE product and INSERT INTO domain_event_outbox, one commit
$events->count();                                 // 1
```

- **The payload is minimal on purpose**: the trigger name and the entity id, never the entity, so no personal data reaches a queue or a log; a handler that needs more reads the entity by its id. Each event carries a deterministic idempotency key (`de:` + SHA-256 of trigger, entity class, entity id, kind and instant): the outbox stores a crossing once, so re-applying the same write does not duplicate it.
- **Reading "before" costs one read per write** (a scan in the in-memory repositories). Over a `PdoRepository` the identity lookup is the SQL one shown above; for another repository that translates specifications to SQL, pass `identitySpecification: TriggeringRepository::byIdProperty('id')`. The in-memory repositories store the caller's object, so the decorator compares with the states it recorded at the last write of that object through it. **Known limitation:** on the in-memory repositories, an entity stored before the decorator existed (or written to the inner repository directly) and then mutated in place has no "before", and that write emits nothing: write through the decorator from the start. With a trigger state store (example 18.1) the "before" is the recorded state, and the limitation is gone for every entity that has one.
- **Delivery** is at-least-once, in write order per entity: handlers deduplicate by the idempotency key they receive. A failing handler never undoes the write; the entry is marked failed (no stack trace) and retried, holding that entity's later events back until it succeeds or, with `maxAttempts`, reaches the limit. An event without a handler is a failed attempt, not a silent drop.
- **Errors:** a specification that throws while being evaluated emits no event; by default the write goes on and the error goes to an `IDomainEventErrorReporter`, and with `failOnEvaluationError: true` the write throws instead. A failure of the publisher (the outbox) always propagates.
- **A crossing caused by time alone**, with no write (a policy that becomes overdue at midnight while nobody writes it), is not seen by the decorator: that is the time-based sweeper of example 18.1. Event sourcing and a message bus client are out of scope: publish to Kafka or RabbitMQ from a handler.

### 18.1. Domain Events From the Passage of Time (Module 24)

"Unpaid and due more than 30 days ago" turns true at midnight while nobody writes the invoice: only the clock moved, so the write-time triggers of example 18 never see it. A **time-based trigger** builds its specification from "now"; a **state store** keeps, for every trigger and entity, the last known answer (`satisfied`, `evaluated_at`); a **sweeper**, run by your cron with the instant injected, compares the two, records each crossing and publishes it through the same publisher and outbox, with the same kind of idempotency key. `TriggeringRepository` keeps the same store on every write, so a write and a sweep never publish the same crossing twice.

```php
use Antevemus\ASpecification\DomainEvents\State\InMemoryTriggerStateStore;
use Antevemus\ASpecification\DomainEvents\TriggerSweeper;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Column;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Id;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\IdStrategy;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Table;

#[Table('invoice')]                               // mapped for the PDO block below; used as is in memory
class Invoice extends AbstractEntity
{
    #[Column] public bool $paid = false;

    public function __construct(
        #[Id(strategy: IdStrategy::Assigned)] private string $number,
        #[Column('due_date')] public DateTimeImmutable $dueDate,
    ) {
        parent::__construct();
    }

    public function getEntityId(): string
    {
        return $this->number;
    }
}

$overdue = new TriggerSet(SpecificationTrigger::timed(
    'invoice.overdue',
    fn(DateTimeImmutable $now) => Spec::property('paid', Spec::isFalse())
        ->and(Spec::property('dueDate', Spec::lessThan($now->modify('-30 days')))),
    onEnter: 'collection.started',
    onExit: 'collection.ended',
    crossedAt: fn(Invoice $invoice) => $invoice->dueDate->modify('+30 days'),   // the real instant of the crossing
));

$today = new DateTimeImmutable('2026-01-01');
$clock = function () use (&$today): DateTimeImmutable { return $today; };   // the system clock by default

$states = new InMemoryTriggerStateStore();        // PdoTriggerStateStore in production
$outbox = new InMemoryDomainEventOutbox();
$repository = new InMemoryRepository();
$invoices = new TriggeringRepository($repository, $overdue, new OutboxDomainEventPublisher($outbox), clock: $clock, states: $states);
$sweeper = new TriggerSweeper($repository, Invoice::class, $overdue, $states, new OutboxDomainEventPublisher($outbox));

$invoice = new Invoice('INV-1', new DateTimeImmutable('2026-01-05'));
$invoices->put($invoice);                         // due in 4 days: no event; its state is recorded
$sweeper->sweep(new DateTimeImmutable('2026-01-20'))->enteredCount();   // 0
$sweeper->sweep(new DateTimeImmutable('2026-02-05'))->enteredCount();   // 1: nobody wrote it, time did
$sweeper->sweep(new DateTimeImmutable('2026-02-06'))->enteredCount();   // 0: already announced
$outbox->findByEntity(Invoice::class, 'INV-1')[0]->event->occurredAt == $invoice->dueDate->modify('+30 days');   // true

$today = new DateTimeImmutable('2026-02-09');
$invoice->paid = true;
$invoices->update($invoice);                      // collection.ended, published by the write
$sweeper->sweep(new DateTimeImmutable('2026-02-10'))->exitedCount();    // 0: the write already did
$outbox->count();                                 // 2
```

> ⚠️ **Introducing a trigger over existing data? Run `baseline()` first.** An entity without a recorded state counts as "not satisfied", so the first sweep publishes an enter for every invoice that is already overdue. That is the truth (they are inside and nobody was told), but rarely what a mailing wants. `baseline($now)` records the current state of every trigger for every entity and publishes nothing; the following sweeps publish only new crossings.

```php
// A trigger introduced over existing data: baseline() first, or the first sweep announces everything already inside
$archive = new InMemoryRepository([
    new Invoice('INV-2', new DateTimeImmutable('2025-11-03')),   // overdue since December
    new Invoice('INV-3', new DateTimeImmutable('2026-03-02')),
]);
$catchUp = new TriggerSweeper($archive, Invoice::class, $overdue, new InMemoryTriggerStateStore(), new OutboxDomainEventPublisher($outbox));
$catchUp->baseline(new DateTimeImmutable('2026-02-10'))->recordedCount();   // 2: one state per invoice, no event
$catchUp->sweep(new DateTimeImmutable('2026-02-11'))->enteredCount();      // 0: INV-2 was already inside
$catchUp->sweep(new DateTimeImmutable('2026-04-02'))->eventNames();        // ['collection.started']: INV-3, a new crossing

// The cron (every hour, every night): the instant is the cron's, the limit slices a large backlog
do {
    $report = $catchUp->sweep(new DateTimeImmutable(), limit: 500);
} while ($report->limitReached());
```

In production the store is a table, `domain_event_trigger_state`, in the same `resources/sql/domain_events.ansi.sql` (running the script again on a 1.11 database only adds it). Give the store, the outbox and the repository the same connection and transaction manager, so that the state of a crossing and its event commit together:

```php
use Antevemus\ASpecification\DomainEvents\State\PdoTriggerStateStore;

// $pdo of example 17; once, with your migration tool: the invoice table and resources/sql/domain_events.ansi.sql
$transactions = new PdoTransactionManager($pdo);
$repository = PdoRepository::forEntity($pdo, Invoice::class, $transactions);
$states = new PdoTriggerStateStore($pdo);         // table domain_event_trigger_state
$publisher = new OutboxDomainEventPublisher($events = new PdoDomainEventOutbox($pdo));
$invoices = new TriggeringRepository($repository, $overdue, $publisher, $transactions, states: $states);
$sweeper = new TriggerSweeper($repository, Invoice::class, $overdue, $states, $publisher, $transactions);

$invoices->put(new Invoice('INV-4', new DateTimeImmutable('2026-01-05')));   // overdue already: the write publishes the enter
$sweeper->sweep(new DateTimeImmutable())->enteredCount();                   // 0: its state says inside
count($events->findByEntity(Invoice::class, 'INV-4'));                      // 1
```

- **How a sweep finds the crossings, and what it costs.** Per time-based trigger, the states recorded as inside are read in pages of 500 (by entity id) and, per page, one `COUNT(*)` of `spec(now) AND id IN (page)` tells whether they are all still inside; only when they are not are those entities loaded (a removed one is an exit). Then, when the number confirmed inside equals the `COUNT(*)` of `spec(now)`, nothing can enter and the entry pass is skipped; otherwise the entities satisfying `spec(now)` are streamed (in SQL with a `PdoRepository`) and those not recorded inside are entries. A sweep that finds no crossing loads no entity: with 10 000 invoices in SQLite, 5 000 of them overdue, it is 22 statements, a few tens of milliseconds, and the same memory peak (one page) as with 1 000 invoices. The SQL only narrows: each crossing is confirmed in PHP on the loaded entity, as on a write.
- **One transaction per crossing, compare-and-set on the state.** The state is written only if it is still at the version the sweep read, and the event is published only when that write won, so two sweepers (two cron hosts, an overlapping run) or a sweeper and a write racing on one crossing publish it once; the lost one is counted in `conflictCount()`. The outbox's unique idempotency key is the second barrier. Two sweepers are correct together, not faster: the work is not partitioned between them, and there is no embedded scheduler (the cron is yours, as with `deliverDue()` of the state machine).
- **The instant.** An event found by a sweep is stamped with the sweep's `$now`, unless the trigger declares `crossedAt` (it receives the entity, `$now` and the kind of crossing): its instant is used when it lies after the last recorded evaluation and not after `$now`, which keeps the idempotency key the same in every sweep and every process, while a re-entry after an exit still gets a key of its own. A crossing seen by a write carries the write's instant.
- **`limit`** bounds the crossings handled by one call: loop while `limitReached()`. `SweepReport` gives the counts, the events, the lost compare-and-sets, the evaluation errors (also sent to the `IDomainEventErrorReporter`) and two cost counters (`entitiesLoaded()`, `statesRead()`).
- **Limits:** one sweeper per concrete entity class (the states are keyed by it; an entity of another class found in the repository is reported, never recorded); on MySQL with unbuffered queries, give the sweeper a repository on its own connection (it reads states while the entity cursor is open); the plain triggers of the set are not swept (they only change on writes), but `baseline()` records them too.

---

## 🏗️ Directory Structure

```
src/
├── Attributes/                # PHP 8 Declarative Attributes Engine (Module 15)
│   ├── AssertSpec.php        # Specification reference attribute
│   ├── ValidateRule.php      # Inline rule attribute
│   ├── AttributeValidator.php # High-performance reflection evaluator
│   ├── Specifiable.php       # Opt-in: non-public property reachable by name (v1.6.1)
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
│   ├── SpecificationSimplifier.php # simplify(): an equivalent, smaller tree (v1.9.0)
│   ├── Comparison/           # Equal, GreaterThan, LessThan, RelationalOperator
│   ├── Logical/              # AlwaysTrue, AlwaysFalse, JointDenial (NOR), DefaultValue
│   ├── String/               # Regex, Wildcard, DateString, EnumName
│   ├── Collection/           # CollectionSpecification, AllEntities, Unique
│   └── Reflection/           # MethodParameterizedSpecification (declarative method call; field access is where())
├── Repositories/              # Concrete repositories and DAG partitioning
│   ├── File/                 # SingleFileRepository, FilePerEntityRepository
│   ├── Pdo/                  # PdoRepository; Mapping/ (Table, Column, Id, Version, EntityMapping, IdStrategy, ColumnType) (Module 22, v1.11.0)
│   │                         # v1.13.0: PdoAggregateQuery, InQuerySpecification; Mapping/ CreatedAt, UpdatedAt, SoftDelete, BooleanStorage, ManyToOne, OneToMany, RelationMapping (Modules 25 and 26)
│   │                         # v1.14.0: JoinTableHop; Mapping/ ManyToMany; Exceptions/ DataIntegrityException (Modules 25 and 26)
│   └── Serialization/        # JsonEntitySerializer (the PHP-native serializer was removed in v2.0.0)
├── Concurrent/                # SemaphoreSynchronizer, FileLockSynchronizer
├── Engine/                    # DynamicSpecificationEngine, RuleEngineVerdict, RuleCompilationWarning, PdoRuleCatalog, Builders
│   ├── Exceptions/           # RuleEngineException, MissingRuleHandlerException, LegacyVocabularyException (v2.0.0)
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
│   ├── Exceptions/           # NonTranslatableLinqException (v1.10.0)
│   ├── ALinqBridge.php       # Bridge between repositories and ALinq collections
│   └── ALinqSpecificationVisitor.php # AST compiler for short-circuit functional predicates
├── Factory/                   # Unified SpecificationFactory (~140 methods)
│   └── Traits/               # 8 Modular Traits (Type, Comparison, Logical, Special, String, Date, Collection, Wrapper)
├── Results/                   # SpecificationResult, SpecificationFailure, FailureSeverity, IOutcome
├── StateMachine/              # Spec-driven state machine (Modules 17 and 18): builder, definition, validator, pure core
│   ├── Persistence/          # Ports, MachinePersistence, InMemory/ and Pdo/ stores (resources/sql/state_machine.ansi.sql)
│   └── Runner/               # MachineRunner, EffectDispatcher, EffectHandlerRegistry, migrations
├── UseCase/                   # IUseCase, UseCaseRunner, UseCaseResult, UseCaseStatus, middlewares (Module 19)
├── Persistence/               # ITransactionManager, PdoTransactionManager: the transaction port shared by the repository, outboxes and machine stores (v1.11.0); IQueryLogger, InMemoryQueryLogger (v1.13.0)
├── DomainEvents/              # Domain events from specifications (Module 23, v1.11.0) and from the passage of time (Module 24, v1.12.0)
│   ├── Outbox/               # IDomainEventOutbox, InMemory and Pdo outboxes (resources/sql/domain_events.ansi.sql)
│   ├── State/                # ITriggerStateStore, InMemory and Pdo stores of the last known state of each trigger (v1.12.0)
│   ├── SpecificationTrigger.php # A specification bound to an enter and an exit event (timed(): built from "now"); TriggerSet, TriggerEvaluator
│   ├── TriggeringRepository.php # IRepository decorator that publishes the crossings of every write
│   ├── TriggerSweeper.php    # sweep() for the crossings caused by time alone, baseline(); SweepReport (v1.12.0)
│   ├── DomainEventDispatcher.php # Delivery after the commit; DomainEventHandlerRegistry
│   └── MachineEventBridge.php # A domain event sent to a MachineRunner
├── Resolution/                # Concurrent data resolution (Module 21, v1.10.0)
│   ├── Exceptions/           # DataResolutionException, DataFetchException, DataSourceNotFoundException
│   ├── ExternalDataSpecification.php # Spec::external(): the leaf that declares external data
│   ├── DataResolver.php      # resolve()/evaluate(); ResolutionPolicy concurrent()/tiered()/sequential()
│   ├── HttpDataSource.php    # URL template with encoded parameters, fetched through curl_multi
│   └── CallableDataSource.php # Any synchronous callable; DataSourceRegistry, IDataCache, InMemoryDataCache
├── Helpers/                   # PropertyAccessor (deprecated facade of antevemus/adeep-property-access, v2.0.0), StopWatch, InstrumentationUtils
├── DSL/                       # Global helper functions for fluent domain syntax
└── Spec.php                   # Unified static facade
```

---

## 🧪 Code Quality & Automated Tests

The library features comprehensive unit and integration test coverage with zero regressions:

```bash
php tests/run_all.php
```

Report below as printed by the runner, with its Portuguese labels translated; the duration depends on the machine. Module 22 also prints its benchmark (10 000 rows in SQLite) and, without the `ASPEC_PDO_*` variables, that its PostgreSQL and MySQL runs were skipped, Module 24 its sweeps over 1 000 and 10 000 rows, Module 25 its batch, `updateWhere()`, null/subquery and null/`Char` parity runs and Module 26 its eager loading benchmarks (1 000 parents × 10 children, 1 000 owners × 5 targets) and the peak memory of `iterate()` with relations over 2 000 and 10 000 rows; those lines are omitted here:

```text
====================================================================
 ANTEVEMUS ASPECIFICATION - MASTER TEST RUNNER & REGRESSION WATCH
====================================================================

• [SUITE] Module 1: Specifications & Boolean Algebra... ✅ PASS (285 assertions)
• [SUITE] Module 2: Entities & Domain Identifiers... ✅ PASS (59 assertions)
• [SUITE] Module 3: In-Memory Repositories & Base... ✅ PASS (90 assertions)
• [SUITE] Module 4: DAG Partitioning Architecture... ✅ PASS (94 assertions)
• [SUITE] Module 5: File Persistence & Hybrid Decorator... ✅ PASS (214 assertions)
• [SUITE] Module 6: Concurrency Utilities & RW-Lock... ✅ PASS (97 assertions)
• [SUITE] Module 7: Predicates, Factories, Helpers & Visitor... ✅ PASS (61 assertions)
• [SUITE] Module 8: Notification Pattern & SpecificationResult... ✅ PASS (151 assertions)
• [SUITE] Module 9: Facade Spec, Fluent Chaining & DSL... ✅ PASS (204 assertions)
• [SUITE] Module 10: Java Parity, Telemetry & Remainder... ✅ PASS (65 assertions)
• [SUITE] Module 11: Dynamic Rule Engine & Document Matrix... ✅ PASS (859 assertions)
• [SUITE] Module 12: SQL Query Visitor & Multi-DBMS Dialects... ✅ PASS (516 assertions)
• [SUITE] Module 13: TCriteria Builder & Adianti Database Bridge... ✅ PASS (209 assertions)
• [SUITE] Module 14: ALinq Synergy & Fluent LINQ Collections... ✅ PASS (1987 assertions)
• [SUITE] Module 15: Declarative PHP 8.4 Attributes (#[AssertSpec])... ✅ PASS (221 assertions)
• [SUITE] Module 16: Relational Rule Catalog (PdoRuleCatalog)... ✅ PASS (1252 assertions)
• [SUITE] Module 17: Specification-Driven State Machine... ✅ PASS (618 assertions)
• [SUITE] Module 18: State Machine Runner & Persistence... ✅ PASS (700 assertions)
• [SUITE] Module 19: Use Case & Shared Result (UseCaseResult)... ✅ PASS (408 assertions)
• [SUITE] Module 20: Specification Simplification (simplify)... ✅ PASS (116 assertions)
• [SUITE] Module 21: Concurrent Data Resolution (DataResolver)... ✅ PASS (864 assertions)
• [SUITE] Module 22: Repository Over PDO (PdoRepository)... ✅ PASS (571 assertions)
• [SUITE] Module 23: Domain Events From Specifications... ✅ PASS (331 assertions)
• [SUITE] Module 24: Domain Events From the Passage of Time... ✅ PASS (310 assertions)
• [SUITE] Module 25: PdoRepository Follow-ups (log, types, lifecycle, batch, set-based queries)... ✅ PASS (608 assertions)
• [SUITE] Module 26: PdoRepository Relationships (ManyToOne, OneToMany, ManyToMany, eager loading, composition)... ✅ PASS (355 assertions)

====================================================================
 FINAL RESULT: 26/26 SUITES PASSED (100% PASS)
 TOTAL ASSERTIONS: 11245 | DURATION: ~21s | REGRESSIONS: 0
====================================================================
```

- **Domian Parity suite:** `tests/Parity/` transcribes the Domian (Java) tests for the specification algebra, the partition DAG, the synchronizers, entities and utilities (219 PHPUnit tests, 2 106 assertions; `vendor/bin/phpunit` runs 247 tests and 13 384 assertions in total). The audit behind it is in `docs/PARIDADE-DOMIAN-2026-10-09.md`.
- **Public API Documentation:** 1,200+ methods documented via structured corporate PHPDoc blocks.
- **Java (Domian) Traceability:** conceptual and architectural parity with the upstream framework (public API and class names follow Domian; see the provenance table in [NOTICE.md](NOTICE.md)).
- **Modular Factory Architecture:** `SpecificationFactory` decomposed into 8 domain-specialized traits.
- **ALinq Synergy:** Native integration with [`Antevemus.AlinqCollection`](https://github.com/antevemus-it/Antevemus.AlinqCollection): the `ALinqSpecificationVisitor` compiler, the shared dot-notation accessor (`antevemus/adeep-property-access`) and the `InMemoryRepository` bridge methods.
- **Multi-DBMS Compatibility:** 15 drivers (10 SQL dialects) certified with proper identifier quoting and bound prepared statements. `PdoRepository` runs its contract battery in SQLite in the suite and, through `tests/Fixtures/pdo_repository/run_portability.sh`, against PostgreSQL 17 and MySQL 8.4 in disposable containers.
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
11a. **Specification-Driven State Machine** (the reserved `RequirementState`, now expressible as a machine) (Shipped in v1.8.0)
11b. **Use Cases With a Result Shared With the State Machine** (Shipped in v1.8.0)
12. **Specification `simplify()`** (Shipped in v1.9.0)
13. **PHP-Native Serializer Retirement, Step 2: Removal & JSON-Only Envelope** (persisted per-entity metadata) (Shipped in v2.0.0)
14. **Shared Property Accessor with ALinq** (`antevemus/adeep-property-access`) (Shipped in v2.0.0)
15. **Concurrent Data Resolution for I/O-Bound Specifications** (core shipped in v1.10.0)
15b. **Fibers/Revolt Adapter for Non-HTTP Sources** (satellite package `antevemus/aspecification-async`)
16. **Distributed Specification Cache** (PSR-6 / PSR-16 / Redis)
17. **GraphQL AST & OpenAPI 3.1 Query Compilers**
18. **Doctrine ORM & Laravel Eloquent Query Visitors**
19. **Specification-Driven Domain Event Triggers** (Shipped in v1.11.0)
19b. **Time-Based Domain Event Triggers** (a scheduled sweeper for crossings without a write) (Shipped in v1.12.0)
20. **AI-Assisted Specification Synthesizer**
21. **PDO-Backed Repository** (the Domian `HibernateRepository`) (Shipped in v1.11.0)
21b. **`PdoRepository` Follow-ups** (lifecycle columns and soft delete, hooks, SQL log, batch insert, bulk update by specification, projections and aggregates, subqueries, `ManyToOne`/`OneToMany` relationships with eager loading in batch) (Shipped in v1.13.0)
21c. **`PdoRepository` Follow-ups II** (`ManyToMany`, marking a hand-built collection as loaded, `iterate()` with relations over unbuffered MySQL, `CHAR(1)` booleans) (Shipped in v1.14.0)
22. **Accent-Insensitive Text Comparison** (an explicit option that ignores case and accents, with the same answer in memory and in SQL)
23. **Write-Behind Hybrid Repository** (the Domian `INMEMORY_AND_FILE` mode: memory now, disk on `persist()`)

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
