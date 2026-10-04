<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Criteria;

use Adianti\Database\TCriteria;
use Adianti\Database\TExpression;
use Adianti\Database\TFilter;
use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\ISpecificationVisitor;
use Antevemus\ASpecification\Contracts\IValueBoundSpecification;
use Antevemus\ASpecification\Contracts\Sql\IFieldMapper;
use Antevemus\ASpecification\Criteria\Exceptions\CriteriaBuilderException;
use Antevemus\ASpecification\Criteria\Exceptions\NonTranslatableCriteriaException;
use Antevemus\ASpecification\Specifications\AndSpecification;
use Antevemus\ASpecification\Specifications\Comparison\EqualSpecification;
use Antevemus\ASpecification\Specifications\Comparison\GreaterThanSpecification;
use Antevemus\ASpecification\Specifications\Comparison\LessThanSpecification;
use Antevemus\ASpecification\Specifications\Comparison\NotEqualSpecification;
use Antevemus\ASpecification\Specifications\Comparison\NotNullSpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysFalseSpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysTrueSpecification;
use Antevemus\ASpecification\Specifications\NotSpecification;
use Antevemus\ASpecification\Specifications\OrSpecification;
use Antevemus\ASpecification\Specifications\PropertySpecification;
use Antevemus\ASpecification\Specifications\String\EqualIgnoreCaseStringSpecification;
use Antevemus\ASpecification\Specifications\String\RegexSpecification;
use Antevemus\ASpecification\Specifications\String\WildcardExpressionMatcherIgnoreCaseStringSpecification;
use Antevemus\ASpecification\Specifications\String\WildcardSpecification;
use Antevemus\ASpecification\Sql\FieldMapper;

/**
 * CriteriaSpecificationVisitor - Tradutor da AST de Especificações para TCriteria do Adianti
 *
 * Percorre recursivamente árvores de especificações de domínio através do padrão GoF Visitor,
 * compilando uma hierarquia equivalente de objetos TCriteria, TFilter e TExpression.
 *
 * Funcionalidades:
 * - Compilação de folhas em TFilter com operadores relacionais (=, <>, >, <, LIKE, IS, IS NOT)
 * - Mapeamento objeto-relacional de propriedades para colunas via IFieldMapper
 * - Suporte a inversão lógica algébrica de De Morgan para negações (NotSpecification)
 * - Preservação de precedência booleana via sub-instâncias aninhadas de TCriteria
 * - Tratamento de case-insensitivity em filtros de texto
 *
 * @implements ISpecificationVisitor<TExpression>
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Criteria
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class CriteriaSpecificationVisitor implements ISpecificationVisitor
{
    private readonly IFieldMapper $fieldMapper;
    private ?string $currentProperty = null;

    /**
     * @param IFieldMapper|array<string, string>|callable(string): string|null $fieldMapper
     * @throws CriteriaBuilderException Se a classe TCriteria do Adianti não estiver disponível
     */
    public function __construct(IFieldMapper|array|callable|null $fieldMapper = null)
    {
        if (!class_exists(TCriteria::class)) {
            throw new CriteriaBuilderException(
                "A classe \\Adianti\\Database\\TCriteria não está carregada no ambiente de execução."
            );
        }

        $this->fieldMapper = FieldMapper::from($fieldMapper);
    }

    /**
     * Ponto de entrada público para converter uma especificação em um TCriteria completo.
     *
     * @param ISpecification $specification
     * @return TCriteria
     */
    public function buildCriteria(ISpecification $specification): TCriteria
    {
        $expression = $this->visit($specification);

        if ($expression instanceof TCriteria) {
            return $expression;
        }

        $criteria = new TCriteria();
        $criteria->add($expression);
        return $criteria;
    }

    /**
     * Visita qualquer especificação despachando para o método adequado.
     *
     * @param ISpecification $specification
     * @return TExpression
     */
    public function visit(ISpecification $specification): TExpression
    {
        if ($specification instanceof ICompositeSpecification) {
            return $this->visitComposite($specification);
        }

        return $this->visitLeaf($specification);
    }

    /**
     * {@inheritdoc}
     */
    public function visitComposite(ICompositeSpecification $specification): TExpression
    {
        return match (true) {
            $specification instanceof PropertySpecification =>
                $this->visitPropertySpecification($specification),

            $specification instanceof AndSpecification =>
                $this->buildBinaryCriteria($specification->getLeftSide(), $specification->getRightSide(), TExpression::AND_OPERATOR),

            $specification instanceof OrSpecification =>
                $this->buildBinaryCriteria($specification->getLeftSide(), $specification->getRightSide(), TExpression::OR_OPERATOR),

            $specification instanceof NotSpecification =>
                $this->visitNot($specification->getSpecification()),

            default => throw new NonTranslatableCriteriaException($specification, "Especificação composta desconhecida."),
        };
    }

    /**
     * Define o contexto da propriedade e visita a especificação interna.
     *
     * @param PropertySpecification $specification
     * @return TExpression
     */
    private function visitPropertySpecification(PropertySpecification $specification): TExpression
    {
        $previousProperty = $this->currentProperty;
        $this->currentProperty = $this->fieldMapper->map($specification->getPropertyName());

        try {
            return $this->visit($specification->getInnerSpecification());
        } finally {
            $this->currentProperty = $previousProperty;
        }
    }

    /**
     * Constrói uma nova instância de TCriteria combinando ramo esquerdo e direito com operador lógico.
     *
     * @param ISpecification $left
     * @param ISpecification $right
     * @param string $operator
     * @return TCriteria
     */
    private function buildBinaryCriteria(ISpecification $left, ISpecification $right, string $operator): TCriteria
    {
        $criteria = new TCriteria();
        $criteria->add($this->visit($left));
        $criteria->add($this->visit($right), $operator);
        return $criteria;
    }

    /**
     * {@inheritdoc}
     */
    public function visitLeaf(ISpecification $specification): TExpression
    {
        // Tautologia e Contradição universais (não exigem coluna)
        if ($specification instanceof AlwaysTrueSpecification) {
            return new TFilter('1', '=', 1);
        }

        if ($specification instanceof AlwaysFalseSpecification) {
            return new TFilter('1', '=', 0);
        }

        $col = $this->currentProperty;
        if ($col === null) {
            throw new NonTranslatableCriteriaException(
                $specification,
                "A especificação folha deve estar associada a um campo/propriedade através de PropertySpecification."
            );
        }

        return match (true) {
            $specification instanceof EqualSpecification =>
                $this->translateEqual($col, $specification->getValue()),

            $specification instanceof NotEqualSpecification =>
                $this->translateNotEqual($col, $specification->getValue()),

            $specification instanceof GreaterThanSpecification =>
                new TFilter($col, '>', $specification->getValue()),

            $specification instanceof LessThanSpecification =>
                new TFilter($col, '<', $specification->getValue()),

            $specification instanceof NotNullSpecification =>
                new TFilter($col, 'IS NOT', null),

            $specification instanceof WildcardSpecification =>
                $this->translateWildcard($col, $specification->getPattern(), false),

            $specification instanceof WildcardExpressionMatcherIgnoreCaseStringSpecification =>
                $this->translateWildcard($col, $specification->getPattern(), true),

            $specification instanceof EqualIgnoreCaseStringSpecification =>
                $this->translateEqualIgnoreCase($col, $specification->getValue()),

            $specification instanceof RegexSpecification =>
                new TFilter($col, 'REGEXP', $this->cleanRegexPattern($specification->getPattern())),

            $specification instanceof IValueBoundSpecification =>
                new TFilter($col, '=', $specification->getValue()),

            default => throw new NonTranslatableCriteriaException($specification),
        };
    }

    /**
     * Traduz igualdade para TFilter tratando nulo como IS.
     *
     * @param string $col
     * @param mixed $val
     * @return TFilter
     */
    private function translateEqual(string $col, mixed $val): TFilter
    {
        if ($val === null) {
            return new TFilter($col, 'IS', null);
        }
        return new TFilter($col, '=', $val);
    }

    /**
     * Traduz desigualdade para TFilter tratando nulo como IS NOT.
     *
     * @param string $col
     * @param mixed $val
     * @return TFilter
     */
    private function translateNotEqual(string $col, mixed $val): TFilter
    {
        if ($val === null) {
            return new TFilter($col, 'IS NOT', null);
        }
        return new TFilter($col, '<>', $val);
    }

    /**
     * Traduz padrões wildcard substituindo * por % e ? por _.
     *
     * @param string $col
     * @param string $rawPattern
     * @param bool $caseInsensitive
     * @return TFilter
     */
    private function translateWildcard(string $col, string $rawPattern, bool $caseInsensitive): TFilter
    {
        $pattern = str_replace(['*', '?'], ['%', '_'], $rawPattern);
        $filter = new TFilter($col, 'LIKE', $pattern);
        if ($caseInsensitive) {
            $filter->setCaseInsensitive(true);
        }
        return $filter;
    }

    /**
     * Traduz padrões wildcard negados substituindo * por % e ? por _.
     *
     * @param string $col
     * @param string $rawPattern
     * @param bool $caseInsensitive
     * @return TFilter
     */
    private function translateNotWildcard(string $col, string $rawPattern, bool $caseInsensitive): TFilter
    {
        $pattern = str_replace(['*', '?'], ['%', '_'], $rawPattern);
        $filter = new TFilter($col, 'NOT LIKE', $pattern);
        if ($caseInsensitive) {
            $filter->setCaseInsensitive(true);
        }
        return $filter;
    }

    /**
     * Traduz igualdade case-insensitive via LIKE com filtro case-insensitive.
     *
     * @param string $col
     * @param mixed $value
     * @return TFilter
     */
    private function translateEqualIgnoreCase(string $col, mixed $value): TFilter
    {
        $filter = new TFilter($col, 'LIKE', $value);
        $filter->setCaseInsensitive(true);
        return $filter;
    }

    /**
     * Traduz desigualdade case-insensitive via NOT LIKE com filtro case-insensitive.
     *
     * @param string $col
     * @param mixed $value
     * @return TFilter
     */
    private function translateNotEqualIgnoreCase(string $col, mixed $value): TFilter
    {
        $filter = new TFilter($col, 'NOT LIKE', $value);
        $filter->setCaseInsensitive(true);
        return $filter;
    }

    /**
     * Traduz uma negação aplicando regras de De Morgan e inversão relacional.
     *
     * @param ISpecification $inner
     * @return TExpression
     */
    private function visitNot(ISpecification $inner): TExpression
    {
        return match (true) {
            $inner instanceof NotSpecification =>
                $this->visit($inner->getSpecification()),

            $inner instanceof AndSpecification =>
                $this->buildBinaryNotCriteria($inner->getLeftSide(), $inner->getRightSide(), TExpression::OR_OPERATOR),

            $inner instanceof OrSpecification =>
                $this->buildBinaryNotCriteria($inner->getLeftSide(), $inner->getRightSide(), TExpression::AND_OPERATOR),

            $inner instanceof PropertySpecification =>
                $this->visitNotPropertySpecification($inner),

            $inner instanceof AlwaysTrueSpecification =>
                new TFilter('1', '=', 0),

            $inner instanceof AlwaysFalseSpecification =>
                new TFilter('1', '=', 1),

            default => $this->visitNotLeaf($inner),
        };
    }

    /**
     * Define o contexto da propriedade e visita a especificação negada interna.
     *
     * @param PropertySpecification $specification
     * @return TExpression
     */
    private function visitNotPropertySpecification(PropertySpecification $specification): TExpression
    {
        $previousProperty = $this->currentProperty;
        $this->currentProperty = $this->fieldMapper->map($specification->getPropertyName());

        try {
            return $this->visitNot($specification->getInnerSpecification());
        } finally {
            $this->currentProperty = $previousProperty;
        }
    }

    /**
     * Constrói uma nova instância de TCriteria combinando ramo esquerdo e direito negados com operador lógico.
     *
     * @param ISpecification $left
     * @param ISpecification $right
     * @param string $operator
     * @return TCriteria
     */
    private function buildBinaryNotCriteria(ISpecification $left, ISpecification $right, string $operator): TCriteria
    {
        $criteria = new TCriteria();
        $criteria->add($this->visitNot($left));
        $criteria->add($this->visitNot($right), $operator);
        return $criteria;
    }

    /**
     * Inverte logicamente uma especificação folha associada à propriedade atual.
     *
     * @param ISpecification $inner
     * @return TFilter
     */
    private function visitNotLeaf(ISpecification $inner): TFilter
    {
        $col = $this->currentProperty;
        if ($col === null) {
            throw new NonTranslatableCriteriaException(
                $inner,
                "A especificação negada deve estar associada a uma propriedade."
            );
        }

        return match (true) {
            $inner instanceof EqualSpecification =>
                $this->translateNotEqual($col, $inner->getValue()),

            $inner instanceof NotEqualSpecification =>
                $this->translateEqual($col, $inner->getValue()),

            $inner instanceof GreaterThanSpecification =>
                new TFilter($col, '<=', $inner->getValue()),

            $inner instanceof LessThanSpecification =>
                new TFilter($col, '>=', $inner->getValue()),

            $inner instanceof NotNullSpecification =>
                new TFilter($col, 'IS', null),

            $inner instanceof WildcardSpecification =>
                $this->translateNotWildcard($col, $inner->getPattern(), false),

            $inner instanceof WildcardExpressionMatcherIgnoreCaseStringSpecification =>
                $this->translateNotWildcard($col, $inner->getPattern(), true),

            $inner instanceof EqualIgnoreCaseStringSpecification =>
                $this->translateNotEqualIgnoreCase($col, $inner->getValue()),

            $inner instanceof RegexSpecification =>
                new TFilter($col, 'NOT REGEXP', $this->cleanRegexPattern($inner->getPattern())),

            default => throw new NonTranslatableCriteriaException($inner, "Impossível inverter logicamente a regra informada."),
        };
    }

    /**
     * Remove delimitadores de regex PHP (ex: '/pattern/i' => 'pattern') para uso em SQL/TCriteria.
     *
     * @param string $pattern
     * @return string
     */
    private function cleanRegexPattern(string $pattern): string
    {
        if (preg_match('/^[\/~#%](.*)[\/~#%][imsxeADSUXJu]*$/s', $pattern, $matches)) {
            return $matches[1];
        }
        return $pattern;
    }
}
