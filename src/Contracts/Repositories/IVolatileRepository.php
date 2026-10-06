<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Repositories;

/**
 * IVolatileRepository - Marker Interface for Volatile Repositories
 *
 * Semantic marker interface for volatile repositories.
 * A volatile repository does not support durability: all stored entities
 * are lost once execution finishes or the process terminates.
 *
 * @template T of \Antevemus\ASpecification\Contracts\Entities\IEntity
 * @extends IRepository<T>
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IVolatileRepository extends IRepository
{
    // Pure semantic marker interface
}
