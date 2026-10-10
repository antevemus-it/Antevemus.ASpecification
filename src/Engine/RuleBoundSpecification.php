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
 *   messages are preserved in `metadata['original_message']` / `metadata['original_messages']` (legacy Portuguese keys `mensagem_original` / `mensagens_originais` kept until 2.0.0).
 * - `metadata` always receives `acao`, `fundamento_legal`, `regra`, `tipo_regra`, `nome_regra`
 *   and `prioridade` under the handler's own keys.
 * - Evaluation errors (`isError`) keep their `evaluation_error` marker, so the verdict blocks them
 *   regardless of the rule action.
 * - Every reported failure carries a FailureSeverity (1.5.0) resolved exactly as the verdict
 *   triages it: an evaluation error is ERROR; otherwise the effective action (the handler's
 *   `acao` when it recorded one, the rule's otherwise) maps BLOCK → ERROR, WARN → WARNING,
 *   LOG → INFO. A severity set explicitly on a customized (coded) failure is kept.
 * - The metadata keys above are unchanged in 1.7.0; `acao` now carries the English action value
 *   (`block`, `warn`, `log`), and the legacy Portuguese values are still triaged on read.
 *
 * @version    1.7.0
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
            ['original_messages' => $messages, 'original_failures' => count($failures), 'mensagens_originais' => $messages, 'falhas_originais' => count($failures)],
            $metadata
        );

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
        // English keys are canonical since 1.7.0; the Portuguese ones are kept as legacy
        // duplicates for consumers that read them, and are removed in 2.0.0.
        $ruleMetadata = [
            'action' => $this->rule->getViolationAction()->value,
            'rule_code' => $this->rule->getCode(),
            'rule_type' => $this->rule->getRuleType(),
            'rule_name' => $this->rule->getName(),
            'priority' => $this->rule->getPriority(),
            'original_message' => $originalMessage,
            'acao' => $this->rule->getViolationAction()->value,
            'regra' => $this->rule->getCode(),
            'tipo_regra' => $this->rule->getRuleType(),
            'nome_regra' => $this->rule->getName(),
            'prioridade' => $this->rule->getPriority(),
            'mensagem_original' => $originalMessage,
        ];
        $legalBasis = $this->rule->getLegalBasis();
        if ($legalBasis !== null && trim($legalBasis) !== '') {
            $ruleMetadata['legal_basis'] = $legalBasis;
            $ruleMetadata['fundamento_legal'] = $legalBasis;
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

        // The handler's action wins over the rule's, whatever language the handler wrote it in:
        // both keys carry the same effective action (English canonical, Portuguese legacy until 2.0.0).
        $handlerAction = $failure->metadata['action'] ?? $failure->metadata['violation_action']
            ?? $failure->metadata['acao'] ?? $failure->metadata['acao_ao_violar'] ?? null;
        if ($handlerAction !== null) {
            $effective = $handlerAction instanceof RuleAction
                ? $handlerAction
                : RuleAction::fromOrDefault(is_string($handlerAction) ? $handlerAction : null);
            $metadata['action'] = $effective->value;
            $metadata['acao'] = $effective->value;
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
     * otherwise the effective action (`action`, `violation_action`, legacy `acao`/`acao_ao_violar`, default `block`) is
     * mapped by RuleAction::toSeverity().
     *
     * @param array<string, mixed> $metadata Final metadata of the failure (rule data under handler data)
     * @return FailureSeverity
     */
    private static function severityOf(array $metadata): FailureSeverity
    {
        if (isset($metadata['evaluation_error'])) {
            return FailureSeverity::ERROR;
        }

        $actionRaw = $metadata['action'] ?? $metadata['violation_action'] ?? $metadata['acao'] ?? $metadata['acao_ao_violar'] ?? null;
        $action = $actionRaw instanceof RuleAction
            ? $actionRaw
            : RuleAction::fromOrDefault(is_string($actionRaw) ? $actionRaw : null);

        return $action->toSeverity();
    }
}
