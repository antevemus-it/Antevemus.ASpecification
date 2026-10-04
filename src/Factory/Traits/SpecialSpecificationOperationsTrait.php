<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Factory\Traits;

use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * SpecialSpecificationOperationsTrait - Trait agregador de especificações especiais e valores sentinela (ISpecialSpecificationFactory).
 *
 * Funcionalidades:
 * - Tautologia e contradição (alwaysTrue, alwaysFalse)
 * - Verificação de nulidade (isNull, isNotNull)
 * - Predicados booleanos (isTrue, isFalse)
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Factory\Traits
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
trait SpecialSpecificationOperationsTrait
{
    /**
     * Cria especificação que sempre retorna verdadeiro.
     *
     * Útil para casos de teste, especificações padrão ou composições
     * onde você precisa de um caso base sempre verdadeiro.
     *
     * @return ISpecification Especificação que sempre é satisfeita
     */
    public function alwaysTrue(): ISpecification
    {
        return $this->specialFactory->alwaysTrue();
    }

    /**
     * Cria especificação que sempre retorna falso.
     *
     * Útil para casos de teste, especificações de negação total ou
     * situações onde nenhum candidato deve ser aceito.
     *
     * @return ISpecification Especificação que nunca é satisfeita
     */
    public function alwaysFalse(): ISpecification
    {
        return $this->specialFactory->alwaysFalse();
    }

    /**
     * Cria especificação que verifica se o valor é null.
     *
     * @return ISpecification Especificação que verifica null
     */
    public function isNull(): ISpecification
    {
        return $this->specialFactory->isNull();
    }

    /**
     * Cria especificação que verifica se o valor não é null.
     *
     * @return ISpecification Especificação que verifica não-null
     */
    public function isNotNull(): ISpecification
    {
        return $this->specialFactory->isNotNull();
    }

    /**
     * Cria especificação que verifica se o valor é verdadeiro (true).
     *
     * Útil para verificar valores booleanos ou valores que podem ser
     * convertidos para booleano.
     *
     * @return ISpecification Especificação que verifica true
     */
    public function isTrue(): ISpecification
    {
        return $this->specialFactory->isTrue();
    }

    /**
     * Cria especificação que verifica se o valor é falso (false).
     *
     * Útil para verificar valores booleanos ou valores que podem ser
     * convertidos para booleano.
     *
     * @return ISpecification Especificação que verifica false
     */
    public function isFalse(): ISpecification
    {
        return $this->specialFactory->isFalse();
    }

}
