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

## 🔮 v2.0.0 — Zero `unserialize`, Shared Accessor & Concurrent Data Resolution

### 13. PHP-Native Serializer Retirement, Step 2: Removal & JSON-Only Envelope 🗑️
- **Description:** `PhpNativeEntitySerializer` and the `.bin` branch are removed; the single-file envelope is always JSON and owned by the repository, the serializer only serialises the entity; existing `.bin` files fail with the migration recipe. Per-entity persistence metadata (`__persistence_metadata`, documented by the 006 data delta and never produced) is written and hydrated by the file repositories in the same format change.
- **Goal:** `grep unserialize src/` returns nothing. Declared breaking.

### 14. Shared Property Accessor with ALinq 🧩
- **Description:** `PropertyAccessor` and the ALinq `ALinqPropertyAccess` ship the same resolution order as two copies. Extracting one package both libraries depend on changes the dependency graph of both (ALinq roadmap milestone 11).
- **Goal:** One accessor, one contract test.

### 15. Concurrent Data Resolution for I/O-Bound Specifications ⚡
- **Description:** Specifications stay pure: a specification that depends on external data (a credit bureau score, a fraud check over gRPC, a microservice lookup) does not call the service itself, it **declares** the data it needs. Before evaluation, a resolver collects the declarations of the whole tree and fetches the data **concurrently**, then evaluation runs over the resolved data. The core resolver uses `curl_multi` (zero dependency, works under PHP-FPM); a satellite package (`antevemus/aspecification-async`) adds a Fibers/Revolt adapter for applications whose SDKs are already asynchronous. A **cost policy** in the resolver chooses between fetching everything concurrently and fetching sequentially with short-circuit: when calls are paid (bureau queries), it can resolve the cheapest or most decisive branch first and skip the ones whose result can no longer change the verdict.
- **Why not "evaluate specifications in Fibers":** a Fiber alone does not make I/O non-blocking. A blocking client (PDO, plain `curl_exec`, `file_get_contents`) inside a Fiber still blocks the whole process; concurrency only happens when the I/O itself is driven by a non-blocking client (`curl_multi`, an event loop driver). And Fibers are cooperative scheduling in one thread, not parallelism: CPU-bound specifications gain nothing. Moving the I/O out of the specifications and into a resolver gets the concurrency where it actually is.
- **What stays as it is:** evaluation remains synchronous, so `because()`/`withCode()`, the SQL and `TCriteria` compilation of the same specifications, the existing tests and the generic error message of 1.8.0 (an exception never reaches the failure) keep working unchanged.
- **Goal:** Concurrent fetching of the data I/O-bound rules need, without async-coloring the specification API and without a dependency in the core.

---

## 🗓️ Unscheduled

### 16. Distributed Specification Cache (PSR-6 / PSR-16 / Redis) ⚡
- **Description:** Native distributed caching adapters for partitioned DAG repositories and dynamic catalog rule sets (wrapping `PdoRuleCatalog` from milestone 6), and **specification serialization** (the declarative `method` constructor of forward 018 RN-08 was deferred here: the core has no `fromArray`/`jsonSerialize` of specifications yet).
- **Goal:** Multi-node high-throughput deployments with distributed cache invalidation.

### 17. GraphQL AST & OpenAPI 3.1 Query Compilers 🔍
- **Description:** Bidirectional AST compilers translating GraphQL query filters and OpenAPI 3.1 query parameter syntax directly into pure domain `ISpecification` trees.

### 18. Doctrine ORM & Laravel Eloquent Query Visitors 🌉
- **Description:** GoF Visitor compilers for `Doctrine\ORM\QueryBuilder` and Laravel's `Illuminate\Database\Eloquent\Builder`, beside the 12 database drivers over 7 SQL dialects and Adianti TCriteria.

### 19. Specification-Driven Domain Event Triggers 📡
- **Description:** Domain event emitter that triggers events whenever an entity transitions into or out of satisfying critical domain specifications (Outbox, CQRS). Renamed in 1.8.0 from "Reactive Domain Event Sourcing Triggers": the milestone never meant event sourcing (rebuilding state by replaying events), and the 1.8.0 state machine keeps the snapshot as the source of truth. It builds on the transactional outbox shipped with the 1.8.0 state machine, and an emitted event can be sent to a machine as a transition trigger.

### 20. AI-Assisted Specification Synthesizer 🤖
- **Description:** Rule synthesis assistant that generates optimized, non-contradictory specification trees (milestone 10 as the checker) from plain-text business requirements.

### 21. PDO-Backed Repository (the Domian `HibernateRepository`) 🗄️
- **Description:** Domian shipped a 799-line `HibernateRepository` that turns a specification into an ORM query; the PHP port has the query half (`SqlQueryVisitor`, `TCriteria`) but no `IRepository` over a database. Found by the 2026-10-09 parity audit (the module was never inventoried). A `PdoRepository` would reuse the SQL visitor for `find*`/`count`/`remove*` over a table mapped by a field mapper, with partitions as `WHERE` fragments.
- **Goal:** The repository family covers memory, file and database, as Domian's did.

---

## 🔒 Reserved (design in progress)

Nothing is reserved at the moment. `RequirementState`, the document requirement state machine announced by the `1.0.0` CHANGELOG and reserved since 1.5.0, shipped in v1.8.0 as the specification-driven state machine (milestone 11a): a document requirement is now a machine definition, not a class of its own.

## 🚫 Declined (with a note in the CHANGELOG)

- **`RelationalOperator` cases for `BETWEEN`, `IN`, `LIKE` and `REGEX`.** Those predicates are specifications (`between()`, `in()`, `WildcardSpecification`, `RegexSpecification`), not comparison operators; `IN` gets its own leaf in milestone 6.

---

## 🤝 Community & Contributions
We welcome suggestions, feedback, and pull requests! If you would like to propose a feature or contribute to any item on this roadmap, please open an issue or discussion on [GitHub Issues](https://github.com/antevemus-it/Antevemus.ASpecification/issues).
