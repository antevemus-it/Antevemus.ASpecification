<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Pdo\Mapping;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Entities\AbstractEntity;
use Antevemus\ADeepPropertyAccess\PropertyAccess;
use Antevemus\ASpecification\Helpers\ReflectionUtils;
use Antevemus\ASpecification\Repositories\Pdo\Exceptions\EntityMappingException;
use Antevemus\ASpecification\Repositories\Pdo\PdoValueConverter;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use Throwable;
use UnitEnum;

/**
 * EntityMapping - Immutable Entity to Table Mapping of the PDO Repository
 *
 * Declared by the author of the entity class, either with attributes read by fromAttributes()
 * (#[Table], #[Column], #[Id], #[Version]) or with the explicit builder for classes that cannot be
 * annotated (forward 028, D2):
 *
 * <code>
 * $mapping = EntityMapping::for(Module::class)
 *     ->table('system_module')
 *     ->id('id', IdStrategy::Max)
 *     ->column('name')
 *     ->column('active', type: ColumnType::Boolean)
 *     ->version('lockVersion');
 * </code>
 *
 * Every builder call returns a new mapping (the receiver never changes); both paths produce the same
 * mapping, which describe()/equals() compare. Validation happens on each call:
 * - the class must be a concrete IEntity; every mapped property must exist in the hierarchy and not be
 *   static; a property is mapped once and a column belongs to one property
 * - table and column names must be plain SQL identifiers (`[A-Za-z_][A-Za-z0-9_]*`, the table
 *   optionally qualified by one schema): they are the only identifiers that reach the SQL text
 *   (RN-04), quoted by the dialect
 * - ColumnType::Auto is resolved from the declared type of the property (see ColumnType); a type that
 *   cannot be inferred must be given explicitly
 * - one identifier; Max needs an integer identifier, Ulid and Uuid7 a string one; one version counter,
 *   always an integer
 *
 * A mapped property is read and written by reflection even when it is private, protected or readonly
 * (a readonly property can be initialized by hydration but never changed afterwards). Reading a
 * property by name from a SPECIFICATION is another matter: it follows the rule of
 * the shared property accessor, `PropertyAccess` (a public property, a public getter, or #[Specifiable]), see findQueryColumn().
 *
 * Since 1.13.0 (forward 031), all optional:
 * - defaults of the mapping: timezone() (#[Table(timezone:)]) for the temporal columns that declare no
 *   zone, booleanAs() (#[Table(booleanAs:)]) for the Boolean columns that declare no storage (D2, D3);
 *   a default changed after the columns were mapped applies to them too
 * - lifecycle columns (D4): createdAt() (#[CreatedAt]), updatedAt() (#[UpdatedAt]) and softDelete()
 *   (#[SoftDelete]); each is a temporal column, one per role, never the identifier or the version
 * - extract() and hydrate(): the row of an entity as column => property value, and an entity built
 *   from such a row (the toArray()/fromArray() of an active record), without a database
 * - relationships (D7): manyToOne() (#[ManyToOne]), a reference whose foreign key is a mapped column of
 *   this table, and oneToMany() (#[OneToMany]), a collection whose foreign key is a property of the
 *   child, optionally cascaded (composition). A relation property is not a column: it is never in
 *   getColumns(), extract() or a specification (filter with PdoRepository::whereRelation()). Both
 *   paths give the same relations, compared by describe()/equals(). The other side is resolved by the
 *   repository; here the foreign key column of a reference must be mapped to a property
 *   (assertComplete()). isRelationLoaded() tells whether a relation of an entity object was loaded
 *
 * Since 1.14.0 (forward 032), all optional:
 * - manyToMany() (#[ManyToMany]): an association through a join table whose name and two columns come
 *   only from the mapping (validated as identifiers); a collection like oneToMany(), never a column
 * - Char booleans (D4): BooleanStorage::Char with the texts of true and false per column
 *   (column(..., trueValue:, falseValue:), #[Column]) or as defaults (booleanAs(Char, 'S', 'N'),
 *   #[Table]); 'Y'/'N' unless given
 *
 * @version    2.0.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\Pdo\Mapping
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class EntityMapping
{
    private const COLUMN_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    private const TABLE_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?$/';

    /** Texts of a Char boolean (since 1.14.0): literals of the compiled SQL, so letters and digits only. */
    private const BOOLEAN_TEXT_PATTERN = '/^[A-Za-z0-9]{1,16}$/';

    /** Spec property that always means the identifier (IEntity::getEntityId()). */
    public const ENTITY_ID_ALIAS = 'entityId';

    private ?string $table = null;

    /** @var array<string, ColumnMapping> Property => column mapping, in mapping order */
    private array $columns = [];

    private ?string $idProperty = null;

    private IdStrategy $idStrategy = IdStrategy::Auto;

    private ?string $sequence = null;

    private ?string $versionProperty = null;

    /** Default time zone of the temporal columns that declare none (since 1.13.0). */
    private ?string $defaultTimezone = null;

    /** Default storage of the Boolean columns that declare none (since 1.13.0). */
    private ?BooleanStorage $defaultBooleanStorage = null;

    /** Default texts of the Char booleans that declare none (since 1.14.0; null = 'Y'/'N'). */
    private ?string $defaultTrueValue = null;

    private ?string $defaultFalseValue = null;

    private ?string $createdAtProperty = null;

    private ?string $updatedAtProperty = null;

    /** Soft delete column: a mapped column, or one with an empty property (since 1.13.0). */
    private ?ColumnMapping $softDelete = null;

    /** @var array<string, RelationMapping> Property => relationship, in mapping order (since 1.13.0) */
    private array $relations = [];

    /** @var array<string, ReflectionProperty> Property => reflection (accessible) */
    private array $reflection = [];

    /** @var ReflectionClass<IEntity> */
    private readonly ReflectionClass $class;

    /** Instance without constructor used to ask the shared accessor what a name reaches (readableByName()). */
    private ?object $probe = null;

    /** @var array<class-string, self> Mappings read from attributes, by class */
    private static array $attributeCache = [];

    private static ?PdoValueConverter $valueReader = null;

    private static ?ReflectionProperty $timeOfCreation = null;

    /**
     * @param class-string<IEntity> $entityClass
     */
    private function __construct(private readonly string $entityClass)
    {
        $this->class = new ReflectionClass($entityClass);
    }

    ///////////////////////////////////////////////////////////////////////////
    // Construction
    ///////////////////////////////////////////////////////////////////////////

    /**
     * Starts an explicit mapping of a concrete entity class.
     *
     * @param class-string<IEntity> $entityClass
     * @throws EntityMappingException When the class does not exist, is abstract or is not an IEntity
     */
    public static function for(string $entityClass): self
    {
        if (!class_exists($entityClass)) {
            throw new EntityMappingException(sprintf('Entity class "%s" does not exist.', $entityClass));
        }
        if (!is_subclass_of($entityClass, IEntity::class)) {
            throw new EntityMappingException(sprintf('"%s" does not implement %s.', $entityClass, IEntity::class));
        }
        $class = new ReflectionClass($entityClass);
        if ($class->isAbstract() || $class->isInterface() || $class->isEnum()) {
            throw new EntityMappingException(sprintf('Entity class "%s" must be concrete to be hydrated.', $entityClass));
        }

        return new self($class->getName());
    }

    /**
     * Reads the mapping declared with #[Table], #[Column], #[Id] and #[Version] (and, since 1.13.0,
     * #[CreatedAt], #[UpdatedAt] and #[SoftDelete]) on the class and its parents (parents first; a
     * declaration on a subclass replaces the one of a parent for the same property). Cached per class.
     *
     * @param class-string<IEntity> $entityClass
     * @throws EntityMappingException When the declarations are invalid or incomplete (no table, no id)
     */
    public static function fromAttributes(string $entityClass): self
    {
        if (isset(self::$attributeCache[$entityClass])) {
            return self::$attributeCache[$entityClass];
        }

        $mapping = self::for($entityClass);

        $chain = [];
        for ($class = $mapping->class; $class !== false; $class = $class->getParentClass()) {
            array_unshift($chain, $class);
        }

        $table = null;
        foreach ($chain as $class) {
            foreach ($class->getAttributes(Table::class) as $attribute) {
                $table = $attribute->newInstance();
            }
        }
        if ($table === null) {
            throw new EntityMappingException(sprintf('%s has no #[Table] attribute in its hierarchy.', $entityClass));
        }
        $mapping = $mapping->table($table->name);
        if ($table->timezone !== null) {
            $mapping = $mapping->timezone($table->timezone);
        }
        if ($table->booleanAs !== null) {
            $mapping = $mapping->booleanAs($table->booleanAs, $table->trueValue, $table->falseValue);
        } elseif ($table->trueValue !== null || $table->falseValue !== null) {
            throw new EntityMappingException(sprintf('The #[Table] of %s gives trueValue/falseValue without booleanAs: BooleanStorage::Char.', $entityClass));
        }

        $roles = [
            Column::class => 'column',
            Id::class => 'id',
            Version::class => 'version',
            CreatedAt::class => 'createdAt',
            UpdatedAt::class => 'updatedAt',
            SoftDelete::class => 'softDelete',
        ];
        foreach ($chain as $class) {
            /** @var array<string, array<string, object>> $declared Property => role => attribute */
            $declared = [];
            /** @var SoftDelete|null $unmappedSoftDelete A class-level #[SoftDelete] without property */
            $unmappedSoftDelete = null;
            foreach ($roles as $type => $key) {
                foreach ($class->getAttributes($type) as $attribute) {
                    $instance = $attribute->newInstance();
                    if ($instance instanceof SoftDelete && $instance->property === null) {
                        $unmappedSoftDelete = $instance;
                        continue;
                    }
                    $name = substr($type, (int) strrpos($type, '\\') + 1);
                    $declared[self::classLevelProperty($class, $name, $instance->property)][$key] = $instance;
                }
            }
            foreach ($class->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() !== $class->getName()) {
                    continue;
                }
                foreach ($roles as $type => $key) {
                    foreach ($property->getAttributes($type) as $attribute) {
                        $instance = $attribute->newInstance();
                        if ($instance->property !== null && $instance->property !== $property->getName()) {
                            throw new EntityMappingException(sprintf(
                                'The #[%s] attribute on %s::$%s cannot name another property ("%s"); use it on the class.',
                                substr($type, (int) strrpos($type, '\\') + 1),
                                $class->getName(),
                                $property->getName(),
                                $instance->property
                            ));
                        }
                        $declared[$property->getName()][$key] = $instance;
                    }
                }
            }

            if ($unmappedSoftDelete !== null) {
                $mapping = $mapping->softDelete($unmappedSoftDelete->column ?? 'deleted_at');
            }

            foreach ($declared as $property => $parts) {
                /** @var Column|null $column */
                $column = $parts['column'] ?? null;
                /** @var Id|null $id */
                $id = $parts['id'] ?? null;
                /** @var Version|null $version */
                $version = $parts['version'] ?? null;
                if ($id !== null && $version !== null) {
                    throw new EntityMappingException(sprintf('Property "%s" of %s cannot be both #[Id] and #[Version].', $property, $entityClass));
                }
                if ($id !== null) {
                    $mapping = $mapping->id(
                        $property,
                        $id->strategy,
                        $id->column ?? $column?->name,
                        $id->type !== ColumnType::Auto ? $id->type : ($column?->type ?? ColumnType::Auto),
                        $id->sequence
                    );
                } elseif ($version !== null) {
                    $mapping = $mapping->version($property, $version->column ?? $column?->name);
                } elseif ($column !== null) {
                    $mapping = $mapping->column($property, $column->name, $column->type, $column->enumClass, $column->timezone, $column->booleanAs, $column->trueValue, $column->falseValue);
                }

                /** @var CreatedAt|null $createdAt */
                $createdAt = $parts['createdAt'] ?? null;
                if ($createdAt !== null) {
                    $mapping = $mapping->createdAt($property, $createdAt->column ?? $column?->name);
                }
                /** @var UpdatedAt|null $updatedAt */
                $updatedAt = $parts['updatedAt'] ?? null;
                if ($updatedAt !== null) {
                    $mapping = $mapping->updatedAt($property, $updatedAt->column ?? $column?->name);
                }
                /** @var SoftDelete|null $softDelete */
                $softDelete = $parts['softDelete'] ?? null;
                if ($softDelete !== null) {
                    $mapping = $mapping->softDelete(
                        $softDelete->column ?? $column?->name ?? self::snakeCase($property),
                        $property
                    );
                }
            }
        }

        // Relationships last: the foreign key column of a reference must be mapped first (since 1.13.0)
        foreach ($chain as $class) {
            foreach ($class->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() !== $class->getName()) {
                    continue;
                }
                foreach ($property->getAttributes(ManyToOne::class) as $attribute) {
                    $reference = $attribute->newInstance();
                    $mapping = $mapping->manyToOne($property->getName(), $reference->target, $reference->joinColumn);
                }
                foreach ($property->getAttributes(OneToMany::class) as $attribute) {
                    $collection = $attribute->newInstance();
                    $mapping = $mapping->oneToMany($property->getName(), $collection->target, $collection->mappedBy, $collection->cascade);
                }
                foreach ($property->getAttributes(ManyToMany::class) as $attribute) {
                    $association = $attribute->newInstance();
                    $mapping = $mapping->manyToMany($property->getName(), $association->target, $association->joinTable, $association->joinColumn, $association->inverseJoinColumn);
                }
            }
        }

        $mapping->assertComplete();

        return self::$attributeCache[$entityClass] = $mapping;
    }

    /**
     * Maps (or remaps) the table.
     *
     * @param string $table Plain SQL identifier, optionally `schema.table`
     * @throws EntityMappingException When the name is not a plain SQL identifier
     */
    public function table(string $table): self
    {
        if (preg_match(self::TABLE_PATTERN, $table) !== 1) {
            throw new EntityMappingException(sprintf(
                'Table name "%s" of %s is not a plain SQL identifier (optionally schema-qualified).',
                $table,
                $this->entityClass
            ));
        }
        $copy = clone $this;
        $copy->table = $table;

        return $copy;
    }

    /**
     * Maps a property to a column (a property already mapped is remapped and keeps its identifier,
     * version or lifecycle role).
     *
     * @param string $property Property declared by the class or a parent
     * @param string|null $column Column name (default: snake_case of the property)
     * @param ColumnType $type Conversion (default: inferred from the declared type)
     * @param class-string<UnitEnum>|null $enumClass Enum of an Enum column with no enum-typed property
     * @param string|null $timezone Time zone of a temporal column (default: the mapping's, the
     *                              repository's, UTC)
     * @param BooleanStorage|null $booleanAs Storage of a Boolean column (default: the mapping's,
     *                                       Native); since 1.13.0
     * @param string|null $trueValue Stored text of true of a Char boolean (default: the mapping's,
     *                               'Y'); since 1.14.0
     * @param string|null $falseValue Stored text of false of a Char boolean (default: the mapping's,
     *                                'N'); since 1.14.0
     * @throws EntityMappingException
     */
    public function column(
        string $property,
        ?string $column = null,
        ColumnType $type = ColumnType::Auto,
        ?string $enumClass = null,
        ?string $timezone = null,
        ?BooleanStorage $booleanAs = null,
        ?string $trueValue = null,
        ?string $falseValue = null
    ): self {
        $existing = $this->columns[$property] ?? null;
        $mapped = $this->resolveColumn($property, $column, $type, $enumClass, $timezone, $booleanAs, $trueValue, $falseValue)
            ->withRole($existing?->isId ?? false, $existing?->isVersion ?? false)
            ->withLifecycle($existing?->isCreatedAt ?? false, $existing?->isUpdatedAt ?? false, $existing?->isSoftDelete ?? false);
        if ($mapped->isId) {
            $this->assertIdType($mapped, $this->idStrategy);
        }
        if ($mapped->isVersion && $mapped->type !== ColumnType::Integer) {
            throw new EntityMappingException(sprintf('The version counter "%s" of %s must be an integer column.', $property, $this->entityClass));
        }
        if ($mapped->isLifecycle()) {
            $this->assertLifecycleType($mapped, $mapped->isSoftDelete);
        }

        return $this->withColumn($mapped);
    }

    /**
     * Maps the identifier property and its generation strategy.
     *
     * @param string $property Identifier property
     * @param IdStrategy $strategy Generation strategy
     * @param string|null $column Column name (default: the column already mapped for the property, else snake_case)
     * @param ColumnType $type Conversion (default: the type already mapped, else inferred)
     * @param string|null $sequence Sequence read by lastInsertId() for Auto
     * @throws EntityMappingException When another property is already the identifier or the type does not fit the strategy
     */
    public function id(
        string $property,
        IdStrategy $strategy = IdStrategy::Auto,
        ?string $column = null,
        ColumnType $type = ColumnType::Auto,
        ?string $sequence = null
    ): self {
        if ($this->idProperty !== null && $this->idProperty !== $property) {
            throw new EntityMappingException(sprintf(
                '%s already maps "%s" as its identifier; "%s" cannot be a second one.',
                $this->entityClass,
                $this->idProperty,
                $property
            ));
        }
        if ($sequence !== null && preg_match(self::TABLE_PATTERN, $sequence) !== 1) {
            throw new EntityMappingException(sprintf('Sequence name "%s" is not a plain SQL identifier.', $sequence));
        }
        if ($property === $this->versionProperty) {
            throw new EntityMappingException(sprintf('Property "%s" of %s cannot be both the identifier and the version.', $property, $this->entityClass));
        }
        $this->assertNoLifecycleRole($property, 'the identifier');

        $existing = $this->columns[$property] ?? null;
        $mapped = $this->resolveColumn(
            $property,
            $column ?? $existing?->column,
            $type !== ColumnType::Auto ? $type : ($existing?->type ?? ColumnType::Auto),
            $existing?->enumClass,
            $existing?->timezoneDeclared ? $existing->timezone : null,
            $existing?->booleanStorageDeclared ? $existing->booleanStorage : null,
            $existing?->booleanValuesDeclared ? $existing->trueValue : null,
            $existing?->booleanValuesDeclared ? $existing->falseValue : null
        )->withRole(true, false);
        $this->assertIdType($mapped, $strategy);

        $copy = $this->withColumn($mapped);
        $copy->idProperty = $property;
        $copy->idStrategy = $strategy;
        $copy->sequence = $sequence;

        return $copy;
    }

    /**
     * Maps the optimistic locking counter (an integer column).
     *
     * @param string $property Counter property
     * @param string|null $column Column name (default: snake_case of the property)
     * @throws EntityMappingException When another property is already the version, or the property is the identifier
     */
    public function version(string $property, ?string $column = null): self
    {
        if ($this->versionProperty !== null && $this->versionProperty !== $property) {
            throw new EntityMappingException(sprintf(
                '%s already maps "%s" as its version; "%s" cannot be a second one.',
                $this->entityClass,
                $this->versionProperty,
                $property
            ));
        }
        if ($property === $this->idProperty) {
            throw new EntityMappingException(sprintf('Property "%s" of %s cannot be both the identifier and the version.', $property, $this->entityClass));
        }
        $this->assertNoLifecycleRole($property, 'the version');

        $declared = $this->reflectionOf($property)->getType();
        $declaredName = $declared instanceof ReflectionNamedType ? $declared->getName() : null;
        if ($declared !== null && $declaredName !== 'int' && $declaredName !== 'mixed') {
            throw new EntityMappingException(sprintf(
                'The version counter "%s" of %s must be an int (or untyped) property, %s declared.',
                $property,
                $this->entityClass,
                (string) $declared
            ));
        }

        $existing = $this->columns[$property] ?? null;
        $mapped = $this->resolveColumn($property, $column ?? $existing?->column, ColumnType::Integer, null, null, null, null, null)
            ->withRole(false, true);

        $copy = $this->withColumn($mapped);
        $copy->versionProperty = $property;

        return $copy;
    }

    /**
     * Default time zone of the temporal columns that declare none (since 1.13.0, forward 031, D3):
     * the zone their values are written in and read from. Precedence: the column, then this default,
     * then the default of the repository (PdoRepository::withTimezone()), then UTC.
     *
     * @param string $timezone Any zone DateTimeZone accepts (`America/Sao_Paulo`, `UTC`, `-03:00`)
     * @throws EntityMappingException When the zone is unknown
     */
    public function timezone(string $timezone): self
    {
        self::assertTimezone($timezone, sprintf('the default of %s', $this->entityClass));
        $copy = clone $this;
        $copy->defaultTimezone = $timezone;
        $copy->refreshDefaults();

        return $copy;
    }

    /**
     * Default storage of the Boolean columns that declare none (since 1.13.0, forward 031, D2) and,
     * for BooleanStorage::Char (since 1.14.0, forward 032, D4), the default texts of true and false
     * ('Y' and 'N' unless given).
     *
     * @throws EntityMappingException When texts are given for another storage, or are not 1 to 16
     *                                letters or digits, or are equal whatever the case
     */
    public function booleanAs(BooleanStorage $storage, ?string $trueValue = null, ?string $falseValue = null): self
    {
        if (($trueValue !== null || $falseValue !== null) && $storage !== BooleanStorage::Char) {
            throw new EntityMappingException(sprintf('trueValue/falseValue of %s apply only to BooleanStorage::Char, %s given.', $this->entityClass, $storage->name));
        }
        self::assertBooleanTexts($trueValue ?? 'Y', $falseValue ?? 'N', sprintf('the default of %s', $this->entityClass));
        $copy = clone $this;
        $copy->defaultBooleanStorage = $storage;
        $copy->defaultTrueValue = $trueValue;
        $copy->defaultFalseValue = $falseValue;
        $copy->refreshDefaults();

        return $copy;
    }

    /**
     * Marks the creation timestamp (since 1.13.0, forward 031, D4): filled with the repository's
     * clock on the INSERT when the property holds no value, never written by an UPDATE.
     *
     * @param string $property Temporal property (mapped now if it is not yet)
     * @param string|null $column Column name (default: the column already mapped, else snake_case)
     * @throws EntityMappingException When the property is not temporal, has another role, or another property is the creation timestamp
     */
    public function createdAt(string $property, ?string $column = null): self
    {
        return $this->withLifecycleRole('createdAt', $property, $column);
    }

    /**
     * Marks the modification timestamp (since 1.13.0, forward 031, D4): set to the repository's
     * clock on every write of the row (INSERT, UPDATE, soft removal).
     *
     * @param string $property Temporal property (mapped now if it is not yet)
     * @param string|null $column Column name (default: the column already mapped, else snake_case)
     * @throws EntityMappingException When the property is not temporal, has another role, or another property is the modification timestamp
     */
    public function updatedAt(string $property, ?string $column = null): self
    {
        return $this->withLifecycleRole('updatedAt', $property, $column);
    }

    /**
     * Soft delete (since 1.13.0, forward 031, D4): a removal writes the instant of the repository's
     * clock into the column instead of deleting the row, and reads leave the marked rows out.
     *
     * @param string $column Nullable timestamp column written only by the repository
     * @param string|null $property Nullable temporal property mapped to the column, hydrated and set on
     *                              removal; null = the column is not mapped to a property
     * @throws EntityMappingException When the column or the property does not fit, or soft delete is already mapped elsewhere
     */
    public function softDelete(string $column = 'deleted_at', ?string $property = null): self
    {
        if ($property !== null) {
            return $this->withLifecycleRole('softDelete', $property, $column);
        }

        self::assertColumnName($column, 'soft delete', $this->entityClass);
        if ($this->softDelete !== null && $this->softDelete->property !== '') {
            throw new EntityMappingException(sprintf(
                '%s already maps "%s" as its soft delete column; it cannot be a second one.',
                $this->entityClass,
                $this->softDelete->property
            ));
        }
        foreach ($this->columns as $other) {
            if (strcasecmp($other->column, $column) === 0) {
                throw new EntityMappingException(sprintf(
                    'Column "%s" of %s is already mapped to property "%s"; give that property to softDelete() to use it as the soft delete column.',
                    $column,
                    $this->entityClass,
                    $other->property
                ));
            }
        }

        $copy = clone $this;
        $copy->softDelete = new ColumnMapping(
            '',
            $column,
            ColumnType::DateTime,
            null,
            $this->defaultTimezone ?? 'UTC',
            DateTimeImmutable::class,
            isSoftDelete: true
        );

        return $copy;
    }

    /**
     * Maps a reference to another entity (since 1.13.0, forward 031, D7; #[ManyToOne]): aggregation,
     * never cascaded. The foreign key column must be (or later be) mapped to a property of this entity,
     * which is the stored truth; assertComplete() checks it.
     *
     * @param string $property Nullable property holding the referenced object (typed with the target
     *                         class, a parent of it, object, or untyped); not a column
     * @param class-string<IEntity> $target Referenced entity class
     * @param string $joinColumn Foreign key column of this table
     * @throws EntityMappingException When the property, the target or the column does not fit
     */
    public function manyToOne(string $property, string $target, string $joinColumn): self
    {
        $target = $this->assertRelation($property, $target, 'ManyToOne');
        self::assertColumnName($joinColumn, sprintf('the join column of "%s"', $property), $this->entityClass);

        $declared = $this->reflectionOf($property)->getType();
        if ($declared !== null) {
            $name = $declared instanceof ReflectionNamedType ? $declared->getName() : null;
            $fits = $name !== null && ($name === 'mixed' || $name === 'object' || is_a($target, $name, true));
            if (!$fits || !$declared->allowsNull()) {
                throw new EntityMappingException(sprintf(
                    'The reference "%s" of %s must be a nullable property of type %s (a reference that is not loaded is null), %s declared.',
                    $property,
                    $this->entityClass,
                    $target,
                    (string) $declared
                ));
            }
        }

        $copy = clone $this;
        $copy->relations[$property] = new RelationMapping($property, RelationMapping::MANY_TO_ONE, $target, joinColumn: $joinColumn);

        return $copy;
    }

    /**
     * Maps a collection of child entities (since 1.13.0, forward 031, D7; #[OneToMany]).
     *
     * @param string $property Array property holding the children (typed array, iterable, or untyped); not a column
     * @param class-string<IEntity> $target Child entity class
     * @param string $mappedBy Property of the child holding this entity's identifier: a mapped property
     *                         or the child's #[ManyToOne] back reference to this class (checked when
     *                         the repository resolves the relation)
     * @param bool $cascade True = composition: the children are saved and removed with this entity
     * @throws EntityMappingException When the property or the target does not fit
     */
    public function oneToMany(string $property, string $target, string $mappedBy, bool $cascade = false): self
    {
        $target = $this->assertRelation($property, $target, 'OneToMany');
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $mappedBy) !== 1) {
            throw new EntityMappingException(sprintf('The mappedBy "%s" of the collection "%s" of %s is not a property name.', $mappedBy, $property, $this->entityClass));
        }
        $this->assertCollectionProperty($property);

        $copy = clone $this;
        $copy->relations[$property] = new RelationMapping($property, RelationMapping::ONE_TO_MANY, $target, mappedBy: $mappedBy, cascade: $cascade);

        return $copy;
    }

    /**
     * Maps an association through a join table (since 1.14.0, forward 032, D1; #[ManyToMany]): never
     * cascaded to the targets; putting this entity with the collection loaded synchronizes the links.
     *
     * @param string $property Array property holding the targets (typed array, iterable, or untyped); not a column
     * @param class-string<IEntity> $target Associated entity class
     * @param string $joinTable Join table, a plain SQL identifier, optionally `schema.table`
     * @param string $joinColumn Column of the join table holding this entity's identifier
     * @param string $inverseJoinColumn Column of the join table holding the target's identifier
     * @throws EntityMappingException When the property, the target, the table or a column does not fit
     */
    public function manyToMany(string $property, string $target, string $joinTable, string $joinColumn, string $inverseJoinColumn): self
    {
        $target = $this->assertRelation($property, $target, 'ManyToMany');
        if (preg_match(self::TABLE_PATTERN, $joinTable) !== 1) {
            throw new EntityMappingException(sprintf('The join table "%s" of "%s" of %s is not a plain SQL identifier (optionally schema-qualified).', $joinTable, $property, $this->entityClass));
        }
        self::assertColumnName($joinColumn, sprintf('the join column of "%s"', $property), $this->entityClass);
        self::assertColumnName($inverseJoinColumn, sprintf('the inverse join column of "%s"', $property), $this->entityClass);
        if (strcasecmp($joinColumn, $inverseJoinColumn) === 0) {
            throw new EntityMappingException(sprintf('The two columns of the join table of "%s" of %s must differ, "%s" given twice.', $property, $this->entityClass, $joinColumn));
        }
        $this->assertCollectionProperty($property);

        $copy = clone $this;
        $copy->relations[$property] = new RelationMapping(
            $property,
            RelationMapping::MANY_TO_MANY,
            $target,
            joinColumn: $joinColumn,
            joinTable: $joinTable,
            inverseJoinColumn: $inverseJoinColumn
        );

        return $copy;
    }

    ///////////////////////////////////////////////////////////////////////////
    // Accessors
    ///////////////////////////////////////////////////////////////////////////

    /** @return class-string<IEntity> */
    public function getEntityClass(): string
    {
        return $this->entityClass;
    }

    /** Table name, or null while not mapped. */
    public function getTable(): ?string
    {
        return $this->table;
    }

    /**
     * Every mapped column, by property, in mapping order.
     *
     * @return array<string, ColumnMapping>
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /** Column mapping of a property, or null when the property is not mapped. */
    public function getColumn(string $property): ?ColumnMapping
    {
        return $this->columns[$property] ?? null;
    }

    /**
     * The identifier column.
     *
     * @throws EntityMappingException When no identifier is mapped
     */
    public function getId(): ColumnMapping
    {
        if ($this->idProperty === null) {
            throw new EntityMappingException(sprintf('%s has no identifier mapped.', $this->entityClass));
        }

        return $this->columns[$this->idProperty];
    }

    /** Identifier generation strategy. */
    public function getIdStrategy(): IdStrategy
    {
        return $this->idStrategy;
    }

    /** Sequence read by lastInsertId() for Auto, or null. */
    public function getSequence(): ?string
    {
        return $this->sequence;
    }

    /** The version counter column, or null when the entity is not versioned. */
    public function getVersion(): ?ColumnMapping
    {
        return $this->versionProperty !== null ? $this->columns[$this->versionProperty] : null;
    }

    /** Default time zone of the temporal columns that declare none, or null (since 1.13.0). */
    public function getTimezone(): ?string
    {
        return $this->defaultTimezone;
    }

    /** Default storage of the Boolean columns that declare none (Native unless set; since 1.13.0). */
    public function getBooleanStorage(): BooleanStorage
    {
        return $this->defaultBooleanStorage ?? BooleanStorage::Native;
    }

    /**
     * Default texts of the Char booleans that declare none, `[true, false]` ('Y' and 'N' unless set;
     * since 1.14.0).
     *
     * @return array{0: string, 1: string}
     */
    public function getBooleanValues(): array
    {
        return [$this->defaultTrueValue ?? 'Y', $this->defaultFalseValue ?? 'N'];
    }

    /** The creation timestamp column, or null (since 1.13.0). */
    public function getCreatedAt(): ?ColumnMapping
    {
        return $this->createdAtProperty !== null ? $this->columns[$this->createdAtProperty] : null;
    }

    /** The modification timestamp column, or null (since 1.13.0). */
    public function getUpdatedAt(): ?ColumnMapping
    {
        return $this->updatedAtProperty !== null ? $this->columns[$this->updatedAtProperty] : null;
    }

    /**
     * The soft delete column, or null (since 1.13.0). Its property is the empty string when the
     * column is not mapped to a property (then it is not among getColumns()).
     */
    public function getSoftDelete(): ?ColumnMapping
    {
        if ($this->softDelete !== null && $this->softDelete->property !== '') {
            return $this->columns[$this->softDelete->property];
        }

        return $this->softDelete;
    }

    /** True when removals mark the rows instead of deleting them (since 1.13.0). */
    public function hasSoftDelete(): bool
    {
        return $this->softDelete !== null;
    }

    /**
     * Every relationship, by property, in mapping order (since 1.13.0).
     *
     * @return array<string, RelationMapping>
     */
    public function getRelations(): array
    {
        return $this->relations;
    }

    /** The relationship of a property, or null (since 1.13.0). */
    public function getRelation(string $property): ?RelationMapping
    {
        return $this->relations[$property] ?? null;
    }

    /**
     * The column mapping of the foreign key of a reference (#[ManyToOne]): the mapped column whose
     * name is its join column (since 1.13.0).
     *
     * @throws EntityMappingException When the relation is not a reference of this mapping or its
     *                                join column is not mapped to a property
     */
    public function getJoinColumn(string $property): ColumnMapping
    {
        $relation = $this->relations[$property] ?? null;
        if ($relation === null || !$relation->isManyToOne()) {
            throw new EntityMappingException(sprintf('"%s" is not a ManyToOne reference of %s.', $property, $this->entityClass));
        }
        foreach ($this->columns as $column) {
            if (strcasecmp($column->column, (string) $relation->joinColumn) === 0) {
                return $column;
            }
        }

        throw new EntityMappingException(sprintf(
            'The join column "%s" of the reference "%s" of %s is not mapped to a property: map it (for instance #[Column(\'%s\')] public ?int $%sId), it holds the stored reference.',
            (string) $relation->joinColumn,
            $property,
            $this->entityClass,
            (string) $relation->joinColumn,
            $property
        ));
    }

    /**
     * True when the relation of this entity OBJECT was loaded (PdoRepository::with()) or written as a
     * whole by the repository, so its value is the stored truth; false when its value says nothing
     * about the database (a collection that was not loaded is never "no children"). Since 1.13.0.
     */
    public function isRelationLoaded(object $entity, string $property): bool
    {
        return LoadedRelations::isLoaded($entity, $property);
    }

    /**
     * Gives the relation properties of an entity built without its constructor their "not loaded"
     * value when they are uninitialized: the empty array for a collection, null for a reference
     * (since 1.13.0; used by the repository and by hydrate()).
     */
    public function initializeRelations(object $entity): void
    {
        foreach ($this->relations as $relation) {
            $reflection = $this->reflection[$relation->property];
            if (!$reflection->isInitialized($entity)) {
                $reflection->setValue($entity, $relation->isCollection() ? [] : null);
            }
        }
    }

    /** True once a table and an identifier are mapped. */
    public function isComplete(): bool
    {
        return $this->table !== null && $this->idProperty !== null;
    }

    /**
     * @throws EntityMappingException When the table or the identifier is missing, the join column of a
     *                                reference is not mapped, or (since 1.14.0) a column declares the
     *                                texts of a Char boolean without being stored as BooleanStorage::Char
     */
    public function assertComplete(): void
    {
        if ($this->table === null) {
            throw new EntityMappingException(sprintf('The mapping of %s has no table.', $this->entityClass));
        }
        if ($this->idProperty === null) {
            throw new EntityMappingException(sprintf('The mapping of %s has no identifier.', $this->entityClass));
        }
        foreach ($this->relations as $relation) {
            if ($relation->isManyToOne()) {
                $this->getJoinColumn($relation->property);
            }
        }
        foreach ($this->columns as $property => $column) {
            if ($column->booleanValuesDeclared && !$column->isCharBoolean()) {
                throw new EntityMappingException(sprintf(
                    'Property "%s" of %s declares trueValue/falseValue, which apply only to a Boolean column stored as BooleanStorage::Char (give booleanAs: BooleanStorage::Char to the column or the mapping).',
                    $property,
                    $this->entityClass
                ));
            }
        }
    }

    /**
     * Plain description of the whole mapping (used by equals() and for diagnostics).
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        $columns = array_map(static fn(ColumnMapping $c): array => $c->describe(), $this->columns);
        ksort($columns);
        $relations = array_map(static fn(RelationMapping $r): array => $r->describe(), $this->relations);
        ksort($relations);

        return [
            'class' => $this->entityClass,
            'table' => $this->table,
            'idStrategy' => $this->idStrategy->value,
            'sequence' => $this->sequence,
            'columns' => $columns,
            'timezone' => $this->defaultTimezone,
            'booleanAs' => $this->defaultBooleanStorage?->value,
            'booleanValues' => $this->getBooleanValues(),
            'softDelete' => $this->getSoftDelete()?->describe(),
            'relations' => $relations,
        ];
    }

    /** True when both mappings describe the same class, table, columns, types and roles. */
    public function equals(self $other): bool
    {
        return $this->describe() === $other->describe();
    }

    ///////////////////////////////////////////////////////////////////////////
    // Specification support
    ///////////////////////////////////////////////////////////////////////////

    /**
     * The column a specification property name compiles to, or null with the reason.
     *
     * A name maps to a column only when the property is mapped AND a specification may read it by
     * name, the rule the shared property accessor (`PropertyAccess`) applies in memory: a public property, a public getter
     * (`getX()`, `x()`, `isX()`, `hasX()`) or #[Specifiable]. So the same specification is refused by
     * the database exactly where it fails in memory, and an unmarked private column is never a read
     * oracle through SQL. `entityId` (IEntity::getEntityId()) always means the identifier column.
     *
     * @param string $property Property name used by the specification
     * @param string|null $reason Set to the reason when null is returned
     */
    public function findQueryColumn(string $property, ?string &$reason = null): ?ColumnMapping
    {
        $mapped = $this->columns[$property] ?? null;
        if ($mapped === null) {
            if ($property === self::ENTITY_ID_ALIAS && $this->idProperty !== null) {
                return $this->columns[$this->idProperty];
            }
            if (isset($this->relations[$property])) {
                $reason = sprintf('property "%s" is a relationship, not a column (filter on it with PdoRepository::whereRelation())', $property);
                return null;
            }
            $reason = sprintf('property "%s" is not mapped to a column of %s', $property, (string) $this->table);
            return null;
        }

        if ($this->readableByName($property)) {
            return $mapped;
        }

        $reason = sprintf(
            'property "%s" is mapped but not readable by name (private or protected without a public getter or #[Specifiable]); ' .
            'it is refused in memory too',
            $property
        );

        return null;
    }

    ///////////////////////////////////////////////////////////////////////////
    // Reflection services used by the repository
    ///////////////////////////////////////////////////////////////////////////

    /**
     * A new instance of the entity class, built WITHOUT calling the constructor (D3); properties
     * keep their declared default values.
     */
    public function instantiate(): IEntity
    {
        /** @var IEntity $entity */
        $entity = $this->class->newInstanceWithoutConstructor();

        return $entity;
    }

    /** True when the mapped property holds a value (initialized). */
    public function isInitialized(object $entity, string $property): bool
    {
        return $this->reflection[$property]->isInitialized($entity);
    }

    /** Value of a mapped property, or null when uninitialized. */
    public function readValue(object $entity, string $property): mixed
    {
        $reflection = $this->reflection[$property];

        return $reflection->isInitialized($entity) ? $reflection->getValue($entity) : null;
    }

    /**
     * Writes a mapped property.
     *
     * @throws EntityMappingException When the property cannot hold the value (readonly already set, type mismatch)
     */
    public function writeValue(object $entity, string $property, mixed $value): void
    {
        try {
            $this->reflection[$property]->setValue($entity, $value);
        } catch (Throwable $e) {
            throw new EntityMappingException(sprintf(
                'Property "%s" of %s cannot be set: %s',
                $property,
                $this->entityClass,
                $e->getMessage()
            ), 0, $e);
        }
    }

    /**
     * The row of an entity: column => property value, for every mapped column in mapping order (the
     * toArray() of an active record; since 1.13.0, forward 031, D4). Values are the PHP values of the
     * properties (dates, enums and arrays as objects and arrays, not in their stored form); an
     * uninitialized property gives null. A soft delete column not mapped to a property is not part of
     * the entity, so not of its row.
     *
     * @return array<string, mixed>
     * @throws InvalidArgumentException When the object is not an instance of the mapped class
     */
    public function extract(object $entity): array
    {
        if (!$entity instanceof $this->entityClass) {
            throw new InvalidArgumentException(sprintf('%s is not a %s.', get_debug_type($entity), $this->entityClass));
        }

        $row = [];
        foreach ($this->columns as $column) {
            $row[$column->column] = $this->readValue($entity, $column->property);
        }

        return $row;
    }

    /**
     * An entity built from a row keyed by column, WITHOUT calling the constructor (the fromArray() of
     * an active record; since 1.13.0, forward 031, D4). Each value is converted to its property like a
     * fetched value of its column (`'42'` to 42, `'2026-01-02 10:00:00'` to a date in the column zone,
     * `'t'`/`'1'` to true, a JSON text to an array, a backing value to its enum case); a value that is
     * already of the property's PHP type is kept (a date is converted to the property's date class).
     * Columns missing from the row leave their property at its declared default (or uninitialized);
     * keys that are not mapped columns are ignored. An AbstractEntity whose creation time is not mapped
     * gets the hydration time, as in the repository. The zones are those of the mapping: the default
     * of a repository is not known here.
     *
     * @param array<string, mixed> $row Column => value
     * @return IEntity
     * @throws InvalidArgumentException When a value cannot be converted to its column type
     * @throws EntityMappingException When a property cannot hold its converted value
     */
    public function hydrate(array $row): IEntity
    {
        $reader = self::$valueReader ??= new PdoValueConverter('ansi');
        $entity = $this->instantiate();
        foreach ($this->columns as $column) {
            if (array_key_exists($column->column, $row)) {
                $this->writeValue($entity, $column->property, $reader->toProperty($column, $row[$column->column]));
            }
        }
        $this->initializeRelations($entity);
        self::initializeCreationTime($entity);

        return $entity;
    }

    /**
     * Gives an AbstractEntity built without its constructor the creation time it would have had (the
     * hydration time), unless a mapping filled it (since 1.13.0; used by the repository too).
     */
    public static function initializeCreationTime(IEntity $entity): void
    {
        if ($entity instanceof AbstractEntity) {
            $property = self::$timeOfCreation ??= new ReflectionProperty(AbstractEntity::class, 'timeOfCreation');
            if (!$property->isInitialized($entity)) {
                $property->setValue($entity, new DateTimeImmutable());
            }
        }
    }

    ///////////////////////////////////////////////////////////////////////////
    // Internals
    ///////////////////////////////////////////////////////////////////////////

    private function withColumn(ColumnMapping $mapped): self
    {
        if (isset($this->relations[$mapped->property])) {
            throw new EntityMappingException(sprintf('Property "%s" of %s is a relationship; it cannot be a column too.', $mapped->property, $this->entityClass));
        }
        if ($this->softDelete !== null && $this->softDelete->property === ''
            && strcasecmp($this->softDelete->column, $mapped->column) === 0) {
            throw new EntityMappingException(sprintf(
                'Column "%s" of %s is the soft delete column; give property "%s" to softDelete() to map it.',
                $mapped->column,
                $this->entityClass,
                $mapped->property
            ));
        }
        foreach ($this->columns as $other) {
            if ($other->property !== $mapped->property && strcasecmp($other->column, $mapped->column) === 0) {
                throw new EntityMappingException(sprintf(
                    'Column "%s" of %s is already mapped to property "%s"; "%s" cannot use it too.',
                    $mapped->column,
                    $this->entityClass,
                    $other->property,
                    $mapped->property
                ));
            }
        }

        $copy = clone $this;
        $copy->columns[$mapped->property] = $mapped;

        return $copy;
    }

    private function resolveColumn(
        string $property,
        ?string $column,
        ColumnType $type,
        ?string $enumClass,
        ?string $timezone,
        ?BooleanStorage $booleanAs,
        ?string $trueValue = null,
        ?string $falseValue = null
    ): ColumnMapping {
        $reflection = $this->reflectionOf($property);

        $column ??= self::snakeCase($property);
        self::assertColumnName($column, sprintf('property "%s"', $property), $this->entityClass);

        $declared = $reflection->getType();
        $declaredName = $declared instanceof ReflectionNamedType ? $declared->getName() : null;

        if ($type === ColumnType::Auto) {
            $type = match (true) {
                $declared === null, $declaredName === 'mixed' => ColumnType::Raw,
                $declaredName === 'int' => ColumnType::Integer,
                $declaredName === 'float' => ColumnType::Float,
                $declaredName === 'bool', $declaredName === 'false', $declaredName === 'true' => ColumnType::Boolean,
                $declaredName === 'string' => ColumnType::String,
                $declaredName === 'array' => ColumnType::Json,
                $declaredName !== null && is_a($declaredName, DateTimeInterface::class, true) => ColumnType::DateTime,
                $declaredName !== null && enum_exists($declaredName) => ColumnType::Enum,
                default => throw new EntityMappingException(sprintf(
                    'Cannot infer the column type of property "%s" of %s (declared %s); give a ColumnType.',
                    $property,
                    $this->entityClass,
                    $declared === null ? 'untyped' : (string) $declared
                )),
            };
        }

        if ($type === ColumnType::Enum) {
            $enumClass ??= ($declaredName !== null && enum_exists($declaredName)) ? $declaredName : null;
            if ($enumClass === null || !enum_exists($enumClass)) {
                throw new EntityMappingException(sprintf(
                    'Enum column of property "%s" of %s needs an enum class (typed property or enumClass).',
                    $property,
                    $this->entityClass
                ));
            }
        } else {
            $enumClass = null;
        }

        if ($timezone !== null) {
            self::assertTimezone($timezone, sprintf('property "%s"', $property));
        }

        $dateClass = $declaredName === DateTime::class ? DateTime::class : DateTimeImmutable::class;

        $storage = $booleanAs ?? $this->defaultBooleanStorage ?? BooleanStorage::Native;
        $valuesDeclared = $trueValue !== null || $falseValue !== null;
        [$defaultTrue, $defaultFalse] = $this->getBooleanValues();
        $trueValue ??= $defaultTrue;
        $falseValue ??= $defaultFalse;
        if ($valuesDeclared) {
            // The storage may still come from a default set later (booleanAs()): assertComplete() checks it
            if ($type !== ColumnType::Boolean || ($booleanAs !== null && $booleanAs !== BooleanStorage::Char)) {
                throw new EntityMappingException(sprintf(
                    'trueValue/falseValue of property "%s" of %s apply only to a Boolean column stored as BooleanStorage::Char.',
                    $property,
                    $this->entityClass
                ));
            }
            self::assertBooleanTexts($trueValue, $falseValue, sprintf('property "%s" of %s', $property, $this->entityClass));
        }

        return new ColumnMapping(
            $property,
            $column,
            $type,
            $enumClass,
            $timezone ?? $this->defaultTimezone ?? 'UTC',
            $dateClass,
            booleanStorage: $storage,
            booleanStorageDeclared: $booleanAs !== null,
            timezoneDeclared: $timezone !== null,
            trueValue: $trueValue,
            falseValue: $falseValue,
            booleanValuesDeclared: $valuesDeclared
        );
    }

    /**
     * Gives a property a lifecycle role (creation, modification, soft delete).
     *
     * @param 'createdAt'|'updatedAt'|'softDelete' $role
     */
    private function withLifecycleRole(string $role, string $property, ?string $column): self
    {
        $label = ['createdAt' => 'creation timestamp', 'updatedAt' => 'modification timestamp', 'softDelete' => 'soft delete column'][$role];
        if ($property === $this->idProperty || $property === $this->versionProperty) {
            throw new EntityMappingException(sprintf(
                'Property "%s" of %s is the %s; it cannot be the %s.',
                $property,
                $this->entityClass,
                $property === $this->idProperty ? 'identifier' : 'version',
                $label
            ));
        }
        $holder = match ($role) {
            'createdAt' => $this->createdAtProperty,
            'updatedAt' => $this->updatedAtProperty,
            'softDelete' => ($this->softDelete !== null && $this->softDelete->property !== '') ? $this->softDelete->property : null,
        };
        if ($holder !== null && $holder !== $property) {
            throw new EntityMappingException(sprintf('%s already maps "%s" as its %s; "%s" cannot be a second one.', $this->entityClass, $holder, $label, $property));
        }

        $existing = $this->columns[$property] ?? null;
        $flags = [
            'createdAt' => $role === 'createdAt',
            'updatedAt' => $role === 'updatedAt',
            'softDelete' => $role === 'softDelete',
        ];
        if ($existing !== null) {
            foreach (['createdAt' => $existing->isCreatedAt, 'updatedAt' => $existing->isUpdatedAt, 'softDelete' => $existing->isSoftDelete] as $other => $has) {
                if ($has && $other !== $role) {
                    throw new EntityMappingException(sprintf('Property "%s" of %s already has another lifecycle role (%s).', $property, $this->entityClass, $other));
                }
            }
        }

        // The column itself must not shadow an unmapped soft delete column (replaced below when this is it)
        $copy = clone $this;
        if ($role === 'softDelete') {
            $copy->softDelete = null;
        }
        $mapped = $copy->resolveColumn(
            $property,
            $column ?? $existing?->column,
            $existing?->type ?? ColumnType::Auto,
            $existing?->enumClass,
            $existing?->timezoneDeclared ? $existing->timezone : null,
            $existing?->booleanStorageDeclared ? $existing->booleanStorage : null,
            $existing?->booleanValuesDeclared ? $existing->trueValue : null,
            $existing?->booleanValuesDeclared ? $existing->falseValue : null
        )->withLifecycle($flags['createdAt'], $flags['updatedAt'], $flags['softDelete']);
        $this->assertLifecycleType($mapped, $role === 'softDelete');

        $copy = $copy->withColumn($mapped);
        match ($role) {
            'createdAt' => $copy->createdAtProperty = $property,
            'updatedAt' => $copy->updatedAtProperty = $property,
            'softDelete' => $copy->softDelete = $mapped,
        };

        return $copy;
    }

    /**
     * A collection property is an array (typed array, iterable, mixed or untyped).
     */
    private function assertCollectionProperty(string $property): void
    {
        $declared = $this->reflectionOf($property)->getType();
        if ($declared !== null) {
            $name = $declared instanceof ReflectionNamedType ? $declared->getName() : null;
            if (!in_array($name, ['array', 'iterable', 'mixed'], true)) {
                throw new EntityMappingException(sprintf(
                    'The collection "%s" of %s must be an array property (array, iterable or untyped), %s declared.',
                    $property,
                    $this->entityClass,
                    (string) $declared
                ));
            }
        }
    }

    /**
     * Checks the property and the target of a relationship; returns the target's class name as declared.
     *
     * @return class-string<IEntity>
     */
    private function assertRelation(string $property, string $target, string $kind): string
    {
        $this->reflectionOf($property);
        if (isset($this->columns[$property])) {
            throw new EntityMappingException(sprintf('Property "%s" of %s is mapped to a column; it cannot be a %s relationship too.', $property, $this->entityClass, $kind));
        }
        if (isset($this->relations[$property])) {
            throw new EntityMappingException(sprintf('Property "%s" of %s is already a relationship.', $property, $this->entityClass));
        }
        if (!class_exists($target) || !is_subclass_of($target, IEntity::class)) {
            throw new EntityMappingException(sprintf('The target "%s" of the %s "%s" of %s is not an entity class.', $target, $kind, $property, $this->entityClass));
        }
        $class = new ReflectionClass($target);
        if ($class->isAbstract()) {
            throw new EntityMappingException(sprintf('The target %s of the %s "%s" of %s must be concrete to be hydrated.', $target, $kind, $property, $this->entityClass));
        }

        return $class->getName();
    }

    /**
     * A lifecycle column holds an instant: a Date or DateTime column; the soft delete property must
     * accept null (a live row).
     */
    private function assertLifecycleType(ColumnMapping $mapped, bool $nullable): void
    {
        if (!$mapped->type->isTemporal()) {
            throw new EntityMappingException(sprintf(
                'The lifecycle property "%s" of %s must be a temporal column (Date or DateTime), %s given.',
                $mapped->property,
                $this->entityClass,
                $mapped->type->value
            ));
        }
        $declared = $this->reflectionOf($mapped->property)->getType();
        if ($nullable && $declared !== null && !$declared->allowsNull()) {
            throw new EntityMappingException(sprintf(
                'The soft delete property "%s" of %s must accept null (a row that is not removed), %s declared.',
                $mapped->property,
                $this->entityClass,
                (string) $declared
            ));
        }
    }

    private function assertNoLifecycleRole(string $property, string $role): void
    {
        $existing = $this->columns[$property] ?? null;
        if ($existing !== null && $existing->isLifecycle()) {
            throw new EntityMappingException(sprintf('Property "%s" of %s has a lifecycle role; it cannot be %s.', $property, $this->entityClass, $role));
        }
    }

    /**
     * Applies the defaults of the mapping to the columns that declare no zone or storage.
     */
    private function refreshDefaults(): void
    {
        $timezone = $this->defaultTimezone ?? 'UTC';
        $storage = $this->defaultBooleanStorage ?? BooleanStorage::Native;
        [$true, $false] = $this->getBooleanValues();
        foreach ($this->columns as $property => $column) {
            $this->columns[$property] = $column->withDefaults($timezone, $storage, $true, $false);
        }
        if ($this->softDelete !== null) {
            $this->softDelete = $this->softDelete->property !== ''
                ? $this->columns[$this->softDelete->property]
                : $this->softDelete->withDefaults($timezone, $storage, $true, $false);
        }
    }

    private static function snakeCase(string $property): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $property));
    }

    private static function assertColumnName(string $column, string $owner, string $entityClass): void
    {
        if (preg_match(self::COLUMN_PATTERN, $column) !== 1) {
            throw new EntityMappingException(sprintf(
                'Column name "%s" (%s of %s) is not a plain SQL identifier.',
                $column,
                $owner,
                $entityClass
            ));
        }
    }

    /**
     * The texts of a Char boolean: 1 to 16 letters or digits each (they are written into the SQL of a
     * comparison as literals), different whatever the case (they are read whatever the case).
     */
    private static function assertBooleanTexts(string $trueValue, string $falseValue, string $owner): void
    {
        foreach ([$trueValue, $falseValue] as $text) {
            if (preg_match(self::BOOLEAN_TEXT_PATTERN, $text) !== 1) {
                throw new EntityMappingException(sprintf('The boolean text "%s" of %s must be 1 to 16 letters or digits.', $text, $owner));
            }
        }
        if (strcasecmp($trueValue, $falseValue) === 0) {
            throw new EntityMappingException(sprintf('The boolean texts of %s must differ whatever the case, "%s" and "%s" given.', $owner, $trueValue, $falseValue));
        }
    }

    private static function assertTimezone(string $timezone, string $owner): void
    {
        try {
            new DateTimeZone($timezone);
        } catch (Throwable $e) {
            throw new EntityMappingException(sprintf('Unknown time zone "%s" for %s.', $timezone, $owner), 0, $e);
        }
    }

    private function reflectionOf(string $property): ReflectionProperty
    {
        if (isset($this->reflection[$property])) {
            return $this->reflection[$property];
        }

        $reflection = $property === '' ? null : ReflectionUtils::getFieldByName($property, $this->entityClass);
        if ($reflection === null) {
            throw new EntityMappingException(sprintf('%s has no property "%s".', $this->entityClass, $property));
        }
        if ($reflection->isStatic()) {
            throw new EntityMappingException(sprintf('Property "%s" of %s is static and cannot be mapped.', $property, $this->entityClass));
        }

        return $this->reflection[$property] = $reflection;
    }

    private function assertIdType(ColumnMapping $mapped, IdStrategy $strategy): void
    {
        $ok = match ($strategy) {
            IdStrategy::Max => $mapped->type === ColumnType::Integer,
            IdStrategy::Ulid, IdStrategy::Uuid7 => $mapped->type === ColumnType::String || $mapped->type === ColumnType::Raw,
            IdStrategy::Auto, IdStrategy::Assigned => in_array($mapped->type, [ColumnType::Integer, ColumnType::String, ColumnType::Raw, ColumnType::Decimal], true),
        };
        if (!$ok) {
            throw new EntityMappingException(sprintf(
                'The %s identifier strategy does not fit the %s column type of "%s" (%s).',
                $strategy->value,
                $mapped->type->value,
                $mapped->property,
                $this->entityClass
            ));
        }
    }

    /**
     * Whether a specification may read the property by name: asked to the shared property accessor
     * itself (2.0.0), on an instance built without constructor, so that SQL refuses exactly what the
     * accessor refuses in memory (a getter with a required parameter, `__call()` or a name starting
     * with `__` no longer count; until 1.14 this class kept its own copy of the getter rule).
     *
     * @param string $property Mapped property name
     * @return bool
     */
    private function readableByName(string $property): bool
    {
        try {
            $this->probe ??= $this->class->newInstanceWithoutConstructor();

            return PropertyAccess::hasProperty($this->probe, $property);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Property named by a class-level attribute.
     *
     * @param ReflectionClass<object> $class
     */
    private static function classLevelProperty(ReflectionClass $class, string $attribute, ?string $property): string
    {
        if ($property === null || $property === '') {
            throw new EntityMappingException(sprintf(
                'The #[%s] attribute on the class %s needs the property: argument.',
                $attribute,
                $class->getName()
            ));
        }

        return $property;
    }
}
