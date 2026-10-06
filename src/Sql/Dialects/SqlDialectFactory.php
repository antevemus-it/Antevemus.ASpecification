<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Sql\Dialects;

use Antevemus\ASpecification\Contracts\Sql\ISqlDialect;
use Antevemus\ASpecification\Sql\SqlDialect;

/**
 * SqlDialectFactory - Concrete Factory for SQL Dialect Instances
 *
 * Instantiates the matching dialect strategy based on driver enum or string.
 *
 * Features:
 * - Resolution across 9 driver families (sqlsrv, oracle, oci, mysql, mssql, ibase, firebird, fbird, dblib, pgsql, sqlite, ansi)
 * - Immutable instance caching for high performance
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Sql\Dialects
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class SqlDialectFactory
{
    /** @var array<string, ISqlDialect> */
    private static array $instances = [];

    /**
     * Create or resolve the requested dialect instance.
     *
     * @param ISqlDialect|SqlDialect|string $dialect Enum, string, or pre-existing instance
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
