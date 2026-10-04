<?php

namespace Antevemus\ASpecification\Specifications;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Results\SpecificationFailure;
use Antevemus\ASpecification\Results\SpecificationResult;
use ReflectionClass;

/**
 * NotSpecification class.
 *
 * Implementação de uma especificação composta que representa a negação (NOT lógico)
 * de uma especificação.
 *
 * Esta especificação é satisfeita se e somente se a especificação interna
 * NÃO for satisfeita pelo candidato.
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
class NotSpecification extends AbstractSpecification implements ICompositeSpecification
{
    use SubsumptionAndEqualityTrait;
    /**
     * @param ISpecification<T> $specification Especificação a ser negada
     */
    /**
     * Construtor da especificação parametrizada.
     *
     * @param string $fieldName Nome da propriedade
     * @param ISpecification<mixed> $specification Especificação a ser aplicada
     */

    public function __construct(
        private readonly ISpecification $specification
    ) {
    }

    /**
     * {@inheritdoc}
     *
     * Avalia a negação lógica invertendo a aprovação diagnóstica.
     *
     * @param mixed $candidate Objeto ou valor a ser validado
     * @return SpecificationResult Resultado da avaliação negada
     */
    public function evaluate(mixed $candidate): SpecificationResult
    {
        if ($candidate === null) {
            return SpecificationResult::failure(
                message: "Candidato nulo não é permitido.",
                code: $this->customCode,
                ruleName: 'NotSpecification'
            );
        }

        $innerResult = $this->specification->evaluate($candidate);
        if (!$innerResult->isSatisfied) {
            return SpecificationResult::satisfied();
        }

        $innerName = (new ReflectionClass($this->specification))->getShortName();
        $message = $this->customReason ?? sprintf("A condição negada '%s' foi indevidamente satisfeita.", $innerName);

        return SpecificationResult::failure(
            message: $message,
            code: $this->customCode,
            ruleName: 'NotSpecification'
        );
    }

    /**
     * {@inheritdoc}
     *
     * @param mixed $candidate Objeto ou valor a ser validado
     * @return bool True se a especificação interna NÃO for satisfeita
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        // Null nunca satisfaz uma especificação, mesmo negada
        if ($candidate === null) {
            return false;
        }

        // Retorna o oposto da especificação interna
        return !$this->specification->isSatisfiedBy($candidate);
    }

    /**
     * {@inheritdoc}
     */
    /**
     * {@inheritdoc}
     */

    public function getType(): string
    {
        return $this->specification->getType();
    }

    /**
     * {@inheritdoc}
     */
    public function getLeftSide(): ?ISpecification
    {
        return $this->specification;
    }

    /**
     * {@inheritdoc}
     */
    public function getRightSide(): ?ISpecification
    {
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function getSpecifications(): array
    {
        return [$this->specification];
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
        return sprintf('NOT(%s)', $this->getSpecName($this->specification));
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
     * Retorna a especificação interna que está sendo negada.
     *
     * @return ISpecification<T>
     */
    public function getSpecification(): ISpecification
    {
        return $this->specification;
    }

    /**
     * {@inheritdoc}
     */
    public function isDisjointWith(ISpecification $otherSpecification): bool
    {
        if ($otherSpecification === null) {
            throw new \InvalidArgumentException('A especificação não pode ser null');
        }

        // Uma especificação negada é disjunta com a especificação original
        if ($this->specification === $otherSpecification) {
            return true;
        }

        return parent::isDisjointWith($otherSpecification);
    }

    /**
     * {@inheritdoc}
     *
     * Uma negação NOT geralmente não é uma interseção.
     */
    public function isIntersectionOf(ISpecification $otherSpecification): bool
    {
        if ($otherSpecification === null) {
            throw new \InvalidArgumentException('A especificação não pode ser null');
        }

        // Uma NOT specification não é uma interseção
        return false;
    }

    /**
     * {@inheritdoc}
     *
     * Uma negação NOT intersecta com outra especificação se não for
     * disjunta com ela.
     */
    public function intersectsWith(ISpecification $otherSpecification): bool
    {
        if ($otherSpecification === null) {
            throw new \InvalidArgumentException('A especificação não pode ser null');
        }

        // Uma negação intersecta com outra se não forem disjuntas
        // Usa a implementação padrão (oposto de isDisjointWith)
        return parent::intersectsWith($otherSpecification);
    }
}
