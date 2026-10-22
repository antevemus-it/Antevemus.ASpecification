<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Pdo;

use PDO;
use Throwable;

/**
 * PdoDriverLimits - What the Driver of a Connection Accepts in One Statement
 *
 * What PdoRepository knows about its driver, in one place (since 2.11.0; until then two private methods
 * and a constant of PdoRepository, with the same bodies): the maximum number of bound parameters in one
 * statement (PostgreSQL and MySQL 65535; SQLite 32766 from 3.32 and 999 before; SQL Server, mssql and
 * dblib 2000; 999 elsewhere) and whether an INSERT takes several rows in VALUES (the families of
 * MULTI_ROW_FAMILIES). The parameter limit is computed on the first call and kept; only then, and only
 * for SQLite, is the server version read from the connection (an unreadable version gives the limit
 * of the older releases).
 *
 * The answers depend only on the family and on the server version of the connection, so the views of
 * a repository (its clones) share one instance.
 *
 * @internal Used by PdoRepository; not part of the public API.
 * @version    2.11.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\Pdo
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class PdoDriverLimits
{
    /** Families whose INSERT accepts several rows in VALUES. */
    private const MULTI_ROW_FAMILIES = ['pgsql', 'mysql', 'sqlite', 'sqlsrv', 'mssql', 'dblib', 'db2', 'duckdb'];

    private ?int $maxParameters = null;

    /**
     * @param string $family Dialect family of the connection (pgsql, mysql, sqlite...)
     * @param PDO $pdo The connection; read only by the first maxParameters() of a SQLite family (its version)
     */
    public function __construct(private readonly string $family, private readonly PDO $pdo)
    {
    }

    /** True when the driver takes several rows in one INSERT ... VALUES. */
    public function multiRowInsert(): bool
    {
        return in_array($this->family, self::MULTI_ROW_FAMILIES, true);
    }

    /**
     * Maximum number of bound parameters in one statement for the driver.
     */
    public function maxParameters(): int
    {
        if ($this->maxParameters !== null) {
            return $this->maxParameters;
        }
        $limit = match ($this->family) {
            'pgsql', 'mysql' => 65535,
            'sqlsrv', 'mssql', 'dblib' => 2000,
            'sqlite' => 999,
            default => 999,
        };
        if ($this->family === 'sqlite') {
            try {
                $version = (string) $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
                if ($version !== '' && version_compare($version, '3.32.0', '>=')) {
                    $limit = 32766;
                }
            } catch (Throwable) {
                // Unknown version: the limit of the older releases
            }
        }

        return $this->maxParameters = $limit;
    }
}
