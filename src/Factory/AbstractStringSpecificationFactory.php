<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Factory;

use Antevemus\ASpecification\Contracts\Factory\IStringSpecificationFactory;
use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * AbstractStringSpecificationFactory - Base abstract factory for string specifications
 *
 * Provides default alias methods and validation helpers for concrete string specifications.
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Factory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class AbstractStringSpecificationFactory implements IStringSpecificationFactory
{
    /**
     * Validates a regular expression pattern.
     *
     * Helper method for concrete implementations to validate regex patterns
     * prior to creating specifications.
     *
     * @param string $pattern Regex pattern to validate
     * @throws \InvalidArgumentException If pattern is invalid
     */
    protected function validateRegexPattern(string $pattern): void
    {
        if (empty($pattern)) {
            throw new \InvalidArgumentException('Regex pattern cannot be empty');
        }

        // Test compiling the pattern to validate
        $result = @preg_match($pattern, '');
        if ($result === false) {
            throw new \InvalidArgumentException("Invalid regex pattern: {$pattern}");
        }
    }

    /**
     * Validates a string value.
     *
     * Helper method for concrete implementations to validate strings
     * prior to creating specifications.
     *
     * @param string $value String to validate
     * @param bool $allowEmpty Whether empty strings are permitted (default: false)
     * @throws \InvalidArgumentException If string is invalid
     */
    protected function validateString(string $value, bool $allowEmpty = false): void
    {
        if (!$allowEmpty && empty($value)) {
            throw new \InvalidArgumentException('String value cannot be empty');
        }
    }

    /**
     * {@inheritdoc}
     */
    abstract public function isBlank(): ISpecification;

    /**
     * {@inheritdoc}
     */
    public function blank(): ISpecification
    {
        return $this->isBlank();
    }

    /**
     * {@inheritdoc}
     */
    abstract public function equalIgnoringCase(string $value): ISpecification;

    /**
     * {@inheritdoc}
     */
    public function equalsIgnoringCase(string $value): ISpecification
    {
        return $this->equalIgnoringCase($value);
    }

    /**
     * {@inheritdoc}
     */
    public function isEqualIgnoringCase(string $value): ISpecification
    {
        return $this->equalIgnoringCase($value);
    }

    /**
     * {@inheritdoc}
     */
    abstract public function matchesRegex(string $pattern): ISpecification;

    /**
     * {@inheritdoc}
     */
    public function matchesRegularExpression(string $pattern): ISpecification
    {
        return $this->matchesRegex($pattern);
    }

    /**
     * {@inheritdoc}
     */
    public function matches(string $pattern): ISpecification
    {
        return $this->matchesRegex($pattern);
    }

    /**
     * {@inheritdoc}
     */
    abstract public function matchesWildcard(string $wildcardExpression): ISpecification;

    /**
     * {@inheritdoc}
     */
    public function like(string $wildcardExpression): ISpecification
    {
        return $this->matchesWildcard($wildcardExpression);
    }

    /**
     * {@inheritdoc}
     */
    abstract public function matchesWildcardIgnoringCase(string $wildcardExpression): ISpecification;

    /**
     * {@inheritdoc}
     */
    abstract public function contains(string $substring, bool $caseSensitive = true): ISpecification;

    /**
     * {@inheritdoc}
     */
    abstract public function startsWith(string $prefix, bool $caseSensitive = true): ISpecification;

    /**
     * {@inheritdoc}
     */
    abstract public function endsWith(string $suffix, bool $caseSensitive = true): ISpecification;

    /**
     * {@inheritdoc}
     */
    abstract public function hasLength(ISpecification $lengthSpecification): ISpecification;

    /**
     * {@inheritdoc}
     */
    abstract public function isValidDate(?string $format = null): ISpecification;

    /**
     * {@inheritdoc}
     */
    public function isDate(?string $format = null): ISpecification
    {
        return $this->isValidDate($format);
    }

    /**
     * {@inheritdoc}
     */
    public function enumCase(string $enumClass): ISpecification
    {
        return new \Antevemus\ASpecification\Specifications\String\EnumNameStringSpecification($enumClass);
    }
}
