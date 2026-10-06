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
 * @version    1.1.0
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
use Antevemus\ASpecification\Specifications\PropertySpecification;
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
        } catch (\TypeError) {
            $satisfied = false;
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
        \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array|\Closure|null $fieldMap = null
    ): \Antevemus\ASpecification\Contracts\Sql\ISqlWhereClause {
        return (new \Antevemus\ASpecification\Sql\SqlQueryVisitor($dialect, $fieldMap))->translate($this);
    }

    /**
     * {@inheritdoc}
     */
    public function toCriteria(
        \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array|\Closure|null $fieldMap = null,
        array $properties = []
    ): mixed {
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
    public function and(ISpecification|string $otherSpecification, ?ISpecification $propertySpecification = null): ICompositeSpecification
    {
        if (is_string($otherSpecification)) {
            if ($propertySpecification === null) {
                throw new \InvalidArgumentException('Property specification cannot be null when property name is provided.');
            }
            return $this->and(new PropertySpecification($this->resolveRootTypeSpecification(), $otherSpecification, $propertySpecification));
        }

        if ($otherSpecification === null) {
            throw new \InvalidArgumentException('Specification cannot be null');
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

        if ($otherSpecification === null) {
            throw new \InvalidArgumentException('Specification cannot be null');
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
     */
    public function isGeneralizationOf(ISpecification $otherSpecification): bool
    {
        if ($otherSpecification === null) {
            throw new \InvalidArgumentException('Specification cannot be null');
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function isSpecialCaseOf(ISpecification $otherSpecification): bool
    {
        if ($otherSpecification === null) {
            throw new \InvalidArgumentException('Specification cannot be null');
        }

        return $otherSpecification->isGeneralizationOf($this);
    }

    /**
     * {@inheritdoc}
     */
    public function isDisjointWith(ISpecification $otherSpecification): bool
    {
        if ($otherSpecification === null) {
            throw new \InvalidArgumentException('Specification cannot be null');
        }

        return false;
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
}
