<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\Repositories\IFakeRepository;

/**
 * FakePartitionRepository - Test double / fake partitioned entity repository
 *
 * Specialization of PartitionRepository implementing IFakeRepository for testing scenarios.
 *
 * @template T of IEntity
 * @extends PartitionRepository<T>
 * @implements IFakeRepository<T>
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class FakePartitionRepository extends PartitionRepository implements IFakeRepository
{
}
