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
 * - Structural tautology/contradiction detection defaults (1.6.0: "not proven")
 * - Declarative method-call restriction (whereMethod, 1.6.0)
 * - Simplification shortcut (simplify, 1.9.0): an equivalent, smaller tree through
 *   SpecificationSimplifier; not part of ISpecification
 * - Human label of the target (labelled, 2.5.0, forward 048): a LabelledSpecification reporting every
 *   failure against the label; not part of ISpecification
 *
 * @template T
 * @implements ISpecification<T>
 * @version    2.5.0
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
use Antevemus\ASpecification\Specifications\LabelledSpecification;
use Antevemus\ASpecification\Specifications\NotSpecification;
use Antevemus\ASpecification\Specifications\OrSpecification;
use Antevemus\ASpecification\Specifications\PredicateSpecification;
use Antevemus\ASpecification\Specifications\PropertySpecification;
use Antevemus\ASpecification\Specifications\Reflection\MethodParameterizedSpecification;
use Antevemus\ASpecification\Specifications\SpecificationAlgebra;
use Antevemus\ASpecification\Specifications\SpecificationSimplifier;
use ReflectionClass;

abstract class AbstractSpecification implements ISpecification
{
    protected ?string $customReason = null;
    protected ?string $customCode = null;

    /**
     * Reflection of the declared instance properties of each concrete class, ancestors included
     * (see instanceProperties()). Metadata of the class, never instance state: equals() skips it.
     *
     * @var array<class-string, list<\ReflectionProperty>>
     */
    private static array $instancePropertiesByClass = [];

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
     * Reports every failure of this specification against a human label (2.5.0, forward 048, L3): the
     * failure's `property` becomes the label and `metadata['label']` holds it; the technical property moves
     * to `metadata['property_path']`. The answer and the SQL do not change. A label already given inside
     * wins (the innermost label). Not declared by ISpecification, so implementations of the interface are
     * unaffected; Spec::labelled() takes any specification.
     *
     * @param string $label Presentation text of the target (not blank)
     * @return LabelledSpecification<T>
     * @throws \InvalidArgumentException When the label is blank
     */
    public function labelled(string $label): LabelledSpecification
    {
        return new LabelledSpecification($this, $label);
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
     *
     * The method-call leaf declares the same candidate type as this specification (as must() does),
     * so that typed composites (Spec::specify(T)) accept the conjunction.
     */
    public function whereMethod(string $methodName, array $arguments, ISpecification $resultSpecification): ICompositeSpecification
    {
        return $this->and(new MethodParameterizedSpecification($methodName, $arguments, $resultSpecification, $this->getType()));
    }

    /**
     * {@inheritdoc}
     *
     * Default: not proven (false). Only the classes whose structure can prove it override this.
     */
    public function isTautology(): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     *
     * Default: not proven (false). Only the classes whose structure can prove it override this.
     */
    public function isContradiction(): bool
    {
        return false;
    }

    /**
     * Returns an equivalent, smaller specification (1.9.0): identity, annihilation, double negation,
     * idempotence, flattening, contradiction/tautology and subsumption, applied bottom-up to a fixed
     * point. Nodes annotated with because()/withCode() are kept (simplified only inside), so the
     * verdict and the annotated messages do not change. Equivalence is guaranteed in truth value only
     * (for non-null candidates): a removed redundant branch may have been one that would raise.
     * This specification is not modified. Shortcut of SpecificationSimplifier::simplify(); it is not
     * declared by ISpecification, so external implementations of the interface are unaffected.
     *
     * @return ISpecification<T>
     */
    public function simplify(): ISpecification
    {
        return SpecificationSimplifier::simplify($this);
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
     * Default structural equality: same concrete class and every instance property
     * holding an equal value. Nested specifications are compared through their own
     * equals(), arrays element by element, scalars strictly and any other object
     * (a value object, a date-time, an enum case, a closure, a service) by identity
     * (see specificationValuesEqual()); a class whose evaluation reads a property in a
     * coarser way (a bound compared by instant, an identifier compared by its text)
     * says so in propertyValuesEqual(). Being the same class is never sufficient on its
     * own (two leaves of the same class with different parameters are different
     * specifications).
     *
     * The properties are those of the whole class chain, this base included:
     * `private` properties declared by an ancestor are part of the predicate too (the
     * literal, the comparison and the pattern of `contains()`, kept by
     * LiteralPatternSpecification and RegexSpecification). Up to 2.0.0 only
     * ReflectionObject::getProperties() was read, which omits them, so `contains('a')`
     * equaled `contains('b')`. The base's own state (the `because()` reason and the
     * `withCode()` code) changes the result of evaluate(), so it takes part; static
     * properties are class state, not part of an instance's predicate, and are skipped.
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

        foreach (self::instanceProperties(static::class) as $prop) {
            $thisInit = $prop->isInitialized($this);
            if ($thisInit !== $prop->isInitialized($other)) {
                return false;
            }
            if (!$thisInit) {
                continue;
            }
            if (!$this->propertyValuesEqual($prop->getDeclaringClass()->getName(), $prop->getName(), $prop->getValue($this), $prop->getValue($other))) {
                return false;
            }
        }

        // Dynamic properties (a class marked #[AllowDynamicProperties]), compared both ways.
        $thisDynamic = self::dynamicProperties($this);
        $otherDynamic = self::dynamicProperties($other);
        if (count($thisDynamic) !== count($otherDynamic)) {
            return false;
        }
        foreach ($thisDynamic as $name => $value) {
            if (!array_key_exists($name, $otherDynamic) || !self::specificationValuesEqual($value, $otherDynamic[$name])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Declared instance properties of a class and of all its ancestors, one entry per storage
     * slot: an ancestor's `private` property is a slot of its own (even when a descendant declares
     * a property of the same name) and is read through the class that declares it. Cached per
     * class, since the list depends only on the class.
     *
     * @param class-string $class
     * @return list<\ReflectionProperty>
     */
    private static function instanceProperties(string $class): array
    {
        if (isset(self::$instancePropertiesByClass[$class])) {
            return self::$instancePropertiesByClass[$class];
        }

        $properties = [];
        for ($level = new ReflectionClass($class); $level !== false; $level = $level->getParentClass()) {
            foreach ($level->getProperties() as $prop) {
                // An inherited non-private property is listed again by every descendant: keep it once,
                // at the level that declares it.
                if ($prop->isStatic() || $prop->getDeclaringClass()->getName() !== $level->getName()) {
                    continue;
                }
                $properties[] = $prop;
            }
        }

        return self::$instancePropertiesByClass[$class] = $properties;
    }

    /**
     * Dynamic (undeclared) properties of an object, by name.
     *
     * @return array<string, mixed>
     */
    private static function dynamicProperties(object $object): array
    {
        $dynamic = [];
        foreach ((new \ReflectionObject($object))->getProperties() as $prop) {
            if (!$prop->isDefault() && !$prop->isStatic()) {
                $dynamic[$prop->getName()] = $prop->getValue($object);
            }
        }

        return $dynamic;
    }

    /**
     * Compares the values one declared property holds in two instances of the same concrete class
     * (equals() calls it for every slot of the class chain). The default is
     * specificationValuesEqual(). A class whose evaluation reads a property in a coarser way than
     * that overrides it for that property only, and must keep the rule: two values may be called
     * equal only when no candidate can tell them apart (same answer and same result), never more
     * loosely than the evaluation (2.1.0, BUG-20261013-2DAZ).
     *
     * @param class-string $declaringClass Class that declares the property (an ancestor's private slot is its own)
     * @param string $property Property name
     * @param mixed $mine Value held by this instance
     * @param mixed $theirs Value held by the other instance
     * @return bool
     */
    protected function propertyValuesEqual(string $declaringClass, string $property, mixed $mine, mixed $theirs): bool
    {
        return self::specificationValuesEqual($mine, $theirs);
    }

    /**
     * Compares two property values for structural specification equality: nested specifications
     * through their own equals(), arrays element by element (same keys), and everything else by
     * strict identity (`===`): scalars by type and value, objects (value objects, date-times, enum
     * cases, closures, services) by identity.
     *
     * Identity is the strictest comparison (it never calls two different objects equal), so it is never
     * looser than an evaluation: equalTo()/notEqual() test an object candidate with `===`, and two
     * leaves holding distinct objects answer differently even when the objects look alike. Up to 2.0.0
     * objects were compared with `==` (so `equalTo(new Money('1'))` equaled `equalTo(new Money(1))`,
     * and `Money(null)` equaled `Money(false)`), and simplify() merged leaves that answer differently.
     * A date-time is compared by identity here too, the safe answer for a class that does not say how
     * it reads it; the comparison leaves, which compare date-times by instant since 2.1.0
     * (BUG-20261013-LIZI), say so in propertyValuesEqual().
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

        return $a === $b;
    }
}
