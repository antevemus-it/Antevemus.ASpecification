# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.5.0] - 2026-10-09

README Promises II (ROADMAP milestone 6, Reversa forward 017) and the first half of the announced-but-unshipped backlog (milestones 7 and 8, forward 020). Two declared breaking changes: Unicode case folding and `in()` becoming its own leaf.

### Added

- **`SysVSemaphoreSynchronizer`** (`src/Concurrent/`): the inter-process reader/writer lock the README promised, on SysV IPC semaphores (`ext-sysvsem`, Linux/Unix). `new SysVSemaphoreSynchronizer(string $name, int $maxPermits = 10000, int $permissions = 0666)`: two semaphores (a permit counter and a binary writer lock, writer preference), keys derived from the name (`keyFor()`), permits released on `release()`/`__destruct` and undone by the kernel if the process dies (`SEM_UNDO`), `remove()` deletes the set, reentrant per Fiber like the in-process class. Without the extension the constructor throws `RuntimeException` pointing to `SemaphoreSynchronizer`, which stays intra-process (its DocBlock says so now). Tested with a child process (`pcntl_fork`/`proc_open`), skipped where the extension is missing. Any repository accepts it: `new InMemoryRepository([], null, new SysVSemaphoreSynchronizer('customers', 8))`.
- **`InSpecification`** (`Specifications/Comparison/`): `in()` is a leaf of its own with strict typed identity (`===` against each member, `null` matches only as a member, a candidate comparable to no member raises `IncompatibleTypeException` like `equalTo()`), set equality, `getType()` `DateTimeInterface` when every member is a date, and algebra on every node: `X ⊇ in(V)` iff `X ⊇ equalTo(v)` for every `v`, `X ⟂ in(V)` iff `X ⟂ equalTo(v)` for every `v` (so `in` × `in` by subset/intersection, `greaterThan(3) ⊇ in(4, 5)`, `notEqualTo(3) ⊇ in(1, 2)`), `in([])` is the empty leaf: never satisfied, disjoint with everything, generalized by everything. `Spec::notIn()`, DSL `notIn()` and `SpecificationFactory::notIn()` (= `not(in(...))`). Translated by the three visitors: SQL `"col" IN (:p1, :p2)` (`null` member adds `OR "col" IS NULL`, empty set is the dialect's false form `1 = 0`), TCriteria `TFilter('col', 'IN', [...])`/`'NOT IN'`, ALinq strict `in_array()`.
- **Lazy sources everywhere**: `AbstractRepository::asLazyCollection()` and `findAsLazyCollection()` on every repository of the library (previously `InMemoryRepository` only), built on `IRepository::iterate()` through a callable that yields a fresh generator per traversal, so the ALinq collection stays re-iterable and `getAll()` is never called; `filterLazy()` over a repository filters through `iterate($spec)`, so partition pruning applies.
- **`Engine\PdoRuleCatalog`**: an `IRuleCatalog` read from the relational rule and document tables through PDO, in portable ANSI SQL (`active = 'Y'`, scope, scenario, product/plan applicability as the `IRuleCatalog` contract requires, optional validity window through the immutable `withAsOf()`), with configurable schema and table names (defaults `regra_negocio`, `grupo_documento_obrigatorio`, `grupo_documento_obrigatorio_tipo`). The SQL narrows the rows; the final applicability pass and the ordering reuse `InMemoryRuleCatalog`, so the same rules in memory and in the database produce the same verdict (tested on SQLite with the reference DDL `resources/sql/rule_catalog.ansi.sql`, which lists only the columns the engine reads). Previously the engine only had `InMemoryRuleCatalog` and `fromArray()`.
- **`Results\FailureSeverity`** (`ERROR`, `WARNING`, `INFO`): `SpecificationFailure` carries an optional severity (`getSeverity()`, `withSeverity()`, `SpecificationResult::failure(..., severity:)`), and the rule engine fills it from the effective action (`RuleAction::toSeverity()`: `bloquear` → ERROR, `alertar` → WARNING, `apenas_log` → INFO; the handler's action wins over the rule's, evaluation errors and document failures are ERROR). `hasBlockingErrors()` is unchanged. Announced by the 1.0.0 CHANGELOG and never shipped.
- **`Entities\AbstractUlidEntity` and `AbstractUuidV7Entity`** with `Util\UlidGenerator` (Crockford base32, 26 chars, monotonic within the millisecond, `OverflowException` when the 80 random bits overflow) and `Util\UuidV7Generator` (RFC 9562, 74-bit counter, advances one millisecond on overflow); injectable clock, `shared()`, `isValid()`, `timestampOf()`. Announced by the 1.0.0 CHANGELOG as ULID/UUID v7 identities and never shipped.

### Changed

- **Unicode case folding (breaking).** `equalIgnoreCase()` and `wildcardIgnoreCase()` (and the ALinq visitor shortcut) compare with `mb_strtolower()` in UTF-8 instead of `strcasecmp()`/`strtolower()` (ASCII only). **Before → after:** `Spec::equalIgnoreCase('ÁGUA')->isSatisfiedBy('água')` `false` → `true`. An accent is still a different letter (`'ÀGUA'` does not match `'água'`) and `ß` does not fold to `ss`. `fnmatch()` works per byte, so `?` does not match a multibyte character: use `*`. `ext-mbstring` moves from `suggest` to `require`.
- **`in()` is a leaf (breaking for code inspecting the tree or the generated SQL).** `in(1, 2)` was an `OrSpecification` of `EqualSpecification`, `in([])` was `AlwaysFalseSpecification`; both are `InSpecification` now, `equals()` compares sets, the failure `ruleName` is `InSpecification`, SQL `("c" = :p1 OR "c" = :p2)` becomes `"c" IN (:p1, :p2)`, TCriteria emits `IN` instead of an `OR` chain, and a mixed set no longer throws depending on member order (`in(1, 'a')->isSatisfiedBy('a')` was an exception, now `true`). `NOT ("c" IN (...))` keeps SQL three-valued logic (rows with `NULL` are not returned), as the `OR` chain did.
- **`ALinqBridge` accepts any `IRepository` and is typed.** Return types narrowed from `object` to `Antevemus\ALinq\Interfaces\IALinqCollection`/`IALinqLazyCollection` on the eight bridge methods, `AbstractRepository::asLazyCollection()`/`findAsLazyCollection()`, `InMemoryRepository::asLinqCollection()`/`findAsLinqCollection()` and `Spec::linq()`/`filterLinq()`/`linqLazy()`/`filterLazy()`. **Breaking for** a subclass overriding one of them with `object` as return type. `suggest` of `antevemus/alinq-collection` raised from `^1.1` to `^1.3`.
- **`iterate*()` is lazy in `SingleFileRepository` and `FilePerEntityRepository`.** The single-file repository evaluates on demand after `load()` (previously materialised through `findAll()`); the file-per-entity repository reads the directory with `readdir` and one file per pulled entity, never scanning the directory first (the iteration order is the directory order; `findAll()` keeps its sort). The in-memory, volatile-partition and hybrid repositories were already lazy and are now proven by test.

### Documentation

- Backlog triage (ROADMAP milestones 7, 11 and the "Reserved"/"Declined" sections): the `RelationalOperator` cases `BETWEEN`, `IN`, `LIKE` and `REGEX` announced by the 1.0.0 CHANGELOG are **declined**, those predicates are specifications (`between()`, `in()` now `InSpecification`, `WildcardSpecification`, `RegexSpecification`); `RequirementState`, the document requirement state machine announced by 1.0.0, is **reserved** for a specification-driven state machine feature being designed (vocabulary catalogued in `docs/state-machine/`); TTL on volatile repositories, the materialised partition DAG index and the DB2/Informix/DuckDB dialects are scheduled for 1.6.0; persisted per-entity metadata and the write-behind hybrid mode for 2.0.0 with the JSON-only envelope.
- README (EN and pt-BR): requirements (`ext-mbstring` required, `ext-sysvsem` optional for `SysVSemaphoreSynchronizer`, ALinq `^1.3`), concurrency and serialization lines, runner block (16 suites, 2 305 assertions; PHPUnit 231 tests, 4 371 assertions), roadmap list synchronised with `ROADMAP.md`.

### Deprecated

- **`PhpNativeEntitySerializer`**, removed in 2.0.0. It is the only `unserialize()` in the library and was the vector of the 2026-10 object-injection finding; `JsonEntitySerializer` covers private and readonly properties, dates, nested objects and constructor-less hydration. The constructor now emits `E_USER_DEPRECATED`; behaviour is unchanged. The non-JSON envelope branch of `SingleFileRepository` is deprecated with it. Migration: open the existing `.bin` with the native serializer, `put()` every entity into a repository built with `JsonEntitySerializer`, `store()`.

## [1.4.4] - 2026-10-09

Domian parity correction lot. A method-by-method audit of this library against the Domian trunk (r1209; 103 main sources in 6 modules, two of them never inventoried before) found that the subsumption algebra and the partition DAG did not behave as the Java reference and as the README promised. The Java tests were transcribed into `tests/Parity/` (213 tests, 2 043 assertions, a new PHPUnit suite "Domian Parity"); the audit reports and reproducible probes live in `docs/paridade-domian-2026-10-09/` and the consolidated findings in `docs/PARIDADE-DOMIAN-2026-10-09.md`. No public signature changes.

### Fixed

- **Subsumption algebra.** `isGeneralizationOf()`, `isSpecialCaseOf()`, `isDisjointWith()` and `intersectsWith()` now follow the Domian rules on every node: `and(A, B) ⊇ X` iff both sides generalize `X`; `X ⊇ and(A, B)` if either side is generalized; `or(A, B) ⊇ X` if either side generalizes `X` (previously **both** were required, so `A ⊂ or(A, B)` was `false`); `X ⊇ or(A, B)` iff both; `not(A) ⊇ not(B)` iff `B ⊇ A`; `A ⟂ not(A)`; reflexivity; `specify(Sub) ⊂ specify(Super)` by class hierarchy; `specify(T) ⊇ specify(T)->where(...)`; `adult ⊇ adult->and(pioneer)`; interval arithmetic on value-bound leaves of the same property (`gte(5) ⊇ gt(5)`, `lt(5) ⟂ gte(5)`, `isBefore(2020) ⊇ isBefore(2010)`, `after(A) ⟂ before(A)`, `isNull ⟂ isNotNull`, `not(lt(10)) ⟂ lt(10)`, integer gaps such as `gt(10) ⟂ lt(11)`); `NotNull` generalizes everything; `AlwaysTrue`/`AlwaysFalse` are leaves that resolve negations. Previously the composite default returned `equals()` only, so `all(Customer) ⊇ all(VipCustomer)` and `(A and B) ⊂ A` were `false` (37 of 61 assertions of the audit probe diverged; 2 remain, both deliberate, see below). **Before → after:** `Spec::specify(Customer::class)->isGeneralizationOf(Spec::specify(VipCustomer::class))` `false` → `true`.
- **Structural `equals()`.** Composite specifications and `Spec::specify(T)` compared object identity (two `Spec::specify(Customer::class)` were never equal, and a partition "equal" to an existing one was duplicated instead of reused). They now compare structure: class, type, children as an unordered set, `because()`/`withCode()`; property leaves by base, name and inner; value-bound leaves by class and value (dates by instant). `and()`/`or()` with an equal specification return the same instance. **Before → after:** `Spec::specify(T)->equals(Spec::specify(T))` `false` → `true`.
- **Named leaves instead of anonymous classes.** `greaterThanOrEqualTo()`/`atLeast()`, `lessThanOrEqualTo()`/`atMost()` return `GreaterThanOrEqualSpecification`/`LessThanOrEqualSpecification` (previously `or(gt, eq)`: the SQL and TCriteria visitors emitted `(x > :p OR x = :p)`, now `x >= :p`, and `evaluate()` reports one failure instead of two causes); `isNull()` returns `IsNullSpecification` (translated as `IS NULL`; previously untranslatable); `before()`, `after()`, `at()`, `beforeOrAt()`, `afterOrAt()` return value-bound leaves with `getValue()` and subsumption, `between(a, b)` is `and(gte(a), lte(b))`; `GreaterThan`/`LessThan` accept `DateTimeInterface`; the date leaves clone a mutable `DateTime` so the specification no longer changes when the original object is mutated.
- **Partition DAG (`PartitionRepository`).** Inserting a partition that generalizes existing ones re-wires the existing node under the new one (previously the node was recreated with its underlying repository only and its sub-partitions were lost: `findPartition()` stopped finding them); `findPartition()` descends by generalization to the most specific partition that generalizes the specification (previously equality only, so a narrower specification returned `null`); a specification that is a special case of two siblings becomes one node referenced by both parents (previously two nodes over the same repository, and `getAllPartitions()` counted it twice); `repartition()` of an entity that is not stored returns `false` without storing it (previously inserted it) and `true` when the entity already resides in the right partition; `put()` on a non-root partition with an entity outside its boundary delegates to the root, which routes it, and the root throws `InvalidArgumentException` when no partition accepts the entity (previously the entity stayed in the wrong repository); a sibling added after the entities receives the ones that satisfy it; iteration is lazy (one evaluation before the first item instead of all); the same set of partitions produces the same graph whatever the insertion order (tested over every permutation); `update()`/`updateWithDelta()` reach every repository that holds the entity before repartitioning; `repartitionAll()` counts the entities that actually moved; `load()`/`store()`/`close()` of a persistent partition reach persistent repositories under volatile ones and visit a shared node once; `getEntitiesOfThisPartitionOnly()` lists what physically resides in the node. `collectPartitions()` additionally accepts a specification over repository objects (Domian `collectAllPartitionsWithRepositorySatisfying`).
- **Repositories are synchronized.** No repository used `ISynchronizer`. `AbstractRepository` and `InMemoryRepository` now carry one (`withSynchronizer()`, `setSynchronizer()`, `getSynchronizer()`, default `NullSynchronizer`; `InMemoryRepository::__construct(array $initialEntities = [], ?string $repositoryId = null, ?ISynchronizer $synchronizer = null)`), run reads under `callConcurrently()` and writes under `callExclusively()`. `SemaphoreSynchronizer` is reentrant per execution context (main flow or Fiber), as the Java thread-local: an exclusive section inside a concurrent one of the same owner no longer throws; real contention between contexts still throws, with a message explaining that a single PHP process cannot wait.
- **`updateWithDelta()` applies the delta** in every repository (memory, partition, single-file, file-per-entity, hybrid): each value-bound property clause of the delta writes the property (public setter, else the field by reflection, private and inherited included), a `JointDenial` clause writes `null`, `null` delta is `update()`, an entity of another type raises `InvalidArgumentException`. Previously the delta was ignored everywhere.
- **`AbstractEntity::equals()` checks the type**: `Customer(102)->equals(Order(102))` was `true`, now `false` (same family is still equal: `VipCustomer(5)` equals `Customer(5)`); `hashCode()`, `toString()`/`__toString()` added. `JointDenialSpecification::isSatisfiedBy(null)` `true` → `false`. `NotNullSpecification::isGeneralizationOf()` `false` → `true`.
- **`StopWatch`** matches the Java `StopWatchTest`: `start()` on a started watch no longer restarts it, stop/start accumulates, `lap()` on a stopped watch measures nothing; `getElapsedTime()`, `getLapTime()`, `elapsedTimeToString()`, `lapTimeToString()`, `toString()`, `print()` added. `InstrumentationUtils` gains the Domian constants and helpers (`prettyPrintLargeNumber()`, stack-trace helpers, memory and thread messages, `printPartitionRepository()`).
- **Private and protected properties are specifiable**: `Spec::property('own', ...)` on a private field without getter resolved to an error; the `PropertyAccessor` now reads non-public properties by reflection as the **last** step, after arrays/`ArrayAccess`, public getters, `__get` guarded by `__isset` and public initialized properties (the documented order is unchanged; `ReflectionUtils` added in `Helpers/`).

### Added

- The 28 Domian `SpecificationFactory` names that were missing, as aliases on the factory, the `Spec` facade and the DSL: `allEntities()`, `entities()`, `entity()`, `allObjects()`, `createTautology()`, `createContradiction()`, `isGreaterThan()`, `isGreaterThanOrEqualTo()`, `isLessThan()`, `isLessThanOrEqualTo()`, `blankString()`/`isBlankString()` (a `null` is blank, unlike `isBlank()`), `defaultNumber()`, `defaultValueOfType()`, `isEnum()`, `objectEqualTo()`, `matchesWildcardExpression()`, `matchesWildcardExpressionIgnoringCase()` and the `create*Specification()` forms. `DefaultValueSpecification` accepts an optional type (`string`, `number`, `bool`, `array`).
- `StrictReturnsNullFactory` implements `IObjectFactory` (published without implementation since 1.0.0): builds the object a specification describes, or `null` when it cannot be built completely. `NullRepository` also implements `IFakeRepository`. `PersistenceDefinition::isNotMemoryBased()`, `isFileBasedOnly()`, `isMemoryBasedOnly()`, `supportsAsynchronousPersistence()`.

### Documentation

- `docs/DomianJavaToPhpAudit.md` (2026-10-02) is superseded by `docs/PARIDADE-DOMIAN-2026-10-09.md`: it counted 78 sources in 4 modules (the trunk has 103 in 6), omitted `domian-hibernate-repository` and `domian-test-benchmark`, and marked as ported types whose semantics diverged. README: the concurrency claims now say what the code does (in-process semaphore wired into the repositories, `flock` across processes, SysV scheduled for v1.5.0). The `1.0.0` entry below carries an erratum on "100% architectural parity".
- Deliberate differences from Domian, kept on purpose: `where()->where()` and `and('field', spec)` without a prior `where()` remain accepted; `not(A) ⊇ not(B)` is decided by subset complement; `or` leaves are not declared disjoint member by member; different properties are never proven disjoint; a `DateTime` is dense (no day granularity); the base `isDisjointWith()` default stays `false`; `and()` of a leaf with a disjoint specification does not throw.

## [1.4.3] - 2026-10-08

### Documentation

- **Provenance statements corrected.** The READMEs called the library both a "clean-room, independent rewrite" and a "full-fidelity", "official" port of Domian; none of the three holds. It is an independent, unofficial PHP reimplementation of Domian's architecture, public API and semantics, written with Domian's public Apache-2.0 sources at hand, not affiliated with or endorsed by the Domian authors. `NOTICE.md` and `THIRD_PARTY_NOTICES.md` now reproduce the copyright notice exactly as it appears in the Domian sources ("Copyright 2006-2010 the original author or authors"), name the developers from the project `pom.xml`, carry a provenance table (which PHP namespaces follow which Domian packages, and which parts are original to this library), and state the PHP 8.2 floor instead of 8.4. The `SemaphoreSynchronizer` DocBlock says "modeled on the algorithm of" instead of "direct port" (the Java original uses two semaphores and a thread-local; the PHP rewrite keeps the permit model with counters), and the three contract DocBlocks that reuse a sentence of the Domian javadoc now say so. No code change.

## [1.4.2] - 2026-10-08

### Fixed

- **TCriteria bridge: the `i` modifier of a regular expression survives.** `Spec::toCriteria()` dropped the PCRE modifiers together with the PHP delimiters, so `regex('/^abc/i')` and `regex('/^Abc/')` reached the database as the same `REGEXP '^abc'` / `'^Abc'`, and case sensitivity depended on the engine (MySQL's `REGEXP` is case-insensitive by default on non-binary columns, so the case-sensitive pattern was evaluated without case too). `TCriteria` has no dialect, so the flag now travels inside the pattern: `REGEXP '(?i)^abc'` for a pattern with `i`, `REGEXP '(?-i)^Abc'` without it, in plain and prepared mode and under `NOT`, verified against the real Adianti classes. `u` is accepted; any other modifier (`m`, `s`, `x`, ...) is refused with `NonTranslatableCriteriaException` naming it instead of being silently discarded, as the SQL visitor already did. A pattern without delimiters (built directly, the factory refuses it) is unchanged. **Breaking for** code matching the `REGEXP` value emitted by `toCriteria()` textually: every delimited pattern now carries the `(?i)`/`(?-i)` prefix. The `REGEXP` operator itself remains what the Adianti `TFilter` can render: it works on MySQL 8 (ICU), MariaDB (PCRE) and SQLite with a registered PCRE function; on PostgreSQL, Oracle, Firebird and SQL Server use `toSql()` with the dialect.

## [1.4.1] - 2026-10-07

### Fixed

- **`#[AssertSpec]` / `#[ValidateRule]` on a getter that throws.** An exception thrown by the annotated getter itself, before its specification or rule is evaluated, no longer propagates raw from `Spec::validateAttributes()`: it becomes a failure carrying the exception message, the attribute `code`, the method name and `metadata['evaluation_error']`, the result reports `isError()` with the exception, the remaining attributes are still evaluated, and `assertAttributes()` throws `AttributeValidationException` with the cause chained (`getPrevious()`), exactly as an error while evaluating the specification already did. **Breaking for** code that caught the getter's exception around `validateAttributes()`: read `isError()` / `exception` on the result instead.
- **TCriteria bridge: `startsWith()`, `endsWith()` and `contains()` translate to `LIKE`.** `Spec::toCriteria()` emitted them as `REGEXP`, an operator missing in SQLite, Firebird, SQL Server and ANSI (`~` on PostgreSQL), and dropped the case-insensitive flag on the way. They now become `col LIKE 'Ab%'` / `'%Ab'` / `'%Ab%'`, `UPPER(col) LIKE UPPER('ab%')` when case-insensitive, `NOT LIKE` under negation, with `%`, `_` and `!` of the literal escaped and an `ESCAPE '!'` clause appended by a dedicated `TFilter` subclass, in plain and prepared mode, verified against the real Adianti classes. A generic `regex()` still goes out as `REGEXP`. The value guard now inspects the literal, so a literal starting with `NOESC:` or `(SELECT`, or containing `{session.`, is refused like any other value.
- **`like()` / `wildcard()` escape the literal `%`, `_` and `!` of the pattern** in the SQL visitor (every dialect) and in the TCriteria bridge, adding `ESCAPE '!'` only when needed (also when the pattern contains a backslash, MySQL's default escape). `Spec::like('100%*')` was bound as `100%%` and matched `1000x`, which the in-memory evaluation (`fnmatch`) never did; it is now `100!%%` with `ESCAPE '!'`. `*` and `?` remain the consumer's wildcards. Patterns without those characters produce exactly the same SQL as before.

### Added

- `Sql\LikePattern` (`@internal`): the LIKE escaping rule shared by the SQL and TCriteria translators. `Criteria\TEscapedLikeFilter` (`@internal`): `TFilter` that appends the `ESCAPE` clause after the parent's rendering.

## [1.4.0] - 2026-10-07

### Changed
- **Remaining Portuguese messages and comments in `src/` translated to English** (write-rejection, atomic-write and lock-timeout messages of the file repositories, the hybrid repository constructor message, the partitioned-repository metadata message, the "cannot be null" guard of the collection specifications, the `(property '...')` suffix of `SpecificationFailure::__toString()`, and five inline comments). The 2026-10 sweep used the PHP tokenizer over every string and comment; what stays in Portuguese is by design: the rule-engine data contract (`codigo`, `tipo_regra`, `parametros`, ...) and the city name in DocBlock examples. **Breaking for** code matching those exception messages textually.
- **`#[AssertSpec]` rejects unknown specification classes.** A class name that does not exist, or that exists but does not implement `ISpecification`, now throws `Attributes\Exceptions\UnknownSpecificationClassException` (naming the class, the annotated target and the reason) on the first call to `Spec::validateAttributes()` / `Spec::assertAttributes()`, instead of being skipped in silence. **Breaking for** code that relied on a mistyped class name disabling the invariant: fix the name.
- **`#[AssertSpec]` follows the Notification Pattern error state.** The attribute validator evaluates with `evaluate()`: an exception thrown while evaluating the referenced specification becomes a failure carrying the exception message, the attribute `code`, the target and `metadata['evaluation_error']`, and the returned `SpecificationResult` reports `isError()` with the first exception, as the core and the rule engine already did. `assertAttributes()` throws `AttributeValidationException` with the cause chained (`getPrevious()`); its constructor accepts an optional third `$previous` argument. **Breaking for** code that caught the raw exception around `validateAttributes()`: read `isError()` / `exception` on the result instead.
- **ALinq visitor parity for childless composites.** `ALinqSpecificationVisitor` hands the candidate as-is to a user-defined composite specification with no children (previously a scalar candidate was replaced by `null`), so the compiled predicate agrees with `isSatisfiedBy()`, including the `TypeError` the core raises for a scalar on a `?object` composite.
- **`IRepository::contains(IEntity $entity): bool` enters the repository contract.** `AbstractRepository` provides the default (lookup by identity, then `equals()`), `InMemoryRepository` answers in O(1), `NullRepository` returns `false`, `PartitionRepository` checks the node and its sub-partitions, and `UnsupportedRepository`/`NotImplementedRepository` throw `BadMethodCallException` like their other methods. `InMemoryAndFileRepository::contains()` (which delegated to a method the cache never had and failed with `Error`) now works. **Breaking for** external implementations of `IRepository` that extend neither `AbstractRepository` nor `PartitionRepository`: implement `contains()`.
- **Oracle and Firebird dialects quote identifiers in upper case** (`"VAL_SALARY"`, `"T"."VAL_SALARY"`), matching the columns created by plain DDL on those engines (unquoted identifiers are stored upper-cased, so the previous `"val_salary"` never matched). A `FieldMapper` function value is still emitted verbatim. **Breaking for** columns created quoted in lower case on Oracle/Firebird: map them through the `FieldMapper`.
- **`IRuleCatalog::findRules()` has a written `$filters` contract.** The reserved keys `codigo_produto`, `codigo_plano` and `data_referencia` narrow the catalog with AND semantics mirroring the document scope rules: a rule restricted to a product or plan only applies when the matching filter is present and equal, global rules (null) always apply, the validity window (`data_inicio_vigencia`/`data_fim_vigencia`, inclusive by day) is only evaluated when `data_referencia` is given, an unreadable date throws `InvalidArgumentException`, unknown keys are ignored. `RuleDefinition::fromArray()` hydrates the applicability columns into `parametros` (`RuleDefinition::APPLICABILITY_COLUMNS`) and `InMemoryRuleCatalog` honours the filters (`InMemoryRuleCatalog::FILTER_*`); the dynamic engine keeps passing the validation context as filters. The Module 11 tests are the oracle for any future catalog implementation. **Breaking for** in-memory rules carrying those keys in `parametros` when the validation context does not bring the matching product or plan: they no longer apply.

### Fixed
- **`startsWith()`, `endsWith()` and `contains()` translate to a portable `LIKE`** with wildcard escaping (`%`, `_`, `!`, backslash, `ESCAPE '!'` only when needed) in every SQL dialect. Previously they were emitted as a regex carrying the PHP delimiters (`/^Ab/`), which never matched on PostgreSQL, MySQL and Oracle and was refused on SQLite, Firebird and ANSI. In-memory evaluation is unchanged; the three factories now build `StartsWithSpecification`, `EndsWithSpecification` and `ContainsSpecification` (which extend `RegexSpecification`), so the failure `ruleName` of those leaves is the new class name. Generic `RegexSpecification` binds no longer carry the PHP delimiters: the `i` modifier maps to the dialect's case-insensitive operator, `u` is accepted, any other modifier is refused with `UnsupportedSqlOperationException`; a legacy pattern without delimiters passes unchanged.
- **`JsonEntitySerializer` no longer calls persistence-metadata methods that do not exist.** The dead "persistent envelope" branch of `serialize()` is gone and `deserialize()` ignores a `__persistence_metadata` envelope found in an external document, always returning the entity (previously it failed with `Error`/`TypeError`). The envelope was documented by the file-repository data delta and never produced by any version; persisted per-entity metadata is listed in the ROADMAP backlog.
- **File repositories record per-entity persistence metadata for the current session.** `SingleFileRepository` and `FilePerEntityRepository` register writes on `put`/`putAll`, reads on `findAll`/`findSingle`/`iterate`/`count` and forget on `remove`/`removeAll`/`clear`, so `getEntityMetaData()` returns the entity's metadata after the first operation in this instance instead of always `null`; a new instance starts empty, and the hybrid repository reflects its file backend only. `AbstractFileRepository` gains `forgetMetadata()`/`clearMetadata()`.
- **`PartitionRepository::addPartitionWithId()` keeps the id and refuses persistent nodes explicitly.** On a volatile node the id is passed to the child constructor when it declares `repositoryId` (`InMemoryRepository` now accepts `?string $repositoryId` and exposes `getRepositoryId()`); on a persistent node (constructor requires a storage path) it throws the new `Contracts\Repositories\Exceptions\PartitionCreationException` naming the type and id and pointing to `addPartitionWithRepository()`, instead of failing with `ArgumentCountError`. No storage path is ever derived by the library.

### Added
- `StartsWithSpecification`, `EndsWithSpecification`, `ContainsSpecification` (`Specifications\String`, extend `RegexSpecification`); `RegexSpecification::getBody()` / `getModifiers()`.
- `Attributes\Exceptions\UnknownSpecificationClassException`; `Contracts\Repositories\Exceptions\PartitionCreationException`.
- `InMemoryRepository::getRepositoryId()`; `AbstractFileRepository::forgetMetadata()` / `clearMetadata()`.
- `RuleDefinition::APPLICABILITY_COLUMNS`; `InMemoryRuleCatalog::FILTER_PRODUCT` / `FILTER_PLAN` / `FILTER_REFERENCE_DATE`.

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
  - 100% architectural parity with Java `Domian` specification library. *Erratum (2026-10-09):* the 2026-10-09 parity audit (`docs/PARIDADE-DOMIAN-2026-10-09.md`) measured 103 Domian sources: 12 ported faithfully, 34 adapted, 28 with divergent semantics, 6 absent, 23 not applicable; the subsumption algebra, the partition DAG and the synchronized repositories were corrected in 1.4.4, a PDO repository (Domian `HibernateRepository`) is a roadmap item.
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

[Unreleased]: https://github.com/antevemus-it/Antevemus.ASpecification/compare/v1.5.0...HEAD
[1.5.0]: https://github.com/antevemus-it/Antevemus.ASpecification/compare/v1.4.4...v1.5.0
[1.4.4]: https://github.com/antevemus-it/Antevemus.ASpecification/compare/v1.4.3...v1.4.4
[1.4.3]: https://github.com/antevemus-it/Antevemus.ASpecification/compare/v1.4.2...v1.4.3
[1.4.2]: https://github.com/antevemus-it/Antevemus.ASpecification/compare/v1.4.1...v1.4.2
[1.4.1]: https://github.com/antevemus-it/Antevemus.ASpecification/compare/v1.4.0...v1.4.1
[1.4.0]: https://github.com/antevemus-it/Antevemus.ASpecification/compare/v1.3.1...v1.4.0
[1.3.1]: https://github.com/antevemus-it/Antevemus.ASpecification/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/antevemus-it/Antevemus.ASpecification/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/antevemus-it/Antevemus.ASpecification/compare/v1.1.2...v1.2.0
[1.1.2]: https://github.com/antevemus-it/Antevemus.ASpecification/compare/v1.1.1...v1.1.2
[1.1.1]: https://github.com/antevemus-it/Antevemus.ASpecification/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/antevemus-it/Antevemus.ASpecification/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/antevemus-it/Antevemus.ASpecification/releases/tag/v1.0.0
