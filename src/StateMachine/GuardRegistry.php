<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine;

use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Specifications\AndSpecification;
use Antevemus\ASpecification\Specifications\NotSpecification;
use Antevemus\ASpecification\Specifications\OrSpecification;
use Antevemus\ASpecification\StateMachine\Exceptions\StateMachineException;
use Antevemus\ASpecification\StateMachine\Exceptions\UnknownGuardException;
use Closure;
use InvalidArgumentException;

/**
 * GuardRegistry - Named Guard Factories of the State Machine
 *
 * Maps a guard name to a factory `(array $params): ISpecification`, in the same spirit as the
 * RuleSpecificationRegistry of the dynamic rule engine (forward 023, D4). Every guard is an
 * ISpecification evaluated against a TransitionCandidate, so a denied transition explains itself
 * with the specification's own message and code, and the same guard can be compiled to SQL.
 * Composite approval rules ("role ∧ amount range ∧ previous level approved ∧ creator does not
 * approve") are simply composite specifications registered under one name (D8).
 *
 * Built-in guards (registered unless disabled):
 * - `inState`     params `{"state": "region.state"}`  → InStateSpecification
 * - `payloadHas`  params `{"field": "motive"}`         → PayloadHasSpecification
 * - `eventOrigin` params `{"origin": "internal"}`      → EventOriginSpecification
 *
 * Features:
 * - Fluent registration of closures, overriding by name
 * - Guard expression compilation (and/or/not, because/withCode annotations)
 * - Missing guard → UnknownGuardException (the validator reports it at load time)
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class GuardRegistry
{
    /** Built-in guard: the snapshot before the event has `region` in `state`. */
    public const IN_STATE = 'inState';

    /** Built-in guard: the event payload provides a non-blank value at `field`. */
    public const PAYLOAD_HAS = 'payloadHas';

    /** Built-in guard: the event has the given `origin`. */
    public const EVENT_ORIGIN = 'eventOrigin';

    /** @var array<string, Closure(array<mixed>): ISpecification> */
    private array $factories = [];

    /**
     * @param bool $withBuiltIns Whether to register the built-in guards
     */
    public function __construct(bool $withBuiltIns = true)
    {
        if ($withBuiltIns) {
            $this->register(self::IN_STATE, static function (array $params): ISpecification {
                $state = $params['state'] ?? null;
                if (!is_string($state)) {
                    throw new InvalidArgumentException('inState expects the parameter "state" as "region.state".');
                }
                return InStateSpecification::fromPath($state);
            });
            $this->register(self::PAYLOAD_HAS, static function (array $params): ISpecification {
                $field = $params['field'] ?? null;
                if (!is_string($field)) {
                    throw new InvalidArgumentException('payloadHas expects the parameter "field" as a string.');
                }
                return new PayloadHasSpecification($field);
            });
            $this->register(self::EVENT_ORIGIN, static function (array $params): ISpecification {
                $origin = is_string($params['origin'] ?? null) ? EventOrigin::tryFrom($params['origin']) : null;
                if ($origin === null) {
                    throw new InvalidArgumentException('eventOrigin expects the parameter "origin" as "internal" or "external".');
                }
                return new EventOriginSpecification($origin);
            });
        }
    }

    /**
     * Registers (or replaces) a guard factory.
     *
     * @param string $name Guard name
     * @param Closure(array<mixed>): ISpecification $factory Factory receiving the declared parameters
     * @return self
     */
    public function register(string $name, Closure $factory): self
    {
        $this->factories[DefinitionSupport::assertName($name, 'guard name')] = $factory;
        return $this;
    }

    /**
     * True when a factory is registered under the name.
     */
    public function has(string $name): bool
    {
        return isset($this->factories[$name]);
    }

    /**
     * Registered guard names, in registration order.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->factories);
    }

    /**
     * Builds the specification of a named guard.
     *
     * @param string $name Guard name
     * @param array<mixed> $params Declared parameters
     * @return ISpecification
     * @throws UnknownGuardException When no factory is registered under the name
     * @throws StateMachineException When the factory does not return an ISpecification
     */
    public function build(string $name, array $params = []): ISpecification
    {
        if (!isset($this->factories[$name])) {
            throw new UnknownGuardException($name);
        }
        $specification = ($this->factories[$name])($params);
        if (!$specification instanceof ISpecification) {
            throw new StateMachineException(sprintf(
                'The factory of guard "%s" must return an ISpecification (got %s).',
                $name,
                get_debug_type($specification)
            ));
        }

        return $specification;
    }

    /**
     * Compiles a guard expression into one specification: named guards through their factories,
     * `and` into AndSpecification, `or` into OrSpecification, `not` into NotSpecification, and the
     * node annotations through because()/withCode().
     *
     * @param GuardExpression $expression Guard expression
     * @return ISpecification
     * @throws UnknownGuardException When a named guard has no factory
     */
    public function compile(GuardExpression $expression): ISpecification
    {
        $specification = match (true) {
            $expression instanceof GuardRef => $this->build($expression->name, $expression->params),
            $expression instanceof GuardAllOf => $this->fold($expression->operands, static fn(ISpecification $l, ISpecification $r): ISpecification => new AndSpecification($l, $r)),
            $expression instanceof GuardAnyOf => $this->fold($expression->operands, static fn(ISpecification $l, ISpecification $r): ISpecification => new OrSpecification($l, $r)),
            $expression instanceof GuardNot => new NotSpecification($this->compile($expression->operand)),
            default => throw new StateMachineException('Unsupported guard expression node: ' . get_class($expression)),
        };

        if ($expression->getMessage() !== null) {
            $specification = $specification->because($expression->getMessage());
        }
        if ($expression->getCode() !== null) {
            $specification = $specification->withCode($expression->getCode());
        }

        return $specification;
    }

    /**
     * @param list<GuardExpression> $operands
     * @param Closure(ISpecification, ISpecification): ISpecification $combine
     */
    private function fold(array $operands, Closure $combine): ISpecification
    {
        $result = $this->compile($operands[0]);
        for ($i = 1, $n = count($operands); $i < $n; $i++) {
            $result = $combine($result, $this->compile($operands[$i]));
        }

        return $result;
    }
}
