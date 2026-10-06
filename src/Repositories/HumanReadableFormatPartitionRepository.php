<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\Repositories\IHumanReadableFormatRepository;

/**
 * HumanReadableFormatPartitionRepository - Partitioned entity repository with human-readable textual serialization format
 *
 * Specialization of TextualFormatPartitionRepository that implements IHumanReadableFormatRepository.
 *
 * @template T of IEntity
 * @extends TextualFormatPartitionRepository<T>
 * @implements IHumanReadableFormatRepository<T>
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class HumanReadableFormatPartitionRepository extends TextualFormatPartitionRepository implements IHumanReadableFormatRepository
{
}
