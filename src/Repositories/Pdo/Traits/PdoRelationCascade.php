<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Pdo\Traits;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Repositories\IRepository;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\ColumnMapping;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\LoadedRelations;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\RelationMapping;
use Antevemus\ASpecification\Repositories\Pdo\PdoRepository;
use Antevemus\ASpecification\Sql\SqlWhereClause;
use DateTimeImmutable;
use Generator;
use InvalidArgumentException;
use PDO;
use PDOStatement;

/**
 * PdoRelationCascade - The Writes of Relationships by PdoRepository (since 1.13.0, forward 031, D7)
 *
 * Since 2.11.0, when the class was split into traits (the methods are the ones of 2.10.0, unchanged):
 * what a write or a removal of an owner does to its relations: the foreign keys of the references
 * (syncReferences(), markReferences()), the children of the compositions (cascadeSave(),
 * removeOrphans(), cascadeRemove()) and the join table of the associations (assertLinkable(),
 * syncLinks(), deleteLinks(), visibleKeys()). PdoWriteOperations and PdoRemoveOperations call it; it
 * writes the children through the related repositories (their insertAll() and removeMatching()).
 *
 * - a reference (ManyToOne) is never cascaded: put() writes the identifier of the referenced object
 *   into the foreign key property (an object without identifier is refused: put it first); a null
 *   reference clears the foreign key only when it was loaded non-null, never when it was not loaded
 * - a composition (OneToMany with cascade, same connection, inside the parent's transaction): put(),
 *   putAll() and insertAll() write the children the collection holds after their parents (foreign
 *   key, and the child's back reference, set to the parent), then run afterPut. When the collection
 *   was loaded (or written by this repository as a whole: the parent was inserted), the children that
 *   left it are removed (orphan removal, before the upsert): only children that still belong to the
 *   parent in the database and that were loaded with it (a child added by someone else since is
 *   never removed), each with the object loaded (its version). A collection that was NOT loaded is
 *   never taken as empty: its children are upserted, nothing is removed. remove() of a parent removes
 *   its children first; a soft removal marks the children that have #[SoftDelete] and KEEPS the others
 *   (the parent's row stays, a revival brings them back); a hard removal (forceRemove(), or a parent
 *   without #[SoftDelete]) deletes every child row, marked or not, since no child row may outlive its
 *   parent's. removeAll*() of a parent with compositions loads the matching parents (as with hooks).
 *   Children are written through the registered repository: a TriggeringRepository publishes their
 *   entries and exits (each cascaded removal of a live child is a remove() through it), a bare
 *   PdoRepository publishes nothing (a TriggerSweeper notices the exits). A TriggeringRepository
 *   over the PARENT reads its "before" without relations: its triggers should read columns. Moving a
 *   child between two loaded parents is safe within ONE putAll() (a child present in any collection
 *   of the call is never an orphan)
 * - an association (#[ManyToMany], since 1.14.0; its loading: PdoRelationLoading):
 *   put()/putAll()/insertAll() of an owner whose collection was LOADED (or that was just
 *   inserted) synchronize only the join table (links added: one multi-row INSERT; links of targets that
 *   left the collection: `DELETE ... WHERE (owner = ? AND target = ?) OR ...`), never writing or
 *   deleting a target, and a target without identifier is refused; a collection that was NOT loaded
 *   never touches the join table. A hard removal of an owner deletes its links first; a soft removal
 *   keeps them. Link changes do not change the owner's row: a TriggeringRepository over the owner sees
 *   no crossing from them
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
trait PdoRelationCascade
{
    /**
     * Before a write: the foreign key of each reference follows the referenced object when there is
     * one (which must have an identifier: a reference is never cascaded), and is cleared when the
     * reference was loaded non-null and is now null; otherwise it is left as it is.
     *
     * @throws InvalidArgumentException When the referenced object is of another class or has no identifier
     */
    private function syncReferences(IEntity $entity): void
    {
        foreach ($this->references as $relation) {
            $foreignKey = $this->mapping->getJoinColumn($relation->property);
            $target = $this->mapping->readValue($entity, $relation->property);
            if ($target !== null) {
                if (!$target instanceof $relation->target) {
                    throw new InvalidArgumentException(sprintf('The reference "%s" of %s holds %s, not a %s.', $relation->property, $this->mapping->getEntityClass(), get_debug_type($target), $relation->target));
                }
                $id = $target->getEntityId();
                if ($id === null) {
                    throw new InvalidArgumentException(sprintf(
                        'The %s referenced by "%s" of %s has no identifier yet: put it first (a ManyToOne reference is never cascaded).',
                        $relation->target,
                        $relation->property,
                        $this->mapping->getEntityClass()
                    ));
                }
                $current = $this->mapping->readValue($entity, $foreignKey->property);
                if ($current === null || $this->keyOf($foreignKey, $current) !== $this->keyOf($foreignKey, $id)) {
                    $this->mapping->writeValue($entity, $foreignKey->property, $id);
                }
            } elseif (LoadedRelations::wasPresent($entity, $relation->property)) {
                // Loaded non-null, now null: the reference was cleared
                $this->mapping->writeValue($entity, $foreignKey->property, null);
            }
        }
    }

    /**
     * After a write: a reference holding an object is known (loaded); a cleared one is known empty.
     */
    private function markReferences(IEntity $entity): void
    {
        foreach ($this->references as $relation) {
            $target = $this->mapping->readValue($entity, $relation->property);
            if ($target !== null || LoadedRelations::isLoaded($entity, $relation->property)) {
                LoadedRelations::markReference($entity, $relation->property, $target !== null);
            }
        }
    }

    /**
     * After the parents of a batch were written (inside the transaction): for each cascaded collection,
     * the foreign key (and back reference) of the children set, the orphans of the LOADED collections
     * removed, the children upserted (inserted by insertAll()), and the collection marked loaded when
     * it was loaded or its parent was inserted by this call.
     *
     * @param list<IEntity> $parents
     * @param array<int, true> $inserted Parents inserted by this call, by object id
     * @param bool $insertOnly True from insertAll(): the children are new as well
     */
    private function cascadeSave(array $parents, array $inserted, bool $insertOnly): void
    {
        foreach ($this->compositions as $relation) {
            $collected = [];
            foreach ($parents as $parent) {
                $children = $this->collectionOf($parent, $relation);
                $loaded = LoadedRelations::isLoaded($parent, $relation->property);
                if ($children === [] && !$loaded && !isset($inserted[spl_object_id($parent)])) {
                    continue;   // nothing known, nothing to write
                }
                $collected[] = ['parent' => $parent, 'children' => $children, 'loaded' => $loaded];
            }
            if ($collected === []) {
                continue;
            }
            $writes = array_filter($collected, static fn(array $entry): bool => $entry['children'] !== [] || $entry['loaded']);
            if ($writes === []) {
                // New parents with an empty collection: known to have no children, nothing to write
                foreach ($collected as $entry) {
                    LoadedRelations::markCollection($entry['parent'], $relation->property, []);
                }
                continue;
            }
            ['reader' => $reader, 'writer' => $writer] = $this->related($relation);
            $this->assertSameConnection($relation, $reader);
            ['column' => $foreignKey, 'back' => $back] = $this->childKey($relation, $reader);

            $upsert = [];
            $kept = [];
            foreach ($collected as $entry) {
                $parentId = $this->mapping->readValue($entry['parent'], $this->idColumn->property);
                foreach ($entry['children'] as $child) {
                    $reader->mapping->writeValue($child, $foreignKey->property, $parentId);
                    if ($back !== null) {
                        $reader->mapping->writeValue($child, $back, $entry['parent']);
                        LoadedRelations::markReference($child, $back, true);
                    }
                    $upsert[spl_object_id($child)] = $child;
                    $childId = $reader->identifierOf($child);
                    if ($childId !== null) {
                        $kept[(string) $childId] = true;
                    }
                }
            }

            $orphanParents = [];
            foreach ($collected as $entry) {
                if (!$entry['loaded']) {
                    continue;   // a collection that was not loaded is never taken as empty: no orphan
                }
                $id = $this->identifierOf($entry['parent']);
                if ($id !== null) {
                    $orphanParents[(string) $id] = ['id' => $id, 'parent' => $entry['parent']];
                }
            }
            if ($orphanParents !== []) {
                $this->removeOrphans($relation, $reader, $writer, $foreignKey, $orphanParents, $kept);
            }

            if ($upsert !== []) {
                if ($insertOnly && $writer === $reader) {
                    $reader->insertAll(array_values($upsert));
                } else {
                    $writer->putAll(array_values($upsert));
                }
            }

            foreach ($collected as $entry) {
                if ($entry['loaded'] || isset($inserted[spl_object_id($entry['parent'])])) {
                    LoadedRelations::markCollection($entry['parent'], $relation->property, $reader->keyed($entry['children']));
                }
            }
        }
    }

    /**
     * Orphan removal: the live children that still belong to the parents in the database (one query
     * per chunk), are no longer in any collection of the call, and were loaded with their parent (a
     * child added since the load is not this collection's to remove); each removed through the
     * registered repository, with the object that was loaded when it is still alive (its version).
     *
     * @param array<string, array{id: mixed, parent: IEntity}> $parents Loaded parents, by identifier text
     * @param array<string, true> $kept Identifier texts of the children present in a collection of the call
     */
    private function removeOrphans(RelationMapping $relation, PdoRepository $reader, IRepository $writer, ColumnMapping $foreignKey, array $parents, array $kept): void
    {
        $orphans = [];
        $ids = array_map(static fn(array $parent): mixed => $parent['id'], array_values($parents));
        foreach (array_chunk($ids, max(1, $reader->limits->maxParameters())) as $chunk) {
            foreach ($reader->stream($reader->inClause($foreignKey, $chunk), null, null, null, [], self::SCOPE_LIVE) as $stored) {
                $childKey = (string) $reader->identifierOf($stored);
                if (isset($kept[$childKey])) {
                    continue;
                }
                $parent = $parents[$reader->keyOf($foreignKey, $reader->mapping->readValue($stored, $foreignKey->property))]['parent'] ?? null;
                if ($parent === null) {
                    continue;
                }
                $loaded = LoadedRelations::loadedChildren($parent, $relation->property);
                if ($loaded !== null && !array_key_exists($childKey, $loaded) && !LoadedRelations::isDeclared($parent, $relation->property)) {
                    continue;   // added by someone else after the load (a collection declared whole by markLoaded() has no such child)
                }
                $orphans[] = $loaded[$childKey] ?? $stored;
            }
        }
        foreach ($orphans as $orphan) {
            $writer->remove($orphan);
        }
    }

    /**
     * Removes the composed children of parents, before the parents' rows (inside the transaction).
     * Soft ($hard false): the children with #[SoftDelete] are marked, the others kept with the
     * parent's row. Hard: every child row is deleted, marked or not. Through a decorator, each live
     * child is removed by its remove() (its exit is published), then, when hard, the rest is deleted.
     *
     * @param list<mixed> $parentIds Bindable identifiers of the parents
     */
    private function cascadeRemove(array $parentIds, bool $hard): void
    {
        if ($parentIds === []) {
            return;
        }
        if ($hard) {
            $this->deleteLinks($parentIds);
        }
        foreach ($this->compositions as $relation) {
            ['reader' => $reader, 'writer' => $writer] = $this->related($relation);
            $this->assertSameConnection($relation, $reader);
            if (!$hard && $reader->softDelete === null) {
                continue;   // the parent's row stays: its children without soft delete stay with it
            }
            $foreignKey = $this->childKey($relation, $reader)['column'];
            foreach (array_chunk($parentIds, self::DELETE_CHUNK) as $chunk) {
                // Named: the removal combines it with the named parameters of its SET
                $where = $reader->inClause($foreignKey, $chunk, 'fk');
                if ($writer !== $reader) {
                    $victims = iterator_to_array($reader->stream($where, null, null, null, [], self::SCOPE_LIVE), false);
                    foreach ($victims as $victim) {
                        $writer->remove($victim);
                    }
                    if (!$hard) {
                        continue;
                    }
                }
                $reader->writeExclusively(fn(): int => $reader->removeMatching($where, null, $hard, $reader->now()));
            }
        }
    }

    /**
     * Before a write: every target of an association that will be synchronized must have an identifier
     * (an association never writes its targets). Checked for the collections that were loaded and for
     * the owners without an identifier yet (new).
     *
     * @param list<IEntity> $batch
     * @throws InvalidArgumentException When a target is of another class or has no identifier
     */
    private function assertLinkable(array $batch): void
    {
        foreach ($this->associations as $relation) {
            foreach ($batch as $owner) {
                if (!LoadedRelations::isLoaded($owner, $relation->property) && $this->identifierOf($owner) !== null) {
                    continue;
                }
                foreach ($this->collectionOf($owner, $relation) as $target) {
                    if ($target->getEntityId() === null) {
                        throw new InvalidArgumentException(sprintf(
                            'The %s linked by "%s" of %s has no identifier yet: put it first (a ManyToMany association never writes its targets).',
                            $relation->target,
                            $relation->property,
                            $this->mapping->getEntityClass()
                        ));
                    }
                }
            }
        }
    }

    /**
     * After the owners of a batch were written (inside the transaction): for each association, the
     * join table is synchronized with the collections that were loaded (or whose owner was inserted by
     * this call): one read of the stored links of those owners per chunk, then the links of the targets
     * added are inserted (multi-row) and the links of the targets that left the collection deleted (one
     * `DELETE ... WHERE (owner = ? AND target = ?) OR ...` per chunk of links). A
     * collection loaded by with() unlinks only the targets loaded with it; one declared by markLoaded()
     * unlinks every stored link to a target the target repository sees (a soft-removed target keeps its
     * link). A collection that was not loaded is skipped: the join table is not touched. No target row
     * is ever written or deleted.
     *
     * @param list<IEntity> $batch
     * @param array<int, true> $inserted Owners inserted by this call, by object id
     */
    private function syncLinks(array $batch, array $inserted): void
    {
        foreach ($this->associations as $relation) {
            $plan = [];
            $ownerIds = [];
            $reader = null;
            foreach ($batch as $owner) {
                $loaded = LoadedRelations::isLoaded($owner, $relation->property);
                if (!$loaded && !isset($inserted[spl_object_id($owner)])) {
                    continue;   // a collection that was not loaded never touches the join table
                }
                $targets = $this->collectionOf($owner, $relation);
                if (!$loaded && $targets === []) {
                    // A new owner without targets: known to have no link, nothing to write
                    LoadedRelations::markCollection($owner, $relation->property, []);
                    continue;
                }
                $reader ??= $this->related($relation)['reader'];
                $ownerId = $this->identifierOf($owner);
                $current = [];
                foreach ($targets as $target) {
                    $targetId = $reader->identifierOf($target);
                    if ($targetId === null) {
                        throw new InvalidArgumentException(sprintf(
                            'The %s linked by "%s" of %s has no identifier yet: put it first (a ManyToMany association never writes its targets).',
                            $relation->target,
                            $relation->property,
                            $this->mapping->getEntityClass()
                        ));
                    }
                    $current[(string) $targetId] = $targetId;
                }
                $plan[] = [
                    'owner' => $owner,
                    'id' => $ownerId,
                    'targets' => $targets,
                    'current' => $current,
                    'baseline' => $loaded ? (LoadedRelations::loadedChildren($owner, $relation->property) ?? []) : null,
                    'declared' => $loaded && LoadedRelations::isDeclared($owner, $relation->property),
                ];
                $ownerIds[(string) $ownerId] = $ownerId;
            }
            if ($plan === [] || $reader === null) {
                continue;
            }
            $this->assertSameConnection($relation, $reader);

            $stored = $this->linksOf($relation, $reader, array_values($ownerIds));
            $inserts = [];
            $deletes = [];
            $toCheck = [];
            foreach ($plan as $entry) {
                $key = (string) $entry['id'];
                $links = $stored[$key] ?? [];
                if ($entry['baseline'] !== null) {
                    $gone = array_diff_key($links, $entry['current']);
                    if (!$entry['declared']) {
                        $gone = array_intersect_key($gone, $entry['baseline']);   // only the targets loaded with it
                    } else {
                        $toCheck += $gone;
                    }
                    if ($gone !== []) {
                        $deletes[$key] = ['id' => $entry['id'], 'targets' => $gone, 'declared' => $entry['declared']];
                    }
                }
                foreach ($entry['current'] as $targetKey => $targetId) {
                    if (!isset($links[$targetKey])) {
                        $inserts[] = [$entry['id'], $targetId];
                        $links[$targetKey] = $targetId;
                    }
                }
                $stored[$key] = $links;
            }
            if ($toCheck !== []) {
                // A declared collection speaks for the targets a read sees: a soft-removed one keeps its link
                $visible = $reader->visibleKeys(array_values($toCheck));
                foreach ($deletes as $key => $delete) {
                    if ($delete['declared']) {
                        $deletes[$key]['targets'] = array_intersect_key($delete['targets'], $visible);
                    }
                }
            }

            $table = $this->quote((string) $relation->joinTable);
            $ownerColumn = $this->quote((string) $relation->joinColumn);
            $targetColumn = $this->quote((string) $relation->inverseJoinColumn);
            $pairs = [];
            foreach ($deletes as $delete) {
                foreach ($delete['targets'] as $targetId) {
                    $pairs[] = [$delete['id'], $targetId];
                }
            }
            // One statement per chunk of links, whatever the number of owners
            foreach (array_chunk($pairs, max(1, min(self::DELETE_CHUNK, intdiv($this->limits->maxParameters(), 2)))) as $chunk) {
                $this->run(
                    'DELETE FROM ' . $table . ' WHERE '
                    . implode(' OR ', array_fill(0, count($chunk), '(' . $ownerColumn . ' = ? AND ' . $targetColumn . ' = ?)')),
                    array_merge(...$chunk)
                );
            }
            if ($inserts !== []) {
                $perStatement = $this->limits->multiRowInsert() ? max(1, min($this->batchSize, intdiv($this->limits->maxParameters(), 2))) : 1;
                if (in_array($this->family, ['sqlsrv', 'mssql', 'dblib'], true)) {
                    $perStatement = min($perStatement, 1000);
                }
                foreach (array_chunk($inserts, $perStatement) as $chunk) {
                    $this->run(
                        'INSERT INTO ' . $table . ' (' . $ownerColumn . ', ' . $targetColumn . ') VALUES '
                        . implode(', ', array_fill(0, count($chunk), '(?, ?)')),
                        array_merge(...$chunk)
                    );
                }
            }

            foreach ($plan as $entry) {
                LoadedRelations::markCollection($entry['owner'], $relation->property, $reader->keyed($entry['targets']));
            }
        }
    }

    /**
     * Deletes every link of some owners from the join tables of their associations (a hard removal of
     * the owners; since 1.14.0). Positional placeholders, one statement per chunk.
     *
     * @param list<mixed> $ownerIds Bindable owner identifiers
     */
    private function deleteLinks(array $ownerIds): void
    {
        foreach ($this->associations as $relation) {
            foreach (array_chunk($ownerIds, self::DELETE_CHUNK) as $chunk) {
                $this->run(
                    'DELETE FROM ' . $this->quote((string) $relation->joinTable) . ' WHERE ' . $this->quote((string) $relation->joinColumn)
                    . (count($chunk) === 1 ? ' = ?' : ' IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')'),
                    array_values($chunk)
                );
            }
        }
    }

    /**
     * The identifier texts of the rows the reads of this repository see among some identifiers (since
     * 1.14.0: the targets a declared association may unlink).
     *
     * @param list<mixed> $ids Bindable identifiers
     * @return array<string, true>
     */
    private function visibleKeys(array $ids): array
    {
        $visible = [];
        foreach (array_chunk($ids, max(1, $this->limits->maxParameters())) as $chunk) {
            $where = $this->scoped($this->inClause($this->idColumn, $chunk));
            $statement = $this->run('SELECT ' . $this->quote($this->idColumn->column) . ' FROM ' . $this->tableSql . $this->whereSql($where), $where->getParameters());
            try {
                while (($row = $statement->fetch(PDO::FETCH_NUM)) !== false) {
                    $visible[(string) $this->rawBindable($row[0])] = true;
                }
            } finally {
                $statement->closeCursor();
            }
        }

        return $visible;
    }

    // What this trait calls and PdoRepository or another of its traits implements

    abstract private function insertAll(array $entities): int;

    abstract private function scoped(?SqlWhereClause $where, ?string $scope = null): SqlWhereClause;

    abstract private function stream(?SqlWhereClause $where, ?ISpecification $memoryFilter, ?int $limit = null, ?int $offset = null, array $orderBy = [], ?string $scope = null): Generator;

    abstract private function whereSql(SqlWhereClause $where): string;

    abstract private function linksOf(RelationMapping $relation, PdoRepository $target, array $ownerIds, bool $ordered = false): array;

    abstract private function rawBindable(mixed $raw): mixed;

    abstract private function related(RelationMapping $relation, ?PdoRepository $root = null): array;

    abstract private function childKey(RelationMapping $relation, PdoRepository $child): array;

    abstract private function assertSameConnection(RelationMapping $relation, PdoRepository $child): void;

    abstract private function inClause(ColumnMapping $column, array $values, ?string $prefix = null): SqlWhereClause;

    abstract private function keyOf(ColumnMapping $column, mixed $value): string;

    abstract private function keyed(array $entities): array;

    abstract private function collectionOf(IEntity $parent, RelationMapping $relation): array;

    abstract private function now(): DateTimeImmutable;

    abstract private function removeMatching(?SqlWhereClause $where, ?ISpecification $memoryFilter, bool $hard, DateTimeImmutable $now): int;

    abstract private function identifierOf(IEntity $entity): mixed;

    abstract private function quote(string $identifier): string;

    abstract private function run(string $sql, array $params = []): PDOStatement;
}
