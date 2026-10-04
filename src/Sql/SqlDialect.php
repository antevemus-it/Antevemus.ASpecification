<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Sql;

/**
 * SqlDialect - Enumeração de Dialetos de Bancos de Dados Relacionais Suportados
 *
 * Mapeia os SGBDs compatíveis com o ecossistema Antevemus e Adianti Framework,
 * abrangendo PostgreSQL, MySQL, SQL Server, Oracle, Firebird, SQLite e ANSI padrão.
 *
 * Funcionalidades:
 * - Suporte nativo a todos os drivers: sqlsrv, oracle, oci, mysql, mssql, ibase, firebird, fbird, dblib, pgsql, sqlite
 * - Normalização de identificadores e drivers via fromDriver()
 * - Identificação de família arquitetural do dialeto
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Sql
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
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

    /**
     * Resolve o dialeto a partir de qualquer string de driver recebida.
     *
     * @param string|self|null $driver Nome do driver ou SGBD
     * @param self $default Fallback padrão se desconhecido
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

        // Normalização de variantes conhecidas
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
            default => self::tryFrom($clean) ?? $default,
        };
    }
}
