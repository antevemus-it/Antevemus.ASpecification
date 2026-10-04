<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Comparison;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\IValueBoundSpecification;
use Antevemus\ASpecification\Specifications\SubsumptionAndEqualityTrait;

/**
 * EqualSpecification - Especificação folha para igualdade estrita (`===`)
 *
 * Valida se o candidato possui valor e tipo estritamente idênticos ao valor
 * esperado, incluindo suporte a subsunção e disjunção algébrica (RF-10).
 *
 * Funcionalidades:
 * - Validação estrita de igualdade (`===`)
 * - Detecção de disjunção contra outros valores constantes e desigualdades (`>`, `<`, `!==`)
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @implements IValueBoundSpecification<T>
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Comparison
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class EqualSpecification extends AbstractSpecification implements IValueBoundSpecification
{
    use SubsumptionAndEqualityTrait;

    /**
     * @param mixed $value Valor esperado
     */
    public function __construct(
        private readonly mixed $value
    ) {
    }

    /**
     * Retorna o valor vinculado a esta especificação.
     */
    public function getValue(): mixed
    {
        return $this->value;
    }

    /**
     * {@inheritdoc}
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        if ($candidate === null) {
            return $this->value === null;
        }
        return $candidate === $this->value;
    }

    /**
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return "mixed";
    }

    /**
     * {@inheritdoc}
     */
    public function isGeneralizationOf(ISpecification $otherSpecification): bool
    {
        return $this->checkBaseGeneralization($otherSpecification);
    }

    /**
     * {@inheritdoc}
     */
    public function isDisjointWith(ISpecification $otherSpecification): bool
    {
        if ($this->checkBaseDisjointness($otherSpecification)) {
            return true;
        }
        if ($otherSpecification instanceof self) {
            return $this->value !== $otherSpecification->getValue();
        }
        if ($otherSpecification instanceof NotEqualSpecification) {
            return $this->value === $otherSpecification->getValue();
        }
        if ($otherSpecification instanceof GreaterThanSpecification && is_numeric($this->value)) {
            return $this->value <= $otherSpecification->getValue();
        }
        if ($otherSpecification instanceof LessThanSpecification && is_numeric($this->value)) {
            return $this->value >= $otherSpecification->getValue();
        }
        return false;
    }
}
