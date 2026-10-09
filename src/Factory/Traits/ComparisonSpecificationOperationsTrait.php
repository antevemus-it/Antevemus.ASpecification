<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Factory\Traits;

use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Specifications\Comparison\NotEqualSpecification;

/**
 * ComparisonSpecificationOperationsTrait - Trait aggregating comparison operations (IComparisonSpecificationFactory)
 *
 * Features:
 * - Relational specifications (=, !=, <, <=, >, >=)
 * - Set membership (in)
 * - Semantic aliases (atMost, atLeast, under, over, exactly, etc.)
 *
 * @version    1.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Factory\Traits
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
trait ComparisonSpecificationOperationsTrait
{
    /**
     * Creates equality specification (==).
     *
     * @param mixed $value Comparison target value
     * @return ISpecification Equality specification
     */
    public function equalTo(mixed $value): ISpecification
    {
        return $this->comparisonFactory->equalTo($value);
    }

    /**
     * Creates an opt-in loose equality specification (`==`, PHP coercion applies).
     *
     * @param mixed $value Comparison target value
     * @return ISpecification Loose equality specification
     */
    public function looselyEqualTo(mixed $value): ISpecification
    {
        return $this->comparisonFactory->looselyEqualTo($value);
    }

    /**
     * Creates inequality specification (!=).
     *
     * @param mixed $value Comparison target value
     * @return ISpecification Inequality specification
     */
    public function notEqualTo(mixed $value): ISpecification
    {
        return new NotEqualSpecification($value);
    }

    /**
     * Creates less-than specification (<).
     *
     * @param mixed $value Upper bound value (exclusive)
     * @return ISpecification Less-than specification
     */
    public function lessThan(mixed $value): ISpecification
    {
        return $this->comparisonFactory->lessThan($value);
    }

    /**
     * Creates less-than-or-equal specification (<=).
     *
     * @param mixed $value Upper bound value (inclusive)
     * @return ISpecification Less-than-or-equal specification
     */
    public function lessThanOrEqualTo(mixed $value): ISpecification
    {
        return $this->comparisonFactory->lessThanOrEqualTo($value);
    }

    /**
     * Creates greater-than specification (>).
     *
     * @param mixed $value Lower bound value (exclusive)
     * @return ISpecification Greater-than specification
     */
    public function greaterThan(mixed $value): ISpecification
    {
        return $this->comparisonFactory->greaterThan($value);
    }

    /**
     * Creates greater-than-or-equal specification (>=).
     *
     * @param mixed $value Lower bound value (inclusive)
     * @return ISpecification Greater-than-or-equal specification
     */
    public function greaterThanOrEqualTo(mixed $value): ISpecification
    {
        return $this->comparisonFactory->greaterThanOrEqualTo($value);
    }

    /**
     * Creates set membership specification (one InSpecification leaf since 1.5.0).
     *
     * Values may be passed variadically or as a single array; an empty set never matches.
     *
     * @param mixed ...$values Set of allowed values, or a single array holding them
     * @return ISpecification Set membership specification
     */
    public function in(mixed ...$values): ISpecification
    {
        return $this->comparisonFactory->in(...$values);
    }

    /**
     * Creates the negated set membership specification: not(in(...)).
     *
     * @param mixed ...$values Set of rejected values, or a single array holding them
     * @return ISpecification Negated set membership specification
     */
    public function notIn(mixed ...$values): ISpecification
    {
        return new \Antevemus\ASpecification\Specifications\NotSpecification($this->comparisonFactory->in(...$values));
    }

    /**
     * Alias for equalTo().
     *
     * @param mixed $value Comparison target value
     * @return ISpecification
     */
    public function equals(mixed $value): ISpecification
    {
        return $this->equalTo($value);
    }

    /**
     * Alias for equalTo().
     *
     * @param mixed $value Comparison target value
     * @return ISpecification
     */
    public function isEqual(mixed $value): ISpecification
    {
        return $this->equalTo($value);
    }

    /**
     * Alias for equalTo().
     *
     * @param mixed $value Comparison target value
     * @return ISpecification
     */
    public function isEqualTo(mixed $value): ISpecification
    {
        return $this->equalTo($value);
    }

    /**
     * Alias for equalTo().
     *
     * @param mixed $value Comparison target value
     * @return ISpecification
     */
    public function sameAs(mixed $value): ISpecification
    {
        return $this->equalTo($value);
    }

    /**
     * Alias for equalTo().
     *
     * @param mixed $value Comparison target value
     * @return ISpecification
     */
    public function isSameAs(mixed $value): ISpecification
    {
        return $this->equalTo($value);
    }

    /**
     * Alias for notEqualTo().
     *
     * @param mixed $value Comparison target value
     * @return ISpecification
     */
    public function notEqual(mixed $value): ISpecification
    {
        return new NotEqualSpecification($value);
    }

    /**
     * Alias for notEqualTo().
     *
     * @param mixed $value Comparison target value
     * @return ISpecification
     */
    public function isNot(mixed $value): ISpecification
    {
        if ($value instanceof ISpecification) {
            return $this->not($value);
        }
        return new NotEqualSpecification($value);
    }

    /**
     * Alias for notEqualTo().
     *
     * @param mixed $value Comparison target value
     * @return ISpecification
     */
    public function isNotEqualTo(mixed $value): ISpecification
    {
        return new NotEqualSpecification($value);
    }

    /**
     * Alias for notEqualTo().
     *
     * @param mixed $value Comparison target value
     * @return ISpecification
     */
    public function differentFrom(mixed $value): ISpecification
    {
        return new NotEqualSpecification($value);
    }

    /**
     * Alias for notEqualTo().
     *
     * @param mixed $value Comparison target value
     * @return ISpecification
     */
    public function isDifferentFrom(mixed $value): ISpecification
    {
        return new NotEqualSpecification($value);
    }

    /**
     * Alias for lessThan().
     *
     * @param mixed $value Upper bound value (exclusive)
     * @return ISpecification
     */
    public function under(mixed $value): ISpecification
    {
        return $this->lessThan($value);
    }

    /**
     * Alias for lessThan().
     *
     * @param mixed $value Upper bound value (exclusive)
     * @return ISpecification
     */
    public function below(mixed $value): ISpecification
    {
        return $this->lessThan($value);
    }

    /**
     * Alias for lessThanOrEqualTo().
     *
     * Fluent usage: "atMost 100"
     *
     * @param mixed $value Upper bound value (inclusive)
     * @return ISpecification
     */
    public function atMost(mixed $value): ISpecification
    {
        return $this->comparisonFactory->atMost($value);
    }

    /**
     * Alias for greaterThan().
     *
     * @param mixed $value Lower bound value (exclusive)
     * @return ISpecification
     */
    public function over(mixed $value): ISpecification
    {
        return $this->greaterThan($value);
    }

    /**
     * Alias for greaterThan().
     *
     * @param mixed $value Lower bound value (exclusive)
     * @return ISpecification
     */
    public function above(mixed $value): ISpecification
    {
        return $this->greaterThan($value);
    }

    /**
     * Alias for greaterThan().
     *
     * Fluent usage: "moreThan 5"
     *
     * @param mixed $value Lower bound value (exclusive)
     * @return ISpecification
     */
    public function moreThan(mixed $value): ISpecification
    {
        return $this->comparisonFactory->moreThan($value);
    }

    /**
     * Alias for greaterThanOrEqualTo().
     *
     * Fluent usage: "atLeast 18"
     *
     * @param mixed $value Lower bound value (inclusive)
     * @return ISpecification
     */
    public function atLeast(mixed $value): ISpecification
    {
        return $this->comparisonFactory->atLeast($value);
    }

    /**
     * Alias for in().
     *
     * Fluent usage: "isOneOf 'active', 'pending', 'approved'"
     *
     * @param mixed ...$values Set of allowed values
     * @return ISpecification
     */
    public function isOneOfValues(mixed ...$values): ISpecification
    {
        return $this->comparisonFactory->isOneOfValues(...$values);
    }

    /**
     * Alias for in().
     *
     * Fluent usage: "isEitherOf 'yes', 'no'"
     *
     * @param mixed ...$values Set of allowed values
     * @return ISpecification
     */
    public function isEitherOfValues(mixed ...$values): ISpecification
    {
        return $this->comparisonFactory->isEitherOfValues(...$values);
    }

    /**
     * Alias for in().
     *
     * Fluent usage: "oneOfValues 1, 2, 3"
     *
     * @param mixed ...$values Set of allowed values
     * @return ISpecification
     */
    public function oneOfValues(mixed ...$values): ISpecification
    {
        return $this->comparisonFactory->oneOfValues(...$values);
    }

    /**
     * Alias for in().
     *
     * Fluent usage: "eitherValue 'A', 'B'"
     *
     * @param mixed ...$values Set of allowed values
     * @return ISpecification
     */
    public function eitherValue(mixed ...$values): ISpecification
    {
        return $this->comparisonFactory->eitherValue(...$values);
    }

    /**
     * Alias for equalTo().
     *
     * Fluent usage: "exactly 10"
     *
     * @param mixed $value Comparison target value
     * @return ISpecification
     */
    public function exactly(mixed $value): ISpecification
    {
        return $this->comparisonFactory->exactly($value);
    }

    // ==========================================
    // Domian SpecificationFactory aliases (1.4.4)
    // ==========================================

    /**
     * Domian alias for equalTo().
     *
     * @param mixed $value Comparison target value
     * @return ISpecification
     */
    public function createEqualSpecification(mixed $value): ISpecification
    {
        return $this->equalTo($value);
    }

    /**
     * Domian alias for equalTo().
     *
     * @param mixed $value Comparison target value
     * @return ISpecification
     */
    public function anObjectEqualTo(mixed $value): ISpecification
    {
        return $this->equalTo($value);
    }

    /**
     * Domian alias for equalTo().
     *
     * @param mixed $value Comparison target value
     * @return ISpecification
     */
    public function objectEqualTo(mixed $value): ISpecification
    {
        return $this->equalTo($value);
    }

    /**
     * Domian alias for lessThan().
     *
     * @param mixed $value Upper bound value (exclusive)
     * @return ISpecification
     */
    public function isLessThan(mixed $value): ISpecification
    {
        return $this->lessThan($value);
    }

    /**
     * Domian alias for lessThanOrEqualTo().
     *
     * @param mixed $value Upper bound value (inclusive)
     * @return ISpecification
     */
    public function isLessThanOrEqualTo(mixed $value): ISpecification
    {
        return $this->lessThanOrEqualTo($value);
    }

    /**
     * Domian alias for greaterThan().
     *
     * @param mixed $value Lower bound value (exclusive)
     * @return ISpecification
     */
    public function isGreaterThan(mixed $value): ISpecification
    {
        return $this->greaterThan($value);
    }

    /**
     * Domian alias for greaterThanOrEqualTo().
     *
     * @param mixed $value Lower bound value (inclusive)
     * @return ISpecification
     */
    public function isGreaterThanOrEqualTo(mixed $value): ISpecification
    {
        return $this->greaterThanOrEqualTo($value);
    }
}
