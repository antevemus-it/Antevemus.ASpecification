<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Messaging\Exceptions;

use InvalidArgumentException;

/**
 * InvalidMessageException - A Message That Breaks the Rules of Message
 *
 * Thrown when a Message is built (a name that is not `module.fact.vN`, a payload that is not
 * JSON-safe or is larger than 65 535 bytes, an ordering key, a tenant or a header out of bounds)
 * and when one is read back (`Message::fromArray()`, `fromJson()`: a document of another format
 * version, a missing or unknown key). It extends InvalidArgumentException, the exception of every
 * value object of the library (forward 063, D2).
 *
 * The message states the rule that was broken and, at most, a length or a count: it never repeats
 * a value of the payload, of a header nor a name, an ordering key or a tenant that was refused
 * (RN-05). A source that cannot read what the broker delivered hands it to the consumer as
 * `ReceivedMessage::invalid()` with this message as the reason.
 *
 * @version    2.14.0
 * @package    Antevemus\ASpecification
 * @subpackage Messaging\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class InvalidMessageException extends InvalidArgumentException
{
}
