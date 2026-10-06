<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Factory;

use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * ISpecialSpecificationFactory - Factory contract for special specifications
 *
 * Contract for factories creating constant, nullability, and truth-evaluating specifications.
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Factory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface ISpecialSpecificationFactory extends ISpecificationFactory
{
    /**
     * Creates a specification that always evaluates to true.
     *
     * Useful for test cases, default specifications, or composition base cases.
     *
     * @return ISpecification Specification that is always satisfied
     */
    public function alwaysTrue(): ISpecification;

    /**
     * Creates a specification that always evaluates to false.
     *
     * Useful for test cases, total negation, or rejecting all candidates.
     *
     * @return ISpecification Specification that is never satisfied
     */
    public function alwaysFalse(): ISpecification;

    /**
     * Creates a specification that verifies if candidate is null.
     *
     * @return ISpecification Specification that checks for null
     */
    public function isNull(): ISpecification;

    /**
     * Creates a specification that verifies if candidate is not null.
     *
     * @return ISpecification Specification that checks for non-null
     */
    public function isNotNull(): ISpecification;

    /**
     * Creates a specification that verifies if candidate is true.
     *
     * Useful for boolean flags or truthiness verification.
     *
     * @return ISpecification Specification that checks for true
     */
    public function isTrue(): ISpecification;

    /**
     * Creates a specification that verifies if candidate is false.
     *
     * Useful for boolean flags or falsiness verification.
     *
     * @return ISpecification Specification that checks for false
     */
    public function isFalse(): ISpecification;
}
