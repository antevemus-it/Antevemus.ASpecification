<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Entities;

use LogicException;

/**
 * AbstractUUIDEntity - Abstract Domain Entity with Canonical UUID v4 Identity
 *
 * Automatically provisions a standard RFC 4122 version 4 UUID string identity
 * upon entity instantiation using PHP's cryptographically secure random bytes generator.
 *
 * Features:
 * - RFC 4122 v4 canonical UUID generation without external dependencies
 * - Readonly immutable string identity
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Entities
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class AbstractUUIDEntity extends AbstractEntity
{
    protected readonly string $entityId;

    /**
     * Constructor initializing the entity with a canonical UUID v4 string identity.
     */
    public function __construct()
    {
        parent::__construct();
        $this->entityId = $this->generateUuidV4();
    }

    /**
     * {@inheritdoc}
     *
     * @return string The canonical UUID string of the entity
     */
    public final function getEntityId(): string
    {
        return $this->entityId;
    }

    /**
     * Generate an RFC 4122 compliant version 4 UUID string.
     * Clean native implementation avoiding heavy external dependencies.
     *
     * @return string
     * @throws LogicException If system entropy source is unavailable
     */
    private function generateUuidV4(): string
    {
        try {
            $data = random_bytes(16);
            
            // Set version 4 (0100)
            $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
            // Set variant RFC 4122 (10)
            $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
            
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        } catch (\Exception $e) {
            throw new LogicException("Catastrophic failure in PHP native RNG source.", 0, $e);
        }
    }
}
