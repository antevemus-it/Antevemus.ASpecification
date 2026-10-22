# 🏗️ Estrutura de Diretórios

```
src/
├── Attributes/                # Engine de Attributes Declarativos PHP 8 (Módulo 15)
│   ├── AssertSpec.php        # Attribute de referência a Specifications
│   ├── ValidateRule.php      # Attribute de validação inline rápida
│   ├── AttributeValidator.php # Avaliador de reflexão de alta performance
│   ├── Specifiable.php       # Opt-in: propriedade não pública alcançável por nome (v1.6.1)
│   └── Exceptions/           # AttributeValidationException
├── Contracts/                 # Interfaces formais segregadas (ISP); ISerializableSpecification (v2.4.0)
│   ├── Entities/             # IEntity, ITransientEntity
│   ├── Factory/              # ITypeSpecificationFactory, IComparison..., ILogical...
│   ├── Repositories/         # IRepository, IPartitionRepository, IPersistent...
│   ├── Concurrent/           # ISynchronizer
│   ├── Helpers/              # IStopWatch, IInstrumentationUtils
│   ├── Engine/               # IRuleDefinition, IDocumentRuleDefinition, IRuleCatalog... (findRuleByCode(), getSpecJson(): v2.4.0); ILookupOnlyRuleTypes, IRuleTypeCatalog (v2.5.0)
│   └── Sql/                  # ISqlDialect, IFieldMapper, ISqlWhereClause, ISqlPagination, ISqlAccentInsensitiveLike (v2.1.0), ISqlRegexRewriter (v2.3.0)
├── Entities/                  # Classes base abstratas de entidades, UUIDs e geradores de ID aleatórios
├── Specifications/            # Implementações concretas de regras
│   ├── SpecificationSimplifier.php # simplify(): árvore equivalente e menor (v1.9.0)
│   ├── LabelledSpecification.php # labelled(): um rótulo humano para o alvo de uma falha (v2.5.0)
│   ├── Subquery/             # InSubquerySpecification, ExistsSubquerySpecification: Spec::inSubquery()/existsIn(), avaliadas pelo banco (v2.5.0)
│   ├── Comparison/           # Equal, GreaterThan, LessThan, RelationalOperator
│   ├── Logical/              # AlwaysTrue, AlwaysFalse, JointDenial (NOR), DefaultValue
│   ├── String/               # Regex, Wildcard, DateString, EnumName; TextComparison, EqualIgnoreCaseAndAccents (v2.1.0); Regex e Wildcard recebem um TextComparison (v2.3.0)
│   ├── Collection/           # CollectionSpecification, AllEntities, Unique
│   └── Reflection/           # MethodParameterizedSpecification (chamada de método declarativa; acesso a campo é where())
├── Serialization/             # Serialização de especificações (Módulo 31, v2.4.0): SpecificationCodec (formato, registro de nomes curtos, limites)
│   └── Exceptions/           # SpecificationCodecException, NonSerializableSpecificationException
├── Repositories/              # Repositórios concretos e particionamento
│   ├── File/                 # SingleFileRepository, FilePerEntityRepository
│   ├── Pdo/                  # PdoRepository; Mapping/ (Table, Column, Id, Version, EntityMapping, IdStrategy, ColumnType) (Módulo 22, v1.11.0)
│   │                         # v1.13.0: PdoAggregateQuery, InQuerySpecification; Mapping/ CreatedAt, UpdatedAt, SoftDelete, BooleanStorage, ManyToOne, OneToMany, RelationMapping (Módulos 25 e 26)
│   │                         # v1.14.0: JoinTableHop; Mapping/ ManyToMany; Exceptions/ DataIntegrityException (Módulos 25 e 26)
│   │                         # v2.11.0: Traits/ (os onze traits do PdoRepository: comandos, consultas, configuração, hooks, leituras, operações em conjunto, relacionamentos, gravações, remoções), PdoDriverLimits
│   └── Serialization/        # JsonEntitySerializer (o serializer nativo do PHP saiu na v2.0.0)
├── Concurrent/                # SemaphoreSynchronizer, FileLockSynchronizer
├── Engine/                    # DynamicSpecificationEngine, RuleEngineVerdict, RuleCompilationWarning, PdoRuleCatalog, Builders; RulePrecedence (v2.5.0)
│   ├── Exceptions/           # RuleEngineException, MissingRuleHandlerException, LegacyVocabularyException (v2.0.0)
│   ├── RuleAction.php        # Enum: BLOCK, WARN, LOG
│   └── DocumentRequirementMode.php # Enum: ALL, ANY, ONE_OF_SET
├── Sql/                       # SQL Query Visitor & Multi-SGBD Engine (Módulo 12)
│   ├── Dialects/             # Abstract, Ansi, PostgreSql, MySql, SqlServer, Oracle, Firebird, Sqlite
│   ├── Exceptions/           # SqlVisitorException, NonTranslatableSpecificationException...
│   ├── SqlDialect.php        # Enum com todos os drivers: sqlsrv, mssql, oracle, oci, mysql, etc.
│   ├── SqlQueryVisitor.php   # Visitor GoF que compila a AST em SQL parametrizado
│   ├── SqlWhereClause.php    # Cláusula WHERE segura com bindings
│   └── FieldMapper.php       # Mapeador de propriedades para colunas físicas
├── Criteria/                  # TCriteria Builder & Adianti Database Bridge (Módulo 13)
│   ├── Exceptions/           # CriteriaBuilderException, NonTranslatableCriteriaException
│   ├── CriteriaSpecificationVisitor.php # Visitor GoF que compila a AST para TCriteria/TFilter
│   ├── TAccentInsensitiveFilter.php, TSubqueryFilter.php, TConstantFilter.php # TFilters donos da própria renderização: sem acento para um banco declarado, subqueries, `1 = 1`/`1 = 0` nunca ligados (v2.5.0)
│   └── TCriteriaBuilder.php  # Builder fluente com paginação, ordenação e mapeamento de campos; withAccentFolding() (v2.5.0)
├── Linq/                      # ALinq Synergy & Coleções Fluentes (Módulo 14)
│   ├── Exceptions/           # NonTranslatableLinqException (v1.10.0)
│   ├── ALinqBridge.php       # Ponte fluente entre repositórios e coleções ALinq
│   └── ALinqSpecificationVisitor.php # Compilador de AST para predicados funcionais LINQ
├── Factory/                   # SpecificationFactory unificada (~140 métodos)
│   └── Traits/               # 8 Traits modulares (Type, Comparison, Logical, Special, String, Date, Collection, Wrapper)
├── Results/                   # SpecificationResult, SpecificationFailure, FailureSeverity, IOutcome
├── StateMachine/              # Máquina de estados dirigida por especificações (Módulos 17 e 18): builder, definição, validador, núcleo puro
│   ├── Persistence/          # Portas, MachinePersistence, stores InMemory/ e Pdo/ (resources/sql/state_machine.ansi.sql)
│   │                         # v2.7.0: PdoMachineDefinitionRepository, rascunhos (IMachineDefinitionDrafts), tenants (ITenantScopedInstanceStore), IMachineInstanceLister (Módulo 40)
│   └── Runner/               # MachineRunner, EffectDispatcher, EffectHandlerRegistry, migrações (v2.7.0: MachineMigrations, migrateAll())
├── UseCase/                   # IUseCase, UseCaseRunner, UseCaseResult, UseCaseStatus, middlewares (Módulo 19)
├── Persistence/               # ITransactionManager, PdoTransactionManager: a porta de transação comum ao repositório, às outboxes e aos stores da máquina (v1.11.0); IQueryLogger, InMemoryQueryLogger (v1.13.0)
├── DomainEvents/              # Eventos de domínio a partir de especificações (Módulo 23, v1.11.0) e pela passagem do tempo (Módulo 24, v1.12.0)
│   ├── Outbox/               # IDomainEventOutbox, outboxes InMemory e Pdo (resources/sql/domain_events.ansi.sql)
│   ├── State/                # ITriggerStateStore, stores InMemory e Pdo do último estado conhecido de cada gatilho (v1.12.0)
│   ├── SpecificationTrigger.php # Especificação ligada a um evento de entrada e um de saída (timed(): montada a partir do "agora"); TriggerSet, TriggerEvaluator
│   ├── TriggeringRepository.php # Decorador de IRepository que publica as travessias de cada escrita
│   ├── TriggerSweeper.php    # sweep() para as travessias causadas só pelo tempo, baseline(); SweepReport (v1.12.0)
│   ├── DomainEventDispatcher.php # Entrega depois do commit; DomainEventHandlerRegistry
│   └── MachineEventBridge.php # Evento de domínio enviado a um MachineRunner
├── Resolution/                # Resolução concorrente de dados (Módulo 21, v1.10.0)
│   ├── Exceptions/           # DataResolutionException, DataFetchException, DataSourceNotFoundException
│   ├── ExternalDataSpecification.php # Spec::external(): a folha que declara o dado externo
│   ├── DataResolver.php      # resolve()/evaluate(); ResolutionPolicy concurrent()/tiered()/sequential()
│   ├── HttpDataSource.php    # Modelo de URL com parâmetros codificados, buscado por curl_multi
│   ├── CallableDataSource.php # Qualquer callable síncrono; DataSourceRegistry, IDataCache, InMemoryDataCache
│   └── AsyncDataSource.php   # Envia uma vez, consulta depois (v2.5.0): AsyncPoll, IAsyncTicketStore, stores InMemory e Callable; Exceptions/DataPendingException
├── Cache/                     # Cache PSR-16 compartilhado (Módulo 43, v2.8.0): CachedRuleCatalog, CachedRuleTypeCatalog, CachedFeatureFlagStore, CachedPartitionIndex, PdoCatalogVersion, CacheKeys; Exceptions/CacheFailureException
├── FeatureFlags/              # Feature flags declaradas (Módulo 27, v2.1.0): FeatureFlags, FeatureFlagRegistry, FeatureFlagDefinition, FeatureEnabledSpecification, FeatureFlagReason; FeatureFlagChangeKind (v2.3.0)
│   ├── Exceptions/           # FeatureFlagException, FeatureFlagStoreException, StaleFeatureFlagException, UndeclaredFeatureFlagException...
│   └── PdoFeatureFlagStore.php # IFeatureFlagStore sobre as tabelas de resources/sql/feature_flags.ansi.sql (ou existentes); InMemoryFeatureFlagStore
├── Helpers/                   # PropertyAccessor (fachada depreciada do antevemus/adeep-property-access, v2.0.0), StopWatch, InstrumentationUtils
├── DSL/                       # Funções globais de apoio para syntax fluente
└── Spec.php                   # Facade principal da biblioteca
```
