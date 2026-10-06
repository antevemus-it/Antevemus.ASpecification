<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Factory;

use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * ISpecificationWrapperFactory - Factory contract for fluent specification wrappers
 *
 * Contract for factories creating fluent wrappers around specifications.
 * These methods wrap existing specifications with more descriptive and fluent names,
 * without altering behavior. They are essentially identity functions with expressive names
 * to improve code readability.
 *
 * Example usage:
 * <code>
 * // Without wrapper
 * $spec = new ActiveUserSpecification();
 *
 * // With fluent wrapper
 * $spec = $factory->is(new ActiveUserSpecification());
 * // or
 * $spec = $factory->isA(new ActiveUserSpecification());
 * </code>
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Factory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface ISpecificationWrapperFactory extends ISpecificationFactory
{
    /**
     * Fluent wrapper returning the specification unchanged.
     *
     * Idiomatic usage: "isSatisfiedBy someSpec"
     * Improves readability in assertion contexts.
     *
     * @template T
     * @param ISpecification<T> $specification Specification to wrap
     * @return ISpecification<T> The exact same specification
     */
    public function isSatisfiedBy(ISpecification $specification): ISpecification;

    /**
     * Fluent wrapper returning the specification unchanged.
     *
     * Idiomatic usage: "a someSpec"
     *
     * @template T
     * @param ISpecification<T> $specification Specification to wrap
     * @return ISpecification<T> The exact same specification
     */
    public function a(ISpecification $specification): ISpecification;

    /**
     * Fluent wrapper returning the specification unchanged.
     *
     * Idiomatic usage: "an someSpec"
     *
     * @template T
     * @param ISpecification<T> $specification Specification to wrap
     * @return ISpecification<T> The exact same specification
     */
    public function an(ISpecification $specification): ISpecification;

    /**
     * Fluent wrapper returning the specification unchanged.
     *
     * Idiomatic usage: "is someSpec"
     *
     * @template T
     * @param ISpecification<T> $specification Specification to wrap
     * @return ISpecification<T> The exact same specification
     */
    public function is(ISpecification $specification): ISpecification;

    /**
     * Fluent wrapper returning the specification unchanged.
     *
     * Idiomatic usage: "isA someSpec"
     *
     * @template T
     * @param ISpecification<T> $specification Specification to wrap
     * @return ISpecification<T> The exact same specification
     */
    public function isA(ISpecification $specification): ISpecification;

    /**
     * Fluent wrapper returning the specification unchanged.
     *
     * Idiomatic usage: "isAn someSpec"
     *
     * @template T
     * @param ISpecification<T> $specification Specification to wrap
     * @return ISpecification<T> The exact same specification
     */
    public function isAn(ISpecification $specification): ISpecification;

    /**
     * Fluent wrapper returning the specification unchanged.
     *
     * Idiomatic usage: "are someSpec"
     *
     * @template T
     * @param ISpecification<T> $specification Specification to wrap
     * @return ISpecification<T> The exact same specification
     */
    public function are(ISpecification $specification): ISpecification;

    /**
     * Fluent wrapper returning the specification unchanged.
     *
     * Idiomatic usage: "isFrom someSpec"
     *
     * @template T
     * @param ISpecification<T> $specification Specification to wrap
     * @return ISpecification<T> The exact same specification
     */
    public function isFrom(ISpecification $specification): ISpecification;
}
