<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Pdo\Exceptions;

use Antevemus\ASpecification\Contracts\Repositories\Exceptions\RepositoryException;
use Throwable;

/**
 * PdoRepositoryException - A Statement of the PDO Repository Failed
 *
 * Wraps the driver error (PDOException in ERRMODE_EXCEPTION, or the errorInfo() of a statement that
 * returned false in the silent modes) with the SQLSTATE, so callers see one repository exception type
 * whatever the error mode of the connection.
 *
 * @version    1.11.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\Pdo\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class PdoRepositoryException extends RepositoryException
{
    /**
     * @param string $message Description, with the driver message
     * @param string|null $sqlState Five-character SQLSTATE reported by the driver, when known
     * @param Throwable|null $previous Driver exception, when there was one
     */
    public function __construct(string $message, private readonly ?string $sqlState = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /** SQLSTATE reported by the driver, or null when unknown. */
    public function getSqlState(): ?string
    {
        return $this->sqlState;
    }
}
