<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Entities;

/**
 * AbstractRandomIntegerEntity - Abstract Test Fixture Entity with Random Integer Identifier
 *
 * Automatically assigns a pseudo-random integer identity upon instantiation.
 *
 * CAUTION: Designed solely for testing, rapid fixture generation, and benchmarking.
 * DO NOT USE THIS CLASS IN PRODUCTION DOMAIN MODELS!
 *
 * Features:
 * - Automatic random 64-bit integer identity generation
 * - Readonly entityId property protection
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Entities
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class AbstractRandomIntegerEntity extends AbstractEntity
{
    protected readonly int $entityId;

    /**
     * Constructor generating a pseudo-random positive integer identifier.
     */
    public function __construct()
    {
        parent::__construct();
        $this->entityId = random_int(1, PHP_INT_MAX);
    }

    /**
     * {@inheritdoc}
     *
     * @return int The generated random entity integer identifier
     */
    public final function getEntityId(): int
    {
        return $this->entityId;
    }
}
