<?php

namespace Antevemus\ASpecification\Specifications;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Helpers\PropertyAccessor;
use Antevemus\ASpecification\Results\SpecificationFailure;
use Antevemus\ASpecification\Results\SpecificationResult;
use Throwable;

/**
 * PropertySpecification class.
 *
 * Implementação de uma especificação composta que aplica uma especificação
 * a uma propriedade específica de um objeto.
 *
 * Esta especificação permite criar validações fluentes baseadas em propriedades
 * aninhadas de objetos, suportando acesso via:
 * - Propriedades públicas
 * - Métodos getter (getPropertyName ou propertyName)
 * - Métodos is/has (isPropertyName, hasPropertyName)
 *
 * Exemplo:
 * <code>
 * $spec = $userSpec->where('address', new CitySpecification('São Paulo'));
 * // Verifica se $user->address (ou $user->getAddress()) satisfaz CitySpecification
 * </code>
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
class PropertySpecification extends AbstractSpecification implements ICompositeSpecification
{
    use SubsumptionAndEqualityTrait;
    /**
     * @param ISpecification<T> $baseSpecification Especificação base do objeto principal
     * @param string $propertyName Nome da propriedade a acessar
     * @param ISpecification<mixed> $propertySpecification Especificação a aplicar na propriedade
     */
    /**
     * Construtor da especificação.
     *
     * @param mixed $value Valor esperado
     */

    public function __construct(
        private readonly ISpecification $baseSpecification,
        private readonly string $propertyName,
        private readonly ISpecification $propertySpecification
    ) {
        if (empty($propertyName)) {
            throw new \InvalidArgumentException('O nome da propriedade não pode ser vazio');
        }
    }

    /**
     * {@inheritdoc}
     *
     * Avalia a propriedade inspecionada anotando o nome do atributo em eventuais falhas.
     *
     * @param mixed $candidate Objeto ou valor a ser validado
     * @return SpecificationResult Resultado diagnóstico enriquecido com o nome da propriedade
     */
    public function evaluate(mixed $candidate): SpecificationResult
    {
        if ($candidate === null || (!is_object($candidate) && !is_array($candidate))) {
            return SpecificationResult::failure(
                message: sprintf("Candidato inválido para inspeção da propriedade '%s'.", $this->propertyName),
                code: $this->customCode,
                ruleName: 'PropertySpecification',
                property: $this->propertyName
            );
        }

        $baseResult = $this->baseSpecification->evaluate($candidate);
        if (!$baseResult->isSatisfied) {
            return $baseResult;
        }

        try {
            $propertyValue = $this->getPropertyValue($candidate);
        } catch (Throwable $e) {
            return SpecificationResult::failure(
                message: $e->getMessage(),
                code: $this->customCode,
                ruleName: 'PropertySpecification',
                property: $this->propertyName
            );
        }

        if ($propertyValue === null) {
            return SpecificationResult::failure(
                message: sprintf("Propriedade '%s' é nula no objeto candidato.", $this->propertyName),
                code: $this->customCode,
                ruleName: 'PropertySpecification',
                property: $this->propertyName
            );
        }

        $propResult = $this->propertySpecification->evaluate($propertyValue);
        if ($propResult->isSatisfied) {
            return SpecificationResult::satisfied();
        }

        $annotatedFailures = array_map(
            fn(SpecificationFailure $f): SpecificationFailure => $f->property !== null ? $f : $f->withProperty($this->propertyName),
            $propResult->failures
        );

        if ($this->customReason !== null || $this->customCode !== null) {
            array_unshift(
                $annotatedFailures,
                new SpecificationFailure(
                    message: $this->customReason ?? sprintf("Violação na propriedade '%s'.", $this->propertyName),
                    code: $this->customCode,
                    ruleName: 'PropertySpecification',
                    property: $this->propertyName
                )
            );
        }

        return new SpecificationResult(false, $annotatedFailures);
    }

    /**
     * {@inheritdoc}
     *
     * @param object|null $candidate Objeto candidato cuja propriedade será inspecionada
     * @return bool True se o candidato e sua propriedade satisfizerem as especificações
     */
    public function isSatisfiedBy(?object $candidate): bool
    {
        // Null nunca satisfaz uma especificação
        if ($candidate === null) {
            return false;
        }

        // Primeiro verifica se o candidato satisfaz a especificação base
        if (!$this->baseSpecification->isSatisfiedBy($candidate)) {
            return false;
        }

        // Obtém o valor da propriedade
        $propertyValue = $this->getPropertyValue($candidate);

        // Se a propriedade não existe ou é null, não satisfaz
        if ($propertyValue === null) {
            return false;
        }

        // Verifica se o valor da propriedade satisfaz a especificação da propriedade
        return $this->propertySpecification->isSatisfiedBy($propertyValue);
    }

    /**
     * Obtém o valor de uma propriedade do objeto ou array candidato.
     *
     * Delega ao PropertyAccessor para resolução polimórfica (propriedade, getters,
     * booleanos, arrays e dot notation aninhada).
     *
     * @param mixed $candidate
     * @return mixed
     */
    private function getPropertyValue(mixed $candidate): mixed
    {
        if (!PropertyAccessor::hasProperty($candidate, $this->propertyName)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Propriedade "%s" não encontrada ou não acessível no objeto de tipo "%s"',
                    $this->propertyName,
                    is_object($candidate) ? get_class($candidate) : gettype($candidate)
                )
            );
        }

        return PropertyAccessor::getValue($candidate, $this->propertyName);
    }

    /**
     * {@inheritdoc}
     */
    /**
     * {@inheritdoc}
     */

    public function getType(): string
    {
        return $this->baseSpecification->getType();
    }

    /**
     * {@inheritdoc}
     */
    public function getLeftSide(): ?ISpecification
    {
        return $this->baseSpecification;
    }

    /**
     * {@inheritdoc}
     */
    public function getRightSide(): ?ISpecification
    {
        return $this->propertySpecification;
    }

    /**
     * {@inheritdoc}
     */
    public function getSpecifications(): array
    {
        return [$this->baseSpecification, $this->propertySpecification];
    }

    /**
     * {@inheritdoc}
     */
    public function __toString(): string
    {
        return sprintf(
            '(%s WHERE %s: %s)',
            $this->getSpecName($this->baseSpecification),
            $this->propertyName,
            $this->getSpecName($this->propertySpecification)
        );
    }

    /**
     * Obtém o nome legível de uma especificação.
     *
     * @param ISpecification<mixed> $spec
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
     * Retorna o nome da propriedade sendo validada.
     *
     * @return string
     */
    public function getPropertyName(): string
    {
        return $this->propertyName;
    }

    /**
     * Retorna a especificação da propriedade.
     *
     * @return ISpecification<mixed>
     */
    public function getPropertySpecification(): ISpecification
    {
        return $this->propertySpecification;
    }

    /**
     * Alias conciso para getPropertySpecification().
     *
     * @return ISpecification<mixed>
     */
    public function getInnerSpecification(): ISpecification
    {
        return $this->propertySpecification;
    }

    /**
     * Retorna a especificação base.
     *
     * @return ISpecification<T>
     */
    public function getBaseSpecification(): ISpecification
    {
        return $this->baseSpecification;
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
    public function isGeneralizationOf(ISpecification $otherSpecification): bool
    {
        if ($this->checkBaseGeneralization($otherSpecification)) {
            return true;
        }

        if ($otherSpecification instanceof self) {
            if ($this->propertyName !== $otherSpecification->getPropertyName()) {
                return false;
            }
            $baseGeneralizes = $this->baseSpecification->isGeneralizationOf($otherSpecification->getBaseSpecification())
                || $this->baseSpecification->getType() === $otherSpecification->getBaseSpecification()->getType();
            return $baseGeneralizes
                && $this->propertySpecification->isGeneralizationOf($otherSpecification->getPropertySpecification());
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function isDisjointWith(ISpecification $otherSpecification): bool
    {
        if ($this->checkBaseDisjointness($otherSpecification)) {
            return true;
        }

        if ($otherSpecification instanceof self && $this->propertyName === $otherSpecification->getPropertyName()) {
            return $this->propertySpecification->isDisjointWith($otherSpecification->getPropertySpecification());
        }

        return false;
    }
}
