<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine\Exceptions;

use InvalidArgumentException;

/**
 * InvalidRuleChangeReasonException - The Reason of a Rule Change Is Too Short or Too Long
 *
 * Every change of a rule carries a reason (2.13.0, forward 060, D7), under the rules of the feature flags:
 * whitespace runs collapsed to one space, the ends trimmed, then a length in characters between the minimum and
 * the maximum of the RuleCatalogEditor (10 and 1000 by default). The message never repeats the reason (RN-05).
 *
 * @version    2.13.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class InvalidRuleChangeReasonException extends InvalidArgumentException
{
}
