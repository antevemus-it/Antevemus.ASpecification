<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Factory;

use Antevemus\ASpecification\Contracts\Factory\ILogicalSpecificationFactory;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysTrueSpecification;
use Antevemus\ASpecification\Specifications\Logical\JointDenialSpecification;

/**
 * AbstractLogicalSpecificationFactory - Base abstract factory for logical specifications
 *
 * Provides default alias implementations and validation helpers for logical specifications.
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Factory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class AbstractLogicalSpecificationFactory implements ILogicalSpecificationFactory
{
    /**
     * Validates a list of specifications.
     *
     * Helper method for concrete implementations to validate specifications
     * prior to creating logical compositions.
     *
     * @param array $specifications List of specifications to validate
     * @throws \InvalidArgumentException If the list is empty or contains non-specification elements
     */
    protected function validateSpecifications(array $specifications): void
    {
        if (empty($specifications)) {
            throw new \InvalidArgumentException('At least one specification is required');
        }

        foreach ($specifications as $spec) {
            if (!$spec instanceof ISpecification) {
                throw new \InvalidArgumentException('All arguments must be instances of ISpecification');
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    abstract public function allOf(ISpecification ...$specifications): ISpecification;

    /**
     * {@inheritdoc}
     */
    abstract public function anyOf(ISpecification ...$specifications): ISpecification;

    /**
     * {@inheritdoc}
     */
    abstract public function not(ISpecification $specification): ISpecification;

    /**
     * {@inheritdoc}
     */
    public function neitherOf(ISpecification ...$specifications): ISpecification
    {
        return $this->not($this->anyOf(...$specifications));
    }

    /**
     * {@inheritdoc}
     *
     * nor()            → AlwaysTrue (nothing to deny)
     * nor(a)           → NOT a
     * nor(a, b, c...)  → JointDenial(a, anyOf(b, c, ...)) = NOT (a OR b OR c ...)
     */
    public function nor(ISpecification ...$specifications): ISpecification
    {
        $count = count($specifications);
        if ($count === 0) {
            return new AlwaysTrueSpecification();
        }
        if ($count === 1) {
            return $this->not($specifications[0]);
        }

        $first = array_shift($specifications);

        return new JointDenialSpecification($first, $this->anyOf(...$specifications));
    }

    /**
     * {@inheritdoc}
     */
    public function noneOf(ISpecification ...$specifications): ISpecification
    {
        return $this->nor(...$specifications);
    }

    /**
     * {@inheritdoc}
     */
    public function shouldBeAllOf(ISpecification ...$specifications): ISpecification
    {
        return $this->allOf(...$specifications);
    }

    /**
     * {@inheritdoc}
     */
    public function shouldBeBoth(ISpecification ...$specifications): ISpecification
    {
        return $this->allOf(...$specifications);
    }

    /**
     * {@inheritdoc}
     */
    public function shouldBe(ISpecification ...$specifications): ISpecification
    {
        return $this->allOf(...$specifications);
    }

    /**
     * {@inheritdoc}
     */
    public function isAllOf(ISpecification ...$specifications): ISpecification
    {
        return $this->allOf(...$specifications);
    }

    /**
     * {@inheritdoc}
     */
    public function isBoth(ISpecification ...$specifications): ISpecification
    {
        return $this->allOf(...$specifications);
    }

    /**
     * {@inheritdoc}
     */
    public function both(ISpecification ...$specifications): ISpecification
    {
        return $this->allOf(...$specifications);
    }

    /**
     * {@inheritdoc}
     */
    public function shouldBeOneOf(ISpecification ...$specifications): ISpecification
    {
        return $this->anyOf(...$specifications);
    }

    /**
     * {@inheritdoc}
     */
    public function shouldBeEitherOf(ISpecification ...$specifications): ISpecification
    {
        return $this->anyOf(...$specifications);
    }

    /**
     * {@inheritdoc}
     */
    public function isOneOf(ISpecification ...$specifications): ISpecification
    {
        return $this->anyOf(...$specifications);
    }

    /**
     * {@inheritdoc}
     */
    public function isEitherOf(ISpecification ...$specifications): ISpecification
    {
        return $this->anyOf(...$specifications);
    }

    /**
     * {@inheritdoc}
     */
    public function isEitherThe(ISpecification ...$specifications): ISpecification
    {
        return $this->anyOf(...$specifications);
    }

    /**
     * {@inheritdoc}
     */
    public function isEither(ISpecification ...$specifications): ISpecification
    {
        return $this->anyOf(...$specifications);
    }

    /**
     * {@inheritdoc}
     */
    public function oneOf(ISpecification ...$specifications): ISpecification
    {
        return $this->anyOf(...$specifications);
    }

    /**
     * {@inheritdoc}
     */
    public function eitherOf(ISpecification ...$specifications): ISpecification
    {
        return $this->anyOf(...$specifications);
    }

    /**
     * {@inheritdoc}
     */
    public function either(ISpecification ...$specifications): ISpecification
    {
        return $this->anyOf(...$specifications);
    }

    /**
     * {@inheritdoc}
     */
    public function defaultValue(): ISpecification
    {
        return new \Antevemus\ASpecification\Specifications\Logical\DefaultValueSpecification();
    }
}
