<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications;

use Antevemus\ASpecification\AbstractCompositeSpecification;
use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\IValueBoundSpecification;
use Antevemus\ASpecification\Specifications\Collection\AllEntitiesSpecification;
use Antevemus\ASpecification\Specifications\Comparison\AbstractComparableValueBoundSpecification;
use Antevemus\ASpecification\Specifications\Comparison\EqualSpecification;
use Antevemus\ASpecification\Specifications\Comparison\InSpecification;
use Antevemus\ASpecification\Specifications\Comparison\IsNullSpecification;
use Antevemus\ASpecification\Specifications\Comparison\NotNullSpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysFalseSpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysTrueSpecification;
use Antevemus\ASpecification\Specifications\Logical\JointDenialSpecification;

/**
 * SpecificationAlgebra - Shared axioms of the Venn set algebra of specifications.
 *
 * Port of the subsumption/disjointness lemmas of the Domian `AbstractSpecification`,
 * `AbstractCompositeSpecification`, `ConjunctionSpecification`, `DisjunctionSpecification`
 * and `JointDenialSpecification` (Copyright 2006-2010 the original author or authors,
 * Apache License 2.0; see THIRD_PARTY_NOTICES.md), expressed as static helpers so that every
 * specification class applies exactly the same rules:
 *
 * - Reflexivity: A ⊇ A (structural equality, never identity)
 * - Contradiction: A ⊇ ∅ and A ⟂ ∅
 * - Conjunction: X ⊇ (A ∧ B) if X ⊇ A ∨ X ⊇ B; X ⟂ (A ∧ B) if X ⟂ A ∨ X ⟂ B
 * - Disjunction: X ⊇ (A ∨ B) ⇔ X ⊇ A ∧ X ⊇ B; X ⟂ (A ∨ B) ⇔ X ⟂ A ∧ X ⟂ B
 * - Negation: ¬A ⊇ ¬B ⇔ B ⊇ A; A ⟂ ¬B ⇔ B ⊇ A; ¬¬A ≡ A; the negation of a comparison
 *   leaf is the leaf with the inverted relational operator (x < 10 ≡ ¬(x >= 10))
 * - Type hierarchy: a type specification generalizes any specification whose candidates are
 *   bounded by a subtype
 * - Sets (1.5.0): in(V) is the union of equalTo(v) for v ∈ V, so X ⊇ in(V) ⇔ X ⊇ equalTo(v) for
 *   every v and X ⟂ in(V) ⇔ X ⟂ equalTo(v) for every v; in([]) resolves to the contradiction
 *
 * Where the Domian code and the set algebra disagree the algebra wins; the deviations are
 * documented in docs/paridade-domian-2026-10-09 (lote 1.4.4).
 *
 * @version    1.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class SpecificationAlgebra
{
    private function __construct()
    {
    }

    // ==========================================
    // 1. Negation resolution and inversion
    // ==========================================

    /**
     * Resolves negations to their simplest equivalent form: ¬¬A becomes A, the negation of an
     * invertible specification (comparison leaf, null check, tautology, contradiction, or a
     * composite of those) becomes its inverse, and a joint denial becomes ¬(A ∨ B).
     * Irreducible negations (¬P with P not invertible) are returned as a NotSpecification.
     *
     * @param ISpecification<mixed> $specification
     * @return ISpecification<mixed>
     */
    public static function resolve(ISpecification $specification): ISpecification
    {
        if ($specification instanceof InSpecification && $specification->isEmpty()) {
            // in([]) is the contradiction (RN-07)
            return new AlwaysFalseSpecification();
        }
        if ($specification instanceof JointDenialSpecification) {
            return self::resolve(new NotSpecification(new OrSpecification(
                $specification->getLeftSide(),
                $specification->getRightSide()
            )));
        }

        if (!$specification instanceof NotSpecification) {
            return $specification;
        }

        $inner = $specification->getSpecification();
        $inverted = self::invert($inner);
        if ($inverted !== null) {
            return self::resolve($inverted);
        }

        $resolvedInner = self::resolve($inner);
        if ($resolvedInner instanceof NotSpecification) {
            // ¬¬P with P irreducible
            return $resolvedInner->getSpecification();
        }

        return $resolvedInner === $inner ? $specification : new NotSpecification($resolvedInner);
    }

    /**
     * Returns the logical inverse of a specification when it can be expressed without a NOT
     * wrapper, null otherwise.
     *
     * @param ISpecification<mixed> $specification
     * @return ISpecification<mixed>|null
     */
    public static function invert(ISpecification $specification): ?ISpecification
    {
        if ($specification instanceof NotSpecification) {
            return $specification->getSpecification();
        }
        if ($specification instanceof JointDenialSpecification) {
            return new OrSpecification($specification->getLeftSide(), $specification->getRightSide());
        }
        if ($specification instanceof AbstractComparableValueBoundSpecification) {
            return $specification->invert();
        }
        if ($specification instanceof IsNullSpecification) {
            return new NotNullSpecification();
        }
        if ($specification instanceof NotNullSpecification) {
            return new IsNullSpecification();
        }
        if ($specification instanceof AlwaysTrueSpecification) {
            return new AlwaysFalseSpecification($specification->getType());
        }
        if ($specification instanceof AlwaysFalseSpecification) {
            return new AlwaysTrueSpecification($specification->getType());
        }
        if ($specification instanceof InSpecification && $specification->isEmpty()) {
            return new AlwaysTrueSpecification();
        }
        if ($specification instanceof AndSpecification || $specification instanceof OrSpecification) {
            $left = self::invert($specification->getLeftSide());
            $right = self::invert($specification->getRightSide());
            if ($left === null || $right === null) {
                return null;
            }
            // De Morgan
            return $specification instanceof AndSpecification
                ? new OrSpecification($left, $right)
                : new AndSpecification($left, $right);
        }

        return null;
    }

    // ==========================================
    // 2. Base axioms
    // ==========================================

    /**
     * Base generalization axioms ($this ⊇ $other) shared by every specification class.
     * Returns true when an axiom proves the subsumption; false means "not proven here",
     * and the caller applies its own class-specific rules.
     *
     * @param ISpecification<mixed> $this_
     * @param ISpecification<mixed> $other
     * @return bool
     */
    public static function baseGeneralizes(ISpecification $this_, ISpecification $other): bool
    {
        $other = self::resolve($other);

        if ($this_ === $other || $this_->equals($other)) {
            return true;
        }
        if ($other instanceof AlwaysFalseSpecification) {
            return true;
        }
        if ($other instanceof OrSpecification) {
            // X ⊇ (A ∨ B) ⇔ X ⊇ A ∧ X ⊇ B
            return $this_->isGeneralizationOf($other->getLeftSide())
                && $this_->isGeneralizationOf($other->getRightSide());
        }
        if ($other instanceof AndSpecification && !($this_ instanceof AndSpecification)) {
            // X ⊇ (A ∧ B) if X ⊇ A ∨ X ⊇ B
            return $this_->isGeneralizationOf($other->getLeftSide())
                || $this_->isGeneralizationOf($other->getRightSide());
        }
        if ($other instanceof InSpecification) {
            // X ⊇ in(V) ⇔ X ⊇ equalTo(v) for every v ∈ V (in(V) is the union of the equalities)
            foreach ($other->getValues() as $value) {
                if (!$this_->isGeneralizationOf(new EqualSpecification($value))) {
                    return false;
                }
            }
            return true;
        }

        return false;
    }

    /**
     * Base disjointness axioms ($this ∩ $other = ∅) shared by every specification class.
     *
     * @param ISpecification<mixed> $this_
     * @param ISpecification<mixed> $other
     * @return bool
     */
    public static function baseDisjoint(ISpecification $this_, ISpecification $other): bool
    {
        $other = self::resolve($other);

        if ($this_ === $other || $this_->equals($other)) {
            return false;
        }
        if ($this_ instanceof AlwaysFalseSpecification || $other instanceof AlwaysFalseSpecification) {
            return true;
        }
        if ($other instanceof NotSpecification) {
            // A ⟂ ¬B ⇔ A ⊆ B
            return $other->getSpecification()->isGeneralizationOf($this_);
        }
        if ($other instanceof AndSpecification) {
            return $this_->isDisjointWith($other->getLeftSide())
                || $this_->isDisjointWith($other->getRightSide());
        }
        if ($other instanceof OrSpecification) {
            return $this_->isDisjointWith($other->getLeftSide())
                && $this_->isDisjointWith($other->getRightSide());
        }
        if ($other instanceof InSpecification) {
            // X ⟂ in(V) ⇔ X ⟂ equalTo(v) for every v ∈ V
            foreach ($other->getValues() as $value) {
                if (!$this_->isDisjointWith(new EqualSpecification($value))) {
                    return false;
                }
            }
            return true;
        }

        return false;
    }

    // ==========================================
    // 3. Types
    // ==========================================

    /**
     * True when every candidate satisfying $specification is necessarily an instance of $type
     * (or $type is the top type). Negations are never bounded: ¬A accepts anything outside A.
     *
     * @param ISpecification<mixed> $specification
     * @param string $type
     * @return bool
     */
    public static function isBoundedByType(ISpecification $specification, string $type): bool
    {
        if (self::isTopType($type)) {
            return true;
        }

        $specification = self::resolve($specification);

        if ($specification instanceof AlwaysFalseSpecification) {
            return true;
        }
        if ($specification instanceof NotSpecification) {
            return false;
        }
        if ($specification instanceof AndSpecification) {
            return self::isBoundedByType($specification->getLeftSide(), $type)
                || self::isBoundedByType($specification->getRightSide(), $type);
        }
        if ($specification instanceof OrSpecification) {
            return self::isBoundedByType($specification->getLeftSide(), $type)
                && self::isBoundedByType($specification->getRightSide(), $type);
        }
        if ($specification instanceof PropertySpecification) {
            return self::isBoundedByType($specification->getBaseSpecification(), $type);
        }
        if ($specification instanceof InSpecification) {
            foreach ($specification->getValues() as $value) {
                if (!is_object($value) || !$value instanceof $type) {
                    return false;
                }
            }
            return true;
        }
        if ($specification instanceof AllEntitiesSpecification || $specification instanceof AbstractCompositeSpecification) {
            return self::canCastFromTo($specification->getType(), $type);
        }
        if ($specification instanceof IValueBoundSpecification) {
            $value = $specification->getValue();
            return is_object($value) && self::isEqualityLeaf($specification) && $value instanceof $type;
        }

        return false;
    }

    /**
     * True when a value of $fromType can be used where $toType is expected. The pseudo-types
     * 'mixed' and 'object' are treated as top types on the destination side; an unknown source
     * type ('mixed') never casts to a concrete class.
     */
    public static function canCastFromTo(string $fromType, string $toType): bool
    {
        if ($fromType === $toType) {
            return true;
        }
        if (self::isTopType($toType)) {
            return $toType !== 'object' || self::isClassLike($fromType) || $fromType === 'object';
        }
        if (!self::isClassLike($fromType) || !self::isClassLike($toType)) {
            return false;
        }

        return is_subclass_of($fromType, $toType);
    }

    /**
     * True when the two types can be cast in at least one direction.
     */
    public static function canCastAtLeastOneWay(string $type1, string $type2): bool
    {
        return self::canCastFromTo($type1, $type2) || self::canCastFromTo($type2, $type1);
    }

    /**
     * True when the type is a declared class or interface.
     */
    public static function isClassLike(string $type): bool
    {
        return $type !== '' && (class_exists($type) || interface_exists($type));
    }

    /**
     * True for the pseudo-types that accept any candidate.
     */
    public static function isTopType(string $type): bool
    {
        return $type === '' || $type === 'mixed' || $type === 'object';
    }

    /**
     * True when the leaf tests equality of a value (not a range, not a difference).
     *
     * @param ISpecification<mixed> $specification
     */
    private static function isEqualityLeaf(ISpecification $specification): bool
    {
        return $specification instanceof Comparison\EqualSpecification;
    }

    // ==========================================
    // 4. Structural helpers
    // ==========================================

    /**
     * True when the two unordered pairs hold structurally equal specifications.
     *
     * @param ISpecification<mixed> $a1
     * @param ISpecification<mixed> $a2
     * @param ISpecification<mixed> $b1
     * @param ISpecification<mixed> $b2
     */
    public static function unorderedPairsEqual(ISpecification $a1, ISpecification $a2, ISpecification $b1, ISpecification $b2): bool
    {
        return ($a1->equals($b1) && $a2->equals($b2))
            || ($a1->equals($b2) && $a2->equals($b1));
    }

    /**
     * True when the two lists hold structurally equal specifications regardless of order.
     *
     * @param array<ISpecification<mixed>> $a
     * @param array<ISpecification<mixed>> $b
     */
    public static function unorderedListsEqual(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }
        $remaining = array_values($b);
        foreach ($a as $spec) {
            $found = false;
            foreach ($remaining as $index => $candidate) {
                if ($spec->equals($candidate)) {
                    unset($remaining[$index]);
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return false;
            }
        }

        return true;
    }

    /**
     * True when the specification is a composite in the structural sense (has children).
     *
     * @param ISpecification<mixed> $specification
     */
    public static function isComposite(ISpecification $specification): bool
    {
        return $specification instanceof ICompositeSpecification;
    }
}
