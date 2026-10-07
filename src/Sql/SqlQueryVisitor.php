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
use Antevemus\ASpecification\Specifications\Logical\JointDenialSpecification;
use Antevemus\ASpecification\Specifications\PredicateSpecification;
use Antevemus\ASpecification\Specifications\OrSpecification;
use Antevemus\ASpecification\Specifications\PropertySpecification;
use Antevemus\ASpecification\Specifications\String\EqualIgnoreCaseStringSpecification;
use Antevemus\ASpecification\Specifications\String\RegexSpecification;
use Antevemus\ASpecification\Specifications\String\WildcardExpressionMatcherIgnoreCaseStringSpecification;
use Antevemus\ASpecification\Specifications\String\WildcardSpecification;
use Antevemus\ASpecification\Sql\Dialects\SqlDialectFactory;
use Antevemus\ASpecification\Engine\RuleBoundSpecification;
use Antevemus\ASpecification\Sql\Exceptions\NonTranslatableSpecificationException;

/**
 * SqlQueryVisitor - Specification AST Translator to Parameterized WHERE Clauses
 *
 * Implements the GoF Visitor pattern to recursively traverse specification trees,
 * compiling SQL injection-proof query fragments compliant with specific database dialects.
 *
 * Features:
 * - Multi-dialect support (PostgreSQL, MySQL, SQL Server, Oracle, Firebird, SQLite, ANSI)
 * - Flexible object-relational property mapping via IFieldMapper
 * - Sequential isolated named parameter generation (:p1, :p2, etc.)
 * - Idiomatic null handling (IS NULL, IS NOT NULL)
 * - Support for relational comparisons, pattern matching (LIKE, ILIKE), and Regular Expressions
 *
 * @template-implements ISpecificationVisitor<ISqlWhereClause>
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Sql
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class SqlQueryVisitor implements ISpecificationVisitor
{
    private ISqlDialect $dialect;
    private IFieldMapper $fieldMapper;
    private int $parameterCounter = 0;
    private ?string $currentColumn = null;

    /**
     * @param ISqlDialect|SqlDialect|string $dialect Target dialect
     * @param IFieldMapper|array<string, string>|Closure|null $fieldMap Property to column field mapper
     * @param string $paramPrefix Named parameter prefix
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
     * Reset internal parameter counter state for a fresh translation cycle.
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
     * Translate a specification into a parameterized WHERE clause.
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
     * Polymorphic visitation dispatch point for any specification.
     *
     * @param ISpecification $specification
     * @return SqlWhereClause
     */
    public function visit(ISpecification $specification): SqlWhereClause
    {
        if ($specification instanceof RuleBoundSpecification) {
            return $this->visit($specification->getInnerSpecification());
        }

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

            $specification instanceof JointDenialSpecification =>
                $this->visitJointDenialSpecification($specification),

            $specification instanceof AlwaysTrueSpecification =>
                new SqlWhereClause($this->dialect->getTrueCondition()),

            $specification instanceof AlwaysFalseSpecification =>
                new SqlWhereClause($this->dialect->getFalseCondition()),

            default => $this->visitGenericComposite($specification),
        };
    }

    /**
     * Set target column context and visit the wrapped inner property specification.
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
     * Translate a logical negation specification (NOT).
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
     * Translate a joint denial (NOR) as NOT (left OR right).
     *
     * @param JointDenialSpecification $specification
     * @return SqlWhereClause
     */
    private function visitJointDenialSpecification(JointDenialSpecification $specification): SqlWhereClause
    {
        $inner = $this->visit($specification->getLeftSide())->or($this->visit($specification->getRightSide()));
        if ($inner->isEmpty()) {
            return SqlWhereClause::empty();
        }
        return new SqlWhereClause("NOT ({$inner->sql})", $inner->parameters);
    }

    /**
     * Process generic composite specifications by aggregating children via AND.
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

        throw new NonTranslatableSpecificationException($specification, 'Unrecognized composite specification for SQL.');
    }

    /** {@inheritdoc} */
    public function visitLeaf(ISpecification $specification): SqlWhereClause
    {
        if ($specification instanceof RuleBoundSpecification) {
            return $this->visit($specification->getInnerSpecification());
        }

        if ($specification instanceof PredicateSpecification) {
            throw new NonTranslatableSpecificationException(
                $specification,
                'An inline PHP closure (must()) is opaque and cannot be translated to SQL; express the rule with property specifications.'
            );
        }

        $col = $this->currentColumn;
        if ($col === null) {
            throw new NonTranslatableSpecificationException(
                $specification,
                'Comparison leaf specifications must be nested in a PropertySpecification to define the target column.'
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
     * Translate equality specification handling nulls, booleans, and scalars.
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
     * Translate inequality specification handling nulls, booleans, and scalars.
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
     * Translate parameterized scalar relational comparisons (=, >, <).
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
     * Translate wildcard specifications by replacing * with % and ? with _.
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
     * Translate case-insensitive string equality via LIKE.
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
     * Translate regular expressions using active SQL dialect.
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
     * Generate an isolated parameter name and return placeholder and binding array.
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
