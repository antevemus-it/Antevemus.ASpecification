<?php

namespace Antevemus\ASpecification\Contracts\Factory;

use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * ISpecialSpecificationFactory interface.
 *
 * Contrato para fábricas que criam especificações especiais.
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Factory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface ISpecialSpecificationFactory extends ISpecificationFactory
{
    /**
     * Cria especificação que sempre retorna verdadeiro.
     *
     * Útil para casos de teste, especificações padrão ou composições
     * onde você precisa de um caso base sempre verdadeiro.
     *
     * @return ISpecification Especificação que sempre é satisfeita
     */
    public function alwaysTrue(): ISpecification;

    /**
     * Cria especificação que sempre retorna falso.
     *
     * Útil para casos de teste, especificações de negação total ou
     * situações onde nenhum candidato deve ser aceito.
     *
     * @return ISpecification Especificação que nunca é satisfeita
     */
    public function alwaysFalse(): ISpecification;

    /**
     * Cria especificação que verifica se o valor é null.
     *
     * @return ISpecification Especificação que verifica null
     */
    public function isNull(): ISpecification;

    /**
     * Cria especificação que verifica se o valor não é null.
     *
     * @return ISpecification Especificação que verifica não-null
     */
    public function isNotNull(): ISpecification;

    /**
     * Cria especificação que verifica se o valor é verdadeiro (true).
     *
     * Útil para verificar valores booleanos ou valores que podem ser
     * convertidos para booleano.
     *
     * @return ISpecification Especificação que verifica true
     */
    public function isTrue(): ISpecification;

    /**
     * Cria especificação que verifica se o valor é falso (false).
     *
     * Útil para verificar valores booleanos ou valores que podem ser
     * convertidos para booleano.
     *
     * @return ISpecification Especificação que verifica false
     */
    public function isFalse(): ISpecification;
}
