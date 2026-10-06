<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts;

/**
 * ICompositeSpecification - Contract for composite specifications formed by logical operators.
 *
 * Part of the Evans/Fowler Specifications pattern.
 *
 * Represents a composite specification created by combining multiple specifications
 * using logical operators (AND, OR, NOT, WHERE).
 *
 * Extends ISpecification and serves as the return type for composition methods,
 * enabling fluent method chaining and Abstract Syntax Tree (AST) inspection.
 *
 * Features:
 * - Fluent property-targeted composition (andWhere, orWhere)
 * - Binary tree operand inspection (getLeftSide, getRightSide)
 * - Flattened child specification retrieval (getSpecifications)
 * - Partial satisfaction decomposition (remainderUnsatisfiedBy)
 *
 * @template T
 * @extends ISpecification<T>
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 * @see        https://www.martinfowler.com/apsupp/spec.pdf The Specifications Pattern
 */
interface ICompositeSpecification extends ISpecification
{
    /**
     * Creates a logical conjunction (AND) with a parameterized property specification.
     *
     * Combines this composite specification with a property specification targeting the named accessible property/getter.
     *
     * Example:
     * <code>
     * $spec = $userSpec->where('age', $ageSpec)
     *                  ->andWhere('address', new CitySpecification('São Paulo'));
     * </code>
     *
     * @template F
     * @param string $accessibleObjectName Accessible property or method name
     * @param ISpecification<F> $accessibleObjectSpecification Specification for the target property value
     * @return ICompositeSpecification<T> New composite specification: this AND parameterized spec
     * @throws \InvalidArgumentException If parameter is empty or types are incompatible
     */
    public function andWhere(string $accessibleObjectName, ISpecification $accessibleObjectSpecification): ICompositeSpecification;

    /**
     * Creates a logical disjunction (OR) with a parameterized property specification.
     *
     * Combines this composite specification with a property specification targeting the named accessible property/getter.
     *
     * Example:
     * <code>
     * $spec = $userSpec->where('role', $roleSpec)
     *                  ->orWhere('permissions', new PermissionSpecification('admin'));
     * </code>
     *
     * @template F
     * @param string $accessibleObjectName Accessible property or method name
     * @param ISpecification<F> $accessibleObjectSpecification Specification for the target property value
     * @return ICompositeSpecification<T> New composite specification: this OR parameterized spec
     * @throws \InvalidArgumentException If parameter is empty or types are incompatible
     */
    public function orWhere(string $accessibleObjectName, ISpecification $accessibleObjectSpecification): ICompositeSpecification;

    /**
     * Returns the left-hand side specification of the binary composite.
     *
     * In binary operations (AND, OR), returns the first operand specification.
     *
     * @return ISpecification<T>|null Left-hand side specification, or null if unary/not applicable
     */
    public function getLeftSide(): ?ISpecification;

    /**
     * Returns the right-hand side specification of the binary composite.
     *
     * In binary operations (AND, OR), returns the second operand specification.
     *
     * @return ISpecification<T>|null Right-hand side specification, or null if unary/not applicable
     */
    public function getRightSide(): ?ISpecification;

    /**
     * Returns all leaf and sub-specifications comprising this composite tree.
     *
     * Useful for diagnostics, AST traversal, and debugging.
     *
     * @return array<ISpecification<T>> Array of specifications participating in this composite
     */
    public function getSpecifications(): array;

    /**
     * Partially satisfied specification (Remainder decomposition).
     *
     * Returns a composite specification containing all sub-components of this specification
     * that were NOT satisfied by the given candidate.
     *
     * If the candidate satisfies all requirements, returns null.
     *
     * Example:
     * <code>
     * $userSpec = $ageSpec->and($emailSpec)->and($termsSpec);
     * $remainder = $userSpec->remainderUnsatisfiedBy($user);
     * if ($remainder !== null) {
     *     // Feedback on unsatisfied parts
     * }
     * </code>
     *
     * @param T $candidate Target candidate object
     * @return ICompositeSpecification<T>|null Specification with unsatisfied components, or null if fully satisfied
     */
    public function remainderUnsatisfiedBy(object $candidate): ?ICompositeSpecification;
}
