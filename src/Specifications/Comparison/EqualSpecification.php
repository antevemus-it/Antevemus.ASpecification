<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Comparison;

/**
 * EqualSpecification - Leaf specification for strict equality (`===`).
 *
 * Validates whether the candidate value and type are strictly identical to the expected value,
 * with full support for algebraic subsumption and disjointness (RF-10): `x = 5 ⟂ x = 6`,
 * `x = 5 ⟂ x <> 5`, `x = 5 ⟂ x > 5`, `x = 5 ⊂ x > 3`, `¬(x = 5) ≡ x <> 5`.
 *
 * Features:
 * - Strict equality validation (`===`) of scalars and arrays; objects by identity
 * - Date-times by instant (2.1.0): a DateTimeInterface candidate equals a DateTimeInterface value
 *   when both denote the same instant, whatever their class or time zone, as the relational leaves,
 *   at(), the SQL translation (`"d" = :p`) and the algebra compare them. Up to 2.0.0 only the very
 *   same object was accepted, so the same specification accepted a row in SQL and refused the same
 *   value hydrated in memory (BUG-20261013-LIZI)
 * - Disjointness detection against other constants and inequalities (`>`, `<`, `!==`)
 *
 * The reference value is kept by reference, never cloned (as up to 2.0.0).
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
class EqualSpecification extends AbstractComparableValueBoundSpecification
{
    /**
     * @param mixed $value Expected reference value
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
        return RelationalOperator::EQUAL;
    }

    /**
     * {@inheritdoc}
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        if ($candidate === null) {
            return $this->value === null;
        }
        TypeCompatibility::assertEquatable($candidate, $this->value, 'EqualSpecification');
        return TypeCompatibility::sameValue($candidate, $this->value);
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
