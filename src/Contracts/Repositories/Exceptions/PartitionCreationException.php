<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Repositories\Exceptions;

use Throwable;

/**
 * PartitionCreationException - A partition could not be created from an identifier alone
 *
 * Thrown by IPartitionRepository::addPartitionWithId() when the node repository is persistent:
 * a persistent repository needs its own storage (path, connection, serializer) and the library
 * never derives one from a partition identifier. The caller must build the sibling repository
 * explicitly and use addPartitionWithRepository() (BUG-20261007-QFSJ, spec 005 RN-10).
 *
 * Features:
 * - Names the repository type that refused the operation
 * - Points to the supported path (addPartitionWithRepository)
 *
 * @version    1.4.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Repositories\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class PartitionCreationException extends RepositoryException
{
    /**
     * @param string $repositoryClass FQCN of the node repository that cannot be cloned from an id
     * @param string $partitionId Identifier that was requested for the new partition
     * @param Throwable|null $previous Previous exception, if any
     */
    public function __construct(
        private readonly string $repositoryClass,
        private readonly string $partitionId,
        ?Throwable $previous = null
    ) {
        parent::__construct(sprintf(
            "Cannot create partition '%s' from an identifier alone: %s is a persistent repository and needs " .
            "its own storage. Build the sibling repository explicitly and use addPartitionWithRepository().",
            $partitionId,
            $repositoryClass
        ), 0, $previous);
    }

    /** FQCN of the node repository that refused to create a sibling from an identifier. */
    public function getRepositoryClass(): string
    {
        return $this->repositoryClass;
    }

    /** Identifier requested for the partition that could not be created. */
    public function getPartitionId(): string
    {
        return $this->partitionId;
    }
}
