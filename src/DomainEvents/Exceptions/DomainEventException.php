<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\DomainEvents\Exceptions;

use RuntimeException;

/**
 * DomainEventException - Base Exception of the Specification-Driven Domain Events
 *
 * Every exception thrown by the Antevemus\ASpecification\DomainEvents namespace extends it
 * (forward 029), so a caller can catch the whole family at once.
 *
 * @version    1.11.0
 * @package    Antevemus\ASpecification
 * @subpackage DomainEvents\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class DomainEventException extends RuntimeException
{
}
