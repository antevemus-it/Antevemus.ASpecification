<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Entities;

use Antevemus\ASpecification\Util\UuidV7Generator;

/**
 * AbstractUuidV7Entity - Abstract Domain Entity with Time-Ordered UUID v7 Identity
 *
 * Automatically provisions an RFC 9562 version 7 UUID string identity (48-bit millisecond
 * timestamp, version 7, RFC variant, 74 bits of counter/randomness) upon entity instantiation.
 * Same surface and canonical 36-character format as AbstractUUIDEntity; the difference is that
 * identities sort by creation time, which keeps database indexes in insertion order.
 *
 * Features:
 * - RFC 9562 UUIDv7 identity from the process-wide UuidV7Generator (monotonic within the same millisecond)
 * - Readonly immutable string identity
 *
 * @version    1.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Entities
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class AbstractUuidV7Entity extends AbstractEntity
{
    protected readonly string $entityId;

    /**
     * Constructor initializing the entity with a canonical UUID v7 string identity.
     */
    public function __construct()
    {
        parent::__construct();
        $this->entityId = UuidV7Generator::shared()->generate();
    }

    /**
     * {@inheritdoc}
     *
     * @return string The canonical UUID v7 string of the entity
     */
    public final function getEntityId(): string
    {
        return $this->entityId;
    }
}
