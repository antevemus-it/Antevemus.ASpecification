<?php

namespace Antevemus\ASpecification\Factory;

use Antevemus\ASpecification\Contracts\Factory\IComparisonSpecificationFactory;
use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * AbstractComparisonSpecificationFactory class.
 *
 * Classe abstrata base para fábricas de especificações de comparação.
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Factory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class AbstractComparisonSpecificationFactory implements IComparisonSpecificationFactory
{
    /**
     * Valida o valor fornecido.
     *
     * Método auxiliar para implementações concretas validarem os valores
     * antes de criar as especificações.
     *
     * @param mixed $value Valor a ser validado
     * @throws \InvalidArgumentException Se o valor for inválido
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
