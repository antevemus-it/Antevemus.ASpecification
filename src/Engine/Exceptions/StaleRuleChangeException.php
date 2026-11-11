<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine\Exceptions;

/**
 * StaleRuleChangeException - The Rule Was Not in the Expected State
 *
 * A change of a rule is a compare-and-set of its version (2.13.0, forward 060, D3): the version read inside the
 * transaction, or the one the caller's screen read (`$expectedVersion`). When another change committed in between
 * (RN-04), when the change would leave the row as it is (D2), when the rule is already in the requested state, or
 * when a rule to create already exists, nothing is written, no history row is added and this exception is thrown:
 * reload and decide again.
 *
 * @version    2.13.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class StaleRuleChangeException extends RuleCatalogEditorException
{
}
