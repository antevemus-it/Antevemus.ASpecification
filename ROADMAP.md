# Product Roadmap & Future Milestones — Antevemus ASpecification

This document outlines the strategic engineering roadmap and upcoming enhancements for the **Antevemus ASpecification** framework.

---

## 🎯 Short-Term Milestones (v1.1.x)

### 1. English PHP DocBlock Internationalization 🌐
- **Description:** Complete translation and standardization of all class and method DocBlocks across the codebase (`src/` and `src/Contracts/`) into idiomatic technical English.
- **Goal:** Ensure full alignment with international PHP-FIG standards (PSR-5 / PSR-19) and seamless developer onboarding for global contributors and static analysis tools (PHPStan, Psalm).
- **Target Release:** v1.1.0

### 2. Native PHP 8.4 Declarative Attributes (`#[AssertSpec]`, `#[ValidateRule]`) 🏷️
- **Description:** Implement declarative attributes to annotate class properties, DTOs, and entity fields directly with specifications.
- **Goal:** Enable zero-boilerplate validation pipelines in REST controllers, form requests, and event handlers using native PHP 8.4 reflection.
- **Example Usage:**
  ```php
  class CreateCustomerRequest {
      #[AssertSpec(CustomerMustBeAdultSpec::class, code: 'CLI_001')]
      public int $age;
  }
  ```
- **Target Release:** v1.2.0

---

## 🚀 Medium-Term Milestones (v1.3.x - v1.5.x)

### 3. Distributed Specification Cache (PSR-6 / PSR-16 / Redis) ⚡
- **Description:** Add native distributed caching adapters for partitioned DAG repositories and dynamic catalog rule sets.
- **Goal:** Enable multi-node high-throughput deployments with distributed cache invalidation across microservices and cluster nodes.
- **Target Release:** v1.3.0

### 4. GraphQL AST & OpenAPI 3.1 Query Compilers 🔍
- **Description:** Provide bidirectional AST compilers translating GraphQL query filters and OpenAPI 3.1 query parameter syntax directly into pure domain `ISpecification` trees.
- **Goal:** Unify frontend querying capabilities with domain-layer business validation and database query projection.
- **Target Release:** v1.4.0

### 5. Doctrine ORM & Laravel Eloquent Query Visitors 🌉
- **Description:** Build dedicated GoF Visitor compilers for `Doctrine\ORM\QueryBuilder` and Laravel's `Illuminate\Database\Eloquent\Builder`.
- **Goal:** Expand our query-level SQL translation capabilities (currently supporting 12 SQL dialects and Adianti TCriteria) to the two most popular ORM ecosystems in the PHP world.
- **Target Release:** v1.5.0

---

## 🔮 Long-Term Vision (v2.0+)

### 6. Reactive Domain Event Sourcing Triggers 📡
- **Description:** Reactive domain event emitter that triggers Domain Events whenever an entity transitions into or out of satisfying critical domain specifications.
- **Goal:** Seamless integration with Event Sourcing, Outbox Pattern, and CQRS architectures.

### 7. AI-Assisted Specification Synthesizer 🤖
- **Description:** Rule synthesis assistant that generates optimized, non-contradictory specification trees and Venn set subsumption models from domain stories or plain text business requirements.

---

## 🤝 Community & Contributions
We welcome suggestions, feedback, and pull requests! If you would like to propose a feature or contribute to any item on this roadmap, please open an issue or discussion on [GitHub Issues](https://github.com/antevemus-it/Antevemus.ASpecification/issues).
