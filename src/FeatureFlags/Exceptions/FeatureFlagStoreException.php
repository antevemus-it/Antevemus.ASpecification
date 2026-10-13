<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\FeatureFlags\Exceptions;

use Throwable;

/**
 * FeatureFlagStoreException - Storage Failure of a Feature Flag Store
 *
 * Wraps a driver error raised by an IFeatureFlagStore (a statement that fails, a stored value that
 * cannot be read, an operation the configured tables do not support). The message carries the driver
 * message, never a stack trace; the SQLSTATE, when the driver gave one, is kept apart (the shape of
 * DomainEventPersistenceException).
 *
 * @version    2.1.0
 * @package    Antevemus\ASpecification
 * @subpackage FeatureFlags\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class FeatureFlagStoreException extends FeatureFlagException
{
    /**
     * @param string $message Human-readable message
     * @param Throwable|null $previous Driver exception, when any
     * @param string|null $sqlState SQLSTATE reported by the driver, when any
     */
    public function __construct(
        string $message = '',
        ?Throwable $previous = null,
        private readonly ?string $sqlState = null
    ) {
        parent::__construct($message, 0, $previous);
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
