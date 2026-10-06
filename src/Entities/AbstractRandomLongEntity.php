<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Entities;

/**
 * AbstractRandomLongEntity - Abstract Test Fixture Entity with High 64-bit Integer Identifier
 *
 * Automatically assigns a high-range random integer identity (simulating Java 64-bit Long).
 *
 * CAUTION: Designed purely for stress testing, volume validation, and benchmarks.
 * DO NOT USE THIS CLASS IN PRODUCTION DOMAIN MODELS!
 *
 * Features:
 * - High-range random integer generation (> 1,000,000,000)
 * - Readonly entityId property protection
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Entities
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class AbstractRandomLongEntity extends AbstractEntity
{
    protected readonly int $entityId;

    /**
     * Constructor generating a high-range random integer identifier.
     */
    public function __construct()
    {
        parent::__construct();
        // Ensure generated number is large (above billions)
        $this->entityId = random_int(1000000000, PHP_INT_MAX);
    }

    /**
     * {@inheritdoc}
     *
     * @return int The generated high random integer identifier
     */
    public final function getEntityId(): int
    {
        return $this->entityId;
    }
}
