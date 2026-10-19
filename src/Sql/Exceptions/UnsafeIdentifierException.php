<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Sql\Exceptions;

/**
 * UnsafeIdentifierException - Rejected SQL Identifier
 *
 * Thrown when a property name or mapped column does not match the strict
 * identifier grammar accepted by the SQL dialects (plain or qualified identifier,
 * or a simple function call over identifiers). Anything else would reach the
 * WHERE clause unquoted, which is an injection vector (BUG-20261007-SJVE).
 * Since 2.5.0 also raised by the subqueries of Spec::inSubquery()/Spec::existsIn() for a table or a
 * column outside their grammar (the message then says which).
 *
 * @version    2.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Sql\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class UnsafeIdentifierException extends SqlVisitorException
{
    /**
     * @param string $identifier The rejected identifier
     * @param string|null $grammar What is accepted where it was rejected (2.5.0); null = the grammar of the
     *                             SQL dialects
     */
    public function __construct(public readonly string $identifier, ?string $grammar = null)
    {
        parent::__construct(sprintf(
            'Unsafe SQL identifier rejected: "%s". %s',
            $identifier,
            $grammar ?? 'Only plain or qualified identifiers '
                . '([A-Za-z_][A-Za-z0-9_$]*, optionally dot-qualified) or simple function calls over '
                . 'identifiers (e.g. LOWER(name)) are accepted.'
        ));
    }
}
