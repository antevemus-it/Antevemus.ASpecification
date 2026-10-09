<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Entities;

use Antevemus\ASpecification\Util\UlidGenerator;

/**
 * AbstractUlidEntity - Abstract Domain Entity with ULID Identity
 *
 * Automatically provisions a ULID string identity (26 Crockford base32 characters, 48-bit
 * millisecond timestamp + 80-bit randomness) upon entity instantiation. Same surface as
 * AbstractUUIDEntity; the difference is that identities sort lexicographically by creation time,
 * which keeps database indexes and file listings in insertion order.
 *
 * Features:
 * - ULID identity from the process-wide UlidGenerator (monotonic within the same millisecond)
 * - Readonly immutable string identity
 *
 * @version    1.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Entities
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class AbstractUlidEntity extends AbstractEntity
{
    protected readonly string $entityId;

    /**
     * Constructor initializing the entity with a ULID string identity.
     */
    public function __construct()
    {
        parent::__construct();
        $this->entityId = UlidGenerator::shared()->generate();
    }

    /**
     * {@inheritdoc}
     *
     * @return string The 26-character ULID of the entity
     */
    public final function getEntityId(): string
    {
        return $this->entityId;
    }
}
