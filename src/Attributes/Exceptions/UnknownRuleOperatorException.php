<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Attributes\Exceptions;

use InvalidArgumentException;

/**
 * UnknownRuleOperatorException - A #[ValidateRule] names an operator outside the catalog.
 *
 * An operator that the validator does not know is a configuration error of the rule itself
 * (a typo such as 'not_blnak' or an operator that was never implemented), never a verdict
 * about the candidate. Before this exception existed, such a rule silently reported a
 * business violation with the attribute's own message, hiding that the rule was never
 * evaluated at all. The library now refuses the rule the moment it is instantiated.
 *
 * The accepted operators are listed in ValidateRule::OPERATORS and in this exception's message.
 *
 * @version    1.3.0
 * @package    Antevemus\ASpecification
 * @subpackage Attributes\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class UnknownRuleOperatorException extends InvalidArgumentException
{
    /**
     * @param string $operator Operator as written on the attribute
     * @param list<string> $accepted Catalog of accepted operators (normalized spellings)
     */
    public function __construct(
        public readonly string $operator,
        public readonly array $accepted
    ) {
        parent::__construct(sprintf(
            "Unknown #[ValidateRule] operator '%s'; accepted operators are: %s.",
            $operator,
            implode(', ', $accepted)
        ));
    }
}
