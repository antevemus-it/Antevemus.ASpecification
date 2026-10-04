<?php

namespace Antevemus\ASpecification\Factory;

use Antevemus\ASpecification\Contracts\Factory\ILogicalSpecificationFactory;
use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * AbstractLogicalSpecificationFactory class.
 *
 * Classe abstrata base para fábricas de especificações lógicas.
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Factory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class AbstractLogicalSpecificationFactory implements ILogicalSpecificationFactory
{
    /**
     * Valida uma lista de especificações.
     *
     * Método auxiliar para implementações concretas validarem as especificações
     * antes de criar composições lógicas.
     *
     * @param array $specifications Lista de especificações a validar
     * @throws \InvalidArgumentException Se a lista estiver vazia ou contiver elementos não-especificações
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
