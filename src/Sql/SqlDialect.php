<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Sql;

/**
 * SqlDialect - Enumeration of Supported Relational Database Dialects
 *
 * Maps relational database engines compatible with the Antevemus ecosystem and Adianti Framework,
 * covering PostgreSQL, MySQL, SQL Server, Oracle, Firebird, SQLite, IBM Db2, IBM Informix, DuckDB
 * and standard ANSI: 15 driver names served by 10 dialect classes.
 *
 * Features:
 * - Native driver support: sqlsrv, oracle, oci, mysql, mssql, ibase, firebird, fbird, dblib, pgsql, sqlite,
 *   db2, informix, duckdb (1.6.0)
 * - Normalization of driver names and variants via fromDriver()
 * - Architectural family classification
 *
 * @version    1.6.0
 * @package    Antevemus\ASpecification
 * @subpackage Sql
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
enum SqlDialect: string
{
    case ANSI = 'ansi';
    case POSTGRESQL = 'pgsql';
    case MYSQL = 'mysql';
    case SQLITE = 'sqlite';
    case ORACLE = 'oracle';
    case OCI = 'oci';
    case SQLSRV = 'sqlsrv';
    case MSSQL = 'mssql';
    case DBLIB = 'dblib';
    case FIREBIRD = 'firebird';
    case FBIRD = 'fbird';
    case IBASE = 'ibase';
    case DB2 = 'db2';
    case INFORMIX = 'informix';
    case DUCKDB = 'duckdb';

    /**
     * Resolve dialect enum from driver name string or instance.
     *
     * @param string|self|null $driver Driver name or RDBMS identifier
     * @param self $default Fallback dialect if unrecognized
     * @return self
     */
    public static function fromDriver(self|string|null $driver, self $default = self::ANSI): self
    {
        if ($driver instanceof self) {
            return $driver;
        }

        if ($driver === null || trim($driver) === '') {
            return $default;
        }

        $clean = strtolower(trim($driver));

        // Normalisation of known variants
        return match ($clean) {
            'pgsql', 'postgres', 'postgresql', 'pdo_pgsql' => self::POSTGRESQL,
            'mysql', 'mariadb', 'pdo_mysql', 'mysqli' => self::MYSQL,
            'sqlite', 'sqlite3', 'pdo_sqlite' => self::SQLITE,
            'oracle', 'pdo_oci' => self::ORACLE,
            'oci', 'oci8' => self::OCI,
            'sqlsrv', 'pdo_sqlsrv' => self::SQLSRV,
            'mssql' => self::MSSQL,
            'dblib', 'sybase', 'pdo_dblib' => self::DBLIB,
            'firebird', 'pdo_firebird' => self::FIREBIRD,
            'fbird' => self::FBIRD,
            'ibase', 'interbase' => self::IBASE,
            'db2', 'ibm', 'ibm_db2', 'pdo_ibm' => self::DB2,
            'informix', 'ifx', 'pdo_informix' => self::INFORMIX,
            'duckdb', 'pdo_duckdb' => self::DUCKDB,
            default => self::tryFrom($clean) ?? $default,
        };
    }
}
