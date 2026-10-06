<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Sql;

/**
 * ISqlDialect - Contract for Relational Database Dialects
 *
 * Defines identifier escaping, boolean literal formatting,
 * text search operators (LIKE/ILIKE), and regular expression support specific to each RDBMS.
 *
 * Features:
 * - Identification of RDBMS family (PostgreSQL, MySQL, SQL Server, Oracle, Firebird, SQLite)
 * - Safe delimitation of column and table identifiers (escapeIdentifier)
 * - Driver-compliant boolean formatting (TRUE/FALSE, 1/0, 'Y'/'N')
 * - Canonical tautological truth and falsity expressions (1=1, 1=0)
 * - Idiomatic mapping of pattern matching and Regex operators
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Sql
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface ISqlDialect
{
    /**
     * Return RDBMS family identifier (e.g. 'pgsql', 'mysql', 'sqlsrv', 'oracle', 'firebird', 'sqlite').
     *
     * @return string
     */
    public function getFamily(): string;

    /**
     * Apply appropriate escape delimiters to an identifier (e.g. "col", `col`, [col]).
     *
     * @param string $identifier Column or table name
     * @return string
     */
    public function escapeIdentifier(string $identifier): string;

    /**
     * Format a boolean value into native RDBMS syntax.
     *
     * @param bool $value
     * @return string
     */
    public function formatBoolean(bool $value): string;

    /**
     * Return universally true SQL condition expression (e.g. '1 = 1').
     *
     * @return string
     */
    public function getTrueCondition(): string;

    /**
     * Return universally false SQL condition expression (e.g. '1 = 0').
     *
     * @return string
     */
    public function getFalseCondition(): string;

    /**
     * Format a LIKE pattern comparison with case sensitivity control.
     *
     * @param string $column Pre-escaped column expression
     * @param string $paramPlaceholder Named parameter placeholder (e.g. ':p1')
     * @param bool $caseSensitive Whether matching should be case-sensitive
     * @return string
     */
    public function formatLike(string $column, string $paramPlaceholder, bool $caseSensitive = true): string;

    /**
     * Format a regular expression comparison based on RDBMS dialect.
     *
     * @param string $column Pre-escaped column expression
     * @param string $paramPlaceholder Named parameter placeholder (e.g. ':p1')
     * @param bool $caseSensitive Whether regex matches case-sensitively
     * @return string
     */
    public function formatRegex(string $column, string $paramPlaceholder, bool $caseSensitive = true): string;

    /**
     * Determine whether dialect natively supports regular expression operators in SQL.
     *
     * @return bool
     */
    public function supportsRegex(): bool;
}
