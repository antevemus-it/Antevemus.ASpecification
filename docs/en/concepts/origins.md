# 🏛️ Origins & Theoretical Foundation

**Antevemus ASpecification** was engineered on solid software craftsmanship principles and Domain-Driven Design (DDD):

1. **The Seminal Paper**:
   Based on the classic paper [The Specifications Pattern (PDF)](http://www.martinfowler.com/apsupp/spec.pdf) by **Eric Evans and Martin Fowler (2002)**, which formalized encapsulating business predicates and domain constraints into first-class composable objects for:
   - **Object Validation**: Verify whether candidate entities satisfy domain constraints.
   - **Selection & Filtering**: Query entities from repositories without leaking SQL or persistence concerns into the domain layer.
   - **Constraint Satisfaction**: Specify preconditions required to instantiate or transition entities between domain lifecycle states.

2. **The Upstream Origin: Domian (Java)**:
   This library is an independent, unofficial PHP port and modern evolution of the [Domian (Domain-Driven Design for Java)](https://domian.sourceforge.net/index.html) framework, originally authored by **Eirik Torske** and **Bjørn Nordlund** under the **Apache License, Version 2.0**. It is not affiliated with, maintained by or endorsed by the Domian authors: the design, public API and semantics follow Domian's public sources, and the PHP code was written by Antevemus. Domian pioneered unifying Boolean algebra, Venn diagram set theory, collection-scoped specifications, Directed Acyclic Graph (DAG) partitioned repositories, and concurrent synchronization.

## 📚 References & Bibliography

- **[Domian Specification Framework](https://domian.sourceforge.net/index.html)**: Original Java framework authored by Eirik Torske.
- **[The Specifications Pattern - Eric Evans & Martin Fowler](http://www.martinfowler.com/apsupp/spec.pdf)**: Seminal paper introducing the pattern.
- **[Domain-Driven Design: Tackling Complexity in the Heart of Software](https://www.domainlanguage.com/ddd/)**: Reference work by Eric Evans on rich domain modeling.
- **[Design Patterns: Elements of Reusable Object-Oriented Software](https://en.wikipedia.org/wiki/Design_Patterns)**: GoF reference patterns (Composite, Decorator, Visitor, Factory).
