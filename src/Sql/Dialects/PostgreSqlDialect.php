<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Sql\Dialects;

use Antevemus\ASpecification\Contracts\Sql\ISqlRegexRewriter;
use Antevemus\ASpecification\Contracts\Sql\ISqlWhereClause;
use Antevemus\ASpecification\Sql\SqlWhereClause;
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
 * Wildcard and regular expression ignoring case and accents (2.3.0): `wildcard()` goes through the same
 * LIKE (its glob as the LIKE pattern). `regex()` is `<fold(col)> ~* :p`, the column folded by the same
 * expression (or the configured function) and the body bound with its accents folded in PHP
 * (TextComparison::foldAccents(), the case kept), with PCRE's word boundaries `\b` and `\B` written as
 * the `\y` and `\Y` of PostgreSQL's regular expressions (where `\b` is a backspace; rewriteRegexBody(),
 * which SqlQueryVisitor also applies to `regex()` without a comparison since 2.3.0, bug #73). Measured on
 * PostgreSQL 17 (forward 039): the battery of pt-BR, es, fr and de words answers as in memory; the
 * other class shorthands keep the classification of the database, as in `regex()` without a
 * comparison: `\d` is the ASCII digits only (PCRE with `u` also takes the other decimal digits, 360 code
 * points of the BMP), `\w` and `\s` differ from PCRE on 605 and 5 code points of the BMP (`²`, `½`,
 * spacing marks of Indic scripts, no-break spaces), and a "C" collation column classifies only ASCII
 * letters as `\w`.
 *
 * @version    2.3.0
 * @package    Antevemus\ASpecification
 * @subpackage Sql\Dialects
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class PostgreSqlDialect extends AbstractSqlDialect implements ISqlRegexRewriter
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
     * {@inheritdoc}
     *
     * `<fold(col)> ~* :p`, the column folded as in formatAccentInsensitiveLike() and the folded body bound
     * with `\b`/`\B` written as `\y`/`\Y` (rewriteRegexBody()).
     *
     * @version 2.3.0
     */
    public function accentInsensitiveRegex(string $column, string $paramPlaceholder, string $foldedBody): ?ISqlWhereClause
    {
        $folded = $this->accentFoldingFunction !== null
            ? "{$this->accentFoldingFunction}(lower({$column}))"
            : self::builtInAccentFolding($column);

        return new SqlWhereClause("{$folded} ~* {$paramPlaceholder}", [$paramPlaceholder => $this->rewriteRegexBody($foldedBody)]);
    }

    /**
     * {@inheritdoc}
     *
     * PCRE's word boundaries in PostgreSQL's syntax (2.3.0, bug #73): `\b` becomes `\y` and `\B` becomes
     * `\Y` outside a bracket expression; inside one (with its POSIX classes), and in a `\Q...\E`
     * quotation, the text is kept (`[\b]` is a backspace in both). Every other character and escape is
     * kept as it is, so `/\bfoo\b/` binds `\yfoo\y`.
     *
     * @version 2.3.0
     */
    public function rewriteRegexBody(string $body): string
    {
        $out = '';
        $inBracket = false;
        $length = strlen($body);
        for ($i = 0; $i < $length; $i++) {
            $character = $body[$i];
            if ($character === '\\' && $i + 1 < $length) {
                $next = $body[++$i];
                if ($next === 'Q') {
                    // \Q...\E: kept verbatim up to \E (or the end)
                    $end = strpos($body, '\\E', $i + 1);
                    $stop = $end === false ? $length : $end + 2;
                    $out .= '\\Q' . substr($body, $i + 1, $stop - $i - 1);
                    $i = $stop - 1;
                    continue;
                }
                $out .= !$inBracket && $next === 'b' ? '\\y' : (!$inBracket && $next === 'B' ? '\\Y' : '\\' . $next);
                continue;
            }
            if ($inBracket) {
                if ($character === '[' && $i + 1 < $length && $body[$i + 1] === ':' && ($close = strpos($body, ':]', $i + 2)) !== false) {
                    // POSIX class [:alpha:] inside the bracket expression
                    $out .= substr($body, $i, $close + 2 - $i);
                    $i = $close + 1;
                    continue;
                }
                if ($character === ']') {
                    $inBracket = false;
                }
                $out .= $character;
                continue;
            }
            $out .= $character;
            if ($character === '[') {
                $inBracket = true;
                // A leading ^ negates; a ] right after the opening (or the ^) is a literal member
                if ($i + 1 < $length && $body[$i + 1] === '^') {
                    $out .= '^';
                    $i++;
                }
                if ($i + 1 < $length && $body[$i + 1] === ']') {
                    $out .= ']';
                    $i++;
                }
            }
        }

        return $out;
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
