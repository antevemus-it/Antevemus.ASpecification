<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Criteria\Exceptions;

use RuntimeException;

/**
 * CriteriaBuilderException - Base Exception for TCriteria Construction Errors
 *
 * Thrown when failures occur during the mapping or compilation of specifications
 * into database criteria objects of the Adianti Framework.
 *
 * Features:
 * - Unified identification of Criteria module failures
 * - Inherits from standard PHP RuntimeException
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Criteria\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class CriteriaBuilderException extends RuntimeException
{
}
