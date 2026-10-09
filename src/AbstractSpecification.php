<?php

declare(strict_types=1);

/**
 * AbstractSpecification - Abstract foundational specification implementation
 *
 * Base abstract specification class providing default implementations for composition,
 * algebraic analysis, visitor dispatch, and notification pattern integration.
 *
 * Features:
 * - Fluent composition operators (and, or, not, andNot, orNot, where)
 * - Notification pattern diagnostic integration (evaluate, because, withCode)
 * - Visitor pattern dispatch for SQL and Criteria translation
 * - Set algebra analysis hooks (generalization, specialization, disjointness)
 *
 * @template T
 * @implements ISpecification<T>
 * @version    1.4.4
 * @package    Antevemus\ASpecification
 * @subpackage Core
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */

namespace Antevemus\ASpecification;

use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Results\SpecificationFailure;
use Antevemus\ASpecification\Results\SpecificationResult;
use Antevemus\ASpecification\Specifications\AndSpecification;
use Antevemus\ASpecification\Specifications\NotSpecification;
use Antevemus\ASpecification\Specifications\OrSpecification;
use Antevemus\ASpecification\Specifications\PredicateSpecification;
use Antevemus\ASpecification\Specifications\PropertySpecification;
use Antevemus\ASpecification\Specifications\SpecificationAlgebra;
use ReflectionClass;

abstract class AbstractSpecification implements ISpecification
{
    protected ?string $customReason = null;
    protected ?string $customCode = null;

    /**
     * {@inheritdoc}
     */
    public function because(string $reason): static
    {
        $clone = clone $this;
        $clone->customReason = $reason;
        return $clone;
    }

    /**
     * {@inheritdoc}
     */
    public function withCode(string $code): static
    {
        $clone = clone $this;
        $clone->customCode = $code;
        return $clone;
    }

    /**
     * {@inheritdoc}
     */
    public function andNot(ISpecification|string $otherSpecification, ?ISpecification $propertySpecification = null): ICompositeSpecification
    {
        if (is_string($otherSpecification)) {
            if ($propertySpecification === null) {
                throw new \InvalidArgumentException('Property specification cannot be null when property name is provided.');
            }
            return $this->and($otherSpecification, $propertySpecification->not());
        }

        return $this->and($otherSpecification->not());
    }

    /**
     * {@inheritdoc}
     */
    public function orNot(ISpecification|string $otherSpecification, ?ISpecification $propertySpecification = null): ICompositeSpecification
    {
        if (is_string($otherSpecification)) {
            if ($propertySpecification === null) {
                throw new \InvalidArgumentException('Property specification cannot be null when property name is provided.');
            }
            return $this->or($otherSpecification, $propertySpecification->not());
        }

        return $this->or($otherSpecification->not());
    }

    /**
     * {@inheritdoc}
     */
    public function evaluate(mixed $candidate): SpecificationResult
    {
        try {
            $satisfied = $this->isSatisfiedBy($candidate);
        } catch (\Throwable $e) {
            // An exception while evaluating is NOT a rule failure: the rule could not be evaluated.
            // It becomes an error result that NOT never inverts and composites propagate.
            return SpecificationResult::error(
                exception: $e,
                ruleName: (new ReflectionClass($this))->getShortName(),
                code: $this->customCode
            );
        }

        if ($satisfied) {
            return SpecificationResult::satisfied();
        }

        $message = $this->customReason ?? $this->getDefaultFailureMessage($candidate);
        $ruleName = (new ReflectionClass($this))->getShortName();

        return SpecificationResult::failure(
            message: $message,
            code: $this->customCode,
            ruleName: $ruleName
        );
    }

    /**
     * {@inheritdoc}
     */
    public function accept(\Antevemus\ASpecification\Contracts\ISpecificationVisitor $visitor): mixed
    {
        return $visitor->visitLeaf($this);
    }

    /**
     * {@inheritdoc}
     */
    public function toSql(
        \Antevemus\ASpecification\Contracts\Sql\ISqlDialect|\Antevemus\ASpecification\Sql\SqlDialect|string $dialect = 'ansi',
        \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array|\Closure|null $fieldMap = null,
        \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array|\Closure|null $fieldMapper = null
    ): \Antevemus\ASpecification\Contracts\Sql\ISqlWhereClause {
        $fieldMap = Spec::resolveFieldMap($fieldMap, $fieldMapper);
        return (new \Antevemus\ASpecification\Sql\SqlQueryVisitor($dialect, $fieldMap))->translate($this);
    }

    /**
     * {@inheritdoc}
     */
    public function toCriteria(
        \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array|\Closure|null $fieldMap = null,
        array $properties = [],
        \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array|\Closure|null $fieldMapper = null
    ): mixed {
        $fieldMap = Spec::resolveFieldMap($fieldMap, $fieldMapper);
        return \Antevemus\ASpecification\Criteria\TCriteriaBuilder::fromSpecification($this, $fieldMap, $properties);
    }

    /**
     * Generates a default failure message if custom reason is not defined via because().
     *
     * @param mixed $candidate Evaluated candidate instance
     * @return string Human-readable failure explanation
     */
    protected function getDefaultFailureMessage(mixed $candidate): string
    {
        $ruleName = (new ReflectionClass($this))->getShortName();
        return sprintf("Candidate failed to satisfy rule '%s'.", $ruleName);
    }

    /**
     * {@inheritdoc}
     */
    public function where(string $accessibleObjectName, ISpecification $accessibleObjectSpecification): ICompositeSpecification
    {
        if (empty($accessibleObjectName)) {
            throw new \InvalidArgumentException('Accessible object name cannot be empty');
        }

        return new PropertySpecification($this, $accessibleObjectName, $accessibleObjectSpecification);
    }

    /**
     * {@inheritdoc}
     */
    public function must(\Closure $predicate, ?string $code = null, ?string $message = null): ICompositeSpecification
    {
        // The leaf declares the same candidate type as this specification, so that typed composites
        // (Spec::specify(T)) accept the conjunction.
        $leaf = new PredicateSpecification($predicate, $this->getType());
        if ($code !== null) {
            $leaf = $leaf->withCode($code);
        }
        if ($message !== null) {
            $leaf = $leaf->because($message);
        }

        return $this->and($leaf);
    }

    /**
     * {@inheritdoc}
     */
    public function and(ISpecification|string $otherSpecification, ?ISpecification $propertySpecification = null): ICompositeSpecification
    {
        if (is_string($otherSpecification)) {
            if ($propertySpecification === null) {
                throw new \InvalidArgumentException('Property specification cannot be null when property name is provided.');
            }
            return $this->and(new PropertySpecification($this->resolveRootTypeSpecification(), $otherSpecification, $propertySpecification));
        }

        if ($this instanceof ICompositeSpecification && ($otherSpecification === $this || $this->equals($otherSpecification))) {
            // A ∧ A ≡ A (Domian CompositeSpecificationTest.testCombinedByItself)
            return $this;
        }

        return new AndSpecification($this, $otherSpecification);
    }

    /**
     * {@inheritdoc}
     */
    public function or(ISpecification|string $otherSpecification, ?ISpecification $propertySpecification = null): ICompositeSpecification
    {
        if (is_string($otherSpecification)) {
            if ($propertySpecification === null) {
                throw new \InvalidArgumentException('Property specification cannot be null when property name is provided.');
            }
            return $this->or(new PropertySpecification($this->resolveRootTypeSpecification(), $otherSpecification, $propertySpecification));
        }

        if ($this instanceof ICompositeSpecification && ($otherSpecification === $this || $this->equals($otherSpecification))) {
            // A ∨ A ≡ A (Domian CompositeSpecificationTest.testCombinedByItself)
            return $this;
        }

        return new OrSpecification($this, $otherSpecification);
    }

    /**
     * Resolves the root type specification representing the candidate type of this composite chain.
     *
     * @return ISpecification Root specification
     */
    protected function resolveRootTypeSpecification(): ISpecification
    {
        if ($this instanceof PropertySpecification) {
            $left = $this->getLeftSide();
            return ($left instanceof AbstractSpecification && $left !== $this)
                ? $left->resolveRootTypeSpecification()
                : ($left ?? $this);
        }

        if ($this instanceof ICompositeSpecification) {
            $left = $this->getLeftSide();
            if ($left instanceof AbstractSpecification && $left !== $this) {
                return $left->resolveRootTypeSpecification();
            }
            if ($left instanceof ISpecification) {
                return $left;
            }
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function not(): ICompositeSpecification
    {
        return new NotSpecification($this);
    }

    /**
     * {@inheritdoc}
     */
    abstract public function getType(): string;

    /**
     * {@inheritdoc}
     */
    abstract public function isSatisfiedBy(?object $candidate): bool;

    /**
     * {@inheritdoc}
     *
     * Default: reflexivity (structural equality) plus the shared axioms (A ⊇ ∅, A ⊇ (B ∧ C) if
     * A ⊇ B or A ⊇ C, A ⊇ (B ∨ C) iff A ⊇ B and A ⊇ C, ¬¬B ≡ B). Domian's default is the
     * reflexivity alone; the axioms are sound for every specification, so they apply here too.
     */
    public function isGeneralizationOf(ISpecification $otherSpecification): bool
    {
        return SpecificationAlgebra::baseGeneralizes($this, $otherSpecification);
    }

    /**
     * {@inheritdoc}
     */
    public function isSpecialCaseOf(ISpecification $otherSpecification): bool
    {
        return $otherSpecification->isGeneralizationOf($this);
    }

    /**
     * {@inheritdoc}
     *
     * Default: never disjoint with itself; disjoint with the contradiction, with the negation of
     * a generalization (A ⟂ ¬B iff B ⊇ A), with a conjunction one of whose sides is disjoint and
     * with a disjunction both of whose sides are disjoint. Anything else is "not proven"
     * (false). Domian's default answers true for every non-equal pair, which is unsound for
     * leaves such as regular expressions; the safe answer is kept here.
     */
    public function isDisjointWith(ISpecification $otherSpecification): bool
    {
        return SpecificationAlgebra::baseDisjoint($this, $otherSpecification);
    }

    /**
     * {@inheritdoc}
     */
    public function isIntersectionOf(ISpecification $otherSpecification): bool
    {
        if ($otherSpecification === null) {
            throw new \InvalidArgumentException('Specification cannot be null');
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function intersectsWith(ISpecification $otherSpecification): bool
    {
        if ($otherSpecification === null) {
            throw new \InvalidArgumentException('Specification cannot be null');
        }

        return !$this->isDisjointWith($otherSpecification);
    }

    /**
     * Returns string representation of this specification class.
     *
     * @return string
     */
    public function __toString(): string
    {
        return static::class;
    }

    /**
     * {@inheritdoc}
     *
     * Default structural equality: same concrete class and every declared property
     * holding an equal value. Nested specifications are compared through their own
     * equals(), date-time values by instant, arrays element by element, scalars
     * strictly. Being the same class is never sufficient on its own (two leaves of
     * the same class with different parameters are different specifications).
     *
     * @param mixed $other Object to compare against
     * @return bool True when both specifications denote the same predicate
     */
    public function equals(mixed $other): bool
    {
        if ($this === $other) {
            return true;
        }
        if (!is_object($other) || static::class !== $other::class) {
            return false;
        }

        $refThis = new \ReflectionObject($this);
        $refOther = new \ReflectionObject($other);

        foreach ($refThis->getProperties() as $prop) {
            $name = $prop->getName();
            if (!$refOther->hasProperty($name)) {
                return false;
            }
            $otherProp = $refOther->getProperty($name);
            $thisInit = $prop->isInitialized($this);
            if ($thisInit !== $otherProp->isInitialized($other)) {
                return false;
            }
            if (!$thisInit) {
                continue;
            }
            if (!self::specificationValuesEqual($prop->getValue($this), $otherProp->getValue($other))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Compares two property values for structural specification equality.
     *
     * @param mixed $a
     * @param mixed $b
     * @return bool
     */
    protected static function specificationValuesEqual(mixed $a, mixed $b): bool
    {
        if ($a instanceof ISpecification && $b instanceof ISpecification) {
            return $a->equals($b);
        }
        if ($a instanceof \DateTimeInterface && $b instanceof \DateTimeInterface) {
            return $a == $b;
        }
        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b)) {
                return false;
            }
            foreach ($a as $key => $value) {
                if (!array_key_exists($key, $b) || !self::specificationValuesEqual($value, $b[$key])) {
                    return false;
                }
            }
            return true;
        }
        if ($a instanceof \Closure || $b instanceof \Closure) {
            return $a === $b;
        }
        if (is_object($a) && is_object($b)) {
            return $a::class === $b::class && $a == $b;
        }

        return $a === $b;
    }
}
