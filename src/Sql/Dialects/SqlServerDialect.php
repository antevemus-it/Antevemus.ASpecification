<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Sql\Dialects;

/**
 * SqlServerDialect - Specialized Dialect for Microsoft SQL Server (sqlsrv, mssql, dblib)
 *
 * Provides support for bracket delimiters ([column]), BIT boolean representation (1/0),
 * and case-insensitive textual filtering with LOWER().
 *
 * @version    1.1.0
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
}
