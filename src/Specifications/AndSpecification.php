<?php

namespace Antevemus\ASpecification\Specifications;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Results\SpecificationFailure;
use Antevemus\ASpecification\Results\SpecificationResult;

/**
 * AndSpecification class.
 *
 * Implementação de uma especificação composta que representa uma conjunção (AND lógico)
 * de duas especificações.
 *
 * Esta especificação é satisfeita se e somente se AMBAS as especificações
 * (esquerda E direita) forem satisfeitas pelo candidato.
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @implements ICompositeSpecification<T>
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Specifications
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class AndSpecification extends AbstractSpecification implements ICompositeSpecification
{
    use SubsumptionAndEqualityTrait;
    /**
     * @param ISpecification<T> $left Especificação do lado esquerdo
     * @param ISpecification<T> $right Especificação do lado direito
     */
    /**
     * Construtor da especificação.
     *
     * @param mixed $value Valor esperado
     */

    public function __construct(
        private readonly ISpecification $left,
        private readonly ISpecification $right
    ) {
    }

    /**
     * {@inheritdoc}
     *
     * Avalia a conjunção de regras agregando diagnósticos de ambas as ramificações.
     *
     * @param mixed $candidate Objeto ou valor a ser validado
     * @return SpecificationResult Resultado consolidado com eventuais falhas
     */
    public function evaluate(mixed $candidate): SpecificationResult
    {
        $leftResult = $this->left->evaluate($candidate);
        $rightResult = $this->right->evaluate($candidate);

        $combined = SpecificationResult::combine($leftResult, $rightResult);

        if (!$combined->isSatisfied && ($this->customReason !== null || $this->customCode !== null)) {
            $topFailure = new SpecificationFailure(
                message: $this->customReason ?? "Conjunção (AND) violada.",
                code: $this->customCode,
                ruleName: 'AndSpecification'
            );
            return new SpecificationResult(false, array_merge([$topFailure], $combined->failures));
        }

        return $combined;
    }

    /**
     * {@inheritdoc}
     *
     * @param mixed $candidate Objeto ou valor a ser validado
     * @return bool True se ambas as ramificações forem satisfeitas
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        // Null nunca satisfaz uma especificação
        if ($candidate === null) {
            return false;
        }

        // Ambas as especificações devem ser satisfeitas (short-circuit evaluation)
        return $this->left->isSatisfiedBy($candidate) && $this->right->isSatisfiedBy($candidate);
    }

    /**
     * {@inheritdoc}
     */
    /**
     * {@inheritdoc}
     */

    public function getType(): string
    {
        // Retorna o tipo da especificação esquerda
        // (assumindo que ambas têm o mesmo tipo ou tipos compatíveis)
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
    public function accept(\Antevemus\ASpecification\Contracts\ISpecificationVisitor $visitor): mixed
    {
        return $visitor->visitComposite($this);
    }

    /**
     * {@inheritdoc}
     */
    public function __toString(): string
    {
        return sprintf('(%s AND %s)', $this->getSpecName($this->left), $this->getSpecName($this->right));
    }

    /**
     * Obtém o nome legível de uma especificação.
     *
     * @param ISpecification<T> $spec
     * @return string
     */
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
     * Uma conjunção AND é uma generalização de outra especificação se
     * qualquer um dos seus lados é uma generalização da outra especificação.
     */
    public function isGeneralizationOf(ISpecification $otherSpecification): bool
    {
        if ($otherSpecification === null) {
            throw new \InvalidArgumentException('A especificação não pode ser null');
        }

        if ($this->checkBaseGeneralization($otherSpecification)) {
            return true;
        }

        // Para (A AND B) ser generalização (superconjunto) de X, AMBOS A e B devem generalizar X
        return $this->left->isGeneralizationOf($otherSpecification)
            && $this->right->isGeneralizationOf($otherSpecification);
    }

    /**
     * {@inheritdoc}
     */
    public function isDisjointWith(ISpecification $otherSpecification): bool
    {
        if ($otherSpecification === null) {
            throw new \InvalidArgumentException('A especificação não pode ser null');
        }

        // Se qualquer lado é disjunto com a outra especificação, então a conjunção é disjunta
        return $this->left->isDisjointWith($otherSpecification)
            || $this->right->isDisjointWith($otherSpecification);
    }

    /**
     * {@inheritdoc}
     *
     * Uma conjunção AND pode ser uma interseção se representa a combinação
     * de especificações que formam uma interseção.
     */
    public function isIntersectionOf(ISpecification $otherSpecification): bool
    {
        if ($otherSpecification === null) {
            throw new \InvalidArgumentException('A especificação não pode ser null');
        }

        // Uma AND specification é literalmente uma interseção de suas partes
        // Verifica se a outra especificação também é uma interseção equivalente
        if ($otherSpecification instanceof AndSpecification) {
            return ($this->left === $otherSpecification->getLeftSide() && $this->right === $otherSpecification->getRightSide())
                || ($this->left === $otherSpecification->getRightSide() && $this->right === $otherSpecification->getLeftSide());
        }

        return false;
    }

    /**
     * {@inheritdoc}
     *
     * Uma conjunção AND intersecta com outra especificação se ambos os lados
     * podem potencialmente intersectar com ela.
     */
    public function intersectsWith(ISpecification $otherSpecification): bool
    {
        if ($otherSpecification === null) {
            throw new \InvalidArgumentException('A especificação não pode ser null');
        }

        // Para uma conjunção intersectar, ambos os lados devem intersectar
        return $this->left->intersectsWith($otherSpecification)
            && $this->right->intersectsWith($otherSpecification);
    }
}
