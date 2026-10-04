<?php

namespace Antevemus\ASpecification\Contracts\Entities;

use DateTimeImmutable;

/**
 * Interface IEntity.
 *
 * Contrato primário para todas as entidades de domínio da aplicação.
 * Garante que a entidade possua uma identidade única e um registro de momento de criação.
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Entities
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IEntity
{
    /**
     * Uma entidade de domínio sempre deve possuir um ID único, final e não-nulo.
     * O retorno é `mixed` para abranger a possibilidade de primitivos (int, string) ou Value Objects estruturados.
     *
     * @return mixed A identidade da entidade
     */
    public function getEntityId(): mixed;

    /**
     * Uma entidade de domínio sempre origina de um momento específico no tempo.
     *
     * @return DateTimeImmutable O momento da criação em memória desta instância da entidade
     */
    public function getTimeOfCreation(): DateTimeImmutable;
    
    /**
     * Avalia se a entidade é equivalente a outra baseada puramente na identidade.
     *
     * @param IEntity $other A entidade a ser comparada
     * @return bool
     */
    public function equals(IEntity $other): bool;
}
