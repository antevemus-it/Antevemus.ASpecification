<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Factory\Traits;

use Antevemus\ASpecification\Contracts\ICompositeSpecification;

/**
 * TypeSpecificationOperationsTrait - Trait aggregating type specification factory operations and fluent aliases (ITypeSpecificationFactory).
 *
 * Provides delegation methods forwarding to the underlying type specification factory.
 *
 * Features:
 * - Creation of typed composite specifications
 * - Idiomatic fluent aliases (the, instanceOf, specify, allOfType, etc.)
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Factory\Traits
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
trait TypeSpecificationOperationsTrait
{
    /**
     * Creates a composite specification for a specific type or class.
     *
     * This is the primary method of the type factory. All other methods are fluent aliases delegating to it.
     *
     * @template T
     * @param class-string<T> $type Fully qualified class or interface name
     * @return ICompositeSpecification<T> Specification verifying whether object is of the specified type
     * @throws \InvalidArgumentException If type name is empty or class does not exist
     */
    public function createSpecificationFor(string $type): ICompositeSpecification
    {
        return $this->typeFactory->createSpecificationFor($type);
    }

    /**
     * Fluent alias for createSpecificationFor().
     *
     * Idiomatic English usage: "the User"
     *
     * @template T
     * @param class-string<T> $type Fully qualified class or interface name
     * @return ICompositeSpecification<T>
     */
    public function the(string $type): ICompositeSpecification
    {
        return $this->typeFactory->the($type);
    }

    /**
     * Fluent alias for createSpecificationFor().
     *
     * Idiomatic usage: "instanceOf User"
     *
     * @template T
     * @param class-string<T> $type Fully qualified class or interface name
     * @return ICompositeSpecification<T>
     */
    public function instanceOf(string $type): ICompositeSpecification
    {
        return $this->typeFactory->instanceOf($type);
    }

    /**
     * Fluent alias for createSpecificationFor().
     *
     * Idiomatic usage: "specify User"
     *
     * @template T
     * @param class-string<T> $type Fully qualified class or interface name
     * @return ICompositeSpecification<T>
     */
    public function specify(string $type): ICompositeSpecification
    {
        return $this->typeFactory->specify($type);
    }

    /**
     * Fluent alias for createSpecificationFor().
     *
     * Idiomatic usage: "instancesOf User"
     *
     * @template T
     * @param class-string<T> $type Fully qualified class or interface name
     * @return ICompositeSpecification<T>
     */
    public function instancesOf(string $type): ICompositeSpecification
    {
        return $this->typeFactory->instancesOf($type);
    }

    /**
     * Fluent alias for createSpecificationFor().
     *
     * Idiomatic usage: "instanceOfType User"
     *
     * @template T
     * @param class-string<T> $type Fully qualified class or interface name
     * @return ICompositeSpecification<T>
     */
    public function instanceOfType(string $type): ICompositeSpecification
    {
        return $this->typeFactory->instanceOfType($type);
    }

    /**
     * Fluent alias for createSpecificationFor().
     *
     * Idiomatic usage: "instancesOfType User"
     *
     * @template T
     * @param class-string<T> $type Fully qualified class or interface name
     * @return ICompositeSpecification<T>
     */
    public function instancesOfType(string $type): ICompositeSpecification
    {
        return $this->typeFactory->instancesOfType($type);
    }

    /**
     * Fluent alias for createSpecificationFor().
     *
     * Idiomatic usage: "anInstanceOfType User"
     *
     * @template T
     * @param class-string<T> $type Fully qualified class or interface name
     * @return ICompositeSpecification<T>
     */
    public function anInstanceOfType(string $type): ICompositeSpecification
    {
        return $this->typeFactory->anInstanceOfType($type);
    }

    /**
     * Fluent alias for createSpecificationFor().
     *
     * Idiomatic usage: "allOfType User"
     *
     * @template T
     * @param class-string<T> $type Fully qualified class or interface name
     * @return ICompositeSpecification<T>
     */
    public function allOfType(string $type): ICompositeSpecification
    {
        return $this->typeFactory->allOfType($type);
    }

    /**
     * Fluent alias for createSpecificationFor().
     *
     * Idiomatic usage: "allInstancesOfType User"
     *
     * @template T
     * @param class-string<T> $type Fully qualified class or interface name
     * @return ICompositeSpecification<T>
     */
    public function allInstancesOfType(string $type): ICompositeSpecification
    {
        return $this->typeFactory->allInstancesOfType($type);
    }

    /**
     * Fluent alias for createSpecificationFor().
     *
     * Idiomatic usage: "specifyA User"
     *
     * @template T
     * @param class-string<T> $type Fully qualified class or interface name
     * @return ICompositeSpecification<T>
     */
    public function specifyA(string $type): ICompositeSpecification
    {
        return $this->typeFactory->specifyA($type);
    }

    /**
     * Fluent alias for createSpecificationFor().
     *
     * Idiomatic usage: "specifyAn Entity"
     *
     * @template T
     * @param class-string<T> $type Fully qualified class or interface name
     * @return ICompositeSpecification<T>
     */
    public function specifyAn(string $type): ICompositeSpecification
    {
        return $this->typeFactory->specifyAn($type);
    }
}
