<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\Repositories\IVolatileRepository;

/**
 * VolatilePartitionRepository - Volatile partitioned entity repository
 *
 * Specialization of PartitionRepository preserving the IVolatileRepository contract.
 *
 * Features:
 * - Strict implementation of IVolatileRepository
 * - Semantic preservation of in-memory repository partitions
 * - Lazy iterate*() inherited from PartitionRepository: the node and each partition are consumed
 *   through their own generators, nothing is materialized before the first entity (1.4.4, checked
 *   by the 1.5.0 RN-04 suite)
 *
 * @template T of IEntity
 * @extends PartitionRepository<T>
 * @implements IVolatileRepository<T>
 * @version    1.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class VolatilePartitionRepository extends PartitionRepository implements IVolatileRepository
{
}
