<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Engine;

use Closure;
use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * IRuleSpecificationRegistry - Central Registry for Business Rule Handlers
 *
 * Manages the lifecycle and resolution of specification factories for each
 * rule type supported by the host application.
 *
 * Features:
 * - Registration of specialized IRuleSpecificationHandler instances
 * - Dynamic registration of Closure-based specification factories
 * - Seamless resolution and compilation of target specifications
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IRuleSpecificationRegistry
{
    /**
     * Registers a specification handler in the registry.
     *
     * @param IRuleSpecificationHandler $handler
     * @return self
     */
    public function register(IRuleSpecificationHandler $handler): self;

    /**
     * Registers a concise specification factory via a Closure.
     *
     * @param string $tipoRegra Technical rule type key
     * @param Closure(IRuleDefinition): ISpecification $factory
     * @return self
     */
    public function registerClosure(string $tipoRegra, Closure $factory): self;

    /**
     * Retrieves the handler responsible for processing the specified rule type.
     *
     * @param string $tipoRegra Technical rule type key
     * @return IRuleSpecificationHandler|null
     */
    public function getHandler(string $tipoRegra): ?IRuleSpecificationHandler;

    /**
     * Checks if a handler is registered for the specified rule type.
     *
     * @param string $tipoRegra Technical rule type key
     * @return bool
     */
    public function hasHandler(string $tipoRegra): bool;

    /**
     * Compiles and returns the specification for the provided rule definition.
     *
     * @param IRuleDefinition $rule
     * @return ISpecification
     */
    public function buildSpecification(IRuleDefinition $rule): ISpecification;
}
