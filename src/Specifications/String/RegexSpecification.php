<?php
declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\String;

use Antevemus\ASpecification\AbstractSpecification;

/**
 * RegexSpecification - Leaf specification for regular expression pattern validation.
 *
 * Uses native PHP `preg_match` to verify whether the candidate string matches the PCRE pattern.
 *
 * Features:
 * - PCRE pattern matching via `preg_match`
 * - Safe rejection of non-string candidates
 * - Portable view of the pattern for translators: body without PHP delimiters, modifiers apart
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @version    1.4.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\String
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class RegexSpecification extends AbstractSpecification
{
    /**
     * Initializes the specification with a regex pattern.
     *
     * @param string $pattern PCRE regular expression pattern
     */
    public function __construct(private readonly string $pattern)
    {
    }

    /**
     * Returns the configured regular expression pattern.
     *
     * @return string
     */
    public function getPattern(): string
    {
        return $this->pattern;
    }

    /**
     * Returns the pattern without the PHP delimiters and trailing modifiers (`/^ab/i` gives `^ab`).
     *
     * Translators send this body to engines that take a bare regular expression (SQL `~`, `REGEXP`,
     * `REGEXP_LIKE`), where the PHP delimiters would be read as literal characters and the pattern
     * would never match (BUG-20261007-3E3F). A pattern that is not delimited is returned unchanged.
     *
     * @return string
     */
    public function getBody(): string
    {
        return $this->splitPattern()[0];
    }

    /**
     * Returns the PCRE modifiers that follow the closing delimiter (`i`, `u`, `m`, ...), or '' when none.
     *
     * @return string
     */
    public function getModifiers(): string
    {
        return $this->splitPattern()[1];
    }

    /**
     * Splits the pattern into [body, modifiers] following the PHP delimiter rules:
     * the delimiter is the first non-alphanumeric, non-backslash, non-whitespace character;
     * bracket delimiters pair with their closing counterpart; modifiers are the letters after it.
     *
     * @return array{0: string, 1: string}
     */
    private function splitPattern(): array
    {
        $pattern = $this->pattern;
        if ($pattern === '' || ctype_alnum($pattern[0]) || $pattern[0] === '\\' || ctype_space($pattern[0])) {
            return [$pattern, ''];
        }

        $open = $pattern[0];
        $close = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'][$open] ?? $open;
        $end = strrpos($pattern, $close);
        if ($end === false || $end === 0) {
            return [$pattern, ''];
        }

        $modifiers = substr($pattern, $end + 1);
        if ($modifiers !== '' && !ctype_alpha($modifiers)) {
            return [$pattern, ''];
        }

        return [substr($pattern, 1, $end - 1), $modifiers];
    }

    /**
     * Verifies whether the provided candidate satisfies the regex pattern.
     *
     * @param mixed $candidate Value or object to validate
     * @return bool True if candidate matches the regular expression pattern
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        if (!is_string($candidate)) {
            return false;
        }

        return preg_match($this->pattern, $candidate) === 1;
    }

    /**
     * Returns the type of candidate validated by this specification.
     *
     * @return string
     */
    public function getType(): string
    {
        return 'string';
    }
}
