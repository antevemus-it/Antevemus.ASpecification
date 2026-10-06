<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Entities;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use DateTimeImmutable;
use LogicException;

/**
 * AbstractEntity - Base Abstract Class for Domain Entity Objects
 *
 * Provides identity-based equality logic and creation timestamp tracking.
 * Ensures comparison semantics are consistent and immutable, governed strictly
 * by identity rather than mutable attributes.
 *
 * Features:
 * - Immutable creation timestamp recording (timeOfCreation)
 * - Optimistic locking version tracking support (version)
 * - Identity-based equivalence evaluation (equals)
 * - Domain object classification indicators (isEntity, isValueObject)
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Entities
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class AbstractEntity implements IEntity
{
    protected readonly DateTimeImmutable $timeOfCreation;

    /**
     * Version control counter for optimistic locking support in RDBMS.
     */
    protected ?int $version = null;

    /**
     * Initialize entity setting immutable creation timestamp.
     */
    public function __construct()
    {
        $this->timeOfCreation = new DateTimeImmutable();
    }

    /**
     * Get optimistic locking version of the entity.
     *
     * @return int|null
     */
    public function getVersion(): ?int
    {
        return $this->version;
    }

    /**
     * Set optimistic locking version.
     *
     * @param int|null $version
     */
    public function setVersion(?int $version): void
    {
        $this->version = $version;
    }

    /**
     * {@inheritdoc}
     */
    public function getTimeOfCreation(): DateTimeImmutable
    {
        return $this->timeOfCreation;
    }

    /**
     * {@inheritdoc}
     *
     * @return bool Always true for domain entities
     */
    public final function isEntity(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     *
     * @return bool Always false for domain entities
     */
    public final function isValueObject(): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function equals(IEntity $other): bool
    {
        if ($this === $other) {
            return true;
        }

        $thisId = $this->getEntityId();
        $otherId = $other->getEntityId();

        if ($thisId === null || $otherId === null) {
            throw new LogicException("The entity does not have a valid ID to compare.");
        }

        // When identities are Value Objects providing their own equals() method
        if (is_object($thisId) && method_exists($thisId, 'equals')) {
            return $thisId->equals($otherId);
        }

        return $thisId === $otherId;
    }
}
