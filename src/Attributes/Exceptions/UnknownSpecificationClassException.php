<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Attributes\Exceptions;

use InvalidArgumentException;

/**
 * UnknownSpecificationClassException - A #[AssertSpec] names a class that cannot act as a specification.
 *
 * A specification class that does not exist (a typo such as 'IsAdultSpecificaton', a class that was
 * renamed or never written) or that does not implement ISpecification is a configuration error of the
 * attribute itself, never a verdict about the candidate. Before this exception existed, such an
 * attribute was skipped in silence: the invariant it declared was switched off without any notice,
 * and the candidate was reported as valid. The validator now refuses the attribute the moment it is
 * resolved, on the first validation of the annotated class.
 *
 * Same family as UnknownRuleOperatorException (InvalidArgumentException): a configuration error is
 * not an AttributeValidationException and is never caught by handlers that expect one.
 *
 * @version    1.4.0
 * @package    Antevemus\ASpecification
 * @subpackage Attributes\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class UnknownSpecificationClassException extends InvalidArgumentException
{
    /**
     * @param string $specificationClass Class name as written on the attribute
     * @param string $target Annotated element, e.g. "property 'age' of App\Dto\Customer",
     *                       "method 'getScore()' of App\Dto\Customer" or "class App\Dto\Customer"
     * @param string $reason Why the class cannot be used ("class does not exist",
     *                       "class does not implement Antevemus\ASpecification\Contracts\ISpecification")
     */
    public function __construct(
        public readonly string $specificationClass,
        public readonly string $target,
        public readonly string $reason
    ) {
        parent::__construct(sprintf(
            "Unknown #[AssertSpec] specification class '%s' on %s: %s.",
            $specificationClass,
            $target,
            $reason
        ));
    }
}
