<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine\Exceptions;

/**
 * MissingRuleHandlerException - Exception Thrown on Missing Rule Type Handler
 *
 * Occurs when the catalog requests the compilation of a rule whose `tipo_regra` has
 * no corresponding handler or factory registered in `RuleSpecificationRegistry`.
 *
 * Features:
 * - Explicit identification of the unsupported rule type and rule code
 * - Actionable resolution hint in specification registry
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class MissingRuleHandlerException extends RuleEngineException
{
    /**
     * @param string $tipoRegra Technical validation type key
     * @param string|null $codigo Stable rule code in catalog
     */
    public function __construct(
        public readonly string $tipoRegra,
        public readonly ?string $codigo = null
    ) {
        $msg = sprintf(
            'No specification handler registered for rule type "%s"%s.',
            $tipoRegra,
            $codigo !== null ? " (code: {$codigo})" : ''
        );
        parent::__construct($msg);
    }
}
