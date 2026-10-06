<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Factory;

use Antevemus\ASpecification\Contracts\Factory\ISpecialSpecificationFactory;
use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * AbstractSpecialSpecificationFactory - Base abstract factory for special specifications
 *
 * Base abstract class defining contract for special constant and nullability specifications.
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Factory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class AbstractSpecialSpecificationFactory implements ISpecialSpecificationFactory
{
    /**
     * {@inheritdoc}
     */
    abstract public function alwaysTrue(): ISpecification;

    /**
     * {@inheritdoc}
     */
    abstract public function alwaysFalse(): ISpecification;

    /**
     * {@inheritdoc}
     */
    abstract public function isNull(): ISpecification;

    /**
     * {@inheritdoc}
     */
    abstract public function isNotNull(): ISpecification;

    /**
     * {@inheritdoc}
     */
    abstract public function isTrue(): ISpecification;

    /**
     * {@inheritdoc}
     */
    abstract public function isFalse(): ISpecification;
}
