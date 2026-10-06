<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\Repositories\IBinaryFormatRepository;

/**
 * BinaryFormatPartitionRepository - Partitioned entity repository with binary serialization format
 *
 * Specialization of PersistentPartitionRepository that implements IBinaryFormatRepository.
 *
 * @template T of IEntity
 * @extends PersistentPartitionRepository<T>
 * @implements IBinaryFormatRepository<T>
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class BinaryFormatPartitionRepository extends PersistentPartitionRepository implements IBinaryFormatRepository
{
}
