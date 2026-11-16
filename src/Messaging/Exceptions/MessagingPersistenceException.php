<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Messaging\Exceptions;

/**
 * MessagingPersistenceException - Storage Failure of the Message Outbox or Inbox
 *
 * A PDO statement that failed, a stored row that cannot be read back, an entry that does not exist
 * (forward 063, D4, D8). The SQLSTATE and the driver code are kept apart.
 *
 * Unlike the other PDO stores of the library, the message of the driver is NOT carried, neither in
 * this message nor as the previous exception: PostgreSQL quotes the whole failing row in the detail
 * of a constraint violation ("Failing row contains (...)"), MySQL quotes the offending bytes of a
 * string, and a row of the outbox holds the payload (RN-05). The message names the operation, the
 * table, the SQLSTATE and the driver code, and the message id when there is one; the SQLSTATE
 * tells a missing table (42P01, 42S02; SQLite HY000 code 1) from a constraint (class 23).
 *
 * @version    2.14.0
 * @package    Antevemus\ASpecification
 * @subpackage Messaging\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class MessagingPersistenceException extends MessagingException
{
    /**
     * @param string $message Message without driver text nor stored values
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
