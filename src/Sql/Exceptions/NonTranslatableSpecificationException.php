<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Sql\Exceptions;

use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * NonTranslatableSpecificationException - Exception for Specifications Incompatible with SQL
 *
 * Occurs when the specification tree contains purely in-memory nodes (such as arbitrary
 * Closures or I/O rules) that cannot be translated into native SQL query clauses.
 *
 * Features:
 * - Exact identification of unsupported specification class
 * - Detailed explanation of translation incompatibility
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Sql\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class NonTranslatableSpecificationException extends SqlVisitorException
{
    /**
     * @param ISpecification $specification Incompatible specification instance
     * @param string $reason Descriptive reason for translation incompatibility
     */
    public function __construct(
        public readonly ISpecification $specification,
        string $reason = ''
    ) {
        $className = get_class($specification);
        $msg = sprintf(
            'Specification of type "%s" cannot be translated into an SQL query%s.',
            $className,
            $reason !== '' ? ": {$reason}" : ''
        );
        parent::__construct($msg);
    }
}
