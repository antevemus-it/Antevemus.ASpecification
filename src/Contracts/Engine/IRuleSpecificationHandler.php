<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Engine;

use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * IRuleSpecificationHandler - Contract for Specialized Rule Specification Factory
 *
 * Defines the pluggable mechanism translating a catalog definition (RuleDefinition)
 * into an executable specification (ISpecification) of the ASpecification ecosystem.
 *
 * Features:
 * - Validation of technical rule type keys support (supports)
 * - Compilation of rule definition into an executable domain specification with metadata
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IRuleSpecificationHandler
{
    /**
     * Verifies whether this handler is capable of compiling the provided rule type.
     *
     * @param string $tipoRegra Technical validation type key
     * @return bool
     */
    public function supports(string $tipoRegra): bool;

    /**
     * Builds the corresponding specification based on the rule definition parameters.
     *
     * @param IRuleDefinition $rule Rule definition loaded from catalog
     * @return ISpecification
     */
    public function build(IRuleDefinition $rule): ISpecification;
}
