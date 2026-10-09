<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\Engine\IDocumentPresenceEvaluator;
use Antevemus\ASpecification\Contracts\Engine\IDocumentRuleDefinition;
use Antevemus\ASpecification\Engine\Exceptions\InvalidConditionalExpressionException;
use Antevemus\ASpecification\Results\FailureSeverity;
use Antevemus\ASpecification\Results\SpecificationResult;

/**
 * DocumentLeafSpecification - Presence of One Required Document, with Optional Guard
 *
 * Leaf of the document requirement matrix. Distinguishes three states of a document for a
 * candidate: NOT APPLICABLE (the guard `condicional_expressao` does not hold, the document is
 * not required), PRESENT and MISSING. Set specifications (ANY / ONE_OF_SET) only count applicable
 * documents; an ALL leaf is satisfied when the document is not applicable or present.
 *
 * Supported guard grammar: `<property>=<value>`, `<property><><value>`, `<property>!=<value>`.
 * Anything else is rejected at construction time (InvalidConditionalExpressionException): a guard
 * that cannot be understood must never silently require or waive a document.
 *
 * @version    1.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class DocumentLeafSpecification extends AbstractSpecification
{
    private const GUARD_PATTERN = '/^([a-zA-Z0-9_\-]+)\s*(<>|!=|=)\s*([a-zA-Z0-9_\-]+)$/';

    /** @var array{0: string, 1: string, 2: string}|null Parsed guard: property, operator, expected value */
    private readonly ?array $guard;

    /**
     * @param IDocumentRuleDefinition $rule Document requirement definition
     * @param IDocumentPresenceEvaluator $evaluator Strategy that checks document presence on the candidate
     * @param array<string, mixed> $context Additional contextual metadata forwarded to the evaluator
     * @throws InvalidConditionalExpressionException When the guard expression is not supported
     */
    public function __construct(
        private readonly IDocumentRuleDefinition $rule,
        private readonly IDocumentPresenceEvaluator $evaluator,
        private readonly array $context = []
    ) {
        $this->guard = self::parseGuard($rule);
    }

    /**
     * Parses the conditional expression of a rule, failing loudly on unsupported grammar.
     *
     * @param IDocumentRuleDefinition $rule
     * @return array{0: string, 1: string, 2: string}|null
     * @throws InvalidConditionalExpressionException
     */
    public static function parseGuard(IDocumentRuleDefinition $rule): ?array
    {
        $expr = $rule->getCondicionalExpressao();
        if ($expr === null || trim($expr) === '') {
            return null;
        }

        if (!preg_match(self::GUARD_PATTERN, trim($expr), $m)) {
            throw new InvalidConditionalExpressionException(
                $expr,
                $rule->getCodigoTipoDocumento(),
                $rule->getGrupoCodigo()
            );
        }

        return [$m[1], $m[2], $m[3]];
    }

    /** @return IDocumentRuleDefinition */
    public function getRule(): IDocumentRuleDefinition
    {
        return $this->rule;
    }

    /** @return string Technical document type code */
    public function getDocumentType(): string
    {
        return $this->rule->getCodigoTipoDocumento();
    }

    /** @return string Failure code emitted when the document is missing (DOC_<TYPE>) */
    public function getFailureCode(): string
    {
        return 'DOC_' . strtoupper($this->rule->getCodigoTipoDocumento());
    }

    /**
     * Whether the document is required for this candidate (guard holds or there is no guard).
     *
     * @param mixed $candidate
     * @return bool
     */
    public function appliesTo(mixed $candidate): bool
    {
        if ($this->guard === null) {
            return true;
        }

        [$prop, $op, $expected] = $this->guard;

        $actual = null;
        if (is_object($candidate)) {
            $actual = $candidate->{$prop}
                ?? (method_exists($candidate, 'get' . ucfirst($prop)) ? $candidate->{'get' . ucfirst($prop)}() : null);
        } elseif (is_array($candidate)) {
            $actual = $candidate[$prop] ?? null;
        }

        $actualStr = (string) ($actual ?? '');

        return $op === '='
            ? $actualStr === $expected
            : $actualStr !== $expected;
    }

    /**
     * Whether the document is attached to the candidate (regardless of the guard).
     *
     * @param mixed $candidate
     * @return bool
     */
    public function isPresentIn(mixed $candidate): bool
    {
        if (!is_object($candidate) && !is_array($candidate)) {
            return false;
        }

        return $this->evaluator->hasDocument($candidate, $this->rule->getCodigoTipoDocumento(), $this->context);
    }

    /** {@inheritdoc} */
    public function getType(): string
    {
        return 'mixed';
    }

    /** {@inheritdoc} */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        return !$this->appliesTo($candidate) || $this->isPresentIn($candidate);
    }

    /** {@inheritdoc} */
    public function evaluate(mixed $candidate): SpecificationResult
    {
        try {
            if ($this->isSatisfiedBy($candidate)) {
                return SpecificationResult::satisfied();
            }
        } catch (\Throwable $e) {
            $error = SpecificationResult::error($e, 'DocumentRule:' . $this->rule->getGrupoCodigo(), 'documentos.' . $this->getDocumentType(), $this->getFailureCode());
            // A document requirement always blocks: its failures, errors included, are ERROR (1.5.0).
            return new SpecificationResult(false, [$error->failures[0]->withSeverity(FailureSeverity::ERROR)], true, $e);
        }

        return SpecificationResult::failure(
            message: sprintf('Required document missing: %s.', $this->getDocumentType()),
            code: $this->getFailureCode(),
            ruleName: 'DocumentRule:' . $this->rule->getGrupoCodigo(),
            property: 'documentos.' . $this->getDocumentType(),
            metadata: [
                'acao' => 'bloquear',
                'tipo_documento' => $this->getDocumentType(),
                'grupo' => $this->rule->getGrupoCodigo(),
                'regra' => $this->rule->getRegraObrigatoriedade()->value,
            ],
            severity: FailureSeverity::ERROR
        );
    }
}
