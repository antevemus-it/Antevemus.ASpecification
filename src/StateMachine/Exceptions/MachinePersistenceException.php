<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Exceptions;

use Throwable;

/**
 * MachinePersistenceException - Storage Failure of the Machine Runner
 *
 * Wraps a driver error raised by a persistence port (PDO statement that fails, malformed stored
 * row or JSON, duplicate row in an append-only table). The message carries the driver message,
 * never a stack trace; the SQLSTATE, when the driver gave one, is kept apart (forward 023, D14).
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class MachinePersistenceException extends StateMachineException
{
    /**
     * @param string $message Human-readable message
     * @param int $code Exception code
     * @param Throwable|null $previous Driver exception, when any
     * @param string|null $sqlState SQLSTATE reported by the driver, when any
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        private readonly ?string $sqlState = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /** SQLSTATE reported by the driver, when any. */
    public function getSqlState(): ?string
    {
        return $this->sqlState;
    }

    /** True when the driver reported an integrity constraint violation (SQLSTATE class 23). */
    public function isIntegrityViolation(): bool
    {
        return $this->sqlState !== null && str_starts_with($this->sqlState, '23');
    }
}
