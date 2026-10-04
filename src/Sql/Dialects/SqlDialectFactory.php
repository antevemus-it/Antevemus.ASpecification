<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Sql\Dialects;

use Antevemus\ASpecification\Contracts\Sql\ISqlDialect;
use Antevemus\ASpecification\Sql\SqlDialect;

/**
 * SqlDialectFactory - Fábrica Concreta de Instâncias de Dialetos SQL
 *
 * Instancia a estratégia de dialeto correspondente com base no enum ou string do driver.
 *
 * Funcionalidades:
 * - Resolução para todas as 9 famílias solicitadas (sqlsrv, oracle, oci, mysql, mssql, ibase, firebird, fbird, dblib)
 * - Cache de instâncias imutáveis para alta performance
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Sql\Dialects
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class SqlDialectFactory
{
    /** @var array<string, ISqlDialect> */
    private static array $instances = [];

    /**
     * Cria ou resolve a instância do dialeto solicitado.
     *
     * @param ISqlDialect|SqlDialect|string $dialect Enum, string ou instância pré-existente
     * @return ISqlDialect
     */
    public static function create(ISqlDialect|SqlDialect|string $dialect): ISqlDialect
    {
        if ($dialect instanceof ISqlDialect) {
            return $dialect;
        }

        $enum = SqlDialect::fromDriver($dialect);
        $key = $enum->value;

        if (isset(self::$instances[$key])) {
            return self::$instances[$key];
        }

        $instance = match ($enum) {
            SqlDialect::POSTGRESQL => new PostgreSqlDialect(),
            SqlDialect::MYSQL => new MySqlDialect(),
            SqlDialect::SQLITE => new SqliteDialect(),
            SqlDialect::ORACLE, SqlDialect::OCI => new OracleDialect($enum->value),
            SqlDialect::SQLSRV, SqlDialect::MSSQL, SqlDialect::DBLIB => new SqlServerDialect($enum->value),
            SqlDialect::FIREBIRD, SqlDialect::FBIRD, SqlDialect::IBASE => new FirebirdDialect($enum->value),
            SqlDialect::ANSI => new AnsiSqlDialect(),
        };

        return self::$instances[$key] = $instance;
    }
}
