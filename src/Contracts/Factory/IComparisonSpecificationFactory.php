<?php

namespace Antevemus\ASpecification\Contracts\Factory;

use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * IComparisonSpecificationFactory interface.
 *
 * Contrato para fábricas que criam especificações de comparação.
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Factory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IComparisonSpecificationFactory extends ISpecificationFactory
{
    /**
     * Cria especificação que verifica igualdade (==).
     *
     * @param mixed $value Valor para comparação
     * @return ISpecification Especificação de igualdade
     */
    public function equalTo(mixed $value): ISpecification;

    /**
     * Alias para equalTo().
     *
     * Uso fluente: "is 10"
     *
     * @param mixed $value Valor para comparação
     * @return ISpecification
     */
    public function is(mixed $value): ISpecification;

    /**
     * Alias para equalTo().
     *
     * Uso fluente: "exactly 10"
     *
     * @param mixed $value Valor para comparação
     * @return ISpecification
     */
    public function exactly(mixed $value): ISpecification;

    /**
     * Cria especificação que verifica se valor é menor que (<).
     *
     * @param mixed $value Valor limite superior (exclusivo)
     * @return ISpecification Especificação de menor que
     */
    public function lessThan(mixed $value): ISpecification;

    /**
     * Alias para lessThan().
     *
     * Uso fluente com datas: "before 2025-01-01"
     *
     * @param mixed $value Valor limite superior (exclusivo)
     * @return ISpecification
     */
    public function before(mixed $value): ISpecification;

    /**
     * Cria especificação que verifica se valor é menor ou igual (<=).
     *
     * @param mixed $value Valor limite superior (inclusivo)
     * @return ISpecification Especificação de menor ou igual
     */
    public function lessThanOrEqualTo(mixed $value): ISpecification;

    /**
     * Alias para lessThanOrEqualTo().
     *
     * Uso fluente: "atMost 100"
     *
     * @param mixed $value Valor limite superior (inclusivo)
     * @return ISpecification
     */
    public function atMost(mixed $value): ISpecification;

    /**
     * Cria especificação que verifica se valor é maior que (>).
     *
     * @param mixed $value Valor limite inferior (exclusivo)
     * @return ISpecification Especificação de maior que
     */
    public function greaterThan(mixed $value): ISpecification;

    /**
     * Alias para greaterThan().
     *
     * Uso fluente com datas: "after 2025-01-01"
     *
     * @param mixed $value Valor limite inferior (exclusivo)
     * @return ISpecification
     */
    public function after(mixed $value): ISpecification;

    /**
     * Alias para greaterThan().
     *
     * Uso fluente: "moreThan 5"
     *
     * @param mixed $value Valor limite inferior (exclusivo)
     * @return ISpecification
     */
    public function moreThan(mixed $value): ISpecification;

    /**
     * Cria especificação que verifica se valor é maior ou igual (>=).
     *
     * @param mixed $value Valor limite inferior (inclusivo)
     * @return ISpecification Especificação de maior ou igual
     */
    public function greaterThanOrEqualTo(mixed $value): ISpecification;

    /**
     * Alias para greaterThanOrEqualTo().
     *
     * Uso fluente: "atLeast 18"
     *
     * @param mixed $value Valor limite inferior (inclusivo)
     * @return ISpecification
     */
    public function atLeast(mixed $value): ISpecification;

    /**
     * Cria especificação que verifica se valor está em um conjunto (OR de igualdades).
     *
     * Diferente de anyOf(ISpecification...) que combina specs, este método
     * aceita VALORES e cria automaticamente especificações de igualdade.
     *
     * Equivalente a: equalTo(value1) OR equalTo(value2) OR equalTo(value3) OR ...
     *
     * Exemplo:
     * <code>
     * $spec = $factory->in('active', 'pending', 'approved');
     * $spec->isSatisfiedBy('active');   // true
     * $spec->isSatisfiedBy('pending');  // true
     * $spec->isSatisfiedBy('rejected'); // false
     * </code>
     *
     * @param mixed ...$values Conjunto de valores permitidos
     * @return ISpecification Especificação que verifica pertencimento ao conjunto
     * @throws \InvalidArgumentException Se nenhum valor for fornecido
     */
    public function in(mixed ...$values): ISpecification;

    /**
     * Alias para in().
     *
     * Uso fluente: "isOneOf 'active', 'pending', 'approved'"
     *
     * @param mixed ...$values Conjunto de valores permitidos
     * @return ISpecification
     */
    public function isOneOfValues(mixed ...$values): ISpecification;

    /**
     * Alias para in().
     *
     * Uso fluente: "isEitherOf 'yes', 'no'"
     *
     * @param mixed ...$values Conjunto de valores permitidos
     * @return ISpecification
     */
    public function isEitherOfValues(mixed ...$values): ISpecification;

    /**
     * Alias para in().
     *
     * Uso fluente: "oneOfValues 1, 2, 3"
     *
     * @param mixed ...$values Conjunto de valores permitidos
     * @return ISpecification
     */
    public function oneOfValues(mixed ...$values): ISpecification;

    /**
     * Alias para in().
     *
     * Uso fluente: "eitherValue 'A', 'B'"
     *
     * @param mixed ...$values Conjunto de valores permitidos
     * @return ISpecification
     */
    public function eitherValue(mixed ...$values): ISpecification;
}
