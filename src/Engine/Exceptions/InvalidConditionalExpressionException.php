<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine\Exceptions;

/**
 * InvalidConditionalExpressionException - Unparseable Document Guard Expression
 *
 * Raised at compilation time by the DocumentGroupSpecificationBuilder when a
 * `condicional_expressao` does not match the supported grammar (`prop=value`,
 * `prop<>value`, `prop!=value`). Failing loudly here prevents a document from being
 * silently required (or silently waived) because its guard could not be understood.
 *
 * @version    1.2.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class InvalidConditionalExpressionException extends RuleEngineException
{
    /**
     * @param string $expression The rejected conditional expression
     * @param string $documentType Document type code the expression belongs to
     * @param string $groupCode Document group code the rule belongs to
     */
    public function __construct(
        public readonly string $expression,
        public readonly string $documentType,
        public readonly string $groupCode
    ) {
        parent::__construct(sprintf(
            'Conditional expression "%s" on document "%s" (group "%s") is not supported. ' .
            'Expected "<property>=<value>", "<property><><value>" or "<property>!=<value>".',
            $expression,
            $documentType,
            $groupCode
        ));
    }
}
