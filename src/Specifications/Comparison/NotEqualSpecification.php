<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Comparison;

/**
 * NotEqualSpecification - Leaf specification for strict inequality (`!==`).
 *
 * Validates whether the candidate is strictly different from the parameterized value.
 *
 * Features:
 * - Strict inequality validation (`!==`) of scalars and arrays; objects by identity
 * - Date-times by instant (2.1.0): a DateTimeInterface candidate at the instant of a DateTimeInterface
 *   value is not accepted, whatever its class or time zone (the complement of equalTo(), as in SQL
 *   `"d" <> :p`; up to 2.0.0 only the very same object was refused, BUG-20261013-LIZI)
 * - Generalization of equality and range specifications that exclude the value (RF-10):
 *   `x <> 5 ⊇ x = 3`, `x <> 5 ⊇ x < 5`; `x <> 5 ⟂ x = 5`; `¬(x <> 5) ≡ x = 5`
 *
 * @template T
 * @extends AbstractComparableValueBoundSpecification<T>
 * @version    2.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Comparison
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class NotEqualSpecification extends AbstractComparableValueBoundSpecification
{
    /**
     * @param mixed $value Forbidden reference value
     */
    public function __construct(mixed $value)
    {
        parent::__construct($value);
    }

    /**
     * {@inheritdoc}
     */
    public function getRelationalOperator(): RelationalOperator
    {
        return RelationalOperator::NOT_EQUAL;
    }

    /**
     * {@inheritdoc}
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        if ($candidate === null) {
            return false;
        }
        TypeCompatibility::assertEquatable($candidate, $this->value, 'NotEqualSpecification');
        return !TypeCompatibility::sameValue($candidate, $this->value);
    }

    /**
     * {@inheritdoc}
     *
     * Identity semantics: the reference value is kept as given, never copied (a mutable DateTime
     * changed after the specification was built moves the instant it is compared with).
     */
    protected static function copyOf(mixed $value): mixed
    {
        return $value;
    }
}
