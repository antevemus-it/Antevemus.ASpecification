<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Collection;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ILeafSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Specifications\NotSpecification;
use Antevemus\ASpecification\Specifications\SpecificationAlgebra;
use Antevemus\ASpecification\Contracts\ISerializableSpecification;
use Antevemus\ASpecification\Serialization\SpecificationCodec;

/**
 * AllEntitiesSpecification - Universal leaf specification for entities.
 *
 * Concrete leaf specification satisfied by any object of the specified entity type
 * (defaults to implementations of IEntity). Acts as the maximal generalization identity
 * in the repository partition DAG and subsumption engine.
 *
 * Features:
 * - Acceptance of any candidate matching the target entity type
 * - Axiomatic generalization over any specification targeting the same type or subtype
 * - Structural equality checking (`equals`, inherited: same concrete class, same target type and
 *   the same because()/withCode() annotations; up to 2.0.0 it ignored the annotations and took any
 *   subclass for an equal specification, BUG-20261013-7RQV)
 * - Universality and type disjointness only for the predicate of this class (2.1.0, forward 037): a
 *   subclass that redefines isSatisfiedBy() (active customers only) does not generalize every
 *   instance of the type, and the other side must be bounded by its type to be proven disjoint
 *
 * @template T of IEntity
 * @extends AbstractSpecification<T>
 * @implements ILeafSpecification<T>
 * @version    2.4.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Collection
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class AllEntitiesSpecification extends AbstractSpecification implements ILeafSpecification, ISerializableSpecification
{
    /**
     * @param string $targetType Target class or interface FQCN (defaults to IEntity::class)
     */
    public function __construct(
        private readonly string $targetType = IEntity::class
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return $this->targetType;
    }

    /**
     * {@inheritdoc}
     */
    public function isSatisfiedBy(?object $candidate): bool
    {
        if ($candidate === null) {
            return false;
        }

        return $candidate instanceof $this->targetType;
    }

    /**
     * {@inheritdoc}
     */
    public function isGeneralizationOf(ISpecification $otherSpecification): bool
    {
        if (SpecificationAlgebra::baseGeneralizes($this, $otherSpecification)) {
            return true;
        }
        if (!SpecificationAlgebra::evaluatesAs($this, self::class)) {
            // A subclass that redefines isSatisfiedBy() is not "every instance of the type"
            return false;
        }

        $other = SpecificationAlgebra::resolve($otherSpecification);
        if ($other instanceof NotSpecification) {
            // ¬A accepts candidates outside the entity type.
            return false;
        }

        return SpecificationAlgebra::isBoundedByType($other, $this->targetType);
    }

    /**
     * {@inheritdoc}
     */
    public function isDisjointWith(ISpecification $otherSpecification): bool
    {
        if (SpecificationAlgebra::baseDisjoint($this, $otherSpecification)) {
            return true;
        }

        // Only when single inheritance proves it (1.6.0: unrelated interfaces may share an instance) and
        // the other specification is bounded by its type (2.1.0: ¬all(Order) has the type Order but
        // accepts customers). A subclass that redefines isSatisfiedBy() may accept other instances.
        return SpecificationAlgebra::evaluatesAs($this, self::class)
            && SpecificationAlgebra::provenTypesDisjoint($this->targetType, SpecificationAlgebra::resolve($otherSpecification));
    }

    /**
     * {@inheritdoc}
     */
    public function __toString(): string
    {
        return sprintf('AllEntitiesSpecification(%s)', $this->targetType);
    }

    /**
     * {@inheritdoc}
     *
     * Serialized as `{"t": "allEntities"}`, with `"type"` when the target type is not IEntity (2.4.0, forward 041).
     */
    public function toArray(): array
    {
        return $this->targetType === IEntity::class ? [] : ['type' => $this->targetType];
    }

    /**
     * {@inheritdoc}
     */
    public static function fromArray(array $data, SpecificationCodec $codec): static
    {
        $codec->expectKeys($data, [], ['type']);

        return new static((string) $codec->optionalString($data, 'type', IEntity::class));
    }
}
