<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Sql\Exceptions;

use RuntimeException;

/**
 * SqlVisitorException - Base Exception for SQL Translation Failures
 *
 * Thrown during traversal and compilation of specification trees into SQL dialect expressions.
 *
 * Features:
 * - Typed base exception for SQL subsystem
 * - Traceability preserving original message and cause
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Sql\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class SqlVisitorException extends RuntimeException
{
}
