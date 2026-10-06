<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Factory;

use Antevemus\ASpecification\Contracts\Factory\ICollectionSpecificationFactory;
use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * AbstractCollectionSpecificationFactory - Base abstract factory for collection specifications
 *
 * Provides default implementations for alias methods and validation helpers for
 * concrete collection specification implementations.
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Factory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class AbstractCollectionSpecificationFactory implements ICollectionSpecificationFactory
{
    /**
     * Validates a size/count specification.
     *
     * Helper method for concrete implementations to validate numeric specifications
     * prior to creating collection specifications.
     *
     * @param ISpecification $specification Specification to validate
     * @throws \InvalidArgumentException If specification is null
     */
    protected function validateSpecification(ISpecification $specification): void
    {
        if ($specification === null) {
            throw new \InvalidArgumentException('Specification cannot be null');
        }
    }

    /**
     * Validates a percentage value.
     *
     * @param int $percentage Percentage value to validate (0-100)
     * @throws \InvalidArgumentException If percentage is out of range
     */
    protected function validatePercentage(int $percentage): void
    {
        if ($percentage < 0 || $percentage > 100) {
            throw new \InvalidArgumentException('Percentage must be between 0 and 100');
        }
    }

    /**
     * {@inheritdoc}
     */
    abstract public function hasSize(ISpecification $sizeSpecification): ISpecification;

    /**
     * {@inheritdoc}
     */
    public function haveSize(ISpecification $sizeSpecification): ISpecification
    {
        return $this->hasSize($sizeSpecification);
    }

    /**
     * {@inheritdoc}
     */
    public function hasSizeOf(ISpecification $sizeSpecification): ISpecification
    {
        return $this->hasSize($sizeSpecification);
    }

    /**
     * {@inheritdoc}
     */
    public function haveSizeOf(ISpecification $sizeSpecification): ISpecification
    {
        return $this->hasSize($sizeSpecification);
    }

    /**
     * {@inheritdoc}
     */
    abstract public function isEmpty(): ISpecification;

    /**
     * {@inheritdoc}
     */
    public function empty(): ISpecification
    {
        return $this->isEmpty();
    }

    /**
     * {@inheritdoc}
     */
    abstract public function include(ISpecification $countSpecification, ISpecification $elementSpecification): ISpecification;

    /**
     * {@inheritdoc}
     */
    public function includes(ISpecification $countSpecification, ISpecification $elementSpecification): ISpecification
    {
        return $this->include($countSpecification, $elementSpecification);
    }

    /**
     * {@inheritdoc}
     */
    abstract public function includePercentageOf(ISpecification $percentageSpecification, ISpecification $elementSpecification): ISpecification;

    /**
     * {@inheritdoc}
     */
    public function includesPercentageOf(ISpecification $percentageSpecification, ISpecification $elementSpecification): ISpecification
    {
        return $this->includePercentageOf($percentageSpecification, $elementSpecification);
    }

    /**
     * {@inheritdoc}
     */
    abstract public function all(ISpecification $elementSpecification): ISpecification;

    /**
     * {@inheritdoc}
     */
    abstract public function any(ISpecification $elementSpecification): ISpecification;

    /**
     * {@inheritdoc}
     */
    abstract public function none(ISpecification $elementSpecification): ISpecification;
}
