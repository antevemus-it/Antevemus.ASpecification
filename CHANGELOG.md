# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-10-04

### Added
- **Core Specification Pattern & Boolean Algebra**:
  - `ISpecification<TCandidate>` contract and `AbstractSpecification<TCandidate>` base class.
  - Boolean combinators: `AndSpecification`, `OrSpecification`, `NotSpecification`, `ConjunctionSpecification`, `DisjunctionSpecification`.
  - Composite operators: `and()`, `or()`, `not()`, `andNot()`, `orNot()`.
  - Identity specifications: `AllSpecification` (tautology / true) and `NoneSpecification` (contradiction / false).
- **Domain Entities, Identifiers & Value Objects**:
  - `IEntity<TId>` interface and `AbstractEntity<TId>` base class with identity encapsulation.
  - Strongly-typed `Identifier` supporting UUID v4, ULID, UUID v7, integer, and string representations.
  - `IEntitySpecification<TEntity>` for domain-driven rule validation against rich aggregate roots.
- **Repository Architecture**:
  - `IRepository<TEntity, TId>` and `ISpecificationRepository<TEntity, TId>` contracts.
  - `InMemoryRepository`: High-performance in-memory repository with thread-safe synchronizers, candidate filtering, and full specification querying.
  - `VolatileRepository`: In-memory storage with Time-To-Live (TTL) expiration strategies and auto-pruning.
  - `DagPartitionedRepository`: Partitioning repository utilizing Directed Acyclic Graph (DAG) topological sorting and cluster indexing.
  - `FileRepository`: Atomic file-based persistence utilizing JSON serialization and locking mechanisms.
  - `HybridRepositoryDecorator`: Two-tier storage caching (L1 Memory + L2 Persistent Storage) with write-through and write-back synchronization.
- **Concurrency & Process Synchronization**:
  - `ISynchronizer` contract for thread/process coordination.
  - `ReadWriteLock`: Reader-Writer lock supporting multiple concurrent readers and exclusive writers.
  - `FileLockSynchronizer`: Cross-process advisory locking based on native `flock`.
  - `SemaphoreSynchronizer`: High-concurrency System V IPC semaphores with reentrancy protection and process isolation.
- **Predicates, Visitors & AST Inspection**:
  - `IPredicate<TCandidate>`, `CallbackPredicate`, and `ReflectionPropertyPredicate`.
  - `ISpecificationVisitor` and `AbstractSpecificationVisitor` implementing full Abstract Syntax Tree (AST) traversal.
  - Native PHP 8.4 pattern matching (`match (true)`) in visitor implementations (`visitLeaf`, `visitComposite`, `visitNot`).
- **Notification Pattern & Diagnostic Telemetry**:
  - `SpecificationResult`: Rich evaluation result distinguishing between valid states and rule failures.
  - `SpecificationFailure`: Encapsulates failure severity (ERROR, WARNING, INFO), machine-readable error codes, messages, and target path.
  - Fluent evaluation via `evaluate(mixed $candidate): SpecificationResult`.
- **Static Facade & Fluent DSL**:
  - Static facade `Spec` with 50+ factory methods (`Spec::all()`, `Spec::property()`, `Spec::criteria()`, `Spec::rule()`, `Spec::linq()`, `Spec::defaultValue()`, `Spec::enumCase()`, etc.).
  - Parameterized chaining supporting fluent pipelines (`$spec->and($other)->or($fallback)`).
  - Global fluent DSL functions in `src/DSL/functions.php` (42 helper functions including `specify()`, `allOf()`, `anyOf()`, `not()`, `is()`, `equal()`, `greaterThan()`, `between()`, etc.).
- **Java Parity & Instrumentation**:
  - 100% architectural parity with Java `Domian` specification library.
  - `RelationalOperator` enum: `EQUAL`, `NOT_EQUAL`, `GREATER_THAN`, `GREATER_THAN_OR_EQUAL`, `LESS_THAN`, `LESS_THAN_OR_EQUAL`, `BETWEEN`, `IN`, `LIKE`, `REGEX`.
  - `StopWatch` and `InstrumentationUtils` for sub-millisecond telemetry and performance metrics.
- **Dynamic Rule Engine & Document Requirements Algebra**:
  - `RequirementRuleEngine` for complex business rule workflows.
  - `DocumentRuleSpecification` and `RequirementState` modeling document states, preconditions, and transition validations.
- **Multi-SGBD SQL Query Visitor (12 Dialects)**:
  - `SqlQueryVisitor` compiling specification trees directly into parameterized SQL `WHERE` clauses and bindings.
  - 12 SQL dialects supported: MySQL, MariaDB, PostgreSQL, SQLite, Oracle, SQL Server (T-SQL), Firebird, DB2, Informix, Sybase, DuckDB, ANSI SQL.
- **Adianti Framework TCriteria Integration**:
  - `TCriteriaBuilder` and `CriteriaSpecificationVisitor` translating specification ASTs into native Adianti `TCriteria`, `TFilter`, and `TExpression`.
  - Full support for nested sub-criteria, logical combinators (`AND`, `OR`), negation handling, and all comparison operators.
- **ALinq Synergy & Modular Decomposition**:
  - 3 synergy integration points with `Antevemus.AlinqCollection`:
    - `PropertyAccessor`: Universal property resolution supporting public properties, getters (`getProp()`, `prop()`), boolean accessors (`isProp()`, `hasProp()`), `ArrayAccess`, associative arrays, and nested dot notation (`user.address.city`).
    - `ALinqSpecificationVisitor`: Compiles any specification AST into an optimized `Closure(mixed $candidate): bool` using native `match (true)`.
    - `ALinqBridge`: Bridges `ISpecification` directly with `ALinqCollection` for fluent collection filtering (`ALinqBridge::filter()`, `ALinqBridge::matching()`).
    - Direct integration methods in `InMemoryRepository`: `asLinqCollection()` and `findAsLinqCollection($spec)`.
  - Modular trait-based decomposition of `SpecificationFactory` (reduced by 64% from 2,376 to 856 lines across 8 specialized traits in `src/Factory/Traits/`):
    - `BasicSpecificationsTrait`
    - `CollectionSpecificationsTrait`
    - `ComparisonSpecificationsTrait`
    - `LogicalSpecificationsTrait`
    - `RangeSpecificationsTrait`
    - `StringSpecificationsTrait`
    - `TypeSpecificationsTrait`
    - `UtilitySpecificationsTrait`
- **Testing Suite**:
  - 14 comprehensive test modules (`Module01` through `Module14`) with 579 assertions and 100% pass rate.
  - Master test runner (`tests/run_all.php`) executing in ~40ms.

### Changed
- Refactored `SpecificationFactory` into 8 modular traits for optimal maintainability and separation of concerns.
- Refactored `CriteriaSpecificationVisitor` and `ALinqSpecificationVisitor` to leverage native PHP 8.4 pattern matching (`match (true)`).
- Standardized all 103+ classes with corporate Antevemus PHPDoc blocks, `@package`, `@subpackage`, `@author`, and `@license MIT`.
- Updated minimum PHP requirement to PHP 8.4+.

### Fixed
- Reentrancy and process exclusivity handling in `SemaphoreSynchronizer`.
- Proper handling of null candidates in `PropertyAccessor` and `ALinqSpecificationVisitor`.
- Array key preservation and dot notation resolution in nested data structures.

### Security
- Strongly typed parameters and strict typing (`declare(strict_types=1)`) throughout all components.
- Parameterized SQL generation avoiding SQL injection vulnerabilities across all 12 SGBD dialects.
- SysV IPC semaphore isolation with safe unlock guarantees in concurrent environments.

## Release Notes

### v1.0.0 - Initial Official Release

This is the initial official release of **Antevemus ASpecification**, a high-performance, enterprise-grade implementation of the Specification Pattern for PHP 8.4+.

**Highlights:**
- 📐 **Full Specification Pattern**: Composable business rules using pure Boolean algebra (`And`, `Or`, `Not`, `Conjunction`, `Disjunction`).
- ⚡ **Zero Runtime Dependencies**: Pure PHP 8.4+ implementation requiring no external vendor dependencies for core functionality.
- 🗄️ **Multi-Target Compilation**:
  - Compiles to SQL `WHERE` clauses across **12 SGBD Dialects** (MySQL, PostgreSQL, Oracle, SQL Server, SQLite, Firebird, etc.).
  - Compiles to **Adianti Framework `TCriteria`** expressions.
  - Compiles to **ALinq / In-Memory Closures** with native pattern matching.
- 🔗 **Deep ALinq Synergy**: Native interoperability with [`Antevemus.AlinqCollection`](https://github.com/antevemus-it/Antevemus.AlinqCollection), featuring dot-notation property access (`user.address.city`).
- 📢 **Notification Pattern**: Non-throwing rule evaluation with structured errors, warnings, metadata, and error codes via `SpecificationResult`.
- 🧩 **Modular Trait Architecture**: Extensible, clean architecture with 8 specialized factory traits.
- 🧪 **Exhaustive Testing**: 14 test suites, 579 assertions, 100% pass rate in ~40ms.

**Installation:**
```bash
composer require antevemus/aspecification
```

**Quick Example:**
```php
use Antevemus\ASpecification\Spec;
use function Antevemus\ASpecification\DSL\{specify, greaterThanOrEqualTo, equal, greaterThan};

// 1. Compose business rules fluently
$isEligible = specify('User')
    ->where('age', greaterThanOrEqualTo(18))
    ->and('status', equal('ACTIVE'))
    ->and('credit.score', greaterThan(700));

// 2. Evaluate candidate with Notification Pattern
$result = $isEligible->evaluate($user);
if ($result->isSatisfied) {
    echo "User is eligible!";
} else {
    foreach ($result->failures as $failure) {
        echo "Rule failed: " . $failure->message;
    }
}
```

For complete documentation, see [README.md](README.md) and [README.pt-BR.md](README.pt-BR.md).

---

[Unreleased]: https://github.com/antevemus-it/Antevemus.ASpecification/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/antevemus-it/Antevemus.ASpecification/releases/tag/v1.0.0
