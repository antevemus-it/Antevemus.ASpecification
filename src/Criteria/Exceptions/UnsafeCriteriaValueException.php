<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Criteria\Exceptions;

/**
 * UnsafeCriteriaValueException - Rejected Filter Value
 *
 * The Adianti TFilter interprets three value shapes as raw SQL, even in prepared mode:
 * values starting with "(SELECT", values containing "{session." and values starting
 * with "NOESC:". A domain specification value in one of those shapes would reach the
 * database unquoted, so the visitor refuses it before building the TFilter
 * (BUG-20261007-KJ36).
 *
 * @version    1.1.2
 * @package    Antevemus\ASpecification
 * @subpackage Criteria\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class UnsafeCriteriaValueException extends CriteriaBuilderException
{
    public function __construct(public readonly string $column, string $reason)
    {
        parent::__construct(sprintf(
            'Unsafe filter value rejected for column "%s": %s. Adianti TFilter would interpret it as raw SQL.',
            $column,
            $reason
        ));
    }
}
