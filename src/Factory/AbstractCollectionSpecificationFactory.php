<?php

namespace Antevemus\ASpecification\Factory;

use Antevemus\ASpecification\Contracts\Factory\ICollectionSpecificationFactory;
use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * AbstractCollectionSpecificationFactory class.
 *
 * Classe abstrata base para fábricas de especificações de coleções.
 *
 * Fornece implementações padrão para métodos alias e helpers para
 * implementações concretas de especificações de coleções.
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Factory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class AbstractCollectionSpecificationFactory implements ICollectionSpecificationFactory
{
    /**
     * Valida uma especificação de tamanho/contagem.
     *
     * Método auxiliar para implementações concretas validarem especificações
     * numéricas antes de criar especificações de coleção.
     *
     * @param ISpecification $specification Especificação a validar
     * @throws \InvalidArgumentException Se a especificação for null
     */
    protected function validateSpecification(ISpecification $specification): void
    {
        if ($specification === null) {
            throw new \InvalidArgumentException('Specification cannot be null');
        }
    }

    /**
     * Valida um valor de percentual.
     *
     * @param int $percentage Percentual a validar
     * @throws \InvalidArgumentException Se o percentual estiver fora do intervalo 0-100
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
