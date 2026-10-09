<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Sql;

/**
 * ISqlPagination - Row limit and offset in the syntax of a dialect
 *
 * Separate from ISqlDialect so that external dialects keep compiling; every dialect of the library
 * implements both (through AbstractSqlDialect) since 1.6.0. The SELECT statement is the caller's:
 * the WHERE clause comes from SqlQueryVisitor, the rest of the statement from the consumer, and
 * paginate() places the limit and the offset where the engine expects them (a suffix for most, the
 * projection clause for Informix).
 *
 * @version    1.6.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Sql
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface ISqlPagination
{
    /**
     * Applies a row limit and an offset to a SELECT statement.
     *
     * Both values are integers emitted as literals (never bound), so no value of the caller reaches
     * the SQL text. A trailing semicolon of the statement is dropped. With neither a limit nor an
     * offset the statement is returned unchanged (without the trailing semicolon).
     *
     * @param string $selectSql Complete SELECT statement (ORDER BY included, when the result order matters)
     * @param int|null $limit Maximum number of rows (>= 1), or null for no limit
     * @param int|null $offset Number of rows skipped (>= 0), or null/0 for none
     * @return string The paginated statement
     * @throws \InvalidArgumentException When the limit is lower than 1, the offset is negative, or the
     *                                   dialect needs a SELECT the statement does not start with
     */
    public function paginate(string $selectSql, ?int $limit, ?int $offset = null): string;
}
