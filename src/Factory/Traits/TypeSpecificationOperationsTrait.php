<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Factory\Traits;

use Antevemus\ASpecification\Contracts\ICompositeSpecification;

/**
 * TypeSpecificationOperationsTrait - Trait agregador de operações e aliases da fábrica de tipos (ITypeSpecificationFactory).
 *
 * Funcionalidades:
 * - Criação de especificações compostas tipadas
 * - Aliases fluentes idiomáticos (the, instanceOf, specify, allOfType, etc.)
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Factory\Traits
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
trait TypeSpecificationOperationsTrait
{
    /**
     * Cria uma especificação composta para um tipo específico.
     *
     * Este é o método principal da factory. Todos os outros métodos são aliases
     * fluentes que delegam para este método.
     *
     * @template T
     * @param class-string<T> $type Nome completo da classe ou interface
     * @return ICompositeSpecification<T> Especificação que verifica se objeto é do tipo especificado
     * @throws \InvalidArgumentException Se o tipo for vazio ou não existir
     */
    public function createSpecificationFor(string $type): ICompositeSpecification
    {
        return $this->typeFactory->createSpecificationFor($type);
    }

    /**
     * Alias fluente para createSpecificationFor().
     *
     * Uso idiomático em inglês: "the User"
     *
     * @template T
     * @param class-string<T> $type Nome completo da classe ou interface
     * @return ICompositeSpecification<T>
     */
    public function the(string $type): ICompositeSpecification
    {
        return $this->typeFactory->the($type);
    }

    /**
     * Alias fluente para createSpecificationFor().
     *
     * Uso idiomático: "instanceOf User"
     *
     * @template T
     * @param class-string<T> $type Nome completo da classe ou interface
     * @return ICompositeSpecification<T>
     */
    public function instanceOf(string $type): ICompositeSpecification
    {
        return $this->typeFactory->instanceOf($type);
    }

    /**
     * Alias fluente para createSpecificationFor().
     *
     * Uso idiomático: "specify User"
     *
     * @template T
     * @param class-string<T> $type Nome completo da classe ou interface
     * @return ICompositeSpecification<T>
     */
    public function specify(string $type): ICompositeSpecification
    {
        return $this->typeFactory->specify($type);
    }

    /**
     * Alias fluente para createSpecificationFor().
     *
     * Uso idiomático: "instancesOf User"
     *
     * @template T
     * @param class-string<T> $type Nome completo da classe ou interface
     * @return ICompositeSpecification<T>
     */
    public function instancesOf(string $type): ICompositeSpecification
    {
        return $this->typeFactory->instancesOf($type);
    }

    /**
     * Alias fluente para createSpecificationFor().
     *
     * Uso idiomático: "instanceOfType User"
     *
     * @template T
     * @param class-string<T> $type Nome completo da classe ou interface
     * @return ICompositeSpecification<T>
     */
    public function instanceOfType(string $type): ICompositeSpecification
    {
        return $this->typeFactory->instanceOfType($type);
    }

    /**
     * Alias fluente para createSpecificationFor().
     *
     * Uso idiomático: "instancesOfType User"
     *
     * @template T
     * @param class-string<T> $type Nome completo da classe ou interface
     * @return ICompositeSpecification<T>
     */
    public function instancesOfType(string $type): ICompositeSpecification
    {
        return $this->typeFactory->instancesOfType($type);
    }

    /**
     * Alias fluente para createSpecificationFor().
     *
     * Uso idiomático: "anInstanceOfType User"
     *
     * @template T
     * @param class-string<T> $type Nome completo da classe ou interface
     * @return ICompositeSpecification<T>
     */
    public function anInstanceOfType(string $type): ICompositeSpecification
    {
        return $this->typeFactory->anInstanceOfType($type);
    }

    /**
     * Alias fluente para createSpecificationFor().
     *
     * Uso idiomático: "allOfType User"
     *
     * @template T
     * @param class-string<T> $type Nome completo da classe ou interface
     * @return ICompositeSpecification<T>
     */
    public function allOfType(string $type): ICompositeSpecification
    {
        return $this->typeFactory->allOfType($type);
    }

    /**
     * Alias fluente para createSpecificationFor().
     *
     * Uso idiomático: "allInstancesOfType User"
     *
     * @template T
     * @param class-string<T> $type Nome completo da classe ou interface
     * @return ICompositeSpecification<T>
     */
    public function allInstancesOfType(string $type): ICompositeSpecification
    {
        return $this->typeFactory->allInstancesOfType($type);
    }

    /**
     * Alias fluente para createSpecificationFor().
     *
     * Uso idiomático: "specifyA User"
     *
     * @template T
     * @param class-string<T> $type Nome completo da classe ou interface
     * @return ICompositeSpecification<T>
     */
    public function specifyA(string $type): ICompositeSpecification
    {
        return $this->typeFactory->specifyA($type);
    }

    /**
     * Alias fluente para createSpecificationFor().
     *
     * Uso idiomático: "specifyAn Entity"
     *
     * @template T
     * @param class-string<T> $type Nome completo da classe ou interface
     * @return ICompositeSpecification<T>
     */
    public function specifyAn(string $type): ICompositeSpecification
    {
        return $this->typeFactory->specifyAn($type);
    }

}
