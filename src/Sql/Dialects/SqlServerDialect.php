<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Sql\Dialects;

/**
 * SqlServerDialect - Dialeto Especializado para Microsoft SQL Server (sqlsrv, mssql, dblib)
 *
 * Provê suporte a colchetes delimitadores ([coluna]), booleanos numéricos tipo BIT (1/0)
 * e formatação de busca textual com LOWER().
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Sql\Dialects
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class SqlServerDialect extends AbstractSqlDialect
{
    /**
     * @param string $family Identificador específico ('sqlsrv', 'mssql' ou 'dblib')
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
