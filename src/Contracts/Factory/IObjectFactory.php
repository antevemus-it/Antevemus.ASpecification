<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Factory;

use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * IObjectFactory - Object creation factory based on specifications
 *
 * Interface describing a factory for creating domain objects based on specifications.
 * The Factory pattern combined with the Specification pattern enables creating objects
 * that satisfy specific criteria encapsulated in specifications.
 *
 * This approach is useful for:
 * - Creating configured objects based on business rules
 * - Implementing complex builders driven by specifications
 * - Generating mock/stub test objects satisfying specifications
 * - Implementing criteria-based creation patterns
 *
 * Example usage:
 * <code>
 * // Specification for a premium product
 * $premiumSpec = (new PriceGreaterThanSpecification(100))
 *     ->and(new CategorySpecification('Premium'))
 *     ->and(new InStockSpecification());
 *
 * // Factory creates a product satisfying the specification
 * $productFactory = new ProductFactory();
 * $premiumProduct = $productFactory->create($premiumSpec);
 *
 * // Verify created product satisfies the specification
 * assert($premiumSpec->isSatisfiedBy($premiumProduct));
 * </code>
 *
 * @template T
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Factory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IObjectFactory
{
    /**
     * Creates an object of type T specified by the given specification.
     *
     * The implementation of this factory must ensure the returned object
     * satisfies the provided specification. Otherwise, the behavior
     * depends on the factory's implementation strategy.
     *
     * Common implementation strategies:
     * - Throw an exception if an object satisfying the spec cannot be created
     * - Return null if an object satisfying the spec cannot be created
     * - Create the best possible approximation
     * - Create a default object and log a warning
     *
     * Example:
     * <code>
     * $spec = new ActiveUserSpecification();
     * $user = $factory->createObjectSpecifiedBy($spec);
     *
     * // The created user satisfies the specification
     * assert($spec->isSatisfiedBy($user));
     * </code>
     *
     * @template T
     * @param ISpecification<T> $specification Specification the created object must satisfy
     * @return T Instance of type T specified by the specification
     * @throws \InvalidArgumentException If the specification is null
     * @throws \RuntimeException If the factory cannot create an object satisfying the spec
     */
    public function createObjectSpecifiedBy(ISpecification $specification): mixed;

    /**
     * Convenience alias for createObjectSpecifiedBy().
     *
     * Provides concise syntax for creating objects based on specifications.
     *
     * Example:
     * <code>
     * // Long form
     * $user = $factory->createObjectSpecifiedBy($spec);
     *
     * // Short form (alias)
     * $user = $factory->create($spec);
     * </code>
     *
     * @template T
     * @param ISpecification<T> $specification Specification the created object must satisfy
     * @return T Instance of type T specified by the specification
     * @throws \InvalidArgumentException If the specification is null
     * @throws \RuntimeException If the factory cannot create an object satisfying the spec
     * @see createObjectSpecifiedBy()
     */
    public function create(ISpecification $specification): mixed;
}
