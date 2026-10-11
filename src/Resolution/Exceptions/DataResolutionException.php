<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Resolution\Exceptions;

use RuntimeException;

/**
 * DataResolutionException - Base Exception for the Concurrent Data Resolution
 *
 * Thrown for configuration errors of the resolution subsystem (an invalid source name, an URL
 * template that cannot be expanded, `ext-curl` missing when an HTTP source is registered) and
 * extended by the fetch failures. A fetch failure never escapes `evaluate()`: it becomes the
 * evaluation error of the external data leaf that needed the data, with the exception kept in
 * `SpecificationResult::$exception` for the log (the 1.8.0 rule).
 *
 * Features:
 * - Typed base exception for the Resolution subsystem
 * - Messages never carry the body of a response nor the value of a parameter
 *
 * @version    1.10.0
 * @package    Antevemus\ASpecification
 * @subpackage Resolution\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class DataResolutionException extends RuntimeException
{
}
