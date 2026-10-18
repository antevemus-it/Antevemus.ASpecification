<?php
declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\String;

use Antevemus\ASpecification\AbstractSpecification;
use InvalidArgumentException;

/**
 * RegexSpecification - Leaf specification for regular expression pattern validation.
 *
 * Uses native PHP `preg_match` to verify whether the candidate string matches the PCRE pattern.
 *
 * Features:
 * - PCRE pattern matching via `preg_match`
 * - Safe rejection of non-string candidates
 * - Portable view of the pattern for translators: body without PHP delimiters, modifiers apart
 * - An optional TextComparison (2.3.0): none keeps the pattern as written (its modifiers decide, as
 *   before); `CaseSensitive` changes nothing either; `IgnoreCase` adds the `i` modifier;
 *   `IgnoreCaseAndAccents` folds the accents of the body with TextComparison::foldAccents() (NFD,
 *   nonspacing marks removed, NFC, the case kept so that an escape such as `\D` keeps its meaning),
 *   adds `i` and `u`, and matches it against the candidate folded the same way, so `/^cora[cç]ao$/`
 *   and `/^coracao$/` both match `'CORAÇÃO'`. It requires `ext-intl` (MissingExtensionException
 *   without it) and a body in valid UTF-8; a candidate that is not valid UTF-8 is not satisfied. With a
 *   comparison the pattern must be delimited (`/.../`). getPattern(), getBody() and getModifiers()
 *   describe the pattern that is evaluated (the folded body, the added modifiers), so a translator
 *   that only knows the modifiers already translates `IgnoreCase`; one that translates this class
 *   must check ignoresAccents() first, since the candidate must be folded too.
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @version    2.3.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\String
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class RegexSpecification extends AbstractSpecification
{
    /** The pattern that is evaluated: the one written, or its form for the comparison (2.3.0). */
    private readonly string $pattern;

    /**
     * Initializes the specification with a regex pattern.
     *
     * @param string $pattern PCRE regular expression pattern
     * @param TextComparison|null $comparison null (default) keeps the pattern as written; IgnoreCase adds
     *        `i`; IgnoreCaseAndAccents folds the accents of body and candidate and adds `iu` (2.3.0)
     * @throws \Antevemus\ASpecification\Specifications\Exceptions\MissingExtensionException With
     *         IgnoreCaseAndAccents when ext-intl is not loaded
     * @throws InvalidArgumentException With a comparison, when the pattern has no delimiters; with
     *         IgnoreCaseAndAccents, when the body is not valid UTF-8
     */
    public function __construct(string $pattern, private readonly ?TextComparison $comparison = null)
    {
        $this->pattern = $comparison === null ? $pattern : self::patternFor($pattern, $comparison);
    }

    /**
     * Returns the regular expression pattern that is evaluated: the pattern as written, or, with a
     * comparison (2.3.0), the pattern with the added modifiers and, for IgnoreCaseAndAccents, the folded body.
     *
     * @return string
     */
    public function getPattern(): string
    {
        return $this->pattern;
    }

    /**
     * The comparison the specification was built with, or null when the pattern decides alone (2.3.0).
     *
     * @return TextComparison|null
     */
    public function getComparison(): ?TextComparison
    {
        return $this->comparison;
    }

    /**
     * Whether the candidate's accents are folded before the match (TextComparison::IgnoreCaseAndAccents, 2.3.0).
     *
     * @return bool
     */
    public function ignoresAccents(): bool
    {
        return $this->comparison !== null && $this->comparison->ignoresAccents();
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
        return self::split($this->pattern);
    }

    /**
     * splitPattern() over any pattern.
     *
     * @param string $pattern
     * @return array{0: string, 1: string}
     */
    private static function split(string $pattern): array
    {
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
     * The pattern evaluated for a comparison (2.3.0): CaseSensitive keeps it; IgnoreCase adds `i`;
     * IgnoreCaseAndAccents folds the accents of the body (never its case) and adds `i` and `u`.
     *
     * @param string $pattern Pattern as written
     * @param TextComparison $comparison
     * @return string
     * @throws InvalidArgumentException When the pattern has no delimiters, or (IgnoreCaseAndAccents) the body is not valid UTF-8
     */
    private static function patternFor(string $pattern, TextComparison $comparison): string
    {
        if ($comparison->ignoresAccents()) {
            TextComparison::assertAccentFoldingAvailable();
        }

        [$body, $modifiers] = self::split($pattern);
        if ($body === $pattern) {
            throw new InvalidArgumentException(
                "A regular expression compared with a TextComparison must be delimited (e.g. '/^ab/'), '{$pattern}' given."
            );
        }
        if ($comparison === TextComparison::CaseSensitive) {
            return $pattern;
        }

        // The closing delimiter is the character before the modifiers as written
        $close = $pattern[strlen($pattern) - strlen($modifiers) - 1];
        $added = ['i'];
        if ($comparison->ignoresAccents()) {
            $folded = TextComparison::foldAccents($body);
            if ($folded === null) {
                throw new InvalidArgumentException('A text compared ignoring case and accents must be valid UTF-8.');
            }
            $body = $folded;
            $added[] = 'u';
        }
        foreach ($added as $modifier) {
            if (!str_contains($modifiers, $modifier)) {
                $modifiers .= $modifier;
            }
        }

        return $pattern[0] . $body . $close . $modifiers;
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

        if ($this->ignoresAccents()) {
            $folded = TextComparison::foldAccents($candidate);

            return $folded !== null && preg_match($this->pattern, $folded) === 1;
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
