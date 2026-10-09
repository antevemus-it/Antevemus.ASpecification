<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Sql\Dialects;

/**
 * Db2Dialect - Specialized Dialect for IBM Db2 (db2, ibm, ibm_db2, pdo_ibm)
 *
 * Features:
 * - Double-quoted identifiers in upper case ("COLUMN"): Db2 stores identifiers created without
 *   quotes in upper case and a quoted identifier is case-sensitive, as in Oracle and Firebird
 * - Numeric booleans (1/0): the SMALLINT flag convention every Db2 platform has (a BOOLEAN column
 *   type exists only on Db2 LUW 11.1+ and Db2 for i 7.5+)
 * - Case-insensitive LIKE through LOWER() (Db2 has no ILIKE); LIKE ... ESCAPE as in ANSI
 * - Regular expressions through REGEXP_LIKE(column, pattern, 'c' | 'i') (Db2 LUW 11.1+, Db2 for z/OS 12+)
 * - Pagination in the SQL:2008 form: `FETCH FIRST n ROWS ONLY` (every Db2 version), preceded by
 *   `OFFSET m ROWS` when an offset is given (Db2 LUW 11.1+)
 *
 * @version    1.6.0
 * @package    Antevemus\ASpecification
 * @subpackage Sql\Dialects
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class Db2Dialect extends AbstractSqlDialect
{
    /** {@inheritdoc} */
    public function getFamily(): string
    {
        return 'db2';
    }

    /**
     * {@inheritdoc}
     *
     * Upper case, so that the quoted form matches columns created by plain DDL.
     */
    protected function escapeSegment(string $segment): string
    {
        return '"' . str_replace('"', '""', strtoupper($segment)) . '"';
    }

    /** {@inheritdoc} */
    public function formatRegex(string $column, string $paramPlaceholder, bool $caseSensitive = true): string
    {
        $flags = $caseSensitive ? "'c'" : "'i'";
        return "REGEXP_LIKE({$column}, {$paramPlaceholder}, {$flags})";
    }

    /** {@inheritdoc} */
    public function supportsRegex(): bool
    {
        return true;
    }
}
