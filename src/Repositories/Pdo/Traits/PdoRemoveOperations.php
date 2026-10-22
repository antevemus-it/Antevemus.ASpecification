<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Pdo\Traits;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Repositories\Pdo\Exceptions\OptimisticLockException;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\ColumnMapping;
use Antevemus\ASpecification\Sql\SqlWhereClause;
use DateTimeImmutable;
use DateTimeInterface;
use Generator;
use PDOStatement;

/**
 * PdoRemoveOperations - The Removals of PdoRepository
 *
 * Since 2.11.0, when the class was split into traits (the methods are the ones of 2.10.0, unchanged):
 * remove(), forceRemove() and removeAllEntitiesSpecifiedBy(), with the removal of one entity
 * (removeEntity(), deleteRow(), markRow()), of the matching rows (removeMatching()) and the SET of a
 * soft removal (softRemovalSets(), stampSoftRemoval()). A removal runs the hooks (PdoLifecycleHooks)
 * and the removals of the relations (PdoRelationCascade). Removals are writes (PdoWriteOperations):
 * each in a transaction of the ITransactionManager (joining an open one), checking the #[Version]
 * counter the entity carries.
 *
 * - remove() deletes by identifier; removeAll*() is one compiled `DELETE ... WHERE`
 * - soft delete (D4, #[SoftDelete]): remove() and removeAll*() write the instant into the column
 *   instead of deleting (and increment the version counter, as any write of the row); every read
 *   (find, iterate, count, findSingle, findPage, findById, contains) leaves the marked rows out, so a
 *   soft-removed entity is removed for every reader: TriggeringRepository publishes its exit and
 *   TriggerSweeper sees it gone. The views that see the marked rows: PdoConfiguration. forceRemove()
 *   deletes the row for real. The revival of a marked row by put(): PdoWriteOperations.
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
trait PdoRemoveOperations
{
    /**
     * {@inheritdoc}
     *
     * With #[SoftDelete] the row is marked (only a live row: a marked one gives false), otherwise
     * deleted. Hooks beforeRemove (always) and afterRemove (when removed) run in the transaction.
     */
    public function remove(IEntity $entity): bool
    {
        return $this->removeEntity($entity, $this->softDelete === null);
    }

    /**
     * Deletes the row of the entity for real, soft-removed or not (since 1.13.0, forward 031, D4).
     * Without #[SoftDelete] it is remove(). The version is checked as by remove(); the hooks run.
     */
    public function forceRemove(IEntity $entity): bool
    {
        return $this->removeEntity($entity, true);
    }

    /**
     * {@inheritdoc}
     *
     * With #[SoftDelete], marks the matching live rows. With remove hooks, cascaded collections (since
     * 1.13.0) or an in-memory evaluated specification the matching entities are loaded, the hooks run
     * for each, their children are removed, and they are removed by identifier; otherwise it is one
     * compiled statement.
     */
    public function removeAllEntitiesSpecifiedBy(ISpecification $specification): int
    {
        $this->validateSpecification($specification);

        return $this->writeExclusively(function () use ($specification): int {
            $where = $this->compileOrFallback($specification);

            return $this->transactions->transactional(
                fn(): int => $this->removeMatching($where, $where === null ? $specification : null, false, $this->now())
            );
        });
    }

    /**
     * Removes the rows matching a clause (or the entities a specification evaluated in PHP accepts),
     * inside the caller's transaction: removeAll*(), and the cascade of a parent's removal into its
     * children. Soft (marks live rows) when the mapping has #[SoftDelete] and $hard is false; hard
     * (deletes every matching row, marked or not) otherwise. One statement without hooks nor cascaded
     * collections; else the entities are loaded, beforeRemove runs for each, their own children are
     * removed, then their rows, then afterRemove.
     */
    private function removeMatching(?SqlWhereClause $where, ?ISpecification $memoryFilter, bool $hard, DateTimeImmutable $now): int
    {
        $soft = $this->softDelete !== null && !$hard;
        if ($soft && $this->scope === self::SCOPE_ONLY_TRASHED) {
            return 0;   // removals only mark live rows: none is visible here
        }
        // A hard removal of a parent reaches every row; a removal by the caller, only the live ones
        $scope = $hard ? self::SCOPE_WITH_TRASHED : self::SCOPE_LIVE;

        if ($where !== null && $this->hooks['beforeRemove'] === [] && $this->hooks['afterRemove'] === [] && $this->compositions === []) {
            $where = $this->scoped($where, $scope);
            if (!$soft && $this->associations !== []) {
                // The links first (no link may outlive its owner's row), by the same filter
                foreach ($this->associations as $relation) {
                    $this->run(
                        'DELETE FROM ' . $this->quote((string) $relation->joinTable) . ' WHERE ' . $this->quote((string) $relation->joinColumn)
                        . ' IN (SELECT ' . $this->quote($this->idColumn->column) . ' FROM ' . $this->tableSql . $this->whereSql($where) . ')',
                        $where->getParameters()
                    );
                }
            }
            if ($soft) {
                [$sets, $params] = $this->softRemovalSets($now);

                return $this->run(
                    'UPDATE ' . $this->tableSql . ' SET ' . implode(', ', $sets) . $this->whereSql($where),
                    $params + $where->getParameters()
                )->rowCount();
            }

            return $this->run('DELETE FROM ' . $this->tableSql . $this->whereSql($where), $where->getParameters())->rowCount();
        }

        // Entities first (hooks, children, or a specification evaluated in PHP), then removal by identifier
        $victims = [];
        foreach ($this->stream($where, $memoryFilter, null, null, [], $scope) as $entity) {
            $victims[] = $entity;
        }
        foreach ($victims as $victim) {
            $this->runHooks('beforeRemove', $victim);
        }
        $ids = array_map(fn(IEntity $victim): mixed => $this->identifierOf($victim), $victims);
        $this->cascadeRemove($ids, !$soft);
        $removed = 0;
        foreach (array_chunk($ids, self::DELETE_CHUNK) as $chunk) {
            $in = $this->inList($chunk, 'd');
            $condition = $this->quote($this->idColumn->column) . ' IN (' . $in['sql'] . ')';
            if ($soft) {
                [$sets, $params] = $this->softRemovalSets($now);
                $removed += $this->run(
                    'UPDATE ' . $this->tableSql . ' SET ' . implode(', ', $sets) . ' WHERE ' . $condition
                    . ' AND ' . $this->quote($this->softDelete->column) . ' IS NULL',
                    $params + $in['params']
                )->rowCount();
            } else {
                $removed += $this->run('DELETE FROM ' . $this->tableSql . ' WHERE ' . $condition, $in['params'])->rowCount();
            }
        }
        foreach ($victims as $victim) {
            if ($soft) {
                $this->stampSoftRemoval($victim, $now, null);
            }
            $this->runHooks('afterRemove', $victim);
        }

        return $removed;
    }

    /**
     * remove() and forceRemove(): marks (soft) or deletes (hard) the row of one entity, with the hooks.
     */
    private function removeEntity(IEntity $entity, bool $hard): bool
    {
        return $this->writeExclusively(function () use ($entity, $hard): bool {
            $id = $this->identifierOf($entity);
            if ($id === null) {
                return false;
            }

            return $this->transactions->transactional(function () use ($entity, $id, $hard): bool {
                $this->runHooks('beforeRemove', $entity);
                if ($this->compositions !== [] || ($hard && $this->associations !== [])) {
                    // The children go first (a hard removal must not leave rows that refer to the parent),
                    // so the parent is checked first: nothing is cascaded for a row that is not removable
                    $state = $this->rowStates([$id])[(string) $id] ?? null;
                    if ($state === null || (!$hard && ($state['trashed'] || $this->scope === self::SCOPE_ONLY_TRASHED))) {
                        return false;
                    }
                    $this->cascadeRemove([$id], $hard);
                }
                $removed = $hard ? $this->deleteRow($entity, $id) : $this->markRow($entity, $id, $this->now());
                if ($removed) {
                    $this->runHooks('afterRemove', $entity);
                }

                return $removed;
            });
        });
    }

    /**
     * DELETE of the row of an entity, checking its version.
     */
    private function deleteRow(IEntity $entity, mixed $id): bool
    {
        $sql = 'DELETE FROM ' . $this->tableSql . ' WHERE ' . $this->quote($this->idColumn->column) . ' = :id';
        $params = [':id' => $id];

        $version = $this->mapping->getVersion();
        $expected = $version !== null ? $this->mapping->readValue($entity, $version->property) : null;
        if ($version !== null && $expected !== null) {
            $sql .= ' AND ' . $this->quote($version->column) . ' = :version';
            $params[':version'] = (int) $expected;
        }

        $deleted = $this->run($sql, $params)->rowCount();
        if ($deleted > 0) {
            return true;
        }
        if ($version !== null && $expected !== null) {
            $actual = $this->currentVersion($id);
            if ($actual !== false) {
                throw new OptimisticLockException($this->mapping->getEntityClass(), $id, (int) $expected, $actual);
            }
        }

        return false;
    }

    /**
     * Soft removal of the row of an entity: marks it when it is live (and its version matches).
     */
    private function markRow(IEntity $entity, mixed $id, DateTimeImmutable $now): bool
    {
        if ($this->scope === self::SCOPE_ONLY_TRASHED) {
            return false;   // removals only mark live rows: none is visible here
        }
        [$sets, $params] = $this->softRemovalSets($now);
        $sql = 'UPDATE ' . $this->tableSql . ' SET ' . implode(', ', $sets)
            . ' WHERE ' . $this->quote($this->idColumn->column) . ' = :id AND ' . $this->quote($this->softDelete->column) . ' IS NULL';
        $params[':id'] = $id;

        $version = $this->mapping->getVersion();
        $expected = $version !== null ? $this->mapping->readValue($entity, $version->property) : null;
        if ($version !== null && $expected !== null) {
            $sql .= ' AND ' . $this->quote($version->column) . ' = :version';
            $params[':version'] = (int) $expected;
        }

        if ($this->run($sql, $params)->rowCount() > 0) {
            $this->stampSoftRemoval($entity, $now, $expected !== null ? (int) $expected + 1 : null);
            return true;
        }
        if ($version !== null && $expected !== null) {
            $states = $this->rowStates([$id]);
            $state = $states[(string) $id] ?? null;
            if ($state !== null && !$state['trashed']) {
                $actual = $this->currentVersion($id);
                throw new OptimisticLockException($this->mapping->getEntityClass(), $id, (int) $expected, $actual === false ? null : $actual);
            }
        }

        return false;
    }

    /**
     * SET terms of a soft removal: the mark, the modification time, the version counter.
     *
     * @return array{0: list<string>, 1: array<string, mixed>}
     */
    private function softRemovalSets(DateTimeImmutable $now): array
    {
        $sets = [$this->quote($this->softDelete->column) . ' = :lc_deleted_at'];
        $params = [':lc_deleted_at' => $this->converter->toDatabase($this->softDelete, $this->stamp($this->softDelete, $now))];
        $updatedAt = $this->mapping->getUpdatedAt();
        if ($updatedAt !== null) {
            $sets[] = $this->quote($updatedAt->column) . ' = :lc_updated_at';
            $params[':lc_updated_at'] = $this->converter->toDatabase($updatedAt, $this->stamp($updatedAt, $now));
        }
        $version = $this->mapping->getVersion();
        if ($version !== null) {
            $column = $this->quote($version->column);
            $sets[] = $column . ' = COALESCE(' . $column . ', 0) + 1';
        }

        return [$sets, $params];
    }

    /**
     * Writes back to a soft-removed entity its mark, its modification time and its new version
     * (null = left as it is).
     */
    private function stampSoftRemoval(IEntity $entity, DateTimeImmutable $now, ?int $newVersion): void
    {
        if ($this->softDelete->property !== '') {
            $this->mapping->writeValue($entity, $this->softDelete->property, $this->stamp($this->softDelete, $now));
        }
        $updatedAt = $this->mapping->getUpdatedAt();
        if ($updatedAt !== null) {
            $this->mapping->writeValue($entity, $updatedAt->property, $this->stamp($updatedAt, $now));
        }
        $version = $this->mapping->getVersion();
        if ($version !== null) {
            $current = $this->mapping->readValue($entity, $version->property);
            $newVersion ??= $current !== null ? (int) $current + 1 : null;
            if ($newVersion !== null) {
                $this->mapping->writeValue($entity, $version->property, $newVersion);
            }
        }
    }

    // What this trait calls and PdoRepository or another of its traits implements

    abstract private function compileOrFallback(ISpecification $specification): ?SqlWhereClause;

    abstract private function scoped(?SqlWhereClause $where, ?string $scope = null): SqlWhereClause;

    abstract private function stream(?SqlWhereClause $where, ?ISpecification $memoryFilter, ?int $limit = null, ?int $offset = null, array $orderBy = [], ?string $scope = null): Generator;

    abstract private function whereSql(SqlWhereClause $where): string;

    abstract private function cascadeRemove(array $parentIds, bool $hard): void;

    abstract private function now(): DateTimeImmutable;

    abstract private function rowStates(array $ids): array;

    abstract private function stamp(ColumnMapping $column, DateTimeImmutable $now): DateTimeInterface;

    abstract private function currentVersion(mixed $id): int|false|null;

    abstract private function inList(array $values, string $prefix): array;

    abstract private function runHooks(string $event, IEntity $entity): void;

    abstract private function identifierOf(IEntity $entity): mixed;

    abstract private function quote(string $identifier): string;

    abstract private function run(string $sql, array $params = []): PDOStatement;
}
