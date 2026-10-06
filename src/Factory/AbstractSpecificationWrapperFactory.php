<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Factory;

use Antevemus\ASpecification\Contracts\Factory\ISpecificationWrapperFactory;
use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * AbstractSpecificationWrapperFactory - Base abstract factory for specification wrappers
 *
 * Provides complete implementations for all identity wrapper methods (returning specifications unchanged).
 * Derived classes may override if custom wrapping behavior is required.
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Factory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class AbstractSpecificationWrapperFactory implements ISpecificationWrapperFactory
{
    /**
     * Validates that the specification is not null.
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
     * {@inheritdoc}
     */
    public function isSatisfiedBy(ISpecification $specification): ISpecification
    {
        $this->validateSpecification($specification);
        return $specification;
    }

    /**
     * {@inheritdoc}
     */
    public function a(ISpecification $specification): ISpecification
    {
        $this->validateSpecification($specification);
        return $specification;
    }

    /**
     * {@inheritdoc}
     */
    public function an(ISpecification $specification): ISpecification
    {
        $this->validateSpecification($specification);
        return $specification;
    }

    /**
     * {@inheritdoc}
     */
    public function is(ISpecification $specification): ISpecification
    {
        $this->validateSpecification($specification);
        return $specification;
    }

    /**
     * {@inheritdoc}
     */
    public function isA(ISpecification $specification): ISpecification
    {
        $this->validateSpecification($specification);
        return $specification;
    }

    /**
     * {@inheritdoc}
     */
    public function isAn(ISpecification $specification): ISpecification
    {
        $this->validateSpecification($specification);
        return $specification;
    }

    /**
     * {@inheritdoc}
     */
    public function are(ISpecification $specification): ISpecification
    {
        $this->validateSpecification($specification);
        return $specification;
    }

    /**
     * {@inheritdoc}
     */
    public function isFrom(ISpecification $specification): ISpecification
    {
        $this->validateSpecification($specification);
        return $specification;
    }
}
