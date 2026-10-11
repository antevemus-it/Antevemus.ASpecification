<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Pdo\Mapping;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Helpers\ReflectionUtils;
use Antevemus\ASpecification\Repositories\Pdo\Exceptions\EntityMappingException;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use ReflectionClass;
use ReflectionMethod;
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
 * PropertyAccessor (a public property, a public getter, or #[Specifiable]), see findQueryColumn().
 *
 * @version    1.11.0
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

    /** Spec property that always means the identifier (IEntity::getEntityId()). */
    public const ENTITY_ID_ALIAS = 'entityId';

    private ?string $table = null;

    /** @var array<string, ColumnMapping> Property => column mapping, in mapping order */
    private array $columns = [];

    private ?string $idProperty = null;

    private IdStrategy $idStrategy = IdStrategy::Auto;

    private ?string $sequence = null;

    private ?string $versionProperty = null;

    /** @var array<string, ReflectionProperty> Property => reflection (accessible) */
    private array $reflection = [];

    /** @var ReflectionClass<IEntity> */
    private readonly ReflectionClass $class;

    /** @var array<class-string, self> Mappings read from attributes, by class */
    private static array $attributeCache = [];

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
     * Reads the mapping declared with #[Table], #[Column], #[Id] and #[Version] on the class and its
     * parents (parents first; a declaration on a subclass replaces the one of a parent for the same
     * property). Cached per class.
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
                $table = $attribute->newInstance()->name;
            }
        }
        if ($table === null) {
            throw new EntityMappingException(sprintf('%s has no #[Table] attribute in its hierarchy.', $entityClass));
        }
        $mapping = $mapping->table($table);

        foreach ($chain as $class) {
            /** @var array<string, array{column: ?Column, id: ?Id, version: ?Version}> $declared */
            $declared = [];
            foreach ($class->getAttributes(Column::class) as $attribute) {
                $column = $attribute->newInstance();
                $property = self::classLevelProperty($class, 'Column', $column->property);
                $declared[$property]['column'] = $column;
            }
            foreach ($class->getAttributes(Id::class) as $attribute) {
                $id = $attribute->newInstance();
                $declared[self::classLevelProperty($class, 'Id', $id->property)]['id'] = $id;
            }
            foreach ($class->getAttributes(Version::class) as $attribute) {
                $version = $attribute->newInstance();
                $declared[self::classLevelProperty($class, 'Version', $version->property)]['version'] = $version;
            }
            foreach ($class->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() !== $class->getName()) {
                    continue;
                }
                foreach ([Column::class => 'column', Id::class => 'id', Version::class => 'version'] as $type => $key) {
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

            foreach ($declared as $property => $parts) {
                $column = $parts['column'] ?? null;
                $id = $parts['id'] ?? null;
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
                    $mapping = $mapping->column($property, $column->name, $column->type, $column->enumClass, $column->timezone);
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
     * Maps a property to a column (a property already mapped is remapped and keeps its identifier or
     * version role).
     *
     * @param string $property Property declared by the class or a parent
     * @param string|null $column Column name (default: snake_case of the property)
     * @param ColumnType $type Conversion (default: inferred from the declared type)
     * @param class-string<UnitEnum>|null $enumClass Enum of an Enum column with no enum-typed property
     * @param string|null $timezone Time zone of a temporal column (default UTC)
     * @throws EntityMappingException
     */
    public function column(
        string $property,
        ?string $column = null,
        ColumnType $type = ColumnType::Auto,
        ?string $enumClass = null,
        ?string $timezone = null
    ): self {
        $existing = $this->columns[$property] ?? null;
        $mapped = $this->resolveColumn($property, $column, $type, $enumClass, $timezone)
            ->withRole($existing?->isId ?? false, $existing?->isVersion ?? false);
        if ($mapped->isId) {
            $this->assertIdType($mapped, $this->idStrategy);
        }
        if ($mapped->isVersion && $mapped->type !== ColumnType::Integer) {
            throw new EntityMappingException(sprintf('The version counter "%s" of %s must be an integer column.', $property, $this->entityClass));
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

        $existing = $this->columns[$property] ?? null;
        $mapped = $this->resolveColumn(
            $property,
            $column ?? $existing?->column,
            $type !== ColumnType::Auto ? $type : ($existing?->type ?? ColumnType::Auto),
            $existing?->enumClass,
            $existing?->timezone
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
        $mapped = $this->resolveColumn($property, $column ?? $existing?->column, ColumnType::Integer, null, null)
            ->withRole(false, true);

        $copy = $this->withColumn($mapped);
        $copy->versionProperty = $property;

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

    /** True once a table and an identifier are mapped. */
    public function isComplete(): bool
    {
        return $this->table !== null && $this->idProperty !== null;
    }

    /**
     * @throws EntityMappingException When the table or the identifier is missing
     */
    public function assertComplete(): void
    {
        if ($this->table === null) {
            throw new EntityMappingException(sprintf('The mapping of %s has no table.', $this->entityClass));
        }
        if ($this->idProperty === null) {
            throw new EntityMappingException(sprintf('The mapping of %s has no identifier.', $this->entityClass));
        }
    }

    /**
     * Plain description of the whole mapping (used by equals() and for diagnostics).
     *
     * @return array{class: string, table: string|null, idStrategy: string, sequence: string|null, columns: array<string, array<string, mixed>>}
     */
    public function describe(): array
    {
        $columns = array_map(static fn(ColumnMapping $c): array => $c->describe(), $this->columns);
        ksort($columns);

        return [
            'class' => $this->entityClass,
            'table' => $this->table,
            'idStrategy' => $this->idStrategy->value,
            'sequence' => $this->sequence,
            'columns' => $columns,
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
     * name, the rule PropertyAccessor applies in memory: a public property, a public getter
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
            $reason = sprintf('property "%s" is not mapped to a column of %s', $property, (string) $this->table);
            return null;
        }

        if ($this->hasPublicReader($property) || $this->reflection[$property]->isPublic()
            || ReflectionUtils::isSpecifiable($this->reflection[$property])) {
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

    ///////////////////////////////////////////////////////////////////////////
    // Internals
    ///////////////////////////////////////////////////////////////////////////

    private function withColumn(ColumnMapping $mapped): self
    {
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
        ?string $timezone
    ): ColumnMapping {
        $reflection = $this->reflectionOf($property);

        $column ??= strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $property));
        if (preg_match(self::COLUMN_PATTERN, $column) !== 1) {
            throw new EntityMappingException(sprintf(
                'Column name "%s" (property "%s" of %s) is not a plain SQL identifier.',
                $column,
                $property,
                $this->entityClass
            ));
        }

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

        $timezone ??= 'UTC';
        try {
            new DateTimeZone($timezone);
        } catch (Throwable $e) {
            throw new EntityMappingException(sprintf('Unknown time zone "%s" for property "%s".', $timezone, $property), 0, $e);
        }

        $dateClass = $declaredName === DateTime::class ? DateTime::class : DateTimeImmutable::class;

        return new ColumnMapping($property, $column, $type, $enumClass, $timezone, $dateClass);
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

    private function hasPublicReader(string $property): bool
    {
        foreach (['get' . ucfirst($property), $property, 'is' . ucfirst($property), 'has' . ucfirst($property)] as $method) {
            if ($this->class->hasMethod($method)) {
                $reflection = new ReflectionMethod($this->entityClass, $method);
                if ($reflection->isPublic() && !$reflection->isStatic()) {
                    return true;
                }
            }
        }

        return false;
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
