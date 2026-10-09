<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Sql\Dialects;

/**
 * SqlServerDialect - Specialized Dialect for Microsoft SQL Server (sqlsrv, mssql, dblib)
 *
 * Provides support for bracket delimiters ([column]), BIT boolean representation (1/0),
 * and case-insensitive textual filtering with LOWER().
 *
 * Pagination (1.6.0): `OFFSET m ROWS FETCH NEXT n ROWS ONLY` (SQL Server 2012+), which requires an
 * ORDER BY and an OFFSET: `OFFSET 0 ROWS` is always written and `ORDER BY (SELECT NULL)` is appended
 * when the statement has no ORDER BY.
 *
 * @version    1.6.0
 * @package    Antevemus\ASpecification
 * @subpackage Sql\Dialects
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class SqlServerDialect extends AbstractSqlDialect
{
    /**
     * @param string $family Specific driver family ('sqlsrv', 'mssql', or 'dblib')
     */
    public function __construct(
        private readonly string $family = 'sqlsrv'
    ) {
    }

    /** {@inheritdoc} */
    public function getFamily(): string
    {
        return $this->family;
    }

    /** {@inheritdoc} */
    protected function escapeSegment(string $segment): string
    {
        return '[' . str_replace(']', ']]', $segment) . ']';
    }

    /**
     * {@inheritdoc}
     *
     * SQL Server 2012+ only accepts OFFSET/FETCH after an ORDER BY, and FETCH only after an OFFSET.
     */
    protected function formatPagination(string $sql, ?int $limit, ?int $offset): string
    {
        if (preg_match('/\bORDER\s+BY\b/i', $sql) !== 1) {
            $sql .= ' ORDER BY (SELECT NULL)';
        }

        $suffix = 'OFFSET ' . ($offset ?? 0) . ' ROWS';
        if ($limit !== null) {
            $suffix .= " FETCH NEXT {$limit} ROWS ONLY";
        }

        return "{$sql} {$suffix}";
    }
}
