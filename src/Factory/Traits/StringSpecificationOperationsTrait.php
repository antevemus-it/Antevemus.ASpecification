<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Factory\Traits;

use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * StringSpecificationOperationsTrait - Trait aggregating string and textual expression operations (IStringSpecificationFactory).
 *
 * Provides delegation methods forwarding to the underlying string specification factory.
 *
 * Features:
 * - Blank string and length validation
 * - Case-insensitive comparisons and regex matching
 * - Wildcard pattern matching and substring containment (contains, startsWith, endsWith)
 *
 * @version    1.1.0
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
     * @return ISpecification<string> Regex match specification
     * @throws \InvalidArgumentException If regex pattern is invalid
     */
    public function matchesRegex(string $pattern): ISpecification
    {
        return $this->stringFactory->matchesRegex($pattern);
    }

    /**
     * Alias for matchesRegex().
     *
     * @param string $pattern Regular expression pattern (PCRE)
     * @return ISpecification<string>
     */
    public function matchesRegularExpression(string $pattern): ISpecification
    {
        return $this->stringFactory->matchesRegularExpression($pattern);
    }

    /**
     * Alias for matchesRegex().
     *
     * @param string $pattern Regular expression pattern (PCRE)
     * @return ISpecification<string>
     */
    public function matches(string $pattern): ISpecification
    {
        return $this->stringFactory->matches($pattern);
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
     * @return ISpecification<string> Wildcard match specification
     */
    public function matchesWildcard(string $wildcardExpression): ISpecification
    {
        return $this->stringFactory->matchesWildcard($wildcardExpression);
    }

    /**
     * Alias for matchesWildcard().
     *
     * Idiomatic SQL-like usage: "like 'user_%'"
     *
     * @param string $wildcardExpression Wildcard pattern expression (* and ?)
     * @return ISpecification<string>
     */
    public function like(string $wildcardExpression): ISpecification
    {
        return $this->stringFactory->like($wildcardExpression);
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
     * @param bool $caseSensitive Whether search is case-sensitive (default: true)
     * @return ISpecification<string> Substring containment specification
     */
    public function contains(string $substring, bool $caseSensitive = true): ISpecification
    {
        return $this->stringFactory->contains($substring, $caseSensitive);
    }

    /**
     * Creates a specification verifying whether a string starts with a prefix.
     *
     * @param string $prefix Expected prefix
     * @param bool $caseSensitive Whether prefix check is case-sensitive (default: true)
     * @return ISpecification<string> Prefix specification
     */
    public function startsWith(string $prefix, bool $caseSensitive = true): ISpecification
    {
        return $this->stringFactory->startsWith($prefix, $caseSensitive);
    }

    /**
     * Creates a specification verifying whether a string ends with a suffix.
     *
     * @param string $suffix Expected suffix
     * @param bool $caseSensitive Whether suffix check is case-sensitive (default: true)
     * @return ISpecification<string> Suffix specification
     */
    public function endsWith(string $suffix, bool $caseSensitive = true): ISpecification
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
}
