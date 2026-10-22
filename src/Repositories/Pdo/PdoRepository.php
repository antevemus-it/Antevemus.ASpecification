<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Pdo;

use Antevemus\ASpecification\Contracts\Concurrent\ISynchronizer;
use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Repositories\IPartitionRepository;
use Antevemus\ASpecification\Contracts\Repositories\IRepository;
use Antevemus\ASpecification\Contracts\Sql\ISqlDialect;
use Antevemus\ASpecification\Persistence\IQueryLogger;
use Antevemus\ASpecification\Persistence\ITransactionManager;
use Antevemus\ASpecification\Persistence\PdoTransactionManager;
use Antevemus\ASpecification\Repositories\AbstractRepository;
use Antevemus\ASpecification\Repositories\Pdo\Exceptions\EntityMappingException;
use Antevemus\ASpecification\Repositories\Pdo\Exceptions\PartitioningNotSupportedException;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\ColumnMapping;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\EntityMapping;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\RelationMapping;
use Antevemus\ASpecification\Repositories\Pdo\Traits\PdoConfiguration;
use Antevemus\ASpecification\Repositories\Pdo\Traits\PdoLifecycleHooks;
use Antevemus\ASpecification\Repositories\Pdo\Traits\PdoQueryKernel;
use Antevemus\ASpecification\Repositories\Pdo\Traits\PdoReadOperations;
use Antevemus\ASpecification\Repositories\Pdo\Traits\PdoRelationCascade;
use Antevemus\ASpecification\Repositories\Pdo\Traits\PdoRelationLoading;
use Antevemus\ASpecification\Repositories\Pdo\Traits\PdoRelationRegistry;
use Antevemus\ASpecification\Repositories\Pdo\Traits\PdoRemoveOperations;
use Antevemus\ASpecification\Repositories\Pdo\Traits\PdoSetOperations;
use Antevemus\ASpecification\Repositories\Pdo\Traits\PdoStatementKernel;
use Antevemus\ASpecification\Repositories\Pdo\Traits\PdoWriteOperations;
use Antevemus\ASpecification\Sql\Dialects\SqlDialectFactory;
use Antevemus\ASpecification\Sql\SqlDialect;
use Closure;
use DateTimeInterface;
use PDO;

/**
 * PdoRepository - IRepository over a Relational Table through PDO
 *
 * The Domian HibernateRepository of the library (roadmap milestone 21, forward 028): a query is a
 * specification compiled to SQL by the SqlQueryVisitor (any of its dialects), a write is a prepared
 * INSERT/UPDATE/DELETE, a read streams the cursor. The entity is mapped to the table by its author
 * (EntityMapping, by attributes or explicitly).
 *
 * Since 1.13.0 (forward 031), all optional; without them nothing changes (D8): the SQL log, the
 * booleans and the time zones (PdoConfiguration), the lifecycle and the batches (PdoWriteOperations),
 * the soft delete (PdoRemoveOperations, PdoConfiguration), the hooks (PdoLifecycleHooks), the set-based
 * reads and writes (PdoSetOperations) and the relationships (PdoRelationRegistry, PdoRelationLoading,
 * PdoRelationCascade).
 *
 * Since 2.11.0 the class holds its state (the constants and the private properties), its construction
 * (__construct(), forEntity(), applyMapping()), makePartition() and hydrate(); its behaviour lives in
 * eleven traits of Repositories\Pdo\Traits, each documented where it is implemented, with the methods,
 * signatures and bodies of 2.10.0:
 * - PdoStatementKernel: quoting, statement execution and driver errors, IN lists, identifiers, clock
 * - PdoQueryKernel: specification compilation, soft delete read scope, the streamed SELECT
 * - PdoConfiguration: the with*() options, the accessors, the withTrashed()/onlyTrashed() views
 * - PdoLifecycleHooks: beforePut(), afterPut(), beforeRemove(), afterRemove()
 * - PdoReadOperations: the reads of the contract, findPage(), findById(), contains()
 * - PdoSetOperations: select(), aggregate(), toSubquery(), updateWhere()
 * - PdoRelationRegistry: withRelated(), with(), isLoaded(), markLoaded(), markUnloaded()
 * - PdoRelationLoading: the batched loads of a with() view, whereRelation()
 * - PdoRelationCascade: references, compositions and associations written with their owner
 * - PdoWriteOperations: put(), putAll(), update(), insertAll()
 * - PdoRemoveOperations: remove(), forceRemove(), removeAllEntitiesSpecifiedBy()
 * The traits read the private properties of this class and call one another; a trait's private
 * methods called on another repository of the class ($child->stream(), ...) work because PHP's
 * private is per class. Collaborators: SqlDialectFactory (the dialect), PdoSpecificationCompiler (the
 * WHERE clauses), PdoValueConverter (the column values), PdoDriverLimits (parameters per statement,
 * multi-row INSERT), the ITransactionManager (PdoTransactionManager over the same connection by
 * default) and the EntityMapping.
 *
 * Extension point: hydrate() is protected; a subclass may override it (the entity built from a
 * fetched row).
 *
 * Out of v1 (D9): joins (relations load by batched IN, D7), DDL generation, second-level cache, partitions
 * (isNativelyPartitioned() is false; makePartition() throws PartitioningNotSupportedException).
 *
 * @template T of IEntity
 * @extends AbstractRepository<T>
 * @version    2.11.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\Pdo
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class PdoRepository extends AbstractRepository
{
    use PdoStatementKernel;
    use PdoQueryKernel;
    use PdoConfiguration;
    use PdoLifecycleHooks;
    use PdoReadOperations;
    use PdoSetOperations;
    use PdoRelationRegistry;
    use PdoRelationLoading;
    use PdoRelationCascade;
    use PdoWriteOperations;
    use PdoRemoveOperations;

    private const DELETE_CHUNK = 500;

    /** Default number of rows per batch of insertAll()/putAll() (since 1.13.0). */
    public const DEFAULT_BATCH_SIZE = 1000;

    private const SCOPE_LIVE = 'live';

    private const SCOPE_WITH_TRASHED = 'with';

    private const SCOPE_ONLY_TRASHED = 'only';

    private readonly ISqlDialect $dialect;

    private readonly string $family;

    private readonly ITransactionManager $transactions;

    private readonly PdoValueConverter $converter;

    /** The mapping given to the constructor (the effective one adds the repository's zone). */
    private readonly EntityMapping $declaredMapping;

    /** The mapping in use: the declared one with the repository's default zone applied. */
    private EntityMapping $mapping;

    /** @var list<ColumnMapping> Mapped columns in SELECT order */
    private array $columnList;

    private readonly string $tableSql;

    private string $selectSql;

    private ColumnMapping $idColumn;

    /** Soft delete column (mapped or not), or null. */
    private ?ColumnMapping $softDelete = null;

    private bool $inMemoryFallback = false;

    private ?IQueryLogger $logger = null;

    private bool $logParameters = false;

    private ?string $timezone = null;

    /** @var (Closure(): DateTimeInterface)|null */
    private ?Closure $clock = null;

    private int $batchSize = self::DEFAULT_BATCH_SIZE;

    /** @var array{beforePut: list<Closure>, afterPut: list<Closure>, beforeRemove: list<Closure>, afterRemove: list<Closure>} */
    private array $hooks = ['beforePut' => [], 'afterPut' => [], 'beforeRemove' => [], 'afterRemove' => []];

    private string $scope = self::SCOPE_LIVE;

    /** Limits of the driver: parameters per statement, multi-row INSERT (since 2.11.0) */
    private readonly PdoDriverLimits $limits;

    /** @var array<class-string, array{reader: PdoRepository, writer: IRepository}> Related repositories, by entity class */
    private array $related = [];

    /** @var array<string, array<string, mixed>> Relations loaded by the reads of this view, as a tree */
    private array $eager = [];

    /** @var list<RelationMapping> Cascaded collections of the mapping */
    private array $compositions = [];

    /** @var list<RelationMapping> References of the mapping */
    private array $references = [];

    /** @var list<RelationMapping> Associations (ManyToMany) of the mapping (since 1.14.0) */
    private array $associations = [];

    private bool $hasRelations = false;

    /**
     * @param PDO $pdo Connection to the database holding the table (its attributes are left untouched)
     * @param EntityMapping $mapping Complete mapping (table and identifier)
     * @param ITransactionManager|null $transactions Transaction boundary of the writes; default: a
     *                                               PdoTransactionManager over the same connection
     * @param ISqlDialect|SqlDialect|string|null $dialect SQL dialect; default: deduced from the PDO driver
     * @param ISynchronizer|null $synchronizer In-process synchronizer (NullSynchronizer when null)
     * @throws EntityMappingException When the mapping has no table or no identifier
     */
    public function __construct(
        private readonly PDO $pdo,
        EntityMapping $mapping,
        ?ITransactionManager $transactions = null,
        ISqlDialect|SqlDialect|string|null $dialect = null,
        ?ISynchronizer $synchronizer = null
    ) {
        $mapping->assertComplete();

        $this->dialect = SqlDialectFactory::create(
            $dialect ?? SqlDialect::fromDriver((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME))
        );
        $this->family = $this->dialect->getFamily();
        $this->transactions = $transactions ?? new PdoTransactionManager($pdo);
        $this->converter = new PdoValueConverter($this->family);
        $this->limits = new PdoDriverLimits($this->family, $pdo);
        $this->declaredMapping = $mapping;
        $this->tableSql = $this->dialect->escapeIdentifier((string) $mapping->getTable());
        $this->applyMapping();

        if ($synchronizer !== null) {
            $this->setSynchronizer($synchronizer);
        }
    }

    /**
     * Repository of an entity class mapped with attributes (EntityMapping::fromAttributes()).
     *
     * @param PDO $pdo Connection
     * @param class-string<IEntity> $entityClass Entity class carrying #[Table], #[Id] and #[Column]
     * @param ITransactionManager|null $transactions Transaction boundary (default: over the same connection)
     * @param ISqlDialect|SqlDialect|string|null $dialect SQL dialect (default: from the driver)
     * @return static
     */
    public static function forEntity(
        PDO $pdo,
        string $entityClass,
        ?ITransactionManager $transactions = null,
        ISqlDialect|SqlDialect|string|null $dialect = null
    ): static {
        return new static($pdo, EntityMapping::fromAttributes($entityClass), $transactions, $dialect);
    }

    /**
     * {@inheritdoc}
     *
     * @throws PartitioningNotSupportedException Always: a table is partitioned by the database (D9)
     */
    public function makePartition(?ISpecification $specification = null): IPartitionRepository
    {
        throw new PartitioningNotSupportedException(static::class, (string) $this->mapping->getTable());
    }

    /**
     * Builds an entity from a fetched row (columns in mapping order) without calling its constructor
     * (D3). An AbstractEntity whose creation time is not mapped gets the hydration time, as an entity
     * built in memory gets its construction time.
     *
     * @param list<mixed> $row Column values in the order of the mapping
     * @return T
     */
    protected function hydrate(array $row): IEntity
    {
        $entity = $this->mapping->instantiate();
        foreach ($this->columnList as $i => $column) {
            $this->mapping->writeValue($entity, $column->property, $this->converter->fromDatabase($column, $row[$i] ?? null));
        }
        if ($this->hasRelations) {
            $this->mapping->initializeRelations($entity);
        }
        EntityMapping::initializeCreationTime($entity);

        return $entity;
    }

    /**
     * Builds the mapping in use (the declared one with the repository's zone) and what derives from it.
     */
    private function applyMapping(): void
    {
        $mapping = $this->declaredMapping;
        if ($this->timezone !== null && $mapping->getTimezone() === null) {
            $mapping = $mapping->timezone($this->timezone);
        }
        $this->mapping = $mapping;
        $this->columnList = array_values($mapping->getColumns());
        $this->idColumn = $mapping->getId();
        $this->softDelete = $mapping->getSoftDelete();
        $relations = $mapping->getRelations();
        $this->hasRelations = $relations !== [];
        $this->compositions = array_values(array_filter($relations, static fn(RelationMapping $r): bool => $r->isComposition()));
        $this->references = array_values(array_filter($relations, static fn(RelationMapping $r): bool => $r->isManyToOne()));
        $this->associations = array_values(array_filter($relations, static fn(RelationMapping $r): bool => $r->isManyToMany()));
        $this->selectSql = 'SELECT ' . implode(', ', array_map(
            fn(ColumnMapping $c): string => $this->dialect->escapeIdentifier($c->column),
            $this->columnList
        )) . ' FROM ' . $this->tableSql;
    }
}
