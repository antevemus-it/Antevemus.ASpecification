<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\Engine\IRuleDefinition;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\ISpecificationVisitor;
use Antevemus\ASpecification\Results\FailureSeverity;
use Antevemus\ASpecification\Results\SpecificationFailure;
use Antevemus\ASpecification\Results\SpecificationResult;
use Antevemus\ASpecification\Specifications\LabelledSpecification;

/**
 * RuleBoundSpecification - Binds a Compiled Specification to its Catalog Rule
 *
 * Decorator applied by the DynamicSpecificationEngine to every specification produced by a
 * rule handler. Evaluation is delegated untouched; on failure, every SpecificationFailure is
 * stamped with the catalog rule it came from, so the RuleEngineVerdict can triage by action
 * (BLOCK / WARN / LOG) and report legal bases without each handler re-implementing the plumbing.
 *
 * Stamping rules (handler data always wins over catalog data):
 * - A failure carrying a `code` counts as customized by the handler: message and code are kept,
 *   and the rule data is merged UNDER its metadata.
 * - When NO failure carries a code (raw specification, e.g. `Spec::property(...)` as the README
 *   shows), the rule is reported ONCE: a single failure with the rule code and the rule's
 *   `violation_message` (or its name). A raw composite such as `lessThanOrEqualTo` (an OR of two
 *   leaves) would otherwise surface two technical failures for one business rule. The technical
 *   messages are preserved in `metadata['original_message']` / `metadata['original_messages']`.
 * - `metadata` always receives `action`, `rule_code`, `rule_type`, `rule_name`, `priority`,
 *   `original_message` and, when the rule has one, `legal_basis`, under the handler's own keys.
 * - Evaluation errors (`isError`) keep their `evaluation_error` marker, so the verdict blocks them
 *   regardless of the rule action.
 * - Every reported failure carries a FailureSeverity (1.5.0) resolved exactly as the verdict
 *   triages it: an evaluation error is ERROR; otherwise the effective action (the handler's
 *   `action` when it recorded one, the rule's otherwise) maps BLOCK → ERROR, WARN → WARNING,
 *   LOG → INFO. A severity set explicitly on a customized (coded) failure is kept.
 * - English keys only since 2.0.0: the Portuguese duplicates written up to 1.14.x (`acao`, `regra`,
 *   `tipo_regra`, `nome_regra`, `prioridade`, `mensagem_original`, `fundamento_legal`,
 *   `mensagens_originais`, `falhas_originais`) are gone, and a handler that still records its action
 *   under `acao`/`acao_ao_violar`, or a Portuguese action value, is refused with
 *   LegacyVocabularyException (RuleAction::fromMetadata()) instead of being triaged by the rule's action.
 * - Labelled targets (2.5.0, forward 048, D12): when the rule is reported once and its failures carry two
 *   or more distinct labels (LabelledSpecification), `metadata['labels']` lists them in order of
 *   appearance (the `property` of the single failure is null then)
 *
 * @version    2.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class RuleBoundSpecification extends AbstractSpecification
{
    /**
     * @param ISpecification $inner Specification compiled by the rule handler
     * @param IRuleDefinition $rule Catalog rule that produced the inner specification
     */
    public function __construct(
        private readonly ISpecification $inner,
        private readonly IRuleDefinition $rule
    ) {
    }

    /**
     * Returns the handler-compiled specification being decorated.
     *
     * @return ISpecification
     */
    public function getInnerSpecification(): ISpecification
    {
        return $this->inner;
    }

    /**
     * Returns the catalog rule bound to this specification.
     *
     * @return IRuleDefinition
     */
    public function getRule(): IRuleDefinition
    {
        return $this->rule;
    }

    /** {@inheritdoc} */
    public function getType(): string
    {
        return $this->inner->getType();
    }

    /** {@inheritdoc} */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        return $this->inner->isSatisfiedBy($candidate);
    }

    /** {@inheritdoc} */
    public function evaluate(mixed $candidate): SpecificationResult
    {
        try {
            $result = $this->inner->evaluate($candidate);
        } catch (\Throwable $e) {
            $result = SpecificationResult::error($e, 'Rule:' . $this->rule->getCode(), code: $this->rule->getCode());
        }

        if ($result->isSatisfied) {
            return $result;
        }

        $customized = array_filter(
            $result->failures,
            static fn(SpecificationFailure $f): bool => $f->code !== null && $f->code !== ''
        );

        $stamped = $customized === [] && $result->failures !== []
            ? [$this->collapse($result->failures)]
            : array_map(fn(SpecificationFailure $f): SpecificationFailure => $this->stamp($f), $result->failures);

        return new SpecificationResult($result->isSatisfied, $stamped, $result->isError, $result->exception);
    }

    /**
     * {@inheritdoc}
     *
     * The binding does not change what the inner specification accepts (1.6.0, forward 019).
     */
    public function isTautology(): bool
    {
        return $this->inner->isTautology();
    }

    /**
     * {@inheritdoc}
     *
     * The binding does not change what the inner specification accepts (1.6.0, forward 019).
     */
    public function isContradiction(): bool
    {
        return $this->inner->isContradiction();
    }

    /** {@inheritdoc} */
    public function accept(ISpecificationVisitor $visitor): mixed
    {
        return $this->inner->accept($visitor);
    }

    /** {@inheritdoc} */
    public function __toString(): string
    {
        return sprintf('Rule[%s](%s)', $this->rule->getCode(), (string) $this->inner);
    }

    /**
     * Reports a raw (uncustomized) evaluation as ONE failure of the catalog rule.
     *
     * @param list<SpecificationFailure> $failures Technical failures produced by the raw specification
     * @return SpecificationFailure
     */
    private function collapse(array $failures): SpecificationFailure
    {
        $first = $failures[0];
        $messages = array_map(static fn(SpecificationFailure $f): string => $f->message, $failures);
        $properties = array_unique(array_filter(array_map(static fn(SpecificationFailure $f): ?string => $f->property, $failures)));

        $metadata = [];
        foreach ($failures as $f) {
            $metadata = array_merge($metadata, $f->metadata);
        }

        $ruleMessage = $this->rule->getViolationMessage();
        $message = ($ruleMessage !== null && trim($ruleMessage) !== '') ? $ruleMessage : $this->rule->getName();

        $metadata = array_merge(
            $this->ruleMetadata($first->message),
            ['original_messages' => $messages, 'original_failures' => count($failures)],
            $metadata
        );

        // 2.5.0 (forward 048, D12): with two or more labelled targets the property is null, so the
        // distinct labels are kept, in the order they appear
        $labels = [];
        foreach ($failures as $f) {
            $label = $f->metadata[LabelledSpecification::METADATA_LABEL] ?? null;
            if (is_string($label) && !in_array($label, $labels, true)) {
                $labels[] = $label;
            }
        }
        if (count($labels) >= 2) {
            $metadata['labels'] = $labels;
        }

        return new SpecificationFailure(
            message: $message,
            code: $this->rule->getCode(),
            ruleName: 'Rule:' . $this->rule->getCode(),
            property: count($properties) === 1 ? reset($properties) : null,
            metadata: $metadata,
            severity: self::severityOf($metadata)
        );
    }

    /**
     * Catalog data stamped under every failure.
     *
     * @param string $originalMessage
     * @return array<string, mixed>
     */
    private function ruleMetadata(string $originalMessage): array
    {
        // English keys only (the Portuguese duplicates of 1.7.0 to 1.14.x were removed in 2.0.0).
        $ruleMetadata = [
            'action' => $this->rule->getViolationAction()->value,
            'rule_code' => $this->rule->getCode(),
            'rule_type' => $this->rule->getRuleType(),
            'rule_name' => $this->rule->getName(),
            'priority' => $this->rule->getPriority(),
            'original_message' => $originalMessage,
        ];
        $legalBasis = $this->rule->getLegalBasis();
        if ($legalBasis !== null && trim($legalBasis) !== '') {
            $ruleMetadata['legal_basis'] = $legalBasis;
        }

        return $ruleMetadata;
    }

    /**
     * Stamps one customized failure with the catalog rule, keeping whatever the handler recorded.
     *
     * @param SpecificationFailure $failure
     * @return SpecificationFailure
     */
    private function stamp(SpecificationFailure $failure): SpecificationFailure
    {
        $metadata = array_merge($this->ruleMetadata($failure->message), $failure->metadata);

        // The handler's action (`action` or `violation_action`) wins over the rule's; a Portuguese key or
        // value recorded by the handler is refused (2.0.0) rather than triaged by the rule's action.
        $handled = array_key_exists('action', $failure->metadata) || array_key_exists('violation_action', $failure->metadata);
        $effective = RuleAction::fromMetadata($failure->metadata, $this->rule->getViolationAction());
        if ($handled) {
            $metadata['action'] = $effective->value;
        }

        return new SpecificationFailure(
            message: $failure->message,
            code: ($failure->code !== null && $failure->code !== '') ? $failure->code : $this->rule->getCode(),
            ruleName: $failure->ruleName ?? ('Rule:' . $this->rule->getCode()),
            property: $failure->property,
            metadata: $metadata,
            severity: $failure->severity ?? self::severityOf($metadata)
        );
    }

    /**
     * Resolves the severity of a stamped failure with the same precedence RuleEngineVerdict uses to
     * triage it, so that severity and verdict bucket never disagree: an evaluation error is ERROR;
     * otherwise the effective action (RuleAction::fromMetadata(): `action`, `violation_action`, default
     * `block`) is mapped by RuleAction::toSeverity().
     *
     * @param array<string, mixed> $metadata Final metadata of the failure (rule data under handler data)
     * @return FailureSeverity
     */
    private static function severityOf(array $metadata): FailureSeverity
    {
        if (isset($metadata['evaluation_error'])) {
            return FailureSeverity::ERROR;
        }

        return RuleAction::fromMetadata($metadata)->toSeverity();
    }
}
