<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

use Closure;
use Antevemus\ASpecification\Contracts\Engine\ILookupOnlyRuleTypes;
use Antevemus\ASpecification\Contracts\Engine\IRuleDefinition;
use Antevemus\ASpecification\Contracts\Engine\IRuleSpecificationHandler;
use Antevemus\ASpecification\Contracts\Engine\IRuleSpecificationRegistry;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Engine\Exceptions\MissingRuleHandlerException;
use Antevemus\ASpecification\Engine\Exceptions\RuleEngineException;
use Antevemus\ASpecification\Serialization\Exceptions\SpecificationCodecException;
use Antevemus\ASpecification\Serialization\SpecificationCodec;

/**
 * RuleSpecificationRegistry - Central Registry and Configurator for Business Rule Handlers
 *
 * Manages the catalog of specification compilation strategies, mapping each `rule_type`
 * to its respective specialized factory (dedicated handler classes or concise Closures).
 *
 * Features:
 * - Fluent registration of IRuleSpecificationHandler instances
 * - Direct registration of Closure-based specification factories
 * - Fast handler resolution by technical rule key (rule_type)
 * - Automatic MissingRuleHandlerException when an unhandled rule type is requested
 * - A rule whose body is a serialized specification (`spec_json`, 2.4.0, forward 041, D7) is built by the
 *   SpecificationCodec, before and instead of any handler of its `rule_type`; a document the codec cannot
 *   read is a RuleEngineException naming the rule (the codec's exception as the previous)
 * - Lookup-only rule types (2.5.0, forward 048, D1): registerLookupOnly() declares the types whose rules
 *   only hold a value read by the application (ILookupOnlyRuleTypes); the engine skips them when it
 *   compiles. A type cannot be lookup only and handled at once: a closure for a lookup-only type, or a
 *   lookup-only declaration of a type with a closure, is a RuleEngineException when it is registered; a
 *   class handler whose supports() accepts a lookup-only type is an "ambiguous rule type"
 *   RuleEngineException when a rule of that type is built or compiled
 *
 * @version    2.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class RuleSpecificationRegistry implements IRuleSpecificationRegistry, ILookupOnlyRuleTypes
{
    /** @var array<string, IRuleSpecificationHandler> */
    private array $handlers = [];

    /** @var array<string, Closure(IRuleDefinition): ISpecification> */
    private array $closures = [];

    /** @var array<string, string> Lookup-only rule types (2.5.0), by trimmed type */
    private array $lookupOnly = [];

    private readonly SpecificationCodec $codec;

    /**
     * Instantiates a new specification handlers registry.
     *
     * @param list<IRuleSpecificationHandler> $initialHandlers Optional initial handlers
     * @param SpecificationCodec|null $codec Reads the `spec_json` of a rule (null = a codec with the
     *                                       library's specifications and default limits; 2.4.0)
     */
    public function __construct(array $initialHandlers = [], ?SpecificationCodec $codec = null)
    {
        $this->codec = $codec ?? new SpecificationCodec();
        foreach ($initialHandlers as $handler) {
            $this->register($handler);
        }
    }

    /**
     * The codec that reads the `spec_json` of a rule (2.4.0).
     */
    public function getCodec(): SpecificationCodec
    {
        return $this->codec;
    }

    /** {@inheritdoc} */
    public function register(IRuleSpecificationHandler $handler): self
    {
        $this->handlers[] = $handler;
        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @throws RuleEngineException When the rule type was declared lookup only (2.5.0)
     */
    public function registerClosure(string $ruleType, Closure $factory): self
    {
        $key = trim($ruleType);
        if (isset($this->lookupOnly[$key])) {
            throw new RuleEngineException(sprintf(
                'Rule type "%s" was declared lookup only: it cannot also have a specification handler.',
                $key
            ));
        }
        $this->closures[$key] = $factory;
        return $this;
    }

    /**
     * Declares rule types whose rules only hold a value read by the application and validate nothing
     * (2.5.0, forward 048, D1). DynamicSpecificationEngine skips the rules of these types that have no
     * `spec_json` and reports them through getSkippedRules(); a type that is neither declared here nor
     * handled is still a MissingRuleHandlerException.
     *
     * @param string ...$ruleTypes Technical rule type keys (compared without surrounding blanks)
     * @return static
     * @throws RuleEngineException When a type is empty or already has a closure handler
     */
    public function registerLookupOnly(string ...$ruleTypes): static
    {
        foreach ($ruleTypes as $ruleType) {
            $key = trim($ruleType);
            if ($key === '') {
                throw new RuleEngineException('A lookup-only rule type cannot be empty.');
            }
            if (isset($this->closures[$key])) {
                throw new RuleEngineException(sprintf(
                    'Rule type "%s" has a specification handler: it cannot also be declared lookup only.',
                    $key
                ));
            }
            $this->lookupOnly[$key] = $key;
        }

        return $this;
    }

    /** {@inheritdoc} */
    public function isLookupOnly(string $ruleType): bool
    {
        return isset($this->lookupOnly[trim($ruleType)]);
    }

    /**
     * The rule types declared lookup only, in declaration order (2.5.0).
     *
     * @return list<string>
     */
    public function getLookupOnlyTypes(): array
    {
        return array_values($this->lookupOnly);
    }

    /** {@inheritdoc} */
    public function getHandler(string $ruleType): ?IRuleSpecificationHandler
    {
        $key = trim($ruleType);
        foreach ($this->handlers as $handler) {
            if ($handler->supports($key)) {
                return $handler;
            }
        }
        return null;
    }

    /** {@inheritdoc} */
    public function hasHandler(string $ruleType): bool
    {
        $key = trim($ruleType);
        if (isset($this->closures[$key])) {
            return true;
        }

        return $this->getHandler($key) !== null;
    }

    /**
     * {@inheritdoc}
     *
     * A rule with `spec_json` (2.4.0) is the specification that document describes; otherwise the
     * closure, then the handler, of its `rule_type`.
     *
     * A rule of a lookup-only type (2.5.0) without `spec_json` has no specification: it is a
     * MissingRuleHandlerException here (the engine skips it before asking), and an "ambiguous rule type"
     * RuleEngineException when a class handler accepts that type.
     *
     * @throws RuleEngineException When the `spec_json` of the rule is not a specification the codec reads,
     *                             or when a class handler accepts a lookup-only type
     * @throws MissingRuleHandlerException When no handler builds the rule type
     */
    public function buildSpecification(IRuleDefinition $rule): ISpecification
    {
        $specJson = $rule->getSpecJson();
        if ($specJson !== null) {
            try {
                return $this->codec->fromJson($specJson);
            } catch (SpecificationCodecException $e) {
                throw new RuleEngineException(sprintf('Rule "%s": spec_json is not a specification document the codec reads: %s', $rule->getCode(), $e->getMessage()), 0, $e);
            }
        }

        $ruleType = $rule->getRuleType();
        if ($this->isLookupOnly($ruleType)) {
            // No closure can exist for it (refused when registered); a class handler is ambiguous; otherwise the
            // MissingRuleHandlerException below
            $this->assertNotAmbiguous($ruleType);
        }

        // 1. Priority for Closure-registered handlers
        if (isset($this->closures[$ruleType])) {
            $closure = $this->closures[$ruleType];
            return $closure($rule);
        }

        // 2. Search class-based handlers
        $handler = $this->getHandler($ruleType);
        if ($handler !== null) {
            return $handler->build($rule);
        }

        throw new MissingRuleHandlerException($ruleType, $rule->getCode());
    }

    /**
     * Refuses a lookup-only type that a class handler accepts (2.5.0, forward 048, D1): its supports() is
     * dynamic, so the conflict can only be seen when a rule of the type is built or compiled.
     *
     * @param string $ruleType A type declared lookup only
     * @throws RuleEngineException When a class handler supports the type
     */
    private function assertNotAmbiguous(string $ruleType): void
    {
        $handler = $this->getHandler($ruleType);
        if ($handler !== null) {
            throw new RuleEngineException(sprintf(
                'Ambiguous rule type "%s": it was declared lookup only and the handler %s supports it.',
                trim($ruleType),
                $handler::class
            ));
        }
    }
}
