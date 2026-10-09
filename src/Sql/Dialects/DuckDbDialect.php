<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Sql\Dialects;

/**
 * DuckDbDialect - Specialized Dialect for DuckDB (duckdb, pdo_duckdb)
 *
 * Features:
 * - Double-quoted identifiers without case change (DuckDB resolves identifiers case-insensitively,
 *   quoted or not, and keeps the case they were created with)
 * - Native booleans (TRUE/FALSE)
 * - Case-insensitive LIKE through ILIKE; LIKE/ILIKE ... ESCAPE as in ANSI
 * - Regular expressions through regexp_matches(column, pattern[, 'i']), which searches the string
 *   like preg_match() does (DuckDB's `~` is a full match)
 * - Pagination `LIMIT n OFFSET m`
 *
 * @version    1.6.0
 * @package    Antevemus\ASpecification
 * @subpackage Sql\Dialects
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class DuckDbDialect extends AbstractSqlDialect
{
    /** {@inheritdoc} */
    public function getFamily(): string
    {
        return 'duckdb';
    }

    /** {@inheritdoc} */
    public function formatBoolean(bool $value): string
    {
        return $value ? 'TRUE' : 'FALSE';
    }

    /** {@inheritdoc} */
    public function formatLike(string $column, string $paramPlaceholder, bool $caseSensitive = true): string
    {
        if ($caseSensitive) {
            return "{$column} LIKE {$paramPlaceholder}";
        }

        return "{$column} ILIKE {$paramPlaceholder}";
    }

    /** {@inheritdoc} */
    public function formatRegex(string $column, string $paramPlaceholder, bool $caseSensitive = true): string
    {
        return $caseSensitive
            ? "regexp_matches({$column}, {$paramPlaceholder})"
            : "regexp_matches({$column}, {$paramPlaceholder}, 'i')";
    }

    /** {@inheritdoc} */
    public function supportsRegex(): bool
    {
        return true;
    }

    /** {@inheritdoc} `LIMIT n OFFSET m`; an offset alone is `OFFSET m`. */
    protected function formatPagination(string $sql, ?int $limit, ?int $offset): string
    {
        return $this->limitOffsetPagination($sql, $limit, $offset);
    }
}
