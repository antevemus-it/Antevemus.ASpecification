<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Pdo\Traits;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Sql\ISqlPagination;
use Antevemus\ASpecification\Repositories\Pdo\PdoSpecificationCompiler;
use Antevemus\ASpecification\Sql\Exceptions\NonTranslatableSpecificationException;
use Antevemus\ASpecification\Sql\Exceptions\UnsupportedSqlOperationException;
use Antevemus\ASpecification\Sql\SqlWhereClause;
use Generator;
use InvalidArgumentException;
use PDO;
use PDOStatement;

/**
 * PdoQueryKernel - The Read Pipeline of PdoRepository
 *
 * What reads, set operations and relations share to read rows (since 2.11.0, when the class was split
 * into traits; the methods are the ones of 2.10.0, unchanged): compiler() and compileOrFallback() turn a
 * specification into a WHERE clause (the visitor's exception, or null when withInMemoryFallback() is
 * on); scoped() and liveOnly() add the read scope of the soft delete (live rows, with or only the
 * marked ones); stream() runs the SELECT ordered by orderSql() (by the identifier) and yields the
 * hydrated entities one row at a time; whereSql() renders a clause.
 *
 * MySQL streams only with unbuffered queries (PDO::MYSQL_ATTR_USE_BUFFERED_QUERY = false on the
 * connection); with the default buffered mode the driver holds the whole result before the first row.
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
trait PdoQueryKernel
{
    private function compiler(string $parameterPrefix = 'p'): PdoSpecificationCompiler
    {
        return new PdoSpecificationCompiler($this->mapping, $this->dialect, $this->converter, $this->pdo, $parameterPrefix);
    }

    /**
     * The compiled clause, or null when the specification cannot be translated and the in-memory
     * fallback is on.
     */
    private function compileOrFallback(ISpecification $specification): ?SqlWhereClause
    {
        try {
            return $this->compiler()->translate($specification);
        } catch (NonTranslatableSpecificationException | UnsupportedSqlOperationException $e) {
            if ($this->inMemoryFallback) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Streams the entities of a SELECT, filtering in PHP when a specification is given.
     *
     * @param array<string, string> $orderBy
     * @param string|null $scope Soft delete scope of this read (SCOPE_LIVE for removals, SCOPE_WITH_TRASHED
     *                           for every row); null = the read scope of this repository or view
     * @return Generator<int, T>
     */
    private function stream(
        ?SqlWhereClause $where,
        ?ISpecification $memoryFilter,
        ?int $limit = null,
        ?int $offset = null,
        array $orderBy = [],
        ?string $scope = null
    ): Generator {
        $where = $this->scoped($where, $scope);
        $sql = $this->selectSql . $this->whereSql($where) . $this->orderSql($orderBy);
        if (($limit !== null || $offset !== null) && $this->dialect instanceof ISqlPagination) {
            $sql = $this->dialect->paginate($sql, $limit, $offset);
        }

        $statement = $this->run($sql, $where->getParameters());
        try {
            while (($row = $statement->fetch(PDO::FETCH_NUM)) !== false) {
                $entity = $this->hydrate($row);
                if ($memoryFilter === null || $memoryFilter->isSatisfiedBy($entity)) {
                    yield $entity;
                }
            }
        } finally {
            $statement->closeCursor();
        }
    }

    private function whereSql(SqlWhereClause $where): string
    {
        return $where->isEmpty() ? '' : ' WHERE ' . $where->toSql();
    }

    /**
     * @param array<string, string> $orderBy
     */
    private function orderSql(array $orderBy): string
    {
        $terms = [];
        $seenId = false;
        foreach ($orderBy as $property => $direction) {
            $reason = null;
            $column = is_string($property) ? $this->mapping->findQueryColumn($property, $reason) : null;
            if ($column === null) {
                throw new InvalidArgumentException(sprintf('Cannot order by "%s": %s.', (string) $property, (string) ($reason ?? 'not a property name')));
            }
            $dir = strtoupper(trim((string) $direction));
            if ($dir !== 'ASC' && $dir !== 'DESC') {
                throw new InvalidArgumentException(sprintf('Order direction of "%s" must be ASC or DESC, "%s" given.', $property, (string) $direction));
            }
            $terms[] = $this->quote($column->column) . ' ' . $dir;
            $seenId = $seenId || $column->isId;
        }
        if (!$seenId) {
            $terms[] = $this->quote($this->idColumn->column) . ' ASC';
        }

        return ' ORDER BY ' . implode(', ', $terms);
    }

    /**
     * The read filter: the clause AND the soft delete condition of the scope (the clause itself when
     * the mapping has no soft delete column).
     */
    private function scoped(?SqlWhereClause $where, ?string $scope = null): SqlWhereClause
    {
        $where ??= SqlWhereClause::empty();
        $scope ??= $this->scope;
        if ($this->softDelete === null || $scope === self::SCOPE_WITH_TRASHED) {
            return $where;
        }

        return $where->and(new SqlWhereClause(
            $this->quote($this->softDelete->column) . ($scope === self::SCOPE_ONLY_TRASHED ? ' IS NOT NULL' : ' IS NULL')
        ));
    }

    /**
     * The clause AND "the row is live" (removals only touch live rows).
     */
    private function liveOnly(?SqlWhereClause $where): SqlWhereClause
    {
        $where ??= SqlWhereClause::empty();
        if ($this->softDelete === null) {
            return $where;
        }

        return $where->and(new SqlWhereClause($this->quote($this->softDelete->column) . ' IS NULL'));
    }

    // What this trait calls and PdoRepository or another of its traits implements

    abstract private function hydrate(array $row): IEntity;

    abstract private function quote(string $identifier): string;

    abstract private function run(string $sql, array $params = []): PDOStatement;
}
