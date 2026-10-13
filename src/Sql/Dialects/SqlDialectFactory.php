<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Sql\Dialects;

use Antevemus\ASpecification\Contracts\Sql\ISqlDialect;
use Antevemus\ASpecification\Contracts\Sql\ISqlPagination;
use Antevemus\ASpecification\Sql\Exceptions\UnsupportedSqlOperationException;
use Antevemus\ASpecification\Sql\SqlDialect;

/**
 * SqlDialectFactory - Concrete Factory for SQL Dialect Instances
 *
 * Instantiates the matching dialect strategy based on driver enum or string.
 *
 * Features:
 * - Resolution of 15 driver names (sqlsrv, oracle, oci, mysql, mssql, ibase, firebird, fbird, dblib, pgsql,
 *   sqlite, ansi, db2, informix, duckdb) into 10 dialect classes
 * - Immutable instance caching for high performance
 * - Resolution of a dialect that must paginate (createPaginating(), 2.1.0): refused up front when it
 *   does not implement ISqlPagination
 *
 * @version    2.1.0
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
            SqlDialect::DB2 => new Db2Dialect(),
            SqlDialect::INFORMIX => new InformixDialect(),
            SqlDialect::DUCKDB => new DuckDbDialect(),
            SqlDialect::ANSI => new AnsiSqlDialect(),
        };

        return self::$instances[$key] = $instance;
    }

    /**
     * Resolves a dialect for a consumer whose reads rely on a row limit (2.1.0).
     *
     * The PDO stores that bound a scan with LIMIT (PdoTriggerStateStore::findSatisfied(),
     * PdoDomainEventOutbox::fetchPending(), PdoMachineSchema::limit()) used to apply the limit only
     * when the dialect was an AbstractSqlDialect and to run the statement without any limit
     * otherwise. The limit is what keeps those scans bounded, so a dialect without ISqlPagination is
     * now refused here, when the consumer is built, instead of silently reading every row later.
     * Every dialect of the library implements ISqlPagination; an external one implementing only
     * ISqlDialect is the one refused.
     *
     * @param ISqlDialect|SqlDialect|string $dialect Enum, string, or pre-existing instance
     * @param string $consumer Name of the consumer, for the message
     * @return ISqlDialect&ISqlPagination
     * @throws UnsupportedSqlOperationException When the dialect does not implement ISqlPagination
     */
    public static function createPaginating(ISqlDialect|SqlDialect|string $dialect, string $consumer): ISqlDialect&ISqlPagination
    {
        $instance = self::create($dialect);
        if (!$instance instanceof ISqlPagination) {
            throw new UnsupportedSqlOperationException(
                sprintf('LIMIT (%s, required by %s)', ISqlPagination::class, $consumer),
                $instance->getFamily()
            );
        }

        return $instance;
    }
}
