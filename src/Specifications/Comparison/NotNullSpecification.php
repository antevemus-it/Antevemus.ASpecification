<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Comparison;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ILeafSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysFalseSpecification;
use Antevemus\ASpecification\Specifications\SpecificationAlgebra;
use Antevemus\ASpecification\Contracts\ISerializableSpecification;
use Antevemus\ASpecification\Serialization\SpecificationCodec;

/**
 * NotNullSpecification - Leaf specification validating that the candidate is not null.
 *
 * Checks directly whether the candidate value or object is strictly different from null.
 * As in Domian, it sits "on top of everything": it is a generalization of every specification that
 * never accepts null, and it is disjoint only with the contradiction, with `isNull()` and with the
 * negation of a specification that generalizes it.
 *
 * Features:
 * - Direct non-null verification (`!== null`)
 * - Universal candidate acceptance across types ('mixed')
 * - Generalization of every specification that refuses null (2.1.0, forward 037: up to 2.0.0 it
 *   claimed to generalize everything, isNull(), equalTo(null), defaultValue() and alwaysTrue()
 *   included, which accept null; SpecificationAlgebra::refusesNull())
 * - A subclass that redefines isSatisfiedBy() gets the shared axioms only
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @implements ILeafSpecification<T>
 * @version    2.4.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Comparison
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class NotNullSpecification extends AbstractSpecification implements ILeafSpecification, ISerializableSpecification
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
     * Every specification that never accepts null is a special case of "not null" (Domian:
     * NotNullSpecification is on top of everything); one that may accept null is not.
     */
    public function isGeneralizationOf(ISpecification $otherSpecification): bool
    {
        if (!SpecificationAlgebra::evaluatesAs($this, self::class)) {
            return SpecificationAlgebra::baseGeneralizes($this, $otherSpecification);
        }

        return SpecificationAlgebra::baseGeneralizes($this, $otherSpecification)
            || SpecificationAlgebra::refusesNull(SpecificationAlgebra::resolve($otherSpecification));
    }

    /**
     * {@inheritdoc}
     */
    public function isDisjointWith(ISpecification $otherSpecification): bool
    {
        $other = SpecificationAlgebra::resolve($otherSpecification);
        if ($other instanceof AlwaysFalseSpecification) {
            return true;
        }
        if ($other instanceof IsNullSpecification && SpecificationAlgebra::evaluatesAs($this, self::class)
            && SpecificationAlgebra::evaluatesAs($other, IsNullSpecification::class)) {
            return true;
        }

        return SpecificationAlgebra::baseDisjoint($this, $other);
    }

    /**
     * {@inheritdoc}
     *
     * Serialized as `{"t": "notNull"}` (2.4.0, forward 041).
     */
    public function toArray(): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public static function fromArray(array $data, SpecificationCodec $codec): static
    {
        $codec->expectKeys($data, []);

        return new static();
    }
}
