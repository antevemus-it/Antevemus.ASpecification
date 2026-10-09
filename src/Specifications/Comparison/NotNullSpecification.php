<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Comparison;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ILeafSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysFalseSpecification;
use Antevemus\ASpecification\Specifications\SpecificationAlgebra;

/**
 * NotNullSpecification - Leaf specification validating that the candidate is not null.
 *
 * Checks directly whether the candidate value or object is strictly different from null.
 * As in Domian, it sits "on top of everything": it is a generalization of every other
 * specification (no specification is satisfied by null except the tautology), and it is
 * disjoint only with the contradiction, with `isNull()` and with the negation of a
 * specification that generalizes it.
 *
 * Features:
 * - Direct non-null verification (`!== null`)
 * - Universal candidate acceptance across types ('mixed')
 * - Universal generalization (`isGeneralizationOf` is always true)
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @implements ILeafSpecification<T>
 * @version    1.4.4
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Comparison
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class NotNullSpecification extends AbstractSpecification implements ILeafSpecification
{
    /**
     * Verifies whether the provided candidate satisfies the non-null rule.
     *
     * @param mixed $candidate The value or object to validate
     * @return bool True if candidate is not null, false otherwise
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        return $candidate !== null;
    }

    /**
     * Returns the type of candidate validated by this specification.
     *
     * @return string Returns 'mixed' as this leaf specification accepts any type
     */
    public function getType(): string
    {
        return 'mixed';
    }

    /**
     * {@inheritdoc}
     *
     * Every specification is a special case of "not null" (Domian: NotNullSpecification is on top
     * of everything).
     */
    public function isGeneralizationOf(ISpecification $otherSpecification): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function isDisjointWith(ISpecification $otherSpecification): bool
    {
        $other = SpecificationAlgebra::resolve($otherSpecification);
        if ($other instanceof IsNullSpecification || $other instanceof AlwaysFalseSpecification) {
            return true;
        }

        return SpecificationAlgebra::baseDisjoint($this, $other);
    }
}
