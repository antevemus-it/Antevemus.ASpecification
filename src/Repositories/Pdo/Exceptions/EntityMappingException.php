<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Pdo\Exceptions;

use InvalidArgumentException;

/**
 * EntityMappingException - Invalid or Incomplete Entity Mapping
 *
 * Thrown while an EntityMapping is built (an unknown or static property, a table or column name that
 * is not a plain SQL identifier, a type that cannot be inferred, two properties on one column, a
 * second identifier) and when a PdoRepository receives a mapping without a table or an identifier.
 *
 * @version    1.11.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\Pdo\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class EntityMappingException extends InvalidArgumentException
{
}
