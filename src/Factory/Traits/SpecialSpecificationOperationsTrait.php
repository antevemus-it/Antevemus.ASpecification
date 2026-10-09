<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Factory\Traits;

use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Specifications\Collection\AllEntitiesSpecification;

/**
 * SpecialSpecificationOperationsTrait - Trait aggregating special specifications and sentinel values (ISpecialSpecificationFactory).
 *
 * Provides delegation methods forwarding to the underlying special specification factory.
 *
 * Features:
 * - Tautology and contradiction (alwaysTrue, alwaysFalse)
 * - Nullity checks (isNull, isNotNull)
 * - Boolean predicates (isTrue, isFalse)
 *
 * @version    1.4.4
 * @package    Antevemus\ASpecification
 * @subpackage Factory\Traits
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
trait SpecialSpecificationOperationsTrait
{
    /**
     * Creates a specification that always returns true (Tautology).
     *
     * Useful for test cases, fallback/default specifications, or base composition anchors.
     *
     * @return ISpecification Specification that is unconditionally satisfied
     */
    public function alwaysTrue(): ISpecification
    {
        return $this->specialFactory->alwaysTrue();
    }

    /**
     * Creates a specification that always returns false (Contradiction).
     *
     * Useful for test cases, total negation guards, or reject-all scenarios.
     *
     * @return ISpecification Specification that is never satisfied
     */
    public function alwaysFalse(): ISpecification
    {
        return $this->specialFactory->alwaysFalse();
    }

    /**
     * Creates a specification verifying whether the candidate value is null.
     *
     * @return ISpecification Null-check specification
     */
    public function isNull(): ISpecification
    {
        return $this->specialFactory->isNull();
    }

    /**
     * Creates a specification verifying whether the candidate value is not null.
     *
     * @return ISpecification Non-null check specification
     */
    public function isNotNull(): ISpecification
    {
        return $this->specialFactory->isNotNull();
    }

    /**
     * Creates a specification verifying whether the candidate evaluates to boolean true.
     *
     * @return ISpecification Boolean true specification
     */
    public function isTrue(): ISpecification
    {
        return $this->specialFactory->isTrue();
    }

    /**
     * Creates a specification verifying whether the candidate evaluates to boolean false.
     *
     * @return ISpecification Boolean false specification
     */
    public function isFalse(): ISpecification
    {
        return $this->specialFactory->isFalse();
    }

    // ==========================================
    // Domian SpecificationFactory aliases (1.4.4)
    // ==========================================

    /**
     * Domian alias for alwaysFalse().
     *
     * @return ISpecification
     */
    public function createAlwaysFalseSpecification(): ISpecification
    {
        return $this->alwaysFalse();
    }

    /**
     * Domian alias for alwaysFalse().
     *
     * @return ISpecification
     */
    public function createContradiction(): ISpecification
    {
        return $this->alwaysFalse();
    }

    /**
     * Domian alias for alwaysTrue().
     *
     * @return ISpecification
     */
    public function createAlwaysTrueSpecification(): ISpecification
    {
        return $this->alwaysTrue();
    }

    /**
     * Domian alias for alwaysTrue().
     *
     * @return ISpecification
     */
    public function createTautology(): ISpecification
    {
        return $this->alwaysTrue();
    }

    /**
     * Domian alias for isNotNull().
     *
     * @return ISpecification
     */
    public function createNotNullSpecification(): ISpecification
    {
        return $this->isNotNull();
    }

    /**
     * Domian `allObjects()`: every non-null candidate (NotNull on top of everything).
     *
     * @return ISpecification
     */
    public function allObjects(): ISpecification
    {
        return $this->isNotNull();
    }

    /**
     * Domian `allEntities()`: every entity (AllEntitiesSpecification, generalization of any entity specification).
     *
     * @return ISpecification
     */
    public function allEntities(): ISpecification
    {
        return new AllEntitiesSpecification();
    }

    /**
     * Domian alias for allEntities().
     *
     * @return ISpecification
     */
    public function entities(): ISpecification
    {
        return $this->allEntities();
    }

    /**
     * Domian alias for allEntities().
     *
     * @return ISpecification
     */
    public function entity(): ISpecification
    {
        return $this->allEntities();
    }
}
