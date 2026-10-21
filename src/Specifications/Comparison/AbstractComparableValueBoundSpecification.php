<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Comparison;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\IValueBoundSpecification;
use Antevemus\ASpecification\Specifications\Exceptions\IncompatibleTypeException;
use Antevemus\ASpecification\Specifications\NotSpecification;
use Antevemus\ASpecification\Specifications\SpecificationAlgebra;
use Antevemus\ASpecification\Specifications\SubsumptionAndEqualityTrait;
use DateTimeInterface;
use Antevemus\ASpecification\Contracts\ISerializableSpecification;
use Antevemus\ASpecification\Serialization\SpecificationCodec;

/**
 * AbstractComparableValueBoundSpecification - Base of the relational leaf specifications.
 *
 * Port of the Domian `AbstractValueBoundSpecification` / `AbstractComparableValueBoundSpecification`
 * (Copyright 2006-2010 the original author or authors, Apache License 2.0; see THIRD_PARTY_NOTICES.md):
 * a leaf bound to one reference value and one `RelationalOperator` (`=`, `<>`, `<`, `<=`, `>`, `>=`),
 * whose subsumption and disjointness against any other relational leaf on the same ordered domain
 * are decided by interval arithmetic:
 *
 * - `x > 5 ⊇ x > 7`, `x >= 5 ⊇ x > 5`, `x < 10 ⊇ x = 3`, `x <> 5 ⊇ x < 5`
 * - `x < 5 ⟂ x >= 5`, `x = 5 ⟂ x = 6`, `x = 5 ⟂ x <> 5`; a leaf that is not int-typed is never closed
 *   over the integers: `x > 10` and `x < 11` are not disjoint and `x >= 5` does not generalize `x > 4`,
 *   because the leaves accept a float candidate (10.5, 4.5) (2.1.0, forward 037 P2; up to 2.0.0 integer
 *   bounds were closed, x > 10 ≡ x >= 11)
 * - int-typed leaves (asInteger(), Spec::int(); 2.9.0, forward 044) close their bounds against each other:
 *   `x > a` is `x >= a + 1` and `x < b` is `x <= b - 1` when BOTH leaves are int-typed, so
 *   `int(x > 10) ⟂ int(x < 11)` and `int(x >= 5) ⊇ int(x > 4)`; an int-typed leaf against one that is not
 *   gets the rules above, unclosed
 * - the negation of a leaf is the leaf with the inverted operator (`¬(x < 10) ≡ x >= 10`)
 *
 * Values are compared with the PHP comparison operators, so integers, floats, strings and
 * `DateTimeInterface` instants are all supported; mixed types are never compared (the algebra
 * answers false, evaluation throws IncompatibleTypeException). A mutable `DateTime` reference
 * value is cloned on construction so the specification cannot change after creation.
 *
 * Soundness (2.1.0, forward 037): the interval rules hold for the predicates of the library leaves
 * only (SpecificationAlgebra::RELATIONAL_PREDICATES): a subclass that redefines isSatisfiedBy() (a user
 * leaf, LooseEqualSpecification) gets the shared axioms only and is not inverted; a leaf refusing null
 * never generalizes one that accepts it (`x <> 5` does not contain `x = null`); and two text bounds
 * are ordered only when neither is a numeric string, because PHP compares numeric strings as numbers
 * and any other text byte by byte, so `'1a' > '10'` while `'1a' < '9'` and `x > '9'` does not contain
 * `x > '10'`.
 *
 * Integer mode (2.9.0, forward 044, D1-D4): asInteger() returns a copy of the leaf whose getType() is 'int'.
 * Its bound must be an int (a float, even 10.0, a text or a date-time is refused with
 * InvalidArgumentException) and its evaluation refuses every non-null candidate that is not an int with
 * IncompatibleTypeException: a float (12.0 included), a numeric text, a boolean. It is what
 * `declare(strict_types=1)` does with an `int` parameter; the conversion belongs to the input boundary
 * (a mapper, json_decode(), which already gives an int for `12`). Null keeps the answer of the leaf. Only
 * the library leaves of the six operators can be int-typed: a subclass that redefines isSatisfiedBy()
 * (LooseEqualSpecification, at(), a user leaf) is refused with LogicException, because its predicate would
 * not apply the integer guard. The SQL, TCriteria and ALinq translations do not change: the column of the
 * database already holds integers, and ALinq evaluates the leaf itself.
 *
 * Reference values are compared the way the leaves compare a candidate with them (2.1.0): date-times
 * by instant, whatever their class or time zone (the relational operators order them by instant, and
 * equalTo()/notEqual()/at() test them by instant, BUG-20261013-LIZI), anything else strictly
 * (TypeCompatibility::sameValue()). equals() and the point rules of the algebra (`=`/`<>`) follow it.
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @implements IValueBoundSpecification<T>
 * @version    2.9.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Comparison
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class AbstractComparableValueBoundSpecification extends AbstractSpecification implements IValueBoundSpecification, ISerializableSpecification
{
    use SubsumptionAndEqualityTrait;

    /** The predicates that apply the integer guard of asInteger() (2.9.0): the six operators of the library. */
    private const INTEGER_PREDICATES = [
        EqualSpecification::class,
        NotEqualSpecification::class,
        LessThanSpecification::class,
        LessThanOrEqualSpecification::class,
        GreaterThanSpecification::class,
        GreaterThanOrEqualSpecification::class,
    ];

    /**
     * Reference value (right operand of the relation).
     */
    protected readonly mixed $value;

    /**
     * True for an int-typed leaf (asInteger(), 2.9.0): set only on the fresh copy asInteger() returns, so a
     * leaf never changes after it is built.
     */
    private bool $integer = false;

    /**
     * @param mixed $value Reference value; a mutable DateTime is defensively cloned
     */
    public function __construct(mixed $value)
    {
        $this->value = static::copyOf($value);
    }

    /**
     * Returns the relational operator this leaf applies between the candidate and the value.
     */
    abstract public function getRelationalOperator(): RelationalOperator;

    /**
     * Returns the reference value bound to this specification.
     */
    public function getValue(): mixed
    {
        return $this->value;
    }

    /**
     * {@inheritdoc}
     *
     * The reference value is compared as the leaves compare a candidate with it
     * (TypeCompatibility::sameValue(): date-times by instant, anything else strictly); the rest of the
     * state (the annotations, a subclass's own properties) as usual.
     */
    protected function propertyValuesEqual(string $declaringClass, string $property, mixed $mine, mixed $theirs): bool
    {
        if ($declaringClass === self::class && $property === 'value') {
            return TypeCompatibility::sameValue($mine, $theirs);
        }

        return parent::propertyValuesEqual($declaringClass, $property, $mine, $theirs);
    }

    /**
     * {@inheritdoc}
     *
     * A leaf bound to a date is a specification of DateTimeInterface; any other leaf accepts
     * whatever type its value has ('mixed'), the type guard being applied at evaluation time.
     */
    public function getType(): string
    {
        if ($this->integer) {
            return 'int';
        }

        return $this->value instanceof DateTimeInterface ? DateTimeInterface::class : 'mixed';
    }

    /**
     * The int-typed copy of this leaf (2.9.0, forward 044): `Spec::greaterThan(10)->asInteger()`. The copy
     * keeps the operator, the bound and the annotations, answers getType() with 'int', refuses every
     * non-null candidate that is not an int (IncompatibleTypeException: a float, 12.0 included, a numeric
     * text, a boolean) and closes its bound against another int-typed leaf (`x > 10` is `x >= 11`). The
     * leaf itself is not changed; an int-typed leaf returns itself.
     *
     * @return static
     * @throws \InvalidArgumentException When the bound is not an int (10.5, 10.0, '10', a date-time, null)
     * @throws \LogicException When the leaf does not evaluate with the predicate of its operator (a loose
     *                         equality, at(), a subclass that redefines isSatisfiedBy())
     */
    public function asInteger(): static
    {
        if ($this->integer) {
            return $this;
        }
        if (!SpecificationAlgebra::evaluatesAs($this, ...self::INTEGER_PREDICATES)) {
            throw new \LogicException(sprintf(
                '%s cannot be int-typed: asInteger() applies to the leaves of equalTo(), notEqual(), greaterThan(), greaterThanOrEqualTo(), lessThan() and lessThanOrEqualTo(), whose predicate applies the integer guard; a subclass that redefines isSatisfiedBy() would not.',
                static::class
            ));
        }
        if (!is_int($this->value)) {
            throw new \InvalidArgumentException(sprintf(
                '%s cannot be int-typed with a bound of type %s (%s): an int-typed leaf takes an int bound; convert the value before building the rule.',
                (new \ReflectionClass($this))->getShortName(),
                get_debug_type($this->value),
                is_scalar($this->value) ? var_export($this->value, true) : get_debug_type($this->value)
            ));
        }
        $copy = clone $this;
        $copy->integer = true;

        return $copy;
    }

    /**
     * True for an int-typed leaf (asInteger(), Spec::int(); 2.9.0).
     */
    public function isInteger(): bool
    {
        return $this->integer;
    }

    /**
     * The guard of an int-typed leaf, called by the predicate of each operator before it compares: a
     * non-null candidate that is not an int is refused (2.9.0, forward 044, D2). It does nothing for a
     * leaf that is not int-typed.
     *
     * @throws IncompatibleTypeException When the leaf is int-typed and the candidate is not an int
     */
    protected function assertIntegerCandidate(mixed $candidate): void
    {
        if (!$this->integer || $candidate === null || is_int($candidate)) {
            return;
        }
        $rule = (new \ReflectionClass($this))->getShortName();

        throw new IncompatibleTypeException($rule, $candidate, $this->value, sprintf(
            '%s is int-typed and refuses the candidate %s of type %s: only an int is a candidate (a float, even without a fractional part, a numeric text or a boolean is refused); convert the value at the input boundary.',
            $rule,
            is_scalar($candidate) ? var_export($candidate, true) : get_debug_type($candidate),
            get_debug_type($candidate)
        ));
    }

    /**
     * Returns the leaf with the logically inverted operator and the same value (x < 10 → x >= 10).
     * A subclass that redefines isSatisfiedBy() (LooseEqualSpecification, a user leaf) is not the
     * inverse of the operator's leaf: its negation is returned as a NotSpecification (2.1.0; up to 2.0.0
     * ¬looselyEqualTo('1') became notEqualTo('1'), which accepts 1).
     *
     * @return ISpecification<T>
     */
    public function invert(): ISpecification
    {
        if (!self::followsItsOperator($this)) {
            return new NotSpecification($this);
        }

        $inverted = static::createFor($this->getRelationalOperator()->getInvertedBinaryRelation(), $this->value);

        // The inversion of an int-typed leaf is int-typed (¬int(x < 10) ≡ int(x >= 10), 2.9.0)
        return $this->integer && $inverted instanceof self ? $inverted->asInteger() : $inverted;
    }

    /**
     * {@inheritdoc}
     */
    public function isGeneralizationOf(ISpecification $otherSpecification): bool
    {
        if ($this->checkBaseGeneralization($otherSpecification)) {
            return true;
        }
        $other = SpecificationAlgebra::resolve($otherSpecification);
        if ($other instanceof self && self::followsItsOperator($this) && self::followsItsOperator($other)
            && (SpecificationAlgebra::refusesNull($other) || !SpecificationAlgebra::refusesNull($this))) {
            return self::leafGeneralizes($this, $other);
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function isDisjointWith(ISpecification $otherSpecification): bool
    {
        if ($this->checkBaseDisjointness($otherSpecification)) {
            return true;
        }
        $other = SpecificationAlgebra::resolve($otherSpecification);
        if ($other instanceof self && self::followsItsOperator($this) && self::followsItsOperator($other)) {
            return self::leafDisjoint($this, $other);
        }
        if ($other instanceof IsNullSpecification && SpecificationAlgebra::evaluatesAs($other, IsNullSpecification::class)) {
            return SpecificationAlgebra::refusesNull($this);
        }

        return false;
    }

    /**
     * True when the leaf evaluates with the predicate of its operator (one of the library leaves), the
     * only predicate the interval rules describe.
     */
    private static function followsItsOperator(self $leaf): bool
    {
        return SpecificationAlgebra::evaluatesAs($leaf, ...SpecificationAlgebra::RELATIONAL_PREDICATES);
    }

    /**
     * True when two bounds are ordered the same way every candidate is ordered against them: numbers,
     * date-times, or two texts neither of which is numeric (PHP compares numeric strings as numbers
     * and the rest byte by byte, which is not one order: '10' > '9' but '1a' < '9' and '1a' > '10').
     */
    private static function boundsOrdered(mixed $a, mixed $b): bool
    {
        if (!TypeCompatibility::isOrderable($a, $b)) {
            return false;
        }

        return !is_string($a) || (!is_numeric($a) && !is_numeric($b));
    }

    /**
     * Factory of the leaf implementing a relational operator for a value.
     *
     * @return ISpecification<mixed>
     */
    public static function createFor(RelationalOperator $operator, mixed $value): ISpecification
    {
        return match ($operator) {
            RelationalOperator::EQUAL => new EqualSpecification($value),
            RelationalOperator::NOT_EQUAL => new NotEqualSpecification($value),
            RelationalOperator::LESS_THAN => new LessThanSpecification($value),
            RelationalOperator::LESS_THAN_OR_EQUAL => new LessThanOrEqualSpecification($value),
            RelationalOperator::GREATER_THAN => new GreaterThanSpecification($value),
            RelationalOperator::GREATER_THAN_OR_EQUAL => new GreaterThanOrEqualSpecification($value),
            RelationalOperator::MUCH_LESS_THAN, RelationalOperator::MUCH_GREATER_THAN =>
                throw new \InvalidArgumentException('No leaf specification implements the operator ' . $operator->value),
        };
    }

    // ==========================================
    // Interval algebra
    // ==========================================

    /**
     * $a ⊇ $b for two relational leaves.
     */
    public static function leafGeneralizes(self $a, self $b): bool
    {
        [$opA, $valA, $opB, $valB] = self::normalize($a, $b);

        if ($opA === RelationalOperator::EQUAL) {
            return $opB === RelationalOperator::EQUAL && TypeCompatibility::sameValue($valA, $valB);
        }
        if ($opA === RelationalOperator::NOT_EQUAL) {
            if ($opB === RelationalOperator::EQUAL || $opB === RelationalOperator::NOT_EQUAL) {
                // x <> a ⊇ x = b ⇔ the points differ; x <> a ⊇ x <> b ⇔ they are the same point
                $same = TypeCompatibility::sameValue($valA, $valB);
                return $opB === RelationalOperator::EQUAL ? !$same : $same;
            }
            if (!TypeCompatibility::isOrderable($valA, $valB)) {
                return false;
            }
            $cmp = $valB <=> $valA; // sign of (valB - valA); A's point is a value, compared as itself
            return match ($opB) {
                RelationalOperator::LESS_THAN => $cmp <= 0,
                RelationalOperator::LESS_THAN_OR_EQUAL => $cmp < 0,
                RelationalOperator::GREATER_THAN => $cmp >= 0,
                RelationalOperator::GREATER_THAN_OR_EQUAL => $cmp > 0,
                default => false,
            };
        }
        if ($opB === RelationalOperator::NOT_EQUAL) {
            return false;
        }
        if (!TypeCompatibility::isOrderable($valA, $valB)) {
            return false;
        }
        $cmp = $valB <=> $valA;
        $lowerA = self::isLowerBound($opA);
        $inclusiveA = self::isInclusive($opA);

        if ($opB !== RelationalOperator::EQUAL && !self::boundsOrdered($valA, $valB)) {
            return false;
        }
        if ($opB === RelationalOperator::EQUAL) {
            return $lowerA
                ? ($cmp > 0 || ($cmp === 0 && $inclusiveA))
                : ($cmp < 0 || ($cmp === 0 && $inclusiveA));
        }
        if ($lowerA !== self::isLowerBound($opB)) {
            return false;
        }
        $inclusiveB = self::isInclusive($opB);
        $tighter = $lowerA ? $cmp > 0 : $cmp < 0;

        return $tighter || ($cmp === 0 && ($inclusiveA || !$inclusiveB));
    }

    /**
     * $a ∩ $b = ∅ for two relational leaves.
     */
    public static function leafDisjoint(self $a, self $b): bool
    {
        [$opA, $valA, $opB, $valB] = self::normalize($a, $b);

        if ($opA === RelationalOperator::NOT_EQUAL || $opB === RelationalOperator::NOT_EQUAL) {
            if ($opA === RelationalOperator::NOT_EQUAL && $opB === RelationalOperator::NOT_EQUAL) {
                return false;
            }
            $other = $opA === RelationalOperator::NOT_EQUAL ? $opB : $opA;
            return $other === RelationalOperator::EQUAL && TypeCompatibility::sameValue($valA, $valB);
        }
        if ($opA === RelationalOperator::EQUAL && $opB === RelationalOperator::EQUAL) {
            if (!TypeCompatibility::isEquatable($valA, $valB) || gettype($valA) !== gettype($valB)) {
                return true;
            }
            return !TypeCompatibility::sameValue($valA, $valB);
        }
        if (!TypeCompatibility::isOrderable($valA, $valB)) {
            return false;
        }
        if ($opA === RelationalOperator::EQUAL || $opB === RelationalOperator::EQUAL) {
            [$point, $op, $bound] = $opA === RelationalOperator::EQUAL ? [$valA, $opB, $valB] : [$valB, $opA, $valA];
            $cmp = $point <=> $bound;
            return self::isLowerBound($op)
                ? ($cmp < 0 || ($cmp === 0 && !self::isInclusive($op)))
                : ($cmp > 0 || ($cmp === 0 && !self::isInclusive($op)));
        }
        if (self::isLowerBound($opA) === self::isLowerBound($opB) || !self::boundsOrdered($valA, $valB)) {
            return false;
        }
        [$lowerOp, $lower, $upperOp, $upper] = self::isLowerBound($opA)
            ? [$opA, $valA, $opB, $valB]
            : [$opB, $valB, $opA, $valA];
        $cmp = $upper <=> $lower;

        return $cmp < 0 || ($cmp === 0 && !(self::isInclusive($lowerOp) && self::isInclusive($upperOp)));
    }

    /**
     * Operators and values of two leaves, as the interval rules read them. Bounds are not closed (x > 10 is
     * not x >= 11) unless BOTH leaves are int-typed: a leaf that is not accepts float candidates, so its
     * domain is dense (2.1.0, forward 037 P2; up to 2.0.0 the closing made x > 10 ⟂ x < 11 although 10.5
     * satisfies both). Two int-typed leaves accept ints only, so `x > a` is `x >= a + 1` and `x < b` is
     * `x <= b - 1` (2.9.0, forward 044, D4); a bound at PHP_INT_MAX/PHP_INT_MIN is left open (a + 1 would be a
     * float), which proves less, never more.
     *
     * @return array{RelationalOperator, mixed, RelationalOperator, mixed}
     */
    private static function normalize(self $a, self $b): array
    {
        if ($a->integer && $b->integer) {
            return [...self::closed($a->getRelationalOperator(), $a->value), ...self::closed($b->getRelationalOperator(), $b->value)];
        }

        return [$a->getRelationalOperator(), $a->value, $b->getRelationalOperator(), $b->value];
    }

    /**
     * The closed form of an int bound: `> a` as `>= a + 1`, `< b` as `<= b - 1`; any other operator, or a
     * bound at the end of the int range, as it is.
     *
     * @return array{RelationalOperator, mixed}
     */
    private static function closed(RelationalOperator $operator, mixed $value): array
    {
        if ($operator === RelationalOperator::GREATER_THAN && is_int($value) && $value < PHP_INT_MAX) {
            return [RelationalOperator::GREATER_THAN_OR_EQUAL, $value + 1];
        }
        if ($operator === RelationalOperator::LESS_THAN && is_int($value) && $value > PHP_INT_MIN) {
            return [RelationalOperator::LESS_THAN_OR_EQUAL, $value - 1];
        }

        return [$operator, $value];
    }

    private static function isLowerBound(RelationalOperator $operator): bool
    {
        return $operator === RelationalOperator::GREATER_THAN || $operator === RelationalOperator::GREATER_THAN_OR_EQUAL;
    }

    private static function isInclusive(RelationalOperator $operator): bool
    {
        return $operator === RelationalOperator::GREATER_THAN_OR_EQUAL || $operator === RelationalOperator::LESS_THAN_OR_EQUAL;
    }

    /**
     * Defensive copy of a mutable reference value (a DateTime); immutable values pass through.
     */
    protected static function copyOf(mixed $value): mixed
    {
        return $value instanceof \DateTime ? clone $value : $value;
    }

    /**
     * {@inheritdoc}
     *
     * The reference value under `v`: `{"t": "eq", "v": 18}` (2.4.0, forward 041; `eq`, `neq`, `gt`, `gte`,
     * `lt`, `lte`, `looseEq` and `at` share this form; a date-time is written as `{"$date": ...}`). An
     * int-typed leaf adds `"int": true`: `{"t": "gte", "v": 12, "int": true}` (2.9.0, forward 044, D6); a
     * leaf that is not int-typed is written as before.
     */
    public function toArray(): array
    {
        return $this->integer ? ['v' => $this->value, 'int' => true] : ['v' => $this->value];
    }

    /**
     * {@inheritdoc}
     *
     * The constructor of the concrete leaf checks the value (an ordered leaf refuses null and booleans);
     * `"int"` is optional and, when present, must be `true` (2.9.0): the leaf is read back int-typed, and
     * asInteger() checks the bound as when it was built.
     */
    public static function fromArray(array $data, SpecificationCodec $codec): static
    {
        $codec->expectKeys($data, ['v'], ['int']);
        $leaf = new static($codec->value($data, 'v'));
        if (!array_key_exists('int', $data)) {
            return $leaf;
        }
        if ($data['int'] !== true) {
            throw $codec->invalid('"int" must be true when present (an int-typed leaf); a leaf that is not int-typed has no "int".');
        }

        return $leaf->asInteger();
    }
}
