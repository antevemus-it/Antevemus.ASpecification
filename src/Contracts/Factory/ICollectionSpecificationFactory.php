<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Factory;

use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * ICollectionSpecificationFactory - Factory contract for collection specifications
 *
 * Contract for factories creating specifications that operate over collections (arrays/iterables).
 * Provides methods for validating collection size, emptiness, element counts,
 * and percentage-based criteria.
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Factory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface ICollectionSpecificationFactory extends ISpecificationFactory
{
    /**
     * Creates a specification that verifies collection size.
     *
     * Example:
     * <code>
     * $spec = $factory->hasSize(equalTo(5));
     * $spec->isSatisfiedBy([1, 2, 3, 4, 5]); // true
     * $spec->isSatisfiedBy([1, 2, 3]); // false
     * </code>
     *
     * @param ISpecification<int> $sizeSpecification Specification for collection size
     * @return ISpecification Collection size specification
     */
    public function hasSize(ISpecification $sizeSpecification): ISpecification;

    /**
     * Alias for hasSize().
     *
     * @param ISpecification<int> $sizeSpecification Specification for collection size
     * @return ISpecification
     */
    public function haveSize(ISpecification $sizeSpecification): ISpecification;

    /**
     * Alias for hasSize().
     *
     * @param ISpecification<int> $sizeSpecification Specification for collection size
     * @return ISpecification
     */
    public function hasSizeOf(ISpecification $sizeSpecification): ISpecification;

    /**
     * Alias for hasSize().
     *
     * @param ISpecification<int> $sizeSpecification Specification for collection size
     * @return ISpecification
     */
    public function haveSizeOf(ISpecification $sizeSpecification): ISpecification;

    /**
     * Creates a specification verifying if the collection is empty.
     *
     * Equivalent to: hasSize(equalTo(0))
     *
     * @return ISpecification Empty collection specification
     */
    public function isEmpty(): ISpecification;

    /**
     * Alias for isEmpty().
     *
     * @return ISpecification
     */
    public function empty(): ISpecification;

    /**
     * Creates a specification verifying count of elements satisfying a criterion.
     *
     * Example:
     * <code>
     * // Verifies that exactly 3 elements are greater than 10
     * $spec = $factory->include(equalTo(3), greaterThan(10));
     * $spec->isSatisfiedBy([5, 12, 15, 8, 20]); // true (3 elements: 12, 15, 20)
     * </code>
     *
     * @param ISpecification<int> $countSpecification Specification for matching element count
     * @param ISpecification $elementSpecification Specification matching elements must satisfy
     * @return ISpecification
     */
    public function include(ISpecification $countSpecification, ISpecification $elementSpecification): ISpecification;

    /**
     * Alias for include().
     *
     * @param ISpecification<int> $countSpecification Specification for matching count
     * @param ISpecification $elementSpecification Specification matching elements must satisfy
     * @return ISpecification
     */
    public function includes(ISpecification $countSpecification, ISpecification $elementSpecification): ISpecification;

    /**
     * Creates a specification verifying percentage of elements satisfying a criterion.
     *
     * Example:
     * <code>
     * // Verifies that at least 50% of elements are even
     * $spec = $factory->includePercentageOf(atLeast(50), isEven());
     * $spec->isSatisfiedBy([2, 4, 5, 8, 9]); // true (60% even)
     * </code>
     *
     * @param ISpecification<int> $percentageSpecification Specification for percentage (0-100)
     * @param ISpecification $elementSpecification Specification matching elements must satisfy
     * @return ISpecification
     */
    public function includePercentageOf(ISpecification $percentageSpecification, ISpecification $elementSpecification): ISpecification;

    /**
     * Alias for includePercentageOf().
     *
     * @param ISpecification<int> $percentageSpecification Specification for percentage
     * @param ISpecification $elementSpecification Specification matching elements must satisfy
     * @return ISpecification
     */
    public function includesPercentageOf(ISpecification $percentageSpecification, ISpecification $elementSpecification): ISpecification;

    /**
     * Creates a specification verifying that all elements satisfy a criterion.
     *
     * Equivalent to: include(equalTo(count($collection)), $elementSpecification)
     *
     * Example:
     * <code>
     * $spec = $factory->all(greaterThan(0));
     * $spec->isSatisfiedBy([1, 2, 3, 4]); // true
     * $spec->isSatisfiedBy([1, 2, 0, 4]); // false
     * </code>
     *
     * @param ISpecification $elementSpecification Specification all elements must satisfy
     * @return ISpecification Universal element specification
     */
    public function all(ISpecification $elementSpecification): ISpecification;

    /**
     * Creates a specification verifying that at least one element satisfies a criterion.
     *
     * Equivalent to: include(atLeast(1), $elementSpecification)
     *
     * Example:
     * <code>
     * $spec = $factory->any(greaterThan(100));
     * $spec->isSatisfiedBy([50, 75, 120]); // true (120 > 100)
     * $spec->isSatisfiedBy([50, 75, 90]); // false
     * </code>
     *
     * @param ISpecification $elementSpecification Specification at least one element must satisfy
     * @return ISpecification Existential element specification
     */
    public function any(ISpecification $elementSpecification): ISpecification;

    /**
     * Creates a specification verifying that no elements satisfy a criterion.
     *
     * Equivalent to: include(equalTo(0), $elementSpecification)
     *
     * @param ISpecification $elementSpecification Specification no elements should satisfy
     * @return ISpecification Universal negation element specification
     */
    public function none(ISpecification $elementSpecification): ISpecification;
}
