<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications;

use Antevemus\ASpecification\AbstractSpecification;
use Closure;

/**
 * PredicateSpecification - Leaf specification backed by an inline PHP closure.
 *
 * Wraps a `Closure(mixed $candidate): bool` so that an ad hoc business rule can take part in
 * the specification algebra (and, or, not, where) and in the Notification Pattern
 * (`because()`, `withCode()`, `evaluate()`), exactly like any other leaf. It is the leaf
 * created by `ISpecification::must()` (README example 7, forward 014 RN-02).
 *
 * Semantics:
 * - The closure receives the whole candidate and returns a boolean.
 * - An exception thrown inside the closure propagates from `isSatisfiedBy()` and becomes an
 *   error result under `evaluate()` (never a rule failure, never inverted by NOT).
 * - The closure is opaque: the SQL and TCriteria visitors refuse it with their
 *   "non translatable" exceptions; the ALinq visitor compiles it by calling `isSatisfiedBy()`.
 * - Equality (`equals()`) compares the closure by identity.
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @version    1.3.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class PredicateSpecification extends AbstractSpecification
{
    /**
     * @param Closure(mixed): bool $predicate Inline rule receiving the whole candidate
     * @param string $type Declared candidate type (defaults to `mixed`)
     */
    public function __construct(
        private readonly Closure $predicate,
        private readonly string $type = 'mixed'
    ) {
    }

    /**
     * Returns the wrapped closure.
     *
     * @return Closure(mixed): bool
     */
    public function getPredicate(): Closure
    {
        return $this->predicate;
    }

    /**
     * {@inheritdoc}
     *
     * Exceptions thrown by the closure propagate untouched.
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        return (bool) ($this->predicate)($candidate);
    }

    /**
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * {@inheritdoc}
     */
    public function __toString(): string
    {
        return 'PredicateSpecification';
    }
}
