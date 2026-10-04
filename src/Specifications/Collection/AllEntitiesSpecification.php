<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Collection;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ILeafSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * AllEntitiesSpecification - Especificação universal de entidades
 *
 * Especificação folha concreta que é satisfeita por qualquer objeto do tipo de entidade especificado
 * (por padrão qualquer implementação de IEntity). Atua como elemento neutro (generalização máxima)
 * no motor de subsunção do grafo de particionamento.
 *
 * Funcionalidades:
 * - Aceitação de qualquer candidato compatível com o tipo de entidade alvo
 * - Generalização axiomática de qualquer especificação que atue sobre o mesmo tipo ou subtipo
 * - Verificação de igualdade estrutural (equals)
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
class AllEntitiesSpecification extends AbstractSpecification implements ILeafSpecification
{
    /**
     * @param string $targetType FQN da classe ou interface alvo (padrão: IEntity::class)
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
     * Verifica igualdade estrutural.
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
