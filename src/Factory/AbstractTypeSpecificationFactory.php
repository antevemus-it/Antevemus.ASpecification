<?php

namespace Antevemus\ASpecification\Factory;

use Antevemus\ASpecification\Contracts\Factory\ITypeSpecificationFactory;
use Antevemus\ASpecification\Contracts\ICompositeSpecification;

/**
 * AbstractTypeSpecificationFactory class.
 *
 * Classe abstrata base para fábricas de especificações tipadas.
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Factory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class AbstractTypeSpecificationFactory implements ITypeSpecificationFactory
{
    /**
     * Valida se o tipo fornecido é uma classe ou interface válida.
     *
     * Método auxiliar para implementações concretas validarem os tipos
     * antes de criar as especificações.
     *
     * @param string $type Nome completo da classe ou interface
     * @throws \InvalidArgumentException Se o tipo for vazio ou não existir
     */
    protected function validateType(string $type): void
    {
        if (empty($type)) {
            throw new \InvalidArgumentException('Type cannot be empty');
        }

        if (!class_exists($type) && !interface_exists($type)) {
            throw new \InvalidArgumentException("Type '{$type}' does not exist");
        }
    }

    /**
     * {@inheritdoc}
     */
    abstract public function createSpecificationFor(string $type): ICompositeSpecification;

    /**
     * {@inheritdoc}
     */
    public function the(string $type): ICompositeSpecification
    {
        return $this->createSpecificationFor($type);
    }

    /**
     * {@inheritdoc}
     */
    public function a(string $type): ICompositeSpecification
    {
        return $this->createSpecificationFor($type);
    }

    /**
     * {@inheritdoc}
     */
    public function an(string $type): ICompositeSpecification
    {
        return $this->createSpecificationFor($type);
    }

    /**
     * {@inheritdoc}
     */
    public function all(string $type): ICompositeSpecification
    {
        return $this->createSpecificationFor($type);
    }

    /**
     * {@inheritdoc}
     */
    public function instanceOf(string $type): ICompositeSpecification
    {
        return $this->createSpecificationFor($type);
    }

    /**
     * {@inheritdoc}
     */
    public function specify(string $type): ICompositeSpecification
    {
        return $this->createSpecificationFor($type);
    }

    /**
     * {@inheritdoc}
     */
    public function isA(string $type): ICompositeSpecification
    {
        return $this->createSpecificationFor($type);
    }

    /**
     * {@inheritdoc}
     */
    public function isAn(string $type): ICompositeSpecification
    {
        return $this->createSpecificationFor($type);
    }

    /**
     * {@inheritdoc}
     */
    public function instancesOf(string $type): ICompositeSpecification
    {
        return $this->createSpecificationFor($type);
    }

    /**
     * {@inheritdoc}
     */
    public function instanceOfType(string $type): ICompositeSpecification
    {
        return $this->createSpecificationFor($type);
    }

    /**
     * {@inheritdoc}
     */
    public function instancesOfType(string $type): ICompositeSpecification
    {
        return $this->createSpecificationFor($type);
    }

    /**
     * {@inheritdoc}
     */
    public function anInstanceOfType(string $type): ICompositeSpecification
    {
        return $this->createSpecificationFor($type);
    }

    /**
     * {@inheritdoc}
     */
    public function allOfType(string $type): ICompositeSpecification
    {
        return $this->createSpecificationFor($type);
    }

    /**
     * {@inheritdoc}
     */
    public function allInstancesOfType(string $type): ICompositeSpecification
    {
        return $this->createSpecificationFor($type);
    }

    /**
     * {@inheritdoc}
     */
    public function specifyA(string $type): ICompositeSpecification
    {
        return $this->createSpecificationFor($type);
    }

    /**
     * {@inheritdoc}
     */
    public function specifyAn(string $type): ICompositeSpecification
    {
        return $this->createSpecificationFor($type);
    }
}
