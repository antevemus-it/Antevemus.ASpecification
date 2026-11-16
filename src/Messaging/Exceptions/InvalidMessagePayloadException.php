<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Messaging\Exceptions;

/**
 * InvalidMessagePayloadException - A Handler Refuses the Payload of a Message for Good
 *
 * Thrown by a message handler when the payload is not what it expects (a required field missing,
 * a value of the wrong type): retrying would not change the answer. MessageConsumer rolls the
 * transaction back and rejects the message without requeue at once, whatever its delivery count,
 * so it goes to the dead letter queue of the broker instead of being retried up to maxDeliveries
 * (forward 063, D8: "payload inválido → reject(false)"). Any other exception of a handler is
 * retried.
 *
 * Its message is not copied to the reason handed to the source, nor to the report (the handler
 * may have quoted the payload): the reason names the message and this class (RN-05).
 *
 * @version    2.14.0
 * @package    Antevemus\ASpecification
 * @subpackage Messaging\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class InvalidMessagePayloadException extends MessagingException
{
}
