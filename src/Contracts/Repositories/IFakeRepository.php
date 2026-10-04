<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Repositories;

use Antevemus\ASpecification\Contracts\Entities\IEntity;

/**
 * IFakeRepository - Marcador de repositório dublê/teste
 *
 * Interface marcadora de classificação para repositórios dublês (test doubles,
 * mocks, stubs ou repositórios de simulação/apoio a testes).
 * Repositórios marcados com esta interface são classificados como 'fake' na promoção
 * e não se propõem a persistir ou reter entidades de forma produtiva.
 *
 * Funcionalidades:
 * - Classificação semântica de repositórios dublês de teste
 * - Preservação do tipo durante promoção de repositório
 *
 * @template T of IEntity
 * @extends IRepository<T>
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IFakeRepository extends IRepository
{
}
