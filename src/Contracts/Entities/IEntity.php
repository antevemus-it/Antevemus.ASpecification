<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Entities;

use DateTimeImmutable;

/**
 * IEntity - Primary Contract for Domain Entities
 *
 * Guarantees that domain entities have a unique, non-null identity
 * and an immutable creation timestamp.
 *
 * Features:
 * - Immutable entity identifier access (getEntityId)
 * - Creation timestamp extraction (getTimeOfCreation)
 * - Identity-based equivalence check (equals)
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Entities
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IEntity
{
    /**
     * A domain entity must always possess a unique, final, and non-null identifier.
     * Return type is mixed to accommodate primitive types (int, string) or structured Value Objects.
     *
     * @return mixed The unique entity identity
     */
    public function getEntityId(): mixed;

    /**
     * A domain entity always originates from a specific instant in time.
     *
     * @return DateTimeImmutable The creation timestamp of this in-memory entity instance
     */
    public function getTimeOfCreation(): DateTimeImmutable;
    
    /**
     * Determine whether this entity is equivalent to another based strictly on identity.
     *
     * @param IEntity $other Target entity to compare against
     * @return bool
     */
    public function equals(IEntity $other): bool;
}
