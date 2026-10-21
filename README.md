# Antevemus ASpecification

<p align="left">
  <strong>🇺🇸 English</strong> &nbsp;|&nbsp; <a href="README.pt-BR.md">🇧🇷 Português (Brasil)</a>
</p>

[![CI](https://github.com/antevemus-it/Antevemus.ASpecification/actions/workflows/tests.yml/badge.svg)](https://github.com/antevemus-it/Antevemus.ASpecification/actions/workflows/tests.yml)
[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php&logoColor=white)](https://php.net)
[![Latest Version](https://img.shields.io/badge/Release-v2.6.1-blue.svg)](https://github.com/antevemus-it/Antevemus.ASpecification/releases/tag/v2.6.1)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Tests](https://img.shields.io/badge/Tests-37%2F37%20Suites%20Pass%20(43925%20Assertions)%20%2B%20Domian%20Parity%20230-success)](docs/en/quality/tests.md)
[![Architecture](https://img.shields.io/badge/Architecture-DDD%20%7C%20Evans%20%26%20Fowler%20Specification-orange)](http://www.martinfowler.com/apsupp/spec.pdf)
[![Upstream: Domian](https://img.shields.io/badge/Origin-Domian%20(Apache%202.0)-brightgreen)](https://domian.sourceforge.net/index.html)
[![Synergy: ALinq](https://img.shields.io/badge/Synergy-Antevemus.AlinqCollection-purple)](https://github.com/antevemus-it/Antevemus.AlinqCollection)

> **Enterprise Specification Pattern Framework for PHP 8.2+** (PHP 8.4 only for the optional ALinq integration)  
> Independent, unofficial PHP reimplementation and evolution of the acclaimed Java [Domian](https://domian.sourceforge.net/index.html) framework, grounded in the seminal paper [Specifications](http://www.martinfowler.com/apsupp/spec.pdf) by Eric Evans and Martin Fowler. Enhanced with natural-language fluent chaining, zero-exception Notification Pattern with rich diagnostic telemetry, relational database Dynamic Rule Engine, multi-DBMS SQL Query Visitor (15 drivers, 10 SQL dialects), Adianti Framework TCriteria Builder, Directed Acyclic Graph (DAG) partitioned repositories, hybrid persistence, high-concurrency IPC primitives, a specification-driven state machine, use cases with a result shared between both, concurrent resolution of the external data I/O-bound rules depend on, a repository over PDO whose queries are specifications compiled to SQL (with lifecycle columns, batch and set-based operations and relationships loaded in batch), domain events emitted when an entity starts or stops satisfying a specification, on a write or by the mere passage of time, declared feature flags with targeting and an audit trail, text comparison that ignores case and accents with the same answer in memory and in SQL, and specifications written as canonical JSON and read back without `unserialize()` nor a class name taken from the input. Every promise of its algebra is checked against the evaluation by a soundness bench.

The documentation lives in [`docs/en`](docs/en/index.md) (English) and [`docs/pt-BR`](docs/pt-BR/index.md) (Portuguese, Brazil), one page per topic, in the Docs Kit format (a `docs-nav.json` per language). The examples keep the numbers they had in this README (example 17.1 is still `### 17.1.`, now on its page), and every PHP block of the pages is checked by the test suite.

---

## ⚡ First Use

A rule is an object: compose it, ask it whether a candidate satisfies it, get every failure with its code and message without an exception, and compile the same rule to SQL (the same block opens [Getting Started](docs/en/getting-started/index.md)):

```php
use Antevemus\ASpecification\Spec;

// Two business rules, each with its own message and code
$adult = Spec::property('age', Spec::greaterThanOrEqualTo(18))
    ->because('Customer must be of legal age.')
    ->withCode('CLI_001');
$active = Spec::property('status', Spec::equalTo('ACTIVE'))
    ->because('Only active accounts can receive a credit line.')
    ->withCode('CLI_002');
$creditRule = $adult->and($active);

$customer = (object) ['age' => 17, 'status' => 'ACTIVE'];   // any object: an entity, a DTO, a stdClass

$creditRule->isSatisfiedBy($customer);                      // false

$result = $creditRule->evaluate($customer);                 // no exception: a result with every failure
count($result->failures);                                   // 1
$result->failures[0]->code;                                 // 'CLI_001'
$result->failures[0]->message;                              // 'Customer must be of legal age.'

// The same rule as a parameterized WHERE clause (15 drivers, 10 SQL dialects)
$where = Spec::toSql($creditRule, 'pgsql');
$where->getSql();         // '(("age" IS NOT NULL AND "age" >= :p1) AND ("status" IS NOT NULL AND "status" = :p2))'
$where->getParameters();  // [':p1' => 18, ':p2' => 'ACTIVE']
```

---

## 📋 Requirements

- **PHP**: `^8.2` (tested on PHP 8.2 and 8.4). PHP 8.4 is required only by the optional ALinq integration (`Antevemus.AlinqCollection`, Module 14), which the library detects at runtime.
- **PHP Extensions**:
  - `ext-json` (for JSON entity serialization; required)
  - `ext-mbstring` (required since v1.5.0: Unicode-aware case-insensitive string specifications, `mb_strtolower`)
  - `ext-intl` *(optional: `TextComparison::IgnoreCaseAndAccents`, the text comparison that also ignores accents, examples 20 and 20.1; without it that option is refused with `MissingExtensionException` and everything else works)*
  - `ext-sysvsem` *(optional: `SysVSemaphoreSynchronizer`, the reader/writer lock shared between processes through SysV IPC semaphores, Linux/Unix)*
  - `ext-curl` *(optional: `HttpDataSource`, the concurrent HTTP fetching of the `DataResolver`, Module 21; without it an HTTP source is refused when registered and `CallableDataSource` keeps working)*
  - `ext-pdo` *(optional, with the driver of your database: `PdoRepository` (Modules 22, 25 and 26), the PDO stores of the state machine, `PdoRuleCatalog` and the PDO domain event outbox and trigger state store (Modules 23 and 24))*
- **Dependency**: one, from the same author, with no dependencies of its own: [`antevemus/adeep-property-access`](https://github.com/antevemus-it/Antevemus.ADeepPropertyAccess) `^1.0` (since v2.0.0), the property accessor shared with ALinq, so that both libraries read the same value from the same candidate (example 10.1). Composer installs it.
- **Optional package**: `antevemus/alinq-collection` `^2.2.0` for `ALinqBridge` (typed `IALinqCollection`/`IALinqLazyCollection` returns), `ALinqSpecificationVisitor` and O(1) lazy streaming over `IRepository::iterate()` (requires PHP 8.4; ALinq reads a non-public property only when it is marked `#[Specifiable]`, the same rule as this package, and its 2.0 resolves properties through the same shared accessor).

> **Upgrading from 1.x?** Read [UPGRADE-2.0.md](UPGRADE-2.0.md) first: `.bin` files of the PHP-native serializer and rule catalogs with Portuguese values are converted on 1.14.x, before the upgrade.

---

## 🚀 Installation

Install via Composer: `composer require antevemus/aspecification`. Requirements, installation and the first use, in detail: [Getting Started](docs/en/getting-started/index.md).

---

## 📚 Documentation

One line per page, grouped as in the navigation; the number in parentheses is the example of the former README that the page hosts.

**Getting started**

- [Installation and first use](docs/en/getting-started/index.md) · [pt-BR](docs/pt-BR/getting-started/index.md): Requirements, installation and a first rule evaluated and compiled to SQL.

**Concepts**

- [Origins and foundation](docs/en/concepts/origins.md) · [pt-BR](docs/pt-BR/concepts/origins.md): The Evans & Fowler paper, the Domian (Java) origin and the references.
- [Core features](docs/en/concepts/core-features.md) · [pt-BR](docs/pt-BR/concepts/core-features.md): Every capability of the library in one list, with the module and the example of each.
- [What sets us apart](docs/en/concepts/what-sets-us-apart.md) · [pt-BR](docs/pt-BR/concepts/what-sets-us-apart.md): A comparison with Domian and with typical PHP specification libraries.

**Specifications**

- [Fluent chaining](docs/en/specifications/fluent-chaining.md) (examples 1 and 1.1) · [pt-BR](docs/pt-BR/specifications/fluent-chaining.md): Business rules composed as natural-language sentences; int-typed leaves (`asInteger()`, `Spec::int()`).
- [Notification pattern](docs/en/specifications/notification-pattern.md) (example 2) · [pt-BR](docs/pt-BR/specifications/notification-pattern.md): `evaluate()`: every failure with its code, message and property, without exceptions.
- [Partial satisfaction](docs/en/specifications/partial-satisfaction.md) (example 3) · [pt-BR](docs/pt-BR/specifications/partial-satisfaction.md): `remainderUnsatisfiedBy()`: only the clauses a candidate still fails.
- [Tautology and simplification](docs/en/specifications/tautology-and-simplification.md) (examples 12 and 12.1) · [pt-BR](docs/pt-BR/specifications/tautology-and-simplification.md): `isTautology()`, `isContradiction()` and `simplify()`.
- [Method calls](docs/en/specifications/method-calls.md) (example 13) · [pt-BR](docs/pt-BR/specifications/method-calls.md): `MethodParameterizedSpecification`: a rule that calls a method of the candidate.
- [Text comparison](docs/en/specifications/text-comparison.md) (examples 20 and 20.1) · [pt-BR](docs/pt-BR/specifications/text-comparison.md): Comparison ignoring case and accents, wildcards and regular expressions, in memory and in SQL.
- [Serialization](docs/en/specifications/serialization.md) (example 21) · [pt-BR](docs/pt-BR/specifications/serialization.md): `SpecificationCodec`: specifications as canonical JSON, read back without `unserialize()`.

**Repositories**

- [Partitioned DAG](docs/en/repositories/partitioned-dag.md) (example 4) · [pt-BR](docs/pt-BR/repositories/partitioned-dag.md): Repositories partitioned by specifications, with early pruning of disjoint branches.
- [Persistence and cache](docs/en/repositories/persistence-and-cache.md) (examples 5 and 5.1) · [pt-BR](docs/pt-BR/repositories/persistence-and-cache.md): File repositories, the hybrid L1 (RAM) + L2 (disk) tier and its write-behind mode.
- [PDO repository](docs/en/repositories/pdo.md) (example 17) · [pt-BR](docs/pt-BR/repositories/pdo.md): `PdoRepository`: queries are specifications compiled to SQL.
- [Lifecycle](docs/en/repositories/pdo-lifecycle.md) (example 17.1) · [pt-BR](docs/pt-BR/repositories/pdo-lifecycle.md): Timestamps, soft delete, hooks and the SQL log.
- [Batch writes](docs/en/repositories/pdo-batch.md) (example 17.2) · [pt-BR](docs/pt-BR/repositories/pdo-batch.md): Batch writes and set-based operations.
- [Relationships](docs/en/repositories/pdo-relationships.md) (example 17.3) · [pt-BR](docs/pt-BR/repositories/pdo-relationships.md): References, compositions and eager loading in batch, never N+1.

**Rule engine**

- [Dynamic rules](docs/en/rule-engine/dynamic-rules.md) (example 7) · [pt-BR](docs/pt-BR/rule-engine/dynamic-rules.md): Rules compiled at runtime from relational catalogs, severity triage and the document matrix.
- [A real consumer](docs/en/rule-engine/real-consumer.md) (example 22) · [pt-BR](docs/pt-BR/rule-engine/real-consumer.md): Lookup-only rule types, the most specific rule of a type and target labels.

**SQL**

- [SQL query visitor](docs/en/sql/query-visitor.md) (example 8) · [pt-BR](docs/pt-BR/sql/query-visitor.md): Parameterized `WHERE` clauses for 15 drivers and 10 SQL dialects.
- [TCriteria builder](docs/en/sql/tcriteria.md) (example 9) · [pt-BR](docs/pt-BR/sql/tcriteria.md): Specifications compiled to the `TCriteria` and `TFilter` of Adianti.
- [TCriteria: accents and subqueries](docs/en/sql/tcriteria-accents-and-subqueries.md) (example 22.1) · [pt-BR](docs/pt-BR/sql/tcriteria-accents-and-subqueries.md): `withAccentFolding()`, `inSubquery()` and `existsIn()`.

**Integrations**

- [ALinq](docs/en/integrations/alinq.md) (example 10) · [pt-BR](docs/pt-BR/integrations/alinq.md): The ALinq bridge, the LINQ predicate compiler and lazy streaming.
- [Attributes](docs/en/integrations/attributes.md) (example 11) · [pt-BR](docs/pt-BR/integrations/attributes.md): `#[AssertSpec]` and `#[ValidateRule]` on PHP 8 classes.
- [Adianti host](docs/en/integrations/adianti-host.md) (example 22.3) · [pt-BR](docs/pt-BR/integrations/adianti-host.md): Adianti screens as hosts: a joined transaction and a `UseCaseResult` on `TMessage`/`TToast`.

**State machine**

- [State machine](docs/en/state-machine/index.md) (example 14) · [pt-BR](docs/pt-BR/state-machine/index.md): Guards are specifications: parallel regions, JSON definitions, the runner and persisted deadlines.
- [Deadlines in business time](docs/en/state-machine/business-time.md) (example 14.11) · [pt-BR](docs/pt-BR/state-machine/business-time.md): business days, working hours and holidays; a pause that does not spend the weekend.
- [Use cases](docs/en/state-machine/use-cases.md) (example 15) · [pt-BR](docs/pt-BR/state-machine/use-cases.md): `IUseCase`, `UseCaseRunner` and the result shared with the machine.
- [A real consumer](docs/en/state-machine/real-consumer.md) (example 23) · [pt-BR](docs/pt-BR/state-machine/real-consumer.md): legacy state codes, definitions versioned in the database with drafts and publication, and tenants sharing one machine.

**Data resolution**

- [Concurrent resolution](docs/en/resolution/concurrent.md) (example 16) · [pt-BR](docs/pt-BR/resolution/concurrent.md): `Spec::external()` and the `DataResolver`: external data fetched concurrently.
- [Asynchronous sources](docs/en/resolution/async.md) (example 22.2) · [pt-BR](docs/pt-BR/resolution/async.md): `AsyncDataSource`: submit once, poll later.
- [Deferred sources](docs/en/resolution/deferred.md) (example 16.1) · [pt-BR](docs/pt-BR/resolution/deferred.md): `IDeferredDataSource` and `IStageTransport`: the hook through which a satellite package fetches the non-HTTP sources of a stage together.

**Domain events**

- [From specifications](docs/en/events/from-specifications.md) (example 18) · [pt-BR](docs/pt-BR/events/from-specifications.md): Domain events when an entity starts or stops satisfying a specification.
- [From the passage of time](docs/en/events/time-based.md) (example 18.1) · [pt-BR](docs/pt-BR/events/time-based.md): `SpecificationTrigger::timed()` and the `TriggerSweeper`.

**Feature flags**

- [Feature flags](docs/en/feature-flags/index.md) (example 19) · [pt-BR](docs/pt-BR/feature-flags/index.md): Flags declared in code and switched in the database, with targeting and an audit trail.
- [Window and rollout](docs/en/feature-flags/window-and-rollout.md) (example 19.1) · [pt-BR](docs/pt-BR/feature-flags/window-and-rollout.md): The validity window and the percentage rollout of a flag.

**Caching**

- [Caching](docs/en/caching/index.md) (examples 23 and 23.1) · [pt-BR](docs/pt-BR/caching/index.md): The rule catalog, the feature flags and the routing clusters of a partitioned repository read through a shared PSR-16 cache.

**Benchmarking**

- [Benchmarking](docs/en/benchmarking/index.md) (example 6) · [pt-BR](docs/pt-BR/benchmarking/index.md): `StopWatch` and `InstrumentationUtils`.

**Quality**

- [Tests](docs/en/quality/tests.md) · [pt-BR](docs/pt-BR/quality/tests.md): The runner report, the Domian parity suite and the soundness bench.
- [Guarantees](docs/en/quality/guarantees.md) · [pt-BR](docs/pt-BR/quality/guarantees.md): The five checks no release ships without.

**Project**

- [Directory structure](docs/en/project/directory-structure.md) · [pt-BR](docs/pt-BR/project/directory-structure.md): The `src/` tree, folder by folder.
- [Roadmap](docs/en/project/roadmap.md) · [pt-BR](docs/pt-BR/project/roadmap.md): Milestones shipped and planned; the full plan is in ROADMAP.md.
- [Attribution and license](docs/en/project/attribution-and-license.md) · [pt-BR](docs/pt-BR/project/attribution-and-license.md): Domian attribution, Apache 2.0 compliance and the MIT license.
- [Authors](docs/en/project/authors.md) · [pt-BR](docs/pt-BR/project/authors.md): Maintainer and contact.

---

## 🧪 Quality

- **Runner:** `php tests/run_all.php`, 37/37 suites, 43 925 assertions, zero regressions (the report: [Tests](docs/en/quality/tests.md)).
- **PHPUnit:** `vendor/bin/phpunit`, 624 tests and 107 097 assertions, the Domian parity suite (230 tests) included.
- **Soundness bench:** every promise of the algebra checked against the evaluation over a pool of over 700 specifications, with a guard that fails when a specification class is missing from it.
- **SQL parity:** the same answers in memory and in PostgreSQL 17, MySQL 8.4 and MariaDB 11.4, in CI.
- **Mutation testing:** Infection with a floor that only rises, now 89 (90.54% MSI measured in 2.5.0).
- **The five permanent guarantees** and how to run them: [Guarantees](docs/en/quality/guarantees.md).

---

## 🗺️ Roadmap & Next Steps

Milestones shipped and planned, in short: [Roadmap](docs/en/project/roadmap.md). The full strategic plan: 👉 **[ROADMAP.md](ROADMAP.md)**.

---

## ⚖️ Upstream Attribution, Licensing & Acknowledgments

**Antevemus ASpecification** expresses its deepest respect and gratitude to the pioneer authors who established the theoretical and practical foundations of this pattern:

- **Eirik Torske** (Project Administrator & Developer) and **Bjørn Nordlund** (Contributor), creators of the **[Domian (Java)](https://domian.sourceforge.net/)** framework, whose pioneering work in Boolean algebra, Venn set calculus (`isGeneralizationOf`, `isSpecialCaseOf`, `isDisjointWith`), and partitioned repository architectures served as the inspiring foundation for this PHP 8.2+ implementation.
- **Eric Evans** and **Martin Fowler**, for authoring the seminal paper *[Specifications (2002)](http://www.martinfowler.com/apsupp/spec.pdf)* and foundational works on Domain-Driven Design (DDD).

### Apache License 2.0 Compliance
The upstream **Domian** framework is distributed under the terms of the **[Apache License, Version 2.0](http://www.apache.org/licenses/LICENSE-2.0)** (Copyright 2006-2010 the original author or authors, as stated in the Domian sources; developers Eirik Torske and Bjørn Nordlund). In full compliance with Section 4 of said license:
- Original authorship and copyright notices are fully preserved.
- The [NOTICE.md](NOTICE.md) and [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md) files contain the formal provenance and full third-party license texts.
- This independent PHP implementation is made available to the global open-source community under the **MIT** license. It is not a clean-room rewrite: it was written with Domian's public sources at hand, and the files that follow a specific Domian class or reuse a sentence of its documentation say so in their DocBlock, keeping the upstream attribution as Section 4 of the Apache License requires.

---

## 📄 License

Distributed under the **MIT** license. See [LICENSE](LICENSE), [NOTICE.md](NOTICE.md), and [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md) for more information.

---

## 👨‍💻 Authors & Corporate Maintenance

**Heliton Junior (CTO)**  
- Email: [contato@antevemus.com.br](mailto:contato@antevemus.com.br)  
- Website: [antevemus.com.br](https://antevemus.com.br)  
- Organization: **Antevemus Soluções Inovadoras em TI Ltda.**
