<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Pdo\Traits;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\Repositories\IRepository;
use Antevemus\ASpecification\Repositories\Pdo\Exceptions\EntityMappingException;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\ColumnMapping;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\LoadedRelations;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\RelationMapping;
use Antevemus\ASpecification\Repositories\Pdo\PdoRepository;
use InvalidArgumentException;

/**
 * PdoRelationRegistry - The Relationship Registry of PdoRepository (since 1.13.0, forward 031, D7)
 *
 * Since 2.11.0, when the class was split into traits (the methods are the ones of 2.10.0, unchanged):
 * the related repositories (withRelated()), the relations a view loads (with(), getEagerRelations()),
 * what the repository knows was loaded (isLoaded(), markLoaded(), markUnloaded()) and the helpers the
 * loading and the cascade share (related(), childKey(), collectionOf(), keyed(), keyOf(),
 * collectionRelations(), assertSameConnection()). The loads are in PdoRelationLoading, the writes of
 * the relations in PdoRelationCascade.
 *
 * - relationships (D7; #[ManyToOne] and #[OneToMany], or EntityMapping::manyToOne()/oneToMany()); the
 *   related repositories are given with withRelated() (a decorator such as TriggeringRepository is
 *   accepted: it reads through its PdoRepository and writes through the decorator). A relation that was not
 *   loaded keeps its "not loaded" value (the empty array, null) and the repository knows it was not
 *   loaded (isLoaded(), EntityMapping::isRelationLoaded(): a WeakMap outside the entity).
 * - markLoaded($entity, 'items', 'tags') declares a collection built by hand as loaded and WHOLE, so the
 *   next put() removes the stored children (or links) it lacks: an incomplete one deletes what it
 *   lacks; markUnloaded() makes a collection say nothing again (since 1.14.0, D2)
 *
 * The registry and the relations of with() are arrays of the repository, copied by value when a view
 * is made: a view keeps the registry of the moment it was made.
 *
 * Like every trait of this folder it holds no state and is used only by PdoRepository, whose private
 * properties it reads and, here, writes.
 *
 * @version    2.11.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\Pdo\Traits
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
trait PdoRelationRegistry
{
    /**
     * Registers the repositories of the related entities (since 1.13.0, forward 031, D7), one per
     * entity class (a later one replaces an earlier one of the same class). Each is a PdoRepository or
     * a decorator of one that exposes getInner() (TriggeringRepository): the relations are READ
     * through the PdoRepository and the cascaded children are WRITTEN through the repository given, so
     * a decorator sees (and publishes) them. A relation of an entity to its own class uses this
     * repository unless another one is registered (a cascade then writes through this very
     * repository: do not give it a non-reentrant synchronizer). The repository of a cascaded collection must use
     * the same PDO connection as this one: its writes join this repository's transaction (its
     * transaction manager must join an open transaction of the connection, as PdoTransactionManager
     * does); so must the repository of a #[ManyToMany] target, whose join table is on this connection
     * (since 1.14.0). Views made before this call (with(), withTrashed()) keep their own registry.
     *
     * @return static
     * @throws InvalidArgumentException When an item is not (a decorator of) a PdoRepository, or the
     *                                  repository of a cascaded collection or of an association is on
     *                                  another connection
     */
    public function withRelated(IRepository ...$repositories): static
    {
        foreach ($repositories as $repository) {
            $reader = $repository;
            for ($depth = 0; !$reader instanceof self && $depth < 8 && method_exists($reader, 'getInner'); $depth++) {
                $reader = $reader->getInner();
            }
            if (!$reader instanceof self) {
                throw new InvalidArgumentException(sprintf(
                    'withRelated() takes PdoRepository instances or decorators of one exposing getInner() (as TriggeringRepository); %s given.',
                    get_debug_type($repository)
                ));
            }
            $class = $reader->mapping->getEntityClass();
            foreach ([...$this->compositions, ...$this->associations] as $relation) {
                if ($relation->target === $class) {
                    $this->assertSameConnection($relation, $reader);
                }
            }
            $this->related[$class] = ['reader' => $reader, 'writer' => $repository];
        }

        return $this;
    }

    /**
     * A VIEW whose reads (find*, iterate*, findSingle, findPage, findById) also load the given
     * relations (since 1.13.0, forward 031, D7): `with('items', 'customer', 'items.product')`. For each
     * batch of withBatchSize() entities read, each relation costs ONE `SELECT ... WHERE fk IN (...)`
     * (a #[ManyToMany] two: the join table, then the targets; since 1.14.0) (more only past the
     * driver's parameter limit), never one per entity; the related repository's
     * read scope applies (its soft-removed rows are not loaded). A dotted path loads the relation of the
     * loaded entities, as deep as the relations go (each segment one more query per batch). The view
     * is a copy of this repository with its configuration of the moment; with() on a view adds paths.
     *
     * @param string ...$paths Relation property names, dotted for nested relations
     * @return static
     * @throws InvalidArgumentException When a segment is not a relationship of its entity, or its
     *                                  repository is not registered (withRelated())
     */
    public function with(string ...$paths): static
    {
        if ($paths === []) {
            throw new InvalidArgumentException('with() needs at least one relation path.');
        }
        $tree = $this->eager;
        foreach ($paths as $path) {
            $segments = explode('.', $path);
            $repository = $this;
            foreach ($segments as $segment) {
                $relation = $repository->mapping->getRelation($segment);
                if ($relation === null) {
                    throw new InvalidArgumentException(sprintf(
                        'Cannot load "%s": "%s" is not a relationship of %s.',
                        $path,
                        $segment,
                        $repository->mapping->getEntityClass()
                    ));
                }
                $related = $repository->related($relation, $this)['reader'];
                if ($relation->isOneToMany()) {
                    $repository->childKey($relation, $related);
                } elseif ($relation->isManyToMany()) {
                    $repository->assertSameConnection($relation, $related);
                } else {
                    $repository->mapping->getJoinColumn($relation->property);
                }
                $repository = $related;
            }
            $node = &$tree;
            foreach ($segments as $segment) {
                $node[$segment] ??= [];
                $node = &$node[$segment];
            }
            unset($node);
        }

        $view = clone $this;
        $view->eager = $tree;

        return $view;
    }

    /**
     * The relation paths the reads of this view load (since 1.13.0), dotted, in declaration order.
     *
     * @return list<string>
     */
    public function getEagerRelations(): array
    {
        $paths = [];
        $walk = static function (array $tree, string $prefix) use (&$walk, &$paths): void {
            foreach ($tree as $segment => $children) {
                $paths[] = $prefix . $segment;
                $walk($children, $prefix . $segment . '.');
            }
        };
        $walk($this->eager, '');

        return $paths;
    }

    /**
     * True when the relation of this entity OBJECT was loaded by a with() read, or written as a whole
     * by a cascade (since 1.13.0): its value is then the stored truth; otherwise it says nothing.
     *
     * @throws InvalidArgumentException When the relation is not a relationship of the mapping
     */
    public function isLoaded(IEntity $entity, string $relation): bool
    {
        if ($this->mapping->getRelation($relation) === null) {
            throw new InvalidArgumentException(sprintf('"%s" is not a relationship of %s.', $relation, $this->mapping->getEntityClass()));
        }

        return LoadedRelations::isLoaded($entity, $relation);
    }

    /**
     * Declares the collections of an entity built by hand (or kept since an earlier load) as LOADED,
     * with what they hold now as the WHOLE stored collection (since 1.14.0, forward 032, D2), for a
     * #[OneToMany] or a #[ManyToMany]. Its next put() then removes, relative to that collection, every
     * stored child it does not hold (a cascaded #[OneToMany]: orphan removal, each removal through the
     * related repository) or every link to a target it does not hold (#[ManyToMany], soft-removed
     * targets aside), and adds what it holds. A later load or write records the collection as loaded
     * again, with its usual safety.
     *
     * DANGER: marking an INCOMPLETE collection deletes what it lacks. A collection loaded by with()
     * only removes the children (or links) loaded with it, so a child added by someone else since the
     * load survives; a declared one does not have that protection: declare only a collection known to
     * be complete. The related repository must be registered (withRelated()).
     *
     * @param object $entity Entity of the mapped class
     * @param string ...$relations Collection properties (#[OneToMany] or #[ManyToMany])
     * @throws InvalidArgumentException When the entity is not of the mapped class, no relation is given,
     *                                  one is not a collection of the mapping, holds something else than
     *                                  its target, or its repository is not registered
     */
    public function markLoaded(object $entity, string ...$relations): void
    {
        foreach ($this->collectionRelations($entity, $relations, 'markLoaded') as $relation) {
            $reader = $this->related($relation)['reader'];
            LoadedRelations::markCollection($entity, $relation->property, $reader->keyed($this->collectionOf($entity, $relation)), true);
        }
    }

    /**
     * Forgets that collections of an entity were loaded (since 1.14.0, forward 032, D2): their value
     * says nothing about the database again, so the next put() removes no child and touches no link
     * (the children a #[OneToMany] composition holds are still upserted).
     *
     * @param object $entity Entity of the mapped class
     * @param string ...$relations Collection properties (#[OneToMany] or #[ManyToMany])
     * @throws InvalidArgumentException When the entity is not of the mapped class, no relation is given,
     *                                  or one is not a collection of the mapping
     */
    public function markUnloaded(object $entity, string ...$relations): void
    {
        foreach ($this->collectionRelations($entity, $relations, 'markUnloaded') as $relation) {
            LoadedRelations::forget($entity, $relation->property);
        }
    }

    /**
     * The relations given to markLoaded()/markUnloaded(), checked: collections of the mapping.
     *
     * @param list<string> $relations
     * @return list<RelationMapping>
     */
    private function collectionRelations(object $entity, array $relations, string $operation): array
    {
        $class = $this->mapping->getEntityClass();
        if (!$entity instanceof $class) {
            throw new InvalidArgumentException(sprintf('%s() takes a %s, %s given.', $operation, $class, get_debug_type($entity)));
        }
        if ($relations === []) {
            throw new InvalidArgumentException(sprintf('%s() needs at least one relation.', $operation));
        }
        $mapped = [];
        foreach ($relations as $name) {
            $relation = $this->mapping->getRelation($name);
            if ($relation === null || !$relation->isCollection()) {
                throw new InvalidArgumentException(sprintf('%s(): "%s" is not a OneToMany or ManyToMany collection of %s.', $operation, $name, $class));
            }
            $mapped[] = $relation;
        }

        return $mapped;
    }

    /**
     * The registered repositories of a relation's target: this repository's registry, then the root's
     * (eager loading), then this repository itself for a relation to its own class.
     *
     * @return array{reader: PdoRepository, writer: IRepository}
     * @throws InvalidArgumentException When no repository of the target is registered
     */
    private function related(RelationMapping $relation, ?PdoRepository $root = null): array
    {
        $entry = $this->related[$relation->target] ?? ($root?->related[$relation->target] ?? null);
        if ($entry !== null) {
            return $entry;
        }
        if ($relation->target === $this->mapping->getEntityClass()) {
            return ['reader' => $this, 'writer' => $this];
        }

        throw new InvalidArgumentException(sprintf(
            'No repository of %s is registered for the relationship "%s" of %s: give one to withRelated().',
            $relation->target,
            $relation->property,
            $this->mapping->getEntityClass()
        ));
    }

    /**
     * The foreign key column of a collection in the child mapping, and the child's back reference
     * property when mappedBy names one.
     *
     * @return array{column: ColumnMapping, back: ?string}
     * @throws EntityMappingException When mappedBy is neither a mapped property of the child nor its reference to this class
     */
    private function childKey(RelationMapping $relation, PdoRepository $child): array
    {
        $mappedBy = (string) $relation->mappedBy;
        $column = $child->mapping->getColumn($mappedBy);
        if ($column !== null) {
            return ['column' => $column, 'back' => null];
        }
        $back = $child->mapping->getRelation($mappedBy);
        if ($back !== null && $back->isManyToOne() && is_a($this->mapping->getEntityClass(), $back->target, true)) {
            return ['column' => $child->mapping->getJoinColumn($mappedBy), 'back' => $mappedBy];
        }

        throw new EntityMappingException(sprintf(
            'The mappedBy "%s" of the collection "%s" of %s is neither a mapped property of %s nor its ManyToOne reference to %s.',
            $mappedBy,
            $relation->property,
            $this->mapping->getEntityClass(),
            $child->mapping->getEntityClass(),
            $this->mapping->getEntityClass()
        ));
    }

    /**
     * @throws InvalidArgumentException When the repository of a cascaded collection is on another connection
     */
    private function assertSameConnection(RelationMapping $relation, PdoRepository $child): void
    {
        if ($child->pdo !== $this->pdo) {
            throw new InvalidArgumentException(sprintf(
                $relation->isManyToMany()
                    ? 'The association "%s" of %s goes through a join table of this connection, so the repository of %s must use the same PDO connection; it uses another one.'
                    : 'The collection "%s" of %s is cascaded inside one transaction, so the repository of %s must use the same PDO connection; it uses another one.',
                $relation->property,
                $this->mapping->getEntityClass(),
                $relation->target
            ));
        }
    }

    /**
     * Text key of a property value of a column (as bound): comparable with the text of identifierOf().
     */
    private function keyOf(ColumnMapping $column, mixed $value): string
    {
        return $value === null ? '' : (string) $this->converter->toDatabase($column, $value);
    }

    /**
     * Entities of this repository keyed by the text of their identifier (those that have one).
     *
     * @param list<IEntity> $entities
     * @return array<string, IEntity>
     */
    private function keyed(array $entities): array
    {
        $keyed = [];
        foreach ($entities as $entity) {
            $id = $this->identifierOf($entity);
            if ($id !== null) {
                $keyed[(string) $id] = $entity;
            }
        }

        return $keyed;
    }

    /**
     * The children a collection property holds, checked.
     *
     * @return list<IEntity>
     * @throws InvalidArgumentException When it is not an array (or traversable) of the target class
     */
    private function collectionOf(IEntity $parent, RelationMapping $relation): array
    {
        $value = $this->mapping->readValue($parent, $relation->property);
        if ($value === null) {
            return [];
        }
        if ($value instanceof \Traversable) {
            $value = iterator_to_array($value, false);
        }
        if (!is_array($value)) {
            throw new InvalidArgumentException(sprintf('The collection "%s" of %s holds %s, not an array.', $relation->property, $this->mapping->getEntityClass(), get_debug_type($value)));
        }
        foreach ($value as $child) {
            if (!$child instanceof $relation->target) {
                throw new InvalidArgumentException(sprintf(
                    'The collection "%s" of %s holds %s; only %s can be cascaded.',
                    $relation->property,
                    $this->mapping->getEntityClass(),
                    get_debug_type($child),
                    $relation->target
                ));
            }
        }

        return array_values($value);
    }

    // What this trait calls and PdoRepository or another of its traits implements

    abstract private function identifierOf(IEntity $entity): mixed;
}
