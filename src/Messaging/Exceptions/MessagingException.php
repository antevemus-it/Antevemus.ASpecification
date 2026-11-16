<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Messaging\Exceptions;

use RuntimeException;

/**
 * MessagingException - Base of the Runtime Failures of the Messaging Hook
 *
 * Thrown by the outbox, the inbox, the relay, the consumer and the in-memory transport and source
 * (forward 063). Its message names the message (its name, its id, its ordering key), never a
 * value of the payload (RN-05).
 *
 * @version    2.14.0
 * @package    Antevemus\ASpecification
 * @subpackage Messaging\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class MessagingException extends RuntimeException
{
}
