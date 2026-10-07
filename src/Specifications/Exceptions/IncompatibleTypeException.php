<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Exceptions;

use InvalidArgumentException;

/**
 * IncompatibleTypeException - Candidate type cannot be compared with the specification value.
 *
 * Raised by comparison and equality leaf specifications when the candidate's type is not
 * comparable with the bound value (e.g. a string candidate against a numeric threshold, an
 * integer against a boolean, a scalar against a DateTimeInterface). PHP's loose comparison
 * rules would silently produce a verdict in those cases; this library refuses to guess.
 *
 * Under evaluate() this exception becomes an evaluation-error result (SpecificationResult::error),
 * never a rule failure. Under isSatisfiedBy() it propagates.
 *
 * @version    1.2.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class IncompatibleTypeException extends InvalidArgumentException
{
    /**
     * @param string $ruleName Specification that refused the comparison
     * @param mixed $candidate Candidate received
     * @param mixed $value Value bound to the specification
     */
    public function __construct(
        public readonly string $ruleName,
        public readonly mixed $candidate,
        public readonly mixed $value
    ) {
        parent::__construct(sprintf(
            '%s cannot compare candidate of type %s with value of type %s; coerce explicitly or use a loose comparison.',
            $ruleName,
            get_debug_type($candidate),
            get_debug_type($value)
        ));
    }
}
