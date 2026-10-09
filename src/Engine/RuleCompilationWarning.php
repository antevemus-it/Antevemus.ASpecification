<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

use Antevemus\ASpecification\Contracts\Engine\IRuleDefinition;
use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * RuleCompilationWarning - Catalog validation notice about a degenerate compiled rule.
 *
 * Produced by DynamicSpecificationEngine when catalog validation is enabled (1.6.0, forward 019
 * RN-11): one warning per compiled rule whose specification is structurally a contradiction (the
 * rule can never be satisfied, so it always fails) or a tautology (the rule can never fail). The
 * warning is only reported, never blocking: compilation and evaluation proceed unchanged, and
 * neither SpecificationResult nor RuleEngineVerdict carries it.
 *
 * Detection is the structural, conservative one of ISpecification::isContradiction() and
 * isTautology(): a rule without a warning is "not proven degenerate", never "proven sound".
 *
 * @version    1.6.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class RuleCompilationWarning
{
    /** The compiled rule is a contradiction: no candidate satisfies it, so it always fails. */
    public const KIND_CONTRADICTION = 'contradiction';

    /** The compiled rule is a tautology: every candidate satisfies it, so it never fails. */
    public const KIND_TAUTOLOGY = 'tautology';

    /**
     * @param string $kind One of the KIND_* constants
     * @param string $ruleCode Catalog code of the rule (IRuleDefinition::getCodigo())
     * @param string $ruleType Rule type that selected the handler (IRuleDefinition::getTipoRegra())
     * @param string $message Human-readable explanation
     * @param ISpecification $specification Specification compiled by the rule handler
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $ruleCode,
        public readonly string $ruleType,
        public readonly string $message,
        public readonly ISpecification $specification
    ) {
    }

    /**
     * Inspects a compiled rule and returns the warning it deserves, or null when the rule is not
     * proven degenerate. A contradiction takes precedence (a specification cannot honestly be both).
     *
     * @param ISpecification $specification Specification compiled by the rule handler
     * @param IRuleDefinition $rule Catalog rule
     * @return self|null
     */
    public static function inspect(ISpecification $specification, IRuleDefinition $rule): ?self
    {
        if ($specification->isContradiction()) {
            return new self(
                self::KIND_CONTRADICTION,
                $rule->getCodigo(),
                $rule->getTipoRegra(),
                sprintf(
                    "Rule '%s' (%s) compiles to a contradiction: no candidate can satisfy it, so it always fails.",
                    $rule->getCodigo(),
                    $rule->getTipoRegra()
                ),
                $specification
            );
        }
        if ($specification->isTautology()) {
            return new self(
                self::KIND_TAUTOLOGY,
                $rule->getCodigo(),
                $rule->getTipoRegra(),
                sprintf(
                    "Rule '%s' (%s) compiles to a tautology: every candidate satisfies it, so it never fails.",
                    $rule->getCodigo(),
                    $rule->getTipoRegra()
                ),
                $specification
            );
        }

        return null;
    }

    /**
     * True when the compiled rule can never be satisfied.
     */
    public function isContradiction(): bool
    {
        return $this->kind === self::KIND_CONTRADICTION;
    }

    /**
     * True when the compiled rule can never fail.
     */
    public function isTautology(): bool
    {
        return $this->kind === self::KIND_TAUTOLOGY;
    }

    /**
     * Returns the human-readable explanation.
     */
    public function __toString(): string
    {
        return $this->message;
    }
}
