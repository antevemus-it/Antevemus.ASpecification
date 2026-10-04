<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Linq;

use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\ISpecificationVisitor;
use Antevemus\ASpecification\Helpers\PropertyAccessor;
use Antevemus\ASpecification\Specifications\AndSpecification;
use Antevemus\ASpecification\Specifications\Collection\CollectionSpecification;
use Antevemus\ASpecification\Specifications\Comparison\EqualSpecification;
use Antevemus\ASpecification\Specifications\Comparison\GreaterThanSpecification;
use Antevemus\ASpecification\Specifications\Comparison\LessThanSpecification;
use Antevemus\ASpecification\Specifications\Comparison\NotEqualSpecification;
use Antevemus\ASpecification\Specifications\Comparison\NotNullSpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysFalseSpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysTrueSpecification;
use Antevemus\ASpecification\Specifications\Logical\JointDenialSpecification;
use Antevemus\ASpecification\Specifications\NotSpecification;
use Antevemus\ASpecification\Specifications\OrSpecification;
use Antevemus\ASpecification\Specifications\PropertySpecification;
use Antevemus\ASpecification\Specifications\String\EqualIgnoreCaseStringSpecification;
use Antevemus\ASpecification\Specifications\String\RegexSpecification;
use Antevemus\ASpecification\Specifications\String\WildcardExpressionMatcherIgnoreCaseStringSpecification;
use Antevemus\ASpecification\Specifications\String\WildcardSpecification;
use Closure;

/**
 * ALinqSpecificationVisitor - Visitor compilador de especificações para predicados executáveis
 *
 * Percorre uma Árvore de Sintaxe Abstrata (AST) de especificações e compila um predicado
 * funcional Closure(mixed): bool de alta performance, otimizado para filtragem de coleções
 * ALinqCollection e consultas em memória.
 *
 * Funcionalidades:
 * - Compilação de especificações compostas (AND, OR, NOT, NOR) em operadores de curto-circuito (&&, ||)
 * - Avaliação de PropertySpecification integrada ao PropertyAccessor (dot notation, arrays, getters)
 * - Avaliação de folhas relacionais (=, !=, <, <=, >, >=, regex, wildcard, case-insensitive)
 * - Execução direta compatível com ALinqCollection::where() e ALinqQueryBuilder
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Linq
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class ALinqSpecificationVisitor implements ISpecificationVisitor
{
    /**
     * Compila uma especificação em um predicado executável.
     *
     * @param ISpecification $specification
     * @return Closure(mixed $candidate): bool
     */
    public function visit(ISpecification $specification): Closure
    {
        if ($specification instanceof ICompositeSpecification) {
            return $this->visitComposite($specification);
        }

        return $this->visitLeaf($specification);
    }

    /**
     * Atalho estático para compilar uma especificação diretamente em um predicado Closure.
     *
     * @param ISpecification $specification
     * @return Closure(mixed $candidate): bool
     */
    public static function createPredicate(ISpecification $specification): Closure
    {
        return (new self())->visit($specification);
    }

    /**
     * Compila e retorna o predicado para a especificação informada.
     *
     * @param ISpecification $specification
     * @return Closure(mixed $candidate): bool
     */
    public function toPredicate(ISpecification $specification): Closure
    {
        return $this->visit($specification);
    }

    /**
     * {@inheritdoc}
     *
     * @param ICompositeSpecification $specification
     * @return Closure(mixed $candidate): bool
     */
    public function visitComposite(ICompositeSpecification $specification): Closure
    {
        return match (true) {
            $specification instanceof PropertySpecification =>
                $this->compilePropertySpecification($specification),

            $specification instanceof AndSpecification =>
                $this->compileAnd($specification),

            $specification instanceof OrSpecification =>
                $this->compileOr($specification),

            $specification instanceof NotSpecification =>
                $this->compileNot($specification),

            $specification instanceof JointDenialSpecification =>
                $this->compileJointDenial($specification),

            $specification instanceof AlwaysTrueSpecification =>
                fn(mixed $candidate): bool => true,

            $specification instanceof AlwaysFalseSpecification =>
                fn(mixed $candidate): bool => false,

            default => $this->compileGenericComposite($specification),
        };
    }

    /**
     * {@inheritdoc}
     *
     * @param ISpecification $specification
     * @return Closure(mixed $candidate): bool
     */
    public function visitLeaf(ISpecification $specification): Closure
    {
        return match (true) {
            $specification instanceof EqualSpecification =>
                fn(mixed $candidate): bool => $candidate == $specification->getValue(),

            $specification instanceof NotEqualSpecification =>
                fn(mixed $candidate): bool => $candidate != $specification->getValue(),

            $specification instanceof GreaterThanSpecification =>
                fn(mixed $candidate): bool => $candidate > $specification->getValue(),

            $specification instanceof LessThanSpecification =>
                fn(mixed $candidate): bool => $candidate < $specification->getValue(),

            $specification instanceof NotNullSpecification =>
                fn(mixed $candidate): bool => $candidate !== null,

            $specification instanceof EqualIgnoreCaseStringSpecification =>
                fn(mixed $candidate): bool => is_string($candidate) && strcasecmp($candidate, $specification->getValue()) === 0,

            $specification instanceof RegexSpecification =>
                fn(mixed $candidate): bool => is_string($candidate) && (bool)preg_match($specification->getPattern(), $candidate),

            $specification instanceof WildcardSpecification =>
                $this->compileWildcard($specification->getPattern(), false),

            $specification instanceof WildcardExpressionMatcherIgnoreCaseStringSpecification =>
                $this->compileWildcard($specification->getPattern(), true),

            $specification instanceof CollectionSpecification =>
                $this->compileCollectionSpecification($specification),

            $specification instanceof AlwaysTrueSpecification =>
                fn(mixed $candidate): bool => true,

            $specification instanceof AlwaysFalseSpecification =>
                fn(mixed $candidate): bool => false,

            default =>
                fn(mixed $candidate): bool => $specification->isSatisfiedBy(is_object($candidate) ? $candidate : null),
        };
    }

    /**
     * Compila inspeção de propriedade com PropertyAccessor.
     */
    private function compilePropertySpecification(PropertySpecification $specification): Closure
    {
        $propertyName = $specification->getPropertyName();
        $innerPredicate = $this->visit($specification->getPropertySpecification());

        return function(mixed $candidate) use ($propertyName, $innerPredicate): bool {
            if ($candidate === null) {
                return false;
            }

            if (!PropertyAccessor::hasProperty($candidate, $propertyName)) {
                return false;
            }

            $value = PropertyAccessor::getValue($candidate, $propertyName);
            return $innerPredicate($value);
        };
    }

    /**
     * Compila conjunção lógica AND.
     */
    private function compileAnd(AndSpecification $specification): Closure
    {
        $left = $this->visit($specification->getLeftSide());
        $right = $this->visit($specification->getRightSide());

        return fn(mixed $candidate): bool => $left($candidate) && $right($candidate);
    }

    /**
     * Compila disjunção lógica OR.
     */
    private function compileOr(OrSpecification $specification): Closure
    {
        $left = $this->visit($specification->getLeftSide());
        $right = $this->visit($specification->getRightSide());

        return fn(mixed $candidate): bool => $left($candidate) || $right($candidate);
    }

    /**
     * Compila negação lógica NOT.
     */
    private function compileNot(NotSpecification $specification): Closure
    {
        $inner = $this->visit($specification->getSpecification());

        return fn(mixed $candidate): bool => !$inner($candidate);
    }

    /**
     * Compila negação conjunta NOR (Joint Denial).
     */
    private function compileJointDenial(JointDenialSpecification $specification): Closure
    {
        $left = $this->visit($specification->getLeftSide());
        $right = $this->visit($specification->getRightSide());

        return fn(mixed $candidate): bool => !$left($candidate) && !$right($candidate);
    }

    /**
     * Compila casamento por wildcard.
     */
    private function compileWildcard(string $pattern, bool $caseInsensitive): Closure
    {
        $regex = '/^' . str_replace(['\*', '\?'], ['.*', '.'], preg_quote($pattern, '/')) . '$/';
        if ($caseInsensitive) {
            $regex .= 'i';
        }

        return fn(mixed $candidate): bool => is_string($candidate) && (bool)preg_match($regex, $candidate);
    }

    /**
     * Compila verificação de todos os itens de uma coleção.
     */
    private function compileCollectionSpecification(CollectionSpecification $specification): Closure
    {
        $elementSpec = $specification->getElementSpecification();
        $elementPredicate = $this->visit($elementSpec);

        return function(mixed $candidate) use ($elementPredicate): bool {
            if (!is_iterable($candidate)) {
                return false;
            }
            foreach ($candidate as $item) {
                if (!$elementPredicate($item)) {
                    return false;
                }
            }
            return true;
        };
    }

    /**
     * Compila composição genérica agregando todos os sub-critérios via AND.
     */
    private function compileGenericComposite(ICompositeSpecification $specification): Closure
    {
        $specs = $specification->getSpecifications();
        if (!empty($specs)) {
            $predicates = array_map(fn($s) => $this->visit($s), $specs);
            return function(mixed $candidate) use ($predicates): bool {
                foreach ($predicates as $predicate) {
                    if (!$predicate($candidate)) {
                        return false;
                    }
                }
                return true;
            };
        }

        return fn(mixed $candidate): bool => $specification->isSatisfiedBy(is_object($candidate) ? $candidate : null);
    }
}
