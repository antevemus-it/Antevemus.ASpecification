<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Factory;

use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * ILogicalSpecificationFactory - Factory contract for logical operator specifications
 *
 * Contract for factories creating composite specifications using boolean logic (AND, OR, NOT, NOR).
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Factory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface ILogicalSpecificationFactory extends ISpecificationFactory
{
    /**
     * Creates a composite specification verifying that ALL specifications are satisfied (logical AND).
     *
     * Equivalent to: spec1 AND spec2 AND spec3 AND ...
     *
     * @param ISpecification ...$specifications Specifications that must all be satisfied
     * @return ISpecification Composite specification with AND operator
     * @throws \InvalidArgumentException If no specifications are provided
     */
    public function allOf(ISpecification ...$specifications): ISpecification;

    /**
     * Creates a composite specification verifying that AT LEAST ONE specification is satisfied (logical OR).
     *
     * Equivalent to: spec1 OR spec2 OR spec3 OR ...
     *
     * @param ISpecification ...$specifications Specifications where at least one must be satisfied
     * @return ISpecification Composite specification with OR operator
     * @throws \InvalidArgumentException If no specifications are provided
     */
    public function anyOf(ISpecification ...$specifications): ISpecification;

    /**
     * Creates a specification inverting the result of the given specification (logical NOT).
     *
     * Equivalent to: NOT spec
     *
     * @param ISpecification $specification Specification to invert
     * @return ISpecification Negated specification
     */
    public function not(ISpecification $specification): ISpecification;

    /**
     * Creates a composite specification verifying that NONE of the specifications are satisfied (logical NOR).
     *
     * Equivalent to: NOT (spec1 OR spec2 OR spec3 OR ...)
     *
     * @param ISpecification ...$specifications Specifications that must all be unsatisfied
     * @return ISpecification Composite specification with NOR operator
     * @throws \InvalidArgumentException If no specifications are provided
     */
    public function neitherOf(ISpecification ...$specifications): ISpecification;

    /**
     * Creates a joint denial (logical NOR): satisfied only when NONE of the specifications is satisfied.
     *
     * Equivalent to: NOT (spec1 OR spec2 OR ...). With no argument returns a tautology (nothing to deny),
     * with one argument returns its negation, with two or more builds a `JointDenialSpecification`
     * whose right side is the disjunction of the remaining specifications.
     *
     * @param ISpecification ...$specifications Specifications that must all be unsatisfied
     * @return ISpecification NOR specification
     */
    public function nor(ISpecification ...$specifications): ISpecification;

    /**
     * Alias for nor().
     *
     * Fluent usage: "noneOf spec1, spec2"
     *
     * @param ISpecification ...$specifications Specifications that must all be unsatisfied
     * @return ISpecification NOR specification
     */
    public function noneOf(ISpecification ...$specifications): ISpecification;

    /**
     * Alias for allOf().
     *
     * Fluent usage: "shouldBeAllOf spec1, spec2"
     *
     * @param ISpecification ...$specifications List of specifications
     * @return ISpecification
     */
    public function shouldBeAllOf(ISpecification ...$specifications): ISpecification;

    /**
     * Alias for allOf() with exactly two specifications.
     *
     * Fluent usage: "shouldBeBoth spec1, spec2"
     *
     * @param ISpecification ...$specifications List of specifications
     * @return ISpecification
     */
    public function shouldBeBoth(ISpecification ...$specifications): ISpecification;

    /**
     * Alias for allOf().
     *
     * Fluent usage: "shouldBe spec1, spec2"
     *
     * @param ISpecification ...$specifications List of specifications
     * @return ISpecification
     */
    public function shouldBe(ISpecification ...$specifications): ISpecification;

    /**
     * Alias for allOf().
     *
     * Fluent usage: "isAllOf spec1, spec2"
     *
     * @param ISpecification ...$specifications List of specifications
     * @return ISpecification
     */
    public function isAllOf(ISpecification ...$specifications): ISpecification;

    /**
     * Alias for allOf() with exactly two specifications.
     *
     * Fluent usage: "isBoth spec1, spec2"
     *
     * @param ISpecification ...$specifications List of specifications
     * @return ISpecification
     */
    public function isBoth(ISpecification ...$specifications): ISpecification;

    /**
     * Alias for allOf() with exactly two specifications.
     *
     * Fluent usage: "both spec1, spec2"
     *
     * @param ISpecification ...$specifications List of specifications
     * @return ISpecification
     */
    public function both(ISpecification ...$specifications): ISpecification;

    /**
     * Alias for anyOf().
     *
     * Fluent usage: "shouldBeOneOf spec1, spec2, spec3"
     *
     * @param ISpecification ...$specifications List of specifications
     * @return ISpecification
     */
    public function shouldBeOneOf(ISpecification ...$specifications): ISpecification;

    /**
     * Alias for anyOf().
     *
     * Fluent usage: "shouldBeEitherOf spec1, spec2"
     *
     * @param ISpecification ...$specifications List of specifications
     * @return ISpecification
     */
    public function shouldBeEitherOf(ISpecification ...$specifications): ISpecification;

    /**
     * Alias for anyOf().
     *
     * Fluent usage: "isOneOf spec1, spec2, spec3"
     *
     * @param ISpecification ...$specifications List of specifications
     * @return ISpecification
     */
    public function isOneOf(ISpecification ...$specifications): ISpecification;

    /**
     * Alias for anyOf().
     *
     * Fluent usage: "isEitherOf spec1, spec2"
     *
     * @param ISpecification ...$specifications List of specifications
     * @return ISpecification
     */
    public function isEitherOf(ISpecification ...$specifications): ISpecification;

    /**
     * Alias for anyOf().
     *
     * Fluent usage: "isEitherThe spec1, spec2"
     *
     * @param ISpecification ...$specifications List of specifications
     * @return ISpecification
     */
    public function isEitherThe(ISpecification ...$specifications): ISpecification;

    /**
     * Alias for anyOf().
     *
     * Fluent usage: "isEither spec1, spec2"
     *
     * @param ISpecification ...$specifications List of specifications
     * @return ISpecification
     */
    public function isEither(ISpecification ...$specifications): ISpecification;

    /**
     * Alias for anyOf().
     *
     * Fluent usage: "oneOf spec1, spec2, spec3"
     *
     * @param ISpecification ...$specifications List of specifications
     * @return ISpecification
     */
    public function oneOf(ISpecification ...$specifications): ISpecification;

    /**
     * Alias for anyOf().
     *
     * Fluent usage: "eitherOf spec1, spec2"
     *
     * @param ISpecification ...$specifications List of specifications
     * @return ISpecification
     */
    public function eitherOf(ISpecification ...$specifications): ISpecification;

    /**
     * Alias for anyOf().
     *
     * Fluent usage: "either spec1, spec2"
     *
     * @param ISpecification ...$specifications List of specifications
     * @return ISpecification
     */
    public function either(ISpecification ...$specifications): ISpecification;

    /**
     * Creates a specification verifying if candidate equals the default value of its type.
     *
     * @return ISpecification Default value specification
     */
    public function defaultValue(): ISpecification;
}
