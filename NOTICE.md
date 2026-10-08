Antevemus ASpecification
Copyright (c) 2026 Antevemus Soluções Inovadoras em TI Ltda.
License: MIT (SPDX: MIT) — see LICENSE.

Website: https://antevemus.com.br
Email: contato@antevemus.com.br

-------------------------------------------------------------------------------
1. SCOPE & PURPOSE
-------------------------------------------------------------------------------

Antevemus ASpecification is an enterprise framework for PHP 8.2+ implementing
the Specification design pattern grounded in Domain-Driven Design (DDD). It provides
zero-exception rule evaluation (Notification Pattern), multi-DBMS AST-to-SQL
compilation, an Adianti Framework TCriteria/TFilter bridge, native synergy with
ALinq collections, dot-notation deep property resolution, Directed Acyclic Graph
(DAG) partitioned repositories, and advanced multi-process IPC concurrency.

-------------------------------------------------------------------------------
2. UPSTREAM FRAMEWORK — DOMIAN (JAVA)
-------------------------------------------------------------------------------

This product is an independent, unofficial PHP 8.2+ reimplementation of the
architecture, public API and semantics of the framework:

    Domian (Domain-Driven Design for Java)
    Website: https://domian.sourceforge.net/
    Original Authors:
      - Eirik Torske (Project Administrator, Developer)
      - Bjørn Nordlund (Contributor)
    Roles as declared on the project site (https://domian.sourceforge.net/team-list.html).
    Copyright notice as it appears in the Domian sources (trunk r1209, 0.5.1-SNAPSHOT;
    last release 0.5, 2010-04-23): "Copyright 2006-2010 the original author or authors."
    Developers declared in the project pom.xml: Eirik Torske, Bjørn Nordlund.

Domian was released under the Apache License, Version 2.0.
THIS LIBRARY DOES NOT CONTAIN OR REDISTRIBUTE JAVA SOURCE CODE FROM DOMIAN.

This library is not affiliated with, maintained by or endorsed by the Domian
authors. It is not a clean-room rewrite: it was written with Domian's public
sources at hand. The PHP code is original; the design, class names, public API
and algorithms of the parts listed below follow Domian, which makes those parts
a derivative work of Domian under the Apache License, Version 2.0, distributed
here under the MIT license as Section 4 of that License allows.

Provenance, at package level (PHP namespace <- Domian package, net.sourceforge.domian.*):

    Contracts\ISpecification, ILeafSpecification, ICompositeSpecification,
      IValueBoundSpecification; Contracts\Factory\*; Factory\*   <- api.specification, api.factory,
                                                                     core.specification (SpecificationFactory)
    AbstractSpecification, AbstractCompositeSpecification, Specifications\AndSpecification,
      OrSpecification, NotSpecification, Specifications\Logical\*, Specifications\Comparison\*
      (except LooseEqualSpecification, TypeCompatibility), Specifications\String\DateString,
      EnumNameString, EqualIgnoreCaseString, Wildcard*, RegexSpecification,
      Specifications\Collection\CollectionSpecification                  <- core.specification
    Specifications\SpecificationPredicate                                   <- predicate
    Contracts\Helpers\*, Helpers\InstrumentationUtils, StopWatch,
      SpecificationHelper                                                   <- api.util, api/core SpecificationUtils
    Contracts\Repositories\* (except Serialization), Repositories\AbstractRepository,
      PartitionRepository, InMemoryRepository, NullRepository, UnsupportedRepository,
      NotImplementedRepository, EntityPersistenceMetaData, PersistentEntity    <- api.repository, core.repository
    Repositories\File\SingleFileRepository, FilePerEntityRepository,
      InMemoryAndFileRepository (XML/XStream replaced by JSON/PHP serializers) <- xstream-repository
    Contracts\Entities\*, Entities\*                                         <- api.entity, core.entity
    Contracts\Concurrent\ISynchronizer; Concurrent\SemaphoreSynchronizer
      (algorithm only; see its DocBlock)                                    <- core.util.concurrent.locks

    Original to this library (no Domian counterpart): the Spec facade and the DSL
    functions; Results (Notification Pattern); Attributes; Engine (dynamic rule
    catalog); Sql (query visitor and dialects); Criteria (Adianti bridge); Linq
    (ALinq bridge); Concurrent\AbstractSynchronizer, FileLockSynchronizer and
    NullSynchronizer; Repositories\Serialization\*, File\FileNameSanitizer and
    FileLockTrait and the typed *PartitionRepository variants; Specifications\
    PropertySpecification, PredicateSpecification, SubsumptionAndEqualityTrait,
    the affix/LIKE leaves (StartsWith, EndsWith, Contains, LiteralPattern),
    LooseEqualSpecification, TypeCompatibility, AllEntities and UniqueEntity
    collection specifications; Helpers\PropertyAccessor; Sql\LikePattern.

    Three DocBlocks (Contracts\ILeafSpecification, ICompositeSpecification and
    IValueBoundSpecification) reuse the Domian javadoc sentence "Part of the
    Evans/Fowler Specifications pattern" and say so.

In strict compliance with Section 4 of the Apache License, Version 2.0, original
authorship attributions and notices are fully preserved. See the full license text in
the THIRD_PARTY_NOTICES.md file.

-------------------------------------------------------------------------------
3. ADIANTI FRAMEWORK — OPTIONAL COMPATIBILITY ACKNOWLEDGMENT
-------------------------------------------------------------------------------

The Criteria module (Module 13) of this library provides an optional builder and
adapter for the Adianti Framework (TCriteria and TFilter).

    This feature is designed to interoperate with the Adianti Framework,
    copyright (c) 2006 Adianti Solutions Ltd., and is used under the terms
    of the Adianti license accompanying your installation.

Adianti is a trademark of Adianti Solutions Ltd.
THIS LIBRARY DOES NOT CONTAIN AND DOES NOT REDISTRIBUTE ADIANTI SOFTWARE. It operates
on top of the user's licensed Adianti installation obtained directly from Adianti
Solutions Ltd.

-------------------------------------------------------------------------------
4. THIRD-PARTY COMPONENTS & LICENSES
-------------------------------------------------------------------------------

See the THIRD_PARTY_NOTICES.md file for the complete list and full texts of
applicable third-party licenses.
