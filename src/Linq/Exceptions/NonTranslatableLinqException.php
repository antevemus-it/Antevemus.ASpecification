<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Linq\Exceptions;

use Antevemus\ASpecification\Contracts\ISpecification;
use InvalidArgumentException;

/**
 * NonTranslatableLinqException - Exception for Specifications the ALinq Visitor Refuses to Compile
 *
 * The ALinq visitor compiles every other node to a predicate (a custom leaf delegates to its own
 * isSatisfiedBy()). It refuses a node whose evaluation needs something an item-by-item predicate must
 * not do: since 1.10.0, an external data leaf (ExternalDataSpecification), whose data is fetched by a
 * DataResolver for one candidate at a time; compiled into a filter it would call the service once per
 * item, synchronously. Same constructor shape as the SQL and TCriteria "non translatable" exceptions.
 *
 * Extends InvalidArgumentException, like the other errors of the ALinq visitor.
 *
 * Features:
 * - Exact identification of the refused specification
 * - Explanation of the refusal
 *
 * @version    1.10.0
 * @package    Antevemus\ASpecification
 * @subpackage Linq\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class NonTranslatableLinqException extends InvalidArgumentException
{
    /**
     * @param ISpecification $specification Refused specification instance
     * @param string $reason Why it cannot be compiled
     */
    public function __construct(
        public readonly ISpecification $specification,
        string $reason = ''
    ) {
        parent::__construct(sprintf(
            'Specification of type "%s" cannot be compiled into an ALinq predicate%s.',
            get_class($specification),
            $reason !== '' ? ": {$reason}" : ''
        ));
    }
}
