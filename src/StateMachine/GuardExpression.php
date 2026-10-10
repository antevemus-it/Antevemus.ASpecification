<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine;

use Antevemus\ASpecification\StateMachine\Exceptions\InvalidMachineDefinitionException;

/**
 * GuardExpression - Serializable Guard Expression Tree of a Transition
 *
 * A guard is data: a tree whose leaves are named guards with JSON-friendly parameters (GuardRef)
 * and whose inner nodes are `and`, `or` and `not` (forward 023, D4). The GuardRegistry compiles it
 * into an ISpecification evaluated against a TransitionCandidate. Any node may carry a failure
 * message and code, applied to the compiled specification through because()/withCode(), so the
 * reason of a denial is the author's own wording.
 *
 * Array forms:
 * - `{"guard": "inState", "params": {"state": "pending.open"}}` (string shorthand: `"name"`)
 * - `{"and": [expr, expr, ...]}`, `{"or": [expr, expr, ...]}`, `{"not": expr}`
 * - any node: optional `"code"` and `"message"`
 *
 * Instances are immutable: because() and withCode() return copies.
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class GuardExpression
{
    protected ?string $code = null;
    protected ?string $message = null;

    /**
     * Creates a named guard reference.
     *
     * @param string $name Guard name registered in the GuardRegistry
     * @param array<mixed> $params JSON-friendly parameters passed to the guard factory
     * @return GuardRef
     */
    public static function ref(string $name, array $params = []): GuardRef
    {
        return new GuardRef($name, $params);
    }

    /**
     * Creates a conjunction: every operand must pass.
     *
     * @param GuardExpression|string ...$operands Expressions or guard names
     * @return GuardAllOf
     */
    public static function allOf(GuardExpression|string ...$operands): GuardAllOf
    {
        return new GuardAllOf(array_map(self::coerce(...), array_values($operands)));
    }

    /**
     * Creates a disjunction: at least one operand must pass.
     *
     * @param GuardExpression|string ...$operands Expressions or guard names
     * @return GuardAnyOf
     */
    public static function anyOf(GuardExpression|string ...$operands): GuardAnyOf
    {
        return new GuardAnyOf(array_map(self::coerce(...), array_values($operands)));
    }

    /**
     * Creates a negation.
     *
     * @param GuardExpression|string $operand Expression or guard name
     * @return GuardNot
     */
    public static function not(GuardExpression|string $operand): GuardNot
    {
        return new GuardNot(self::coerce($operand));
    }

    /**
     * Returns a copy whose compiled specification reports this message when it fails.
     *
     * @param string $message Failure message
     * @return static
     */
    public function because(string $message): static
    {
        $clone = clone $this;
        $clone->message = $message;
        return $clone;
    }

    /**
     * Returns a copy whose compiled specification reports this code when it fails.
     *
     * @param string $code Failure code
     * @return static
     */
    public function withCode(string $code): static
    {
        $clone = clone $this;
        $clone->code = $code;
        return $clone;
    }

    /**
     * Failure code of this node, when declared.
     */
    public function getCode(): ?string
    {
        return $this->code;
    }

    /**
     * Failure message of this node, when declared.
     */
    public function getMessage(): ?string
    {
        return $this->message;
    }

    /**
     * Returns every named guard reference of the tree, depth-first, in declaration order.
     *
     * @return list<GuardRef>
     */
    abstract public function refs(): array;

    /**
     * Returns the canonical array form of the node (annotations included when declared).
     *
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;

    /**
     * Hydrates an expression from its array form or from the string shorthand (a guard name).
     *
     * @param array<string, mixed>|string $data
     * @return GuardExpression
     * @throws InvalidMachineDefinitionException
     */
    public static function fromArray(array|string $data): GuardExpression
    {
        if (is_string($data)) {
            return new GuardRef($data);
        }

        $kinds = array_values(array_intersect(['guard', 'and', 'or', 'not'], array_keys($data)));
        if (count($kinds) !== 1) {
            throw InvalidMachineDefinitionException::single(
                MachineDefinitionError::KIND_MALFORMED,
                'A guard expression must have exactly one of the keys "guard", "and", "or", "not".'
            );
        }

        $expression = match ($kinds[0]) {
            'guard' => self::refFromArray($data),
            'and' => new GuardAllOf(self::operandsFromArray($data['and'], 'and')),
            'or' => new GuardAnyOf(self::operandsFromArray($data['or'], 'or')),
            'not' => new GuardNot(self::operandFromArray($data['not'])),
        };

        if (isset($data['code'])) {
            $expression = $expression->withCode(self::annotation($data['code'], 'code'));
        }
        if (isset($data['message'])) {
            $expression = $expression->because(self::annotation($data['message'], 'message'));
        }

        return $expression;
    }

    /**
     * Adds the declared annotations to an array form.
     *
     * @param array<string, mixed> $array Array form without annotations
     * @return array<string, mixed>
     */
    protected function withAnnotations(array $array): array
    {
        if ($this->code !== null) {
            $array['code'] = $this->code;
        }
        if ($this->message !== null) {
            $array['message'] = $this->message;
        }

        return $array;
    }

    private static function coerce(GuardExpression|string $operand): GuardExpression
    {
        return is_string($operand) ? new GuardRef($operand) : $operand;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function refFromArray(array $data): GuardRef
    {
        $name = DefinitionSupport::requireString($data, 'guard', 'a guard reference');
        $params = $data['params'] ?? [];
        if (!is_array($params)) {
            throw InvalidMachineDefinitionException::single(
                MachineDefinitionError::KIND_MALFORMED,
                sprintf('The params of guard "%s" must be an array.', $name)
            );
        }

        return new GuardRef($name, $params);
    }

    /**
     * @return list<GuardExpression>
     */
    private static function operandsFromArray(mixed $operands, string $kind): array
    {
        if (!is_array($operands) || !array_is_list($operands)) {
            throw InvalidMachineDefinitionException::single(
                MachineDefinitionError::KIND_MALFORMED,
                sprintf('The operands of a guard "%s" node must be a list.', $kind)
            );
        }

        return array_map(self::operandFromArray(...), $operands);
    }

    private static function operandFromArray(mixed $operand): GuardExpression
    {
        if (!is_string($operand) && !is_array($operand)) {
            throw InvalidMachineDefinitionException::single(
                MachineDefinitionError::KIND_MALFORMED,
                'A guard operand must be a guard name or a guard expression array.'
            );
        }

        return self::fromArray($operand);
    }

    private static function annotation(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw InvalidMachineDefinitionException::single(
                MachineDefinitionError::KIND_MALFORMED,
                sprintf('The "%s" of a guard expression must be a string.', $field)
            );
        }

        return $value;
    }
}
