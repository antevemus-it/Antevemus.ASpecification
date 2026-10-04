<?php

namespace Antevemus\ASpecification\Specifications\Logical;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Specifications\SubsumptionAndEqualityTrait;

/**
 * JointDenialSpecification class.
 *
 * Implementação do operador lógico NOR (Negação Conjunta).
 * Retorna true apenas se ambas as especificações repassadas retornarem false.
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @implements ICompositeSpecification<T>
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Logical
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class JointDenialSpecification extends AbstractSpecification implements ICompositeSpecification
{
    use SubsumptionAndEqualityTrait;
    /**
     * @param ISpecification<T> $left Especificação do lado esquerdo
     * @param ISpecification<T> $right Especificação do lado direito
     */
    public function __construct(
        private readonly ISpecification $left,
        private readonly ISpecification $right
    ) {
    }

    /**
     * Verifica se o candidato falha em ambas as regras (NOR).
     *
     * @param mixed $candidate Objeto ou valor a ser validado
     * @return bool
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        return !$this->left->isSatisfiedBy($candidate) && !$this->right->isSatisfiedBy($candidate);
    }

    /**
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return $this->left->getType();
    }

    /**
     * {@inheritdoc}
     */
    public function getLeftSide(): ?ISpecification
    {
        return $this->left;
    }

    /**
     * {@inheritdoc}
     */
    public function getRightSide(): ?ISpecification
    {
        return $this->right;
    }

    /**
     * {@inheritdoc}
     */
    public function getSpecifications(): array
    {
        return [$this->left, $this->right];
    }

    /**
     * {@inheritdoc}
     */
    public function __toString(): string
    {
        return sprintf('(%s NOR %s)', $this->getSpecName($this->left), $this->getSpecName($this->right));
    }

    private function getSpecName(ISpecification $spec): string
    {
        if ($spec instanceof ICompositeSpecification) {
            return (string) $spec;
        }

        $className = get_class($spec);
        $parts = explode('\\', $className);
        return end($parts);
    }

    /**
     * {@inheritdoc}
     *
     * @param ISpecification $otherSpecification
     * @return bool
     */
    public function isGeneralizationOf(ISpecification $otherSpecification): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     *
     * @param ISpecification $otherSpecification
     * @return bool
     */
    public function isDisjointWith(ISpecification $otherSpecification): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     *
     * @param ISpecification $otherSpecification
     * @return bool
     */
    public function isIntersectionOf(ISpecification $otherSpecification): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     *
     * @param ISpecification $otherSpecification
     * @return bool
     */
    public function intersectsWith(ISpecification $otherSpecification): bool
    {
        return false;
    }
}
