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

---

## 🎯 Short-Term Milestones (v1.2.x - v1.3.x)

### 4. Distributed Specification Cache (PSR-6 / PSR-16 / Redis) ⚡
- **Description:** Add native distributed caching adapters for partitioned DAG repositories and dynamic catalog rule sets.
- **Goal:** Enable multi-node high-throughput deployments with distributed cache invalidation across microservices and cluster nodes.
- **Target Release:** v1.2.0

### 5. GraphQL AST & OpenAPI 3.1 Query Compilers 🔍
- **Description:** Provide bidirectional AST compilers translating GraphQL query filters and OpenAPI 3.1 query parameter syntax directly into pure domain `ISpecification` trees.
- **Goal:** Unify frontend querying capabilities with domain-layer business validation and database query projection.
- **Target Release:** v1.3.0

---

## 🚀 Medium-Term Milestones (v1.4.x - v1.5.x)

### 6. Doctrine ORM & Laravel Eloquent Query Visitors 🌉
- **Description:** Build dedicated GoF Visitor compilers for `Doctrine\ORM\QueryBuilder` and Laravel's `Illuminate\Database\Eloquent\Builder`.
- **Goal:** Expand our query-level SQL translation capabilities (currently supporting 12 SQL dialects and Adianti TCriteria) to the two most popular ORM ecosystems in the PHP world.
- **Target Release:** v1.4.0

---

## 🔮 Long-Term Vision (v2.0+)

### 7. Reactive Domain Event Sourcing Triggers 📡
- **Description:** Reactive domain event emitter that triggers Domain Events whenever an entity transitions into or out of satisfying critical domain specifications.
- **Goal:** Seamless integration with Event Sourcing, Outbox Pattern, and CQRS architectures.

### 8. AI-Assisted Specification Synthesizer 🤖
- **Description:** Rule synthesis assistant that generates optimized, non-contradictory specification trees and Venn set subsumption models from domain stories or plain text business requirements.

---

## 🤝 Community & Contributions
We welcome suggestions, feedback, and pull requests! If you would like to propose a feature or contribute to any item on this roadmap, please open an issue or discussion on [GitHub Issues](https://github.com/antevemus-it/Antevemus.ASpecification/issues).
