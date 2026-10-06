<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Helpers;

use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * ISpecificationHelper - Contract for Type-Safety and Identity Operations
 *
 * Defines auxiliary methods for common specification operations,
 * including runtime type-safe candidate verification and entity identity specification factory.
 *
 * Features:
 * - Runtime type-safe candidate evaluation
 * - Dynamic generation of unique entity identity specifications
 *
 * Example usage:
 * <code>
 * // Type-safe candidate check
 * $spec = new ActiveUserSpecification();
 * $user = new User();
 * $helper = new SpecificationHelper();
 *
 * if ($helper->typeSafeIsSatisfiedBy($spec, $user)) {
 *     echo "Active user!";
 * }
 *
 * // Create unique specification for entity
 * $uniqueSpec = $helper->createUniqueSpecificationFor($user);
 * // Result: (id = 123)
 * </code>
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Helpers
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface ISpecificationHelper
{
    /**
     * Determine whether a specification is satisfied by a candidate in a type-safe manner.
     *
     * Performs dynamic type checking at runtime, guaranteeing that the candidate matches
     * the expected type before delegating to isSatisfiedBy().
     *
     * Behavior:
     * - Incompatible candidate types return false instead of throwing a TypeError
     * - Null candidates return false
     *
     * @template T
     * @param ISpecification<T> $specification Specification to verify
     * @param object|null $candidate Candidate object to test
     * @return bool True if candidate matches expected type AND satisfies specification; false otherwise
     * @throws \InvalidArgumentException If specification is null
     */
    public function typeSafeIsSatisfiedBy(ISpecification $specification, ?object $candidate): bool;

    /**
     * Create a specification uniquely identifying a specific domain entity instance.
     *
     * Inspects entity identity properties (IEntity contract, public getters, or reflection)
     * and compiles an identity-bound specification.
     *
     * @template T
     * @param T $entity Target domain entity
     * @return ISpecification<T> Specification uniquely identifying the entity
     * @throws \InvalidArgumentException If entity is null
     * @throws \RuntimeException If entity does not possess identifiable identity properties
     */
    public function createUniqueSpecificationFor(object $entity): ISpecification;
}
