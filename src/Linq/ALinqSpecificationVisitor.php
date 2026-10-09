<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Linq;

use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Engine\RuleBoundSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\ISpecificationVisitor;
use Antevemus\ASpecification\Helpers\PropertyAccessor;
use Antevemus\ASpecification\Specifications\AndSpecification;
use Antevemus\ASpecification\Specifications\Collection\CollectionSpecification;
use Antevemus\ASpecification\Specifications\Comparison\InSpecification;
use Antevemus\ASpecification\Specifications\Comparison\NotNullSpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysFalseSpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysTrueSpecification;
use Antevemus\ASpecification\Specifications\Logical\JointDenialSpecification;
use Antevemus\ASpecification\Specifications\NotSpecification;
use Antevemus\ASpecification\Specifications\OrSpecification;
use Antevemus\ASpecification\Specifications\PropertySpecification;
use Antevemus\ASpecification\Specifications\Reflection\MethodParameterizedSpecification;
use Antevemus\ASpecification\Specifications\String\EqualIgnoreCaseStringSpecification;
use Antevemus\ASpecification\Specifications\String\RegexSpecification;
use Closure;

/**
 * ALinqSpecificationVisitor - Specification AST Compiler to Executable Predicates
 *
 * Traverses an Abstract Syntax Tree (AST) of specifications and compiles a high-performance
 * functional Closure(mixed): bool predicate, optimized for ALinqCollection filtering
 * and in-memory candidate evaluation.
 *
 * Features:
 * - Compilation of composite specifications (AND, OR, NOT, NOR) to short-circuit operators (&&, ||)
 * - Evaluation of PropertySpecification integrated with PropertyAccessor (dot notation, arrays, getters)
 * - Evaluation of relational and pattern leaves (=, !=, <, <=, >, >=, regex, wildcard, case-insensitive)
 * - Direct execution compatible with ALinqCollection::where() and ALinqQueryBuilder
 * - Set membership (InSpecification, 1.5.0) compiled to `in_array($candidate, $values, true)`
 * - Case-insensitive equality compared with `mb_strtolower(..., 'UTF-8')`, as the leaf (1.5.0)
 * - Declarative method call (MethodParameterizedSpecification, 1.6.0) compiled to the call of the
 *   method on the item, the result filtered by the compiled result specification
 *
 * Parity contract: for the same candidate, the compiled predicate returns exactly what the
 * specification's own isSatisfiedBy()/evaluate() would decide, and throws the same typed
 * exceptions (IncompatibleTypeException on type mismatch, InvalidArgumentException on a
 * missing property). Leaves are only short-circuited when their semantics are provably
 * identical to the core; every other leaf delegates to its own isSatisfiedBy().
 *
 * @version    1.6.0
 * @package    Antevemus\ASpecification
 * @subpackage Linq
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class ALinqSpecificationVisitor implements ISpecificationVisitor
{
    /**
     * Compile a specification tree into an executable predicate closure.
     *
     * @param ISpecification $specification
     * @return Closure(mixed $candidate): bool
     */
    public function visit(ISpecification $specification): Closure
    {
        if ($specification instanceof RuleBoundSpecification) {
            return $this->visit($specification->getInnerSpecification());
        }

        if ($specification instanceof ICompositeSpecification) {
            return $this->visitComposite($specification);
        }

        return $this->visitLeaf($specification);
    }

    /**
     * Static shortcut to compile a specification directly into an executable Closure predicate.
     *
     * @param ISpecification $specification
     * @return Closure(mixed $candidate): bool
     */
    public static function createPredicate(ISpecification $specification): Closure
    {
        return (new self())->visit($specification);
    }

    /**
     * Compile and return the executable predicate for the given specification.
     *
     * @param ISpecification $specification
     * @return Closure(mixed $candidate): bool
     */
    public function toPredicate(ISpecification $specification): Closure
    {
        return $this->visit($specification);
    }

    /**
     * {@inheritdoc}
     *
     * @param ICompositeSpecification $specification
     * @return Closure(mixed $candidate): bool
     */
    public function visitComposite(ICompositeSpecification $specification): Closure
    {
        return match (true) {
            $specification instanceof PropertySpecification =>
                $this->compilePropertySpecification($specification),

            $specification instanceof AndSpecification =>
                $this->compileAnd($specification),

            $specification instanceof OrSpecification =>
                $this->compileOr($specification),

            $specification instanceof NotSpecification =>
                $this->compileNot($specification),

            $specification instanceof JointDenialSpecification =>
                $this->compileJointDenial($specification),

            $specification instanceof AlwaysTrueSpecification =>
                fn(mixed $candidate): bool => true,

            $specification instanceof AlwaysFalseSpecification =>
                fn(mixed $candidate): bool => false,

            default => $this->compileGenericComposite($specification),
        };
    }

    /**
     * {@inheritdoc}
     *
     * @param ISpecification $specification
     * @return Closure(mixed $candidate): bool
     */
    public function visitLeaf(ISpecification $specification): Closure
    {
        if ($specification instanceof RuleBoundSpecification) {
            return $this->visit($specification->getInnerSpecification());
        }

        return match (true) {
            // Shortcuts kept only where the semantics are identical to the leaf's isSatisfiedBy().
            $specification instanceof NotNullSpecification =>
                fn(mixed $candidate): bool => $candidate !== null,

            $specification instanceof EqualIgnoreCaseStringSpecification =>
                $this->compileEqualIgnoreCase($specification),

            $specification instanceof InSpecification =>
                $this->compileIn($specification),

            $specification instanceof MethodParameterizedSpecification =>
                $this->compileMethodCall($specification),

            $specification instanceof RegexSpecification =>
                fn(mixed $candidate): bool => is_string($candidate) && preg_match($specification->getPattern(), $candidate) === 1,

            $specification instanceof CollectionSpecification =>
                $this->compileCollectionSpecification($specification),

            $specification instanceof AlwaysTrueSpecification =>
                fn(mixed $candidate): bool => true,

            $specification instanceof AlwaysFalseSpecification =>
                fn(mixed $candidate): bool => false,

            // Comparison leaves (Equal, NotEqual, GreaterThan, LessThan, LooseEqual), wildcard leaves
            // (fnmatch semantics) and custom leaves: the leaf itself is the only source of truth.
            // Strictness, IncompatibleTypeException and scalar candidates are inherited from it.
            default =>
                fn(mixed $candidate): bool => $specification->isSatisfiedBy($candidate),
        };
    }

    /**
     * Compile case-insensitive string equality with the leaf's Unicode lowering (RN-03): the
     * reference value is lowered once, at compile time.
     */
    private function compileEqualIgnoreCase(EqualIgnoreCaseStringSpecification $specification): Closure
    {
        $expected = mb_strtolower($specification->getValue(), 'UTF-8');

        return static fn(mixed $candidate): bool => is_string($candidate)
            && mb_strtolower($candidate, 'UTF-8') === $expected;
    }

    /**
     * Compile set membership as `in_array($candidate, $values, true)` (RN-07). A hit is decided
     * here; a miss is handed to the leaf, which returns false or raises the same
     * IncompatibleTypeException the core raises for a candidate of a type no member shares.
     */
    private function compileIn(InSpecification $specification): Closure
    {
        $values = $specification->getValues();

        return static fn(mixed $candidate): bool => in_array($candidate, $values, true)
            || $specification->isSatisfiedBy($candidate);
    }

    /**
     * Compile a declarative method call (RN-07 of forward 018): the method is called on the item with
     * the declared arguments and the returned value is filtered by the compiled result specification.
     * Mirrors MethodParameterizedSpecification::isSatisfiedBy(): a non-object item and a null returned
     * value never satisfy; a missing or non-public method raises the same BadMethodCallException.
     */
    private function compileMethodCall(MethodParameterizedSpecification $specification): Closure
    {
        $resultPredicate = $this->visit($specification->getResultSpecification());

        return static function(mixed $candidate) use ($specification, $resultPredicate): bool {
            if (!is_object($candidate)) {
                return false;
            }

            $result = $specification->callOn($candidate);
            if ($result === null) {
                return false;
            }

            return $resultPredicate($result);
        };
    }

    /**
     * Compile property inspection using PropertyAccessor.
     */
    private function compilePropertySpecification(PropertySpecification $specification): Closure
    {
        $propertyName = $specification->getPropertyName();
        $basePredicate = $this->visit($specification->getLeftSide());
        $innerPredicate = $this->visit($specification->getPropertySpecification());

        // Mirrors PropertySpecification::evaluate(): non-inspectable candidate and null property
        // value never satisfy; a missing property is an evaluation error, never a false that a
        // NOT could turn into approval.
        return function(mixed $candidate) use ($propertyName, $basePredicate, $innerPredicate): bool {
            if ($candidate === null || (!is_object($candidate) && !is_array($candidate))) {
                return false;
            }

            if (!$basePredicate($candidate)) {
                return false;
            }

            if (!PropertyAccessor::hasProperty($candidate, $propertyName)) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Property "%s" not found or not accessible on candidate of type "%s"',
                        $propertyName,
                        is_object($candidate) ? get_class($candidate) : gettype($candidate)
                    )
                );
            }

            $value = PropertyAccessor::getValue($candidate, $propertyName);
            if ($value === null) {
                return false;
            }

            return $innerPredicate($value);
        };
    }

    /**
     * Compile logical conjunction (AND).
     */
    private function compileAnd(AndSpecification $specification): Closure
    {
        $left = $this->visit($specification->getLeftSide());
        $right = $this->visit($specification->getRightSide());

        return fn(mixed $candidate): bool => $left($candidate) && $right($candidate);
    }

    /**
     * Compile logical disjunction (OR).
     */
    private function compileOr(OrSpecification $specification): Closure
    {
        $left = $this->visit($specification->getLeftSide());
        $right = $this->visit($specification->getRightSide());

        return fn(mixed $candidate): bool => $left($candidate) || $right($candidate);
    }

    /**
     * Compile logical negation (NOT).
     */
    private function compileNot(NotSpecification $specification): Closure
    {
        $inner = $this->visit($specification->getSpecification());

        return fn(mixed $candidate): bool => !$inner($candidate);
    }

    /**
     * Compile joint denial (NOR).
     */
    private function compileJointDenial(JointDenialSpecification $specification): Closure
    {
        $left = $this->visit($specification->getLeftSide());
        $right = $this->visit($specification->getRightSide());

        return fn(mixed $candidate): bool => !$left($candidate) && !$right($candidate);
    }

    /**
     * Compile verification across all elements in an iterable collection.
     */
    private function compileCollectionSpecification(CollectionSpecification $specification): Closure
    {
        $elementSpec = $specification->getElementSpecification();
        $elementPredicate = $this->visit($elementSpec);

        return function(mixed $candidate) use ($elementPredicate): bool {
            if (!is_iterable($candidate)) {
                return false;
            }
            foreach ($candidate as $item) {
                if (!$elementPredicate($item)) {
                    return false;
                }
            }
            return true;
        };
    }

    /**
     * Compile generic composite aggregating all child criteria via logical AND.
     *
     * A childless composite is evaluated by its own isSatisfiedBy() with the candidate as is
     * (scalar, array or object), exactly like a custom leaf: a consumer composite that widens the
     * candidate to mixed sees the scalar, and a core-typed (?object) composite raises the same
     * TypeError the core raises, never a silent false that NOT could turn into approval.
     */
    private function compileGenericComposite(ICompositeSpecification $specification): Closure
    {
        $specs = $specification->getSpecifications();
        if (!empty($specs)) {
            $predicates = array_map(fn($s) => $this->visit($s), $specs);
            return function(mixed $candidate) use ($predicates): bool {
                foreach ($predicates as $predicate) {
                    if (!$predicate($candidate)) {
                        return false;
                    }
                }
                return true;
            };
        }

        return fn(mixed $candidate): bool => $specification->isSatisfiedBy($candidate);
    }
}
