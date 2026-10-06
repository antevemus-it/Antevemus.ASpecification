<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Criteria\Exceptions;

use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * NonTranslatableCriteriaException - Exception for Specifications Incompatible with TCriteria
 *
 * Thrown when a domain specification cannot be translated into an Adianti Framework
 * relational expression or filter (e.g. arbitrary in-memory reflection predicates).
 *
 * Features:
 * - Tracking of the incompatible specification instance
 * - Explanatory error message including class name and rejection rationale
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Criteria\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class NonTranslatableCriteriaException extends CriteriaBuilderException
{
    /**
     * @param ISpecification $specification The non-translatable specification instance
     * @param string|null $reason Optional reason explaining translation incompatibility
     */
    public function __construct(
        private readonly ISpecification $specification,
        ?string $reason = null
    ) {
        $className = get_class($specification);
        $message = "The specification [{$className}] cannot be converted into a relational TCriteria/TFilter.";
        if ($reason !== null) {
            $message .= " Reason: {$reason}";
        }
        parent::__construct($message);
    }

    /**
     * Return the specification that caused the translation error.
     *
     * @return ISpecification
     */
    public function getSpecification(): ISpecification
    {
        return $this->specification;
    }
}
