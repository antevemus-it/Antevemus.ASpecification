<?php

namespace Antevemus\ASpecification\Factory;

use Antevemus\ASpecification\Contracts\Factory\ISpecialSpecificationFactory;
use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * AbstractSpecialSpecificationFactory class.
 *
 * Classe abstrata base para fábricas de especificações especiais.
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Factory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
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
