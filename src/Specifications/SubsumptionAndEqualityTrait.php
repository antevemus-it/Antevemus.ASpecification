<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications;

use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Specifications\Collection\AllEntitiesSpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysFalseSpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysTrueSpecification;

/**
 * SubsumptionAndEqualityTrait - Trait providing structural equality and subsumption axioms (RF-10).
 *
 * Implements deep structural equality (`equals`) via reflection and fundamental algebraic axioms
 * for specification subsumption (`isGeneralizationOf`) and disjointness (`isDisjointWith`).
 *
 * Features:
 * - Deep structural equality (`equals`) across composite trees and leaf specifications
 * - Reflexivity ($A \supseteq A$) and universality ($AlwaysTrue \supseteq A$) axioms
 * - Conjunction subsumption ($S \supseteq (A \land B)$ if $S \supseteq A$ or $S \supseteq B$)
 * - Disjunction subsumption ($S \supseteq (A \lor B)$ if $S \supseteq A$ and $S \supseteq B$)
 * - Fluent property composition (`andWhere`, `orWhere`)
 * - Partial remainder resolution (`remainderUnsatisfiedBy`)
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
trait SubsumptionAndEqualityTrait
{
    /*
     * equals() is no longer defined here: ISpecification declares it and
     * AbstractSpecification provides the structural default for every
     * specification (BUG-20261007-C5YG). Keeping a second copy in this trait
     * diverged from the base (identity comparison of DateTime and arrays).
     */

    /**
     * Evaluates core subsumption axioms ($this \supseteq $otherSpecification).
     *
     * @param ISpecification<mixed> $otherSpecification
     * @return bool
     */
    protected function checkBaseGeneralization(ISpecification $otherSpecification): bool
    {
        // Axiom 1: Reflexivity and Structural Equality ($A \supseteq A$)
        if ($this === $otherSpecification || $this->equals($otherSpecification)) {
            return true;
        }

        // Axiom 2: Any specification generalizes AlwaysFalseSpecification (empty set)
        if ($otherSpecification instanceof AlwaysFalseSpecification) {
            return true;
        }

        // Axiom 3: AlwaysTrueSpecification / AllEntitiesSpecification with compatible supertype
        if ($this instanceof AlwaysTrueSpecification || $this instanceof AllEntitiesSpecification) {
            $myType = $this->getType();
            $otherType = $otherSpecification->getType();
            if ($myType === "mixed" || $myType === "object" || $myType === $otherType) {
                return true;
            }
            if (
                (class_exists($myType) || interface_exists($myType)) &&
                (class_exists($otherType) || interface_exists($otherType)) &&
                is_a($otherType, $myType, true)
            ) {
                return true;
            }
        }

        // Axiom 4: A specification $S$ generalizes conjunction $(A \land B)$ if $S \supseteq A$ or $S \supseteq B$
        if ($otherSpecification instanceof AndSpecification && !($this instanceof AndSpecification)) {
            $left = $otherSpecification->getLeftSide();
            $right = $otherSpecification->getRightSide();
            if ($left !== null && $this->isGeneralizationOf($left)) {
                return true;
            }
            if ($right !== null && $this->isGeneralizationOf($right)) {
                return true;
            }
        }

        // Axiom 5: A specification $S$ generalizes disjunction $(A \lor B)$ if $S \supseteq A$ AND $S \supseteq B$
        if ($otherSpecification instanceof OrSpecification) {
            $left = $otherSpecification->getLeftSide();
            $right = $otherSpecification->getRightSide();
            if ($left !== null && $right !== null) {
                return $this->isGeneralizationOf($left) && $this->isGeneralizationOf($right);
            }
        }

        return false;
    }

    /**
     * Evaluates core disjointness axioms ($this \cap $otherSpecification = \emptyset$).
     *
     * @param ISpecification<mixed> $otherSpecification
     * @return bool
     */
    protected function checkBaseDisjointness(ISpecification $otherSpecification): bool
    {
        if ($this instanceof AlwaysFalseSpecification || $otherSpecification instanceof AlwaysFalseSpecification) {
            return true;
        }

        if ($otherSpecification instanceof NotSpecification) {
            $negated = $otherSpecification->getLeftSide();
            if ($negated !== null && $negated->isGeneralizationOf($this)) {
                return true;
            }
        }

        if ($otherSpecification instanceof AndSpecification) {
            $left = $otherSpecification->getLeftSide();
            $right = $otherSpecification->getRightSide();
            if (($left !== null && $this->isDisjointWith($left)) || ($right !== null && $this->isDisjointWith($right))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Chains a new property restriction via logical conjunction (AND).
     *
     * @param string $accessibleObjectName Accessible property name
     * @param ISpecification<mixed> $accessibleObjectSpecification Property specification
     * @return \Antevemus\ASpecification\Contracts\ICompositeSpecification<mixed>
     */
    public function andWhere(
        string $accessibleObjectName,
        ISpecification $accessibleObjectSpecification
    ): \Antevemus\ASpecification\Contracts\ICompositeSpecification {
        return new AndSpecification(
            $this,
            new PropertySpecification($this, $accessibleObjectName, $accessibleObjectSpecification)
        );
    }

    /**
     * Chains a new property restriction via logical disjunction (OR).
     *
     * @param string $accessibleObjectName Accessible property name
     * @param ISpecification<mixed> $accessibleObjectSpecification Property specification
     * @return \Antevemus\ASpecification\Contracts\ICompositeSpecification<mixed>
     */
    public function orWhere(
        string $accessibleObjectName,
        ISpecification $accessibleObjectSpecification
    ): \Antevemus\ASpecification\Contracts\ICompositeSpecification {
        return new OrSpecification(
            $this,
            new PropertySpecification($this, $accessibleObjectName, $accessibleObjectSpecification)
        );
    }

    /**
     * Returns the sub-specification unsatisfied by the given candidate, or null if fully satisfied.
     *
     * @param object $candidate Evaluated candidate object
     * @return \Antevemus\ASpecification\Contracts\ICompositeSpecification<mixed>|null
     */
    public function remainderUnsatisfiedBy(
        object $candidate
    ): ?\Antevemus\ASpecification\Contracts\ICompositeSpecification {
        if ($this->isSatisfiedBy($candidate)) {
            return null;
        }
        if ($this instanceof \Antevemus\ASpecification\Contracts\ICompositeSpecification) {
            return $this;
        }
        return new AndSpecification($this, $this);
    }
}
