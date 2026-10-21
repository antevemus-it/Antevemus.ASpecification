<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Runner;

use Closure;
use InvalidArgumentException;
use ReflectionFunction;

/**
 * EffectHandlerRegistry - Action Name → Effect Handler
 *
 * Effects are named actions (forward 023, D11); this registry says what runs for each name. A
 * handler receives the effect, its idempotency key (stable across retries) and the instance id:
 * `function (Effect $effect, string $idempotencyKey, string $instanceId): void`.
 *
 * Two kinds of registration (D12):
 * - register(): asynchronous, the default. The runner writes the effect to the outbox in the
 *   transaction of the step and the EffectDispatcher delivers it after the commit; a handler that
 *   throws never undoes the transition (the entry is marked failed and retried).
 * - registerSync(): synchronous, the rare effect that must be able to veto. The runner runs it
 *   inside the transaction, after the snapshot, history and outbox writes and before the commit;
 *   an exception rolls everything back (SyncEffectFailedException). The effect is still written
 *   to the outbox, already delivered, so the trail of effects stays complete.
 *
 * "Sync" is declared here, by the application that implements the action, not in the machine
 * definition: the definition stays the same data whatever the effect does, and the veto power is
 * visible where the code that vetoes lives.
 *
 * Since 2.10.0 a handler may declare a fourth parameter, `EffectContext $context`: it then also
 * receives the machine and instance versions and the event of the step (payload, actor, instant). The
 * signature is read once, when the handler is registered (a fourth parameter, or a variadic one,
 * means the context is wanted); a three-parameter handler is called exactly as before. The runner
 * and the EffectDispatcher call every handler through invoke().
 *
 * @version    2.10.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Runner
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class EffectHandlerRegistry
{
    /** @var array<string, array{handler: Closure, sync: bool, context: bool}> */
    private array $handlers = [];

    /**
     * Registers an asynchronous handler (delivered after the commit through the outbox).
     *
     * @param string $action Action name
     * @param callable $handler function (Effect $effect, string $idempotencyKey, string $instanceId[, EffectContext $context]): void
     * @return self
     */
    public function register(string $action, callable $handler): self
    {
        return $this->add($action, $handler, false);
    }

    /**
     * Registers a synchronous handler (run inside the transaction; an exception vetoes the step).
     *
     * @param string $action Action name
     * @param callable $handler function (Effect $effect, string $idempotencyKey, string $instanceId[, EffectContext $context]): void
     * @return self
     */
    public function registerSync(string $action, callable $handler): self
    {
        return $this->add($action, $handler, true);
    }

    /** True when the action has a handler. */
    public function has(string $action): bool
    {
        return isset($this->handlers[$action]);
    }

    /** True when the action's handler is synchronous. */
    public function isSync(string $action): bool
    {
        return $this->handlers[$action]['sync'] ?? false;
    }

    /** The handler of the action, or null. */
    public function get(string $action): ?Closure
    {
        return $this->handlers[$action]['handler'] ?? null;
    }

    /**
     * True when the action's handler declares the fourth parameter, EffectContext (2.10.0).
     */
    public function takesContext(string $action): bool
    {
        return $this->handlers[$action]['context'] ?? false;
    }

    /**
     * Runs the handler of the context's effect (2.10.0): `(effect, key, instanceId, context)` when it
     * declares the fourth parameter, `(effect, key, instanceId)` otherwise. Whatever the handler throws
     * propagates.
     *
     * @throws InvalidArgumentException When the action has no handler
     */
    public function invoke(EffectContext $context): void
    {
        $action = $context->effect->action;
        $entry = $this->handlers[$action]
            ?? throw new InvalidArgumentException(sprintf('No handler registered for action "%s".', $action));
        if ($entry['context']) {
            ($entry['handler'])($context->effect, $context->idempotencyKey, $context->instanceId, $context);
            return;
        }
        ($entry['handler'])($context->effect, $context->idempotencyKey, $context->instanceId);
    }

    /**
     * Registered action names, in registration order (usable as the validator's known actions).
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_map('strval', array_keys($this->handlers));
    }

    private function add(string $action, callable $handler, bool $sync): self
    {
        if ($action === '' || trim($action) !== $action) {
            throw new InvalidArgumentException('The action name must be a non-empty string without surrounding whitespace.');
        }
        $closure = Closure::fromCallable($handler);
        $signature = new ReflectionFunction($closure);
        $this->handlers[$action] = [
            'handler' => $closure,
            'sync' => $sync,
            'context' => $signature->isVariadic() || $signature->getNumberOfParameters() >= 4,
        ];

        return $this;
    }
}
