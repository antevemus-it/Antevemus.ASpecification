<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Repositories;

use Antevemus\ASpecification\Contracts\Entities\IEntity;

/**
 * IHumanReadableFormatRepository - Marker Interface for Human-Readable Format Repositories
 *
 * Classification marker interface for textual persistent repositories
 * structured in human-friendly formats (e.g. formatted JSON, YAML).
 *
 * Features:
 * - Semantic classification for human-readable textual formats
 * - Hierarchical extension of ITextualFormatRepository and IPersistentRepository
 * - Type preservation during repository promotion
 *
 * @template T of IEntity
 * @extends ITextualFormatRepository<T>
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IHumanReadableFormatRepository extends ITextualFormatRepository
{
}
