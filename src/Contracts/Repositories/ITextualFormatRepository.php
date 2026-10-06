<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Repositories;

use Antevemus\ASpecification\Contracts\Entities\IEntity;

/**
 * ITextualFormatRepository - Marker Interface for Textual Format Repositories
 *
 * Classification marker interface for persistent repositories storing
 * entities in textual formats (JSON, XML, CSV, YAML, etc.).
 *
 * Features:
 * - Semantic classification for repositories with textual media
 * - Type preservation during repository promotion
 *
 * @template T of IEntity
 * @extends IPersistentRepository<T>
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface ITextualFormatRepository extends IPersistentRepository
{
}
