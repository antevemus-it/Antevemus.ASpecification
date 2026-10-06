<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Factory;

use Antevemus\ASpecification\Contracts\Factory\IComparisonSpecificationFactory;
use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * AbstractComparisonSpecificationFactory - Base abstract factory for comparison specifications
 *
 * Provides default alias methods and validation helpers for comparison specifications.
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Factory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class AbstractComparisonSpecificationFactory implements IComparisonSpecificationFactory
{
    /**
     * Validates the provided value.
     *
     * Helper method for concrete implementations to validate values
     * prior to creating specifications.
     *
     * @param mixed $value Value to validate
     * @throws \InvalidArgumentException If value is null
     */
    protected function validateValue(mixed $value): void
    {
        if ($value === null) {
            throw new \InvalidArgumentException('Value cannot be null');
        }
    }

    /**
     * {@inheritdoc}
     */
    abstract public function equalTo(mixed $value): ISpecification;

    /**
     * {@inheritdoc}
     */
    public function is(mixed $value): ISpecification
    {
        return $this->equalTo($value);
    }

    /**
     * {@inheritdoc}
     */
    public function exactly(mixed $value): ISpecification
    {
        return $this->equalTo($value);
    }

    /**
     * {@inheritdoc}
     */
    abstract public function lessThan(mixed $value): ISpecification;

    /**
     * {@inheritdoc}
     */
    public function before(mixed $value): ISpecification
    {
        return $this->lessThan($value);
    }

    /**
     * {@inheritdoc}
     */
    abstract public function lessThanOrEqualTo(mixed $value): ISpecification;

    /**
     * {@inheritdoc}
     */
    public function atMost(mixed $value): ISpecification
    {
        return $this->lessThanOrEqualTo($value);
    }

    /**
     * {@inheritdoc}
     */
    abstract public function greaterThan(mixed $value): ISpecification;

    /**
     * {@inheritdoc}
     */
    public function after(mixed $value): ISpecification
    {
        return $this->greaterThan($value);
    }

    /**
     * {@inheritdoc}
     */
    public function moreThan(mixed $value): ISpecification
    {
        return $this->greaterThan($value);
    }

    /**
     * {@inheritdoc}
     */
    abstract public function greaterThanOrEqualTo(mixed $value): ISpecification;

    /**
     * {@inheritdoc}
     */
    public function atLeast(mixed $value): ISpecification
    {
        return $this->greaterThanOrEqualTo($value);
    }

    /**
     * {@inheritdoc}
     */
    abstract public function in(mixed ...$values): ISpecification;

    /**
     * {@inheritdoc}
     */
    public function isOneOfValues(mixed ...$values): ISpecification
    {
        return $this->in(...$values);
    }

    /**
     * {@inheritdoc}
     */
    public function isEitherOfValues(mixed ...$values): ISpecification
    {
        return $this->in(...$values);
    }

    /**
     * {@inheritdoc}
     */
    public function oneOfValues(mixed ...$values): ISpecification
    {
        return $this->in(...$values);
    }

    /**
     * {@inheritdoc}
     */
    public function eitherValue(mixed ...$values): ISpecification
    {
        return $this->in(...$values);
    }
}
