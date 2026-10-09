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
 * - Strict equality validation (`===`); objects by identity (use SameInstantSpecification for dates)
 * - Disjointness detection against other constants and inequalities (`>`, `<`, `!==`)
 *
 * The reference value is kept by reference (identity semantics), never cloned.
 *
 * @template T
 * @extends AbstractComparableValueBoundSpecification<T>
 * @version    1.4.4
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
        return $candidate === $this->value;
    }

    /**
     * {@inheritdoc}
     *
     * Identity semantics: the reference object is never copied.
     */
    protected static function copyOf(mixed $value): mixed
    {
        return $value;
    }
}
