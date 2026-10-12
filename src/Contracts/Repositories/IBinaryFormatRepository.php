<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Repositories;

use Antevemus\ASpecification\Contracts\Entities\IEntity;

/**
 * IBinaryFormatRepository - Marker Interface for Binary Format Repositories
 *
 * Classification marker interface for persistent repositories storing
 * entities in binary formats (Protobuf, BSON, etc.; the library ships none since the PHP-native
 * serializer was removed in 2.0.0).
 *
 * Features:
 * - Semantic classification for repositories with binary media
 * - Type preservation during repository promotion
 *
 * @template T of IEntity
 * @extends IPersistentRepository<T>
 * @version    2.0.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IBinaryFormatRepository extends IPersistentRepository
{
}
