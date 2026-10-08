<?php
declare(strict_types=1);

namespace Antevemus\ASpecification\Sql;

use Antevemus\ASpecification\Specifications\String\LiteralPatternSpecification;

/**
 * LikePattern - SQL LIKE pattern with the wildcard escaping shared by every translator.
 *
 * Turns a literal (startsWith/endsWith/contains) or a glob (like()/wildcard(), `*` and `?`)
 * into the value bound to a LIKE operator, escaping the `%`, `_` and `!` characters that
 * belong to the text and reporting whether an `ESCAPE '!'` clause must follow the operator.
 * The clause is only requested when the text contains one of those characters or a backslash
 * (MySQL's default escape, neutralised by an explicit ESCAPE), so the common case emits the
 * same SQL as before (BUG-20261007-3E3F, BUG-20261007-ZY6E, BUG-20261007-3TVR).
 *
 * Features:
 * - One escaping rule for the SQL visitor and the TCriteria visitor
 * - Position-aware shaping of literals through LiteralPatternSpecification::toWildcardPattern()
 * - Glob translation `*` -> `%`, `?` -> `_` applied after the literal characters are escaped
 *
 * @internal Implementation detail of the translators; not part of the public API.
 * @version    1.4.1
 * @package    Antevemus\ASpecification
 * @subpackage Sql
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class LikePattern
{
    /** LIKE escape character, portable across the supported dialects and the Adianti bridge. */
    public const ESCAPE = '!';

    /**
     * @param string $pattern Value to bind to the LIKE operator (wildcards and escapes applied)
     * @param bool $escaped Whether the pattern relies on the ESCAPE character
     */
    private function __construct(
        public readonly string $pattern,
        public readonly bool $escaped
    ) {
    }

    /**
     * Pattern for a prefix, suffix or substring literal.
     *
     * @param LiteralPatternSpecification $specification
     * @return self
     */
    public static function fromLiteral(LiteralPatternSpecification $specification): self
    {
        $literal = $specification->getLiteral();
        $escaped = self::needsEscape($literal);

        return new self(
            $specification->toWildcardPattern($escaped ? self::escape($literal) : $literal, '%'),
            $escaped
        );
    }

    /**
     * Pattern for a glob where `*` means any sequence and `?` any single character; every other
     * character, including `%` and `_`, is literal (fnmatch semantics of WildcardSpecification).
     *
     * @param string $glob
     * @return self
     */
    public static function fromGlob(string $glob): self
    {
        $escaped = self::needsEscape($glob);
        $text = $escaped ? self::escape($glob) : $glob;

        return new self(str_replace(['*', '?'], ['%', '_'], $text), $escaped);
    }

    /**
     * SQL fragment to append after the LIKE operand: ` ESCAPE '!'` or an empty string.
     *
     * @return string
     */
    public function escapeClause(): string
    {
        return $this->escaped ? " ESCAPE '" . self::ESCAPE . "'" : '';
    }

    /**
     * @param string $text
     * @return bool
     */
    private static function needsEscape(string $text): bool
    {
        return strpbrk($text, '%_\\' . self::ESCAPE) !== false;
    }

    /**
     * Escapes the escape character first, then the LIKE wildcards.
     *
     * @param string $text
     * @return string
     */
    private static function escape(string $text): string
    {
        return str_replace(
            [self::ESCAPE, '%', '_'],
            [self::ESCAPE . self::ESCAPE, self::ESCAPE . '%', self::ESCAPE . '_'],
            $text
        );
    }
}
