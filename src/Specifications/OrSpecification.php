<?php

namespace Antevemus\ASpecification\Specifications;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Results\SpecificationFailure;
use Antevemus\ASpecification\Results\SpecificationResult;

/**
 * OrSpecification class.
 *
 * Implementação de uma especificação composta que representa uma disjunção (OR lógico)
 * de duas especificações.
 *
 * Esta especificação é satisfeita se PELO MENOS UMA das especificações
 * (esquerda OU direita) for satisfeita pelo candidato.
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
class OrSpecification extends AbstractSpecification implements ICompositeSpecification
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
     * Avalia a disjunção retornando satisfeito caso qualquer uma das alternativas seja atendida.
     *
     * @param mixed $candidate Objeto ou valor a ser validado
     * @return SpecificationResult Resultado consolidado com falhas se ambas forem reprovadas
     */
    public function evaluate(mixed $candidate): SpecificationResult
    {
        $leftResult = $this->left->evaluate($candidate);
        if ($leftResult->isSatisfied) {
            return $leftResult;
        }

        $rightResult = $this->right->evaluate($candidate);
        if ($rightResult->isSatisfied) {
            return $rightResult;
        }

        $combined = SpecificationResult::combine($leftResult, $rightResult);

        if ($this->customReason !== null || $this->customCode !== null) {
            $topFailure = new SpecificationFailure(
                message: $this->customReason ?? "Nenhuma das alternativas da disjunção (OR) foi satisfeita.",
                code: $this->customCode,
                ruleName: 'OrSpecification'
            );
            return new SpecificationResult(false, array_merge([$topFailure], $combined->failures));
        }

        return $combined;
    }

    /**
     * {@inheritdoc}
     *
     * @param mixed $candidate Objeto ou valor a ser validado
     * @return bool True se ao menos uma das alternativas for satisfeita
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        // Null nunca satisfaz uma especificação
        if ($candidate === null) {
            return false;
        }

        // Pelo menos uma das especificações deve ser satisfeita (short-circuit evaluation)
        return $this->left->isSatisfiedBy($candidate) || $this->right->isSatisfiedBy($candidate);
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
        return sprintf('(%s OR %s)', $this->getSpecName($this->left), $this->getSpecName($this->right));
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
     * Uma disjunção OR é uma generalização de outra especificação se
     * ambos os lados são generalizações da outra especificação.
     */
    public function isGeneralizationOf(ISpecification $otherSpecification): bool
    {
        if ($otherSpecification === null) {
            throw new \InvalidArgumentException('A especificação não pode ser null');
        }

        // Ambos os lados devem ser generalização para a disjunção ser generalização
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

        // Ambos os lados devem ser disjuntos para a disjunção ser disjunta
        return $this->left->isDisjointWith($otherSpecification)
            && $this->right->isDisjointWith($otherSpecification);
    }

    /**
     * {@inheritdoc}
     *
     * Uma disjunção OR geralmente não é uma interseção, a menos que seja
     * semanticamente equivalente a uma.
     */
    public function isIntersectionOf(ISpecification $otherSpecification): bool
    {
        if ($otherSpecification === null) {
            throw new \InvalidArgumentException('A especificação não pode ser null');
        }

        // Uma OR specification geralmente não é uma interseção
        // Implementação conservadora
        return false;
    }

    /**
     * {@inheritdoc}
     *
     * Uma disjunção OR intersecta com outra especificação se pelo menos
     * um dos lados intersecta com ela.
     */
    public function intersectsWith(ISpecification $otherSpecification): bool
    {
        if ($otherSpecification === null) {
            throw new \InvalidArgumentException('A especificação não pode ser null');
        }

        // Para uma disjunção intersectar, pelo menos um lado deve intersectar
        return $this->left->intersectsWith($otherSpecification)
            || $this->right->intersectsWith($otherSpecification);
    }

    /**
     * {@inheritdoc}
     *
     * Satisfacao parcial de especificacoes disjuntivas (OR) nao e suportada.
     *
     * @param object $candidate Objeto candidato
     * @return ICompositeSpecification|null
     * @throws \InvalidArgumentException Sempre lancado para especificacoes disjuntivas
     */
    public function remainderUnsatisfiedBy(object $candidate): ?ICompositeSpecification
    {
        throw new \InvalidArgumentException('Satisfação parcial de especificações disjuntivas não é suportada');
    }
}
