# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.3.1] - 2026-10-07

### Changed
- **PHP floor lowered to `^8.2`.** The core never used anything beyond PHP 8.2; the `>=8.4` requirement had leaked from the optional ALinq integration, which the library detects at runtime (`class_exists`). `composer.json` now declares `php: ^8.2` and `ext-json`, and lists `antevemus/alinq-collection` (requires PHP 8.4), `ext-mbstring` and `ext-sysvsem` under `suggest` with an honest note on what uses them today. The only 8.3-only construct in `src/` (a typed class constant in `SemaphoreSynchronizer`) was replaced by an untyped constant with a `@var` annotation. The suite passes on PHP 8.2 and 8.4 (certified in a `php:8.2-cli` container: lint clean, 15/15 suites, `vendor/bin/phpunit` green). Module 14 now skips its `ALinqBridge`/collection tests with a notice when `antevemus/alinq-collection` is not installed, keeping `PropertyAccessor` and the ALinq visitor covered.
- `tests/run_all.php` no longer aborts at the first failing suite: every suite runs, failures are listed at the end, the pass rate and the number of regressions are reported, and the exit code is 1 when any suite failed.
- The rule-engine data contract is documented as Portuguese by design (it mirrors the relational catalog schema it hydrates from); orchestration and verdict APIs stay English. No renames.
- Upstream attribution now states the roles published by the Domian project site: Eirik Torske (Project Administrator, Developer) and Bjørn Nordlund (Contributor), in `NOTICE.md`, `THIRD_PARTY_NOTICES.md` and both READMEs.

### Added
- PHPUnit bridge: `vendor/bin/phpunit` runs the fifteen module suites through `tests/PhpUnit/ModuleSuitesTest.php` and `phpunit.xml.dist` (no coverage by default, no Xdebug required). `tests/run_all.php` remains the canonical runner; the `phpunit/phpunit` dev dependency is no longer decorative.
- `.gitattributes` with normalized line endings and `export-ignore` for `tests/`, the PHPUnit configuration and the git metadata, so distributed archives carry only `src/` and the package files.

### Documentation
- CHANGELOG errata: the `1.0.0` and `1.1.0` entries now name the classes, traits and methods that actually shipped (for example `AndSpecification`/`OrSpecification` instead of `ConjunctionSpecification`/`DisjunctionSpecification`, the eight `*SpecificationOperationsTrait` traits, `PartitionRepository`, `InMemoryAndFileRepository`, `DynamicSpecificationEngine`), with an *Erratum* note wherever an announced capability was never shipped (ULID/UUID v7 identities, TTL on volatile repositories, topological sorting of the DAG, failure severity, `RelationalOperator` with `BETWEEN`/`IN`/`LIKE`/`REGEX`, a document state machine, DB2/Informix/DuckDB dialects). Those capabilities are listed in `ROADMAP.md` as a backlog. Version comparison links added to the footer.
- Class DocBlock `@version` tags now state the package version in which each file was last changed (derived from the repository history; this one-off correction of the tags does not itself count as a change to the files); the policy is recorded in the release contract (`docs/contratos/RELEASING.md`, Fase 1), which also lists `.gitattributes` and `phpunit.xml.dist` among the files promoted to the public branch.
- README (EN and pt-BR): requirements section rewritten for the PHP 8.2 floor and the optional packages; vocabulary note on the rule engine.

## [1.3.0] - 2026-10-07

### Changed
- **The ALinq visitor now has full parity with the core.** `ALinqSpecificationVisitor::createPredicate()` returns exactly what `evaluate()`/`isSatisfiedBy()` decide for the same candidate and throws the same typed exceptions: strict comparisons raise `IncompatibleTypeException` on a type mismatch (the compiled predicate no longer coerces with `==`, `>`, `<`), custom leaves receive scalar property values, a `null` property value never satisfies, and a missing property raises `InvalidArgumentException` instead of returning `false` (so `NOT` can never approve it). Wildcard leaves use the same `fnmatch` semantics as `WildcardSpecification`. `looselyEqualTo()` keeps its coercion in both paths. **Breaking for** code filtering ALinq collections or repositories over mixed-type data or candidates missing the inspected property: normalise the data, guard with `isNotNull()`, or use `looselyEqualTo()`.
- **`#[ValidateRule]` rejects unknown operators.** Constructing the attribute (and therefore `Spec::validateAttributes()` / `Spec::assertAttributes()`) throws `Attributes\Exceptions\UnknownRuleOperatorException` listing the accepted operators, instead of silently reporting the attribute's business message as a violation. The accepted operators are published as `ValidateRule::OPERATORS`. **Breaking for** code that declared an operator outside the catalog (for example a typo): it now fails loudly on the first validation.
- Composite type specifications created by `Spec::specify(T)` now print as `Spec<T>` instead of an anonymous class name with a file path.

### Fixed
- **`because()`/`withCode()` on a property or composite specification report a single failure.** `PropertySpecification`, `AndSpecification` and `OrSpecification` annotated with a reason or code now return one failure carrying the annotated message, code and (for properties) the property name; the inner failures are preserved in `metadata['causes']` instead of leaking as code-less sibling failures. A `null` property on an annotated specification also uses the annotated reason. Evaluation errors are still never absorbed by the annotation. The README Notification Pattern example now produces exactly two coded failures. **Breaking for** code that read the branch failures of an annotated `AND`/`OR` from the failure list: read `metadata['causes']`.
- **`remainderUnsatisfiedBy()` returns only the unsatisfied clauses.** Satisfied clauses are dropped, a single pending clause is returned as itself, nested disjunctions are kept whole, top-level disjunctions still throw, and the remainder can be evaluated on its own. Previously the fluent `specify()->where()->and()` chain returned the whole specification unchanged.
- **`in()` accepts the value set as a single array.** `Spec::in([0, 2, 4])`, the DSL `in()` function and `IComparisonSpecificationFactory::in()`/`isOneOfValues()` treat a single array argument as the set of values (equivalent to `in(0, 2, 4)`). Previously the array was compared as one value and the specification never matched (since 1.2.0 it raised `IncompatibleTypeException`). Empty sets (`in()`, `in([])`) never match; the variadic form is unchanged.

### Added
- **`ISpecification::must(Closure $predicate, ?string $code = null, ?string $message = null)`** appends an inline rule over the whole candidate as a `Specifications\PredicateSpecification` leaf carrying the optional failure code and message, so README example 7 (`Spec::specify(Contract::class)->must(...)`) runs as written. An exception thrown by the closure propagates from `isSatisfiedBy()` and becomes an error result under `evaluate()`. The leaf is compiled natively by the ALinq visitor and refused by the SQL and TCriteria visitors with a clear "non translatable" message.
- **Logical NOR exposed by the API:** `Spec::nor()`/`Spec::noneOf()`, `ILogicalSpecificationFactory::nor()`/`noneOf()` and the DSL functions `nor()`/`noneOf()` build a `JointDenialSpecification` (`NOT (a OR b ...)`; no argument is a tautology, one argument is its negation). The SQL visitor translates it as `NOT (a OR b)` and the TCriteria visitor as `NOT a AND NOT b` (and `NOT(NOR)` as `a OR b`); previously a `JointDenialSpecification` reaching the SQL visitor was silently emitted as a conjunction. `neitherOf()` is unchanged.
- **`#[ValidateRule]` `not_blank` operator** (alias `notblank`): a string must have visible content after trimming, an array must have elements, and any other value counts as present unless it is `null` or `false` (so `0` passes, unlike `not_empty`). Listed in `ValidateRule::OPERATORS` and in the message of `UnknownRuleOperatorException`.
- **`#[ValidateRule]` accepts `value:` as an alias of `expected:`** (`#[ValidateRule('>=', value: 18)]`, the spelling used by the README). `expected` remains the property read by the validator; passing both with different contents throws `InvalidArgumentException`.
- **`InMemoryAndFileRepository::create(storagePath:, serializer:, cache:, repositoryId:, persistenceDefinition:)`**: named factory for the L1 (RAM) + L2 (single file) tiering shown in README example 5. It builds the `SingleFileRepository` backend over the given path, creates the parent directory when missing, warms the cache from an existing file and returns a ready-to-query repository. The original constructor (cache + backend) is unchanged.
- **`ISqlWhereClause::getSql()` and `getBindings()`**, aliases of `toSql()` and `getParameters()` (same `:param` keys), the names used by README example 8.
- **`fieldMapper:` accepted as an alias of `fieldMap:`** on `Spec::toSql()`, `Spec::toCriteria()`, `Spec::criteriaBuilder()` and on the instance methods `toSql()`/`toCriteria()`; passing both with different values throws `InvalidArgumentException`. `Spec::resolveFieldMap()` exposes the resolution rule.
- **`TCriteriaBuilder::withFieldMapping()`** (define or replace the column mapping before building), **`offset()`** (set the offset without touching the limit) and **`toCriteria()`** (alias of `build()`), so the fluent pt-BR README example runs as written. `limit($limit, $offset = null)` no longer resets a previously set offset.
- `Attributes\Exceptions\UnknownRuleOperatorException`; `ValidateRule::OPERATORS`, `ValidateRule::normalizeOperator()` and `ValidateRule::isKnownOperator()`; `Specifications\PredicateSpecification`.
- Reproduction, regression and README-example tests across `Module5`, `Module7`, `Module8`, `Module9`, `Module10`, `Module11`, `Module12`, `Module13`, `Module14` and `Module15` (suite now 15/15, 1223 assertions). Every one of the eleven README code blocks is now executed by the suite or by a probe and runs as written.

### Documentation
- README (EN and pt-BR) audited line by line against the code, with every example executed: example 1 rewritten with the DSL functions it imports (and a precedence that matches its comment), SQL and TCriteria outputs updated to what the library prints, `IEntity` requirement stated in the DAG example, driver count stated as 12 drivers over 7 SQL dialects, roadmap list synchronised with `ROADMAP.md`, and the pt-BR edition brought to parity with the English one. Promised APIs that do not exist yet (`must()`, `getSql()`/`getBindings()`, `fieldMapper:`, `not_blank`, `value:`, `Specifications/Reflection/`, `TCriteriaBuilder::withFieldMapping()`) remain in the README as roadmap items.

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
  - `#[ValidateRule]` attribute for inline declarative rules with operators: `=`/`==`, `===`, `!=`/`<>`, `!==`, `>`, `>=`, `<`, `<=`, `between`, `in`, `notin`/`not_in`, `regex`, `notnull`/`not_null`, `null`, `notempty`/`not_empty`, `empty`, `email`. *Erratum (2026-10-07):* this entry originally also listed `not_blank`; that operator did not exist until 1.3.0.
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
  - Boolean combinators: `AndSpecification`, `OrSpecification`, `NotSpecification` and `JointDenialSpecification` (NOR). *Erratum (2026-10-07):* `ConjunctionSpecification` and `DisjunctionSpecification` never existed; the conjunction and disjunction are `AndSpecification` and `OrSpecification`.
  - Composite operators: `and()`, `or()`, `not()`, `andNot()`, `orNot()`.
  - Identity specifications: `AlwaysTrueSpecification` (tautology / true) and `AlwaysFalseSpecification` (contradiction / false). *Erratum (2026-10-07):* originally named `AllSpecification` and `NoneSpecification`, which never existed.
- **Domain Entities, Identifiers & Value Objects**:
  - `IEntity` and `ITransientEntity` contracts and the `AbstractEntity` base class with identity encapsulation.
  - Identity base classes `AbstractUUIDEntity` (UUID v4), `AbstractRandomIntegerEntity` and `AbstractRandomLongEntity`. *Erratum (2026-10-07):* originally announced as a strongly-typed `Identifier` class supporting UUID v4, ULID, UUID v7, integer and string; no such class exists and ULID/UUID v7 were never shipped.
  - `UniqueEntitySpecification` and `AllEntitiesSpecification` for entity-level rules. *Erratum (2026-10-07):* originally announced as `IEntitySpecification<TEntity>`, which never existed.
- **Repository Architecture**:
  - `IRepository`, `IPartitionRepository`, `IVolatileRepository` and `IPersistentRepository` contracts. *Erratum (2026-10-07):* originally announced as `IRepository<TEntity, TId>` and `ISpecificationRepository<TEntity, TId>`; the latter never existed.
  - `InMemoryRepository`: High-performance in-memory repository with thread-safe synchronizers, candidate filtering, and full specification querying.
  - `VolatilePartitionRepository`: in-memory partition storage. *Erratum (2026-10-07):* originally announced as `VolatileRepository` with TTL expiration and auto-pruning; no TTL mechanism was shipped.
  - `PartitionRepository`: Directed Acyclic Graph (DAG) partitioning with O(1) pruning of disjoint branches. *Erratum (2026-10-07):* originally announced as `DagPartitionedRepository` with topological sorting and cluster indexing; neither the name nor those two mechanisms exist.
  - `SingleFileRepository` and `FilePerEntityRepository`: file-based persistence with `JsonEntitySerializer`/`PhpNativeEntitySerializer` and `flock` locking. *Erratum (2026-10-07):* originally announced as a single `FileRepository` class, which never existed.
  - `InMemoryAndFileRepository`: two-tier storage (L1 memory + L2 persistent) with the modes of `PersistenceDefinition` (`ReadWrite`, `Snapshot`, `WriteOnly`, `MemoryOnly`, ...). *Erratum (2026-10-07):* originally announced as `HybridRepositoryDecorator`, which never existed.
- **Concurrency & Process Synchronization**:
  - `ISynchronizer` contract for thread/process coordination.
  - `ISynchronizer::runConcurrently()`/`runExclusively()` (shared readers, exclusive writer) implemented by `AbstractSynchronizer`, with `NullSynchronizer` as the no-op. *Erratum (2026-10-07):* originally announced as a `ReadWriteLock` class, which never existed.
  - `FileLockSynchronizer`: Cross-process advisory locking based on native `flock` (`LOCK_SH`/`LOCK_EX`).
  - `SemaphoreSynchronizer`: counting semaphore with reentrancy protection. *Erratum (2026-10-07):* announced here as System V IPC semaphores; the implementation is an in-process permit counter and does not use `ext-sysvsem`. SysV backing is tracked in `ROADMAP.md` milestone 6.
- **Predicates, Visitors & AST Inspection**:
  - `SpecificationPredicate` (closure from a specification), `SpecificationHelper::toPredicate()`/`filter()` and `PropertyAccessor`. *Erratum (2026-10-07):* originally announced as `IPredicate<TCandidate>`, `CallbackPredicate` and `ReflectionPropertyPredicate`, none of which ever existed.
  - `ISpecificationVisitor` (`visitComposite`, `visitLeaf`) implemented by `SqlQueryVisitor`, `CriteriaSpecificationVisitor` and `ALinqSpecificationVisitor` for full AST traversal. *Erratum (2026-10-07):* originally announced with an `AbstractSpecificationVisitor` base class and a `visitNot` method; neither exists (negation is handled inside `visitComposite`).
  - Native pattern matching (`match (true)`) in the visitor implementations.
- **Notification Pattern & Diagnostic Telemetry**:
  - `SpecificationResult`: Rich evaluation result distinguishing between valid states and rule failures.
  - `SpecificationFailure`: encapsulates message, machine-readable code, rule name, target property and metadata. *Erratum (2026-10-07):* originally announced with a severity field (ERROR, WARNING, INFO); no such field exists (severity appears only as metadata of the attribute engine since 1.1.0).
  - Fluent evaluation via `evaluate(mixed $candidate): SpecificationResult`.
- **Static Facade & Fluent DSL**:
  - Static facade `Spec` with 50+ factory methods (`Spec::allOf()`, `Spec::property()`, `Spec::toCriteria()`, `Spec::ruleRegistry()`/`Spec::engine()`, `Spec::defaultValue()`, `Spec::enumCase()`, etc.). *Erratum (2026-10-07):* originally listed as `Spec::all()`, `Spec::criteria()`, `Spec::rule()` and `Spec::linq()`; the first three never existed and `Spec::linq()` arrived in 1.1.0.
  - Parameterized chaining supporting fluent pipelines (`$spec->and($other)->or($fallback)`).
  - Global fluent DSL functions in `src/DSL/functions.php` (44 helper functions including `specify()`, `allOf()`, `anyOf()`, `not()`, `is()`, `equal()`, `greaterThan()`, `between()`, etc.).
- **Java Parity & Instrumentation**:
  - 100% architectural parity with Java `Domian` specification library.
  - `RelationalOperator` enum: `EQUAL`, `NOT_EQUAL`, `GREATER_THAN`, `GREATER_THAN_OR_EQUAL`, `LESS_THAN`, `LESS_THAN_OR_EQUAL`, `MUCH_GREATER_THAN`, `MUCH_LESS_THAN`. *Erratum (2026-10-07):* `BETWEEN`, `IN`, `LIKE` and `REGEX` were listed but are not cases of the enum (those predicates are specifications: date `between()`, `in()`, `WildcardSpecification`, `RegexSpecification`).
  - `StopWatch` and `InstrumentationUtils` for sub-millisecond telemetry and performance metrics.
- **Dynamic Rule Engine & Document Requirements Algebra**:
  - `DynamicSpecificationEngine` with `RuleSpecificationRegistry`, `RuleDefinition` and `RuleEngineVerdict` for catalog-driven business rules. *Erratum (2026-10-07):* originally announced as `RequirementRuleEngine`, which never existed.
  - `DocumentGroupSpecificationBuilder`, `DocumentRuleDefinition` and `DocumentRequirementMode` (`ALL`, `ANY`, `ONE_OF_SET`) for mandatory document matrices. *Erratum (2026-10-07):* originally announced as `DocumentRuleSpecification` and `RequirementState` "modeling document states, preconditions and transition validations"; no document state machine was shipped.
- **Multi-SGBD SQL Query Visitor (12 drivers, 7 dialects)**:
  - `SqlQueryVisitor` compiling specification trees directly into parameterized SQL `WHERE` clauses and bindings.
  - 12 driver names (`pgsql`, `mysql`, `sqlite`, `oracle`/`oci`, `sqlsrv`/`mssql`/`dblib`, `firebird`/`fbird`/`ibase`, `ansi`) mapped onto 7 dialect classes: ANSI, PostgreSQL, MySQL/MariaDB, SQLite, Oracle, SQL Server (T-SQL), Firebird. *Erratum (2026-10-07):* originally listed DB2, Informix, DuckDB and Sybase as dialects; DB2, Informix and DuckDB were never shipped, and Sybase exists only as the `dblib` driver alias of the SQL Server dialect.
- **Adianti Framework TCriteria Integration**:
  - `TCriteriaBuilder` and `CriteriaSpecificationVisitor` translating specification ASTs into native Adianti `TCriteria`, `TFilter`, and `TExpression`.
  - Full support for nested sub-criteria, logical combinators (`AND`, `OR`), negation handling, and all comparison operators.
- **ALinq Synergy & Modular Decomposition**:
  - 3 synergy integration points with `Antevemus.AlinqCollection`:
    - `PropertyAccessor`: Universal property resolution supporting public properties, getters (`getProp()`, `prop()`), boolean accessors (`isProp()`, `hasProp()`), `ArrayAccess`, associative arrays, and nested dot notation (`user.address.city`).
    - `ALinqSpecificationVisitor`: Compiles any specification AST into an optimized `Closure(mixed $candidate): bool` using native `match (true)`.
    - `ALinqBridge`: Bridges `ISpecification` directly with `ALinqCollection` for fluent collection filtering (`ALinqBridge::filter()`, `toCollection()`, `fromRepository()`, `queryRepository()`). *Erratum (2026-10-07):* `ALinqBridge::matching()` was listed but never existed.
    - Direct integration methods in `InMemoryRepository`: `asLinqCollection()` and `findAsLinqCollection($spec)`.
  - Modular trait-based decomposition of `SpecificationFactory` (reduced by 64% from 2,376 to 856 lines across 8 specialized traits in `src/Factory/Traits/`):
    - `CollectionSpecificationOperationsTrait`
    - `ComparisonSpecificationOperationsTrait`
    - `DateSpecificationOperationsTrait`
    - `LogicalSpecificationOperationsTrait`
    - `SpecialSpecificationOperationsTrait`
    - `SpecificationWrapperOperationsTrait`
    - `StringSpecificationOperationsTrait`
    - `TypeSpecificationOperationsTrait`

    *Erratum (2026-10-07):* the eight traits were originally listed as `Basic`, `Collection`, `Comparison`, `Logical`, `Range`, `String`, `Type` and `Utility` `SpecificationsTrait`; those names never existed. The real names are the ones above.
- **Testing Suite**:
  - 14 test modules (`Module1` through `Module14`) with 579 assertions and 100% pass rate. *Erratum (2026-10-07):* originally stated as 15 modules and 604 assertions; `Module15` arrived in 1.1.0 and the v1.0.0 badge reported 579.
  - Master test runner (`tests/run_all.php`) executing in ~40ms.

### Changed
- Refactored `SpecificationFactory` into 8 modular traits for optimal maintainability and separation of concerns.
- Refactored `CriteriaSpecificationVisitor` and `ALinqSpecificationVisitor` to leverage native PHP 8.4 pattern matching (`match (true)`).
- Standardized all 110+ classes with corporate Antevemus PHPDoc blocks, `@package`, `@subpackage`, `@author`, and `@license MIT`.
- Updated minimum PHP requirement to PHP 8.4+.

### Fixed
- Reentrancy handling in `SemaphoreSynchronizer` (in-process).
- Proper handling of null candidates in `PropertyAccessor` and `ALinqSpecificationVisitor`.
- Array key preservation and dot notation resolution in nested data structures.

### Security
- Strongly typed parameters and strict typing (`declare(strict_types=1)`) throughout all components.
- Parameterized SQL generation avoiding SQL injection vulnerabilities across all 12 drivers (7 dialects).
- Semaphore release guarantees in concurrent environments. *Erratum (2026-10-07):* originally stated as SysV IPC isolation; see the `SemaphoreSynchronizer` note above.

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
- 🧪 **15 Test Suites & 623 Assertions**: 100% pass rate with zero regressions. *Erratum (2026-10-07):* originally stated as 604; the v1.1.0 badge and runner reported 623.

---

### v1.0.0 - Initial Official Release

This is the initial official release of **Antevemus ASpecification**, a high-performance, enterprise-grade implementation of the Specification Pattern for PHP 8.4+.

**Installation:**
```bash
composer require antevemus/aspecification
```

---

[Unreleased]: https://github.com/antevemus-it/Antevemus.ASpecification/compare/v1.3.1...HEAD
[1.3.1]: https://github.com/antevemus-it/Antevemus.ASpecification/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/antevemus-it/Antevemus.ASpecification/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/antevemus-it/Antevemus.ASpecification/compare/v1.1.2...v1.2.0
[1.1.2]: https://github.com/antevemus-it/Antevemus.ASpecification/compare/v1.1.1...v1.1.2
[1.1.1]: https://github.com/antevemus-it/Antevemus.ASpecification/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/antevemus-it/Antevemus.ASpecification/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/antevemus-it/Antevemus.ASpecification/releases/tag/v1.0.0
