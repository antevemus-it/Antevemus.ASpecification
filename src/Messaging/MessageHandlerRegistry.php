<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Messaging;

use Antevemus\ASpecification\Messaging\Exceptions\InvalidMessageException;
use Closure;

/**
 * MessageHandlerRegistry - Handlers of Messages by Name
 *
 * A handler is `function (Message $message): void` (forward 063, D8). A name may have several
 * handlers; MessageConsumer runs them in registration order, in one transaction, and stops at the
 * first that throws (the transaction rolls back and the message is retried whole). The name must be
 * a valid message name (`module.fact.vN`): a handler of a name no message can have is a mistake.
 *
 * @version    2.14.0
 * @package    Antevemus\ASpecification
 * @subpackage Messaging
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class MessageHandlerRegistry
{
    /** @var array<string, list<Closure>> */
    private array $handlers = [];

    /**
     * Adds a handler for a message name.
     *
     * @param string $name Message name (`module.fact.vN`)
     * @param callable $handler function (Message $message): void
     * @throws InvalidMessageException When the name is not a valid message name
     */
    public function register(string $name, callable $handler): self
    {
        if (!Message::isValidName($name)) {
            throw new InvalidMessageException(sprintf('A handler is registered for a message name, module.fact.vN, at most %d characters.', Message::MAX_NAME_LENGTH));
        }
        $this->handlers[$name][] = Closure::fromCallable($handler);

        return $this;
    }

    /** True when the name has at least one handler. */
    public function has(string $name): bool
    {
        return isset($this->handlers[$name]);
    }

    /**
     * Handlers of a name, in registration order (empty when none).
     *
     * @return list<Closure>
     */
    public function handlersFor(string $name): array
    {
        return $this->handlers[$name] ?? [];
    }

    /**
     * Names with handlers, in registration order (what a source subscribes to).
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->handlers);
    }
}
