<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Pdo\Exceptions;

use Antevemus\ASpecification\Contracts\Repositories\Exceptions\RepositoryException;

/**
 * OptimisticLockException - The Entity Was Changed or Removed by Someone Else
 *
 * Thrown by an update or a removal of a versioned entity (#[Version]) when the row no longer holds
 * the version the entity carries: another writer updated it since it was read, or removed it. Nothing
 * was written; reload the entity and apply the change again.
 *
 * @version    1.11.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\Pdo\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class OptimisticLockException extends RepositoryException
{
    /**
     * @param string $entityClass Class of the stale entity
     * @param mixed $entityId Its identifier
     * @param int|null $expectedVersion Version the entity carried
     * @param int|null $actualVersion Version found in the row, or null when the row is gone
     */
    public function __construct(
        private readonly string $entityClass,
        private readonly mixed $entityId,
        private readonly ?int $expectedVersion,
        private readonly ?int $actualVersion
    ) {
        parent::__construct(sprintf(
            '%s #%s is stale: it carries version %s but the row %s. Reload it and apply the change again.',
            $entityClass,
            is_scalar($entityId) ? (string) $entityId : get_debug_type($entityId),
            $expectedVersion === null ? 'null' : (string) $expectedVersion,
            $actualVersion === null ? 'no longer exists' : 'holds version ' . $actualVersion
        ));
    }

    /** Class of the stale entity. */
    public function getEntityClass(): string
    {
        return $this->entityClass;
    }

    /** Identifier of the stale entity. */
    public function getEntityId(): mixed
    {
        return $this->entityId;
    }

    /** Version the entity carried. */
    public function getExpectedVersion(): ?int
    {
        return $this->expectedVersion;
    }

    /** Version found in the row, or null when the row no longer exists. */
    public function getActualVersion(): ?int
    {
        return $this->actualVersion;
    }
}
