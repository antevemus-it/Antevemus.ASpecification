<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine;

use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Serialization\SpecificationCodec;
use Antevemus\ASpecification\Specifications\AndSpecification;
use Antevemus\ASpecification\Specifications\NotSpecification;
use Antevemus\ASpecification\Specifications\OrSpecification;
use Antevemus\ASpecification\StateMachine\Exceptions\InvalidMachineDefinitionException;
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
 * Opt-in guard (2.7.0, enableSpecGuard()):
 * - `spec`        params `{"document": {"v": 1, "spec": {...}}}` → the specification of a
 *   SpecificationCodec document, evaluated over the CONTEXT of the candidate (what the application
 *   passes to send()/can()), so a definition edited by a screen can carry a condition such as
 *   `amount < 10000` without a guard written in code. The document is rooted at the context when the
 *   guard is built (a top-level property `amount` reads `context.amount`, also inside and/or/not;
 *   any other node reads the context itself), so an array context works in a transition guard and in
 *   a deadline pause alike, and a failure names the property as `context.amount`. It is off by default: enabling it lets whoever
 *   edits the definition write any condition the codec reads over the context (the codec's limits of
 *   depth and size apply, and it reads no callback, table or service).
 *
 * Role guards (2.10.0, markRoleGuards()): the names of the guards that check WHO acts (a role, a
 * profile, an approval level). Marking changes no evaluation; it lets nextEvents() classify an
 * event refused by them as CandidateReason::ROLE_REFUSED ("not yours to send") instead of
 * GUARD_REFUSED, and lets a rejection with several candidates for the event choose as its primary
 * reason a candidate the caller's role does not refuse.
 *
 * Features:
 * - Fluent registration of closures, overriding by name
 * - Guard expression compilation (and/or/not, because/withCode annotations)
 * - Missing guard → UnknownGuardException (the validator reports it at load time)
 *
 * @version    2.10.0
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

    /** Opt-in guard (2.7.0): a specification serialized by SpecificationCodec, evaluated over the context. */
    public const SPEC = 'spec';

    /** @var array<string, Closure(array<mixed>): ISpecification> */
    private array $factories = [];

    /** @var list<string> Names marked as role guards (2.10.0), in marking order */
    private array $roleGuards = [];

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
     * Registers the `spec` guard (2.7.0): its parameter `document` is a document of the codec
     * (`SpecificationCodec::encode()`), decoded when the guard is built and evaluated over the context
     * of the candidate. A document the codec refuses fails the build, so the validator reports it
     * (`guard_build_failed`) before the definition runs or is published.
     *
     * @param SpecificationCodec|null $codec Codec reading the documents (null = a default codec: core
     *                                       types, default limits); register your own types on it
     * @return self
     */
    public function enableSpecGuard(?SpecificationCodec $codec = null): self
    {
        $codec ??= new SpecificationCodec();

        return $this->register(self::SPEC, static function (array $params) use ($codec): ISpecification {
            $document = $params['document'] ?? null;
            if (!is_array($document)) {
                throw new InvalidArgumentException('spec expects the parameter "document" as a specification document {"v": 1, "spec": {...}}.');
            }

            if (array_key_exists('spec', $document)) {
                $document['spec'] = self::rootedAtContext($document['spec']);
            }

            return $codec->decode($document);
        });
    }

    /**
     * Roots a node of a codec document at the context of the candidate: a property path `p` becomes
     * `context.p` (so an array context is read by path, under isSatisfiedBy() too: pause conditions),
     * and/or/not keep their shape with their operands rooted, any other node is wrapped in a property
     * `context` (it then reads the context itself). A malformed node is left as is for the codec to
     * refuse.
     */
    private static function rootedAtContext(mixed $node): mixed
    {
        if (!is_array($node)) {
            return $node;
        }
        $type = $node['t'] ?? null;
        if (($type === 'and' || $type === 'or') && is_array($node['s'] ?? null) && array_is_list($node['s'])) {
            $node['s'] = array_map(self::rootedAtContext(...), $node['s']);
            return $node;
        }
        if ($type === 'not' && is_array($node['s'] ?? null)) {
            $node['s'] = self::rootedAtContext($node['s']);
            return $node;
        }
        if ($type === 'property' && is_string($node['p'] ?? null) && !array_key_exists('base', $node)) {
            $node['p'] = 'context.' . $node['p'];
            return $node;
        }

        return ['t' => 'property', 'p' => 'context', 's' => $node];
    }

    /**
     * A reference to the `spec` guard carrying the document of a specification (2.7.0), for the
     * builder (`->guard(GuardRegistry::specRef(Spec::property('amount', Spec::lessThan(10000))))`).
     *
     * @param ISpecification $specification Condition over the context
     * @param SpecificationCodec|null $codec Codec writing the document (null = a default codec)
     * @return GuardRef
     */
    public static function specRef(ISpecification $specification, ?SpecificationCodec $codec = null): GuardRef
    {
        return GuardExpression::ref(self::SPEC, ['document' => ($codec ?? new SpecificationCodec())->encode($specification)]);
    }

    /**
     * Marks guard names as role guards (2.10.0): guards that check who acts (role, profile, approval
     * level). The names need not be registered yet; marking changes no evaluation (see the class
     * description).
     *
     * @param string ...$names Guard names
     * @return self
     * @throws InvalidMachineDefinitionException When a name is not a valid guard name
     */
    public function markRoleGuards(string ...$names): self
    {
        foreach ($names as $name) {
            if (!in_array(DefinitionSupport::assertName($name, 'guard name'), $this->roleGuards, true)) {
                $this->roleGuards[] = $name;
            }
        }

        return $this;
    }

    /** True when the guard name was marked as a role guard (2.10.0). */
    public function isRoleGuard(string $name): bool
    {
        return in_array($name, $this->roleGuards, true);
    }

    /**
     * The names marked as role guards (2.10.0), in marking order.
     *
     * @return list<string>
     */
    public function roleGuards(): array
    {
        return $this->roleGuards;
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
