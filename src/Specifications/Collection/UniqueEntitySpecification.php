<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Collection;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ILeafSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * UniqueEntitySpecification - Especificação de unicidade por identidade de entidade
 *
 * Especificação folha concreta satisfeita exclusivamente por instâncias da mesma classe
 * que compartilhem o mesmo identificador de entidade (getEntityId()).
 *
 * Funcionalidades:
 * - Validação exata de classe e identidade de entidade
 * - Subsunção por disjunção imediata contra outras especificações de unicidade distintas
 * - Igualdade estrutural por identidade e classe
 *
 * @template T of IEntity
 * @extends AbstractSpecification<T>
 * @implements ILeafSpecification<T>
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Collection
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class UniqueEntitySpecification extends AbstractSpecification implements ILeafSpecification
{
    private readonly ?IEntity $entity;
    private readonly mixed $expectedId;
    private readonly string $entityClass;

    /**
     * @param T|string|int $entityOrId Entidade de referência ou identificador escalar
     * @param class-string<T>|null $entityClass Classe da entidade (opcional quando informado ID escalar)
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
     * Retorna a entidade de referência, se disponível.
     *
     * @return T|null
     */
    public function getEntity(): ?IEntity
    {
        return $this->entity;
    }

    /**
     * Retorna o identificador único esperado pela especificação.
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
            throw new \InvalidArgumentException('A especificação não pode ser null');
        }

        return $this->equals($otherSpecification);
    }

    /**
     * {@inheritdoc}
     */
    public function isDisjointWith(ISpecification $otherSpecification): bool
    {
        if ($otherSpecification === null) {
            throw new \InvalidArgumentException('A especificação não pode ser null');
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
     * Verifica igualdade estrutural.
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
