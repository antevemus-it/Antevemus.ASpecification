<?php

namespace Antevemus\ASpecification\Contracts\Factory;

use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * ISpecificationWrapperFactory interface.
 *
 * Contrato para fábricas que criam wrappers fluentes para especificações.
 *
 * Esta interface fornece métodos que encapsulam especificações existentes
 * com nomes mais descritivos e fluentes, sem alterar o comportamento.
 * São essencialmente funções identidade com nomes expressivos para melhorar
 * a legibilidade do código.
 *
 * Exemplo de uso:
 * <code>
 * // Sem wrapper
 * $spec = new ActiveUserSpecification();
 *
 * // Com wrapper fluente
 * $spec = $factory->is(new ActiveUserSpecification());
 * // ou
 * $spec = $factory->isA(new ActiveUserSpecification());
 * </code>
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Factory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface ISpecificationWrapperFactory extends ISpecificationFactory
{
    /**
     * Wrapper fluente que retorna a especificação sem alterações.
     *
     * Uso idiomático: "isSatisfiedBy someSpec"
     * Melhora legibilidade em contextos onde a condição está sendo testada.
     *
     * @template T
     * @param ISpecification<T> $specification Especificação a encapsular
     * @return ISpecification<T> A mesma especificação
     */
    public function isSatisfiedBy(ISpecification $specification): ISpecification;

    /**
     * Wrapper fluente que retorna a especificação sem alterações.
     *
     * Uso idiomático: "a someSpec"
     *
     * @template T
     * @param ISpecification<T> $specification Especificação a encapsular
     * @return ISpecification<T> A mesma especificação
     */
    public function a(ISpecification $specification): ISpecification;

    /**
     * Wrapper fluente que retorna a especificação sem alterações.
     *
     * Uso idiomático: "an someSpec"
     *
     * @template T
     * @param ISpecification<T> $specification Especificação a encapsular
     * @return ISpecification<T> A mesma especificação
     */
    public function an(ISpecification $specification): ISpecification;

    /**
     * Wrapper fluente que retorna a especificação sem alterações.
     *
     * Uso idiomático: "is someSpec"
     *
     * @template T
     * @param ISpecification<T> $specification Especificação a encapsular
     * @return ISpecification<T> A mesma especificação
     */
    public function is(ISpecification $specification): ISpecification;

    /**
     * Wrapper fluente que retorna a especificação sem alterações.
     *
     * Uso idiomático: "isA someSpec"
     *
     * @template T
     * @param ISpecification<T> $specification Especificação a encapsular
     * @return ISpecification<T> A mesma especificação
     */
    public function isA(ISpecification $specification): ISpecification;

    /**
     * Wrapper fluente que retorna a especificação sem alterações.
     *
     * Uso idiomático: "isAn someSpec"
     *
     * @template T
     * @param ISpecification<T> $specification Especificação a encapsular
     * @return ISpecification<T> A mesma especificação
     */
    public function isAn(ISpecification $specification): ISpecification;

    /**
     * Wrapper fluente que retorna a especificação sem alterações.
     *
     * Uso idiomático: "are someSpec"
     *
     * @template T
     * @param ISpecification<T> $specification Especificação a encapsular
     * @return ISpecification<T> A mesma especificação
     */
    public function are(ISpecification $specification): ISpecification;

    /**
     * Wrapper fluente que retorna a especificação sem alterações.
     *
     * Uso idiomático: "isFrom someSpec"
     *
     * @template T
     * @param ISpecification<T> $specification Especificação a encapsular
     * @return ISpecification<T> A mesma especificação
     */
    public function isFrom(ISpecification $specification): ISpecification;
}
