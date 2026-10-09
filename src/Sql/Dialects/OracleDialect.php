<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Sql\Dialects;

/**
 * OracleDialect - Specialized Dialect for Oracle Database (oracle, oci, oci8)
 *
 * Provides support for double-quoted identifiers in upper case ("COLUMN"), numeric booleans (1/0),
 * case-insensitive matching via LOWER(), and native REGEXP_LIKE pattern matching.
 *
 * Pagination (1.6.0): the SQL:2008 form inherited from AbstractSqlDialect,
 * `OFFSET m ROWS FETCH FIRST n ROWS ONLY` (Oracle 12c+).
 *
 * @version    1.6.0
 * @package    Antevemus\ASpecification
 * @subpackage Sql\Dialects
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class OracleDialect extends AbstractSqlDialect
{
    /**
     * @param string $family Specific driver family ('oracle' or 'oci')
     */
    public function __construct(
        private readonly string $family = 'oracle'
    ) {
    }

    /** {@inheritdoc} */
    public function getFamily(): string
    {
        return $this->family;
    }

    /**
     * {@inheritdoc}
     *
     * Identifiers created without quotes are stored in upper case by this engine, and a quoted
     * identifier is case-sensitive, so the quoted form must be upper case to match columns created
     * by plain DDL (spec 012 RN-03, BUG-20261007-MNZN).
     */
    protected function escapeSegment(string $segment): string
    {
        return '"' . str_replace('"', '""', strtoupper($segment)) . '"';
    }

    /** {@inheritdoc} */
    public function formatRegex(string $column, string $paramPlaceholder, bool $caseSensitive = true): string
    {
        $matchParam = $caseSensitive ? "'c'" : "'i'";
        return "REGEXP_LIKE({$column}, {$paramPlaceholder}, {$matchParam})";
    }

    /** {@inheritdoc} */
    public function supportsRegex(): bool
    {
        return true;
    }
}
