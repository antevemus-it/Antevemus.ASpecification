<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications;

use Antevemus\ASpecification\Contracts\ISpecification;
use Closure;

/**
 * SpecificationPredicate - Specification adapter for native PHP Closures and callbacks.
 *
 * Wraps an ISpecification instance, converting it into a Closure or invokable object
 * compatible with native PHP higher-order functions such as array_filter(), array_map(),
 * usort(), and iterable collections.
 *
 * Features:
 * - Direct static conversion via SpecificationPredicate::from($spec) returning Closure(mixed): bool
 * - Static negated conversion via SpecificationPredicate::negate($spec)
 * - Direct invocable (__invoke) support as an immutable callable object
 * - Clean integration with PHP iterable filtering pipelines
 *
 * @template T
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final readonly class SpecificationPredicate
{
    /**
     * Initializes an invokable predicate wrapping the target specification.
     *
     * @param ISpecification<T> $specification Specification to adapt
     * @param bool $negated If true, inverts boolean result of isSatisfiedBy()
     */
    public function __construct(
        private ISpecification $specification,
        private bool $negated = false
    ) {
    }

    /**
     * Creates a native PHP Closure that delegates evaluation to ISpecification::isSatisfiedBy().
     *
     * Ideal for direct usage in array_filter($items, SpecificationPredicate::from($spec)).
     *
     * @template TCandidate
     * @param ISpecification<TCandidate> $specification Base specification
     * @return Closure(mixed): bool Evaluation closure
     */
    public static function from(ISpecification $specification): Closure
    {
        return static fn(mixed $candidate): bool => $specification->isSatisfiedBy($candidate);
    }

    /**
     * Creates a native PHP Closure that inverts the result of ISpecification::isSatisfiedBy().
     *
     * Ideal for rejecting elements that satisfy the specification in array_filter().
     *
     * @template TCandidate
     * @param ISpecification<TCandidate> $specification Base specification to negate
     * @return Closure(mixed): bool Negated evaluation closure
     */
    public static function negate(ISpecification $specification): Closure
    {
        return static fn(mixed $candidate): bool => !$specification->isSatisfiedBy($candidate);
    }

    /**
     * Allows the SpecificationPredicate instance to be directly invoked as a callable.
     *
     * @param mixed $candidate Candidate object or value to evaluate
     * @return bool True if candidate satisfies the predicate (respecting the $negated flag)
     */
    public function __invoke(mixed $candidate): bool
    {
        $satisfied = $this->specification->isSatisfiedBy($candidate);

        return $this->negated ? !$satisfied : $satisfied;
    }

    /**
     * Converts this instance into an explicit Closure.
     *
     * @return Closure(mixed): bool
     */
    public function toClosure(): Closure
    {
        return $this->negated
            ? self::negate($this->specification)
            : self::from($this->specification);
    }

    /**
     * Returns a new instance with inverted logical polarity.
     *
     * @return self
     */
    public function inverted(): self
    {
        return new self($this->specification, !$this->negated);
    }

    /**
     * Returns the underlying specification wrapped by this predicate.
     *
     * @return ISpecification<T>
     */
    public function getSpecification(): ISpecification
    {
        return $this->specification;
    }

    /**
     * Indicates whether this predicate is operating in negated mode.
     *
     * @return bool
     */
    public function isNegated(): bool
    {
        return $this->negated;
    }
}
