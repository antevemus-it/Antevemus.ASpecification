<?php

namespace Antevemus\ASpecification\Contracts\Factory;

use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * ILogicalSpecificationFactory interface.
 *
 * Contrato para fábricas que criam especificações usando operadores lógicos.
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Factory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface ILogicalSpecificationFactory extends ISpecificationFactory
{
    /**
     * Cria especificação composta que verifica se TODAS as especificações são satisfeitas (AND lógico).
     *
     * Equivalente a: spec1 AND spec2 AND spec3 AND ...
     *
     * @param ISpecification ...$specifications Lista de especificações que devem todas ser verdadeiras
     * @return ISpecification Especificação composta com operador AND
     * @throws \InvalidArgumentException Se nenhuma especificação for fornecida
     */
    public function allOf(ISpecification ...$specifications): ISpecification;

    /**
     * Cria especificação composta que verifica se QUALQUER especificação é satisfeita (OR lógico).
     *
     * Equivalente a: spec1 OR spec2 OR spec3 OR ...
     *
     * @param ISpecification ...$specifications Lista de especificações onde pelo menos uma deve ser verdadeira
     * @return ISpecification Especificação composta com operador OR
     * @throws \InvalidArgumentException Se nenhuma especificação for fornecida
     */
    public function anyOf(ISpecification ...$specifications): ISpecification;

    /**
     * Cria especificação que inverte o resultado da especificação fornecida (NOT lógico).
     *
     * Equivalente a: NOT spec
     *
     * @param ISpecification $specification Especificação a ser invertida
     * @return ISpecification Especificação negada
     */
    public function not(ISpecification $specification): ISpecification;

    /**
     * Cria especificação que verifica se NENHUMA das especificações é satisfeita (NOR lógico).
     *
     * Equivalente a: NOT (spec1 OR spec2 OR spec3 OR ...)
     *
     * @param ISpecification ...$specifications Lista de especificações que todas devem ser falsas
     * @return ISpecification Especificação composta com operador NOR
     * @throws \InvalidArgumentException Se nenhuma especificação for fornecida
     */
    public function neitherOf(ISpecification ...$specifications): ISpecification;

    /**
     * Alias para allOf().
     *
     * Uso fluente: "shouldBeAllOf spec1, spec2"
     *
     * @param ISpecification ...$specifications Lista de especificações
     * @return ISpecification
     */
    public function shouldBeAllOf(ISpecification ...$specifications): ISpecification;

    /**
     * Alias para allOf() com exatamente 2 especificações.
     *
     * Uso fluente: "shouldBeBoth spec1, spec2"
     *
     * @param ISpecification ...$specifications Lista de especificações
     * @return ISpecification
     */
    public function shouldBeBoth(ISpecification ...$specifications): ISpecification;

    /**
     * Alias para allOf().
     *
     * Uso fluente: "shouldBe spec1, spec2"
     *
     * @param ISpecification ...$specifications Lista de especificações
     * @return ISpecification
     */
    public function shouldBe(ISpecification ...$specifications): ISpecification;

    /**
     * Alias para allOf().
     *
     * Uso fluente: "isAllOf spec1, spec2"
     *
     * @param ISpecification ...$specifications Lista de especificações
     * @return ISpecification
     */
    public function isAllOf(ISpecification ...$specifications): ISpecification;

    /**
     * Alias para allOf() com exatamente 2 especificações.
     *
     * Uso fluente: "isBoth spec1, spec2"
     *
     * @param ISpecification ...$specifications Lista de especificações
     * @return ISpecification
     */
    public function isBoth(ISpecification ...$specifications): ISpecification;

    /**
     * Alias para allOf() com exatamente 2 especificações.
     *
     * Uso fluente: "both spec1, spec2"
     *
     * @param ISpecification ...$specifications Lista de especificações
     * @return ISpecification
     */
    public function both(ISpecification ...$specifications): ISpecification;

    /**
     * Alias para anyOf().
     *
     * Uso fluente: "shouldBeOneOf spec1, spec2, spec3"
     *
     * @param ISpecification ...$specifications Lista de especificações
     * @return ISpecification
     */
    public function shouldBeOneOf(ISpecification ...$specifications): ISpecification;

    /**
     * Alias para anyOf().
     *
     * Uso fluente: "shouldBeEitherOf spec1, spec2"
     *
     * @param ISpecification ...$specifications Lista de especificações
     * @return ISpecification
     */
    public function shouldBeEitherOf(ISpecification ...$specifications): ISpecification;

    /**
     * Alias para anyOf().
     *
     * Uso fluente: "isOneOf spec1, spec2, spec3"
     *
     * @param ISpecification ...$specifications Lista de especificações
     * @return ISpecification
     */
    public function isOneOf(ISpecification ...$specifications): ISpecification;

    /**
     * Alias para anyOf().
     *
     * Uso fluente: "isEitherOf spec1, spec2"
     *
     * @param ISpecification ...$specifications Lista de especificações
     * @return ISpecification
     */
    public function isEitherOf(ISpecification ...$specifications): ISpecification;

    /**
     * Alias para anyOf().
     *
     * Uso fluente: "isEitherThe spec1, spec2"
     *
     * @param ISpecification ...$specifications Lista de especificações
     * @return ISpecification
     */
    public function isEitherThe(ISpecification ...$specifications): ISpecification;

    /**
     * Alias para anyOf().
     *
     * Uso fluente: "isEither spec1, spec2"
     *
     * @param ISpecification ...$specifications Lista de especificações
     * @return ISpecification
     */
    public function isEither(ISpecification ...$specifications): ISpecification;

    /**
     * Alias para anyOf().
     *
     * Uso fluente: "oneOf spec1, spec2, spec3"
     *
     * @param ISpecification ...$specifications Lista de especificações
     * @return ISpecification
     */
    public function oneOf(ISpecification ...$specifications): ISpecification;

    /**
     * Alias para anyOf().
     *
     * Uso fluente: "eitherOf spec1, spec2"
     *
     * @param ISpecification ...$specifications Lista de especificações
     * @return ISpecification
     */
    public function eitherOf(ISpecification ...$specifications): ISpecification;

    /**
     * Alias para anyOf().
     *
     * Uso fluente: "either spec1, spec2"
     *
     * @param ISpecification ...$specifications Lista de especificações
     * @return ISpecification
     */
    public function either(ISpecification ...$specifications): ISpecification;

    /**
     * Cria especificação que verifica se o candidato equivale ao valor default do seu tipo.
     *
     * @return ISpecification
     */
    public function defaultValue(): ISpecification;
}
