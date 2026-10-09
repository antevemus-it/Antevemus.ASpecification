<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Comparison;

/**
 * NotEqualSpecification - Leaf specification for strict inequality (`!==`).
 *
 * Validates whether the candidate is strictly different from the parameterized value.
 *
 * Features:
 * - Strict inequality validation (`!==`)
 * - Generalization of equality and range specifications that exclude the value (RF-10):
 *   `x <> 5 ⊇ x = 3`, `x <> 5 ⊇ x < 5`; `x <> 5 ⟂ x = 5`; `¬(x <> 5) ≡ x = 5`
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
        return $candidate !== $this->value;
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
