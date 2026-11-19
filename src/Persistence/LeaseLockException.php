<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Persistence;

use RuntimeException;

/**
 * LeaseLockException - Storage Failure of a Lease Lock
 *
 * A PDO statement of PdoLeaseLock that failed (forward 065, D9). The SQLSTATE and the driver code are
 * kept apart; the message names the operation, the table, the lock name, the SQLSTATE and the driver
 * code, never the message of the driver, in the convention of the stores of the messaging hook (a lock
 * row carries no payload, but one rule for every store of the outboxes is easier to keep).
 *
 * @version    2.16.0
 * @package    Antevemus\ASpecification
 * @subpackage Persistence
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class LeaseLockException extends RuntimeException
{
    /**
     * @param string $message Message without driver text
     * @param string|null $sqlState SQLSTATE reported by the driver, when any
     * @param string|null $driverCode Driver-specific error code, when any
     */
    public function __construct(
        string $message,
        private readonly ?string $sqlState = null,
        private readonly ?string $driverCode = null
    ) {
        parent::__construct($message);
    }

    /** SQLSTATE reported by the driver, when any. */
    public function getSqlState(): ?string
    {
        return $this->sqlState;
    }

    /** Driver-specific error code (errorInfo()[1]), when any. */
    public function getDriverCode(): ?string
    {
        return $this->driverCode;
    }

    /** True when the driver reported an integrity constraint violation (SQLSTATE class 23). */
    public function isIntegrityViolation(): bool
    {
        return $this->sqlState !== null && str_starts_with($this->sqlState, '23');
    }
}
