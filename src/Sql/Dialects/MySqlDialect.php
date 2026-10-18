<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Sql\Dialects;

use InvalidArgumentException;

/**
 * MySqlDialect - Specialized Dialect for MySQL and MariaDB
 *
 * Provides support for backtick delimiters (`column`), case-sensitive matching with BINARY,
 * numeric boolean representation (1/0), and the native REGEXP operator.
 *
 * Pagination (1.6.0): `LIMIT n OFFSET m`; MySQL has no OFFSET without LIMIT, so an offset alone
 * carries the documented maximum `LIMIT 18446744073709551615`.
 *
 * Text comparison ignoring case and accents (2.1.0, TextComparison::IgnoreCaseAndAccents):
 * `CONVERT(col USING utf8mb4) COLLATE utf8mb4_0900_ai_ci LIKE :p`. LIKE compares character by
 * character, so the expansions of the collation (`ß` = `ss`, `æ` = `ae`) do not apply and those
 * letters stay distinct, as in memory. Measured on MySQL 8.4 and MariaDB 11.4.13 (which accepts the
 * MySQL name as an alias of `utf8mb4_uca1400_ai_ci` and answers the same), on `utf8mb4_bin` and
 * `latin1` columns: the pt-BR, es, fr and de battery answers as in memory. Limit: the Unicode collation
 * algorithm makes a letter with a stroke a base letter plus a secondary difference, so `ø`, `đ` and
 * `ł` match `o`, `d` and `l` here and not in memory. The collation is configurable (a MariaDB older
 * than 11.4.5 needs `utf8mb4_uca1400_ai_ci`). The comparison does not use an index on the column.
 *
 * Wildcard and regular expression ignoring case and accents (2.3.0): `wildcard()` goes through the same
 * LIKE (its glob as the LIKE pattern), with the same limits. `regex()` is refused
 * (accentInsensitiveRegex() answers null, inherited): measured on MySQL 8.4 (`REGEXP_LIKE(col, :p, 'i')`
 * and `REGEXP`) and MariaDB 11.4 (`REGEXP`) over a column converted to `utf8mb4_0900_ai_ci`, the regular
 * expression engines (ICU, PCRE2) ignore the collation, so `^cora[cç][aã]o$` finds `coracao` and not
 * `Coração` (MySQL 54 to 60 and MariaDB 51 to 57 of 240 queries differ from memory, with the body
 * folded or as written); MySQL and MariaDB have no Unicode normalization function to fold the column.
 *
 * @version    2.3.0
 * @package    Antevemus\ASpecification
 * @subpackage Sql\Dialects
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class MySqlDialect extends AbstractSqlDialect
{
    /**
     * @param string $accentInsensitiveCollation `utf8mb4` collation, accent- and case-insensitive,
     *        used by the accent-insensitive LIKE (default `utf8mb4_0900_ai_ci`, MySQL 8.0+ and
     *        MariaDB 11.4.5+; `utf8mb4_uca1400_ai_ci` for MariaDB 10.10 to 11.4.4)
     * @throws InvalidArgumentException When the name is not a `utf8mb4_` collation identifier
     */
    public function __construct(private readonly string $accentInsensitiveCollation = 'utf8mb4_0900_ai_ci')
    {
        if (preg_match('/^utf8mb4_[A-Za-z0-9_]+$/', $accentInsensitiveCollation) !== 1) {
            throw new InvalidArgumentException(
                "The accent-insensitive collation must be a utf8mb4_ collation name, '{$accentInsensitiveCollation}' given."
            );
        }
    }

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

    /**
     * {@inheritdoc}
     *
     * `CONVERT(col USING utf8mb4) COLLATE <collation> LIKE :p`: the explicit collation of the column
     * side decides the comparison, whatever the column's own charset and collation.
     */
    public function formatAccentInsensitiveLike(string $column, string $paramPlaceholder): ?string
    {
        return "CONVERT({$column} USING utf8mb4) COLLATE {$this->accentInsensitiveCollation} LIKE {$paramPlaceholder}";
    }

    /**
     * The collation of the accent-insensitive LIKE.
     *
     * @return string
     */
    public function getAccentInsensitiveCollation(): string
    {
        return $this->accentInsensitiveCollation;
    }

    /** {@inheritdoc} `LIMIT n OFFSET m`; an offset alone needs the maximum limit in MySQL. */
    protected function formatPagination(string $sql, ?int $limit, ?int $offset): string
    {
        return $this->limitOffsetPagination($sql, $limit, $offset, '18446744073709551615');
    }
}
