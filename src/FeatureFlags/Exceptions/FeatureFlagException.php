<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\FeatureFlags\Exceptions;

use RuntimeException;

/**
 * FeatureFlagException - Base of the Feature Flag Exceptions
 *
 * Raised by the write side of FeatureFlags (toggle, addSubject, removeSubject) and by the stores.
 * The read side (isEnabled, evaluate, spec) never throws: a flag that cannot be read is off
 * (forward 034, D7 and D9).
 *
 * @version    2.1.0
 * @package    Antevemus\ASpecification
 * @subpackage FeatureFlags\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class FeatureFlagException extends RuntimeException
{
}
