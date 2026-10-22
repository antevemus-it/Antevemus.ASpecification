<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Pdo\Traits;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Sql\ISqlPagination;
use Antevemus\ASpecification\Repositories\Pdo\InQuerySpecification;
use Antevemus\ASpecification\Repositories\Pdo\JoinTableHop;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\ColumnMapping;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\EntityMapping;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\LoadedRelations;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\RelationMapping;
use Antevemus\ASpecification\Repositories\Pdo\PdoRepository;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\PropertySpecification;
use Antevemus\ASpecification\Sql\SqlWhereClause;
use Generator;
use InvalidArgumentException;
use PDO;
use PDOStatement;

/**
 * PdoRelationLoading - The Loading of Relationships by PdoRepository (since 1.13.0, forward 031, D7)
 *
 * Since 2.11.0, when the class was split into traits (the methods are the ones of 2.10.0, unchanged):
 * the reads of a with() view (withRelationsLoaded(), pagedWithRelations()), the batched loads of each
 * kind of relation (loadRelations(), loadCollection(), loadReference(), loadAssociation(), linksOf())
 * and the filter through a relationship (whereRelation(), ownersLinkedTo()). Relations are registered
 * in PdoRelationRegistry.
 *
 * - No lazy loading, no proxy: with('items', 'customer', 'items.product') returns a VIEW whose reads load those
 *   relations for each batch of withBatchSize() entities with ONE `SELECT ... WHERE fk IN (...)` per
 *   relation and batch (more only when the IN list exceeds the driver's parameter limit), applying the
 *   related repository's read scope (its soft-removed rows stay out); paths go as deep as the
 *   relations go, each segment one more query per batch, each level resolved in the registry of its
 *   own repository, then in the one of the repository with() was called on. Since 1.14.0
 *   no relation query runs while a cursor is open: find*()/findPage() finish their read first, and
 *   iterate*() with relations pages by key (`id > :last ORDER BY id LIMIT batch`), each page read
 *   whole and its cursor closed before its relations load, so it works on MySQL unbuffered queries
 *   with the memory of one page; without relations iterate() still streams one cursor
 * - an association (#[ManyToMany], since 1.14.0, forward 032, D1; target repository on the same
 *   connection): with('tags') loads it in batch (`SELECT owner, target FROM join WHERE owner IN (...)`,
 *   then the targets by IN in their repository's read scope: a soft-removed target is left out, its
 *   link stays); its writes: PdoRelationCascade
 * - whereRelation('customer', $spec) / whereRelation('items', $spec) is the filter through a
 *   relationship: Spec::inQuery() on the foreign key (one statement; the foreign key property must be
 *   readable by a specification); whereRelation('tags', $spec) goes through the join table (since
 *   1.14.0): `id IN (SELECT owner FROM join WHERE target IN (SELECT id FROM tag WHERE ...))`
 *
 * Like every trait of this folder it holds no state and is used only by PdoRepository, whose private
 * properties it reads.
 *
 * @version    2.11.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\Pdo\Traits
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
trait PdoRelationLoading
{
    /**
     * The filter through a relationship (since 1.13.0, forward 031, D7), a Spec::inQuery() on the
     * foreign key, one statement over the same connection:
     * - a reference: `whereRelation('customer', $spec)` = the entities whose referenced entity
     *   satisfies $spec, `customer_id IN (SELECT id FROM customer WHERE ...)`
     * - a collection: `whereRelation('items', $spec)` = the entities with at least one child that
     *   satisfies $spec, `id IN (SELECT order_id FROM item WHERE ...)`
     * The read scope of the related repository applies (its soft-removed rows never match). Combine
     * it like any specification (and(), or(), Spec::not()); the foreign key property must be readable
     * by a specification (public, a public getter or #[Specifiable]).
     *
     * @throws InvalidArgumentException When the relation is not a relationship of the mapping, its
     *                                  repository is not registered, or the foreign key is not readable
     */
    public function whereRelation(string $relation, ISpecification $where): PropertySpecification
    {
        $mapped = $this->mapping->getRelation($relation);
        if ($mapped === null) {
            throw new InvalidArgumentException(sprintf('"%s" is not a relationship of %s.', $relation, $this->mapping->getEntityClass()));
        }
        $related = $this->related($mapped)['reader'];
        if ($mapped->isManyToOne()) {
            $foreignKey = $this->mapping->getJoinColumn($relation);
            $reason = null;
            if ($this->mapping->findQueryColumn($foreignKey->property, $reason) === null) {
                throw new InvalidArgumentException(sprintf('Cannot filter through "%s": %s.', $relation, (string) $reason));
            }

            return Spec::inQuery($foreignKey->property, $related, $where, EntityMapping::ENTITY_ID_ALIAS);
        }
        if ($mapped->isManyToMany()) {
            $hop = new JoinTableHop(
                $this,
                $relation,
                (string) $mapped->joinTable,
                (string) $mapped->joinColumn,
                (string) $mapped->inverseJoinColumn,
                fn(array $targets): array => $this->ownersLinkedTo($mapped, $related, $targets)
            );

            return Spec::property(EntityMapping::ENTITY_ID_ALIAS, new InQuerySpecification($related, $where, EntityMapping::ENTITY_ID_ALIAS, $hop));
        }

        return Spec::inQuery(EntityMapping::ENTITY_ID_ALIAS, $related, $where, $this->childKey($mapped, $related)['column']->property);
    }

    /**
     * The entities of a read already complete (its cursor closed), with the relations of this view
     * loaded per batch of withBatchSize() (since 1.14.0 the read is finished first, so no relation query
     * runs while a cursor is open: MySQL unbuffered queries allow one open statement per connection).
     *
     * @param list<T> $entities
     * @return list<T>
     */
    private function withRelationsLoaded(array $entities): array
    {
        if ($this->eager !== []) {
            foreach (array_chunk($entities, $this->batchSize) as $batch) {
                $this->loadRelations($batch, $this->eager, $this);
            }
        }

        return $entities;
    }

    /**
     * iterate() with relations (since 1.14.0, forward 032, D3): keyset pagination on the identifier,
     * `WHERE <filter> AND id > :last ORDER BY id LIMIT <batch + 1>`, each page fetched whole and its
     * cursor CLOSED before the relations of its entities load, then yielded. Works on every driver,
     * MySQL unbuffered queries included, and holds one page of entities (withBatchSize()) at a time.
     * The extra row only tells whether another page follows; it is read again by the next page. With a
     * specification evaluated in PHP (fallback), the pages are cut on the rows read and filtered after.
     *
     * @return Generator<int, T>
     */
    private function pagedWithRelations(?SqlWhereClause $where, ?ISpecification $memoryFilter): Generator
    {
        if (!$this->dialect instanceof ISqlPagination) {
            // No LIMIT in the dialect: the whole read first, then the relations by batch
            yield from $this->withRelationsLoaded(iterator_to_array($this->stream($where, $memoryFilter), false));
            return;
        }

        $idIndex = 0;
        foreach ($this->columnList as $i => $column) {
            if ($column->isId) {
                $idIndex = $i;
            }
        }
        $id = $this->quote($this->idColumn->column);
        $after = null;
        do {
            $page = $this->scoped($where);
            if ($after !== null) {
                $page = $page->and(new SqlWhereClause($id . ' > :ks_after', [':ks_after' => $after]));
            }
            $sql = $this->dialect->paginate($this->selectSql . $this->whereSql($page) . ' ORDER BY ' . $id . ' ASC', $this->batchSize + 1, null);
            $statement = $this->run($sql, $page->getParameters());
            try {
                $rows = $statement->fetchAll(PDO::FETCH_NUM);
            } finally {
                $statement->closeCursor();
            }
            $more = count($rows) > $this->batchSize;
            if ($more) {
                array_pop($rows);
            }
            $batch = [];
            foreach ($rows as $row) {
                $entity = $this->hydrate($row);
                if ($memoryFilter === null || $memoryFilter->isSatisfiedBy($entity)) {
                    $batch[] = $entity;
                }
            }
            if ($rows !== []) {
                $after = $this->rawBindable($rows[count($rows) - 1][$idIndex]);
            }
            $rows = [];
            if ($batch !== []) {
                $this->loadRelations($batch, $this->eager, $this);
                foreach ($batch as $entity) {
                    yield $entity;
                }
            }
            $batch = [];
        } while ($more);
    }

    /**
     * Loads a tree of relations for entities of this repository: per relation, one query per chunk of
     * the driver's parameter limit, then the subtree on the loaded entities.
     *
     * @param list<IEntity> $entities
     * @param array<string, array<string, mixed>> $tree
     * @param PdoRepository $root The repository with() was called on (fallback registry)
     */
    private function loadRelations(array $entities, array $tree, PdoRepository $root): void
    {
        if ($entities === []) {
            return;
        }
        foreach ($tree as $property => $subtree) {
            $relation = $this->mapping->getRelation((string) $property);
            if ($relation === null) {
                continue;   // validated by with()
            }
            $related = $this->related($relation, $root)['reader'];
            $loaded = match (true) {
                $relation->isOneToMany() => $this->loadCollection($entities, $relation, $related),
                $relation->isManyToMany() => $this->loadAssociation($entities, $relation, $related),
                default => $this->loadReference($entities, $relation, $related),
            };
            if ($subtree !== []) {
                $related->loadRelations($loaded, $subtree, $root);
            }
        }
    }

    /**
     * Loads a collection for every parent: `SELECT ... FROM child WHERE fk IN (parent ids)` in the
     * child repository's read scope, ordered by the child identifier; returns every child loaded.
     *
     * @param list<IEntity> $parents
     * @return list<IEntity>
     */
    private function loadCollection(array $parents, RelationMapping $relation, PdoRepository $child): array
    {
        ['column' => $foreignKey, 'back' => $back] = $this->childKey($relation, $child);
        $ids = [];
        foreach ($parents as $parent) {
            $id = $this->identifierOf($parent);
            if ($id !== null) {
                $ids[(string) $id] = $id;
            }
        }

        $byParent = [];
        $all = [];
        foreach (array_chunk(array_values($ids), max(1, $child->limits->maxParameters())) as $chunk) {
            foreach ($child->stream($child->inClause($foreignKey, $chunk), null) as $loaded) {
                $byParent[$child->keyOf($foreignKey, $child->mapping->readValue($loaded, $foreignKey->property))][] = $loaded;
                $all[] = $loaded;
            }
        }

        foreach ($parents as $parent) {
            $id = $this->identifierOf($parent);
            $children = $id === null ? [] : ($byParent[(string) $id] ?? []);
            $this->mapping->writeValue($parent, $relation->property, $children);
            if ($back !== null) {
                foreach ($children as $loaded) {
                    $child->mapping->writeValue($loaded, $back, $parent);
                    LoadedRelations::markReference($loaded, $back, true);
                }
            }
            LoadedRelations::markCollection($parent, $relation->property, $child->keyed($children));
        }

        return $all;
    }

    /**
     * Loads a reference for every entity: `SELECT ... FROM target WHERE id IN (foreign keys)` in the
     * target repository's read scope (a soft-removed target is not loaded: the reference stays null);
     * returns the distinct targets loaded.
     *
     * @param list<IEntity> $entities
     * @return list<IEntity>
     */
    private function loadReference(array $entities, RelationMapping $relation, PdoRepository $target): array
    {
        $foreignKey = $this->mapping->getJoinColumn($relation->property);
        $targetId = $target->idColumn;
        $values = [];
        foreach ($entities as $entity) {
            $value = $this->mapping->readValue($entity, $foreignKey->property);
            if ($value !== null) {
                $bindable = $target->converter->toDatabase($targetId, $value);
                $values[(string) $bindable] = $bindable;
            }
        }

        $found = [];
        foreach (array_chunk(array_values($values), max(1, $target->limits->maxParameters())) as $chunk) {
            foreach ($target->stream($target->inClause($targetId, $chunk), null) as $loaded) {
                $found[(string) $target->identifierOf($loaded)] = $loaded;
            }
        }

        foreach ($entities as $entity) {
            $value = $this->mapping->readValue($entity, $foreignKey->property);
            $loaded = $value === null ? null : ($found[(string) $target->converter->toDatabase($targetId, $value)] ?? null);
            $this->mapping->writeValue($entity, $relation->property, $loaded);
            LoadedRelations::markReference($entity, $relation->property, $loaded !== null);
        }

        return array_values($found);
    }

    /**
     * Loads an association for every owner (since 1.14.0, forward 032, D1): `SELECT owner, target FROM
     * join WHERE owner IN (owner ids)`, then `SELECT ... FROM target WHERE id IN (linked ids)` in the
     * target repository's read scope (a soft-removed target is left out; its link stays), one query of
     * each per chunk of the driver's parameter limit; each collection in the order of the target
     * identifier. Returns the distinct targets loaded.
     *
     * @param list<IEntity> $owners
     * @return list<IEntity>
     */
    private function loadAssociation(array $owners, RelationMapping $relation, PdoRepository $target): array
    {
        $ids = [];
        foreach ($owners as $owner) {
            $id = $this->identifierOf($owner);
            if ($id !== null) {
                $ids[(string) $id] = $id;
            }
        }

        $links = [];
        $targetIds = [];
        foreach ($this->linksOf($relation, $target, array_values($ids), true) as $ownerKey => $linked) {
            foreach ($linked as $targetKey => $targetId) {
                $links[$ownerKey][] = $targetKey;
                $targetIds[$targetKey] = $targetId;
            }
        }

        $found = [];
        foreach (array_chunk(array_values($targetIds), max(1, $target->limits->maxParameters())) as $chunk) {
            foreach ($target->stream($target->inClause($target->idColumn, $chunk), null) as $loaded) {
                $found[(string) $target->identifierOf($loaded)] = $loaded;
            }
        }

        foreach ($owners as $owner) {
            $id = $this->identifierOf($owner);
            $children = [];
            foreach ($id === null ? [] : ($links[(string) $id] ?? []) as $targetKey) {
                if (isset($found[$targetKey])) {
                    $children[] = $found[$targetKey];
                }
            }
            $this->mapping->writeValue($owner, $relation->property, $children);
            LoadedRelations::markCollection($owner, $relation->property, $target->keyed($children));
        }

        return array_values($found);
    }

    /**
     * The links of some owners in the join table of an association: owner identifier text => (target
     * identifier text => bindable target identifier). One statement per chunk of the parameter limit.
     *
     * @param list<mixed> $ownerIds Bindable owner identifiers
     * @param bool $ordered True = ordered by owner and target (a load); false = any order (a sync)
     * @return array<string, array<string, mixed>>
     */
    private function linksOf(RelationMapping $relation, PdoRepository $target, array $ownerIds, bool $ordered = false): array
    {
        $owner = $this->quote((string) $relation->joinColumn);
        $inverse = $this->quote((string) $relation->inverseJoinColumn);
        $head = 'SELECT ' . $owner . ', ' . $inverse . ' FROM ' . $this->quote((string) $relation->joinTable) . ' WHERE ' . $owner;
        $links = [];
        foreach (array_chunk($ownerIds, max(1, $this->limits->maxParameters())) as $chunk) {
            $sql = $head . (count($chunk) === 1 ? ' = ?' : ' IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')')
                . ($ordered ? ' ORDER BY ' . $owner . ', ' . $inverse : '');
            $statement = $this->run($sql, array_values($chunk));
            try {
                while (($row = $statement->fetch(PDO::FETCH_NUM)) !== false) {
                    $targetId = $target->rawBindable($row[1]);
                    $links[(string) $this->rawBindable($row[0])][(string) $targetId] = $targetId;
                }
            } finally {
                $statement->closeCursor();
            }
        }

        return $links;
    }

    /**
     * The identifiers (property values) of the owners linked to some targets in the join table of an
     * association: the in-memory side of whereRelation() on a #[ManyToMany] (since 1.14.0).
     *
     * @param list<mixed> $targetValues Identifier values of the targets (property values)
     * @return list<mixed>
     */
    private function ownersLinkedTo(RelationMapping $relation, PdoRepository $target, array $targetValues): array
    {
        $bindable = [];
        foreach ($targetValues as $value) {
            $id = $target->converter->toDatabase($target->idColumn, $value);
            $bindable[(string) $id] = $id;
        }
        $owner = $this->quote((string) $relation->joinColumn);
        $inverse = $this->quote((string) $relation->inverseJoinColumn);
        $owners = [];
        foreach (array_chunk(array_values($bindable), max(1, $this->limits->maxParameters())) as $chunk) {
            $statement = $this->run(
                'SELECT DISTINCT ' . $owner . ' FROM ' . $this->quote((string) $relation->joinTable) . ' WHERE ' . $inverse
                    . ' IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')',
                array_values($chunk)
            );
            try {
                while (($row = $statement->fetch(PDO::FETCH_NUM)) !== false) {
                    $owners[(string) $this->rawBindable($row[0])] = $this->converter->fromDatabase($this->idColumn, $row[0]);
                }
            } finally {
                $statement->closeCursor();
            }
        }

        return array_values($owners);
    }

    /**
     * The bindable form of an identifier value as fetched from the driver (a join table, a key column),
     * the same as identifierOf() gives for an entity with that identifier.
     */
    private function rawBindable(mixed $raw): mixed
    {
        return $this->converter->toDatabase($this->idColumn, $this->converter->fromDatabase($this->idColumn, $raw));
    }

    // What this trait calls and PdoRepository or another of its traits implements

    abstract private function hydrate(array $row): IEntity;

    abstract private function scoped(?SqlWhereClause $where, ?string $scope = null): SqlWhereClause;

    abstract private function stream(?SqlWhereClause $where, ?ISpecification $memoryFilter, ?int $limit = null, ?int $offset = null, array $orderBy = [], ?string $scope = null): Generator;

    abstract private function whereSql(SqlWhereClause $where): string;

    abstract private function related(RelationMapping $relation, ?PdoRepository $root = null): array;

    abstract private function childKey(RelationMapping $relation, PdoRepository $child): array;

    abstract private function inClause(ColumnMapping $column, array $values, ?string $prefix = null): SqlWhereClause;

    abstract private function keyOf(ColumnMapping $column, mixed $value): string;

    abstract private function keyed(array $entities): array;

    abstract private function identifierOf(IEntity $entity): mixed;

    abstract private function quote(string $identifier): string;

    abstract private function run(string $sql, array $params = []): PDOStatement;
}
