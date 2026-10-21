# Antevemus ASpecification

The official documentation of the library, one page per topic. The same pages exist in Portuguese (Brazil), in `docs/pt-BR`; the numbered examples (`### 17.1.` and so on) keep the numbers they had in the README, and every PHP block of these pages is checked by the test suite.

> **Enterprise Specification Pattern Framework for PHP 8.2+** (PHP 8.4 only for the optional ALinq integration)  
> Independent, unofficial PHP reimplementation and evolution of the acclaimed Java [Domian](https://domian.sourceforge.net/index.html) framework, grounded in the seminal paper [Specifications](http://www.martinfowler.com/apsupp/spec.pdf) by Eric Evans and Martin Fowler. Enhanced with natural-language fluent chaining, zero-exception Notification Pattern with rich diagnostic telemetry, relational database Dynamic Rule Engine, multi-DBMS SQL Query Visitor (15 drivers, 10 SQL dialects), Adianti Framework TCriteria Builder, Directed Acyclic Graph (DAG) partitioned repositories, hybrid persistence, high-concurrency IPC primitives, a specification-driven state machine, use cases with a result shared between both, concurrent resolution of the external data I/O-bound rules depend on, a repository over PDO whose queries are specifications compiled to SQL (with lifecycle columns, batch and set-based operations and relationships loaded in batch), domain events emitted when an entity starts or stops satisfying a specification, on a write or by the mere passage of time, declared feature flags with targeting and an audit trail, text comparison that ignores case and accents with the same answer in memory and in SQL, and specifications written as canonical JSON and read back without `unserialize()` nor a class name taken from the input. Every promise of its algebra is checked against the evaluation by a soundness bench.

## Documentation Map

### Getting started

- [Installation and first use](getting-started/index.md): Requirements, installation and a first rule evaluated and compiled to SQL.

### Concepts

- [Origins and foundation](concepts/origins.md): The Evans & Fowler paper, the Domian (Java) origin and the references.
- [Core features](concepts/core-features.md): Every capability of the library in one list, with the module and the example of each.
- [What sets us apart](concepts/what-sets-us-apart.md): A comparison with Domian and with typical PHP specification libraries.

### Specifications

- [Fluent chaining](specifications/fluent-chaining.md) (example 1): Business rules composed as natural-language sentences.
- [Notification pattern](specifications/notification-pattern.md) (example 2): `evaluate()`: every failure with its code, message and property, without exceptions.
- [Partial satisfaction](specifications/partial-satisfaction.md) (example 3): `remainderUnsatisfiedBy()`: only the clauses a candidate still fails.
- [Tautology and simplification](specifications/tautology-and-simplification.md) (example 12): `isTautology()`, `isContradiction()` and `simplify()`.
- [Method calls](specifications/method-calls.md) (example 13): `MethodParameterizedSpecification`: a rule that calls a method of the candidate.
- [Text comparison](specifications/text-comparison.md) (examples 20 and 20.1): Comparison ignoring case and accents, wildcards and regular expressions, in memory and in SQL.
- [Serialization](specifications/serialization.md) (example 21): `SpecificationCodec`: specifications as canonical JSON, read back without `unserialize()`.

### Repositories

- [Partitioned DAG](repositories/partitioned-dag.md) (example 4): Repositories partitioned by specifications, with early pruning of disjoint branches.
- [Persistence and cache](repositories/persistence-and-cache.md) (examples 5 and 5.1): File repositories, the hybrid L1 (RAM) + L2 (disk) tier and its write-behind mode.
- [PDO repository](repositories/pdo.md) (example 17): `PdoRepository`: queries are specifications compiled to SQL.
- [Lifecycle](repositories/pdo-lifecycle.md) (example 17.1): Timestamps, soft delete, hooks and the SQL log.
- [Batch writes](repositories/pdo-batch.md) (example 17.2): Batch writes and set-based operations.
- [Relationships](repositories/pdo-relationships.md) (example 17.3): References, compositions and eager loading in batch, never N+1.

### Rule engine

- [Dynamic rules](rule-engine/dynamic-rules.md) (example 7): Rules compiled at runtime from relational catalogs, severity triage and the document matrix.
- [A real consumer](rule-engine/real-consumer.md) (example 22): Lookup-only rule types, the most specific rule of a type and target labels.

### SQL

- [SQL query visitor](sql/query-visitor.md) (example 8): Parameterized `WHERE` clauses for 15 drivers and 10 SQL dialects.
- [TCriteria builder](sql/tcriteria.md) (example 9): Specifications compiled to the `TCriteria` and `TFilter` of Adianti.
- [TCriteria: accents and subqueries](sql/tcriteria-accents-and-subqueries.md) (example 22.1): `withAccentFolding()`, `inSubquery()` and `existsIn()`.

### Integrations

- [ALinq](integrations/alinq.md) (example 10): The ALinq bridge, the LINQ predicate compiler and lazy streaming.
- [Attributes](integrations/attributes.md) (example 11): `#[AssertSpec]` and `#[ValidateRule]` on PHP 8 classes.
- [Adianti host](integrations/adianti-host.md) (example 22.3): Adianti screens as hosts: a joined transaction and a `UseCaseResult` on `TMessage`/`TToast`.

### State machine

- [State machine](state-machine/index.md) (example 14): Guards are specifications: parallel regions, JSON definitions, the runner and persisted deadlines.
- [Deadlines in business time](state-machine/business-time.md) (example 14.11): business days, working hours and holidays; a pause that does not spend the weekend.
- [Use cases](state-machine/use-cases.md) (example 15): `IUseCase`, `UseCaseRunner` and the result shared with the machine.
- [A real consumer](state-machine/real-consumer.md) (example 23): legacy state codes, definitions versioned in the database with drafts and publication, and tenants sharing one machine.

### Data resolution

- [Concurrent resolution](resolution/concurrent.md) (example 16): `Spec::external()` and the `DataResolver`: external data fetched concurrently.
- [Asynchronous sources](resolution/async.md) (example 22.2): `AsyncDataSource`: submit once, poll later.
- [Deferred sources](resolution/deferred.md) (example 16.1): `IDeferredDataSource` and `IStageTransport`: the hook through which a satellite package fetches the non-HTTP sources of a stage together.

### Domain events

- [From specifications](events/from-specifications.md) (example 18): Domain events when an entity starts or stops satisfying a specification.
- [From the passage of time](events/time-based.md) (example 18.1): `SpecificationTrigger::timed()` and the `TriggerSweeper`.

### Feature flags

- [Feature flags](feature-flags/index.md) (example 19): Flags declared in code and switched in the database, with targeting and an audit trail.
- [Window and rollout](feature-flags/window-and-rollout.md) (example 19.1): The validity window and the percentage rollout of a flag.

### Caching

- [Caching](caching/index.md) (examples 23 and 23.1): The rule catalog, the feature flags and the routing clusters of a partitioned repository read through a shared PSR-16 cache.

### Benchmarking

- [Benchmarking](benchmarking/index.md) (example 6): `StopWatch` and `InstrumentationUtils`.

### Quality

- [Tests](quality/tests.md): The runner report, the Domian parity suite and the soundness bench.
- [Guarantees](quality/guarantees.md): The five checks no release ships without.

### Project

- [Directory structure](project/directory-structure.md): The `src/` tree, folder by folder.
- [Roadmap](project/roadmap.md): Milestones shipped and planned; the full plan is in ROADMAP.md.
- [Attribution and license](project/attribution-and-license.md): Domian attribution, Apache 2.0 compliance and the MIT license.
- [Authors](project/authors.md): Maintainer and contact.

## Where Is Each Thing

| What | Where |
| :--- | :--- |
| Overview, badges and the first use | [README.md](https://github.com/antevemus-it/Antevemus.ASpecification/blob/main/README.md) |
| What changed in each version | [CHANGELOG.md](https://github.com/antevemus-it/Antevemus.ASpecification/blob/main/CHANGELOG.md) |
| Milestones shipped and planned | [ROADMAP.md](https://github.com/antevemus-it/Antevemus.ASpecification/blob/main/ROADMAP.md) |
| Upgrading from 1.x | [UPGRADE-2.0.md](https://github.com/antevemus-it/Antevemus.ASpecification/blob/main/UPGRADE-2.0.md) |
| Provenance and third-party licenses | [NOTICE.md](https://github.com/antevemus-it/Antevemus.ASpecification/blob/main/NOTICE.md), [THIRD_PARTY_NOTICES.md](https://github.com/antevemus-it/Antevemus.ASpecification/blob/main/THIRD_PARTY_NOTICES.md) |
