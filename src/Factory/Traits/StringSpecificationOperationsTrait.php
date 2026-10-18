<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Factory\Traits;

use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Specifications\Logical\DefaultValueSpecification;
use Antevemus\ASpecification\Specifications\String\TextComparison;

/**
 * StringSpecificationOperationsTrait - Trait aggregating string and textual expression operations (IStringSpecificationFactory).
 *
 * Provides delegation methods forwarding to the underlying string specification factory.
 *
 * Features:
 * - Blank string and length validation
 * - Case-insensitive comparisons and regex matching
 * - Wildcard pattern matching and substring containment (contains, startsWith, endsWith)
 * - Accent-insensitive text comparison (TextComparison::IgnoreCaseAndAccents, equalIgnoringCaseAndAccents; 2.1.0;
 *   wildcard and regex, 2.3.0)
 *
 * @version    2.3.0
 * @package    Antevemus\ASpecification
 * @subpackage Factory\Traits
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
trait StringSpecificationOperationsTrait
{
    /**
     * Creates a specification verifying whether a string is empty or blank.
     *
     * Evaluates to true if the string is null, empty (""), or contains only whitespace characters.
     *
     * @return ISpecification<string> Blank string specification
     */
    public function isBlank(): ISpecification
    {
        return $this->stringFactory->isBlank();
    }

    /**
     * Alias for isBlank().
     *
     * @return ISpecification<string>
     */
    public function blank(): ISpecification
    {
        return $this->stringFactory->blank();
    }

    /**
     * Creates a specification verifying string equality ignoring case.
     *
     * Compares strings in a case-insensitive manner.
     *
     * Example:
     * <code>
     * $spec = $factory->equalIgnoringCase('HELLO');
     * $spec->isSatisfiedBy('hello'); // true
     * $spec->isSatisfiedBy('Hello'); // true
     * $spec->isSatisfiedBy('hi');    // false
     * </code>
     *
     * @param string $value Target string for comparison
     * @return ISpecification<string> Case-insensitive equality specification
     */
    public function equalIgnoringCase(string $value): ISpecification
    {
        return $this->stringFactory->equalIgnoringCase($value);
    }

    /**
     * Alias for equalIgnoringCase().
     *
     * @param string $value Target string for comparison
     * @return ISpecification<string>
     */
    public function equalsIgnoringCase(string $value): ISpecification
    {
        return $this->stringFactory->equalsIgnoringCase($value);
    }

    /**
     * Alias for equalIgnoringCase().
     *
     * @param string $value Target string for comparison
     * @return ISpecification<string>
     */
    public function isEqualIgnoringCase(string $value): ISpecification
    {
        return $this->stringFactory->isEqualIgnoringCase($value);
    }

    /**
     * Creates a specification verifying string equality ignoring case and accents (2.1.0).
     *
     * Both strings are folded by TextComparison::foldCaseAndAccents(): `'sao paulo'` matches
     * `'São Paulo'`, `'strasse'` does not match `'Straße'`. Requires ext-intl.
     *
     * @param string $value Target string for comparison
     * @return ISpecification<string> Case- and accent-insensitive equality specification
     * @throws \Antevemus\ASpecification\Specifications\Exceptions\MissingExtensionException When ext-intl is not loaded
     */
    public function equalIgnoringCaseAndAccents(string $value): ISpecification
    {
        return $this->stringFactory->equalIgnoringCaseAndAccents($value);
    }

    /**
     * Creates a specification verifying regex pattern matching.
     *
     * Example:
     * <code>
     * $spec = $factory->matchesRegex('/^[A-Z]{3}-\d{3}$/');
     * $spec->isSatisfiedBy('ABC-123'); // true
     * $spec->isSatisfiedBy('abc-123'); // false
     * </code>
     *
     * @param string $pattern Regular expression pattern (PCRE)
     * @param TextComparison|null $comparison null (default) keeps the pattern as written, IgnoreCase adds `i`,
     *        TextComparison::IgnoreCaseAndAccents folds the accents of body and candidate and adds `iu`
     *        (2.3.0, requires ext-intl)
     * @return ISpecification<string> Regex match specification
     * @throws \InvalidArgumentException If regex pattern is invalid
     */
    public function matchesRegex(string $pattern, ?TextComparison $comparison = null): ISpecification
    {
        return $this->stringFactory->matchesRegex($pattern, $comparison);
    }

    /**
     * Alias for matchesRegex().
     *
     * @param string $pattern Regular expression pattern (PCRE)
     * @param TextComparison|null $comparison null (default) keeps the pattern as written, IgnoreCase adds `i`,
     *        TextComparison::IgnoreCaseAndAccents folds the accents of body and candidate and adds `iu`
     *        (2.3.0, requires ext-intl)
     * @return ISpecification<string>
     */
    public function matchesRegularExpression(string $pattern, ?TextComparison $comparison = null): ISpecification
    {
        return $this->stringFactory->matchesRegularExpression($pattern, $comparison);
    }

    /**
     * Alias for matchesRegex().
     *
     * @param string $pattern Regular expression pattern (PCRE)
     * @param TextComparison|null $comparison null (default) keeps the pattern as written, IgnoreCase adds `i`,
     *        TextComparison::IgnoreCaseAndAccents folds the accents of body and candidate and adds `iu`
     *        (2.3.0, requires ext-intl)
     * @return ISpecification<string>
     */
    public function matches(string $pattern, ?TextComparison $comparison = null): ISpecification
    {
        return $this->stringFactory->matches($pattern, $comparison);
    }

    /**
     * Creates a specification verifying wildcard expression matching.
     *
     * Supports wildcards: * (any sequence) and ? (single character).
     * Case-sensitive by default.
     *
     * Example:
     * <code>
     * $spec = $factory->matchesWildcard('user_*');
     * $spec->isSatisfiedBy('user_123'); // true
     * $spec->isSatisfiedBy('user_abc'); // true
     * $spec->isSatisfiedBy('admin_123'); // false
     * </code>
     *
     * @param string $wildcardExpression Wildcard pattern expression (* and ?)
     * @param bool|TextComparison $caseSensitive `true` (default) compares exactly, `false` ignores case,
     *        TextComparison::IgnoreCaseAndAccents also ignores accents (2.3.0, requires ext-intl)
     * @return ISpecification<string> Wildcard match specification
     */
    public function matchesWildcard(string $wildcardExpression, bool|TextComparison $caseSensitive = true): ISpecification
    {
        return $this->stringFactory->matchesWildcard($wildcardExpression, $caseSensitive);
    }

    /**
     * Alias for matchesWildcard().
     *
     * Idiomatic SQL-like usage: "like 'user_%'"
     *
     * @param string $wildcardExpression Wildcard pattern expression (* and ?)
     * @param bool|TextComparison $caseSensitive `true` (default) compares exactly, `false` ignores case,
     *        TextComparison::IgnoreCaseAndAccents also ignores accents (2.3.0, requires ext-intl)
     * @return ISpecification<string>
     */
    public function like(string $wildcardExpression, bool|TextComparison $caseSensitive = true): ISpecification
    {
        return $this->stringFactory->like($wildcardExpression, $caseSensitive);
    }

    /**
     * Creates a specification verifying wildcard matching ignoring case.
     *
     * Similar to matchesWildcard() but case-insensitive.
     *
     * @param string $wildcardExpression Wildcard pattern expression (* and ?)
     * @return ISpecification<string> Case-insensitive wildcard specification
     */
    public function matchesWildcardIgnoringCase(string $wildcardExpression): ISpecification
    {
        return $this->stringFactory->matchesWildcardIgnoringCase($wildcardExpression);
    }

    /**
     * Creates a specification verifying whether a string contains a substring.
     *
     * @param string $substring Substring to search for
     * @param bool|TextComparison $caseSensitive `true` (default) compares exactly, `false` ignores case,
     *        TextComparison::IgnoreCaseAndAccents also ignores accents (2.1.0, requires ext-intl)
     * @return ISpecification<string> Substring containment specification
     */
    public function contains(string $substring, bool|TextComparison $caseSensitive = true): ISpecification
    {
        return $this->stringFactory->contains($substring, $caseSensitive);
    }

    /**
     * Creates a specification verifying whether a string starts with a prefix.
     *
     * @param string $prefix Expected prefix
     * @param bool|TextComparison $caseSensitive `true` (default) compares exactly, `false` ignores case,
     *        TextComparison::IgnoreCaseAndAccents also ignores accents (2.1.0, requires ext-intl)
     * @return ISpecification<string> Prefix specification
     */
    public function startsWith(string $prefix, bool|TextComparison $caseSensitive = true): ISpecification
    {
        return $this->stringFactory->startsWith($prefix, $caseSensitive);
    }

    /**
     * Creates a specification verifying whether a string ends with a suffix.
     *
     * @param string $suffix Expected suffix
     * @param bool|TextComparison $caseSensitive `true` (default) compares exactly, `false` ignores case,
     *        TextComparison::IgnoreCaseAndAccents also ignores accents (2.1.0, requires ext-intl)
     * @return ISpecification<string> Suffix specification
     */
    public function endsWith(string $suffix, bool|TextComparison $caseSensitive = true): ISpecification
    {
        return $this->stringFactory->endsWith($suffix, $caseSensitive);
    }

    /**
     * Creates a specification verifying string length against an integer specification.
     *
     * @param ISpecification<int> $lengthSpecification Specification for length value
     * @return ISpecification<string> String length specification
     */
    public function hasLength(ISpecification $lengthSpecification): ISpecification
    {
        return $this->stringFactory->hasLength($lengthSpecification);
    }

    /**
     * Creates a specification verifying whether a string is a valid date.
     *
     * Attempts to parse string as date using standard PHP date formats.
     * Supports formats: Y-m-d, d/m/Y, d-m-Y, d.m.Y, Ymd, etc.
     *
     * Example:
     * <code>
     * $spec = $factory->isValidDate();
     * $spec->isSatisfiedBy('2025-01-15'); // true
     * $spec->isSatisfiedBy('15/01/2025'); // true
     * $spec->isSatisfiedBy('invalid');    // false
     * </code>
     *
     * @param string|null $format Specific date format (optional). If null, tries common formats
     * @return ISpecification<string> Date validity specification
     */
    public function isValidDate(?string $format = null): ISpecification
    {
        return $this->stringFactory->isValidDate($format);
    }

    /**
     * Alias for isValidDate().
     *
     * @param string|null $format Specific date format (optional)
     * @return ISpecification<string>
     */
    public function isDate(?string $format = null): ISpecification
    {
        return $this->stringFactory->isDate($format);
    }

    /**
     * Creates a specification validating whether a string matches a case in a PHP Enum.
     *
     * @param class-string $enumClass The target Enum class name
     * @return ISpecification<string> Enum case specification
     */
    public function enumCase(string $enumClass): ISpecification
    {
        return $this->stringFactory->enumCase($enumClass);
    }

    // ==========================================
    // Domian SpecificationFactory aliases (1.4.4)
    // ==========================================

    /**
     * Domian `blankString()`: a text that is blank (DefaultValueSpecification<String>: empty or
     * whitespace). Since 2.2.0 null is NOT blank here either (it is not a text), as in isBlank();
     * only the untyped defaultValue() accepts null.
     *
     * @return ISpecification
     */
    public function blankString(): ISpecification
    {
        return new DefaultValueSpecification('string');
    }

    /**
     * Domian alias for blankString().
     *
     * @return ISpecification
     */
    public function isBlankString(): ISpecification
    {
        return $this->blankString();
    }

    /**
     * Domian alias for blankString().
     *
     * @return ISpecification
     */
    public function createBlankStringSpecification(): ISpecification
    {
        return $this->blankString();
    }

    /**
     * Domian alias for equalIgnoringCase().
     *
     * @param string $value Expected string
     * @return ISpecification
     */
    public function createEqualIgnoreCaseStringSpecification(string $value): ISpecification
    {
        return $this->equalIgnoringCase($value);
    }

    /**
     * Domian alias for isDate(): the pattern uses the PHP date format ('Y-m-d'), not SimpleDateFormat.
     *
     * @param string $format PHP date format
     * @return ISpecification
     */
    public function createDateStringSpecification(string $format): ISpecification
    {
        return $this->isDate($format);
    }

    /**
     * Domian alias for enumCase().
     *
     * @param class-string $enumClass Enum class name
     * @return ISpecification
     */
    public function createEnumNameStringSpecification(string $enumClass): ISpecification
    {
        return $this->enumCase($enumClass);
    }

    /**
     * Domian alias for enumCase().
     *
     * @param class-string $enumClass Enum class name
     * @return ISpecification
     */
    public function isEnum(string $enumClass): ISpecification
    {
        return $this->enumCase($enumClass);
    }

    /**
     * Domian alias for matchesWildcard().
     *
     * @param string $wildcardExpression Wildcard expression (* and ?)
     * @param bool|TextComparison $caseSensitive `true` (default) compares exactly, `false` ignores case,
     *        TextComparison::IgnoreCaseAndAccents also ignores accents (2.3.0, requires ext-intl)
     * @return ISpecification
     */
    public function matchesWildcardExpression(string $wildcardExpression, bool|TextComparison $caseSensitive = true): ISpecification
    {
        return $this->matchesWildcard($wildcardExpression, $caseSensitive);
    }

    /**
     * Domian alias for matchesWildcardIgnoringCase().
     *
     * @param string $wildcardExpression Wildcard expression (* and ?)
     * @return ISpecification
     */
    public function matchesWildcardExpressionIgnoringCase(string $wildcardExpression): ISpecification
    {
        return $this->matchesWildcardIgnoringCase($wildcardExpression);
    }
}
