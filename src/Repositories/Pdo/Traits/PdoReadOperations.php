<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Pdo\Traits;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Sql\ISqlPagination;
use Antevemus\ASpecification\Contracts\Sql\ISqlWhereClause;
use Antevemus\ASpecification\Repositories\Pdo\PdoRepository;
use Antevemus\ASpecification\Repositories\Pdo\PdoSpecificationCompiler;
use Antevemus\ASpecification\Sql\Exceptions\NonTranslatableSpecificationException;
use Antevemus\ASpecification\Sql\Exceptions\UnsupportedSqlOperationException;
use Antevemus\ASpecification\Sql\SqlWhereClause;
use Generator;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use RuntimeException;

/**
 * PdoReadOperations - The Reads of PdoRepository
 *
 * Since 2.11.0, when the class was split into traits (the methods are the ones of 2.10.0, unchanged):
 * the reads of the IRepository contract (countAllEntitiesSpecifiedBy(), iterateAllEntitiesSpecifiedBy(),
 * findAllEntitiesSpecifiedBy(), findSingleEntitySpecifiedBy(), contains()), findPage(), findById(),
 * toWhereClause() (the compiled clause, for diagnostics) and rowExists(). They read through PdoQueryKernel; a with() view loads its relations
 * through PdoRelationLoading.
 *
 * Reads (D7):
 * - find*() builds the entities; iterate*() is a generator over the cursor (constant memory: one row
 *   and one entity at a time; the query runs on the first pull); both order by the identifier, so
 *   time-ordered and generated identifiers come back in insertion order, as from InMemoryRepository
 * - count*() is `SELECT COUNT(*)` and builds no entity; findSingle() asks for at most two rows
 *   (`LIMIT 2` in the dialect) in one query and throws RuntimeException when there is more than one
 * - findPage() paginates through ISqlPagination; only the mapped columns are selected
 * - a specification that cannot be translated raises the visitor's exception; nothing is filtered in
 *   memory unless withInMemoryFallback() was called (then the table is scanned and the specification
 *   evaluated in PHP: only for small tables)
 *
 * Every read (find, iterate, count, findSingle, findPage, findById, contains) leaves the rows marked by
 * the soft delete out, unless the repository is a withTrashed() or onlyTrashed() view.
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
trait PdoReadOperations
{
    /**
     * The WHERE clause a specification compiles to over this mapping (diagnostics, custom statements).
     * The soft delete condition of the reads is not part of it.
     *
     * @throws NonTranslatableSpecificationException When the specification cannot be translated
     * @throws UnsupportedSqlOperationException When the dialect lacks an operation the specification needs
     */
    public function toWhereClause(ISpecification $specification): ISqlWhereClause
    {
        return $this->compiler()->translate($specification);
    }

    /** {@inheritdoc} */
    public function countAllEntitiesSpecifiedBy(ISpecification $specification): int
    {
        $this->validateSpecification($specification);

        return $this->readConcurrently(function () use ($specification): int {
            $where = $this->compileOrFallback($specification);
            if ($where === null) {
                $count = 0;
                foreach ($this->stream(null, $specification) as $_) {
                    $count++;
                }
                return $count;
            }

            $where = $this->scoped($where);
            $statement = $this->run('SELECT COUNT(*) FROM ' . $this->tableSql . $this->whereSql($where), $where->getParameters());
            $count = $statement->fetchColumn();
            $statement->closeCursor();

            return (int) $count;
        });
    }

    /** {@inheritdoc} */
    public function iterateAllEntitiesSpecifiedBy(ISpecification $specification): iterable
    {
        $this->validateSpecification($specification);

        // Compiled now, so that a non-translatable specification fails at the call, not at the first pull
        return $this->readConcurrently(function () use ($specification): Generator {
            $where = $this->compileOrFallback($specification);
            $memoryFilter = $where === null ? $specification : null;

            // With relations: pages by key, each read whole and its cursor closed before its relations load
            return $this->eager === [] ? $this->stream($where, $memoryFilter) : $this->pagedWithRelations($where, $memoryFilter);
        });
    }

    /** {@inheritdoc} */
    public function findAllEntitiesSpecifiedBy(ISpecification $specification): array
    {
        $this->validateSpecification($specification);

        return $this->readConcurrently(function () use ($specification): array {
            $where = $this->compileOrFallback($specification);

            return $this->withRelationsLoaded(iterator_to_array($this->stream($where, $where === null ? $specification : null), false));
        });
    }

    /**
     * {@inheritdoc}
     *
     * One query asking for at most two rows.
     */
    public function findSingleEntitySpecifiedBy(ISpecification $specification): ?IEntity
    {
        $this->validateSpecification($specification);

        return $this->readConcurrently(function () use ($specification): ?IEntity {
            $where = $this->compileOrFallback($specification);
            $limit = ($where !== null && $this->dialect instanceof ISqlPagination) ? 2 : null;

            $found = [];
            foreach ($this->stream($where, $where === null ? $specification : null, $limit, null, []) as $entity) {
                $found[] = $entity;
                if (count($found) > 1) {
                    throw new RuntimeException('Expected a single entity result, but found at least 2');
                }
            }
            if ($found !== [] && $this->eager !== []) {
                $this->loadRelations($found, $this->eager, $this);
            }

            return $found[0] ?? null;
        });
    }

    /**
     * One page of the entities satisfying the specification, ordered.
     *
     * @param ISpecification $specification Filter
     * @param int $limit Maximum number of entities (>= 1)
     * @param int $offset Number of entities skipped (>= 0)
     * @param array<string, string> $orderBy Property => 'ASC'|'DESC' (default: the identifier ascending);
     *                                       the identifier is always appended as the last key, so pages
     *                                       never overlap
     * @return array<T>
     * @throws InvalidArgumentException When the limit, the offset, a property or a direction is invalid
     * @throws UnsupportedSqlOperationException When the dialect cannot paginate (not an ISqlPagination)
     */
    public function findPage(ISpecification $specification, int $limit, int $offset = 0, array $orderBy = []): array
    {
        $this->validateSpecification($specification);
        if ($limit < 1) {
            throw new InvalidArgumentException("The page size must be at least 1, {$limit} given.");
        }
        if ($offset < 0) {
            throw new InvalidArgumentException("The page offset cannot be negative, {$offset} given.");
        }

        return $this->readConcurrently(function () use ($specification, $limit, $offset, $orderBy): array {
            $where = $this->compileOrFallback($specification);
            if ($where === null) {
                // Evaluated in PHP: the page is cut after the filter
                $page = [];
                $skipped = 0;
                foreach ($this->stream(null, $specification, null, null, $orderBy) as $entity) {
                    if ($skipped++ < $offset) {
                        continue;
                    }
                    $page[] = $entity;
                    if (count($page) === $limit) {
                        break;
                    }
                }
                return $this->withRelationsLoaded($page);
            }

            if (!$this->dialect instanceof ISqlPagination) {
                throw new UnsupportedSqlOperationException('LIMIT/OFFSET', $this->family);
            }

            return $this->withRelationsLoaded(iterator_to_array($this->stream($where, null, $limit, $offset, $orderBy), false));
        });
    }

    /**
     * The entity with the given identifier, or null.
     *
     * @param mixed $id Identifier value
     * @return T|null
     */
    public function findById(mixed $id): ?IEntity
    {
        if ($id === null) {
            return null;
        }

        return $this->readConcurrently(function () use ($id): ?IEntity {
            $name = ':id';
            $where = new SqlWhereClause(
                $this->dialect->escapeIdentifier($this->idColumn->column) . ' = ' . $name,
                [$name => $this->converter->toDatabase($this->idColumn, $id)]
            );
            $found = null;
            foreach ($this->stream($where, null, null, null, []) as $entity) {
                $found = $entity;
            }
            if ($found !== null && $this->eager !== []) {
                $this->loadRelations([$found], $this->eager, $this);
            }

            return $found;
        });
    }

    /**
     * {@inheritdoc}
     *
     * By identity: true when a row has the entity's identifier (an entity of another class, or
     * without an identifier yet, is never contained).
     */
    public function contains(IEntity $entity): bool
    {
        return $this->readConcurrently(function () use ($entity): bool {
            $id = $this->identifierOf($entity);

            return $id !== null && $this->rowExists($id, true);
        });
    }

    /**
     * @param bool $scoped True = only a row the reads of this repository see
     */
    private function rowExists(mixed $id, bool $scoped): bool
    {
        $where = new SqlWhereClause($this->quote($this->idColumn->column) . ' = :id', [':id' => $id]);
        if ($scoped) {
            $where = $this->scoped($where);
        }
        $statement = $this->run('SELECT 1 FROM ' . $this->tableSql . $this->whereSql($where), $where->getParameters());
        $exists = $statement->fetch(PDO::FETCH_NUM) !== false;
        $statement->closeCursor();

        return $exists;
    }

    // What this trait calls and PdoRepository or another of its traits implements

    abstract private function compiler(string $parameterPrefix = 'p'): PdoSpecificationCompiler;

    abstract private function compileOrFallback(ISpecification $specification): ?SqlWhereClause;

    abstract private function scoped(?SqlWhereClause $where, ?string $scope = null): SqlWhereClause;

    abstract private function stream(?SqlWhereClause $where, ?ISpecification $memoryFilter, ?int $limit = null, ?int $offset = null, array $orderBy = [], ?string $scope = null): Generator;

    abstract private function whereSql(SqlWhereClause $where): string;

    abstract private function withRelationsLoaded(array $entities): array;

    abstract private function pagedWithRelations(?SqlWhereClause $where, ?ISpecification $memoryFilter): Generator;

    abstract private function loadRelations(array $entities, array $tree, PdoRepository $root): void;

    abstract private function identifierOf(IEntity $entity): mixed;

    abstract private function quote(string $identifier): string;

    abstract private function run(string $sql, array $params = []): PDOStatement;
}
