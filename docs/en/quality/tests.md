# 🧪 Code Quality & Automated Tests

The library features comprehensive unit and integration test coverage with zero regressions:

```bash
php tests/run_all.php
```

Report below as printed by the runner, with its Portuguese labels translated; the duration depends on the machine. Module 22 also prints its benchmark (10 000 rows in SQLite) and, without the `ASPEC_PDO_*` variables, that its PostgreSQL and MySQL runs were skipped, Module 24 its sweeps over 1 000 and 10 000 rows, Module 25 its batch, `updateWhere()`, null/subquery and null/`Char` parity runs, Module 26 its eager loading benchmarks (1 000 parents × 10 children, 1 000 owners × 5 targets) and the peak memory of `iterate()` with relations over 2 000 and 10 000 rows, Module 13 its run of 400 trees over 320 rows against the filters `TRepository` adds, Module 27 that its PostgreSQL, MySQL and MariaDB runs were skipped without the `ASPEC_PDO_*` variables, Modules 28 and 29 that `ext-intl` is missing, so that only the refusal was checked (with `ext-intl` they run the accent-insensitive batteries: Module 28 about 650 assertions on PHP 8.4, Module 29 about 1 500 with the three databases), Module 30 the distribution of its rollout over 10 000 subjects and that its database runs were skipped, Module 31 that its PostgreSQL, MySQL and MariaDB runs were skipped, Module 36 that `ext-intl` is missing (the accent battery of `toCriteria()` runs in the containers with it), its SQLite subquery battery and that its database runs were skipped, and Module 40 its properties (200 generated machines, the definitions round trip, the tenant isolation), the acceptance replay of the quote machine and that its database runs and the replay of a real sample were skipped; those lines are omitted here:

```text
====================================================================
 ANTEVEMUS ASPECIFICATION - MASTER TEST RUNNER & REGRESSION WATCH
====================================================================

• [SUITE] Module 1: Specifications & Boolean Algebra... ✅ PASS (285 assertions)
• [SUITE] Module 2: Entities & Domain Identifiers... ✅ PASS (59 assertions)
• [SUITE] Module 3: In-Memory Repositories & Base... ✅ PASS (90 assertions)
• [SUITE] Module 4: DAG Partitioning Architecture... ✅ PASS (94 assertions)
• [SUITE] Module 5: File Persistence & Hybrid Decorator... ✅ PASS (214 assertions)
• [SUITE] Module 6: Concurrency Utilities & RW-Lock... ✅ PASS (97 assertions)
• [SUITE] Module 7: Predicates, Factories, Helpers & Visitor... ✅ PASS (61 assertions)
• [SUITE] Module 8: Notification Pattern & SpecificationResult... ✅ PASS (151 assertions)
• [SUITE] Module 9: Facade Spec, Fluent Chaining & DSL... ✅ PASS (204 assertions)
• [SUITE] Module 10: Java Parity, Telemetry & Remainder... ✅ PASS (65 assertions)
• [SUITE] Module 11: Dynamic Rule Engine & Document Matrix... ✅ PASS (859 assertions)
• [SUITE] Module 12: SQL Query Visitor & Multi-DBMS Dialects... ✅ PASS (536 assertions)
• [SUITE] Module 13: TCriteria Builder & Adianti Database Bridge... ✅ PASS (576 assertions)
• [SUITE] Module 14: ALinq Synergy & Fluent LINQ Collections... ✅ PASS (2279 assertions)
• [SUITE] Module 15: Declarative PHP 8.4 Attributes (#[AssertSpec])... ✅ PASS (221 assertions)
• [SUITE] Module 16: Relational Rule Catalog (PdoRuleCatalog)... ✅ PASS (1252 assertions)
• [SUITE] Module 17: Specification-Driven State Machine... ✅ PASS (618 assertions)
• [SUITE] Module 18: State Machine Runner & Persistence... ✅ PASS (700 assertions)
• [SUITE] Module 19: Use Case & Shared Result (UseCaseResult)... ✅ PASS (408 assertions)
• [SUITE] Module 20: Specification Simplification (simplify)... ✅ PASS (116 assertions)
• [SUITE] Module 21: Concurrent Data Resolution (DataResolver)... ✅ PASS (1004 assertions)
• [SUITE] Module 22: Repository Over PDO (PdoRepository)... ✅ PASS (571 assertions)
• [SUITE] Module 23: Domain Events From Specifications... ✅ PASS (331 assertions)
• [SUITE] Module 24: Domain Events From the Passage of Time... ✅ PASS (310 assertions)
• [SUITE] Module 25: PdoRepository Follow-ups (log, types, lifecycle, batch, set-based queries)... ✅ PASS (608 assertions)
• [SUITE] Module 26: PdoRepository Relationships (ManyToOne, OneToMany, ManyToMany, eager loading, composition)... ✅ PASS (355 assertions)
• [SUITE] Module 27: Declared Feature Flags With Targeting and an Audit Trail... ✅ PASS (538 assertions)
• [SUITE] Module 28: Text Comparison Without Accents (TextComparison::IgnoreCaseAndAccents)... ✅ PASS (64 assertions)
• [SUITE] Module 29: Wildcard and Regex Without Accents (wildcard()/regex() with TextComparison)... ✅ PASS (585 assertions)
• [SUITE] Module 30: Feature Flags II (validity window through the service and percentage rollout)... ✅ PASS (441 assertions)
• [SUITE] Module 31: Specification Serialization and Flag Target by a Catalog Rule... ✅ PASS (178 assertions)
• [SUITE] Module 36: Rule Engine, TCriteria and Asynchronous Data for a Real Consumer (lookup-only types, precedence, labels, accents, subqueries, pending)... ✅ PASS (4353 assertions)
• [SUITE] Module 40: State Machine for a Real Consumer (legacy codes, versioned definition over PDO, tenant)... ✅ PASS (14335 assertions)
• [SUITE] Module 41: Deadlines in Business Time (business-day and working-hours calendars, holidays, pause and parity with the SLA clock)... ✅ PASS (6061 assertions)

====================================================================
 FINAL RESULT: 34/34 SUITES PASSED (100% PASS)
 TOTAL ASSERTIONS: 38619 | DURATION: ~26s | REGRESSIONS: 0
====================================================================
```

- **Domian Parity suite:** `tests/Parity/` transcribes the Domian (Java) tests for the specification algebra, the partition DAG, the synchronizers, entities and utilities (219 PHPUnit tests, 2 106 assertions; `vendor/bin/phpunit` runs 555 tests and 100 531 assertions in total, two of them skipped without `ext-intl`). Since v2.1.0 seven of its assertions state that integer bounds are not closed (Domian's leaves are typed `Integer`, these accept any number), each with a note.
- **Soundness bench of the algebra (v2.1.0):** `tests/PhpUnit/AlgebraSoundnessTest.php` checks every promise of the algebra against the evaluation: `equals()` (same answers and annotated failures), `isGeneralizationOf()`/`isSpecialCaseOf()`, `isDisjointWith()`, `isTautology()`/`isContradiction()`, `simplify()`, the deduplication of `and()`/`or()` and the partition lookup. Its pool of over 700 specifications covers every concrete specification class of `src/` (comparison, `in`, nulls, text with the three `TextComparison` cases, date-times, value objects, collections and entities, method calls, the wrappers of `a()`, user subclasses with predicates of their own, annotated variants) and composites generated with a fixed seed, tried pair by pair against about 80 boundary candidates (the same value in distinct instances, the same instant in other zones and classes, `'1'`/`1`/`true`, `null`, fractions, accents, entities of sibling types). A guard fails the suite when a concrete specification class is missing from the pool. The fixed part runs in the default suite in about 10 seconds; `ASPEC_SOUNDNESS_EXTENDED=1` widens it to about 3 million pairs and `ASPEC_SOUNDNESS_DUMP=<file>` writes the violations as JSON lines. `AlgebraSoundnessCasesTest` keeps a reproduction of each defect it found, and `DateSqlParityTest` compares memory and SQL for the date-time leaves. Since v2.4.0 the bench also checks the serialization (Module 31): every serializable tree of the pool (505 of 746 without `ext-intl`, 518 of 756 with it, in 2.5.0) comes back `equals()` in both directions, with a canonical text and the same answers, and translates to the same SQL (ANSI, PostgreSQL, MySQL) and `TCriteria`; every other tree is refused for a reason of the contract (callbacks, external data, flags, machine guards, subqueries, user leaves without the contract). Since v2.5.0 the pool holds labelled targets and the subqueries of `inSubquery()`/`existsIn()` (an evaluation error on every candidate), and labelling any specification of the pool keeps its answers, its failures (reported against the label), its SQL and its `TCriteria`. `SpecificationCodecTest` covers the format of every node, the values, the limits and about 46 malformed documents.
- **Public API Documentation:** 1,200+ methods documented via structured corporate PHPDoc blocks.
- **Java (Domian) Traceability:** conceptual and architectural parity with the upstream framework (public API and class names follow Domian; see the provenance table in [NOTICE.md](https://github.com/antevemus-it/Antevemus.ASpecification/blob/main/NOTICE.md)).
- **Modular Factory Architecture:** `SpecificationFactory` decomposed into 8 domain-specialized traits.
- **ALinq Synergy:** Native integration with [`Antevemus.AlinqCollection`](https://github.com/antevemus-it/Antevemus.AlinqCollection): the `ALinqSpecificationVisitor` compiler, the shared dot-notation accessor (`antevemus/adeep-property-access`) and the `InMemoryRepository` bridge methods.
- **Multi-DBMS Compatibility:** 15 drivers (10 SQL dialects) certified with proper identifier quoting and bound prepared statements. `PdoRepository` runs its contract battery in SQLite in the suite and, through `tests/Fixtures/pdo_repository/run_portability.sh`, against PostgreSQL 17 and MySQL 8.4 in disposable containers; the same script runs the feature flag store (Module 27), the date-time parity, the accent-insensitive batteries (Modules 28 and 29, in PHP 8.2 and 8.4 containers with `ext-intl`) the feature flags II with the migration from 2.1.0 (Module 30), the serialized rule bodies with the migration from 2.3.0 (Module 31) and, since v2.5.0, the rule precedence, the subquery battery and the accent battery of `toCriteria()` executed through the real Adianti 8.1 and 8.7 (Module 36) and, since v2.7.0, the versioned definitions with drafts, tenants and the migration from the 2.6 DDL of the state machine (Module 40) against PostgreSQL 17, MySQL 8.4 and MariaDB 11.4.
- **Adianti Database Bridge:** Full translation to `TCriteria`/`TFilter` with De Morgan logic inversion and strict precedence.
