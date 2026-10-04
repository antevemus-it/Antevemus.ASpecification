<?php

namespace Antevemus\ASpecification;

use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Results\SpecificationFailure;
use Antevemus\ASpecification\Results\SpecificationResult;
use Antevemus\ASpecification\Specifications\AndSpecification;
use Antevemus\ASpecification\Specifications\NotSpecification;
use Antevemus\ASpecification\Specifications\OrSpecification;
use Antevemus\ASpecification\Specifications\PropertySpecification;
use ReflectionClass;

/**
 * AbstractSpecification class.
 *
 * Classe abstrata base que fornece implementação padrão para os métodos
 * de composição da interface ISpecification.
 *
 * Classes concretas de especificação devem estender esta classe e implementar
 * apenas os métodos abstratos:
 * - isSatisfiedBy(): lógica específica de validação
 * - getType(): retorna o tipo do objeto candidato
 *
 * Os métodos de análise (isGeneralizationOf, isSpecialCaseOf, isDisjointWith)
 * possuem implementação padrão que pode ser sobrescrita quando necessário.
 *
 * @template T
 * @implements ISpecification<T>
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Core
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class AbstractSpecification implements ISpecification
{
    protected ?string $customReason = null;
    protected ?string $customCode = null;

    /**
     * {@inheritdoc}
     */
    public function because(string $reason): static
    {
        $clone = clone $this;
        $clone->customReason = $reason;
        return $clone;
    }

    /**
     * {@inheritdoc}
     */
    public function withCode(string $code): static
    {
        $clone = clone $this;
        $clone->customCode = $code;
        return $clone;
    }

    /**
     * {@inheritdoc}
     */
    public function andNot(ISpecification|string $otherSpecification, ?ISpecification $propertySpecification = null): ICompositeSpecification
    {
        if (is_string($otherSpecification)) {
            if ($propertySpecification === null) {
                throw new \InvalidArgumentException('A especificação da propriedade não pode ser nula quando o nome da propriedade é fornecido.');
            }
            return $this->and($otherSpecification, $propertySpecification->not());
        }

        return $this->and($otherSpecification->not());
    }

    /**
     * {@inheritdoc}
     */
    public function orNot(ISpecification|string $otherSpecification, ?ISpecification $propertySpecification = null): ICompositeSpecification
    {
        if (is_string($otherSpecification)) {
            if ($propertySpecification === null) {
                throw new \InvalidArgumentException('A especificação da propriedade não pode ser nula quando o nome da propriedade é fornecido.');
            }
            return $this->or($otherSpecification, $propertySpecification->not());
        }

        return $this->or($otherSpecification->not());
    }

    /**
     * {@inheritdoc}
     */
    public function evaluate(mixed $candidate): SpecificationResult
    {
        try {
            $satisfied = $this->isSatisfiedBy($candidate);
        } catch (\TypeError) {
            $satisfied = false;
        }

        if ($satisfied) {
            return SpecificationResult::satisfied();
        }

        $message = $this->customReason ?? $this->getDefaultFailureMessage($candidate);
        $ruleName = (new ReflectionClass($this))->getShortName();

        return SpecificationResult::failure(
            message: $message,
            code: $this->customCode,
            ruleName: $ruleName
        );
    }

    /**
     * {@inheritdoc}
     */
    public function accept(\Antevemus\ASpecification\Contracts\ISpecificationVisitor $visitor): mixed
    {
        return $visitor->visitLeaf($this);
    }

    /**
     * {@inheritdoc}
     */
    public function toSql(
        \Antevemus\ASpecification\Contracts\Sql\ISqlDialect|\Antevemus\ASpecification\Sql\SqlDialect|string $dialect = 'ansi',
        \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array|\Closure|null $fieldMap = null
    ): \Antevemus\ASpecification\Contracts\Sql\ISqlWhereClause {
        return (new \Antevemus\ASpecification\Sql\SqlQueryVisitor($dialect, $fieldMap))->translate($this);
    }

    /**
     * {@inheritdoc}
     */
    public function toCriteria(
        \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array|\Closure|null $fieldMap = null,
        array $properties = []
    ): mixed {
        return \Antevemus\ASpecification\Criteria\TCriteriaBuilder::fromSpecification($this, $fieldMap, $properties);
    }

    /**
     * Gera uma mensagem padrão de falha caso não tenha sido configurada via because().
     *
     * @param mixed $candidate
     * @return string
     */
    protected function getDefaultFailureMessage(mixed $candidate): string
    {
        $ruleName = (new ReflectionClass($this))->getShortName();
        return sprintf("O candidato não satisfez a regra '%s'.", $ruleName);
    }

    /**
     * {@inheritdoc}
     */
    public function where(string $accessibleObjectName, ISpecification $accessibleObjectSpecification): ICompositeSpecification
    {
        if (empty($accessibleObjectName)) {
            throw new \InvalidArgumentException('O nome do objeto acessível não pode ser vazio');
        }

        return new PropertySpecification($this, $accessibleObjectName, $accessibleObjectSpecification);
    }

    /**
     * {@inheritdoc}
     */
    public function and(ISpecification|string $otherSpecification, ?ISpecification $propertySpecification = null): ICompositeSpecification
    {
        if (is_string($otherSpecification)) {
            if ($propertySpecification === null) {
                throw new \InvalidArgumentException('A especificação da propriedade não pode ser nula quando o nome da propriedade é fornecido.');
            }
            return $this->and(new PropertySpecification($this->resolveRootTypeSpecification(), $otherSpecification, $propertySpecification));
        }

        if ($otherSpecification === null) {
            throw new \InvalidArgumentException('A especificação não pode ser null');
        }

        return new AndSpecification($this, $otherSpecification);
    }

    /**
     * {@inheritdoc}
     */
    public function or(ISpecification|string $otherSpecification, ?ISpecification $propertySpecification = null): ICompositeSpecification
    {
        if (is_string($otherSpecification)) {
            if ($propertySpecification === null) {
                throw new \InvalidArgumentException('A especificação da propriedade não pode ser nula quando o nome da propriedade é fornecido.');
            }
            return $this->or(new PropertySpecification($this->resolveRootTypeSpecification(), $otherSpecification, $propertySpecification));
        }

        if ($otherSpecification === null) {
            throw new \InvalidArgumentException('A especificação não pode ser null');
        }

        return new OrSpecification($this, $otherSpecification);
    }

    /**
     * Resolve a especificação raiz que representa o tipo candidato desta composição.
     *
     * @return ISpecification
     */
    protected function resolveRootTypeSpecification(): ISpecification
    {
        if ($this instanceof PropertySpecification) {
            $left = $this->getLeftSide();
            return ($left instanceof AbstractSpecification && $left !== $this)
                ? $left->resolveRootTypeSpecification()
                : ($left ?? $this);
        }

        if ($this instanceof ICompositeSpecification) {
            $left = $this->getLeftSide();
            if ($left instanceof AbstractSpecification && $left !== $this) {
                return $left->resolveRootTypeSpecification();
            }
            if ($left instanceof ISpecification) {
                return $left;
            }
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function not(): ICompositeSpecification
    {
        return new NotSpecification($this);
    }

    /**
     * {@inheritdoc}
     */
    abstract public function getType(): string;

    /**
     * {@inheritdoc}
     */
    abstract public function isSatisfiedBy(?object $candidate): bool;

    /**
     * {@inheritdoc}
     *
     * Implementação padrão: retorna false.
     * Subclasses devem sobrescrever este método para fornecer lógica específica.
     */
    public function isGeneralizationOf(ISpecification $otherSpecification): bool
    {
        if ($otherSpecification === null) {
            throw new \InvalidArgumentException('A especificação não pode ser null');
        }

        // Implementação padrão conservadora
        return false;
    }

    /**
     * {@inheritdoc}
     *
     * Implementação padrão: delega para isGeneralizationOf da outra especificação.
     */
    public function isSpecialCaseOf(ISpecification $otherSpecification): bool
    {
        if ($otherSpecification === null) {
            throw new \InvalidArgumentException('A especificação não pode ser null');
        }

        // Se a outra especificação é uma generalização desta, então esta é um caso especial daquela
        return $otherSpecification->isGeneralizationOf($this);
    }

    /**
     * {@inheritdoc}
     *
     * Implementação padrão: retorna false.
     * Subclasses devem sobrescrever este método para fornecer lógica específica.
     */
    public function isDisjointWith(ISpecification $otherSpecification): bool
    {
        if ($otherSpecification === null) {
            throw new \InvalidArgumentException('A especificação não pode ser null');
        }

        // Implementação padrão conservadora
        return false;
    }

    /**
     * {@inheritdoc}
     *
     * Implementação padrão: retorna false.
     * Subclasses devem sobrescrever este método para fornecer lógica específica.
     */
    public function isIntersectionOf(ISpecification $otherSpecification): bool
    {
        if ($otherSpecification === null) {
            throw new \InvalidArgumentException('A especificação não pode ser null');
        }

        // Implementação padrão conservadora
        return false;
    }

    /**
     * {@inheritdoc}
     *
     * Implementação padrão: retorna o oposto de isDisjointWith().
     * Se as especificações não são disjuntas, então elas se intersectam.
     */
    public function intersectsWith(ISpecification $otherSpecification): bool
    {
        if ($otherSpecification === null) {
            throw new \InvalidArgumentException('A especificação não pode ser null');
        }

        // Por padrão, intersectsWith é o oposto lógico de isDisjointWith
        return !$this->isDisjointWith($otherSpecification);
    }

    /**
     * Retorna uma representação em string desta especificação.
     *
     * @return string
     */
    public function __toString(): string
    {
        return static::class;
    }
}
