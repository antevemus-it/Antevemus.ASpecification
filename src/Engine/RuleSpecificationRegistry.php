<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

use Closure;
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
 *
 * @version    2.4.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class RuleSpecificationRegistry implements IRuleSpecificationRegistry
{
    /** @var array<string, IRuleSpecificationHandler> */
    private array $handlers = [];

    /** @var array<string, Closure(IRuleDefinition): ISpecification> */
    private array $closures = [];

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

    /** {@inheritdoc} */
    public function registerClosure(string $ruleType, Closure $factory): self
    {
        $this->closures[trim($ruleType)] = $factory;
        return $this;
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
     * @throws RuleEngineException When the `spec_json` of the rule is not a specification the codec reads
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
}
