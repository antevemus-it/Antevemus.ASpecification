<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Repositories;

use Antevemus\ASpecification\Contracts\Entities\IEntity;

/**
 * IFakeRepository - Marker Interface for Test Double / Fake Repositories
 *
 * Classification marker interface for test double repositories (mocks,
 * stubs, test simulators). Repositories marked with this interface are
 * classified as 'fake' during repository promotion and do not retain entities for production.
 *
 * Features:
 * - Semantic classification of test double repositories
 * - Type preservation during repository promotion
 *
 * @template T of IEntity
 * @extends IRepository<T>
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IFakeRepository extends IRepository
{
}
