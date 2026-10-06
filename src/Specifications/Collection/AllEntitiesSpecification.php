<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Collection;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ILeafSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;

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
 * - Structural equality checking (`equals`)
 *
 * @template T of IEntity
 * @extends AbstractSpecification<T>
 * @implements ILeafSpecification<T>
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Collection
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class AllEntitiesSpecification extends AbstractSpecification implements ILeafSpecification
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
        if ($otherSpecification === null) {
            throw new \InvalidArgumentException('A especificação não pode ser null');
        }

        if ($this->equals($otherSpecification)) {
            return true;
        }

        $otherType = $otherSpecification->getType();
        if ($otherType === $this->targetType || is_subclass_of($otherType, $this->targetType)) {
            return true;
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function isDisjointWith(ISpecification $otherSpecification): bool
    {
        if ($otherSpecification === null) {
            throw new \InvalidArgumentException('A especificação não pode ser null');
        }

        $otherType = $otherSpecification->getType();
        if ($otherType !== 'mixed' && !is_a($otherType, $this->targetType, true) && !is_a($this->targetType, $otherType, true)) {
            return true;
        }

        return false;
    }

    /**
     * Checks structural equality.
     */
    public function equals(mixed $other): bool
    {
        return $other instanceof self && $other->getType() === $this->targetType;
    }

    /**
     * {@inheritdoc}
     */
    public function __toString(): string
    {
        return sprintf('AllEntitiesSpecification(%s)', $this->targetType);
    }
}
