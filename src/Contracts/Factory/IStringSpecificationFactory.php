<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Factory;

use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * IStringSpecificationFactory - Factory contract for string specifications
 *
 * Contract for factories creating string-specialized specifications,
 * including case-insensitive comparisons, regular expressions, wildcards,
 * length checks, and format validations.
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Factory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IStringSpecificationFactory extends ISpecificationFactory
{
    /**
     * Creates a specification verifying if string is null, empty, or whitespace-only.
     *
     * @return ISpecification<string> Blank string specification
     */
    public function isBlank(): ISpecification;

    /**
     * Alias for isBlank().
     *
     * @return ISpecification<string>
     */
    public function blank(): ISpecification;

    /**
     * Creates a specification verifying case-insensitive equality.
     *
     * Example:
     * <code>
     * $spec = $factory->equalIgnoringCase('HELLO');
     * $spec->isSatisfiedBy('hello'); // true
     * $spec->isSatisfiedBy('Hello'); // true
     * $spec->isSatisfiedBy('hi');    // false
     * </code>
     *
     * @param string $value Comparison target string
     * @return ISpecification<string> Case-insensitive equality specification
     */
    public function equalIgnoringCase(string $value): ISpecification;

    /**
     * Alias for equalIgnoringCase().
     *
     * @param string $value Comparison target string
     * @return ISpecification<string>
     */
    public function equalsIgnoringCase(string $value): ISpecification;

    /**
     * Alias for equalIgnoringCase().
     *
     * @param string $value Comparison target string
     * @return ISpecification<string>
     */
    public function isEqualIgnoringCase(string $value): ISpecification;

    /**
     * Creates a specification verifying regular expression match.
     *
     * Example:
     * <code>
     * $spec = $factory->matchesRegex('/^[A-Z]{3}-\d{3}$/');
     * $spec->isSatisfiedBy('ABC-123'); // true
     * $spec->isSatisfiedBy('abc-123'); // false
     * </code>
     *
     * @param string $pattern PCRE regular expression pattern
     * @return ISpecification<string> Regex match specification
     * @throws \InvalidArgumentException If regex pattern is invalid
     */
    public function matchesRegex(string $pattern): ISpecification;

    /**
     * Alias for matchesRegex().
     *
     * @param string $pattern PCRE regular expression pattern
     * @return ISpecification<string>
     */
    public function matchesRegularExpression(string $pattern): ISpecification;

    /**
     * Alias for matchesRegex().
     *
     * @param string $pattern PCRE regular expression pattern
     * @return ISpecification<string>
     */
    public function matches(string $pattern): ISpecification;

    /**
     * Creates a specification verifying wildcard match (* and ?).
     *
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
     * @param string $wildcardExpression Wildcard pattern (* and ?)
     * @return ISpecification<string> Wildcard match specification
     */
    public function matchesWildcard(string $wildcardExpression): ISpecification;

    /**
     * Alias for matchesWildcard().
     *
     * Idiomatic SQL-like usage: "like 'user_%'"
     *
     * @param string $wildcardExpression Wildcard pattern (* and ?)
     * @return ISpecification<string>
     */
    public function like(string $wildcardExpression): ISpecification;

    /**
     * Creates a specification verifying case-insensitive wildcard match.
     *
     * @param string $wildcardExpression Wildcard pattern (* and ?)
     * @return ISpecification<string> Case-insensitive wildcard match specification
     */
    public function matchesWildcardIgnoringCase(string $wildcardExpression): ISpecification;

    /**
     * Creates a specification verifying if candidate contains a substring.
     *
     * @param string $substring Substring to search for
     * @param bool $caseSensitive Whether search is case-sensitive (default: true)
     * @return ISpecification<string> Substring containment specification
     */
    public function contains(string $substring, bool $caseSensitive = true): ISpecification;

    /**
     * Creates a specification verifying if candidate starts with a prefix.
     *
     * @param string $prefix Expected prefix
     * @param bool $caseSensitive Whether check is case-sensitive (default: true)
     * @return ISpecification<string> Prefix match specification
     */
    public function startsWith(string $prefix, bool $caseSensitive = true): ISpecification;

    /**
     * Creates a specification verifying if candidate ends with a suffix.
     *
     * @param string $suffix Expected suffix
     * @param bool $caseSensitive Whether check is case-sensitive (default: true)
     * @return ISpecification<string> Suffix match specification
     */
    public function endsWith(string $suffix, bool $caseSensitive = true): ISpecification;

    /**
     * Creates a specification verifying string length.
     *
     * @param ISpecification<int> $lengthSpecification Specification for length
     * @return ISpecification<string> String length specification
     */
    public function hasLength(ISpecification $lengthSpecification): ISpecification;

    /**
     * Creates a specification verifying if candidate is a valid date string.
     *
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
     * @param string|null $format Optional specific date format. If null, tries common formats
     * @return ISpecification<string> Valid date specification
     */
    public function isValidDate(?string $format = null): ISpecification;

    /**
     * Alias for isValidDate().
     *
     * @param string|null $format Optional specific date format
     * @return ISpecification<string>
     */
    public function isDate(?string $format = null): ISpecification;

    /**
     * Creates a specification verifying if candidate corresponds to a PHP Enum case.
     *
     * @param class-string $enumClass The target Enum class to validate against
     * @return ISpecification<string>
     */
    public function enumCase(string $enumClass): ISpecification;
}
