<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine\Exceptions;

use Throwable;

/**
 * RuleCatalogEditorException - Base Exception of the Audited Write Path of the Rule Catalog
 *
 * Thrown by RuleCatalogEditor and its stores (2.13.0, forward 060). The message cites the rule code, the column
 * and the version, never a column value, the reason of the change or the value received (RN-05): it is safe to
 * show and to log. getRuleCode() is the code of the rule concerned (null when the failure concerns no rule).
 *
 * @version    2.13.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class RuleCatalogEditorException extends RuleEngineException
{
    /**
     * @param string $message Human-readable message (no column value, reason nor received value)
     * @param string|null $ruleCode Code of the rule concerned
     * @param Throwable|null $previous Cause
     */
    public function __construct(string $message, private readonly ?string $ruleCode = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /** Code of the rule concerned, or null. */
    public function getRuleCode(): ?string
    {
        return $this->ruleCode;
    }
}
