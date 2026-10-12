<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

use Antevemus\ADeepPropertyAccess\PropertyAccess;
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
 * candidate: NOT APPLICABLE (the guard `condition_expression` does not hold, the document is
 * not required), PRESENT and MISSING. Set specifications (ANY / ONE_OF_SET) only count applicable
 * documents; an ALL leaf is satisfied when the document is not applicable or present.
 *
 * Supported guard grammar: `<property>=<value>`, `<property><><value>`, `<property>!=<value>`.
 * Anything else is rejected at construction time (InvalidConditionalExpressionException): a guard
 * that cannot be understood must never silently require or waive a document. The property is read
 * through the shared property accessor (`PropertyAccess`, 2.0.0), as every property specification:
 * array key, public getter (`getX()`, `x()`, `isX()`, `hasX()`), public property or `__get` guarded by
 * `__isset`, then a #[Specifiable] property (up to 1.14 a public property was tried before the getter,
 * and `isX()`/`hasX()`/`x()` and #[Specifiable] were not read).
 *
 * Failure metadata in English only since 2.0.0 (`action`, `document_type`, `group_code`,
 * `requirement_mode`); the Portuguese duplicates of 1.7.0 to 1.14.x were removed.
 *
 * @version    2.0.0
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
        $expr = $rule->getConditionExpression();
        if ($expr === null || trim($expr) === '') {
            return null;
        }

        if (!preg_match(self::GUARD_PATTERN, trim($expr), $m)) {
            throw new InvalidConditionalExpressionException(
                $expr,
                $rule->getDocumentTypeCode(),
                $rule->getGroupCode()
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
        return $this->rule->getDocumentTypeCode();
    }

    /** @return string Failure code emitted when the document is missing (DOC_<TYPE>) */
    public function getFailureCode(): string
    {
        return 'DOC_' . strtoupper($this->rule->getDocumentTypeCode());
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

        $actual = PropertyAccess::getValue($candidate, $prop);

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

        return $this->evaluator->hasDocument($candidate, $this->rule->getDocumentTypeCode(), $this->context);
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
            $error = SpecificationResult::error($e, 'DocumentRule:' . $this->rule->getGroupCode(), 'documents.' . $this->getDocumentType(), $this->getFailureCode());
            // A document requirement always blocks: its failures, errors included, are ERROR (1.5.0).
            return new SpecificationResult(false, [$error->failures[0]->withSeverity(FailureSeverity::ERROR)], true, $e);
        }

        return SpecificationResult::failure(
            message: sprintf('Required document missing: %s.', $this->getDocumentType()),
            code: $this->getFailureCode(),
            ruleName: 'DocumentRule:' . $this->rule->getGroupCode(),
            property: 'documents.' . $this->getDocumentType(),
            metadata: [
                'action' => RuleAction::BLOCK->value,
                'document_type' => $this->getDocumentType(),
                'group_code' => $this->rule->getGroupCode(),
                'requirement_mode' => $this->rule->getRequirementMode()->value,
            ],
            severity: FailureSeverity::ERROR
        );
    }
}
