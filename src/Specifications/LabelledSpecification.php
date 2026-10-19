<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ISerializableSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\ISpecificationVisitor;
use Antevemus\ASpecification\Results\SpecificationFailure;
use Antevemus\ASpecification\Results\SpecificationResult;
use Antevemus\ASpecification\Serialization\SpecificationCodec;
use InvalidArgumentException;
use Throwable;

/**
 * LabelledSpecification - A Human Label for the Target of a Specification (2.5.0, forward 048, L3)
 *
 * Decorator built by `$specification->labelled('Ongoing claims: 3 (maximum 2)')` or
 * `Spec::labelled($label, $specification)`. The evaluation is delegated untouched; every failure of the
 * result (rule failure or evaluation error) is reported against the label: `property` becomes the label
 * and `metadata['label']` holds it, while the technical property the failure had, when it differs, moves
 * to `metadata['property_path']` (nothing is lost). The answer, `isError` and the exception of the result
 * never change. The label is presentation, never SQL: toSql()/toCriteria() and every visitor see the inner
 * specification (accept() delegates), and the algebra compares the inner one (D8, D11).
 *
 * Semantics (D8-D10):
 * - nested labels: the innermost wins. A failure that already carries `metadata['label']` keeps it, as
 *   PropertySpecification keeps a property set inside it; `allOf(a->labelled('A'), b->labelled('B'))
 *   ->labelled('Group')` reports A and B, and the outer label only names failures that had none;
 * - because()/withCode() on the decorator replace the message and the code of each rule failure; an
 *   evaluation error is never absorbed (its failures keep their message and code), as in
 *   PropertySpecification;
 * - candidates: whatever the inner specification accepts (null included); isTautology()/isContradiction()
 *   are the inner ones;
 * - equality: same class, same label, inner specifications equal() and the same annotations;
 * - serialization: `{"t": "labelled", "label": "...", "s": {...}}`, read only from 2.5.0 on;
 * - simplify() keeps the node and simplifies inside.
 *
 * @template T
 * @version    2.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class LabelledSpecification extends AbstractSpecification implements ISerializableSpecification
{
    /** Metadata key holding the label of a failure. */
    public const METADATA_LABEL = 'label';

    /** Metadata key holding the technical property a labelled failure had. */
    public const METADATA_PROPERTY_PATH = 'property_path';

    /**
     * @param ISpecification<T> $inner Specification whose failures are labelled
     * @param string $label Human label of the target (presentation text, not blank)
     * @throws InvalidArgumentException When the label is blank
     */
    public function __construct(
        private readonly ISpecification $inner,
        private readonly string $label
    ) {
        if (trim($label) === '') {
            throw new InvalidArgumentException('The label of a specification cannot be blank.');
        }
    }

    /**
     * The decorated specification.
     *
     * @return ISpecification<T>
     */
    public function getInnerSpecification(): ISpecification
    {
        return $this->inner;
    }

    /** The label given to the target. */
    public function getLabel(): string
    {
        return $this->label;
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

    /**
     * {@inheritdoc}
     *
     * The inner result with every failure labelled (the innermost label wins) and, for a rule failure
     * (not an evaluation error), the because()/withCode() annotations of this node.
     */
    public function evaluate(mixed $candidate): SpecificationResult
    {
        try {
            $result = $this->inner->evaluate($candidate);
        } catch (Throwable $e) {
            $result = SpecificationResult::error(exception: $e, ruleName: 'LabelledSpecification', code: $this->customCode);
        }

        if ($result->isSatisfied) {
            return $result;
        }

        $annotate = !$result->isError && ($this->customReason !== null || $this->customCode !== null);
        $failures = array_map(
            fn(SpecificationFailure $failure): SpecificationFailure => $this->relabel($failure, $annotate),
            $result->failures
        );

        return new SpecificationResult(false, $failures, $result->isError, $result->exception);
    }

    /** {@inheritdoc} */
    public function isTautology(): bool
    {
        return $this->inner->isTautology();
    }

    /** {@inheritdoc} */
    public function isContradiction(): bool
    {
        return $this->inner->isContradiction();
    }

    /**
     * {@inheritdoc}
     *
     * The label does not change what is accepted: the inner specification answers.
     */
    public function isGeneralizationOf(ISpecification $otherSpecification): bool
    {
        return $this->inner->isGeneralizationOf($otherSpecification);
    }

    /**
     * {@inheritdoc}
     *
     * The label does not change what is accepted: the inner specification answers.
     */
    public function isDisjointWith(ISpecification $otherSpecification): bool
    {
        return $this->inner->isDisjointWith($otherSpecification);
    }

    /**
     * {@inheritdoc}
     *
     * The visitors translate the inner specification: a label is never SQL.
     */
    public function accept(ISpecificationVisitor $visitor): mixed
    {
        return $this->inner->accept($visitor);
    }

    /** {@inheritdoc} */
    public function __toString(): string
    {
        return sprintf('Labelled[%s](%s)', $this->label, (string) $this->inner);
    }

    /**
     * {@inheritdoc}
     */
    public function toArray(): array
    {
        return ['label' => $this->label, 's' => $this->inner];
    }

    /**
     * {@inheritdoc}
     */
    public static function fromArray(array $data, SpecificationCodec $codec): static
    {
        $codec->expectKeys($data, ['label', 's']);

        return new static($codec->specification($data, 's'), $codec->string($data, 'label'));
    }

    /**
     * One failure reported against the label: `property` = the label and `metadata['label']`, the former
     * property kept as `metadata['property_path']` when it differs; a failure already labelled inside
     * keeps its label. With $annotate, the because()/withCode() of this node replace message and code.
     */
    private function relabel(SpecificationFailure $failure, bool $annotate): SpecificationFailure
    {
        $metadata = $failure->metadata;
        $property = $failure->property;
        if (!array_key_exists(self::METADATA_LABEL, $metadata)) {
            if ($property !== null && $property !== $this->label) {
                $metadata[self::METADATA_PROPERTY_PATH] = $property;
            }
            $metadata[self::METADATA_LABEL] = $this->label;
            $property = $this->label;
        }

        return new SpecificationFailure(
            message: $annotate && $this->customReason !== null ? $this->customReason : $failure->message,
            code: $annotate && $this->customCode !== null ? $this->customCode : $failure->code,
            ruleName: $failure->ruleName,
            property: $property,
            metadata: $metadata,
            severity: $failure->severity
        );
    }
}
