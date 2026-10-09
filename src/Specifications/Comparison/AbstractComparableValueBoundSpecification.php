<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Comparison;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\IValueBoundSpecification;
use Antevemus\ASpecification\Specifications\SpecificationAlgebra;
use Antevemus\ASpecification\Specifications\SubsumptionAndEqualityTrait;
use DateTimeInterface;

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
 * - `x < 5 ⟂ x >= 5`, `x = 5 ⟂ x = 6`, `x = 5 ⟂ x <> 5`, and for integer bounds `x > 10 ⟂ x < 11`
 *   (there is no integer between 10 and 11; a decimal or date domain is dense)
 * - the negation of a leaf is the leaf with the inverted operator (`¬(x < 10) ≡ x >= 10`)
 *
 * Values are compared with the PHP comparison operators, so integers, floats, strings and
 * `DateTimeInterface` instants are all supported; mixed types are never compared (the algebra
 * answers false, evaluation throws IncompatibleTypeException). A mutable `DateTime` reference
 * value is cloned on construction so the specification cannot change after creation.
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @implements IValueBoundSpecification<T>
 * @version    1.4.4
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Comparison
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class AbstractComparableValueBoundSpecification extends AbstractSpecification implements IValueBoundSpecification
{
    use SubsumptionAndEqualityTrait;

    /**
     * Reference value (right operand of the relation).
     */
    protected readonly mixed $value;

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
     * A leaf bound to a date is a specification of DateTimeInterface; any other leaf accepts
     * whatever type its value has ('mixed'), the type guard being applied at evaluation time.
     */
    public function getType(): string
    {
        return $this->value instanceof DateTimeInterface ? DateTimeInterface::class : 'mixed';
    }

    /**
     * Returns the leaf with the logically inverted operator and the same value (x < 10 → x >= 10).
     *
     * @return ISpecification<T>
     */
    public function invert(): ISpecification
    {
        return static::createFor($this->getRelationalOperator()->getInvertedBinaryRelation(), $this->value);
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
        if ($other instanceof self) {
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
        if ($other instanceof self) {
            return self::leafDisjoint($this, $other);
        }
        if ($other instanceof IsNullSpecification) {
            return $this->value !== null;
        }

        return false;
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
            return $opB === RelationalOperator::EQUAL && self::valuesEqual($valA, $valB);
        }
        if ($opA === RelationalOperator::NOT_EQUAL) {
            if ($opB === RelationalOperator::EQUAL || $opB === RelationalOperator::NOT_EQUAL) {
                $equal = self::valuesEqual($valA, $valB);
                return $opB === RelationalOperator::EQUAL ? !$equal : $equal;
            }
            if (!TypeCompatibility::isOrderable($valA, $valB)) {
                return false;
            }
            $cmp = $valB <=> $valA; // sign of (valB - valA)
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
            return $other === RelationalOperator::EQUAL && self::valuesEqual($valA, $valB);
        }
        if ($opA === RelationalOperator::EQUAL && $opB === RelationalOperator::EQUAL) {
            if (!TypeCompatibility::isEquatable($valA, $valB) || gettype($valA) !== gettype($valB)) {
                return true;
            }
            return !self::valuesEqual($valA, $valB);
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
        if (self::isLowerBound($opA) === self::isLowerBound($opB)) {
            return false;
        }
        [$lowerOp, $lower, $upperOp, $upper] = self::isLowerBound($opA)
            ? [$opA, $valA, $opB, $valB]
            : [$opB, $valB, $opA, $valA];
        $cmp = $upper <=> $lower;

        return $cmp < 0 || ($cmp === 0 && !(self::isInclusive($lowerOp) && self::isInclusive($upperOp)));
    }

    /**
     * Normalizes integer-bound strict relations to inclusive ones (x > 10 ≡ x >= 11), so the
     * integer gap is accounted for by the generic rules.
     *
     * @return array{RelationalOperator, mixed, RelationalOperator, mixed}
     */
    private static function normalize(self $a, self $b): array
    {
        $opA = $a->getRelationalOperator();
        $opB = $b->getRelationalOperator();
        $valA = $a->value;
        $valB = $b->value;

        if (is_int($valA) && is_int($valB)) {
            [$opA, $valA] = self::closeIntegerBound($opA, $valA);
            [$opB, $valB] = self::closeIntegerBound($opB, $valB);
        }

        return [$opA, $valA, $opB, $valB];
    }

    /**
     * @return array{RelationalOperator, int}
     */
    private static function closeIntegerBound(RelationalOperator $operator, int $value): array
    {
        return match ($operator) {
            RelationalOperator::GREATER_THAN => [RelationalOperator::GREATER_THAN_OR_EQUAL, $value + 1],
            RelationalOperator::LESS_THAN => [RelationalOperator::LESS_THAN_OR_EQUAL, $value - 1],
            default => [$operator, $value],
        };
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
     * Value equality as the algebra sees it: date-times by instant, everything else strictly.
     */
    protected static function valuesEqual(mixed $a, mixed $b): bool
    {
        if ($a instanceof DateTimeInterface && $b instanceof DateTimeInterface) {
            return $a == $b;
        }

        return $a === $b;
    }

    /**
     * Defensive copy of a mutable reference value (a DateTime); immutable values pass through.
     */
    protected static function copyOf(mixed $value): mixed
    {
        return $value instanceof \DateTime ? clone $value : $value;
    }
}
