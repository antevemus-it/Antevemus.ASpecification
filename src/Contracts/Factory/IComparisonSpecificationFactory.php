<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Factory;

use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * IComparisonSpecificationFactory - Factory contract for comparison specifications
 *
 * Contract for factories creating value comparison specifications.
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Factory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IComparisonSpecificationFactory extends ISpecificationFactory
{
    /**
     * Creates a specification verifying equality (==).
     *
     * @param mixed $value Comparison target value
     * @return ISpecification Equality specification
     */
    public function equalTo(mixed $value): ISpecification;

    /**
     * Alias for equalTo().
     *
     * Fluent usage: "is 10"
     *
     * @param mixed $value Comparison target value
     * @return ISpecification
     */
    public function is(mixed $value): ISpecification;

    /**
     * Alias for equalTo().
     *
     * Fluent usage: "exactly 10"
     *
     * @param mixed $value Comparison target value
     * @return ISpecification
     */
    public function exactly(mixed $value): ISpecification;

    /**
     * Creates a specification verifying if value is strictly less than (<).
     *
     * @param mixed $value Upper bound value (exclusive)
     * @return ISpecification Less-than specification
     */
    public function lessThan(mixed $value): ISpecification;

    /**
     * Alias for lessThan().
     *
     * Fluent date/value usage: "before 2025-01-01"
     *
     * @param mixed $value Upper bound value (exclusive)
     * @return ISpecification
     */
    public function before(mixed $value): ISpecification;

    /**
     * Creates a specification verifying if value is less than or equal to (<=).
     *
     * @param mixed $value Upper bound value (inclusive)
     * @return ISpecification Less-than-or-equal specification
     */
    public function lessThanOrEqualTo(mixed $value): ISpecification;

    /**
     * Alias for lessThanOrEqualTo().
     *
     * Fluent usage: "atMost 100"
     *
     * @param mixed $value Upper bound value (inclusive)
     * @return ISpecification
     */
    public function atMost(mixed $value): ISpecification;

    /**
     * Creates a specification verifying if value is strictly greater than (>).
     *
     * @param mixed $value Lower bound value (exclusive)
     * @return ISpecification Greater-than specification
     */
    public function greaterThan(mixed $value): ISpecification;

    /**
     * Alias for greaterThan().
     *
     * Fluent date/value usage: "after 2025-01-01"
     *
     * @param mixed $value Lower bound value (exclusive)
     * @return ISpecification
     */
    public function after(mixed $value): ISpecification;

    /**
     * Alias for greaterThan().
     *
     * Fluent usage: "moreThan 5"
     *
     * @param mixed $value Lower bound value (exclusive)
     * @return ISpecification
     */
    public function moreThan(mixed $value): ISpecification;

    /**
     * Creates a specification verifying if value is greater than or equal to (>=).
     *
     * @param mixed $value Lower bound value (inclusive)
     * @return ISpecification Greater-than-or-equal specification
     */
    public function greaterThanOrEqualTo(mixed $value): ISpecification;

    /**
     * Alias for greaterThanOrEqualTo().
     *
     * Fluent usage: "atLeast 18"
     *
     * @param mixed $value Lower bound value (inclusive)
     * @return ISpecification
     */
    public function atLeast(mixed $value): ISpecification;

    /**
     * Creates a specification verifying if candidate is member of a set (OR of equalities).
     *
     * Accepts raw values and creates equality specifications under the hood.
     * Equivalent to: equalTo(value1) OR equalTo(value2) OR equalTo(value3) OR ...
     *
     * Example:
     * <code>
     * $spec = $factory->in('active', 'pending', 'approved');
     * $spec->isSatisfiedBy('active');   // true
     * $spec->isSatisfiedBy('pending');  // true
     * $spec->isSatisfiedBy('rejected'); // false
     * </code>
     *
     * @param mixed ...$values Set of allowed values
     * @return ISpecification Set membership specification
     * @throws \InvalidArgumentException If no values are provided
     */
    public function in(mixed ...$values): ISpecification;

    /**
     * Alias for in().
     *
     * Fluent usage: "isOneOf 'active', 'pending', 'approved'"
     *
     * @param mixed ...$values Set of allowed values
     * @return ISpecification
     */
    public function isOneOfValues(mixed ...$values): ISpecification;

    /**
     * Alias for in().
     *
     * Fluent usage: "isEitherOf 'yes', 'no'"
     *
     * @param mixed ...$values Set of allowed values
     * @return ISpecification
     */
    public function isEitherOfValues(mixed ...$values): ISpecification;

    /**
     * Alias for in().
     *
     * Fluent usage: "oneOfValues 1, 2, 3"
     *
     * @param mixed ...$values Set of allowed values
     * @return ISpecification
     */
    public function oneOfValues(mixed ...$values): ISpecification;

    /**
     * Alias for in().
     *
     * Fluent usage: "eitherValue 'A', 'B'"
     *
     * @param mixed ...$values Set of allowed values
     * @return ISpecification
     */
    public function eitherValue(mixed ...$values): ISpecification;
}
