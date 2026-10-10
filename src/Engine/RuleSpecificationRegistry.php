<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

use Closure;
use Antevemus\ASpecification\Contracts\Engine\IRuleDefinition;
use Antevemus\ASpecification\Contracts\Engine\IRuleSpecificationHandler;
use Antevemus\ASpecification\Contracts\Engine\IRuleSpecificationRegistry;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Engine\Exceptions\MissingRuleHandlerException;

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
 *
 * @version    1.7.0
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

    /**
     * Instantiates a new specification handlers registry.
     *
     * @param list<IRuleSpecificationHandler> $initialHandlers Optional initial handlers
     */
    public function __construct(array $initialHandlers = [])
    {
        foreach ($initialHandlers as $handler) {
            $this->register($handler);
        }
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

    /** {@inheritdoc} */
    public function buildSpecification(IRuleDefinition $rule): ISpecification
    {
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
