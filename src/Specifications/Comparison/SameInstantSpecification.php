<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Comparison;

use DateTimeInterface;

/**
 * SameInstantSpecification - Leaf specification for date-time equality by instant (`==`).
 *
 * Named value-bound leaf behind `at()`, `atTheSameTimeAs()` and `isAtTheSameTimeAs()`: two
 * DateTimeInterface values denote the same instant when their timestamps are equal, whatever
 * their class (DateTime/DateTimeImmutable) or time zone. It is an EqualSpecification for the
 * algebra and the translators (`=`), so `at(d) ⟂ before(d)`, `before(d + 1) ⊇ at(d)` and
 * `at(d) ⟂ not(at(d))` hold.
 *
 * The reference date is cloned when it is a mutable DateTime.
 *
 * @template T of DateTimeInterface
 * @extends EqualSpecification<T>
 * @version    1.4.4
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Comparison
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class SameInstantSpecification extends EqualSpecification
{
    /**
     * @param DateTimeInterface $instant Target instant
     */
    public function __construct(DateTimeInterface $instant)
    {
        parent::__construct($instant);
    }

    /**
     * Returns the target instant.
     */
    public function getValue(): DateTimeInterface
    {
        return $this->value;
    }

    /**
     * {@inheritdoc}
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        return TypeCompatibility::isDateCandidate($candidate, 'SameInstantSpecification', $this->value)
            && $candidate == $this->value;
    }

    /**
     * {@inheritdoc}
     *
     * Value semantics: a mutable DateTime is cloned so the specification cannot drift.
     */
    protected static function copyOf(mixed $value): mixed
    {
        return $value instanceof \DateTime ? clone $value : $value;
    }
}
