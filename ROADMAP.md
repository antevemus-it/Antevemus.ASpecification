# Product Roadmap & Future Milestones — Antevemus ASpecification

This document outlines the engineering roadmap of the **Antevemus ASpecification** framework: what shipped, what is scheduled into which version, what is reserved for a design still in progress, and what was explicitly declined. Milestones are grouped by target release; every scheduled milestone gets a forward specification before code.

---

## ✅ Completed Milestones

### 1. English PHP DocBlock Internationalization 🌐 (Shipped in v1.1.0)
- **Description:** Complete translation and standardization of all class and method DocBlocks across the codebase (`src/` and `src/Contracts/`) into idiomatic technical English.
- **Goal:** Full alignment with international PHP-FIG standards (PSR-5 / PSR-19) and seamless developer onboarding for global contributors and static analysis tools.

### 2. Native PHP 8.4 Declarative Attributes (`#[AssertSpec]`, `#[ValidateRule]`) 🏷️ (Shipped in v1.1.0)
- **Description:** Declarative attributes to annotate class properties, DTOs, and entity fields directly with specifications.
- **Goal:** Zero-boilerplate validation pipelines in REST controllers, form requests, and event handlers using native PHP 8.4 reflection.

### 3. ALinq Lazy Streaming Pipeline & O(1) RAM Evaluation 🌊 (Shipped in v1.1.0)
- **Description:** Deep integration with `Antevemus.AlinqCollection` (`ALinqLazyCollection`) enabling deferred streaming evaluation of domain specifications over infinite generators and massive datasets.
- **Goal:** Constant \(O(1)\) RAM footprint when filtering large volumes of entities, logs, or external data streams without preloading collections into memory.

### 4. Review-Driven Hardening: Error State, Strict Typing, Rule Binding & Document Matrix 🛡️ (Shipped in v1.2.0)
- **Description:** Evaluation errors became a first-class `error` state (never inverted by `NOT`), comparisons refuse incompatible types (`looselyEqualTo()` as the opt-in), the Dynamic Rule Engine binds every compiled rule to its catalog definition, the document matrix got exact scope/scenario matching with mandatory alternative sets, `equals()` entered the specification contract, and file repositories gained cross-process locking.
- **Goal:** No silently wrong verdicts: every ambiguity found by the 2026-10 technical review now either evaluates correctly or fails loudly at compilation.

### 5. README Promises I: Every Documented Example Runs 📘 (Shipped in v1.3.0)
- **Description:** The 2026-10 line-by-line README audit executed every code block against the library and found APIs the README promises that do not exist yet. This milestone implements the additive ones so that all eleven README examples run as written: `specify(T)->must(closure, code, message)` (inline rule with code and message, README example 7), `ISqlWhereClause::getSql()`/`getBindings()` beside `toSql()`/`getParameters()` (example 8), `Spec::toSql(fieldMapper: ...)` accepted beside `fieldMap:` (example 8), the `not_blank` operator and the `value:` parameter of `#[ValidateRule]` (example 11), `Spec::nor()`/`noneOf()` and the DSL `nor()` exposing `JointDenialSpecification` (Core Features), `TCriteriaBuilder::withFieldMapping()`, `offset()` and `toCriteria()` (pt-BR example 9), and a named factory `InMemoryAndFileRepository::create(storagePath:, serializer:)` (example 5).
- **Goal:** No README promise without code behind it. Each item ships with the README example itself as its test.

---

## ✅ v1.4.4 — Domian Parity Correction Lot (Shipped in v1.4.4)

### 5a. Domian Parity: Subsumption Algebra, Partition DAG, Synchronized Repositories, Entities & Utilities 🧭
- **Description:** The 2026-10-09 method-by-method parity audit against the Domian trunk (r1209, 103 main sources in 6 modules; reports in `docs/paridade-domian-2026-10-09/`) found that the subsumption algebra and the partition DAG did not behave as the Java reference and as this README promise: composite `equals()` compared object identity, `OrSpecification::isGeneralizationOf` required both sides, `NotSpecification` had no algebra, date and `>=`/`<=` leaves were anonymous classes without subsumption; inserting a generalizing partition lost sub-partitions, `findPartition` matched only by equality, `repartition()` of a missing entity inserted it, iteration materialised; no repository used `ISynchronizer`; `AbstractEntity::equals` ignored the type; `StopWatch`, `updateWithDelta`, 28 factory names, `StrictReturnsNullFactory` and private-field access were missing or wrong. This lot ports the Domian rules faithfully, with the Java tests transcribed into `tests/Parity/`.
- **Goal:** "Venn Set Subsumption" and "DAG partitioning" mean what the README says. No public signature changes (PATCH).

---

## ✅ v1.5.0 — README Promises II & Announced Backlog I (Shipped in v1.5.0)

### 6. README Promises II: Inter-Process, Unicode, Lazy Sources, Relational Catalog & `IN` 🧵 (Shipped in v1.5.0)
- **Description:** The remaining README promises that change observable behaviour: a `SysVSemaphoreSynchronizer` backed by SysV IPC (`sem_get`, `ext-sysvsem`) as its own class beside the in-process `SemaphoreSynchronizer`; Unicode-aware case-insensitive string specifications via `mb_*` (`ext-mbstring` becomes required); `IRepository::iterate()` as a true lazy source for `findAsLazyCollection()` (generator, no `getAll()` materialisation); typed return types on the ALinq bridge with `antevemus/alinq-collection ^1.3` in `suggest`; `PdoRuleCatalog`, a PDO-backed `IRuleCatalog` mirroring the relational rule and document tables (configurable table names, reference ANSI DDL shipped); and an `InSpecification` leaf so that `in()` is translated as `IN (...)` by the SQL, TCriteria and ALinq visitors (today a chain of `OR` equalities).
- **Goal:** The Requirements and Core Features sections describe what the code does. Two declared breaking changes: Unicode case folding and `in()` becoming its own leaf.

### 7. Announced-but-Unshipped Backlog I: ULID / UUID v7 Identities & Failure Severity 📋 (Shipped in v1.5.0)
- **Description:** Two capabilities announced by the `1.0.0` CHANGELOG and never shipped, both additive: `AbstractUlidEntity` and `AbstractUuidV7Entity` (time-ordered identities beside `AbstractUUIDEntity`), and a `FailureSeverity` (`ERROR`, `WARNING`, `INFO`) on `SpecificationFailure`, filled by the rule engine from the rule's action (`bloquear`, `alertar`, `apenas_log`).
- **Goal:** Promises become code or get an explicit note. The same release records the two items declined (see "Declined") and the one reserved (see "Reserved").

### 8. PHP-Native Serializer Retirement, Step 1: Deprecation ⚠️ (Shipped in v1.5.0)
- **Description:** `PhpNativeEntitySerializer` and the `.bin` envelope of `SingleFileRepository` are the only use of `unserialize()` in the library and were the vector of the 2026-10 security finding (object injection through the envelope). The JSON serializer covers private and readonly properties, dates, nested objects and constructor-less hydration. In v1.5.0 the class is marked `@deprecated` and emits `E_USER_DEPRECATED`; the README documents the `.bin` → `.json` migration recipe. Nothing is removed before v2.0.0.
- **Goal:** A library with zero `unserialize()` by v2.0.0, announced one MINOR ahead.

---

## ✅ v1.6.0 — Algebra & Announced Backlog II (Shipped in v1.6.0)

### 9. `MethodParameterizedSpecification`: Declarative Method Calls 🔬 (Shipped in v1.6.0)
- **Description:** The README tree promises `Specifications/Reflection/` with parameterized specifications. Field access already exists as `where()` with the `PropertyAccessor`, so no field-parameterized class is built. The method-parameterized one is: `Spec::calling('isEligibleFor', [$date], $resultSpec)` calls a method of the candidate with arguments declared as data (scalars, arrays, enums, dates) and applies a specification to the result. Unlike `must(closure)`, it has structural `equals()`, its own type, and can be described in a rule catalog.
- **Goal:** Close the README promise with the half that adds value, and state plainly that `where()` is the field half.

### 10. Tautology & Contradiction Detection 📐 (Shipped in v1.6.0)
- **Description:** `isTautology()` and `isContradiction()` on every specification, structural and conservative (`true` only when the shape of the tree proves it): `AlwaysTrue`/`AlwaysFalse`, `not`, flattened `and`/`or` with `A ∧ ¬A` and `A ∨ ¬A`, disjoint leaves through `isDisjointWith`, `in([])`, inverted `between`. A truth-table test guards soundness. The rule engine reports a `RuleCompilationWarning` for catalog rules that can never fail or never pass.
- **Goal:** Close the last README promise of the 2026-10 audit without a SAT solver and without touching `evaluate()`.

### 11. Announced-but-Unshipped Backlog II: TTL, Partition DAG Index & Three SQL Dialects 📋 (Shipped in v1.6.0)
- **Description:** TTL and auto-pruning on volatile repositories (`withTtl()`, `prune()`); a materialised index on the partition DAG (topological order and clusters of mutually disjoint sibling partitions) so that routing and traversal stop testing every sibling in large DAGs, shipped only if a reproducible benchmark proves the gain at 100+ partitions; and the DB2, Informix and DuckDB SQL dialects.
- **Goal:** Each item is either code with a benchmark or a measured number explaining why it is not. **Outcome:** the traversal index ships on by default (`findAll` 45 % to 77 % faster); the routing clusters ship off by default because building them (one `isDisjointWith()` per sibling pair, 88 ms for two `in()` sets of 162 values) made `put()` 3 to 6 times slower with library specifications (`tests/Benchmark/REPORT-dag-index.md`); `paginate()` added to every dialect on the way.

---

## ✅ v1.8.0 — Spec-Driven State Machine & Use Cases (Shipped in v1.8.0)

### 11a. Specification-Driven State Machine 🚦 (Shipped in v1.8.0)
- **Description:** `Antevemus\ASpecification\StateMachine`: a state machine in which every guard is an `ISpecification`, so a denied transition returns the reason (code, message, property, severity) instead of a bare `false`. Event first (`send(event)`, the first candidate whose guard passes wins); one or more parallel regions, `inState('region.state')` across regions, region-level transitions, all-or-nothing events; named actions turned into effects in `exit` → transition → `entry` order; final states are inert and a region without final states (an orthogonal flag) does not hold the machine open; persisted deadlines (ISO-8601 duration, internal deadline event). The definition is data (fluent builder or JSON, stable key and version, lossless round trip, fingerprint) checked at load time by a validator that refuses structural errors and warns about unreachable states, dead ends, overlapping or shadowed guards and guards that always pass. A pure core (`transition()`, `can()`, `nextEvents()`) and a single write port, `MachineRunner`: snapshot (compare-and-set), append-only history and transactional outbox in one transaction, idempotency key per instance, effects delivered after the commit by `EffectDispatcher`, synchronous effects that may veto, instances pinned to their definition version with explicit migrations, `findDue()`/`deliverDue()` for deadlines. InMemory and PDO stores share one test battery over the reference DDL `resources/sql/state_machine.ansi.sql`.
- **Goal:** Delivers the reserved `RequirementState`: the document requirement lifecycle (pending → delivered → validated, or expired by deadline) is now a machine definition (README example 14.4), so it needs no class of its own. Acceptance was a real production quote workflow (20 progress states) rewritten with the pending flag as a parallel region, which removes the "back to where it was" bookkeeping the original needed.
- **Out of scope (deliberate):** actors, `invoke`/`spawn`, eventless (`always`) transitions and `raise`, nested states inside a region, history states, a machine-owned context, event sourcing as the source of truth.

### 11b. Use Cases With a Result Shared With the State Machine 🧭 (Shipped in v1.8.0)
- **Description:** `Antevemus\ASpecification\UseCase`: `IUseCase` (one method, `execute()`), `UseCaseRunner` (middlewares, request validation by attributes and by specifications registered per request class, where only `ERROR` blocks and warnings come back with the success, unhandled exceptions turned into `FAILED` without leaking anything to the result), `TransactionMiddleware`, `UseCaseResult` with a closed domain status (`UseCaseStatus`, HTTP only in `toHttpStatus()`), and `StateMachineEventUseCase`, which exposes a machine event as a use case. `Results\IOutcome` is the shape shared by `UseCaseResult` and the machine's `TransitionResult`.
- **Goal:** The layer that orchestrates entities, repositories and specifications, with one outcome type from the state machine to the edge of the application. No mediator, container or handler discovery.

---

## ✅ v1.9.0 — Specification `simplify()` & Context Deadlines (Shipped in v1.9.0)

### 12. Specification `simplify()` 🧮 (Shipped in v1.9.0)
- **Description:** Structural simplification built on milestones 5a and 10: `SpecificationSimplifier::simplify()` (with `AbstractSpecification::simplify()` and `Spec::simplify()`) applies identity, annihilation, double negation, idempotence, flattening of nested `and`/`or`, contradictory conjunctions collapsed to `alwaysFalse()`, tautological disjunctions to `alwaysTrue()` and subsumption, bottom-up to a fixed point. Nodes annotated with `because()`/`withCode()` are kept (simplified only inside), so the verdict and the annotated messages do not change. The equivalence is of truth value only, for non-null candidates. The rule engine offers it as an opt-in (`withSimplification()`); the visitors compile the tree as given, since simplifying automatically would change the SQL they generate.
- **Goal:** Smaller trees for the visitors and for the catalog, without losing a single annotated message.

The same release extends the 1.8.0 state machine (milestone 11a) with deadlines read from the context: a duration (`afterFrom`) or an absolute instant (`atFrom`) taken from the instance's context when the state is entered, a missing or invalid source rejected with `SM_DEADLINE_SOURCE_INVALID` instead of a silently empty deadline, and a pause condition (`pausedWhile`) that stops the deadline and keeps the balance while it holds (an SLA paused while a pending item is open). `deliverDue()` accepts a context provider, and the reference DDL gains the nullable `paused_remaining` column.

---

## ✅ v1.10.0 — Concurrent Data Resolution, Core (Shipped in v1.10.0)

### 15. Concurrent Data Resolution for I/O-Bound Specifications ⚡ (Core shipped in v1.10.0)
- **Description:** Specifications stay pure: a specification that depends on external data (a credit bureau score, a fraud check, a microservice lookup) does not call the service itself, it **declares** the data it needs (`Spec::external('credit.score', ['document' => 'customer.cpf'], Spec::greaterThanOrEqualTo(700))`). Before evaluation, a `DataResolver` collects the declarations of the whole tree (deduplicated by a canonical request key) and fetches the data **concurrently**, then evaluation runs over the resolved data. The core resolver uses `curl_multi` (zero dependency, works under PHP-FPM; `ext-curl` is a `suggest`): `HttpDataSource` builds each call from a fixed base URL and a path template whose parameters are percent-encoded, so a parameter cannot change the host or the path; `CallableDataSource` covers synchronous non-HTTP sources. A **cost policy** chooses between fetching everything at once (`concurrent()`), fetching by cost with a three-valued evaluation after each stage that skips the sources whose result can no longer change the verdict (`tiered()`, an explicit choice; `concurrent()` is the default), and fetching each leaf when the evaluation reaches it (`sequential()`). Outside a resolver a leaf fetches its own data synchronously, so every specification keeps working without it. A failed fetch (status, transport, time limit of the source or of the whole resolution, parser) is an evaluation error of its leaf, with the status code and never the response body; there is no retry. An optional cache between resolutions (`IDataCache`, in memory in the core) uses the TTL of each source. The SQL, TCriteria and ALinq visitors refuse the leaf.
- **Why not "evaluate specifications in Fibers":** a Fiber alone does not make I/O non-blocking. A blocking client (PDO, plain `curl_exec`, `file_get_contents`) inside a Fiber still blocks the whole process; concurrency only happens when the I/O itself is driven by a non-blocking client (`curl_multi`, an event loop driver). And Fibers are cooperative scheduling in one thread, not parallelism: CPU-bound specifications gain nothing. Moving the I/O out of the specifications and into a resolver gets the concurrency where it actually is.
- **What stays as it is:** evaluation remains synchronous, so `because()`/`withCode()`, the SQL and `TCriteria` compilation of the same specifications, the existing tests and the generic error message of 1.8.0 (an exception never reaches the failure) keep working unchanged. State machine guards stay pure: a use case resolves the data before sending the event.
- **Goal:** Concurrent fetching of the data I/O-bound rules need, without async-coloring the specification API and without a dependency in the core.
- **Not in this release:** the Fibers/Revolt adapter for applications whose SDKs are already asynchronous (milestone 15b), retries and circuit breakers, and the PSR-16 cache adapter (milestone 16).

---

## ✅ v1.11.0 — PDO Repository & Domain Event Triggers (Shipped in v1.11.0)

### 21. PDO-Backed Repository (the Domian `HibernateRepository`) 🗄️ (Shipped in v1.11.0)
- **Description:** Domian shipped a 799-line `HibernateRepository` that turns a specification into an ORM query; the PHP port had the query half (`SqlQueryVisitor`, `TCriteria`) but no `IRepository` over a database (found by the 2026-10-09 parity audit). `Repositories\Pdo\PdoRepository` closes it without an ORM: a query is a specification compiled to parameterized SQL by the SQL visitor (any of its dialects), a write is a prepared `INSERT`/`UPDATE`/`DELETE` in a transaction, `iterate()` streams the cursor in constant memory, `count()` builds no entity, `findSingle()` asks for at most two rows, `findPage()` paginates through the dialect. The entity is mapped by its author with attributes (`#[Table]`, `#[Column]`, `#[Id]`, `#[Version]`) or with the `EntityMapping` builder; identifiers `auto`, `ulid`, `uuid7`, `assigned` and `max` (compatible with Adianti's `IDPOLICY = 'max'`, documented as race-prone); optimistic locking with a version counter. A specification that cannot be translated is refused instead of filtering the table in memory (`withInMemoryFallback()` is the explicit opt-in for small tables), and only properties a specification may read by name can be queried, so a private column is never a read oracle through SQL. The transaction port becomes shared (`Persistence\ITransactionManager`, `PdoTransactionManager`), extended by the state machine's types without a break, so the repository, the outboxes and the machine stores can write in one transaction. The contract battery of the in-memory repository runs against it in SQLite in the suite and against PostgreSQL 17 and MySQL 8.4 in disposable containers (`tests/Fixtures/pdo_repository/run_portability.sh`).
- **Goal:** The repository family covers memory, file and database, as Domian's did, and an application can replace an ORM's record, repository and criteria classes with specifications on the data layer.
- **Not in this release:** relationships and joins, DDL generation, second-level cache, partitions as `WHERE` fragments (`makePartition()` is refused); see milestone 21b (shipped in v1.13.0).

### 19. Specification-Driven Domain Event Triggers 📡 (Shipped in v1.11.0)
- **Description:** `DomainEvents\SpecificationTrigger` binds a specification to an enter and an exit event: when an entity starts to satisfy it, the enter event; when it stops, the exit event ("policy delinquent for more than 30 days" → `delinquency.started` / `delinquency.ended`). `TriggerEvaluator` compares the before and the after of a write (a creation that already satisfies is an enter, a removal of an entity that satisfied is an exit; an evaluation error emits nothing and is returned for the log). `TriggeringRepository` decorates any `IRepository` and does it on every write; over a `PdoRepository` it reads the "before" with an identity specification compiled to SQL. Events are immutable, carry a deterministic idempotency key and a payload without personal data (the trigger and the entity id), and go to a publisher: in production an outbox (in memory or PDO, reference DDL `resources/sql/domain_events.ansi.sql`) written in the same transaction as the write and delivered after the commit by `DomainEventDispatcher`, in the design of the 1.8.0 effect outbox. `MachineEventBridge` delivers an event to a state machine instance as a transition trigger, idempotently. Renamed in 1.8.0 from "Reactive Domain Event Sourcing Triggers": it never meant event sourcing.
- **Goal:** "Something became true about this entity" is declared once, as a specification, and reaches handlers and state machines reliably, without polling and without leaking the entity.
- **Not in this release:** crossings caused by time alone, without a write (milestone 19b, shipped in v1.12.0); an external bus client (publish from a handler); event sourcing.

---

## ✅ v1.12.0 — Time-Based Domain Event Triggers (Shipped in v1.12.0)

### 19b. Time-Based Domain Event Triggers ⏰ (Shipped in v1.12.0)
- **Description:** The triggers of v1.11.0 (milestone 19) are evaluated on writes, so a specification whose truth changes with time alone ("unpaid and due more than 30 days ago") crossed only when something wrote the entity. `SpecificationTrigger::timed()` builds its specification from the instant of each evaluation (a factory `fn(DateTimeImmutable $now): ISpecification`, which never reads a clock); `DomainEvents\State\ITriggerStateStore` (in memory and PDO, table `domain_event_trigger_state` in `resources/sql/domain_events.ansi.sql`) keeps the last known state of every trigger for every entity; `TriggerSweeper::sweep($now, $limit)`, run by the application's cron as the state machine's `deliverDue()` is, compares it with the truth at `$now` and publishes the crossings through the same publisher and outbox. A sweep that finds no crossing loads no entity (one page of states and one `COUNT(*)` per 500 entities recorded inside, then one `COUNT(*)`); each crossing is one transaction with a compare-and-set of the state on the version read, so two sweepers, or a sweeper and a write, publish it once. `baseline($now)` introduces a trigger over existing data without publishing an enter for everything already inside. A trigger may declare the real instant of its crossings (`crossedAt`), which keeps the idempotency key stable across sweeps and processes. `TriggeringRepository` takes the same store: its "before" is the recorded state, which also lifts the in-memory limitation of v1.11.0.
- **Goal:** Time-based crossings without a write, with the same events, keys and outbox as the write path, correct under concurrent sweepers and in constant memory.
- **Not in this release:** an embedded scheduler (the cron is the application's) and partitioning of a sweep between processes (two sweepers are correct together, not faster).

---

## ✅ v1.13.0 — `PdoRepository` Follow-ups (Shipped in v1.13.0)

### 21b. `PdoRepository` Follow-ups 🗄️ (Shipped in v1.13.0)
- **Description:** What v1.11.0 (milestone 21) left out on purpose, in three parts, all optional. **Lifecycle and types:** a SQL log behind `Persistence\IQueryLogger` (parameter values only when asked for, since they can be personal data); booleans stored as 1/0 on every driver (`BooleanStorage::Integer`, for the `SMALLINT` flags of legacy schemas); default time zones per mapping and per repository, for tables holding local time without an offset; `#[CreatedAt]`, `#[UpdatedAt]` and `#[SoftDelete]` with `withTrashed()`/`onlyTrashed()`/`forceRemove()`; `beforePut`/`afterPut`/`beforeRemove`/`afterRemove` hooks on the repository, inside the write's transaction (the entity stays a plain object); `EntityMapping::extract()`/`hydrate()`; `insertAll()` with multi-row `INSERT`s and `putAll()` with one existence `SELECT` per batch. **Set-based operations:** `updateWhere()` (one `UPDATE` by specification that stamps the modification time and increments the version counter, without per-entity hooks), `select()` projections, `aggregate()->groupBy()->sum()/avg()/min()/max()/count()->get()` with SQL's null rules, and `Spec::inQuery()` subqueries over another repository on the same connection. **Relationships:** `#[ManyToOne]` references (never cascaded) and `#[OneToMany]` collections (composition opt-in with `cascade: true`), loaded explicitly with `with('items', 'items.product')`, one query per relation and batch instead of one per entity (1 000 orders with 10 items each: 2 queries instead of 1 001), `isLoaded()`, `whereRelation()`, and cascades that never take an unloaded collection as empty and remove only orphans that were loaded with their parent. Checked in SQLite, PostgreSQL 17 and MySQL 8.4.
- **Goal:** The data layer of an application built on Adianti's `TRecord` (logs, integer flags, local time, timestamps, soft delete, hooks, `toArray()`/`fromArray()`, master-detail compositions) can move to the repository without hand-written SQL beside it.
- **Not in this release:** see milestone 21c (shipped in v1.14.0).

---

## ✅ v1.14.0 — `PdoRepository` Follow-ups II (Shipped in v1.14.0)

### 21c. `PdoRepository` Follow-ups II 🗄️ (Shipped in v1.14.0)
- **Description:** What v1.13.0 (milestone 21b) left out, all optional. `#[ManyToMany(target, joinTable, joinColumn, inverseJoinColumn)]` (and `EntityMapping::manyToMany()`): an association, never a composition; loaded in batch with `with('tags')` (the join table, then the targets: a fixed number of queries per batch, 3 for 1 000 owners of 5 targets each), a soft-removed target left out of the collection while its link stays; `put()` of an owner whose collection was loaded synchronizes only the join table (links added and removed, no target ever written or deleted, a target without identifier refused), a collection that was not loaded never touches it; a hard removal of the owner deletes its links, a soft one keeps them; `whereRelation('tags', $spec)` through the join table. `markLoaded()`/`markUnloaded()`: a collection built by hand can be declared loaded and whole, so its next `put()` removes the orphans or links it lacks (documented danger: an incomplete collection deletes what it lacks). `iterate()` with relations pages by key and closes each page's cursor before loading its relations, so it works on MySQL unbuffered queries with the memory of one page (10 000 rows: about 1 MB at the peak against 20 MB for `find()`). `BooleanStorage::Char` for the `CHAR(1)` flags of legacy schemas (`'Y'`/`'N'` unless declared otherwise), read strictly: any other stored value is a `DataIntegrityException`, never a silent false. Checked in SQLite, PostgreSQL 17 and MySQL 8.4 (unbuffered for `iterate()`).
- **Goal:** Close the remaining cases of the relationship and column options without a lazy-loading proxy, so that the Adianti flag columns and master-detail aggregates with associations move to the repository.

---

## ✅ v2.0.0 — Zero `unserialize` & Shared Accessor (Shipped in v2.0.0)

### 13. PHP-Native Serializer Retirement, Step 2: Removal & JSON-Only Envelope 🗑️ (Shipped in v2.0.0)
- **Description:** `PhpNativeEntitySerializer` and the `.bin` branch are removed; the single-file envelope is always JSON and owned by the repository, the serializer only serialises the entity; existing `.bin` files (single-file envelopes and file-per-entity `<id>.bin`) fail with a `RepositoryException` that points to the conversion recipe of `UPGRADE-2.0.md`, which runs on 1.14.x (proved on the real v1.14.0, fixtures in the suite). Per-entity persistence metadata (`__persistence_metadata`, documented by the 006 data delta and never produced) is written and hydrated by both file repositories, with the real names of `EntityPersistenceMetaData` and `write_count` counting the first write as in Domian; reads reach the file with the next write, `store()` or `close()`. A single-file document that is not JSON is refused instead of being read as empty. The same major removes the Portuguese vocabulary of the rule engine, legacy since v1.7.0: a Portuguese key or action value is refused with `LegacyVocabularyException`, and `UPGRADE-2.0.md` carries the SQL that migrates a catalog (run in the suite on SQLite and before the release on PostgreSQL 17, MySQL 8.4 and MariaDB 11.4).
- **Goal:** `grep unserialize src/` returns nothing (a test of Module 5 keeps it so). Declared breaking.

### 14. Shared Property Accessor with ALinq 🧩 (Shipped in v2.0.0)
- **Description:** `PropertyAccessor` and the ALinq `ALinqPropertyAccess` shipped the same resolution order as two copies. The resolution now lives in `antevemus/adeep-property-access` (1.0.0, same author, no dependencies), which both libraries require; every internal reader of this library calls it, `Helpers\PropertyAccessor` is a deprecated facade until 3.0 and `Attributes\Specifiable` stays (recognized by name). The package fixed a defect common to both copies (a getter with a required parameter threw) and settled four edge cases where they disagreed (ALinq roadmap milestone 11 is its counterpart, in ALinq 2.0).
- **Goal:** One accessor, one contract test (in the package; this library tests that it delegates and keeps no copy).

---

## 🗓️ Unscheduled

### 15b. Fibers/Revolt Adapter for Non-HTTP Sources (`antevemus/aspecification-async`) ⚡
- **Description:** A satellite package with an adapter for applications whose SDKs are already asynchronous (Revolt event loop, Fibers): non-HTTP sources of a stage fetched concurrently instead of one after the other, behind the same `DataResolver` and `IDataSource` contract shipped in v1.10.0 (milestone 15). It stays out of the core so that the core keeps a single runtime dependency, from the same author and with no dependencies of its own (`antevemus/adeep-property-access`).

### 16. Distributed Specification Cache (PSR-6 / PSR-16 / Redis) ⚡
- **Description:** Native distributed caching adapters for partitioned DAG repositories and dynamic catalog rule sets (wrapping `PdoRuleCatalog` from milestone 6), and **specification serialization** (the declarative `method` constructor of forward 018 RN-08 was deferred here: the core has no `fromArray`/`jsonSerialize` of specifications yet).
- **Goal:** Multi-node high-throughput deployments with distributed cache invalidation.

### 17. GraphQL AST & OpenAPI 3.1 Query Compilers 🔍
- **Description:** Bidirectional AST compilers translating GraphQL query filters and OpenAPI 3.1 query parameter syntax directly into pure domain `ISpecification` trees.

### 18. Doctrine ORM & Laravel Eloquent Query Visitors 🌉
- **Description:** GoF Visitor compilers for `Doctrine\ORM\QueryBuilder` and Laravel's `Illuminate\Database\Eloquent\Builder`, beside the 12 database drivers over 7 SQL dialects and Adianti TCriteria.

### 20. AI-Assisted Specification Synthesizer 🤖
- **Description:** Rule synthesis assistant that generates optimized, non-contradictory specification trees (milestone 10 as the checker) from plain-text business requirements.

### 22. Accent-Insensitive Text Comparison 🔤
- **Description:** Since v1.14.0 the case-insensitive text operators ignore case only, in memory and in every database, so `contains('jose', false)` does not match `José`. An explicit comparison option that also ignores accents (the .NET `CompareOptions.IgnoreCase | IgnoreNonSpace`; note that .NET's `InvariantCultureIgnoreCase` still distinguishes `e` and `é`), with the same answer in memory (Unicode decomposition without combining marks) and in SQL (accent-insensitive collations on MySQL/MariaDB, `unaccent` or a nondeterministic ICU collation on PostgreSQL; refused where the database cannot answer it, such as SQLite).
- **Goal:** Accent-insensitive search as a declared choice, never as a side effect of the column collation.

### 23. Write-Behind Hybrid Repository (Domian `INMEMORY_AND_FILE`) 🗃️
- **Description:** `InMemoryAndFileRepository` is write-through (`put()` writes the file at once). Domian's `INMEMORY_AND_FILE` mode is write-behind: `put()` touches memory only, `persist()` writes, `load()` reloads, and one metadata record counts the repository's reads and writes; `isMemoryBased()` answers by mode. The 1.5.0 notes and forward 020 (RN-08) scheduled it for 2.0.0 together with the persisted metadata; the 2.0.0 forward (033) delivered the metadata and left this mode out because it is additive (a new mode chosen by `PersistenceDefinition`, nothing removed), so it fits a MINOR.
- **Goal:** Memory-speed writes with explicit, batched persistence, as in Domian.

---

## 🔒 Reserved (design in progress)

Nothing is reserved at the moment. `RequirementState`, the document requirement state machine announced by the `1.0.0` CHANGELOG and reserved since 1.5.0, shipped in v1.8.0 as the specification-driven state machine (milestone 11a): a document requirement is now a machine definition, not a class of its own.

## 🚫 Declined (with a note in the CHANGELOG)

- **`RelationalOperator` cases for `BETWEEN`, `IN`, `LIKE` and `REGEX`.** Those predicates are specifications (`between()`, `in()`, `WildcardSpecification`, `RegexSpecification`), not comparison operators; `IN` gets its own leaf in milestone 6.

---

## 🤝 Community & Contributions
We welcome suggestions, feedback, and pull requests! If you would like to propose a feature or contribute to any item on this roadmap, please open an issue or discussion on [GitHub Issues](https://github.com/antevemus-it/Antevemus.ASpecification/issues).
