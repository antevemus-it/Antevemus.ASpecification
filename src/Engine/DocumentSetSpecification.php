<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Results\SpecificationResult;

/**
 * DocumentSetSpecification - Alternative (ANY) or Exclusive (ONE_OF_SET) Document Set
 *
 * Aggregates the leaves of one alternative set and counts only the documents that APPLY to the
 * candidate (guards that do not hold take the document out of the count, they never count as
 * "present"). ANY requires at least one applicable document present; ONE_OF_SET requires exactly
 * one. A set in which no document applies requires nothing and is satisfied.
 *
 * A failing set emits ONE aggregated failure (`DOC_SET_<KEY>`) listing the accepted documents,
 * instead of one failure per document, so the verdict reports one blocked requirement.
 *
 * @version    1.2.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class DocumentSetSpecification extends AbstractSpecification
{
    /**
     * @param string $setKey Set identifier (codigo_set_alternativas, or the group code for implicit sets)
     * @param DocumentRequirementMode $mode ANY or ONE_OF_SET
     * @param list<DocumentLeafSpecification> $leaves Documents belonging to the set
     */
    public function __construct(
        private readonly string $setKey,
        private readonly DocumentRequirementMode $mode,
        private readonly array $leaves
    ) {
        if ($mode === DocumentRequirementMode::ALL) {
            throw new \InvalidArgumentException('DocumentSetSpecification only aggregates ANY or ONE_OF_SET rules.');
        }
    }

    /** @return string */
    public function getSetKey(): string
    {
        return $this->setKey;
    }

    /** @return DocumentRequirementMode */
    public function getMode(): DocumentRequirementMode
    {
        return $this->mode;
    }

    /** @return list<DocumentLeafSpecification> */
    public function getLeaves(): array
    {
        return $this->leaves;
    }

    /** {@inheritdoc} */
    public function getType(): string
    {
        return 'mixed';
    }

    /** {@inheritdoc} */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        [$applicable, $present] = $this->tally($candidate);

        if ($applicable === []) {
            return true;
        }

        return $this->mode === DocumentRequirementMode::ANY
            ? count($present) >= 1
            : count($present) === 1;
    }

    /** {@inheritdoc} */
    public function evaluate(mixed $candidate): SpecificationResult
    {
        try {
            [$applicable, $present] = $this->tally($candidate);
        } catch (\Throwable $e) {
            return SpecificationResult::error($e, 'DocumentRuleSet:' . $this->setKey, 'documentos_set.' . $this->setKey, $this->getFailureCode());
        }

        $satisfied = $applicable === []
            || ($this->mode === DocumentRequirementMode::ANY ? count($present) >= 1 : count($present) === 1);

        if ($satisfied) {
            return SpecificationResult::satisfied();
        }

        $accepted = array_map(static fn(DocumentLeafSpecification $l): string => $l->getDocumentType(), $applicable);
        $codes = array_map(static fn(DocumentLeafSpecification $l): string => $l->getFailureCode(), $applicable);
        $presentTypes = array_map(static fn(DocumentLeafSpecification $l): string => $l->getDocumentType(), $present);

        $message = $this->mode === DocumentRequirementMode::ANY
            ? sprintf('Document set "%s" requires at least one of: %s (present: %d).', $this->setKey, implode(', ', $accepted), count($present))
            : sprintf('Document set "%s" requires exactly one of: %s (present: %d).', $this->setKey, implode(', ', $accepted), count($present));

        return SpecificationResult::failure(
            message: $message,
            code: $this->getFailureCode(),
            ruleName: 'DocumentRuleSet:' . $this->setKey,
            property: 'documentos_set.' . $this->setKey,
            metadata: [
                'acao' => 'bloquear',
                'set_alternativas' => $this->setKey,
                'modo' => $this->mode->value,
                'documentos_aceitos' => $accepted,
                'codigos' => $codes,
                'presentes' => $presentTypes,
                'esperado' => $this->mode === DocumentRequirementMode::ANY ? '>=1' : 1,
                'recebido' => count($present),
            ]
        );
    }

    /** @return string Aggregated failure code (DOC_SET_<KEY>) */
    public function getFailureCode(): string
    {
        return 'DOC_SET_' . strtoupper($this->setKey);
    }

    /**
     * Splits the leaves into those that apply to the candidate and, among them, those present.
     *
     * @param mixed $candidate
     * @return array{0: list<DocumentLeafSpecification>, 1: list<DocumentLeafSpecification>}
     */
    private function tally(mixed $candidate): array
    {
        $applicable = [];
        $present = [];
        foreach ($this->leaves as $leaf) {
            if (!$leaf->appliesTo($candidate)) {
                continue;
            }
            $applicable[] = $leaf;
            if ($leaf->isPresentIn($candidate)) {
                $present[] = $leaf;
            }
        }

        return [$applicable, $present];
    }
}
