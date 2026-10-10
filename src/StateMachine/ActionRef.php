<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine;

use Antevemus\ASpecification\StateMachine\Exceptions\InvalidMachineDefinitionException;

/**
 * ActionRef - Named Action Reference of a Machine Definition
 *
 * An action is referenced by name plus JSON-friendly parameters, never by closure (forward 023,
 * D3, D11). The interpreter never runs it: each referenced action becomes an Effect in the
 * TransitionResult, and whoever applies the result (the MachineRunner) dispatches it.
 *
 * Array form: `{"action": "send_email", "params": {...}}`; the string shorthand `"send_email"` is
 * accepted by fromArray().
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class ActionRef
{
    public readonly string $name;

    /** @var array<mixed> */
    public readonly array $params;

    /**
     * @param string $name Action name (resolved by whoever dispatches the effects)
     * @param array<mixed> $params JSON-friendly parameters
     * @throws InvalidMachineDefinitionException
     */
    public function __construct(string $name, array $params = [])
    {
        $this->name = DefinitionSupport::assertName($name, 'action name');
        $this->params = DefinitionSupport::assertJsonFriendly($params, "action '{$name}'");
    }

    /**
     * Returns the canonical array form.
     *
     * @return array{action: string, params: array<mixed>}
     */
    public function toArray(): array
    {
        return ['action' => $this->name, 'params' => $this->params];
    }

    /**
     * Hydrates an action reference from its array form or from the string shorthand.
     *
     * @param array<string, mixed>|string $data
     * @return self
     * @throws InvalidMachineDefinitionException
     */
    public static function fromArray(array|string $data): self
    {
        if (is_string($data)) {
            return new self($data);
        }
        $name = DefinitionSupport::requireString($data, 'action', 'an action reference');
        $params = $data['params'] ?? [];
        if (!is_array($params)) {
            throw InvalidMachineDefinitionException::single(
                MachineDefinitionError::KIND_MALFORMED,
                sprintf('The params of action "%s" must be an array.', $name)
            );
        }

        return new self($name, $params);
    }
}
