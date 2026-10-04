<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Sql;

use Closure;
use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\ISpecificationVisitor;
use Antevemus\ASpecification\Contracts\IValueBoundSpecification;
use Antevemus\ASpecification\Contracts\Sql\IFieldMapper;
use Antevemus\ASpecification\Contracts\Sql\ISqlDialect;
use Antevemus\ASpecification\Contracts\Sql\ISqlWhereClause;
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
use Antevemus\ASpecification\Sql\Dialects\SqlDialectFactory;
use Antevemus\ASpecification\Sql\Exceptions\NonTranslatableSpecificationException;

/**
 * SqlQueryVisitor - Tradutor da AST de Especificações para Cláusulas WHERE Parametrizadas
 *
 * Implementa o padrão GoF Visitor para percorrer recursivamente qualquer árvore de especificações,
 * compilando fragmentos de consulta SQL imunes a injeção em conformidade com o dialeto do SGBD.
 *
 * Funcionalidades:
 * - Suporte nativo a múltiplos dialetos (PostgreSQL, MySQL, SQL Server, Oracle, Firebird, SQLite)
 * - Mapeamento flexível de campos objeto-relacional via IFieldMapper
 * - Geração de parâmetros nomeados sequenciais e isolados (:p1, :p2, etc.)
 * - Tratamento idiomático de nulos (IS NULL, IS NOT NULL)
 * - Suporte a operadores de igualdade, comparação, LIKE, ILIKE e Regex
 *
 * @template-implements ISpecificationVisitor<ISqlWhereClause>
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Sql
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class SqlQueryVisitor implements ISpecificationVisitor
{
    private ISqlDialect $dialect;
    private IFieldMapper $fieldMapper;
    private int $parameterCounter = 0;
    private ?string $currentColumn = null;

    /**
     * @param ISqlDialect|SqlDialect|string $dialect Dialeto alvo
     * @param IFieldMapper|array<string, string>|Closure|null $fieldMap Mapeamento de propriedades para colunas
     * @param string $paramPrefix Prefixo dos parâmetros nomeados
     */
    public function __construct(
        ISqlDialect|SqlDialect|string $dialect = 'ansi',
        IFieldMapper|array|Closure|null $fieldMap = null,
        private readonly string $paramPrefix = 'p'
    ) {
        $this->dialect = SqlDialectFactory::create($dialect);
        $this->fieldMapper = $fieldMap instanceof IFieldMapper
            ? $fieldMap
            : new FieldMapper($fieldMap);
    }

    /**
     * Reinicia o estado interno de parâmetros para uma nova tradução.
     *
     * @return self
     */
    public function reset(): self
    {
        $this->parameterCounter = 0;
        $this->currentColumn = null;
        return $this;
    }

    /**
     * Traduz uma especificação em uma cláusula WHERE parametrizada.
     *
     * @param ISpecification $specification
     * @return SqlWhereClause
     */
    public function translate(ISpecification $specification): SqlWhereClause
    {
        $this->reset();
        return $this->visit($specification);
    }

    /**
     * Ponto focal de visitação polimórfica da especificação.
     *
     * @param ISpecification $specification
     * @return SqlWhereClause
     */
    public function visit(ISpecification $specification): SqlWhereClause
    {
        if ($specification instanceof ICompositeSpecification) {
            return $this->visitComposite($specification);
        }

        return $this->visitLeaf($specification);
    }

    /** {@inheritdoc} */
    public function visitComposite(ICompositeSpecification $specification): SqlWhereClause
    {
        return match (true) {
            $specification instanceof PropertySpecification =>
                $this->visitPropertySpecification($specification),

            $specification instanceof AndSpecification =>
                $this->visit($specification->getLeftSide())->and($this->visit($specification->getRightSide())),

            $specification instanceof OrSpecification =>
                $this->visit($specification->getLeftSide())->or($this->visit($specification->getRightSide())),

            $specification instanceof NotSpecification =>
                $this->visitNotSpecification($specification),

            $specification instanceof AlwaysTrueSpecification =>
                new SqlWhereClause($this->dialect->getTrueCondition()),

            $specification instanceof AlwaysFalseSpecification =>
                new SqlWhereClause($this->dialect->getFalseCondition()),

            default => $this->visitGenericComposite($specification),
        };
    }

    /**
     * Define a coluna ativa e visita a especificação interna associada à propriedade.
     *
     * @param PropertySpecification $specification
     * @return SqlWhereClause
     */
    private function visitPropertySpecification(PropertySpecification $specification): SqlWhereClause
    {
        $previousColumn = $this->currentColumn;
        $rawProp = $specification->getPropertyName();
        $mappedCol = $this->fieldMapper->mapField($rawProp);
        $this->currentColumn = $this->dialect->escapeIdentifier($mappedCol);

        try {
            return $this->visit($specification->getPropertySpecification());
        } finally {
            $this->currentColumn = $previousColumn;
        }
    }

    /**
     * Traduz uma especificação de negação lógica (NOT).
     *
     * @param NotSpecification $specification
     * @return SqlWhereClause
     */
    private function visitNotSpecification(NotSpecification $specification): SqlWhereClause
    {
        $inner = $this->visit($specification->getSpecification());
        if ($inner->isEmpty()) {
            return SqlWhereClause::empty();
        }
        return new SqlWhereClause("NOT ({$inner->sql})", $inner->parameters);
    }

    /**
     * Processa especificações compostas genéricas agregando filhos via AND.
     *
     * @param ICompositeSpecification $specification
     * @return SqlWhereClause
     */
    private function visitGenericComposite(ICompositeSpecification $specification): SqlWhereClause
    {
        $specs = $specification->getSpecifications();
        if (!empty($specs)) {
            $clauses = array_map(fn($s) => $this->visit($s), $specs);
            $combined = array_shift($clauses);
            foreach ($clauses as $c) {
                $combined = $combined->and($c);
            }
            return $combined;
        }

        throw new NonTranslatableSpecificationException($specification, 'Especificação composta não reconhecida para SQL.');
    }

    /** {@inheritdoc} */
    public function visitLeaf(ISpecification $specification): SqlWhereClause
    {
        $col = $this->currentColumn;
        if ($col === null) {
            throw new NonTranslatableSpecificationException(
                $specification,
                'Especificações folha de comparação exigem estar aninhadas em uma PropertySpecification para definir a coluna.'
            );
        }

        return match (true) {
            $specification instanceof EqualSpecification =>
                $this->translateEqual($specification, $col),

            $specification instanceof NotEqualSpecification =>
                $this->translateNotEqual($specification, $col),

            $specification instanceof GreaterThanSpecification =>
                $this->translateComparison($col, '>', $specification->getValue()),

            $specification instanceof LessThanSpecification =>
                $this->translateComparison($col, '<', $specification->getValue()),

            $specification instanceof NotNullSpecification =>
                new SqlWhereClause("{$col} IS NOT NULL"),

            $specification instanceof WildcardSpecification =>
                $this->translateWildcard($col, $specification->getPattern(), true),

            $specification instanceof WildcardExpressionMatcherIgnoreCaseStringSpecification =>
                $this->translateWildcard($col, $specification->getPattern(), false),

            $specification instanceof EqualIgnoreCaseStringSpecification =>
                $this->translateEqualIgnoreCase($col, $specification->getValue()),

            $specification instanceof RegexSpecification =>
                $this->translateRegex($col, $specification->getPattern()),

            $specification instanceof IValueBoundSpecification =>
                $this->translateComparison($col, '=', $specification->getValue()),

            default => throw new NonTranslatableSpecificationException($specification),
        };
    }

    /**
     * Traduz especificação de igualdade tratando nulos, booleanos e escalares.
     *
     * @param EqualSpecification $specification
     * @param string $col
     * @return SqlWhereClause
     */
    private function translateEqual(EqualSpecification $specification, string $col): SqlWhereClause
    {
        $val = $specification->getValue();
        if ($val === null) {
            return new SqlWhereClause("{$col} IS NULL");
        }
        if (is_bool($val)) {
            $formattedBool = $this->dialect->formatBoolean($val);
            return new SqlWhereClause("{$col} = {$formattedBool}");
        }
        $param = $this->createParameter($val);
        return new SqlWhereClause("{$col} = {$param['name']}", $param['binding']);
    }

    /**
     * Traduz especificação de desigualdade tratando nulos, booleanos e escalares.
     *
     * @param NotEqualSpecification $specification
     * @param string $col
     * @return SqlWhereClause
     */
    private function translateNotEqual(NotEqualSpecification $specification, string $col): SqlWhereClause
    {
        $val = $specification->getValue();
        if ($val === null) {
            return new SqlWhereClause("{$col} IS NOT NULL");
        }
        if (is_bool($val)) {
            $opposite = $this->dialect->formatBoolean(!$val);
            return new SqlWhereClause("{$col} = {$opposite}");
        }
        $param = $this->createParameter($val);
        return new SqlWhereClause("{$col} <> {$param['name']}", $param['binding']);
    }

    /**
     * Traduz comparações relacionais escalares parametrizadas (=, >, <).
     *
     * @param string $col
     * @param string $operator
     * @param mixed $value
     * @return SqlWhereClause
     */
    private function translateComparison(string $col, string $operator, mixed $value): SqlWhereClause
    {
        $param = $this->createParameter($value);
        return new SqlWhereClause("{$col} {$operator} {$param['name']}", $param['binding']);
    }

    /**
     * Traduz especificações de wildcard substituindo * por % e ? por _.
     *
     * @param string $col
     * @param string $rawPattern
     * @param bool $caseSensitive
     * @return SqlWhereClause
     */
    private function translateWildcard(string $col, string $rawPattern, bool $caseSensitive): SqlWhereClause
    {
        $pattern = str_replace(['*', '?'], ['%', '_'], $rawPattern);
        $param = $this->createParameter($pattern);
        $sql = $this->dialect->formatLike($col, $param['name'], $caseSensitive);
        return new SqlWhereClause($sql, $param['binding']);
    }

    /**
     * Traduz comparação case-insensitive de strings via LIKE.
     *
     * @param string $col
     * @param mixed $value
     * @return SqlWhereClause
     */
    private function translateEqualIgnoreCase(string $col, mixed $value): SqlWhereClause
    {
        $param = $this->createParameter($value);
        $sql = $this->dialect->formatLike($col, $param['name'], false);
        return new SqlWhereClause($sql, $param['binding']);
    }

    /**
     * Traduz expressões regulares com o dialeto SQL ativo.
     *
     * @param string $col
     * @param string $pattern
     * @return SqlWhereClause
     */
    private function translateRegex(string $col, string $pattern): SqlWhereClause
    {
        $param = $this->createParameter($pattern);
        $sql = $this->dialect->formatRegex($col, $param['name'], true);
        return new SqlWhereClause($sql, $param['binding']);
    }

    /**
     * Gera um novo nome de parâmetro isolado e retorna o placeholder com seu binding.
     *
     * @param mixed $value
     * @return array{name: string, binding: array<string, mixed>}
     */
    private function createParameter(mixed $value): array
    {
        $this->parameterCounter++;
        $paramName = ":{$this->paramPrefix}{$this->parameterCounter}";
        return [
            'name' => $paramName,
            'binding' => [$paramName => $value],
        ];
    }
}
