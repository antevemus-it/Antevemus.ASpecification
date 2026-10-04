<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Factory\Traits;

use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Specifications\Comparison\NotEqualSpecification;

/**
 * ComparisonSpecificationOperationsTrait - Trait agregador de operações de comparação (IComparisonSpecificationFactory).
 *
 * Funcionalidades:
 * - Especificações relacionais (=, !=, <, <=, >, >=)
 * - Pertencimento a conjuntos (in)
 * - Aliases semânticos (atMost, atLeast, under, over, exactly, etc.)
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Factory\Traits
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
trait ComparisonSpecificationOperationsTrait
{
    /**
     * Cria especificação que verifica igualdade (==).
     *
     * @param mixed $value Valor para comparação
     * @return ISpecification Especificação de igualdade
     */
    public function equalTo(mixed $value): ISpecification
    {
        return $this->comparisonFactory->equalTo($value);
    }

    /**
     * Cria especificação que verifica desigualdade (!=).
     *
     * @param mixed  Valor para comparação
     * @return ISpecification
     */
    public function notEqualTo(mixed $value): ISpecification
    {
        return new NotEqualSpecification($value);
    }

    /**
     * Cria especificação que verifica se valor é menor que (<).
     *
     * @param mixed $value Valor limite superior (exclusivo)
     * @return ISpecification Especificação de menor que
     */
    public function lessThan(mixed $value): ISpecification
    {
        return $this->comparisonFactory->lessThan($value);
    }

    /**
     * Cria especificação que verifica se valor é menor ou igual (<=).
     *
     * @param mixed $value Valor limite superior (inclusivo)
     * @return ISpecification Especificação de menor ou igual
     */
    public function lessThanOrEqualTo(mixed $value): ISpecification
    {
        return $this->comparisonFactory->lessThanOrEqualTo($value);
    }

    /**
     * Cria especificação que verifica se valor é maior que (>).
     *
     * @param mixed $value Valor limite inferior (exclusivo)
     * @return ISpecification Especificação de maior que
     */
    public function greaterThan(mixed $value): ISpecification
    {
        return $this->comparisonFactory->greaterThan($value);
    }

    /**
     * Cria especificação que verifica se valor é maior ou igual (>=).
     *
     * @param mixed $value Valor limite inferior (inclusivo)
     * @return ISpecification Especificação de maior ou igual
     */
    public function greaterThanOrEqualTo(mixed $value): ISpecification
    {
        return $this->comparisonFactory->greaterThanOrEqualTo($value);
    }

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
    public function in(mixed ...$values): ISpecification
    {
        return $this->comparisonFactory->in(...$values);
    }

    /**
     * Alias para equalTo(). Verifica igualdade de valor.
     *
     * @param mixed  Valor para comparação
     * @return ISpecification
     */
    public function equals(mixed $value): ISpecification
    {
        return $this->equalTo($value);
    }

    /**
     * Alias para equalTo(). Verifica igualdade de valor.
     *
     * @param mixed  Valor para comparação
     * @return ISpecification
     */
    public function isEqual(mixed $value): ISpecification
    {
        return $this->equalTo($value);
    }

    /**
     * Alias para equalTo(). Verifica igualdade de valor.
     *
     * @param mixed  Valor para comparação
     * @return ISpecification
     */
    public function isEqualTo(mixed $value): ISpecification
    {
        return $this->equalTo($value);
    }

    /**
     * Alias para equalTo(). Verifica igualdade de valor.
     *
     * @param mixed  Valor para comparação
     * @return ISpecification
     */
    public function sameAs(mixed $value): ISpecification
    {
        return $this->equalTo($value);
    }

    /**
     * Alias para equalTo(). Verifica igualdade de valor.
     *
     * @param mixed  Valor para comparação
     * @return ISpecification
     */
    public function isSameAs(mixed $value): ISpecification
    {
        return $this->equalTo($value);
    }

    /**
     * Alias para notEqualTo(). Verifica desigualdade de valor.
     *
     * @param mixed  Valor para comparação
     * @return ISpecification
     */
    public function notEqual(mixed $value): ISpecification
    {
        return new NotEqualSpecification($value);
    }

    /**
     * Alias para notEqualTo(). Verifica desigualdade de valor.
     *
     * @param mixed  Valor para comparação
     * @return ISpecification
     */
    public function isNot(mixed $value): ISpecification
    {
        if ($value instanceof ISpecification) {
            return $this->not($value);
        }
        return new NotEqualSpecification($value);
    }

    /**
     * Alias para notEqualTo(). Verifica desigualdade de valor.
     *
     * @param mixed  Valor para comparação
     * @return ISpecification
     */
    public function isNotEqualTo(mixed $value): ISpecification
    {
        return new NotEqualSpecification($value);
    }

    /**
     * Alias para notEqualTo(). Verifica desigualdade de valor.
     *
     * @param mixed  Valor para comparação
     * @return ISpecification
     */
    public function differentFrom(mixed $value): ISpecification
    {
        return new NotEqualSpecification($value);
    }

    /**
     * Alias para notEqualTo(). Verifica desigualdade de valor.
     *
     * @param mixed  Valor para comparação
     * @return ISpecification
     */
    public function isDifferentFrom(mixed $value): ISpecification
    {
        return new NotEqualSpecification($value);
    }

    /**
     * Alias para lessThan(). Verifica se o valor é menor que o limite.
     *
     * @param mixed  Valor limite superior (exclusivo)
     * @return ISpecification
     */
    public function under(mixed $value): ISpecification
    {
        return $this->lessThan($value);
    }

    /**
     * Alias para lessThan(). Verifica se o valor é menor que o limite.
     *
     * @param mixed  Valor limite superior (exclusivo)
     * @return ISpecification
     */
    public function below(mixed $value): ISpecification
    {
        return $this->lessThan($value);
    }

    /**
     * Alias para lessThanOrEqualTo().
     *
     * Uso fluente: "atMost 100"
     *
     * @param mixed $value Valor limite superior (inclusivo)
     * @return ISpecification
     */
    public function atMost(mixed $value): ISpecification
    {
        return $this->comparisonFactory->atMost($value);
    }

    /**
     * Alias para greaterThan(). Verifica se o valor é maior que o limite.
     *
     * @param mixed  Valor limite inferior (exclusivo)
     * @return ISpecification
     */
    public function over(mixed $value): ISpecification
    {
        return $this->greaterThan($value);
    }

    /**
     * Alias para greaterThan(). Verifica se o valor é maior que o limite.
     *
     * @param mixed  Valor limite inferior (exclusivo)
     * @return ISpecification
     */
    public function above(mixed $value): ISpecification
    {
        return $this->greaterThan($value);
    }

    /**
     * Alias para greaterThan().
     *
     * Uso fluente: "moreThan 5"
     *
     * @param mixed $value Valor limite inferior (exclusivo)
     * @return ISpecification
     */
    public function moreThan(mixed $value): ISpecification
    {
        return $this->comparisonFactory->moreThan($value);
    }

    /**
     * Alias para greaterThanOrEqualTo().
     *
     * Uso fluente: "atLeast 18"
     *
     * @param mixed $value Valor limite inferior (inclusivo)
     * @return ISpecification
     */
    public function atLeast(mixed $value): ISpecification
    {
        return $this->comparisonFactory->atLeast($value);
    }

    /**
     * Alias para in().
     *
     * Uso fluente: "isOneOf 'active', 'pending', 'approved'"
     *
     * @param mixed ...$values Conjunto de valores permitidos
     * @return ISpecification
     */
    public function isOneOfValues(mixed ...$values): ISpecification
    {
        return $this->comparisonFactory->isOneOfValues(...$values);
    }

    /**
     * Alias para in().
     *
     * Uso fluente: "isEitherOf 'yes', 'no'"
     *
     * @param mixed ...$values Conjunto de valores permitidos
     * @return ISpecification
     */
    public function isEitherOfValues(mixed ...$values): ISpecification
    {
        return $this->comparisonFactory->isEitherOfValues(...$values);
    }

    /**
     * Alias para in().
     *
     * Uso fluente: "oneOfValues 1, 2, 3"
     *
     * @param mixed ...$values Conjunto de valores permitidos
     * @return ISpecification
     */
    public function oneOfValues(mixed ...$values): ISpecification
    {
        return $this->comparisonFactory->oneOfValues(...$values);
    }

    /**
     * Alias para in().
     *
     * Uso fluente: "eitherValue 'A', 'B'"
     *
     * @param mixed ...$values Conjunto de valores permitidos
     * @return ISpecification
     */
    public function eitherValue(mixed ...$values): ISpecification
    {
        return $this->comparisonFactory->eitherValue(...$values);
    }

    /**
     * Alias para equalTo().
     *
     * Uso fluente: "exactly 10"
     *
     * @param mixed $value Valor para comparação
     * @return ISpecification
     */
public function exactly(mixed $value): ISpecification
    {
        return $this->comparisonFactory->exactly($value);
    }

}
