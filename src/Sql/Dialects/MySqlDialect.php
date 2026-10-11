<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Sql\Dialects;

/**
 * MySqlDialect - Specialized Dialect for MySQL and MariaDB
 *
 * Provides support for backtick delimiters (`column`), case-sensitive matching with BINARY,
 * numeric boolean representation (1/0), and the native REGEXP operator.
 *
 * Pagination (1.6.0): `LIMIT n OFFSET m`; MySQL has no OFFSET without LIMIT, so an offset alone
 * carries the documented maximum `LIMIT 18446744073709551615`.
 *
 * @version    1.14.0
 * @package    Antevemus\ASpecification
 * @subpackage Sql\Dialects
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class MySqlDialect extends AbstractSqlDialect
{
    /** {@inheritdoc} */
    public function getFamily(): string
    {
        return 'mysql';
    }

    /** {@inheritdoc} */
    protected function escapeSegment(string $segment): string
    {
        return '`' . str_replace('`', '``', $segment) . '`';
    }

    /**
     * {@inheritdoc}
     *
     * 1.14.0: the comparison is made over the text converted to `utf8mb4` with the `utf8mb4_bin`
     * collation, so the result follows the in-memory evaluation on every column collation and
     * charset of MySQL and MariaDB: case-sensitive compares characters exactly (the former
     * `BINARY col` compared bytes, so `_` matched one byte and missed `é`); case-insensitive lowers
     * both sides and still compares accents (the former `col LIKE` ignored case only on `_ci`
     * columns and also ignored accents on `_ai` ones). Neither form uses an index on the column.
     */
    public function formatLike(string $column, string $paramPlaceholder, bool $caseSensitive = true): string
    {
        if ($caseSensitive) {
            return "CONVERT({$column} USING utf8mb4) COLLATE utf8mb4_bin LIKE {$paramPlaceholder}";
        }

        return "LOWER(CONVERT({$column} USING utf8mb4)) COLLATE utf8mb4_bin LIKE LOWER({$paramPlaceholder})";
    }

    /**
     * {@inheritdoc}
     *
     * 1.14.0: the case rule travels inside the pattern as an inline flag, `(?-i)` or `(?i)`, which
     * both regex engines (ICU in MySQL 8, PCRE in MariaDB) honour over any collation. The former
     * `REGEXP BINARY` failed with error 3995 on MySQL 8 and compared bytes on MariaDB; the former
     * case-insensitive form was case-sensitive on `_bin` columns.
     */
    public function formatRegex(string $column, string $paramPlaceholder, bool $caseSensitive = true): string
    {
        $flag = $caseSensitive ? '(?-i)' : '(?i)';
        return "{$column} REGEXP CONCAT('{$flag}', {$paramPlaceholder})";
    }

    /** {@inheritdoc} */
    public function supportsRegex(): bool
    {
        return true;
    }

    /** {@inheritdoc} `LIMIT n OFFSET m`; an offset alone needs the maximum limit in MySQL. */
    protected function formatPagination(string $sql, ?int $limit, ?int $offset): string
    {
        return $this->limitOffsetPagination($sql, $limit, $offset, '18446744073709551615');
    }
}
