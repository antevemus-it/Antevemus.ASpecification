# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.2] - 2026-10-07

### Security
- **SQL Query Visitor**: column identifiers are now validated against a strict grammar before reaching the `WHERE` clause. Plain or dot-qualified identifiers are quoted by the dialect; simple function calls over identifiers (`LOWER(name)`, `COALESCE(c.nick, c.name)`) are preserved; anything else throws `Sql\Exceptions\UnsafeIdentifierException`. Previously, any name containing parentheses was emitted raw, which allowed SQL injection through the property name or a `FieldMapper` result. **Breaking for** field mappers that returned arbitrary SQL expressions (`CASE WHEN ...`): build those clauses outside the visitor.
- **TCriteria Builder**: values that the Adianti `TFilter` emits as raw SQL even in prepared mode (starting with `(SELECT`, containing `{session.`, starting with `NOESC:`) are now refused with `Criteria\Exceptions\UnsafeCriteriaValueException` before the `TFilter` is built, for every leaf including `IN` lists and De Morgan inversions. Values are data, never SQL: a value that the target engine would interpret as SQL is rejected instead of forwarded. The Adianti test stub now mirrors the real `TFilter::transform()` for these shapes.
- **SingleFileRepository**: the `.bin` envelope is now read with `allowed_classes => false`. Previously it was read with `allowed_classes => true`, so an object placed in the envelope was instantiated (running `__wakeup`/`__destruct`) before the `PhpNativeEntitySerializer` whitelist applied. Entities are still reconstructed through the whitelist; existing `.bin` files remain readable.

### Added
- Reproduction and regression tests for the three fixes above (`Module5`, `Module12`, `Module13`; suite now 15/15, 656 assertions).

## [1.1.1] - 2026-10-06

### Fixed
- Updated strategic roadmap and completed milestone statuses in documentation (`README.md`, `README.pt-BR.md`, `ROADMAP.md`).
- Aligned Packagist stable distribution with immutable versioning policy.

## [1.1.0] - 2026-10-06

### Added
- **Declarative PHP 8.4 Attributes Engine**:
  - `#[AssertSpec]` attribute supporting class-level aggregate specifications, property-level value validation, and parameterless getter execution.
  - `#[ValidateRule]` attribute for inline declarative rules with operators: `=`, `!=`, `>`, `>=`, `<`, `<=`, `between`, `in`, `regex`, `not_blank`, `email`.
  - `AttributeValidator`: High-performance reflection scanning engine supporting both non-throwing `validate()` returning `SpecificationResult` and throwing `assert()`.
  - `AttributeValidationException`: Rich validation exception carrying full diagnostic failures and error codes.
  - `Spec::validateAttributes(object $target): SpecificationResult` and `Spec::assertAttributes(object $target): void` facade methods.
  - Test Suite `Module 15: Attributes Declarativos PHP 8.4 (#[AssertSpec])` with 25 assertions.
- **ALinq Synergy & Generator-Based Streaming Pipeline (O(1) RAM)**:
  - Integration with `ALinqLazyCollection` from `antevemus/alinq-collection` >= 1.1.0 for processing massive datasets with constant memory overhead.
  - `ALinqBridge::isLazyAvailable()` for runtime capability detection.
  - `ALinqBridge::toLazyCollection(iterable|callable|InMemoryRepository $source)` converting generators, callables, and repositories into lazy streaming collections.
  - `ALinqBridge::filterLazy(iterable|callable|InMemoryRepository $source, ISpecification $specification)` compiling specifications into lazy LINQ filters with on-demand evaluation.
  - `ALinqBridge::fromRepositoryLazy(InMemoryRepository $repository)` and `ALinqBridge::queryRepositoryLazy(InMemoryRepository $repository, ISpecification $specification)` for deferred repository queries.
  - `InMemoryRepository::asLazyCollection()` and `InMemoryRepository::findAsLazyCollection(ISpecification $specification)` methods.
  - `Spec::linqLazy()` and `Spec::filterLazy()` fluent facade methods.
  - Expanded test suite `Module 14: ALinq Synergy & Coleções Fluentes LINQ` to 91 assertions covering full lazy pipeline.
- **Documentation & Release Artifacts**:
  - `VERSION` file tracking current library version `1.1.0`.

### Changed
- **PSR-12 Strict Type Declarations**:
  - Enforced `declare(strict_types=1);` across 100% of all PHP source files (`src/` - 164 files) and test suites (`tests/` - 21 files).
- **English DocBlock Internationalization & PSR-5 / PSR-19 Compliance**:
  - Full translation of all PHP DocBlocks, summaries, parameter descriptions, and return types from Portuguese to English across all 110+ files in `src/` and `src/Contracts/`.
  - Standardized mandatory Antevemus corporate header across every class, interface, trait, and enum (`@version 1.1.0`, `@copyright Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.`, `@license MIT`).
  - Standardized internal exception messages to professional English across specifications, repositories, factories, and concurrent synchronizers.

### Fixed
- Fixed exception message matching in `Module10_JavaParityAndTelemetryTest` to support multilingual remainder assertions.

---

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
  - 15 comprehensive test modules (`Module01` through `Module15`) with 604 assertions and 100% pass rate.
  - Master test runner (`tests/run_all.php`) executing in ~40ms.

### Changed
- Refactored `SpecificationFactory` into 8 modular traits for optimal maintainability and separation of concerns.
- Refactored `CriteriaSpecificationVisitor` and `ALinqSpecificationVisitor` to leverage native PHP 8.4 pattern matching (`match (true)`).
- Standardized all 110+ classes with corporate Antevemus PHPDoc blocks, `@package`, `@subpackage`, `@author`, and `@license MIT`.
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

### v1.1.0 - Declarative Attributes & Full DocBlock Internationalization

**Antevemus ASpecification v1.1.0** introduces native PHP 8.4 Declarative Attributes support and completes 100% English DocBlock internationalization across the entire codebase.

**Highlights:**
- 🏷️ **PHP 8.4 Declarative Attributes (`#[AssertSpec]`, `#[ValidateRule]`)**:
  - Annotate DTOs, domain models, Form Requests, and Value Objects directly.
  - Validate with `Spec::validateAttributes($dto)` (Notification Pattern) or `Spec::assertAttributes($dto)` (throwing).
- 🌐 **100% English DocBlock Internationalization**:
  - Full PSR-5 and PSR-19 compliant docblocks across all 110+ source files.
  - Standardized corporate PHPDoc header and English exception messaging.
- 🧪 **15 Test Suites & 604 Assertions**: 100% pass rate with zero regressions.

---

### v1.0.0 - Initial Official Release

This is the initial official release of **Antevemus ASpecification**, a high-performance, enterprise-grade implementation of the Specification Pattern for PHP 8.4+.

**Installation:**
```bash
composer require antevemus/aspecification
```

---

[Unreleased]: https://github.com/antevemus-it/Antevemus.ASpecification/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/antevemus-it/Antevemus.ASpecification/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/antevemus-it/Antevemus.ASpecification/releases/tag/v1.0.0
