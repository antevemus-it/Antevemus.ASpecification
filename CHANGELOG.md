# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.0] - 2026-10-07

### Changed
- **Evaluation errors are no longer rule failures.** When a specification cannot be evaluated (missing property on the candidate, throwing getter, `TypeError` inside `isSatisfiedBy()`), `evaluate()` now returns an error result: `isSatisfied = false`, new `isError = true` and the exception attached in the new `exception` property (`SpecificationResult::error(...)`). `NOT` never inverts an error result, `AND`/`OR`/`combine()` propagate it, and `isSatisfiedBy()` keeps throwing. Previously the exception was downgraded to a rule failure and `Spec::not(...)` turned it into approval. A property whose value is `null` is still a rule failure.
- **Comparison and equality specifications refuse incompatible candidate types.** `GreaterThan`/`LessThan` (and `atLeast`, `atMost`, `between`), the date leaves (`before`, `after`, `at`, `beforeOrAt`, `afterOrAt`, `between`, `isToday`, `isPast`, `isFuture`) and the strict `Equal`/`NotEqual` (`equalTo`, `isTrue`, `isFalse`, `in`) now throw `Specifications\Exceptions\IncompatibleTypeException` instead of letting PHP coerce: a string candidate against a numeric threshold, an object against a string, `"5"` against `5`, `1` against `true`. Numeric strings are not coerced; `int` and `float` are distinct types for strict equality. Under `evaluate()` this becomes an error result. Subsumption (`isGeneralizationOf`, `isDisjointWith`) between mixed-type specifications returns `false` without throwing. **Breaking for** code that relied on loose coercion: use `looselyEqualTo()`.
- **`ISpecification::equals()` is now part of the contract.** `AbstractSpecification` provides a structural default (same class and equal parameters; nested specifications compared through `equals()`, dates by instant, arrays element by element, scalars strictly). Custom specifications that extend `AbstractSpecification` get it for free; classes implementing `ISpecification` directly must add it.
- **Dynamic Rule Engine binds every compiled rule to its catalog definition.** Specifications returned by rule handlers are wrapped in `Engine\RuleBoundSpecification` by `DynamicSpecificationEngine::compileSpecification()`: every failure now carries the rule's `acao`, `fundamento_legal`, `regra`, `tipo_regra`, `nome_regra` and `prioridade` in its metadata, with handler-provided data taking precedence. A raw specification (no `withCode()`/custom failure) is reported once per rule, with the rule code and `mensagem_violacao`; technical messages are kept in `metadata['mensagens_originais']`. Previously a WARN/LOG rule whose handler did not copy the action by hand was triaged as BLOCK and `getLegalBases()` came back empty. The SQL, TCriteria and ALinq visitors translate the compiled specification transparently (they unwrap it), so `Spec::toSql($engine->compileSpecification(...))` keeps working.
- **Evaluation errors always block.** `RuleEngineVerdict` classifies a failure produced by an evaluation error (`SpecificationResult::error()`) as blocking regardless of the configured rule action, and exposes it via `hasEvaluationErrors()`. `getFailureCodes()` now returns one entry per failure (explicit `null` when a failure has no code) instead of silently dropping nulls. `isSatisfied()` is documented as "no failure of any action was recorded"; the operational gate is `hasBlockingErrors()` or the new `canProceed()`.
- **Document requirements declare their scope; the catalog matches on it, never on the group code.** `IDocumentRuleDefinition` now requires `getEscopo(): string` and `getCenario(): ?string`, mirroring the relational document matrix (scope is mandatory on the group, scenario is optional, the group code is an identifier only). `DocumentRuleDefinition` takes `escopo` as its third constructor parameter and refuses an empty one (`InvalidArgumentException`); `fromArray()` requires the `escopo` key and reads an optional `cenario`. `InMemoryRuleCatalog::findDocumentRules()` matches exactly on scope and scenario, so documents can no longer leak between scopes (`contrato` vs `contratos_x`, `sinistro_ocupado` vs `sinistro_desocupado`, two scopes sharing a scenario name): the former guesswork on `grupo_codigo` is gone. **Breaking for** every caller that builds `DocumentRuleDefinition` (positional calls shift by one argument and fail with a `TypeError`; records without `escopo` throw) and for custom `IDocumentRuleDefinition` implementations, which must add the two getters.
- **Rule catalog scenario semantics.** `IRuleCatalog::findRules()`/`findDocumentRules()` with a `null` scenario now return scope-global rules and documents only, never every scenario at once.
- **Document matrix semantics.** A document whose conditional guard does not hold is *not applicable*: it leaves the count of `ANY`/`ONE_OF_SET` sets instead of counting as present (a `ONE_OF_SET` with one conditional document used to reject candidates holding the right document and accept candidates holding none). `ANY`/`ONE_OF_SET` rules without `codigo_set_alternativas` are now refused at compilation with `Engine\Exceptions\MissingAlternativeSetException` (the relational catalog already forbids that shape; previously each such rule became a set of one document, which degenerated into `ALL`). A failing set emits one aggregated failure (`DOC_SET_<KEY>`) listing the accepted documents (`metadata['documentos_aceitos']`, `['codigos']`) instead of one failure per document. An unsupported guard expression now throws `Engine\Exceptions\InvalidConditionalExpressionException` at compilation instead of silently requiring the document.

### Fixed
- **TCriteria Builder**: `equalIgnoreCase` and `wildcardExpressionMatcherIgnoreCase` leaves now reach the database as `UPPER(col) LIKE UPPER(value)` (and `NOT LIKE` under negation), also in prepared mode and at any nesting depth. Previously the Adianti `TCriteria::dump()` reset the case-insensitive flag of every child filter to its own default (`false`), so those searches were silently case-sensitive on PostgreSQL, Oracle, Firebird and SQLite. The visitor now emits `Criteria\TCaseInsensitiveFilter` (a `TFilter` whose flag cannot be switched off) for those leaves; sensitive sibling filters are unaffected. The Adianti test stub now mirrors the real flag propagation, and the suite additionally verifies the translation against the real Adianti classes in a child process when they are available next to the repository.
- **Partitioned repositories no longer orphan entities when sibling partitions share a class.** `addPartition()` treated two leaves of the same class as equivalent (the fallback compared class name and string form, and the string form is the class name), replaced the existing partition and dropped the entities that did not satisfy the new specification. Equivalence is now `equals()`; `collectPartitions()` no longer throws for leaves without a custom `equals()`.
- **`SingleFileRepository` no longer loses updates between processes or instances.** The exclusive lock was taken on the document itself, which `rename()` replaces with a new inode, and each instance stored the whole map it had loaded once. Locks now live in a stable `<document>.lock` file next to the document, and in `ReadWrite` mode every mutation re-reads the document under the lock before writing the merged map. Snapshot/WriteOnly/MemoryOnly modes keep writing the whole map on `store()`/`close()`.

### Added
- `SpecificationResult::error()`, `SpecificationResult::$isError`, `SpecificationResult::$exception` and `SpecificationResult::withLeadingFailures()`.
- Opt-in loose equality: `Spec::looselyEqualTo($value)`, `looselyEqualTo()` DSL function, `IComparisonSpecificationFactory::looselyEqualTo()` and `LooseEqualSpecification` (`==` semantics; extends `EqualSpecification`, so SQL, TCriteria and ALinq visitors translate it as plain equality).
- `Specifications\Comparison\TypeCompatibility` (type guards) and `Specifications\Exceptions\IncompatibleTypeException`.
- `Engine\RuleBoundSpecification` (with `getInnerSpecification()` and `getRule()`), `RuleEngineVerdict::canProceed()` and `::hasEvaluationErrors()`.
- `IDocumentRuleDefinition::getEscopo()` / `::getCenario()`; `DocumentRuleDefinition` `escopo` and `cenario` constructor parameters and `fromArray()` keys.
- `Engine\DocumentLeafSpecification` and `Engine\DocumentSetSpecification`, the named building blocks of the document matrix; `DocumentGroupSpecificationBuilder::resolveSetKey()` and `::createAnySetSpecification()` hooks; `Engine\Exceptions\MissingAlternativeSetException`.
- `Criteria\TCaseInsensitiveFilter`.
- Reproduction and regression tests across `Module1`, `Module4`, `Module5`, `Module8`, `Module11` and `Module13` (suite now 15/15, 846 assertions).

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
