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

## ✅ v2.1.0 — Feature Flags, Accent-Insensitive Text & a Sound Algebra (Shipped in v2.1.0)

### 24. Declared Feature Flags With Targeting and an Audit Trail 🚩 (Shipped in v2.1.0)
- **Description:** `Antevemus\ASpecification\FeatureFlags`, the generalization of a production application's hand-made switches. A flag is declared once, in code (`FeatureFlagRegistry` of `FeatureFlagDefinition`: code, name, description, category, audience, the texts shown when turning it on and off, origin, an optional targeting specification and the path of the subject key); the database holds only the switch, the validity window, the subject lists and the history (reference DDL `resources/sql/feature_flags.ansi.sql`). Every flag is born off. `FeatureFlags::isEnabled()`/`evaluate()`/`spec()`: on = switch on **and** inside the validity window **and** the subject in the target (a subject list editable without a deploy, the pilot "only these two agencies", and a specification in code), with a reason mapped to OpenFeature's; an undeclared flag is off, an unreadable store is off with the reason `ERROR` and the exception goes to an error reporter instead of being swallowed; one load per instance. Changes (`toggle()`, `addSubject()`, `removeSubject()`) require a reason, are compare-and-set (a stale screen is refused, nothing written), refuse to turn on an undeclared flag, and write their history row in the same transaction. `InMemoryFeatureFlagStore` and `PdoFeatureFlagStore` share one contract battery (SQLite, PostgreSQL 17, MySQL 8.4, MariaDB 11.4); the PDO store reads existing tables through table and column maps by role, a shared audit table through fixed column values, and Boolean columns stored as integers or texts. Accepted against the tables of the production application that motivated it, in a disposable database.
- **Goal:** A flag is a declared, audited, targetable decision with a reason for every answer, and it composes with every other rule as a specification.
- **Not in this release:** a target by a rule of the catalog (24b), percentage rollout (24c), an API for the validity window (24d).

### 22. Accent-Insensitive Text Comparison 🔤 (Shipped in v2.1.0)
- **Description:** `TextComparison` (`CaseSensitive`, `IgnoreCase`, `IgnoreCaseAndAccents`) wherever the case flag of `contains()`, `startsWith()` and `endsWith()` was (`true`/`false` keep their meaning), plus `Spec::equalIgnoreCaseAndAccents()`. In memory and in ALinq both sides are decomposed (NFD), stripped of nonspacing marks, recomposed (NFC) and lowered, so `configuracao` finds `Configuração` while `ß`, `æ`, `ø` stay letters of their own (the .NET `IgnoreNonSpace`); the option requires `ext-intl` and is refused clearly without it. In SQL, with the same answer as memory: PostgreSQL through a built-in folding expression (no extension, `IMMUTABLE`; `unaccent` or an indexable wrapper as options), MySQL 8.4 and MariaDB 11.4 through an `_ai_ci` collation; SQL Server and Oracle (measured to diverge), SQLite and the other dialects, and TCriteria refuse it.
- **Goal:** Accent-insensitive search as a declared choice, never as a side effect of the column collation.
- **Not in this release:** wildcards and regular expressions without accents (22b).

### 26. Soundness Audit of the Specification Algebra 🧪 (Shipped in v2.1.0)
- **Description:** The investigation of four defects in `equals()` and date comparison showed a family: the algebra (`equals()`, subsumption, disjointness, tautology and contradiction, `simplify()`, deduplication, partitions) promised more than the evaluation delivers, because the 1.4.4 parity lot and the 1.9.0 `simplify()` were tested with examples chosen by their author. A brute-force bench now checks every promise against the evaluation over a pool that covers every concrete specification class of `src/` (a guard fails the suite when a new class is left out) and boundary candidates; the defects it found were fixed in the same release, each with a reproduction kept in the suite, and the rule became "the algebra never promises more than the evaluation delivers". Date-times are compared by instant by `equalTo()`/`notEqual()`/`in()`, as in SQL; integer bounds are no longer closed without an integer type (milestone 25).
- **Goal:** `simplify()`, partitions and deduplication can be trusted because a test, not a reviewer, proves that they never change an answer.

---

## ✅ v2.2.0 — Pending Decisions of the Soundness Bench (Shipped in v2.2.0)

### 26b. Pending Decisions of the Soundness Bench 🧪 (Shipped in v2.2.0)
- **Description:** The two violations the soundness bench of v2.1.0 found and left for a decision (a third, BUG-20261013-ZYEC, was fixed in v2.1.0 together with BUG-20261013-T6CS), in both the algebra and the evaluation disagreed, and the bench does not say which side is wrong. **BUG-20261013-JPQT (#68):** `defaultValue('string')->isDisjointWith(defaultValue('number'))` was `true` although `null` satisfied both; the evaluation was the wrong side: a typed default value is "a value of that type and its default" (`isBlankString()` a blank text, `defaultNumber()` zero, as `default(T)` of a non-nullable type), so it now refuses `null`, which only the untyped `defaultValue()` accepts, and the disjointness holds for every candidate; three Domian parity assertions that asserted `null` as blank or zero are inverted with a note. **BUG-20261013-XEIB (#70):** `not(lessThan(5.5))` accepted `NAN` and its inversion `greaterThanOrEqualTo(5.5)` did not, because `NAN` is unordered; the ordered leaves now refuse it as a candidate (`IncompatibleTypeException`) and as a bound (`InvalidArgumentException`), the inversion of every numeric leaf is kept, the equality leaves keep answering it, and `NAN` joins the candidate pool of the bench. No decision is pending; the hook of the bench stays for the next one.
- **Goal:** The bench runs without exceptions: every promise of the algebra is checked on every candidate of the pool, `NAN` included.

---

## ✅ v2.3.0 — Text and Feature Flags II (Shipped in v2.3.0)

### 22b. Accent-Insensitive Wildcards and Regular Expressions 🔤 (Shipped in v2.3.0)
- **Description:** `wildcard()` and `regex()` take the `TextComparison` that `contains()`, `startsWith()` and `endsWith()` took in v2.1.0 (forward 039). The wildcard folds pattern and candidate as the literal leaves do (`false` is now `wildcardIgnoreCase()`); the regular expression folds only the accents of its body (`TextComparison::foldAccents()`, case kept, so `\D` stays `\D`), adds `iu` and matches the candidate folded the same way; with a comparison the pattern must be delimited. In SQL, with the in-memory answer or not at all: **PostgreSQL** translates both (the built-in folding expression on the column; the regex as `fold(col) ~* :p`), **MySQL 8.4 and MariaDB 11.4** translate the wildcard through the `_ai_ci` collation and **refuse the regex**, because measured, their engines (ICU, PCRE2) ignore the collation (`^cora[cç][aã]o$` finds `coracao`, not `Coração`, in every form tried) and neither has a function to fold the column; every other dialect and TCriteria refuse both, ALinq evaluates through the leaf. Parity with memory measured on PostgreSQL 17 (483 queries), MySQL 8.4 (175) and MariaDB 11.4 (323). The measurement also found **#73**, fixed in the same release: on PostgreSQL `\b` is a backspace, so a `regex()` with `\b` never matched; every regular expression translated for it now has `\b`/`\B` written as `\y`/`\Y` (`ISqlRegexRewriter`). Documented limits that are not defects: on PostgreSQL the class shorthands follow the database (`\d` ASCII only, `\w`/`\s` on a few hundred code points, a `"C"` collation column), and `fnmatch()` does not always read `?` as one multibyte character.
- **Goal:** Every text operator offers the accent-insensitive comparison, with one answer wherever it runs.

### 24d. An API for the Validity Window of a Feature Flag 🚩 (Shipped in v2.3.0)
- **Description:** `FeatureFlags::setValidity()` (forward 040) sets and clears the window that v2.1.0 evaluated but only SQL could write: a reason, one transaction with a history row typed `Validity` (`FeatureFlagChangeKind`, with a `detail` such as `2026-11-01T00:00:00+00:00 → open`), and a compare-and-set on the row `version`, which `toggle()` also increments; an optional `expectedVersion` lets a screen refuse to save over someone else's change, and setting what is already there is refused as stale. `IFeatureFlagStore` gains the window and rollout methods (a declared break for a store implemented outside the library; none was found).
- **Goal:** The window is an audited change like the switch, never a hand-written `UPDATE`.

### 24c. Percentage Rollout of a Feature Flag 🚩 (Shipped in v2.3.0)
- **Description:** `FeatureFlags::setRollout()` through the same path, with `feature_flag.rollout_percent`. A subject is in when `FeatureFlags::bucket($code, $subjectKey)` (`xxh3` of the code and the key, modulo 10 000; part of the contract, changing it is a MAJOR) is below the percentage times 100; the rollout is the last step of the evaluation, a subject without a key is out, and the new reason `OUTSIDE_ROLLOUT` and the bucket in the metadata let a panel explain the answer. Over 10 000 subjects, 1 %, 10 %, 25 %, 50 % and 90 % land within ±1 %; two flags are independent and two processes give the same buckets. The new columns (`rollout_percent`, `change_kind`, `detail`) are read only with `columns: PdoFeatureFlagStore::COLUMNS_SINCE_2_3`, so a table of v2.1.0 keeps working untouched; the additive migration is commented in the DDL.
- **Goal:** A gradual launch with a stable, explainable answer per subject, through the audited path.

---

## ✅ v2.4.0 — Specification Serialization (Shipped in v2.4.0)

### 16. Distributed Specification Cache (PSR-6 / PSR-16 / Redis) ⚡ — 16a shipped in v2.4.0; 16b scheduled for v2.8.0
- **Description:** Native distributed caching adapters for partitioned DAG repositories and dynamic catalog rule sets (wrapping `PdoRuleCatalog` from milestone 6), and **specification serialization** (the declarative `method` constructor of forward 018 RN-08 was deferred here, because the core had no `fromArray`/`jsonSerialize` of specifications). Split in two: 16a, the serialization, shipped in v2.4.0; 16b, the cache adapters, is scheduled for v2.8.0 (below; it slid from v2.5.0 when forwards 048 and 046 took v2.5.0 and v2.6.0, and again on 2026-10-20, when the core hook of forward 045 took v2.6.0 and 046 slid to v2.7.0).
- **16a, delivered (forward 041):** `SpecificationCodec` writes a specification as a canonical JSON tree, `{"v": 1, "spec": {"t": "<name>", ...}}`, and reads it back as the same classes. `t` is one of 44 short, stable names (`and`, `property`, `eq`, `in`, `contains`, `regex`, `method`, `isToday`...), never a class name taken from the input; the registry is open to leaves of the application (`ISerializableSpecification` with `toArray()`/`fromArray()`, `register()`) and is checked by exact class. Dates as `{"$date"}` (RFC 3339 with the offset), enum cases as `{"$enum"}` only for an allowlist; closures, objects and resources refused. Decoding never calls `unserialize()` nor instantiates a class named by the document, and is limited to 64 levels and 10 000 nodes by default. Callbacks, `Spec::external()`, `FeatureEnabledSpecification`, the guards of the machine and the bindings of the rule engine are refused with the reason; a method call is serialized by its name (the `method` constructor of forward 018). Proven on the soundness bench: every serializable tree of the pool (497 of 726) round-trips with `equals()` in both directions, a canonical text and the same answers, and translates to the same SQL (ANSI, PostgreSQL, MySQL) and `TCriteria`; the other 229 are refused for a reason of the contract.
- **Goal:** A specification is data that can be stored, sent and audited, without opening a door to code execution.

### 24b. Feature Flag Target by a Catalog Rule 🚩 (Shipped in v2.4.0)
- **Description:** The target of a flag (milestone 24) was a subject list in the database or a specification in code. Since v2.4.0 it can be the rule of the catalog named by `targetRuleCode` (exclusive with `target`): `IRuleCatalog::findRuleByCode()` finds the active rule, its body is the serialized specification of `business_rule.spec_json` (`IRuleDefinition::getSpecJson()`, read by `PdoRuleCatalog` with `COLUMNS_SINCE_2_4`, so a table of v2.3.0 keeps working; the additive migration is commented in the DDL), and `FeatureFlags`, given the catalog, resolves it once per instance and evaluates it as a target in code; `refresh()` reads it again, and a missing rule or an unreadable document turns the flag off with `ERROR` (fail closed). The same body serves the rule engine, which builds a rule with `spec_json` from it instead of a handler. Tested against SQLite, PostgreSQL 17, MySQL 8.4 and MariaDB 11.4, the migration included.
- **Goal:** An operator changes who sees a feature by changing a rule in the database, without a deploy.

---

## ✅ v2.5.0 — A Rule Engine, TCriteria and Data Resolution for a Real Consumer (Shipped in v2.5.0)

Forward 048 (2026-10-19): the gaps L1 to L5, L10 and L11 the audit of the Virttus measured on 2026-10-18, closed in the core without a breaking change (new interfaces and classes, parameters only at the end, nothing new in an existing interface).

### 27. A Rule Engine for a Real Consumer 🧭 (Shipped in v2.5.0)
- **Description:** Three things the consumer wrote by hand. **Lookup-only rule types** (`ILookupOnlyRuleTypes`, `RuleSpecificationRegistry::registerLookupOnly()`): rows of the catalog that only hold a value the application reads are skipped by `DynamicSpecificationEngine` and listed by `getSkippedRules()`, instead of one rule without handler stopping the whole scope; a type nobody declared is still `MissingRuleHandlerException`, a type cannot be lookup only and handled at once, and a lookup-only rule with `spec_json` is a validation. **The most specific rule of a type** (`IRuleTypeCatalog::findRulesByType()`, one query in `PdoRuleCatalog`; `RulePrecedence`): the requested scenario over the scope-global rule, a plan restriction over a product restriction over none, then priority, then `code`, the same answer over any catalog and whatever the order of insertion; `IRuleCatalog` and the order of `findRules()` unchanged. **A human label for the target** (`LabelledSpecification`, `labelled()`, `Spec::labelled()`): every failure reported against the label, the technical property kept in `metadata['property_path']`, the innermost label winning, serialized as `labelled` (45 names), kept by `simplify()`, invisible to SQL, TCriteria and the algebra; a bound rule reported once lists its labels. **Adianti as the host** (documentation, example 22.3 and `docs/adianti/`): a `TransactionMiddleware` that joins the transaction of the screen (`TTransaction::open()` always opens a new connection) and a `UseCaseResult` shown with `TMessage`/`TToast`, run against the stubs.
- **Proof:** compiling with the declaration answers what the Evaluator of the Virttus answers after filtering the catalog by hand (120 generated catalogs); the precedence over the in-memory catalog = the PDO one = brute force on SQLite, PostgreSQL 17, MySQL 8.4 and MariaDB 11.4, independent of the order of insertion, and equal to the `first()`/`byScenario()` of the Virttus; the Evaluator of the Virttus rewritten with labels and lookup-only types answers its violations over 300 generated sets of facts in three scopes; every specification of the soundness bench keeps its answers, SQL and TCriteria when labelled.
- **Goal:** The rules of a real catalog without the plumbing around the engine.

### 28. TCriteria Without Accents and With Subqueries 🔤 (Shipped in v2.5.0)
- **Description:** `toCriteria()` writes a comparison that ignores case and accents for a declared database (`TCriteriaBuilder::withAccentFolding()`, `Spec::toCriteria(..., accentFolding:)`), exactly as `toSql()` does for that dialect, through a `TFilter` that owns its rendering (`TAccentInsensitiveFilter`, value bound under `:aspf_N`, requires `prep = 1`). `Spec::inSubquery()` and `Spec::existsIn()` are subqueries over any table for `toSql()` and `toCriteria()` (`TSubqueryFilter`, its filter a TCriteria of its own, never the raw `(SELECT` of `TFilter`), with validated identifiers (a segment between double quotes allowed), `NOT IN` kept two-valued, an evaluation error in memory and a refusal in the codec.
- **Proof:** the accent battery of 2.1.0 and 2.3.0 executed through the TCriteria of the real Adianti 8.1 and 8.7 = `toSql()` = memory on PostgreSQL 17, MySQL 8.4 and MariaDB 11.4 (negations with NULL rows; `prep = 0` fails closed); 100 subquery cases with hostile literals (`#`, `--`, `/*`, `]:`, `'`, `\`) through the real Adianti 8.1 and 8.7 = `toSql()` = brute force in SQLite and the three databases; five subqueries of the Virttus written with `NOESC:` and with `inSubquery()` select the same rows.
- **Goal:** Adianti screens searching without accents and filtering by related tables without raw SQL.

### 29. Asynchronous Data Sources ⏳ (Shipped in v2.5.0)
- **Description:** `AsyncDataSource` submits a request once (a store of tickets keyed by the request, `IAsyncTicketStore`, in memory or over a table of the application through `CallableAsyncTicketStore`), polls it at each resolution and never waits; a result not ready yet is `DataPendingException`, the evaluation error of the leaf (never approved nor refused, NOT does not invert it), told apart by `SpecificationResult::isPending()`, `RuleEngineVerdict::isPending()` and `ResolvedData::getPendingRequests()`; a crash between sending and storing the ticket is reconciled or fails closed, never sent again.
- **Proof:** scripted providers over 600 resolutions (pending, ready, failed, transport errors, crashes): at most one submission per key, a pending datum never satisfied, a value cached only when ready; the credit analysis of the Virttus simulated, exactly one POST.
- **Goal:** Providers that answer later, without a `sleep()` in the request and without paying twice.

---

## ✅ v2.6.0 — Deferred Sources: the Stage Transport Hook (Shipped in v2.6.0)

Forward 045, phase 2 (2026-10-20): the half of milestone 15b that belongs to the core, so that the satellite package (below) needs nothing else from it.

### 15b, core hook. Deferred Sources: the Stage Transport Hook ⚡ (Shipped in v2.6.0)
- **Description:** Two interfaces, no dependency: `IDeferredDataSource` (an `IDataSource` that names its `IStageTransport`) and `IStageTransport::fetchAll($pending, $deadline)` (every pending request of its sources in a stage, in one call, answered by request key with the value or the `Throwable` that failed it). A stage of `ResolvedData` now runs three groups, serialized, in this order: the HTTP batch (`curl_multi`, as before), one `fetchAll()` per transport instance (in the order its first request was met), and the other sources one after the other (as before). A transport is not called once the time limit of the resolution has passed (its requests fail with `DataFetchException::resolutionTimeout()`); a `Throwable` answered fails that request only, a key not answered fails with `DataFetchException::noTransportResult()`, and a crash of `fetchAll()` fails the requests of that transport and nothing else; values are cached with the TTL of their source, failures never. Like an HTTP source, a deferred source is never fetched through its unitary `fetch()` inside a resolution scope; `fetch()` stays the synchronous path outside it. A stage without deferred sources runs exactly as before.
- **Proof:** Module 21 with a fake transport: the order of the three groups through a shared event log (HTTP, transport, callable), grouping by transport instance in first-seen order, tiered stages by cost with the expensive source skipped, a `Throwable` result never cached, a cache hit that never reaches the transport, a missing key, a crashed transport beside a healthy one, the deadline received by the transport and a transport not called past it, the sequential policy one key at a time, deduplication, the unitary path outside a scope, and 300 random trees (fixed seed) over callable, plain and deferred sources on up to three transports giving the same verdict under `concurrent()`, `tiered()` and `sequential()` with one `fetchAll()` per transport (and per cost under `tiered()`) with pending requests; example 16.1 runs as written.
- **Goal:** Non-HTTP sources of a stage fetched concurrently by a package outside the core, with the core still dependency-free.

---

## ✅ v2.7.0 — The State Machine for a Real Consumer (Shipped in v2.7.0)

### 30. The State Machine for a Real Consumer 🧭 (Shipped in v2.7.0)
- **Description:** The four gaps the first real consumer measured before adopting the state machine, all closed: **legacy state codes** (`StateBuilder::code()`, `MachineDefinition::stateByCode()`/`codeOf()`/`codesOf()`, `"20"` ≡ `20`, every existing fingerprint unchanged); **definitions versioned in the database** (`PdoMachineDefinitionRepository`: one immutable row per version, the fingerprint checked on every read; drafts with the validator's report, compare-and-set on the revision and `publish()` in one transaction, `IMachineDefinitionDrafts` in memory and over PDO; `MachineDefinition::diff()`; `MachineMigrations::byStateName()` and `MachineRunner::migrateAll()`; the opt-in `spec` guard, a specification serialized by the codec of 2.4.0 and evaluated over the context); **tenants** (`MachineInstance::$tenant`, `ITenantScopedInstanceStore`, the tenant at the end of every runner call, the opt-in columns `COLUMNS_SINCE_2_7`); and **deadlines in business time** (`IDeadlineCalendar` with `ContinuousCalendar`, `BusinessDayCalendar` and `WorkingHoursCalendar`, a `HolidaySet` built from the application's own table, `DeadlineCalendarRegistry` resolving by name and context, durations and pauses in working seconds, the due instant materialized and frozen: a holiday registered later does not move a running deadline). The reference DDL gains two tables, five opt-in columns and the additive migration commented in its header.
- **Proof:** Module 40 (14 335 assertions: the batteries of codes, drafts, publication, migration and tenants, 200 generated machines, the round trip of definitions on SQLite, PostgreSQL 17, MySQL 8.4 and MariaDB 11.4, tenant isolation equal in memory and in SQL, an acceptance over a synthetic fixture with the shape of the consumer's sample: a quote machine with codes 1..21, two tenants, 102 steps compared, exclusions counted) and Module 41 (6 061 assertions: a table of 3 762 cases generated by running the consumer's own SLA clock functions, properties over random calendars and time zones, an SLA region compared with a recomputing reference clock, continuous calendar = no calendar bit for bit). Mutation score of the new files: 92.9 % (codes, drafts, tenants) and 98.5 % (calendars).
- **Goal:** The consumer's quote and claim workflows run as definitions of the machine, published from a screen, with their legacy ids, their tenants and their SLA policies, without a cron that recomputes deadlines from the history. The remaining gaps found by its adoption guide (importing instances in a mapped state, effect context, candidate classification, main refusal reason, wide JSON columns, batch send) are scheduled for v2.10.0.

---

## 🗓️ Scheduled (forward specification written, no code yet)

Planned on 2026-10-18 from the unscheduled list and reordered on 2026-10-19, when the first real consumer (the Virttus) put two forwards ahead of the queue: 048 shipped in v2.5.0 (above) and 046 was placed at v2.6.0, so the repository and cache work slid to v2.7.0 and the integer leaves to v2.8.0. Reordered again on 2026-10-20: the Fibers/Revolt work (milestone 15b) is the last feature before the work on the engine resumes, and its satellite package needs a hook in the core, so the hook took v2.6.0 (above) and every scheduled version slid one minor: 046 to v2.7.0, the repositories and the cache to v2.8.0, the integer leaves to v2.9.0. 046 shipped in v2.7.0 (above) on 2026-10-21. Each milestone has its forward specification in the Reversa of this repository (`_reversa_forward/042` to `046`; 039 and 040 shipped in v2.3.0, 041 in v2.4.0, 048 in v2.5.0 and the core hook of 045 in v2.6.0) and goes into the version named here; the satellite package comes next, right after the hook.

### v2.8.0 — Repositories and Cache

### 23. Write-Behind Hybrid Repository (Domian `INMEMORY_AND_FILE`) 🗃️
- **Description:** `InMemoryAndFileRepository` is write-through (`put()` writes the file at once). Domian's `INMEMORY_AND_FILE` mode is write-behind: `put()` touches memory only, `persist()` writes, `load()` reloads, and one metadata record counts the repository's reads and writes; `isMemoryBased()` answers by mode. The 1.5.0 notes and forward 020 (RN-08) scheduled it for 2.0.0 together with the persisted metadata; the 2.0.0 forward (033) delivered the metadata and left this mode out because it is additive (a new mode chosen by `PersistenceDefinition`, nothing removed), so it fits a MINOR.
- **Goal:** Memory-speed writes with explicit, batched persistence, as in Domian.
- **Plan:** forward 042 (`042-repositorio-write-behind`). The hybrid repository obeys `PersistenceDefinition::MemoryAsyncFile`: writes touch memory only, `persist()` writes the whole cache atomically (temporary file and rename), `load()` discards what was not persisted, `isDirty()`/`pendingWrites()`/`persistEvery()`/`persistOnShutdown()`; a repository metadata record travels in the file envelope; the Domian parity cases for this mode run instead of carrying a note.

### 16b. Distributed Cache Adapters (PSR-16) ⚡
- **Description:** The second half of milestone 16: decorators over `IRuleCatalog`, `IFeatureFlagStore` and the partition index of the DAG behind `Psr\SimpleCache\CacheInterface` (suggested, never required: the core keeps its single runtime dependency), invalidated on every write of the same process and bounded by a short TTL for the others; a failing cache is a miss, reported through the error hook, never an error of the evaluation. Redis or any other backend is the application's client.
- **Plan:** forward 043 (`043-cache-distribuido`).

### v2.9.0 — Integer Leaves (waits for a real consumer)

### 25. Optional Integer Mode for the Relational Leaves 🔢
- **Description:** Since v2.1.0 the algebra no longer closes integer bounds (`x > 10` was taken as `x >= 11`): the relational leaves accept float candidates and the library has no int-typed leaf, so `greaterThan(10)` and `lessThan(11)` are not disjoint (10.5 satisfies both) and `simplify()` no longer collapses them (forward 037, BUG-20261013-ST36). An opt-in integer mode gives the closing back where it is sound, in two forms that coexist:
  - **`asInteger()` on the leaf:** `Spec::greaterThan(10)->asInteger()`, or `greaterThan(10)->asInteger()` with the DSL function (the method belongs to the leaf, `AbstractComparableValueBoundSpecification`, so both entry points return an object that has it). It returns a new, immutable leaf whose `getType()` is `'int'`; the evaluation refuses a non-integer candidate with `IncompatibleTypeException`, like the other type guards; the algebra closes the bound only when both sides are int-typed.
  - **`Spec::int()` scope:** a factory scoped to integers whose methods build int-typed leaves (`Spec::int()->greaterThan(10)`, `Spec::int()->between(1, 12)`), more readable when a whole rule is about integers; internally it calls `asInteger()`.
  - **Narrowed return types:** `Spec::greaterThan()`, `lessThan()`, `greaterThanOrEqualTo()`, `lessThanOrEqualTo()`, `between()` and their DSL functions declare `ISpecification` today, which has no `asInteger()`: it works at runtime but IDEs and static analysis (PHPStan) flag it and autocompletion does not offer it. The relational factories narrow their return type to the leaf class (or a small interface of it), which is compatible for every caller (return types are covariant).
- **Goal:** Sound interval simplification for integer domains (installments, months, quantities) plus validation for free (`10.5` refused where only integers make sense). First natural consumer: integer rules such as a minimum adjustment periodicity in months. Waits for a real use case.
- **Plan:** forward 044 (`044-folhas-inteiras`), written and ready; it enters the queue when the first integer rule (a minimum adjustment periodicity in months) or a decision pulls it. Decided on 2026-10-18: the integer leaf refuses every float, `12.0` included, as `strict_types=1` does for an `int` parameter; the conversion belongs to the input boundary.

### Satellite package (no version of the core)

### 15b. Fibers/Revolt Adapter for Non-HTTP Sources (`antevemus/aspecification-revolt`) ⚡ (Shipped as v1.0.0 on 2026-10-20)
- **Description:** A satellite package with an adapter for applications whose SDKs are already asynchronous (Revolt event loop, Fibers): non-HTTP sources of a stage fetched concurrently instead of one after the other, behind the same `DataResolver` and `IDataSource` contract shipped in v1.10.0 (milestone 15). It stays out of the core so that the core keeps a single runtime dependency, from the same author and with no dependencies of its own (`antevemus/adeep-property-access`).
- **Plan:** forward 045: the core hook (`IDeferredDataSource`/`IStageTransport`) shipped in v2.6.0 (above), and the satellite shipped as v1.0.0 on 2026-10-20 ([repository](https://github.com/antevemus-it/Antevemus.ASpecificationRevolt), [Packagist](https://packagist.org/packages/antevemus/aspecification-revolt)): a new repository `Antevemus.ASpecificationRevolt` built like `Antevemus.ADeepPropertyAccess`, with a `RevoltDataSource` (an `IDeferredDataSource` over Revolt/Amp futures) and a `RevoltStageTransport` (bounded concurrency, the stage deadline enforced through cancellation, the application's Revolt loop when one is running). The core only gained the hook, a `suggest` line and the page of example 16.1. It is the pair of ALinq milestone 12 (async collection), which gets its own forward in the ALinq repository.

---

## 🔭 Distant Future (no forward, no version)

Moved here on 2026-10-18: none of them has a consumer in the ecosystem the library serves (Adianti hosts, the `PdoRepository`, the Virttus applications), and a milestone without a consumer is a promise the README cannot keep. Each one needs a written use case before it gets a forward.

### 17. GraphQL AST & OpenAPI 3.1 Query Compilers 🔍
- **Description:** Bidirectional AST compilers translating GraphQL query filters and OpenAPI 3.1 query parameter syntax directly into pure domain `ISpecification` trees.
- **Why it waits:** no consumer exposes GraphQL or an OpenAPI query syntax; the day one does, the compiler is a visitor like the SQL and TCriteria ones, with the same parity bench.

### 18. Doctrine ORM & Laravel Eloquent Query Visitors 🌉
- **Description:** GoF Visitor compilers for `Doctrine\ORM\QueryBuilder` and Laravel's `Illuminate\Database\Eloquent\Builder`, beside the 12 database drivers over 7 SQL dialects and Adianti TCriteria.
- **Why it waits:** no consumer uses Doctrine or Eloquent; the `PdoRepository` (1.11.0 to 1.14.0) and the Adianti `TCriteria` visitor cover the real hosts.

### 20. AI-Assisted Specification Synthesizer 🤖
- **Description:** Rule synthesis assistant that generates optimized, non-contradictory specification trees (milestone 10 as the checker) from plain-text business requirements.
- **Why it waits:** the use case has not been stated convincingly. The honest candidate is an operator writing a catalog rule in plain text and getting a specification tree checked by milestone 10 and serialized by milestone 16a; it depends on 16a and on a product decision about where the text would be typed.

---

## 🔒 Reserved (design in progress)

Nothing is reserved at the moment. `RequirementState`, the document requirement state machine announced by the `1.0.0` CHANGELOG and reserved since 1.5.0, shipped in v1.8.0 as the specification-driven state machine (milestone 11a): a document requirement is now a machine definition, not a class of its own.

## 🚫 Declined (with a note in the CHANGELOG)

- **`RelationalOperator` cases for `BETWEEN`, `IN`, `LIKE` and `REGEX`.** Those predicates are specifications (`between()`, `in()`, `WildcardSpecification`, `RegexSpecification`), not comparison operators; `IN` gets its own leaf in milestone 6.

---

## 🤝 Community & Contributions
We welcome suggestions, feedback, and pull requests! If you would like to propose a feature or contribute to any item on this roadmap, please open an issue or discussion on [GitHub Issues](https://github.com/antevemus-it/Antevemus.ASpecification/issues).
