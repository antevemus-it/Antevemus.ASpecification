<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Sql\Dialects;

use InvalidArgumentException;

/**
 * PostgreSqlDialect - Specialized Dialect for PostgreSQL
 *
 * Provides support for double-quoted identifiers, native boolean types (TRUE/FALSE),
 * ILIKE operator for case-insensitive matching, and native POSIX regular expressions (~ and ~*).
 *
 * Pagination (1.6.0): `LIMIT n OFFSET m`.
 *
 * Text comparison ignoring case and accents (2.1.0, TextComparison::IgnoreCaseAndAccents): both sides
 * of the LIKE are folded by the same expression, `lower(normalize(regexp_replace(normalize(x, NFD),
 * <marks>, '', 'g'), NFC))`, where `<marks>` is the bracket expression of the Unicode nonspacing marks
 * (UnicodeNonspacingMarks), the in-memory folding written in SQL: no extension is needed, `ß`, `æ`,
 * `ø`... stay themselves, and every function in it is IMMUTABLE. Measured on PostgreSQL 17: the
 * 50 303 letters and marks of the BMP fold as in memory under the default (libc) collation; a column
 * with the "C" collation lowers ASCII letters only (the limit 1.14.0 documents for IgnoreCase).
 * `normalize()` needs a UTF8 database (PostgreSQL 13+). Optionally the folding is a function of the
 * database, `fn(lower(col)) LIKE fn(lower(:p))` (an IMMUTABLE wrapper over builtInAccentFolding() that
 * an expression index can use, or `unaccent` from the extension of the same name, which also maps
 * `ß` to `ss`, `æ` to `ae`, `ø` to `o` and so answers differently from memory for those letters).
 * Neither form uses an ordinary index on the column.
 *
 * @version    2.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Sql\Dialects
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class PostgreSqlDialect extends AbstractSqlDialect
{
    /** Plain or schema-qualified function name. */
    private const FUNCTION_NAME = '/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?$/';

    /**
     * @param string|null $accentFoldingFunction Function of the database that folds accents in the
     *        accent-insensitive LIKE (e.g. 'unaccent' or an IMMUTABLE wrapper such as 'public.f_fold'),
     *        applied as `fn(lower(x))`; null (default) uses the built-in expression, which needs no
     *        extension and answers as the in-memory comparison
     * @throws InvalidArgumentException When the name is not a plain or schema-qualified identifier
     */
    public function __construct(private readonly ?string $accentFoldingFunction = null)
    {
        if ($accentFoldingFunction !== null && preg_match(self::FUNCTION_NAME, $accentFoldingFunction) !== 1) {
            throw new InvalidArgumentException(
                "The accent folding function must be a plain or schema-qualified identifier, '{$accentFoldingFunction}' given."
            );
        }
    }

    /** {@inheritdoc} */
    public function getFamily(): string
    {
        return 'pgsql';
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
        $op = $caseSensitive ? '~' : '~*';
        return "{$column} {$op} {$paramPlaceholder}";
    }

    /** {@inheritdoc} */
    public function supportsRegex(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     *
     * Built-in: `<fold(col)> LIKE <fold(:p)>` with builtInAccentFolding(); with a configured function:
     * `fn(lower(col)) LIKE fn(lower(:p))`.
     */
    public function formatAccentInsensitiveLike(string $column, string $paramPlaceholder): ?string
    {
        if ($this->accentFoldingFunction !== null) {
            $fn = $this->accentFoldingFunction;

            return "{$fn}(lower({$column})) LIKE {$fn}(lower({$paramPlaceholder}))";
        }

        return self::builtInAccentFolding($column) . ' LIKE ' . self::builtInAccentFolding($paramPlaceholder);
    }

    /**
     * The function of the database configured for the accent folding, or null for the built-in expression.
     *
     * @return string|null
     */
    public function getAccentFoldingFunction(): ?string
    {
        return $this->accentFoldingFunction;
    }

    /**
     * The built-in folding of an SQL text expression, equal to TextComparison::foldCaseAndAccents():
     * `lower(normalize(regexp_replace(normalize(<operand>, NFD), E'[\\u0300-\\u036F...]', '', 'g'), NFC))`.
     * The bracket expression is an `E''` literal, so it reads the same whatever
     * `standard_conforming_strings` says. Useful to declare an IMMUTABLE wrapper for an expression index:
     * `CREATE FUNCTION f_fold(t text) RETURNS text LANGUAGE sql IMMUTABLE PARALLEL SAFE RETURN
     * <builtInAccentFolding('t')>`, then `new PostgreSqlDialect('f_fold')`.
     *
     * @param string $operand SQL text expression (escaped column, placeholder or parameter name)
     * @return string
     */
    public static function builtInAccentFolding(string $operand): string
    {
        $marks = "E'" . str_replace('\\', '\\\\', UnicodeNonspacingMarks::bracketExpression()) . "'";

        return "lower(normalize(regexp_replace(normalize({$operand}, NFD), {$marks}, '', 'g'), NFC))";
    }

    /** {@inheritdoc} `LIMIT n OFFSET m`; an offset alone is `OFFSET m`. */
    protected function formatPagination(string $sql, ?int $limit, ?int $offset): string
    {
        return $this->limitOffsetPagination($sql, $limit, $offset);
    }
}
