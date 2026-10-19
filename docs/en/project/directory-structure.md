# 🏗️ Directory Structure

```
src/
├── Attributes/                # PHP 8 Declarative Attributes Engine (Module 15)
│   ├── AssertSpec.php        # Specification reference attribute
│   ├── ValidateRule.php      # Inline rule attribute
│   ├── AttributeValidator.php # High-performance reflection evaluator
│   ├── Specifiable.php       # Opt-in: non-public property reachable by name (v1.6.1)
│   └── Exceptions/           # AttributeValidationException
├── Contracts/                 # Segregated formal interfaces (ISP); ISerializableSpecification (v2.4.0)
│   ├── Entities/             # IEntity, ITransientEntity
│   ├── Factory/              # ITypeSpecificationFactory, IComparison..., ILogical...
│   ├── Repositories/         # IRepository, IPartitionRepository, IPersistent...
│   ├── Concurrent/           # ISynchronizer
│   ├── Helpers/              # IStopWatch, IInstrumentationUtils
│   ├── Engine/               # IRuleDefinition, IDocumentRuleDefinition, IRuleCatalog... (findRuleByCode(), getSpecJson(): v2.4.0); ILookupOnlyRuleTypes, IRuleTypeCatalog (v2.5.0)
│   └── Sql/                  # ISqlDialect, IFieldMapper, ISqlWhereClause, ISqlPagination, ISqlAccentInsensitiveLike (v2.1.0), ISqlRegexRewriter (v2.3.0)
├── Entities/                  # Base entities, UUIDs, and random ID generators
├── Specifications/            # Concrete rule implementations
│   ├── SpecificationSimplifier.php # simplify(): an equivalent, smaller tree (v1.9.0)
│   ├── LabelledSpecification.php # labelled(): a human label for the target of a failure (v2.5.0)
│   ├── Subquery/             # InSubquerySpecification, ExistsSubquerySpecification: Spec::inSubquery()/existsIn(), evaluated by the database (v2.5.0)
│   ├── Comparison/           # Equal, GreaterThan, LessThan, RelationalOperator
│   ├── Logical/              # AlwaysTrue, AlwaysFalse, JointDenial (NOR), DefaultValue
│   ├── String/               # Regex, Wildcard, DateString, EnumName; TextComparison, EqualIgnoreCaseAndAccents (v2.1.0); Regex and Wildcard take a TextComparison (v2.3.0)
│   ├── Collection/           # CollectionSpecification, AllEntities, Unique
│   └── Reflection/           # MethodParameterizedSpecification (declarative method call; field access is where())
├── Serialization/             # Specification serialization (Module 31, v2.4.0): SpecificationCodec (format, registry of short names, limits)
│   └── Exceptions/           # SpecificationCodecException, NonSerializableSpecificationException
├── Repositories/              # Concrete repositories and DAG partitioning
│   ├── File/                 # SingleFileRepository, FilePerEntityRepository
│   ├── Pdo/                  # PdoRepository; Mapping/ (Table, Column, Id, Version, EntityMapping, IdStrategy, ColumnType) (Module 22, v1.11.0)
│   │                         # v1.13.0: PdoAggregateQuery, InQuerySpecification; Mapping/ CreatedAt, UpdatedAt, SoftDelete, BooleanStorage, ManyToOne, OneToMany, RelationMapping (Modules 25 and 26)
│   │                         # v1.14.0: JoinTableHop; Mapping/ ManyToMany; Exceptions/ DataIntegrityException (Modules 25 and 26)
│   └── Serialization/        # JsonEntitySerializer (the PHP-native serializer was removed in v2.0.0)
├── Concurrent/                # SemaphoreSynchronizer, FileLockSynchronizer
├── Engine/                    # DynamicSpecificationEngine, RuleEngineVerdict, RuleCompilationWarning, PdoRuleCatalog, Builders; RulePrecedence (v2.5.0)
│   ├── Exceptions/           # RuleEngineException, MissingRuleHandlerException, LegacyVocabularyException (v2.0.0)
│   ├── RuleAction.php        # Enum: BLOCK, WARN, LOG
│   └── DocumentRequirementMode.php # Enum: ALL, ANY, ONE_OF_SET
├── Sql/                       # SQL Query Visitor & Multi-DBMS Engine (Module 12)
│   ├── Dialects/             # Abstract, Ansi, PostgreSql, MySql, SqlServer, Oracle, Firebird, Sqlite
│   ├── Exceptions/           # SqlVisitorException, NonTranslatableSpecificationException...
│   ├── SqlDialect.php        # Enum of all drivers: sqlsrv, mssql, oracle, oci, mysql, etc.
│   ├── SqlQueryVisitor.php   # GoF Visitor compiling pure AST into parameterized SQL
│   ├── SqlWhereClause.php    # Safe WHERE clause with bound PDO parameters
│   └── FieldMapper.php       # Semantic property-to-column mapper
├── Criteria/                  # TCriteria Builder & Adianti Database Bridge (Module 13)
│   ├── Exceptions/           # CriteriaBuilderException, NonTranslatableCriteriaException
│   ├── CriteriaSpecificationVisitor.php # GoF Visitor compiling AST to TCriteria/TFilter
│   ├── TAccentInsensitiveFilter.php, TSubqueryFilter.php, TConstantFilter.php # TFilters that own their rendering: accents for a declared database, subqueries, `1 = 1`/`1 = 0` never bound (v2.5.0)
│   └── TCriteriaBuilder.php  # Fluent builder with pagination, sorting, and field mapping; withAccentFolding() (v2.5.0)
├── Linq/                      # ALinq Synergy & Fluent Collections (Module 14)
│   ├── Exceptions/           # NonTranslatableLinqException (v1.10.0)
│   ├── ALinqBridge.php       # Bridge between repositories and ALinq collections
│   └── ALinqSpecificationVisitor.php # AST compiler for short-circuit functional predicates
├── Factory/                   # Unified SpecificationFactory (~140 methods)
│   └── Traits/               # 8 Modular Traits (Type, Comparison, Logical, Special, String, Date, Collection, Wrapper)
├── Results/                   # SpecificationResult, SpecificationFailure, FailureSeverity, IOutcome
├── StateMachine/              # Spec-driven state machine (Modules 17 and 18): builder, definition, validator, pure core
│   ├── Persistence/          # Ports, MachinePersistence, InMemory/ and Pdo/ stores (resources/sql/state_machine.ansi.sql)
│   └── Runner/               # MachineRunner, EffectDispatcher, EffectHandlerRegistry, migrations
├── UseCase/                   # IUseCase, UseCaseRunner, UseCaseResult, UseCaseStatus, middlewares (Module 19)
├── Persistence/               # ITransactionManager, PdoTransactionManager: the transaction port shared by the repository, outboxes and machine stores (v1.11.0); IQueryLogger, InMemoryQueryLogger (v1.13.0)
├── DomainEvents/              # Domain events from specifications (Module 23, v1.11.0) and from the passage of time (Module 24, v1.12.0)
│   ├── Outbox/               # IDomainEventOutbox, InMemory and Pdo outboxes (resources/sql/domain_events.ansi.sql)
│   ├── State/                # ITriggerStateStore, InMemory and Pdo stores of the last known state of each trigger (v1.12.0)
│   ├── SpecificationTrigger.php # A specification bound to an enter and an exit event (timed(): built from "now"); TriggerSet, TriggerEvaluator
│   ├── TriggeringRepository.php # IRepository decorator that publishes the crossings of every write
│   ├── TriggerSweeper.php    # sweep() for the crossings caused by time alone, baseline(); SweepReport (v1.12.0)
│   ├── DomainEventDispatcher.php # Delivery after the commit; DomainEventHandlerRegistry
│   └── MachineEventBridge.php # A domain event sent to a MachineRunner
├── Resolution/                # Concurrent data resolution (Module 21, v1.10.0)
│   ├── Exceptions/           # DataResolutionException, DataFetchException, DataSourceNotFoundException
│   ├── ExternalDataSpecification.php # Spec::external(): the leaf that declares external data
│   ├── DataResolver.php      # resolve()/evaluate(); ResolutionPolicy concurrent()/tiered()/sequential()
│   ├── HttpDataSource.php    # URL template with encoded parameters, fetched through curl_multi
│   ├── CallableDataSource.php # Any synchronous callable; DataSourceRegistry, IDataCache, InMemoryDataCache
│   └── AsyncDataSource.php   # Submit once, poll later (v2.5.0): AsyncPoll, IAsyncTicketStore, InMemory and Callable stores; Exceptions/DataPendingException
├── FeatureFlags/              # Declared feature flags (Module 27, v2.1.0): FeatureFlags, FeatureFlagRegistry, FeatureFlagDefinition, FeatureEnabledSpecification, FeatureFlagReason; FeatureFlagChangeKind (v2.3.0)
│   ├── Exceptions/           # FeatureFlagException, FeatureFlagStoreException, StaleFeatureFlagException, UndeclaredFeatureFlagException...
│   └── PdoFeatureFlagStore.php # IFeatureFlagStore over the tables of resources/sql/feature_flags.ansi.sql (or existing ones); InMemoryFeatureFlagStore
├── Helpers/                   # PropertyAccessor (deprecated facade of antevemus/adeep-property-access, v2.0.0), StopWatch, InstrumentationUtils
├── DSL/                       # Global helper functions for fluent domain syntax
└── Spec.php                   # Unified static facade
```
