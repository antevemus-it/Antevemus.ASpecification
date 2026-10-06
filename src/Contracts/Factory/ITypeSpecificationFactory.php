<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Factory;

use Antevemus\ASpecification\Contracts\ICompositeSpecification;

/**
 * ITypeSpecificationFactory - Factory contract for class type specifications
 *
 * Contract for factories creating type-checking specifications based on class or interface names.
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Factory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface ITypeSpecificationFactory extends ISpecificationFactory
{
    /**
     * Creates a composite specification for a specific class or interface type.
     *
     * This is the primary factory method. All other methods are fluent aliases delegating to this method.
     *
     * @template T
     * @param class-string<T> $type Fully qualified class or interface name
     * @return ICompositeSpecification<T> Specification verifying object is an instance of the specified type
     * @throws \InvalidArgumentException If type is empty or does not exist
     */
    public function createSpecificationFor(string $type): ICompositeSpecification;

    /**
     * Fluent alias for createSpecificationFor().
     *
     * Idiomatic English usage: "the User"
     *
     * @template T
     * @param class-string<T> $type Fully qualified class or interface name
     * @return ICompositeSpecification<T>
     */
    public function the(string $type): ICompositeSpecification;

    /**
     * Fluent alias for createSpecificationFor().
     *
     * Idiomatic English usage: "a Product"
     *
     * @template T
     * @param class-string<T> $type Fully qualified class or interface name
     * @return ICompositeSpecification<T>
     */
    public function a(string $type): ICompositeSpecification;

    /**
     * Fluent alias for createSpecificationFor().
     *
     * Idiomatic English usage: "an Order"
     *
     * @template T
     * @param class-string<T> $type Fully qualified class or interface name
     * @return ICompositeSpecification<T>
     */
    public function an(string $type): ICompositeSpecification;

    /**
     * Fluent alias for createSpecificationFor().
     *
     * Idiomatic English usage: "all User"
     *
     * @template T
     * @param class-string<T> $type Fully qualified class or interface name
     * @return ICompositeSpecification<T>
     */
    public function all(string $type): ICompositeSpecification;

    /**
     * Fluent alias for createSpecificationFor().
     *
     * Idiomatic English usage: "instanceOf User"
     *
     * @template T
     * @param class-string<T> $type Fully qualified class or interface name
     * @return ICompositeSpecification<T>
     */
    public function instanceOf(string $type): ICompositeSpecification;

    /**
     * Fluent alias for createSpecificationFor().
     *
     * Idiomatic English usage: "specify User"
     *
     * @template T
     * @param class-string<T> $type Fully qualified class or interface name
     * @return ICompositeSpecification<T>
     */
    public function specify(string $type): ICompositeSpecification;

    /**
     * Fluent alias for createSpecificationFor().
     *
     * Idiomatic English usage: "isA User"
     *
     * @template T
     * @param class-string<T> $type Fully qualified class or interface name
     * @return ICompositeSpecification<T>
     */
    public function isA(string $type): ICompositeSpecification;

    /**
     * Fluent alias for createSpecificationFor().
     *
     * Idiomatic English usage: "isAn Entity"
     *
     * @template T
     * @param class-string<T> $type Fully qualified class or interface name
     * @return ICompositeSpecification<T>
     */
    public function isAn(string $type): ICompositeSpecification;

    /**
     * Fluent alias for createSpecificationFor().
     *
     * Idiomatic English usage: "instancesOf User"
     *
     * @template T
     * @param class-string<T> $type Fully qualified class or interface name
     * @return ICompositeSpecification<T>
     */
    public function instancesOf(string $type): ICompositeSpecification;

    /**
     * Fluent alias for createSpecificationFor().
     *
     * Idiomatic English usage: "instanceOfType User"
     *
     * @template T
     * @param class-string<T> $type Fully qualified class or interface name
     * @return ICompositeSpecification<T>
     */
    public function instanceOfType(string $type): ICompositeSpecification;

    /**
     * Fluent alias for createSpecificationFor().
     *
     * Idiomatic English usage: "instancesOfType User"
     *
     * @template T
     * @param class-string<T> $type Fully qualified class or interface name
     * @return ICompositeSpecification<T>
     */
    public function instancesOfType(string $type): ICompositeSpecification;

    /**
     * Fluent alias for createSpecificationFor().
     *
     * Idiomatic English usage: "anInstanceOfType User"
     *
     * @template T
     * @param class-string<T> $type Fully qualified class or interface name
     * @return ICompositeSpecification<T>
     */
    public function anInstanceOfType(string $type): ICompositeSpecification;

    /**
     * Fluent alias for createSpecificationFor().
     *
     * Idiomatic English usage: "allOfType User"
     *
     * @template T
     * @param class-string<T> $type Fully qualified class or interface name
     * @return ICompositeSpecification<T>
     */
    public function allOfType(string $type): ICompositeSpecification;

    /**
     * Fluent alias for createSpecificationFor().
     *
     * Idiomatic English usage: "allInstancesOfType User"
     *
     * @template T
     * @param class-string<T> $type Fully qualified class or interface name
     * @return ICompositeSpecification<T>
     */
    public function allInstancesOfType(string $type): ICompositeSpecification;

    /**
     * Fluent alias for createSpecificationFor().
     *
     * Idiomatic English usage: "specifyA User"
     *
     * @template T
     * @param class-string<T> $type Fully qualified class or interface name
     * @return ICompositeSpecification<T>
     */
    public function specifyA(string $type): ICompositeSpecification;

    /**
     * Fluent alias for createSpecificationFor().
     *
     * Idiomatic English usage: "specifyAn Entity"
     *
     * @template T
     * @param class-string<T> $type Fully qualified class or interface name
     * @return ICompositeSpecification<T>
     */
    public function specifyAn(string $type): ICompositeSpecification;
}
