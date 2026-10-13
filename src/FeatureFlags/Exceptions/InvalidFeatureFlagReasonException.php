<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\FeatureFlags\Exceptions;

use InvalidArgumentException;

/**
 * InvalidFeatureFlagReasonException - The Reason of a Change Is Too Short or Too Long
 *
 * Every change carries a reason (forward 034, D5): whitespace runs are collapsed to one space and
 * the ends trimmed, then its length in characters must lie between the minimum and the maximum of
 * the FeatureFlags service (10 and 1000 by default). The message is safe to show to the user.
 *
 * @version    2.1.0
 * @package    Antevemus\ASpecification
 * @subpackage FeatureFlags\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class InvalidFeatureFlagReasonException extends InvalidArgumentException
{
}
