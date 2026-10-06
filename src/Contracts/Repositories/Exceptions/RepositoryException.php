<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Repositories\Exceptions;

use RuntimeException;
use Throwable;

/**
 * RepositoryException - Base Exception for Repository Operations
 *
 * Base exception thrown by repositories in the Specification ecosystem when
 * an internal error occurs during storage, querying, or partitioning operations.
 *
 * Features:
 * - Encapsulation of internal failures with root cause chaining (Throwable)
 * - Explicit identification of the failing repository operation
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Repositories\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class RepositoryException extends RuntimeException
{
    /**
     * Constructs a repository exception.
     *
     * @param string $message Descriptive error message
     * @param int $code Numeric error code
     * @param Throwable|null $previous Previous exception that caused this failure
     */
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
