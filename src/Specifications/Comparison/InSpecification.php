<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Comparison;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ILeafSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Specifications\Exceptions\IncompatibleTypeException;
use Antevemus\ASpecification\Specifications\SpecificationAlgebra;
use Antevemus\ASpecification\Specifications\SubsumptionAndEqualityTrait;
use DateTimeInterface;

/**
 * InSpecification - Leaf specification for set membership (`x ∈ V`).
 *
 * The candidate satisfies the specification when it is strictly identical (`===`, the same typed
 * equality as `equalTo()`) to one of the values of the set. It is the leaf behind `Spec::in()`,
 * the factory `in()` / `isOneOfValues()` / `isEitherOfValues()` and the DSL `in()`; `notIn()` is
 * `not(in())`. Before 1.5.0 `in(a, b)` built `equalTo(a) OR equalTo(b)`; the leaf keeps that
 * semantics and adds what the chain could not express: set equality, set algebra and a direct
 * `IN (...)` translation in the SQL, TCriteria and ALinq visitors.
 *
 * Evaluation:
 * - null satisfies only when null belongs to the set
 * - a candidate equatable with at least one value of the set (same type family, see
 *   TypeCompatibility::isEquatable()) is a member when one of them is `===` to it; values of the
 *   set of another type simply do not match (the set may mix types)
 * - a non-null candidate that is equatable with no value of the set is a type error
 *   (IncompatibleTypeException), as `equalTo()` raises it; never a silent false
 * - the empty set is the contradiction: never satisfied, never throws
 *
 * Algebra (set semantics, `V` and `W` the value sets):
 * - `in(V) ⊇ equalTo(x)` ⇔ `x ∈ V`; `in(V) ⟂ equalTo(x)` ⇔ `x ∉ V`
 * - `in(V) ⊇ in(W)` ⇔ `W ⊆ V`; `in(V) ⟂ in(W)` ⇔ `V ∩ W = ∅`
 * - `X ⊇ in(V)` ⇔ `X ⊇ equalTo(v)` for every `v ∈ V`; `X ⟂ in(V)` ⇔ `X ⟂ equalTo(v)` for every
 *   `v ∈ V` (SpecificationAlgebra applies these two rules for every specification class)
 * - `in([])` is the contradiction: generalized by everything and disjoint with everything, and
 *   isContradiction() says so (1.6.0, forward 019 RN-09)
 * - `equals()` compares the two sets as sets (order and repetitions are irrelevant)
 *
 * Membership in the algebra compares date-times by instant, like the relational leaves; evaluation
 * is strict identity, like `equalTo()` (use SameInstantSpecification for dates).
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @implements ILeafSpecification<T>
 * @version    1.6.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Comparison
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class InSpecification extends AbstractSpecification implements ILeafSpecification
{
    use SubsumptionAndEqualityTrait;

    /**
     * Accepted values, as a list without repetitions (strict identity), in the order given.
     *
     * @var list<mixed>
     */
    private readonly array $values;

    /**
     * @param array<mixed> $values Accepted values; keys are ignored and repetitions removed
     */
    public function __construct(array $values)
    {
        $unique = [];
        foreach ($values as $value) {
            if (!in_array($value, $unique, true)) {
                $unique[] = $value;
            }
        }
        $this->values = $unique;
    }

    /**
     * Returns the accepted values (a list without repetitions, in the order given).
     *
     * @return list<mixed>
     */
    public function getValues(): array
    {
        return $this->values;
    }

    /**
     * True when the set is empty (the specification is a contradiction).
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /**
     * True when the value belongs to the set as the algebra sees it (date-times by instant,
     * everything else by strict identity). Evaluation uses isSatisfiedBy() instead.
     *
     * @param mixed $value
     * @return bool
     */
    public function containsValue(mixed $value): bool
    {
        foreach ($this->values as $member) {
            if (self::valuesEqual($member, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     *
     * @throws IncompatibleTypeException When the candidate is not null and cannot be compared with
     *                                   any value of the (non-empty) set
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        if ($this->values === []) {
            return false;
        }
        if (in_array($candidate, $this->values, true)) {
            return true;
        }
        if ($candidate === null) {
            return false;
        }

        foreach ($this->values as $value) {
            if (TypeCompatibility::isEquatable($candidate, $value)) {
                return false;
            }
        }

        throw new IncompatibleTypeException('InSpecification', $candidate, $this->values[0]);
    }

    /**
     * {@inheritdoc}
     *
     * A set made only of date-times is a specification of DateTimeInterface; any other set
     * accepts whatever type its values have ('mixed').
     */
    public function getType(): string
    {
        if ($this->values === []) {
            return 'mixed';
        }
        foreach ($this->values as $value) {
            if (!$value instanceof DateTimeInterface) {
                return 'mixed';
            }
        }

        return DateTimeInterface::class;
    }

    /**
     * {@inheritdoc}
     *
     * Two set specifications are equal when they hold the same set of values (order and
     * repetitions are irrelevant), with the same custom reason and code.
     */
    public function equals(mixed $other): bool
    {
        if ($this === $other) {
            return true;
        }
        if (!$other instanceof self || $other::class !== static::class) {
            return false;
        }
        if ($this->customReason !== $other->customReason || $this->customCode !== $other->customCode) {
            return false;
        }
        if (count($this->values) !== count($other->values)) {
            return false;
        }
        foreach ($other->values as $value) {
            if (!$this->containsValue($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * {@inheritdoc}
     *
     * `in(V) ⊇ X`: the shared axioms first (reflexivity, `X` contradiction, `X` a disjunction or
     * conjunction, `X` a set: `W ⊆ V`), then `X = equalTo(x)` ⇔ `x ∈ V` and `X = isNull()` ⇔
     * `null ∈ V`. The empty set generalizes only contradictions.
     */
    public function isGeneralizationOf(ISpecification $otherSpecification): bool
    {
        if ($this->checkBaseGeneralization($otherSpecification)) {
            return true;
        }

        $other = SpecificationAlgebra::resolve($otherSpecification);
        if ($other instanceof EqualSpecification) {
            return $this->containsValue($other->getValue());
        }
        if ($other instanceof IsNullSpecification) {
            return in_array(null, $this->values, true);
        }

        return false;
    }

    /**
     * {@inheritdoc}
     *
     * `in(V) ⟂ X`: the empty set is disjoint with everything; otherwise the shared axioms
     * (including `X` a set: `V ∩ W = ∅`), then `in(V) ⟂ X` ⇔ `equalTo(v) ⟂ X` for every `v ∈ V`
     * (`x ∉ V` against `equalTo(x)`, every value outside the range against a relational leaf,
     * `V ⊆ {x}` against `notEqualTo(x)`).
     */
    public function isDisjointWith(ISpecification $otherSpecification): bool
    {
        if ($this->values === []) {
            return true;
        }
        if ($this->checkBaseDisjointness($otherSpecification)) {
            return true;
        }

        $other = SpecificationAlgebra::resolve($otherSpecification);
        if ($other instanceof self) {
            // Already decided by the shared axiom (V ∩ W = ∅); reaching here means they intersect.
            return false;
        }

        foreach ($this->values as $value) {
            if (!(new EqualSpecification($value))->isDisjointWith($other)) {
                return false;
            }
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function isSpecialCaseOf(ISpecification $otherSpecification): bool
    {
        if ($this->values === []) {
            return true;
        }

        return $otherSpecification->isGeneralizationOf($this);
    }

    /**
     * {@inheritdoc}
     *
     * RN-09 (forward 019): the empty set in([]) is the contradiction; any other set is not.
     */
    public function isContradiction(): bool
    {
        return $this->values === [];
    }

    /**
     * {@inheritdoc}
     */
    public function __toString(): string
    {
        return 'InSpecification';
    }

    /**
     * Value equality as the algebra sees it: date-times by instant, everything else strictly.
     */
    private static function valuesEqual(mixed $a, mixed $b): bool
    {
        if ($a instanceof DateTimeInterface && $b instanceof DateTimeInterface) {
            return $a == $b;
        }

        return $a === $b;
    }
}
