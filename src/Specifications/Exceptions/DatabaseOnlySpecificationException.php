<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Exceptions;

use LogicException;

/**
 * DatabaseOnlySpecificationException - A Specification Evaluated by the Database Only (2.5.0)
 *
 * Raised by isSatisfiedBy() of the subqueries of Spec::inSubquery() and Spec::existsIn() (forward 048,
 * D21): they read another table of the database, which a candidate in memory does not hold. Under
 * evaluate() it becomes an evaluation-error result (SpecificationResult::error): never true nor false,
 * NOT does not invert it and the composites propagate it, so a tree holding a subquery is never
 * approved or refused in PHP by accident. Translate the tree with toSql() or toCriteria() instead.
 *
 * @version    2.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class DatabaseOnlySpecificationException extends LogicException
{
    /**
     * @param string $ruleName Specification that cannot be evaluated in memory
     */
    public function __construct(public readonly string $ruleName)
    {
        parent::__construct(sprintf(
            '%s is evaluated only by the database: translate the specification with toSql() or toCriteria().',
            $ruleName
        ));
    }
}
