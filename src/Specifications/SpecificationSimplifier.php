<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Engine\RuleBoundSpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysFalseSpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysTrueSpecification;
use Antevemus\ASpecification\Specifications\Logical\JointDenialSpecification;

/**
 * SpecificationSimplifier - Returns an equivalent, smaller specification tree (1.9.0).
 *
 * Features:
 * - Static simplify(ISpecification): ISpecification (shortcuts: AbstractSpecification::simplify(),
 *   Spec::simplify(); opt-in in DynamicSpecificationEngine::withSimplification())
 * - Boolean rewrite rules of forward 025 D2, bottom-up to a fixed point
 * - Annotation preservation (D3), truth-value equivalence (D4), immutability and idempotence (D5)
 *
 * Rewrites a specification bottom-up, to a fixed point, with the rules below. Every rule is
 * applied to the flattened operand list of a conjunction or disjunction chain (the operands of
 * nested AND/OR nodes of the same kind, without annotation, are one list) and the chain is
 * rebuilt left-deep, in the original operand order.
 *
 * Rules (forward 025, D2):
 * - Identity: A ∧ true = A, A ∨ false = A
 * - Annihilation: A ∧ false = false, A ∨ true = true (a special case of the next rule)
 * - Contradictory conjunction → alwaysFalse(), tautological disjunction → alwaysTrue(), by the
 *   structural detection of 1.6.0 (isContradictoryConjunction / isTautologicalDisjunction)
 * - Double negation: ¬¬A = A
 * - Idempotence: A ∧ A = A, A ∨ A = A, by equals()
 * - Flattening of nested AND/OR nodes of the same kind
 * - Subsumption: A ∧ B = A and A ∨ B = B when A is a special case of B (1.4.4 algebra)
 *
 * Annotations (D3): a node carrying because() or withCode() is a unit. It is simplified inside
 * (it reports a single failure, so its interior is not visible), but it is never removed, merged
 * into another node or replaced by alwaysTrue()/alwaysFalse(), unless the very same annotation is
 * on both sides (idempotence). An annotated AND/OR keeps at least two operands. A subtree is only
 * dropped when it holds no annotation at all; a double negation is only removed when the negated
 * subtree holds none (removing it would expose failures the negation hid). The verdict and the
 * annotated messages and codes reported by evaluate() are therefore unchanged.
 *
 * Equivalence (D4): the result is guaranteed to have the same truth value (isSatisfiedBy) as the
 * input for every non-null candidate. Nothing else is promised: removing a redundant branch may
 * remove a branch that would have raised an exception, and unannotated default failure messages may
 * differ. The reverse never happens (2.1.0, forward 037, bugs #69 and #71): AND/OR evaluate left to
 * right and stop at the first deciding operand, so subsumption removes an operand that comes before
 * the operand that stays only when the operands it would expose raise only where it raised (read
 * from the type guards of the leaves, never by evaluating), and the absorbing constant takes the
 * place of the first removed operand; the result raises only where the input raised. A null candidate is rejected by every AND/OR/NOT node, whatever its operands; when the
 * root of the tree is simplified away the remaining node answers for null on its own (for example
 * alwaysTrue() accepts it), as isTautology()/isContradiction() already assume non-null candidates.
 * A property value, on the other hand, may be null (1.11.0: it reaches the inner specification), so
 * the inside of a property restriction is rewritten only when the rewrite keeps the answer for a null
 * value.
 *
 * Unknown specification types are never guessed: AND, OR, NOT, NOR (JointDenialSpecification),
 * property restrictions (PropertySpecification) and rule bindings (RuleBoundSpecification) are
 * simplified inside; any other node, including subclasses of those, is kept as it is. A node whose
 * evaluate() is not the default one (and that is not one of the classes above) is never dropped.
 *
 * Immutability (D5): the input is never modified, unchanged subtrees are shared, and
 * simplify(simplify(x)) returns simplify(x) itself. The rewrite never adds nodes.
 *
 * Automatic use (D6): no visitor and no engine simplifies by itself; the rule engine offers it
 * as an opt-in (DynamicSpecificationEngine::withSimplification()).
 *
 * @version    2.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class SpecificationSimplifier
{
    /**
     * Upper bound of whole-tree passes. Every rule removes nodes, so the fixed point is reached in
     * very few passes; the bound only protects against a misbehaving external equals().
     */
    private const MAX_PASSES = 64;

    private static ?\ReflectionProperty $reasonProperty = null;
    private static ?\ReflectionProperty $codeProperty = null;

    /** @var array<class-string, bool> */
    private static array $defaultEvaluateCache = [];

    private function __construct()
    {
    }

    /**
     * Returns an equivalent specification with the redundancies removed (see the class docblock).
     *
     * @param ISpecification<mixed> $specification Specification to simplify (never modified)
     * @return ISpecification<mixed> The simplified specification, or the input itself when nothing applies
     */
    public static function simplify(ISpecification $specification): ISpecification
    {
        $current = $specification;
        for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
            $next = self::simplifyNode($current);
            if ($next === $current) {
                return $current;
            }
            $current = $next;
        }

        return $current;
    }

    // ==========================================
    // 1. Node dispatch
    // ==========================================

    /**
     * One bottom-up pass over a node. Returns the node itself when nothing changed.
     *
     * @param ISpecification<mixed> $node
     * @return ISpecification<mixed>
     */
    private static function simplifyNode(ISpecification $node): ISpecification
    {
        return match ($node::class) {
            AndSpecification::class, OrSpecification::class => self::simplifyChain($node),
            NotSpecification::class => self::simplifyNegation($node),
            PropertySpecification::class => self::simplifyProperty($node),
            JointDenialSpecification::class => self::simplifyJointDenial($node),
            RuleBoundSpecification::class => self::simplifyRuleBinding($node),
            default => $node,
        };
    }

    /**
     * ¬¬A = A when neither negation is annotated and A holds no annotation; otherwise the negated
     * specification is simplified inside.
     */
    private static function simplifyNegation(NotSpecification $node): ISpecification
    {
        $inner = $node->getSpecification();
        $simplifiedInner = self::simplifyNode($inner);

        if (
            !self::isAnnotated($node)
            && $simplifiedInner::class === NotSpecification::class
            && !self::isAnnotated($simplifiedInner)
            && self::isDisposable($simplifiedInner->getSpecification())
        ) {
            return $simplifiedInner->getSpecification();
        }

        if ($simplifiedInner === $inner) {
            return $node;
        }

        return self::copyAnnotations($node, new NotSpecification($simplifiedInner));
    }

    /**
     * A property restriction is simplified inside: its base and its property specification.
     */
    private static function simplifyProperty(PropertySpecification $node): ISpecification
    {
        $base = $node->getBaseSpecification();
        $inner = $node->getPropertySpecification();
        $simplifiedBase = self::simplifyNode($base);
        $simplifiedInner = self::simplifyNode($inner);

        // Since 1.11.0 a null property value reaches the inner specification, while the rules here
        // assume non-null candidates: a rewrite that changes the answer for null (an AND/OR/NOT node
        // rejects null, the alwaysTrue() or isNull() left in its place accepts it) is not applied
        if ($simplifiedInner !== $inner
            && PropertySpecification::nullValueAnswer($simplifiedInner) !== PropertySpecification::nullValueAnswer($inner)) {
            $simplifiedInner = $inner;
        }

        if ($simplifiedBase === $base && $simplifiedInner === $inner) {
            return $node;
        }

        return self::copyAnnotations(
            $node,
            new PropertySpecification($simplifiedBase, $node->getPropertyName(), $simplifiedInner)
        );
    }

    /**
     * A joint denial (NOR) is simplified inside, operand by operand.
     */
    private static function simplifyJointDenial(JointDenialSpecification $node): ISpecification
    {
        $left = $node->getLeftSide();
        $right = $node->getRightSide();
        $simplifiedLeft = self::simplifyNode($left);
        $simplifiedRight = self::simplifyNode($right);

        if ($simplifiedLeft === $left && $simplifiedRight === $right) {
            return $node;
        }

        return self::copyAnnotations($node, new JointDenialSpecification($simplifiedLeft, $simplifiedRight));
    }

    /**
     * A rule binding is simplified inside: the binding (and the rule data it stamps) is kept.
     */
    private static function simplifyRuleBinding(RuleBoundSpecification $node): ISpecification
    {
        $inner = $node->getInnerSpecification();
        $simplifiedInner = self::simplifyNode($inner);

        if ($simplifiedInner === $inner) {
            return $node;
        }

        return self::copyAnnotations($node, new RuleBoundSpecification($simplifiedInner, $node->getRule()));
    }

    // ==========================================
    // 2. Conjunction and disjunction chains
    // ==========================================

    /**
     * Simplifies an AND or OR chain: flattening, operand simplification, chain rules and a
     * left-deep rebuild that keeps the chain's own annotation on its root.
     */
    private static function simplifyChain(AndSpecification|OrSpecification $node): ISpecification
    {
        $class = $node::class;
        $annotated = self::isAnnotated($node);

        $operands = [];
        foreach (self::flatten($node, $class) as $operand) {
            $simplified = self::simplifyNode($operand);
            // An operand that simplified into an unannotated chain of the same kind joins this one.
            foreach (self::flatten($simplified, $class, false) as $flattened) {
                $operands[] = $flattened;
            }
        }

        $operands = self::applyChainRules($operands, $class === AndSpecification::class, $annotated, $node->getType());

        if (self::isLeftDeepChainOf($node, $operands)) {
            return $node;
        }
        if (count($operands) === 1) {
            // Only reached for an unannotated chain: an annotated one keeps two operands.
            return $operands[0];
        }

        $rebuilt = $operands[0];
        for ($i = 1, $n = count($operands); $i < $n; $i++) {
            $rebuilt = new $class($rebuilt, $operands[$i]);
        }

        return self::copyAnnotations($node, $rebuilt);
    }

    /**
     * Operands of a chain: the node itself is opened, and so is every nested node of the same
     * class without annotation. Any other node is one operand.
     *
     * @param ISpecification<mixed> $node
     * @param class-string $class
     * @return list<ISpecification<mixed>>
     */
    private static function flatten(ISpecification $node, string $class, bool $root = true): array
    {
        if ($node::class !== $class || (!$root && self::isAnnotated($node))) {
            return [$node];
        }

        /** @var AndSpecification|OrSpecification $node */
        return array_merge(
            self::flatten($node->getLeftSide(), $class, false),
            self::flatten($node->getRightSide(), $class, false)
        );
    }

    /**
     * Applies the chain rules to a local fixed point.
     *
     * @param list<ISpecification<mixed>> $operands
     * @return list<ISpecification<mixed>>
     */
    private static function applyChainRules(array $operands, bool $isConjunction, bool $annotated, string $type): array
    {
        // An annotated chain is a unit: it is never collapsed into one of its operands.
        $minimum = $annotated ? 2 : 1;

        do {
            $changed = false;

            // Contradictory conjunction → false; tautological disjunction → true. The operands that
            // hold annotations stay (D3); the others are replaced by the absorbing constant.
            if (count($operands) >= 2 && self::provesAbsorption($operands, $isConjunction)) {
                $kept = self::absorb($operands, $isConjunction, $type);
                if (count($kept) >= $minimum && self::countNodesOf($kept) < self::countNodesOf($operands)) {
                    $operands = $kept;
                    $changed = true;
                    continue;
                }
            }

            // Identity: A ∧ true = A, A ∨ false = A.
            $neutral = $isConjunction ? AlwaysTrueSpecification::class : AlwaysFalseSpecification::class;
            foreach ($operands as $index => $operand) {
                if (count($operands) > $minimum && $operand::class === $neutral && !self::isAnnotated($operand)) {
                    unset($operands[$index]);
                    $operands = array_values($operands);
                    $changed = true;
                    continue 2;
                }
            }

            // Idempotence: A ∧ A = A, A ∨ A = A (the later occurrence is dropped).
            $count = count($operands);
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($count > $minimum && self::isDuplicate($operands[$i], $operands[$j])) {
                        unset($operands[$j]);
                        $operands = array_values($operands);
                        $changed = true;
                        continue 3;
                    }
                }
            }

            // Subsumption: A ∧ B = A and A ∨ B = B when A ⊆ B (2.1.0, bug #71: never trading an answer for
            // an error). AND/OR stop at the first deciding operand. When the operand that stays (the
            // witness) comes first, it answers wherever the chain answers, and dropping the later one
            // changes nothing but the errors it could raise. When the redundant operand comes first, it
            // may decide alone (A true in A ∨ B, B false in B ∧ A); without it, the operands up to the
            // witness are evaluated on those candidates, so they must not raise where it did not
            // (raisesOnlyWhere()): greaterThan(6) ∨ notEqual(1) answers 9.5 at greaterThan(6), and
            // notEqual(1) raises on a float.
            for ($i = 0; $i < $count; $i++) {
                for ($j = 0; $j < $count; $j++) {
                    if ($i === $j || $count <= $minimum || !self::isSpecialCase($operands[$i], $operands[$j])) {
                        continue;
                    }
                    // Conjunction: the general operand (B) is redundant; disjunction: the special one (A).
                    $redundant = $isConjunction ? $j : $i;
                    $witness = $isConjunction ? $i : $j;
                    if ($witness > $redundant && !self::laterOperandsRaiseOnlyWhere($operands, $redundant, $witness)) {
                        continue;
                    }
                    if (self::isDisposable($operands[$redundant])) {
                        unset($operands[$redundant]);
                        $operands = array_values($operands);
                        $changed = true;
                        continue 3;
                    }
                }
            }
        } while ($changed);

        return $operands;
    }

    /**
     * The operands of a chain proven contradictory (conjunction) or tautological (disjunction), with the
     * disposable ones replaced by the absorbing constant, which takes the place of the first operand
     * removed (2.1.0, bug #69). The operands before it are evaluated as in the original chain, and the
     * constant decides before any later operand is reached, so the result never raises where the
     * original answered: up to 2.0.0 the constant went last, after the kept annotated operands, which
     * the original might never have reached (isNull() ∧ equalTo(5) ∧ at(d).withCode(C) answered false
     * at isNull() for `true`; the simplified at(d) ∧ false raised). No constant is added when a kept
     * operand that absorbs the chain by itself comes before the first removed one.
     *
     * @param list<ISpecification<mixed>> $operands
     * @return list<ISpecification<mixed>>
     */
    private static function absorb(array $operands, bool $isConjunction, string $type): array
    {
        $kept = [];
        $constantPlaced = false;
        foreach ($operands as $operand) {
            if (!self::isDisposable($operand)) {
                $kept[] = $operand;
                if (!$constantPlaced && self::isAbsorbing($operand, $isConjunction)) {
                    // A kept absorbing operand decides here, before anything removed: no constant needed
                    $constantPlaced = true;
                }
                continue;
            }
            if (!$constantPlaced) {
                $kept[] = $isConjunction ? new AlwaysFalseSpecification($type) : new AlwaysTrueSpecification($type);
                $constantPlaced = true;
            }
        }

        return $kept;
    }

    // ==========================================
    // 2a. Where an operand may raise (2.1.0, bug #71)
    // ==========================================

    /**
     * Kinds of non-null candidate, as the type guards of the leaves tell them apart. Null needs no kind:
     * an AND/OR/NOR node refuses it before evaluating any operand.
     */
    private const EVERY_KIND = ['int', 'float', 'string', 'bool', 'array', 'date', 'object'];

    /**
     * True when every operand after $redundant up to $witness answers (never raises) on every candidate
     * the redundant operand answers: dropping the redundant one then never makes the chain raise where
     * it answered.
     *
     * @param list<ISpecification<mixed>> $operands
     */
    private static function laterOperandsRaiseOnlyWhere(array $operands, int $redundant, int $witness): bool
    {
        for ($k = $redundant + 1; $k <= $witness; $k++) {
            if (!self::raisesOnlyWhere($operands[$k], $operands[$redundant])) {
                return false;
            }
        }

        return true;
    }

    /**
     * True when $later raises only on candidates where $earlier raises too, decided from the structure
     * (never by evaluating): the kinds of candidate each one answers without raising
     * (answeringKinds()), or, for two restrictions of the same property over the same base, the kinds
     * of value their inner specifications answer.
     *
     * @param ISpecification<mixed> $later
     * @param ISpecification<mixed> $earlier
     */
    private static function raisesOnlyWhere(ISpecification $later, ISpecification $earlier): bool
    {
        $laterKinds = self::answeringKinds($later);
        if ($laterKinds !== null && array_diff(self::EVERY_KIND, $laterKinds) === []) {
            return true;
        }
        if ($later::class === PropertySpecification::class && $earlier::class === PropertySpecification::class
            && $later->getPropertyName() === $earlier->getPropertyName()) {
            try {
                $sameBase = $later->getBaseSpecification() === $earlier->getBaseSpecification()
                    || $later->getBaseSpecification()->equals($earlier->getBaseSpecification());
            } catch (\Throwable) {
                $sameBase = false;
            }
            if ($sameBase) {
                return self::raisesOnlyWhere($later->getPropertySpecification(), $earlier->getPropertySpecification());
            }
        }
        $earlierKinds = self::answeringKinds($earlier);
        if ($laterKinds === null || $earlierKinds === null) {
            return false;
        }

        return array_diff($earlierKinds, $laterKinds) === [];
    }

    /**
     * The kinds of non-null candidate (EVERY_KIND) a specification answers without raising, read from its
     * structure; null when unknown (a class whose evaluation is not known here, a subclass that
     * redefines isSatisfiedBy(), a property restriction, a method call). The type guards of the
     * comparison leaves are the main source of errors: equalTo(1)/notEqual(1) raise on a float, a
     * relational leaf on a value of another family, at() on anything but a date-time.
     *
     * @param ISpecification<mixed> $specification
     * @return list<string>|null
     */
    private static function answeringKinds(ISpecification $specification): ?array
    {
        $as = static fn(string ...$classes): bool => SpecificationAlgebra::evaluatesAs($specification, ...$classes);

        if ($as(
            Comparison\IsNullSpecification::class,
            Comparison\NotNullSpecification::class,
            AlwaysTrueSpecification::class,
            AlwaysFalseSpecification::class,
            String\RegexSpecification::class,
            String\LiteralPatternSpecification::class,
            String\WildcardSpecification::class,
            String\WildcardExpressionMatcherIgnoreCaseStringSpecification::class,
            String\EqualIgnoreCaseStringSpecification::class,
            String\EqualIgnoreCaseAndAccentsStringSpecification::class,
            String\DateStringSpecification::class,
            String\EnumNameStringSpecification::class
        )) {
            return self::EVERY_KIND;
        }
        if ($as(Comparison\EqualSpecification::class, Comparison\NotEqualSpecification::class) && $specification instanceof Comparison\AbstractComparableValueBoundSpecification) {
            return self::kindsEquatableWith($specification->getValue());
        }
        if ($as(Comparison\SameInstantSpecification::class)) {
            return ['date'];
        }
        if ($as(
            Comparison\LessThanSpecification::class,
            Comparison\LessThanOrEqualSpecification::class,
            Comparison\GreaterThanSpecification::class,
            Comparison\GreaterThanOrEqualSpecification::class
        ) && $specification instanceof Comparison\AbstractComparableValueBoundSpecification) {
            $value = $specification->getValue();
            return match (true) {
                is_int($value), is_float($value) => ['int', 'float'],
                is_string($value) => ['string'],
                $value instanceof \DateTimeInterface => ['date'],
                default => [],
            };
        }
        if ($as(Comparison\InSpecification::class) && $specification instanceof Comparison\InSpecification) {
            if ($specification->getValues() === []) {
                return self::EVERY_KIND;
            }
            $kinds = [];
            foreach ($specification->getValues() as $value) {
                $kinds = array_merge($kinds, self::kindsEquatableWith($value));
            }
            return $kinds;
        }
        if ($as(NotSpecification::class) && $specification instanceof NotSpecification) {
            return self::answeringKinds($specification->getSpecification());
        }
        if ($as(AndSpecification::class, OrSpecification::class, JointDenialSpecification::class)
            && ($specification instanceof AndSpecification || $specification instanceof OrSpecification || $specification instanceof JointDenialSpecification)) {
            $left = self::answeringKinds($specification->getLeftSide());
            $right = self::answeringKinds($specification->getRightSide());
            if ($left === null || $right === null) {
                return null;
            }
            // Both operands may be evaluated
            return array_values(array_intersect($left, $right));
        }

        return null;
    }

    /**
     * Kinds of candidate a strict equality with $value accepts without raising (TypeCompatibility::
     * isEquatable()): every kind for null, objects for an object, arrays for an array, the same
     * scalar type for a scalar.
     *
     * @return list<string>
     */
    private static function kindsEquatableWith(mixed $value): array
    {
        return match (true) {
            $value === null => self::EVERY_KIND,
            is_object($value) => ['date', 'object'],
            is_array($value) => ['array'],
            is_int($value) => ['int'],
            is_float($value) => ['float'],
            is_string($value) => ['string'],
            default => ['bool'],
        };
    }

    /**
     * True when the 1.6.0 detection proves the whole chain is a contradiction (conjunction) or a
     * tautology (disjunction). Detection is a query: an exception means "not proven".
     *
     * @param list<ISpecification<mixed>> $operands
     */
    private static function provesAbsorption(array $operands, bool $isConjunction): bool
    {
        try {
            return $isConjunction
                ? SpecificationAlgebra::isContradictoryConjunction($operands)
                : SpecificationAlgebra::isTautologicalDisjunction($operands);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * True when the operand alone absorbs the chain (a contradiction in a conjunction, a tautology
     * in a disjunction).
     */
    private static function isAbsorbing(ISpecification $operand, bool $isConjunction): bool
    {
        try {
            return $isConjunction ? $operand->isContradiction() : $operand->isTautology();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * True when $later may be dropped as a repetition of $earlier: structurally equal and carrying
     * the same annotations (or none that could surface).
     */
    private static function isDuplicate(ISpecification $earlier, ISpecification $later): bool
    {
        if ($earlier === $later) {
            return true;
        }

        try {
            if (!$earlier->equals($later)) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        if (self::isDisposable($later)) {
            return true;
        }

        $signature = self::annotationSignature($earlier);

        return $signature !== null && $signature === self::annotationSignature($later);
    }

    /**
     * True when the algebra proves $special ⊆ $general. An exception means "not proven".
     */
    private static function isSpecialCase(ISpecification $special, ISpecification $general): bool
    {
        try {
            return $special->isSpecialCaseOf($general);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * True when $node is exactly the left-deep chain of $operands (same instances, no annotated
     * inner chain node), so that the original instance can be returned unchanged.
     *
     * @param list<ISpecification<mixed>> $operands
     */
    private static function isLeftDeepChainOf(AndSpecification|OrSpecification $node, array $operands): bool
    {
        $class = $node::class;
        $current = $node;
        for ($i = count($operands) - 1; $i >= 1; $i--) {
            if ($current::class !== $class || ($current !== $node && self::isAnnotated($current))) {
                return false;
            }
            /** @var AndSpecification|OrSpecification $current */
            if ($current->getRightSide() !== $operands[$i]) {
                return false;
            }
            $current = $current->getLeftSide();
        }

        return count($operands) > 1 && $current === $operands[0];
    }

    // ==========================================
    // 3. Annotations (D3)
    // ==========================================

    /**
     * True when the subtree may be dropped without hiding an annotation: no node of it carries
     * because()/withCode(), and every node either is one of the structural classes (AND, OR, NOT,
     * NOR, property restriction) or reports through the default evaluate() (its failures are only
     * default messages). Anything else (rule bindings, document requirements, method calls, external
     * implementations) is never dropped.
     */
    private static function isDisposable(ISpecification $node): bool
    {
        if (!$node instanceof AbstractSpecification || self::isAnnotated($node)) {
            return false;
        }

        return match ($node::class) {
            AndSpecification::class, OrSpecification::class, JointDenialSpecification::class =>
                self::isDisposable($node->getLeftSide()) && self::isDisposable($node->getRightSide()),
            NotSpecification::class => self::isDisposable($node->getSpecification()),
            PropertySpecification::class =>
                self::isDisposable($node->getBaseSpecification()) && self::isDisposable($node->getPropertySpecification()),
            RuleBoundSpecification::class => false,
            default => self::usesDefaultEvaluate($node),
        };
    }

    /**
     * A deterministic description of every annotation in the subtree, or null when the subtree holds
     * a node whose reporting is unknown. Two equal subtrees with the same signature report the same
     * annotated failures.
     */
    private static function annotationSignature(ISpecification $node): ?string
    {
        if (!$node instanceof AbstractSpecification) {
            return null;
        }

        $own = json_encode([self::reasonOf($node), self::codeOf($node)]);
        $children = match ($node::class) {
            AndSpecification::class, OrSpecification::class, JointDenialSpecification::class =>
                [$node->getLeftSide(), $node->getRightSide()],
            NotSpecification::class => [$node->getSpecification()],
            PropertySpecification::class => [$node->getBaseSpecification(), $node->getPropertySpecification()],
            RuleBoundSpecification::class => [$node->getInnerSpecification()],
            default => null,
        };

        if ($children === null) {
            return self::usesDefaultEvaluate($node) ? $own : null;
        }

        $parts = [];
        foreach ($children as $child) {
            $signature = self::annotationSignature($child);
            if ($signature === null) {
                return null;
            }
            $parts[] = $signature;
        }

        return $own . '(' . implode(',', $parts) . ')';
    }

    /**
     * True when the node carries because() or withCode().
     */
    private static function isAnnotated(ISpecification $node): bool
    {
        if (!$node instanceof AbstractSpecification) {
            // External implementation: whether it is annotated cannot be known, so it is a unit.
            return true;
        }

        return self::reasonOf($node) !== null || self::codeOf($node) !== null;
    }

    /**
     * Applies the annotations of $from to $to (a freshly built node of the same kind).
     *
     * @param AbstractSpecification<mixed> $from
     * @param AbstractSpecification<mixed> $to
     * @return AbstractSpecification<mixed>
     */
    private static function copyAnnotations(AbstractSpecification $from, AbstractSpecification $to): AbstractSpecification
    {
        $reason = self::reasonOf($from);
        $code = self::codeOf($from);
        if ($reason !== null) {
            $to = $to->because($reason);
        }
        if ($code !== null) {
            $to = $to->withCode($code);
        }

        return $to;
    }

    private static function reasonOf(AbstractSpecification $node): ?string
    {
        self::$reasonProperty ??= new \ReflectionProperty(AbstractSpecification::class, 'customReason');

        return self::$reasonProperty->getValue($node);
    }

    private static function codeOf(AbstractSpecification $node): ?string
    {
        self::$codeProperty ??= new \ReflectionProperty(AbstractSpecification::class, 'customCode');

        return self::$codeProperty->getValue($node);
    }

    /**
     * True when the node's evaluate() is the default one of AbstractSpecification (a single failure
     * with its own reason and code, or the default message).
     */
    private static function usesDefaultEvaluate(AbstractSpecification $node): bool
    {
        $class = $node::class;

        return self::$defaultEvaluateCache[$class]
            ??= (new \ReflectionMethod($node, 'evaluate'))->getDeclaringClass()->getName() === AbstractSpecification::class;
    }

    // ==========================================
    // 4. Size
    // ==========================================

    /**
     * @param list<ISpecification<mixed>> $nodes
     */
    private static function countNodesOf(array $nodes): int
    {
        $total = 0;
        foreach ($nodes as $node) {
            $total += self::countNodes($node);
        }

        return $total;
    }

    /**
     * Number of nodes of the subtree, counting the structural classes through their children and
     * any other specification as one node.
     */
    private static function countNodes(ISpecification $node): int
    {
        return match ($node::class) {
            AndSpecification::class, OrSpecification::class, JointDenialSpecification::class =>
                1 + self::countNodes($node->getLeftSide()) + self::countNodes($node->getRightSide()),
            NotSpecification::class => 1 + self::countNodes($node->getSpecification()),
            PropertySpecification::class =>
                1 + self::countNodes($node->getBaseSpecification()) + self::countNodes($node->getPropertySpecification()),
            RuleBoundSpecification::class => 1 + self::countNodes($node->getInnerSpecification()),
            default => 1,
        };
    }
}
