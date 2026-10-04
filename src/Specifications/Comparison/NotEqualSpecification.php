<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Comparison;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\IValueBoundSpecification;
use Antevemus\ASpecification\Specifications\SubsumptionAndEqualityTrait;

/**
 * NotEqualSpecification - Especificação folha para desigualdade estrita (`!==`)
 *
 * Valida se o candidato é estritamente diferente do valor parametrizado.
 *
 * Funcionalidades:
 * - Validação estrita de desigualdade (`!==`)
 * - Generalização de especificações de igualdade com valores distintos (RF-10)
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
class NotEqualSpecification extends AbstractSpecification implements IValueBoundSpecification
{
    use SubsumptionAndEqualityTrait;

    /**
     * @param mixed $value Valor proibido
     */
    public function __construct(
        private readonly mixed $value
    ) {
    }

    /**
     * Retorna o valor vinculado.
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
            return false;
        }
        return $candidate !== $this->value;
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
        if ($this->checkBaseGeneralization($otherSpecification)) {
            return true;
        }
        if ($otherSpecification instanceof EqualSpecification) {
            return $this->value !== $otherSpecification->getValue();
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
        if ($otherSpecification instanceof EqualSpecification) {
            return $this->value === $otherSpecification->getValue();
        }
        return false;
    }
}
