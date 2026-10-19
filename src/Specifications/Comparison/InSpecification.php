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
use Antevemus\ASpecification\Contracts\ISerializableSpecification;
use Antevemus\ASpecification\Serialization\SpecificationCodec;

/**
 * InSpecification - Leaf specification for set membership (`x ∈ V`).
 *
 * The candidate satisfies the specification when it is the same value as one of the values of the set,
 * with the typed equality of `equalTo()` (TypeCompatibility::sameValue(): `===`, date-times by
 * instant). It is the leaf behind `Spec::in()`,
 * the factory `in()` / `isOneOfValues()` / `isEitherOfValues()` and the DSL `in()`; `notIn()` is
 * `not(in())`. Before 1.5.0 `in(a, b)` built `equalTo(a) OR equalTo(b)`; the leaf keeps that
 * semantics and adds what the chain could not express: set equality, set algebra and a direct
 * `IN (...)` translation in the SQL, TCriteria and ALinq visitors.
 *
 * Evaluation:
 * - null satisfies only when null belongs to the set
 * - a candidate equatable with at least one value of the set (same type family, see
 *   TypeCompatibility::isEquatable()) is a member when one of them is the same value (`===`, or the
 *   same instant for date-times); values of the set of another type simply do not match (the set may
 *   mix types)
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
 * - only for the predicate of this class: a subclass that redefines isSatisfiedBy() gets the shared
 *   axioms only, and only equalTo()/at() (not a loose equality) is a point of the set (2.1.0)
 *
 * Membership compares date-times by instant, in the evaluation as in the algebra and in SQL
 * (`"d" IN (...)`), like the relational leaves and `equalTo()` (2.1.0; up to 2.0.0 the evaluation took
 * only the very same date-time object, BUG-20261013-LIZI). Any other object is a member by identity.
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @implements ILeafSpecification<T>
 * @version    2.4.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Comparison
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class InSpecification extends AbstractSpecification implements ILeafSpecification, ISerializableSpecification
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
     * True when the value belongs to the set (TypeCompatibility::sameValue(): date-times by instant,
     * everything else by strict identity), as isSatisfiedBy() decides membership.
     *
     * @param mixed $value
     * @return bool
     */
    public function containsValue(mixed $value): bool
    {
        foreach ($this->values as $member) {
            if (TypeCompatibility::sameValue($value, $member)) {
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
        if (in_array($candidate, $this->values, true)
            || ($candidate instanceof DateTimeInterface && $this->containsValue($candidate))) {
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
     * repetitions are irrelevant), with the same custom reason and code. Members are matched as
     * isSatisfiedBy() matches a candidate (containsValue(): date-times by instant, anything else by
     * strict identity), so `in([$d, clone $d])` equals `in([$d])`.
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
        foreach ($other->values as $value) {
            if (!$this->containsValue($value)) {
                return false;
            }
        }
        foreach ($this->values as $value) {
            if (!$other->containsValue($value)) {
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
        if (!SpecificationAlgebra::evaluatesAs($this, self::class)) {
            // A subclass that redefines isSatisfiedBy() gets the shared axioms only (2.1.0)
            return false;
        }

        $other = SpecificationAlgebra::resolve($otherSpecification);
        if ($other instanceof EqualSpecification && SpecificationAlgebra::evaluatesAs($other, EqualSpecification::class, SameInstantSpecification::class)) {
            // equalTo(x)/at(x) accepts the values that are the same value as x, as this set matches them;
            // a loose equality (`==`) or a user subclass accepts others (2.1.0)
            return $this->containsValue($other->getValue());
        }
        if ($other instanceof IsNullSpecification && SpecificationAlgebra::evaluatesAs($other, IsNullSpecification::class)) {
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
        if ($this->values === [] && SpecificationAlgebra::evaluatesAs($this, self::class)) {
            return true;
        }
        if ($this->checkBaseDisjointness($otherSpecification)) {
            return true;
        }
        if (!SpecificationAlgebra::evaluatesAs($this, self::class)) {
            return false;
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
        if ($this->values === [] && SpecificationAlgebra::evaluatesAs($this, self::class)) {
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
        return $this->values === [] && SpecificationAlgebra::evaluatesAs($this, self::class);
    }

    /**
     * {@inheritdoc}
     */
    public function __toString(): string
    {
        return 'InSpecification';
    }

    /**
     * {@inheritdoc}
     *
     * Serialized as `{"t": "in", "v": [1, 2]}` (2.4.0, forward 041).
     */
    public function toArray(): array
    {
        return ['v' => $this->values];
    }

    /**
     * {@inheritdoc}
     */
    public static function fromArray(array $data, SpecificationCodec $codec): static
    {
        $codec->expectKeys($data, ['v']);

        return new static($codec->values($data, 'v'));
    }
}
