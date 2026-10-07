<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Factory\Traits;

use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * LogicalSpecificationOperationsTrait - Trait aggregating boolean logical operations (ILogicalSpecificationFactory).
 *
 * Provides delegation methods forwarding to the underlying logical specification factory.
 *
 * Features:
 * - Boolean algebra (allOf, anyOf, not, neitherOf)
 * - Expressive DSL aliases (shouldBeAllOf, isBoth, shouldBeOneOf, either, etc.)
 *
 * @version    1.3.0
 * @package    Antevemus\ASpecification
 * @subpackage Factory\Traits
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
trait LogicalSpecificationOperationsTrait
{
    /**
     * Creates a composite specification verifying whether ALL supplied specifications are satisfied (Logical AND).
     *
     * Equivalent to: spec1 AND spec2 AND spec3 AND ...
     *
     * @param ISpecification ...$specifications Specifications that must all evaluate to true
     * @return ISpecification Composite specification with AND operator
     * @throws \InvalidArgumentException If no specifications are provided
     */
    public function allOf(ISpecification ...$specifications): ISpecification
    {
        return $this->logicalFactory->allOf(...$specifications);
    }

    /**
     * Creates a composite specification verifying whether ANY supplied specification is satisfied (Logical OR).
     *
     * Equivalent to: spec1 OR spec2 OR spec3 OR ...
     *
     * @param ISpecification ...$specifications Specifications where at least one must evaluate to true
     * @return ISpecification Composite specification with OR operator
     * @throws \InvalidArgumentException If no specifications are provided
     */
    public function anyOf(ISpecification ...$specifications): ISpecification
    {
        return $this->logicalFactory->anyOf(...$specifications);
    }

    /**
     * Creates a specification that inverts the result of the given specification (Logical NOT).
     *
     * Equivalent to: NOT spec
     *
     * @param ISpecification $specification Specification to invert
     * @return ISpecification Negated specification
     */
    public function not(ISpecification $specification): ISpecification
    {
        return $this->logicalFactory->not($specification);
    }

    /**
     * Creates a specification verifying whether NONE of the specifications are satisfied (Logical NOR).
     *
     * Equivalent to: NOT (spec1 OR spec2 OR spec3 OR ...)
     *
     * @param ISpecification ...$specifications Specifications that must all evaluate to false
     * @return ISpecification Composite specification with NOR operator
     * @throws \InvalidArgumentException If no specifications are provided
     */
    public function neitherOf(ISpecification ...$specifications): ISpecification
    {
        return $this->logicalFactory->neitherOf(...$specifications);
    }

    /**
     * Creates a joint denial (logical NOR): satisfied only when NONE of the specifications is satisfied.
     *
     * Equivalent to: NOT (spec1 OR spec2 OR ...). No argument → tautology; one → its negation;
     * two or more → `JointDenialSpecification`.
     *
     * @param ISpecification ...$specifications Specifications that must all be unsatisfied
     * @return ISpecification NOR specification
     */
    public function nor(ISpecification ...$specifications): ISpecification
    {
        return $this->logicalFactory->nor(...$specifications);
    }

    /**
     * Alias for nor().
     *
     * @param ISpecification ...$specifications Specifications that must all be unsatisfied
     * @return ISpecification NOR specification
     */
    public function noneOf(ISpecification ...$specifications): ISpecification
    {
        return $this->logicalFactory->noneOf(...$specifications);
    }

    /**
     * Alias for allOf().
     *
     * Fluent usage: "shouldBeAllOf spec1, spec2"
     *
     * @param ISpecification ...$specifications List of specifications
     * @return ISpecification
     */
    public function shouldBeAllOf(ISpecification ...$specifications): ISpecification
    {
        return $this->logicalFactory->shouldBeAllOf(...$specifications);
    }

    /**
     * Alias for allOf() with exactly 2 specifications.
     *
     * Fluent usage: "shouldBeBoth spec1, spec2"
     *
     * @param ISpecification ...$specifications List of specifications
     * @return ISpecification
     */
    public function shouldBeBoth(ISpecification ...$specifications): ISpecification
    {
        return $this->logicalFactory->shouldBeBoth(...$specifications);
    }

    /**
     * Alias for allOf().
     *
     * Fluent usage: "shouldBe spec1, spec2"
     *
     * @param ISpecification ...$specifications List of specifications
     * @return ISpecification
     */
    public function shouldBe(ISpecification ...$specifications): ISpecification
    {
        return $this->logicalFactory->shouldBe(...$specifications);
    }

    /**
     * Alias for allOf().
     *
     * Fluent usage: "isAllOf spec1, spec2"
     *
     * @param ISpecification ...$specifications List of specifications
     * @return ISpecification
     */
    public function isAllOf(ISpecification ...$specifications): ISpecification
    {
        return $this->logicalFactory->isAllOf(...$specifications);
    }

    /**
     * Alias for allOf() with exactly 2 specifications.
     *
     * Fluent usage: "isBoth spec1, spec2"
     *
     * @param ISpecification ...$specifications List of specifications
     * @return ISpecification
     */
    public function isBoth(ISpecification ...$specifications): ISpecification
    {
        return $this->logicalFactory->isBoth(...$specifications);
    }

    /**
     * Alias for allOf() with exactly 2 specifications.
     *
     * Fluent usage: "both spec1, spec2"
     *
     * @param ISpecification ...$specifications List of specifications
     * @return ISpecification
     */
    public function both(ISpecification ...$specifications): ISpecification
    {
        return $this->logicalFactory->both(...$specifications);
    }

    /**
     * Alias for anyOf().
     *
     * Fluent usage: "shouldBeOneOf spec1, spec2, spec3"
     *
     * @param ISpecification ...$specifications List of specifications
     * @return ISpecification
     */
    public function shouldBeOneOf(ISpecification ...$specifications): ISpecification
    {
        return $this->logicalFactory->shouldBeOneOf(...$specifications);
    }

    /**
     * Alias for anyOf().
     *
     * Fluent usage: "shouldBeEitherOf spec1, spec2"
     *
     * @param ISpecification ...$specifications List of specifications
     * @return ISpecification
     */
    public function shouldBeEitherOf(ISpecification ...$specifications): ISpecification
    {
        return $this->logicalFactory->shouldBeEitherOf(...$specifications);
    }

    /**
     * Alias for anyOf().
     *
     * Fluent usage: "isOneOf spec1, spec2, spec3"
     *
     * @param ISpecification ...$specifications List of specifications
     * @return ISpecification
     */
    public function isOneOf(ISpecification ...$specifications): ISpecification
    {
        return $this->logicalFactory->isOneOf(...$specifications);
    }

    /**
     * Alias for anyOf().
     *
     * Fluent usage: "isEitherOf spec1, spec2"
     *
     * @param ISpecification ...$specifications List of specifications
     * @return ISpecification
     */
    public function isEitherOf(ISpecification ...$specifications): ISpecification
    {
        return $this->logicalFactory->isEitherOf(...$specifications);
    }

    /**
     * Alias for anyOf().
     *
     * Fluent usage: "isEitherThe spec1, spec2"
     *
     * @param ISpecification ...$specifications List of specifications
     * @return ISpecification
     */
    public function isEitherThe(ISpecification ...$specifications): ISpecification
    {
        return $this->logicalFactory->isEitherThe(...$specifications);
    }

    /**
     * Alias for anyOf().
     *
     * Fluent usage: "isEither spec1, spec2"
     *
     * @param ISpecification ...$specifications List of specifications
     * @return ISpecification
     */
    public function isEither(ISpecification ...$specifications): ISpecification
    {
        return $this->logicalFactory->isEither(...$specifications);
    }

    /**
     * Alias for anyOf().
     *
     * Fluent usage: "oneOf spec1, spec2, spec3"
     *
     * @param ISpecification ...$specifications List of specifications
     * @return ISpecification
     */
    public function oneOf(ISpecification ...$specifications): ISpecification
    {
        return $this->logicalFactory->oneOf(...$specifications);
    }

    /**
     * Alias for anyOf().
     *
     * Fluent usage: "eitherOf spec1, spec2"
     *
     * @param ISpecification ...$specifications List of specifications
     * @return ISpecification
     */
    public function eitherOf(ISpecification ...$specifications): ISpecification
    {
        return $this->logicalFactory->eitherOf(...$specifications);
    }

    /**
     * Alias for anyOf().
     *
     * Fluent usage: "either spec1, spec2"
     *
     * @param ISpecification ...$specifications List of specifications
     * @return ISpecification
     */
    public function either(ISpecification ...$specifications): ISpecification
    {
        return $this->logicalFactory->either(...$specifications);
    }

    /**
     * Creates a specification verifying whether the candidate matches the default value of its type.
     *
     * @return ISpecification
     */
    public function defaultValue(): ISpecification
    {
        return $this->logicalFactory->defaultValue();
    }
}
