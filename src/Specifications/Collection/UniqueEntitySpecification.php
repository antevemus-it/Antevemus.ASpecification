<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Collection;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ILeafSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * UniqueEntitySpecification - Entity identity uniqueness leaf specification.
 *
 * Concrete leaf specification satisfied exclusively by instances of the same class
 * sharing the identical entity identifier (getEntityId()).
 *
 * Features:
 * - Exact class and entity identifier validation
 * - Immediate disjointness against distinct uniqueness specifications
 * - Structural equality checking by identity and entity class
 *
 * @template T of IEntity
 * @extends AbstractSpecification<T>
 * @implements ILeafSpecification<T>
 * @version    1.4.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Collection
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class UniqueEntitySpecification extends AbstractSpecification implements ILeafSpecification
{
    private readonly ?IEntity $entity;
    private readonly mixed $expectedId;
    private readonly string $entityClass;

    /**
     * @param T|string|int $entityOrId Reference entity instance or scalar identifier
     * @param class-string<T>|null $entityClass Target entity class (optional when scalar ID provided)
     */
    public function __construct(
        IEntity|string|int $entityOrId,
        ?string $entityClass = null
    ) {
        if ($entityOrId instanceof IEntity) {
            $this->entity = $entityOrId;
            $this->expectedId = $entityOrId->getEntityId();
            $this->entityClass = $entityClass ?? get_class($entityOrId);
        } else {
            $this->entity = null;
            $this->expectedId = $entityOrId;
            $this->entityClass = $entityClass ?? IEntity::class;
        }
    }

    /**
     * Returns the reference entity, if available.
     *
     * @return T|null
     */
    public function getEntity(): ?IEntity
    {
        return $this->entity;
    }

    /**
     * Returns the expected unique entity identifier.
     */
    public function getExpectedId(): mixed
    {
        return $this->expectedId;
    }

    /**
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return $this->entityClass;
    }

    /**
     * {@inheritdoc}
     */
    public function isSatisfiedBy(?object $candidate): bool
    {
        if (!$candidate instanceof IEntity) {
            return false;
        }

        if ($this->entityClass !== IEntity::class && !is_a($candidate, $this->entityClass)) {
            return false;
        }

        return (string)$candidate->getEntityId() === (string)$this->expectedId;
    }

    /**
     * {@inheritdoc}
     */
    public function isGeneralizationOf(ISpecification $otherSpecification): bool
    {
        if ($otherSpecification === null) {
            throw new \InvalidArgumentException('The specification cannot be null');
        }

        return $this->equals($otherSpecification);
    }

    /**
     * {@inheritdoc}
     */
    public function isDisjointWith(ISpecification $otherSpecification): bool
    {
        if ($otherSpecification === null) {
            throw new \InvalidArgumentException('The specification cannot be null');
        }

        if ($otherSpecification instanceof self) {
            return !$this->equals($otherSpecification);
        }

        $otherType = $otherSpecification->getType();
        if ($otherType !== 'mixed' && $this->entityClass !== IEntity::class && !is_a($this->entityClass, $otherType, true)) {
            return true;
        }

        return false;
    }

    /**
     * Checks structural equality.
     */
    public function equals(mixed $other): bool
    {
        if (!$other instanceof self) {
            return false;
        }

        $sameType = ($this->entityClass === IEntity::class || $other->getType() === IEntity::class)
            ? true
            : $other->getType() === $this->entityClass;

        return $sameType && (string)$other->getExpectedId() === (string)$this->expectedId;
    }

    /**
     * {@inheritdoc}
     */
    public function __toString(): string
    {
        return sprintf('UniqueEntitySpecification(%s#%s)', $this->entityClass, (string)$this->expectedId);
    }
}
