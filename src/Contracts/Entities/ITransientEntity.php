<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Entities;

/**
 * ITransientEntity - Marker Interface for Ephemeral Domain Entities
 *
 * Marker interface designating transient entities. Transient entities
 * are entities by formal definition (possessing identity and creation time),
 * but ephemeral in persistence lifecycle (e.g. timestamped value objects or computed projections).
 *
 * Persisting transient entities should generally be avoided.
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Entities
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface ITransientEntity extends IEntity
{
    // Semantic marker interface
}
