<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Factory\Traits;

use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * SpecificationWrapperOperationsTrait - Trait agregador de adaptadores e wrappers fluentes de especificação (ISpecificationWrapperFactory).
 *
 * Funcionalidades:
 * - Envelopamento transparente de especificações
 * - Aliases semânticos para fluência (isSatisfiedBy, are, isFrom)
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Factory\Traits
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
trait SpecificationWrapperOperationsTrait
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
    public function isSatisfiedBy(ISpecification $specification): ISpecification
    {
        return $this->wrapperFactory->isSatisfiedBy($specification);
    }

    /**
     * Wrapper fluente que retorna a especificação sem alterações.
     *
     * Uso idiomático: "are someSpec"
     *
     * @template T
     * @param ISpecification<T> $specification Especificação a encapsular
     * @return ISpecification<T> A mesma especificação
     */
    public function are(ISpecification $specification): ISpecification
    {
        return $this->wrapperFactory->are($specification);
    }

    /**
     * Wrapper fluente que retorna a especificação sem alterações.
     *
     * Uso idiomático: "isFrom someSpec"
     *
     * @template T
     * @param ISpecification<T> $specification Especificação a encapsular
     * @return ISpecification<T> A mesma especificação
     */
    public function isFrom(ISpecification $specification): ISpecification
    {
        return $this->wrapperFactory->isFrom($specification);
    }
}
