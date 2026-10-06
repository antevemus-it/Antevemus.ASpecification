<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine\Exceptions;

use RuntimeException;

/**
 * RuleEngineException - Base Exception for Dynamic Rule Engine Failures
 *
 * Thrown when operational, configuration, or compilation failures occur
 * during the lifecycle of the dynamic specification engine.
 *
 * Features:
 * - Typed base exception for the Engine subsystem
 * - Preserves contextual message and root cause
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class RuleEngineException extends RuntimeException
{
}
