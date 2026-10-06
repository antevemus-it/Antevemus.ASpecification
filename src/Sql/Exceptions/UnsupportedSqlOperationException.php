<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Sql\Exceptions;

/**
 * UnsupportedSqlOperationException - Exception for SQL Operation Not Supported by Dialect
 *
 * Thrown when a specific operation (e.g. Regular Expressions) lacks syntax support
 * in the configured SQL dialect (e.g. Firebird 2.5 or standard SQL Server).
 *
 * Features:
 * - Identifies unsupported operation and target dialect
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Sql\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class UnsupportedSqlOperationException extends SqlVisitorException
{
    /**
     * @param string $operation Name of requested operation
     * @param string $dialect Target SQL dialect name
     */
    public function __construct(string $operation, string $dialect)
    {
        parent::__construct(sprintf(
            'The operation "%s" is not natively supported by the SQL dialect "%s".',
            $operation,
            $dialect
        ));
    }
}
