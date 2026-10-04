<?php

namespace Antevemus\ASpecification\Contracts\Repositories;

/**
 * Interface IVolatileRepository.
 *
 * Interface de marcação (Marker Interface) para repositórios Voláteis.
 * Um repositório volátil não suporta durabilidade: todos os dados gravados nele
 * são perdidos após o término da execução ou encerramento do processo.
 *
 * @template T of \Antevemus\ASpecification\Contracts\Entities\IEntity
 * @extends IRepository<T>
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IVolatileRepository extends IRepository
{
    // Interface de marcação puramente semântica
}
