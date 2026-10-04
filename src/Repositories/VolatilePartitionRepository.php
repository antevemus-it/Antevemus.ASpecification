<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\Repositories\IVolatileRepository;

/**
 * VolatilePartitionRepository - Repositório particionado volátil
 *
 * Especialização de PartitionRepository que preserva o contrato de IVolatileRepository.
 *
 * Funcionalidades:
 * - Implementação estrita de IVolatileRepository
 * - Preservação semântica de repositório em memória
 *
 * @template T of IEntity
 * @extends PartitionRepository<T>
 * @implements IVolatileRepository<T>
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class VolatilePartitionRepository extends PartitionRepository implements IVolatileRepository
{
}
