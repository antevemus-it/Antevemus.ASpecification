# Product Roadmap & Future Milestones — Antevemus ASpecification

This document outlines the strategic engineering roadmap and upcoming enhancements for the **Antevemus ASpecification** framework.

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

## 🎯 Short-Term Milestones (v1.4.x)

### 6. README Promises II: Inter-Process, Unicode, Lazy Sources & Relational Catalog 🧵
- **Description:** The remaining README promises that change observable behaviour: `SemaphoreSynchronizer` backed by SysV IPC (`sem_get`) when `ext-sysvsem` is available (in-process fallback documented), Unicode-aware case-insensitive string specifications via `mb_*` (`ext-mbstring`), a truly lazy repository source for `findAsLazyCollection()` (iterator, no `getAll()` materialisation), a typed bridge contract for ALinq collections with `antevemus/alinq-collection` declared in `suggest`, a PDO-backed `IRuleCatalog` mirroring the relational rule and document tables, and `IN (...)` translation of `in()` value sets by the SQL and TCriteria visitors (today emitted as a chain of `OR` equalities).
- **Goal:** The Requirements and Core Features sections describe what the code does.
- **Target Release:** v1.4.0

### 7. Distributed Specification Cache (PSR-6 / PSR-16 / Redis) ⚡
- **Description:** Add native distributed caching adapters for partitioned DAG repositories and dynamic catalog rule sets.
- **Goal:** Enable multi-node high-throughput deployments with distributed cache invalidation across microservices and cluster nodes.
- **Target Release:** v1.4.0

### 8. GraphQL AST & OpenAPI 3.1 Query Compilers 🔍
- **Description:** Provide bidirectional AST compilers translating GraphQL query filters and OpenAPI 3.1 query parameter syntax directly into pure domain `ISpecification` trees.
- **Goal:** Unify frontend querying capabilities with domain-layer business validation and database query projection.
- **Target Release:** v1.4.0

---

## 🚀 Medium-Term Milestones (v1.5.x)

### 9. Parameterized Reflection Specifications & Tautology/Contradiction Detection 🔬
- **Description:** `Specifications/Reflection/` (`FieldParameterizedSpecification`, `MethodParameterizedSpecification`) and structural detection of tautologies and contradictions (`A ∧ ¬A`, `A ∨ ¬A`, disjoint leaves through `isDisjointWith`, absorbing `alwaysTrue()`/`alwaysFalse()`), both promised by the README. Each gets its own forward specification before code.
- **Goal:** Close the last two README promises of the 2026-10 audit.
- **Target Release:** v1.5.0

### 10. Doctrine ORM & Laravel Eloquent Query Visitors 🌉
- **Description:** Build dedicated GoF Visitor compilers for `Doctrine\ORM\QueryBuilder` and Laravel's `Illuminate\Database\Eloquent\Builder`.
- **Goal:** Expand our query-level SQL translation capabilities (currently supporting 12 database drivers over 7 SQL dialects, and Adianti TCriteria) to the two most popular ORM ecosystems in the PHP world.
- **Target Release:** v1.5.0

---

## 🔮 Long-Term Vision (v2.0+)

### 11. Reactive Domain Event Sourcing Triggers 📡
- **Description:** Reactive domain event emitter that triggers Domain Events whenever an entity transitions into or out of satisfying critical domain specifications.
- **Goal:** Seamless integration with Event Sourcing, Outbox Pattern, and CQRS architectures.

### 12. AI-Assisted Specification Synthesizer 🤖
- **Description:** Rule synthesis assistant that generates optimized, non-contradictory specification trees and Venn set subsumption models from domain stories or plain text business requirements.

### 13. PHP Fibers & Non-Blocking Async Specification Runner ⚡
- **Description:** Provide an optional asynchronous specification evaluation runner powered by native PHP 8.1+ Fibers and Revolt Event Loop for executing I/O-bound composite specification branches (remote REST APIs, gRPC fraud checks, external microservices) concurrently in parallel, reducing total latency by up to 60%.
- **Goal:** Parity with modern asynchronous evaluation while keeping the core library 100% zero-dependency and synchronous for traditional PHP-FPM environments.
- **Target Release:** v2.0.0 (or `antevemus/aspecification-async`)

### 14. Announced-but-Unshipped Backlog (CHANGELOG errata, 2026-10) 📋
- **Description:** Capabilities that the `1.0.0`/`1.1.0` CHANGELOG entries announced and the code never shipped, kept here so the promise is not lost: ULID and UUID v7 entity identities; TTL and auto-pruning on volatile repositories; topological sorting and cluster indexing on the partition DAG; a severity field on `SpecificationFailure`; `RelationalOperator` cases for `BETWEEN`, `IN`, `LIKE` and `REGEX`; a document requirement state machine (`RequirementState`); DB2, Informix and DuckDB SQL dialects.
- **Goal:** Each item is either scheduled into a milestone above or explicitly declined with a note in the CHANGELOG.
- **Target Release:** unscheduled (triage after v1.4.0).

---

## 🤝 Community & Contributions
We welcome suggestions, feedback, and pull requests! If you would like to propose a feature or contribute to any item on this roadmap, please open an issue or discussion on [GitHub Issues](https://github.com/antevemus-it/Antevemus.ASpecification/issues).
