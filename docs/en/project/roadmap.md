# 🗺️ Roadmap & Next Steps

**Antevemus ASpecification** is actively maintained and continuously evolving. For the full strategic roadmap and timeline:

👉 **[View the complete ROADMAP.md](https://github.com/antevemus-it/Antevemus.ASpecification/blob/main/ROADMAP.md)**

Key highlights & upcoming roadmap:
1. **English DocBlock Internationalization** (Shipped in v1.1.0)
2. **PHP 8.4 Declarative Attributes** (`#[AssertSpec]`, `#[ValidateRule]`) (Shipped in v1.1.0)
3. **ALinq Lazy Streaming Pipeline & O(1) RAM Evaluation** (Shipped in v1.1.0)
4. **Review-Driven Hardening: Error State, Strict Typing, Rule Binding & Document Matrix** (Shipped in v1.2.0)
5. **README Promises I: Every Documented Example Runs** (Shipped in v1.3.0)
5a. **Domian Parity: Subsumption Algebra, Partition DAG, Synchronized Repositories, Entities & Utilities** (Shipped in v1.4.4)
6. **README Promises II: Inter-Process, Unicode, Lazy Sources, Relational Catalog & `IN`** (Shipped in v1.5.0)
7. **Announced-but-Unshipped Backlog I: ULID / UUID v7 Identities & Failure Severity** (Shipped in v1.5.0)
8. **PHP-Native Serializer Retirement, Step 1: Deprecation** (Shipped in v1.5.0)
9. **`MethodParameterizedSpecification`: Declarative Method Calls** (Shipped in v1.6.0)
10. **Tautology & Contradiction Detection** (Shipped in v1.6.0)
11. **Announced-but-Unshipped Backlog II: TTL, Partition DAG Index & Three SQL Dialects** (Shipped in v1.6.0)
11a. **Specification-Driven State Machine** (the reserved `RequirementState`, now expressible as a machine) (Shipped in v1.8.0)
11b. **Use Cases With a Result Shared With the State Machine** (Shipped in v1.8.0)
12. **Specification `simplify()`** (Shipped in v1.9.0)
13. **PHP-Native Serializer Retirement, Step 2: Removal & JSON-Only Envelope** (persisted per-entity metadata) (Shipped in v2.0.0)
14. **Shared Property Accessor with ALinq** (`antevemus/adeep-property-access`) (Shipped in v2.0.0)
15. **Concurrent Data Resolution for I/O-Bound Specifications** (core shipped in v1.10.0)
15b. **Fibers/Revolt Adapter for Non-HTTP Sources** (satellite package `antevemus/aspecification-async`)
16. **Distributed Specification Cache** (PSR-6 / PSR-16 / Redis) (16a shipped in v2.4.0; 16b scheduled for v2.7.0)
16a. **Specification Serialization** (a canonical JSON with short, stable names, `SpecificationCodec`, never a class taken from the input) (Shipped in v2.4.0)
16b. **Distributed Cache Adapters** (PSR-16)
17. **GraphQL AST & OpenAPI 3.1 Query Compilers**
18. **Doctrine ORM & Laravel Eloquent Query Visitors**
19. **Specification-Driven Domain Event Triggers** (Shipped in v1.11.0)
19b. **Time-Based Domain Event Triggers** (a scheduled sweeper for crossings without a write) (Shipped in v1.12.0)
20. **AI-Assisted Specification Synthesizer**
21. **PDO-Backed Repository** (the Domian `HibernateRepository`) (Shipped in v1.11.0)
21b. **`PdoRepository` Follow-ups** (lifecycle columns and soft delete, hooks, SQL log, batch insert, bulk update by specification, projections and aggregates, subqueries, `ManyToOne`/`OneToMany` relationships with eager loading in batch) (Shipped in v1.13.0)
21c. **`PdoRepository` Follow-ups II** (`ManyToMany`, marking a hand-built collection as loaded, `iterate()` with relations over unbuffered MySQL, `CHAR(1)` booleans) (Shipped in v1.14.0)
22. **Accent-Insensitive Text Comparison** (an explicit option that ignores case and accents, with the same answer in memory and in SQL) (Shipped in v2.1.0)
22b. **Accent-Insensitive Wildcards and Regular Expressions** (`wildcard()` and `regex()` with `TextComparison`; translated where a database answers as memory, refused elsewhere) (Shipped in v2.3.0)
23. **Write-Behind Hybrid Repository** (the Domian `INMEMORY_AND_FILE` mode: memory now, disk on `persist()`)
24. **Declared Feature Flags With Targeting and an Audit Trail** (Shipped in v2.1.0)
24b. **Feature Flag Target by a Catalog Rule** (`targetRuleCode`, `IRuleCatalog::findRuleByCode()`, a rule body in `spec_json`) (Shipped in v2.4.0)
24c. **Percentage Rollout of a Feature Flag** (a stable bucket per subject from `xxh3` of the code and the key, set through the audited path) (Shipped in v2.3.0)
24d. **An API for the Validity Window of a Feature Flag** (`setValidity()`, compare-and-set on the row version, a typed history row) (Shipped in v2.3.0)
25. **Optional Integer Mode for the Relational Leaves** (`asInteger()`, `Spec::int()`: integer bounds closed where it is sound)
26. **Soundness Audit of the Specification Algebra** (a brute-force bench over every specification class; the algebra never promises more than the evaluation delivers) (Shipped in v2.1.0)

26b. **Pending Decisions of the Soundness Bench** (a typed default value, `isBlankString()`/`defaultNumber()`/`defaultValueOfType()`, is "a value of that type and its default" and refuses `null`, which only the untyped `defaultValue()` accepts; `NAN` is refused by the ordered leaves as a candidate and as a bound, so `not(lessThan(v))` and `greaterThanOrEqualTo(v)` agree on every candidate they answer) (Shipped in v2.2.0)

27. **A Rule Engine for a Real Consumer** (lookup-only rule types, the most specific rule of a type, a human label for the target, Adianti as the host) (Shipped in v2.5.0)
28. **TCriteria Without Accents and With Subqueries** (`withAccentFolding()`, `inSubquery()`, `existsIn()`, also in `toSql()`) (Shipped in v2.5.0)
29. **Asynchronous Data Sources** (`AsyncDataSource`: submit once, poll later, a pending datum never approved nor refused) (Shipped in v2.5.0)
