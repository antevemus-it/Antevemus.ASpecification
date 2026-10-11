<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\DomainEvents\Exceptions;

use Throwable;

/**
 * DomainEventPersistenceException - Storage Failure of the Domain Event Outbox
 *
 * Wraps a driver error raised by an IDomainEventOutbox (PDO statement that fails, malformed stored
 * row, missing entry). The message carries the driver message, never a stack trace; the SQLSTATE,
 * when the driver gave one, is kept apart (forward 029, D5; same shape as the 1.8.0
 * MachinePersistenceException).
 *
 * @version    1.11.0
 * @package    Antevemus\ASpecification
 * @subpackage DomainEvents\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class DomainEventPersistenceException extends DomainEventException
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
