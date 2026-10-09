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
- **Description:** The 2026-10-09 method-by-method parity audit against the Domian trunk (r1209, 103 main sources in 6 modules; reports in `docs/paridade-domian-2026-10-09/`) found that the subsumption algebra and the partition DAG did not behave as the Java reference and as this README promise: composite `equals()` compared object identity, `OrSpecification::isGeneralizationOf` required both sides, `NotSpecification` had no algebra, date and `>=`/`<=` leaves were anonymous classes without subsumption; inserting a generalizing partition lost sub-partitions, `findPartition` matched only by equality, `repartition()` of a missing entity inserted it, iteration materialised; no repository used `ISynchronizer`; `AbstractEntity::equals` ignored the type; `StopWatch`, `updateWithDelta`, 28 factory names, `StrictReturnsNullFactory` and private-field access were missing or wrong. This lot ports the Domian rules faithfully (Reversa bugs #45 to #48), with the Java tests transcribed into `tests/Parity/`.
- **Goal:** "Venn Set Subsumption" and "DAG partitioning" mean what the README says. No public signature changes (PATCH).

---

## ✅ v1.5.0 — README Promises II & Announced Backlog I (Shipped in v1.5.0)

### 6. README Promises II: Inter-Process, Unicode, Lazy Sources, Relational Catalog & `IN` 🧵 (Shipped in v1.5.0)
- **Description:** The remaining README promises that change observable behaviour: a `SysVSemaphoreSynchronizer` backed by SysV IPC (`sem_get`, `ext-sysvsem`) as its own class beside the in-process `SemaphoreSynchronizer`; Unicode-aware case-insensitive string specifications via `mb_*` (`ext-mbstring` becomes required); `IRepository::iterate()` as a true lazy source for `findAsLazyCollection()` (generator, no `getAll()` materialisation); typed return types on the ALinq bridge with `antevemus/alinq-collection ^1.3` in `suggest`; `PdoRuleCatalog`, a PDO-backed `IRuleCatalog` mirroring the relational rule and document tables (configurable table names, reference ANSI DDL shipped); and an `InSpecification` leaf so that `in()` is translated as `IN (...)` by the SQL, TCriteria and ALinq visitors (today a chain of `OR` equalities).
- **Goal:** The Requirements and Core Features sections describe what the code does. Two declared breaking changes: Unicode case folding and `in()` becoming its own leaf.
- **Specification:** Reversa forward 017 (decisions D2 to D7, 2026-10-09).

### 7. Announced-but-Unshipped Backlog I: ULID / UUID v7 Identities & Failure Severity 📋 (Shipped in v1.5.0)
- **Description:** Two capabilities announced by the `1.0.0` CHANGELOG and never shipped, both additive: `AbstractUlidEntity` and `AbstractUuidV7Entity` (time-ordered identities beside `AbstractUUIDEntity`), and a `FailureSeverity` (`ERROR`, `WARNING`, `INFO`) on `SpecificationFailure`, filled by the rule engine from the rule's action (`bloquear`, `alertar`, `apenas_log`).
- **Goal:** Promises become code or get an explicit note. The same release records the two items declined (see "Declined") and the one reserved (see "Reserved").
- **Specification:** Reversa forward 020.

### 8. PHP-Native Serializer Retirement, Step 1: Deprecation ⚠️ (Shipped in v1.5.0)
- **Description:** `PhpNativeEntitySerializer` and the `.bin` envelope of `SingleFileRepository` are the only use of `unserialize()` in the library and were the vector of the 2026-10 security finding (object injection through the envelope). The JSON serializer covers private and readonly properties, dates, nested objects and constructor-less hydration. In v1.5.0 the class is marked `@deprecated` and emits `E_USER_DEPRECATED`; the README documents the `.bin` → `.json` migration recipe. Nothing is removed before v2.0.0.
- **Goal:** A library with zero `unserialize()` by v2.0.0, announced one MINOR ahead.
- **Specification:** Reversa forward 020 (decision D1).

---

## ✅ v1.6.0 — Algebra & Announced Backlog II (Shipped in v1.6.0, except milestone 12)

### 9. `MethodParameterizedSpecification`: Declarative Method Calls 🔬 (Shipped in v1.6.0)
- **Description:** The README tree promises `Specifications/Reflection/` with parameterized specifications. Field access already exists as `where()` with the `PropertyAccessor`, so no field-parameterized class is built. The method-parameterized one is: `Spec::calling('isEligibleFor', [$date], $resultSpec)` calls a method of the candidate with arguments declared as data (scalars, arrays, enums, dates) and applies a specification to the result. Unlike `must(closure)`, it has structural `equals()`, its own type, and can be described in a rule catalog.
- **Goal:** Close the README promise with the half that adds value, and state plainly that `where()` is the field half.
- **Specification:** Reversa forward 018 (decision D8).

### 10. Tautology & Contradiction Detection 📐 (Shipped in v1.6.0)
- **Description:** `isTautology()` and `isContradiction()` on every specification, structural and conservative (`true` only when the shape of the tree proves it): `AlwaysTrue`/`AlwaysFalse`, `not`, flattened `and`/`or` with `A ∧ ¬A` and `A ∨ ¬A`, disjoint leaves through `isDisjointWith`, `in([])`, inverted `between`. A truth-table test guards soundness. The rule engine reports a `RuleCompilationWarning` for catalog rules that can never fail or never pass.
- **Goal:** Close the last README promise of the 2026-10 audit without a SAT solver and without touching `evaluate()`.
- **Specification:** Reversa forward 019 (decision D9).

### 11. Announced-but-Unshipped Backlog II: TTL, Partition DAG Index & Three SQL Dialects 📋 (Shipped in v1.6.0)
- **Description:** TTL and auto-pruning on volatile repositories (`withTtl()`, `prune()`); a materialised index on the partition DAG (topological order and clusters of mutually disjoint sibling partitions) so that routing and traversal stop testing every sibling in large DAGs, shipped only if a reproducible benchmark proves the gain at 100+ partitions; and the DB2, Informix and DuckDB SQL dialects.
- **Goal:** Each item is either code with a benchmark or a measured number explaining why it is not. **Outcome:** the traversal index ships on by default (`findAll` 45 % to 77 % faster); the routing clusters ship off by default because building them (one `isDisjointWith()` per sibling pair, 88 ms for two `in()` sets of 162 values) made `put()` 3 to 6 times slower with library specifications (`tests/Benchmark/REPORT-dag-index.md`); `paginate()` added to every dialect on the way.
- **Specification:** Reversa forward 020.

### 12. Specification `simplify()` 🧮 (next)
- **Description:** Structural simplification built on milestone 10: absorbing identities, double negation, idempotent duplicates, contradictory conjunctions collapsed to `alwaysFalse()`, tautological disjunctions to `alwaysTrue()`.
- **Goal:** Smaller trees for the visitors and for the catalog.
- **Target Release:** v1.6.x (after milestone 10; own forward specification).

---

## 🔮 v2.0.0 — Zero `unserialize`, Shared Accessor & Async

### 13. PHP-Native Serializer Retirement, Step 2: Removal & JSON-Only Envelope 🗑️
- **Description:** `PhpNativeEntitySerializer` and the `.bin` branch are removed; the single-file envelope is always JSON and owned by the repository, the serializer only serialises the entity; existing `.bin` files fail with the migration recipe. Per-entity persistence metadata (`__persistence_metadata`, documented by the 006 data delta and never produced) is written and hydrated by the file repositories in the same format change.
- **Goal:** `grep unserialize src/` returns nothing. Declared breaking.

### 14. Shared Property Accessor with ALinq 🧩
- **Description:** `PropertyAccessor` and the ALinq `ALinqPropertyAccess` ship the same resolution order as two copies. Extracting one package both libraries depend on changes the dependency graph of both (ALinq roadmap milestone 11).
- **Goal:** One accessor, one contract test.

### 15. PHP Fibers & Non-Blocking Async Specification Runner ⚡
- **Description:** Optional asynchronous specification evaluation runner powered by native PHP 8.1+ Fibers and Revolt Event Loop for executing I/O-bound composite specification branches (remote REST APIs, gRPC fraud checks, external microservices) concurrently.
- **Goal:** Parity with modern asynchronous evaluation while keeping the core library zero-dependency and synchronous for traditional PHP-FPM environments (or `antevemus/aspecification-async`).

---

## 🗓️ Unscheduled

### 16. Distributed Specification Cache (PSR-6 / PSR-16 / Redis) ⚡
- **Description:** Native distributed caching adapters for partitioned DAG repositories and dynamic catalog rule sets (wrapping `PdoRuleCatalog` from milestone 6), and **specification serialization** (the declarative `method` constructor of forward 018 RN-08 was deferred here: the core has no `fromArray`/`jsonSerialize` of specifications yet).
- **Goal:** Multi-node high-throughput deployments with distributed cache invalidation.

### 17. GraphQL AST & OpenAPI 3.1 Query Compilers 🔍
- **Description:** Bidirectional AST compilers translating GraphQL query filters and OpenAPI 3.1 query parameter syntax directly into pure domain `ISpecification` trees.

### 18. Doctrine ORM & Laravel Eloquent Query Visitors 🌉
- **Description:** GoF Visitor compilers for `Doctrine\ORM\QueryBuilder` and Laravel's `Illuminate\Database\Eloquent\Builder`, beside the 12 database drivers over 7 SQL dialects and Adianti TCriteria.

### 19. Reactive Domain Event Sourcing Triggers 📡
- **Description:** Domain event emitter that triggers events whenever an entity transitions into or out of satisfying critical domain specifications (Event Sourcing, Outbox, CQRS).

### 20. AI-Assisted Specification Synthesizer 🤖
- **Description:** Rule synthesis assistant that generates optimized, non-contradictory specification trees (milestone 10 as the checker) from plain-text business requirements.

### 21. PDO-Backed Repository (the Domian `HibernateRepository`) 🗄️
- **Description:** Domian shipped a 799-line `HibernateRepository` that turns a specification into an ORM query; the PHP port has the query half (`SqlQueryVisitor`, `TCriteria`) but no `IRepository` over a database. Found by the 2026-10-09 parity audit (the module was never inventoried). A `PdoRepository` would reuse the SQL visitor for `find*`/`count`/`remove*` over a table mapped by a field mapper, with partitions as `WHERE` fragments.
- **Goal:** The repository family covers memory, file and database, as Domian's did.

---

## 🔒 Reserved (design in progress)

- **`RequirementState`, the document requirement state machine.** Announced by the `1.0.0` CHANGELOG ("modeling document states, preconditions and transition validations") and never shipped. It is neither scheduled nor declined: it is the seed of a specification-driven state machine feature being designed for one of the Antevemus modules, and will get its own milestone when that design lands.

## 🚫 Declined (with a note in the CHANGELOG)

- **`RelationalOperator` cases for `BETWEEN`, `IN`, `LIKE` and `REGEX`.** Those predicates are specifications (`between()`, `in()`, `WildcardSpecification`, `RegexSpecification`), not comparison operators; `IN` gets its own leaf in milestone 6.

---

## 🤝 Community & Contributions
We welcome suggestions, feedback, and pull requests! If you would like to propose a feature or contribute to any item on this roadmap, please open an issue or discussion on [GitHub Issues](https://github.com/antevemus-it/Antevemus.ASpecification/issues).
