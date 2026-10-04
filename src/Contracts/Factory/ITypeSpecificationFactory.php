<?php

namespace Antevemus\ASpecification\Contracts\Factory;

use Antevemus\ASpecification\Contracts\ICompositeSpecification;

/**
 * ITypeSpecificationFactory interface.
 *
 * Contrato para fábricas que criam especificações baseadas em tipos de classes.
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Factory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface ITypeSpecificationFactory extends ISpecificationFactory
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
    public function createSpecificationFor(string $type): ICompositeSpecification;

    /**
     * Alias fluente para createSpecificationFor().
     *
     * Uso idiomático em inglês: "the User"
     *
     * @template T
     * @param class-string<T> $type Nome completo da classe ou interface
     * @return ICompositeSpecification<T>
     */
    public function the(string $type): ICompositeSpecification;

    /**
     * Alias fluente para createSpecificationFor().
     *
     * Uso idiomático em inglês: "a Product"
     *
     * @template T
     * @param class-string<T> $type Nome completo da classe ou interface
     * @return ICompositeSpecification<T>
     */
    public function a(string $type): ICompositeSpecification;

    /**
     * Alias fluente para createSpecificationFor().
     *
     * Uso idiomático em inglês: "an Order"
     *
     * @template T
     * @param class-string<T> $type Nome completo da classe ou interface
     * @return ICompositeSpecification<T>
     */
    public function an(string $type): ICompositeSpecification;

    /**
     * Alias fluente para createSpecificationFor().
     *
     * Uso idiomático em inglês: "all User"
     *
     * @template T
     * @param class-string<T> $type Nome completo da classe ou interface
     * @return ICompositeSpecification<T>
     */
    public function all(string $type): ICompositeSpecification;

    /**
     * Alias fluente para createSpecificationFor().
     *
     * Uso idiomático: "instanceOf User"
     *
     * @template T
     * @param class-string<T> $type Nome completo da classe ou interface
     * @return ICompositeSpecification<T>
     */
    public function instanceOf(string $type): ICompositeSpecification;

    /**
     * Alias fluente para createSpecificationFor().
     *
     * Uso idiomático: "specify User"
     *
     * @template T
     * @param class-string<T> $type Nome completo da classe ou interface
     * @return ICompositeSpecification<T>
     */
    public function specify(string $type): ICompositeSpecification;

    /**
     * Alias fluente para createSpecificationFor().
     *
     * Uso idiomático: "isA User"
     *
     * @template T
     * @param class-string<T> $type Nome completo da classe ou interface
     * @return ICompositeSpecification<T>
     */
    public function isA(string $type): ICompositeSpecification;

    /**
     * Alias fluente para createSpecificationFor().
     *
     * Uso idiomático: "isAn Entity"
     *
     * @template T
     * @param class-string<T> $type Nome completo da classe ou interface
     * @return ICompositeSpecification<T>
     */
    public function isAn(string $type): ICompositeSpecification;

    /**
     * Alias fluente para createSpecificationFor().
     *
     * Uso idiomático: "instancesOf User"
     *
     * @template T
     * @param class-string<T> $type Nome completo da classe ou interface
     * @return ICompositeSpecification<T>
     */
    public function instancesOf(string $type): ICompositeSpecification;

    /**
     * Alias fluente para createSpecificationFor().
     *
     * Uso idiomático: "instanceOfType User"
     *
     * @template T
     * @param class-string<T> $type Nome completo da classe ou interface
     * @return ICompositeSpecification<T>
     */
    public function instanceOfType(string $type): ICompositeSpecification;

    /**
     * Alias fluente para createSpecificationFor().
     *
     * Uso idiomático: "instancesOfType User"
     *
     * @template T
     * @param class-string<T> $type Nome completo da classe ou interface
     * @return ICompositeSpecification<T>
     */
    public function instancesOfType(string $type): ICompositeSpecification;

    /**
     * Alias fluente para createSpecificationFor().
     *
     * Uso idiomático: "anInstanceOfType User"
     *
     * @template T
     * @param class-string<T> $type Nome completo da classe ou interface
     * @return ICompositeSpecification<T>
     */
    public function anInstanceOfType(string $type): ICompositeSpecification;

    /**
     * Alias fluente para createSpecificationFor().
     *
     * Uso idiomático: "allOfType User"
     *
     * @template T
     * @param class-string<T> $type Nome completo da classe ou interface
     * @return ICompositeSpecification<T>
     */
    public function allOfType(string $type): ICompositeSpecification;

    /**
     * Alias fluente para createSpecificationFor().
     *
     * Uso idiomático: "allInstancesOfType User"
     *
     * @template T
     * @param class-string<T> $type Nome completo da classe ou interface
     * @return ICompositeSpecification<T>
     */
    public function allInstancesOfType(string $type): ICompositeSpecification;

    /**
     * Alias fluente para createSpecificationFor().
     *
     * Uso idiomático: "specifyA User"
     *
     * @template T
     * @param class-string<T> $type Nome completo da classe ou interface
     * @return ICompositeSpecification<T>
     */
    public function specifyA(string $type): ICompositeSpecification;

    /**
     * Alias fluente para createSpecificationFor().
     *
     * Uso idiomático: "specifyAn Entity"
     *
     * @template T
     * @param class-string<T> $type Nome completo da classe ou interface
     * @return ICompositeSpecification<T>
     */
    public function specifyAn(string $type): ICompositeSpecification;
}
