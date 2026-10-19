<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Sql;

use Antevemus\ASpecification\Sql\Dialects\SqliteDialect;
use Closure;
use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\ISpecificationVisitor;
use Antevemus\ASpecification\Contracts\IValueBoundSpecification;
use Antevemus\ASpecification\Contracts\Sql\IFieldMapper;
use Antevemus\ASpecification\Contracts\Sql\ISqlAccentInsensitiveLike;
use Antevemus\ASpecification\Contracts\Sql\ISqlRegexRewriter;
use Antevemus\ASpecification\Contracts\Sql\ISqlDialect;
use Antevemus\ASpecification\Contracts\Sql\ISqlWhereClause;
use Antevemus\ASpecification\Specifications\AndSpecification;
use Antevemus\ASpecification\Specifications\Comparison\EqualSpecification;
use Antevemus\ASpecification\Specifications\Comparison\GreaterThanOrEqualSpecification;
use Antevemus\ASpecification\Specifications\Comparison\GreaterThanSpecification;
use Antevemus\ASpecification\Specifications\Comparison\InSpecification;
use Antevemus\ASpecification\Specifications\Comparison\IsNullSpecification;
use Antevemus\ASpecification\Specifications\Comparison\LessThanOrEqualSpecification;
use Antevemus\ASpecification\Specifications\Comparison\LessThanSpecification;
use Antevemus\ASpecification\Specifications\Comparison\NotEqualSpecification;
use Antevemus\ASpecification\Specifications\Comparison\NotNullSpecification;
use Antevemus\ASpecification\Specifications\LabelledSpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysFalseSpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysTrueSpecification;
use Antevemus\ASpecification\Specifications\NotSpecification;
use Antevemus\ASpecification\Specifications\Logical\JointDenialSpecification;
use Antevemus\ASpecification\Specifications\PredicateSpecification;
use Antevemus\ASpecification\Specifications\Reflection\MethodParameterizedSpecification;
use Antevemus\ASpecification\Specifications\OrSpecification;
use Antevemus\ASpecification\Specifications\PropertySpecification;
use Antevemus\ASpecification\Specifications\String\EqualIgnoreCaseAndAccentsStringSpecification;
use Antevemus\ASpecification\Specifications\String\EqualIgnoreCaseStringSpecification;
use Antevemus\ASpecification\Specifications\String\LiteralPatternSpecification;
use Antevemus\ASpecification\Specifications\String\RegexSpecification;
use Antevemus\ASpecification\Specifications\String\WildcardExpressionMatcherIgnoreCaseStringSpecification;
use Antevemus\ASpecification\Specifications\String\WildcardSpecification;
use Antevemus\ASpecification\Specifications\Subquery\AbstractSubquerySpecification;
use Antevemus\ASpecification\Specifications\Subquery\ExistsSubquerySpecification;
use Antevemus\ASpecification\Specifications\Subquery\InSubquerySpecification;
use Antevemus\ASpecification\Sql\Dialects\SqlDialectFactory;
use Antevemus\ASpecification\Engine\RuleBoundSpecification;
use Antevemus\ASpecification\Resolution\ExternalDataSpecification;
use Antevemus\ASpecification\Sql\Exceptions\NonTranslatableSpecificationException;
use Antevemus\ASpecification\Sql\Exceptions\UnsupportedSqlOperationException;

/**
 * SqlQueryVisitor - Specification AST Translator to Parameterized WHERE Clauses
 *
 * Implements the GoF Visitor pattern to recursively traverse specification trees,
 * compiling SQL injection-proof query fragments compliant with specific database dialects.
 *
 * Features:
 * - Multi-dialect support (PostgreSQL, MySQL, SQL Server, Oracle, Firebird, SQLite, ANSI, and since 1.6.0
 *   IBM Db2, IBM Informix and DuckDB); every dialect-specific form (quoting, boolean literal, LIKE/ILIKE,
 *   REGEX) is delegated to the ISqlDialect, so the new dialects needed no change in the translation
 * - Flexible object-relational property mapping via IFieldMapper
 * - Sequential isolated named parameter generation (:p1, :p2, etc.)
 * - Idiomatic null handling (IS NULL, IS NOT NULL)
 * - Support for relational comparisons, pattern matching (LIKE, ILIKE), and Regular Expressions
 * - startsWith/endsWith/contains emitted as portable LIKE with wildcard escaping; regex bodies sent
 *   without PHP delimiters (BUG-20261007-3E3F)
 * - Set membership (InSpecification, 1.5.0) emitted as `"col" IN (:p1, :p2)` (guarded since 1.11.0); the empty set as the
 *   dialect's false condition (`1 = 0`), a null member as an extra `OR "col" IS NULL`
 * - A declarative method call (MethodParameterizedSpecification, 1.6.0) is opaque to SQL: it raises
 *   NonTranslatableSpecificationException, as the inline closure of must() does
 * - External data (ExternalDataSpecification, 1.10.0) only exists when the tree is evaluated in PHP by
 *   a DataResolver: it raises NonTranslatableSpecificationException
 * - Two-valued null semantics, the same as the in-memory evaluation (1.11.0): inside a property clause
 *   every comparison on a column that may hold NULL is emitted as `(col IS NOT NULL AND <comparison>)`,
 *   false for a NULL column instead of SQL's unknown, so a NOT or a NOR over it answers what
 *   `isSatisfiedBy()` answers (before, `NOT ("age" > :p1)` excluded a row whose age was NULL). The null
 *   checks (`IS NULL`, `IS NOT NULL`, equality to null, a set holding null) already are two-valued and
 *   are not guarded. An AND/OR/NOT/NOR node directly inside a property rejects a null value in memory,
 *   so it is emitted as `(col IS NOT NULL AND (...))` and its own comparisons need no further guard
 * - An extension point for leaves known only to a subclass, translateExtensionLeaf() (1.13.0): the
 *   PdoRepository compiler uses it for the subquery leaf of Spec::inQuery(); here such a leaf is refused
 *   with NonTranslatableSpecificationException
 * - A second extension point, formatBooleanComparison() (1.14.0): a subclass bound to a mapping may
 *   answer a boolean comparison with a whole condition instead of `col = <literal>` (the PdoRepository
 *   compiler emits `col IN ('Y', 'y')` for a boolean stored as text); here it answers null and the
 *   dialect's literal is used, as before
 * - Text comparison ignoring case and accents (2.1.0): `contains`/`startsWith`/`endsWith` built with
 *   TextComparison::IgnoreCaseAndAccents and `equalIgnoreCaseAndAccents()` go through the dialect's
 *   ISqlAccentInsensitiveLike over the same escaped LIKE pattern as the other text operators; a dialect
 *   without it, or that answers null (SQLite, SQL Server, Oracle and the others not measured), raises
 *   NonTranslatableSpecificationException instead of answering differently from memory
 * - Wildcard and regular expression ignoring case and accents (2.3.0): `wildcard()` built with
 *   IgnoreCaseAndAccents goes through the same ISqlAccentInsensitiveLike LIKE, its glob as the pattern;
 *   `regex()` built with it through ISqlAccentInsensitiveLike::accentInsensitiveRegex() with the folded
 *   body (PostgreSQL); a dialect that answers null (MySQL/MariaDB, SQLite and the others) raises
 *   NonTranslatableSpecificationException. `wildcard()` built with `false`/IgnoreCase is the
 *   case-insensitive LIKE of wildcardIgnoreCase(), and `regex()` built with IgnoreCase carries the `i`
 *   modifier, which the dialect's case-insensitive regular expression already translates
 * - The body of a regular expression goes through the dialect's ISqlRegexRewriter when it has one (2.3.0,
 *   bug #73): on PostgreSQL `\b`/`\B` are bound as `\y`/`\Y`; before, `regex('/\bfoo/')` bound `\bfoo`,
 *   a backspace there, and never matched. Dialects without the interface receive the body as written
 * - Subqueries over another table (2.5.0, forward 048, D20): Spec::inSubquery() becomes
 *   `"col" IN (SELECT "sq"."c" FROM "t" "sq" WHERE "sq"."c" IS NOT NULL AND (...))` (guarded against a NULL
 *   column like any comparison) and Spec::existsIn() `EXISTS (SELECT 1 FROM "t" "sq" WHERE "sq"."fk" =
 *   "outer"."id" AND (...))`; the filter of the other table is translated by a visitor of the same dialect
 *   over the columns of `sq`, its parameters named apart (`:ps1_1`, then `:ps2_1`...). Plain identifiers are
 *   quoted by the dialect; a segment written between double quotes is re-quoted with the dialect's quote
 *   characters
 * - A labelled target (LabelledSpecification, 2.5.0) is translated as the specification it labels
 *
 * @template-implements ISpecificationVisitor<ISqlWhereClause>
 * @version    2.5.0
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

    /** Subqueries translated so far (2.5.0): the prefix of their parameters. */
    private int $subqueryCounter = 0;

    /**
     * Whether the current column may still be NULL where the clause being built is evaluated: true
     * inside a property clause, false once a null guard on that column encloses the clause (and for a
     * column that never holds NULL, see canBeNull()).
     */
    private bool $currentColumnNullable = false;

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
        $this->subqueryCounter = 0;
        $this->currentColumn = null;
        $this->currentColumnNullable = false;
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
        if ($specification instanceof RuleBoundSpecification || $specification instanceof LabelledSpecification) {
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
        if ($this->currentColumn !== null && $this->currentColumnNullable && self::isLogicalNode($specification)) {
            // In memory an AND/OR/NOT/NOR node rejects a null candidate, so the node is false for a NULL
            // column; under the guard the column is known not to be NULL and its leaves need no guard.
            $this->currentColumnNullable = false;
            try {
                $clause = $this->visitComposite($specification);
            } finally {
                $this->currentColumnNullable = true;
            }

            return $this->guardNull($clause, $this->currentColumn);
        }

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
        $previousNullable = $this->currentColumnNullable;
        $rawProp = $specification->getPropertyName();
        $mappedCol = $this->fieldMapper->mapField($rawProp);
        $this->currentColumn = $this->dialect->escapeIdentifier($mappedCol);
        $this->currentColumnNullable = $this->canBeNull($rawProp);

        try {
            return $this->visit($specification->getPropertySpecification());
        } finally {
            $this->currentColumn = $previousColumn;
            $this->currentColumnNullable = $previousNullable;
        }
    }

    /**
     * Whether the column a property is mapped to may hold NULL (1.11.0). A comparison on a column that
     * never does needs no null guard; the visitor cannot know the schema, so every column may. A
     * subclass bound to a mapping (the PdoRepository compiler) answers false for the identifier column.
     *
     * @param string $property Property name as written in the specification (before the field mapping)
     * @return bool
     */
    protected function canBeNull(string $property): bool
    {
        return true;
    }

    /**
     * The SQL literal of a boolean compared with the current column (equality, inequality, set
     * membership): the dialect's literal. A subclass bound to a mapping answers per column (the
     * PdoRepository compiler writes 1/0 for a boolean stored as an integer; since 1.13.0).
     *
     * @param bool $value
     * @return string
     */
    protected function formatBooleanLiteral(bool $value): string
    {
        return $this->dialect->formatBoolean($value);
    }

    /**
     * The condition "the current column holds $value" for a boolean compared with it (equality,
     * inequality, set membership), or null to use `col = <formatBooleanLiteral()>` (the default). A
     * subclass bound to a mapping answers for the columns whose stored form is not a literal (the
     * PdoRepository compiler: `col IN ('Y', 'y')` for BooleanStorage::Char; since 1.14.0). The condition
     * is guarded against NULL like any comparison.
     *
     * @param string $column Escaped column
     * @param bool $value
     * @return string|null
     */
    protected function formatBooleanComparison(string $column, bool $value): ?string
    {
        return null;
    }

    /**
     * Translation of a leaf this visitor does not know, inside a property clause (since 1.13.0): null
     * (the default) lets the visitor translate it or refuse it as usual. A subclass bound to more
     * context answers for its own leaves (the PdoRepository compiler turns Spec::inQuery() into
     * `col IN (SELECT ...)`). The returned clause is guarded against NULL like any comparison.
     *
     * @param ISpecification $specification Leaf inside a property clause
     * @param string $column Escaped column of the enclosing property
     * @return SqlWhereClause|null
     */
    protected function translateExtensionLeaf(ISpecification $specification, string $column): ?SqlWhereClause
    {
        return null;
    }

    /**
     * Whether a node is one of the logical composites that reject a null candidate in memory.
     */
    private static function isLogicalNode(ISpecification $specification): bool
    {
        return $specification instanceof AndSpecification
            || $specification instanceof OrSpecification
            || $specification instanceof NotSpecification
            || $specification instanceof JointDenialSpecification;
    }

    /**
     * Make a clause on a column two-valued: `(col IS NOT NULL AND <clause>)`, false for a NULL column
     * where SQL would answer unknown (1.11.0).
     *
     * @param SqlWhereClause $clause
     * @param string $col Escaped column
     * @return SqlWhereClause
     */
    private function guardNull(SqlWhereClause $clause, string $col): SqlWhereClause
    {
        if ($clause->isEmpty()) {
            return $clause;
        }

        return new SqlWhereClause("({$col} IS NOT NULL AND {$clause->sql})", $clause->parameters);
    }

    /**
     * Whether the clause of a leaf already answers for a NULL column what the leaf answers for a null
     * value, without a guard: the null checks, equality and inequality to null, a set holding null and
     * the empty set (1.11.0).
     */
    private static function isNullAwareLeaf(ISpecification $specification): bool
    {
        return match (true) {
            $specification instanceof IsNullSpecification,
            $specification instanceof NotNullSpecification => true,
            $specification instanceof EqualSpecification,
            $specification instanceof NotEqualSpecification => $specification->getValue() === null,
            $specification instanceof InSpecification =>
                $specification->getValues() === [] || in_array(null, $specification->getValues(), true),
            default => false,
        };
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
        // The constants are leaves, so they always arrive here (the AlwaysTrue/AlwaysFalse arms of
        // visitComposite() are never reached): wherever they appear in a tree (at its root, as an operand
        // of and/or/not/nor, inside a property clause) they are the dialect's constant condition (fixed in
        // 1.11.0; before, any of them threw NonTranslatableSpecificationException, as
        // Spec::toSql(Spec::alwaysTrue()) did).
        if ($specification instanceof AlwaysTrueSpecification) {
            return new SqlWhereClause($this->dialect->getTrueCondition());
        }
        if ($specification instanceof AlwaysFalseSpecification) {
            return new SqlWhereClause($this->dialect->getFalseCondition());
        }

        if ($specification instanceof RuleBoundSpecification || $specification instanceof LabelledSpecification) {
            return $this->visit($specification->getInnerSpecification());
        }

        if ($specification instanceof ExistsSubquerySpecification) {
            // A condition of the row, not of a column: never guarded (2.5.0)
            return $this->translateExists($specification);
        }

        if ($specification instanceof PredicateSpecification) {
            throw new NonTranslatableSpecificationException(
                $specification,
                'An inline PHP closure (must()) is opaque and cannot be translated to SQL; express the rule with property specifications.'
            );
        }

        if ($specification instanceof ExternalDataSpecification) {
            throw new NonTranslatableSpecificationException(
                $specification,
                sprintf(
                    "external data (source '%s') is fetched by a DataResolver when the tree is evaluated in PHP and cannot be translated to SQL; filter in SQL with the other rules and evaluate the external part in PHP",
                    $specification->getSource()
                )
            );
        }

        if ($specification instanceof MethodParameterizedSpecification) {
            throw new NonTranslatableSpecificationException(
                $specification,
                sprintf(
                    'A method call on the candidate (%s) is evaluated in PHP and cannot be translated to SQL; express the rule with property specifications.',
                    $specification->getRuleName()
                )
            );
        }

        $col = $this->currentColumn;
        if ($col === null) {
            throw new NonTranslatableSpecificationException(
                $specification,
                'Comparison leaf specifications must be nested in a PropertySpecification to define the target column.'
            );
        }

        $clause = $this->translateExtensionLeaf($specification, $col) ?? match (true) {
            $specification instanceof InSubquerySpecification =>
                $this->translateInSubquery($col, $specification),

            $specification instanceof EqualSpecification =>
                $this->translateEqual($specification, $col),

            $specification instanceof InSpecification =>
                $this->translateIn($specification, $col),

            $specification instanceof NotEqualSpecification =>
                $this->translateNotEqual($specification, $col),

            $specification instanceof GreaterThanSpecification =>
                $this->translateComparison($col, '>', $specification->getValue()),

            $specification instanceof LessThanSpecification =>
                $this->translateComparison($col, '<', $specification->getValue()),

            $specification instanceof GreaterThanOrEqualSpecification =>
                $this->translateComparison($col, '>=', $specification->getValue()),

            $specification instanceof LessThanOrEqualSpecification =>
                $this->translateComparison($col, '<=', $specification->getValue()),

            $specification instanceof NotNullSpecification =>
                new SqlWhereClause("{$col} IS NOT NULL"),

            $specification instanceof IsNullSpecification =>
                new SqlWhereClause("{$col} IS NULL"),

            $specification instanceof WildcardSpecification && $specification->ignoresAccents() =>
                $this->accentInsensitiveLikeClause($col, LikePattern::fromGlob($specification->getPattern()), $specification),

            $specification instanceof WildcardSpecification =>
                $this->translateWildcard($col, $specification->getPattern(), $specification->isCaseSensitive()),

            $specification instanceof WildcardExpressionMatcherIgnoreCaseStringSpecification =>
                $this->translateWildcard($col, $specification->getPattern(), false),

            $specification instanceof EqualIgnoreCaseStringSpecification =>
                $this->translateEqualIgnoreCase($col, $specification->getValue()),

            $specification instanceof EqualIgnoreCaseAndAccentsStringSpecification =>
                $this->accentInsensitiveLikeClause($col, LikePattern::exact($specification->getValue()), $specification),

            $specification instanceof LiteralPatternSpecification && $specification->ignoresAccents() =>
                $this->accentInsensitiveLikeClause($col, LikePattern::fromLiteral($specification), $specification),

            $specification instanceof LiteralPatternSpecification =>
                $this->translateLiteralPattern($col, $specification),

            $specification instanceof RegexSpecification && $specification->ignoresAccents() =>
                $this->accentInsensitiveRegexClause($col, $specification),

            $specification instanceof RegexSpecification =>
                $this->translateRegex($col, $specification),

            $specification instanceof IValueBoundSpecification =>
                $this->translateComparison($col, '=', $specification->getValue()),

            default => throw new NonTranslatableSpecificationException($specification),
        };

        // A comparison is unknown in SQL for a NULL column, false in memory for a null value (1.11.0)
        return $this->currentColumnNullable && !self::isNullAwareLeaf($specification)
            ? $this->guardNull($clause, $col)
            : $clause;
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
            $condition = $this->formatBooleanComparison($col, $val);
            if ($condition !== null) {
                return new SqlWhereClause($condition);
            }
            $formattedBool = $this->formatBooleanLiteral($val);
            return new SqlWhereClause("{$col} = {$formattedBool}");
        }
        $param = $this->createParameter($val);
        return new SqlWhereClause("{$col} = {$param['name']}", $param['binding']);
    }

    /**
     * Translate set membership as `col IN (:p1, :p2, ...)`.
     *
     * The empty set is the dialect's false condition (`1 = 0`); booleans go out as the dialect's
     * boolean literals inside the list, like translateEqual(); a null member cannot be matched by
     * `IN` in SQL, so it becomes `(col IN (...) OR col IS NULL)` (or just `col IS NULL` when null
     * is the only member).
     *
     * @param InSpecification $specification
     * @param string $col
     * @return SqlWhereClause
     */
    private function translateIn(InSpecification $specification, string $col): SqlWhereClause
    {
        $values = $specification->getValues();
        if ($values === []) {
            return new SqlWhereClause($this->dialect->getFalseCondition());
        }

        $hasNull = false;
        $items = [];
        $bindings = [];
        $conditions = [];
        foreach ($values as $value) {
            if ($value === null) {
                $hasNull = true;
                continue;
            }
            if (is_bool($value)) {
                $condition = $this->formatBooleanComparison($col, $value);
                if ($condition !== null) {
                    $conditions[$condition] = true;
                    continue;
                }
                $items[] = $this->formatBooleanLiteral($value);
                continue;
            }
            $param = $this->createParameter($value);
            $items[] = $param['name'];
            $bindings += $param['binding'];
        }

        $isNull = new SqlWhereClause("{$col} IS NULL");
        if ($items === [] && $conditions === []) {
            return $isNull;
        }

        $in = $items === [] ? SqlWhereClause::empty() : new SqlWhereClause("{$col} IN (" . implode(', ', $items) . ")", $bindings);
        foreach (array_keys($conditions) as $condition) {
            $in = $in->or(new SqlWhereClause($condition));
        }

        return $hasNull ? $in->or($isNull) : $in;
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
            $condition = $this->formatBooleanComparison($col, !$val);
            if ($condition !== null) {
                return new SqlWhereClause($condition);
            }
            $opposite = $this->formatBooleanLiteral(!$val);
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
        // `*` and `?` are the consumer's wildcards; `%`, `_` and `!` in the glob are literal and
        // escaped, so the LIKE matches exactly what fnmatch matches in memory (BUG-20261007-ZY6E).
        return $this->likeClause($col, LikePattern::fromGlob($rawPattern), $caseSensitive);
    }

    /**
     * Translate case-insensitive string equality via LIKE over the escaped text (1.14.0: `%` and `_`
     * in the value are literal; before, `a_b` also matched `axb`).
     *
     * @param string $col
     * @param mixed $value
     * @return SqlWhereClause
     */
    private function translateEqualIgnoreCase(string $col, mixed $value): SqlWhereClause
    {
        return $this->likeClause($col, LikePattern::exact((string) $value), false);
    }

    /**
     * Translate startsWith/endsWith/contains as a portable LIKE over the literal.
     *
     * `%`, `_` and the escape character are escaped and an explicit `ESCAPE '!'` clause is added
     * only when the literal contains one of them (or a backslash, which MySQL treats as the default
     * escape). The dialect decides the case-insensitive form (ILIKE, LOWER(), collation).
     *
     * @param string $col
     * @param LiteralPatternSpecification $specification
     * @return SqlWhereClause
     */
    private function translateLiteralPattern(string $col, LiteralPatternSpecification $specification): SqlWhereClause
    {
        return $this->likeClause($col, LikePattern::fromLiteral($specification), $specification->isCaseSensitive());
    }

    /**
     * Bind a LIKE pattern through the dialect, appending the ESCAPE clause when the pattern needs it.
     *
     * @param string $col
     * @param LikePattern $pattern
     * @param bool $caseSensitive
     * @return SqlWhereClause
     */
    private function likeClause(string $col, LikePattern $pattern, bool $caseSensitive): SqlWhereClause
    {
        if ($caseSensitive && $this->dialect instanceof SqliteDialect) {
            // 1.14.0: SQLite's LIKE ignores the case of ASCII letters; GLOB compares exactly.
            $param = $this->createParameter($pattern->toGlob());

            return new SqlWhereClause("{$col} GLOB {$param['name']}", $param['binding']);
        }

        $param = $this->createParameter($pattern->pattern);
        $sql = $this->dialect->formatLike($col, $param['name'], $caseSensitive) . $pattern->escapeClause();

        return new SqlWhereClause($sql, $param['binding']);
    }

    /**
     * Bind a LIKE pattern compared ignoring case and accents (2.1.0): the dialect writes the condition
     * through ISqlAccentInsensitiveLike, the visitor appends the ESCAPE clause when the pattern needs
     * it. A dialect that cannot answer as the in-memory comparison refuses the specification.
     *
     * @param string $col
     * @param LikePattern $pattern Pattern of the literal as written
     * @param ISpecification $specification Leaf being translated, for the exception
     * @return SqlWhereClause
     * @throws NonTranslatableSpecificationException When the dialect has no accent-insensitive LIKE
     */
    private function accentInsensitiveLikeClause(string $col, LikePattern $pattern, ISpecification $specification): SqlWhereClause
    {
        $param = $this->createParameter($pattern->pattern);
        $condition = $this->dialect instanceof ISqlAccentInsensitiveLike
            ? $this->dialect->formatAccentInsensitiveLike($col, $param['name'])
            : null;

        if ($condition === null) {
            throw new NonTranslatableSpecificationException(
                $specification,
                sprintf(
                    "a comparison that ignores case and accents has no equivalent in the %s dialect that selects the rows the in-memory evaluation accepts (it is translated for PostgreSQL and MySQL/MariaDB); evaluate it in PHP or compare with TextComparison::IgnoreCase",
                    $this->dialect->getFamily()
                )
            );
        }

        return new SqlWhereClause($condition . $pattern->escapeClause(), $param['binding']);
    }

    /**
     * Translate a regular expression compared ignoring case and accents (2.3.0): the dialect writes the
     * condition through ISqlAccentInsensitiveLike::accentInsensitiveRegex() over the folded body (the body
     * of the evaluated pattern); the modifiers follow translateRegex() (`i` and `u` only). A dialect that
     * cannot answer as the in-memory evaluation refuses the specification.
     *
     * @version 2.3.0
     * @param string $col
     * @param RegexSpecification $specification
     * @return SqlWhereClause
     * @throws UnsupportedSqlOperationException When a modifier other than i/u is present
     * @throws NonTranslatableSpecificationException When the dialect has no accent-insensitive regular expression
     */
    private function accentInsensitiveRegexClause(string $col, RegexSpecification $specification): SqlWhereClause
    {
        $unsupported = str_replace(['i', 'u'], '', $specification->getModifiers());
        if ($unsupported !== '') {
            throw new UnsupportedSqlOperationException("REGEX modifier \"{$unsupported}\"", $this->dialect->getFamily());
        }

        $param = $this->createParameter($specification->getBody());
        $clause = $this->dialect instanceof ISqlAccentInsensitiveLike
            ? $this->dialect->accentInsensitiveRegex($col, $param['name'], $specification->getBody())
            : null;

        if ($clause === null) {
            throw new NonTranslatableSpecificationException(
                $specification,
                sprintf(
                    "a regular expression that ignores case and accents has no equivalent in the %s dialect that selects the rows the in-memory evaluation accepts (it is translated for PostgreSQL); evaluate it in PHP or match with TextComparison::IgnoreCase",
                    $this->dialect->getFamily()
                )
            );
        }

        return $clause instanceof SqlWhereClause ? $clause : new SqlWhereClause($clause->getSql(), $clause->getParameters());
    }

    /**
     * Translate regular expressions using active SQL dialect.
     *
     * The bind receives the pattern body without the PHP delimiters. The `i` modifier maps to the
     * dialect's case-insensitive operator and `u` is accepted (the engine applies its own charset);
     * any other modifier has no portable SQL equivalent and is refused.
     *
     * @param string $col
     * @param RegexSpecification $specification
     * @return SqlWhereClause
     * @throws UnsupportedSqlOperationException When a modifier other than i/u is present
     */
    private function translateRegex(string $col, RegexSpecification $specification): SqlWhereClause
    {
        $modifiers = $specification->getModifiers();
        $unsupported = str_replace(['i', 'u'], '', $modifiers);
        if ($unsupported !== '') {
            throw new UnsupportedSqlOperationException("REGEX modifier \"{$unsupported}\"", $this->dialect->getFamily());
        }

        // Bug #73 (2.3.0): the body in the dialect's syntax (PostgreSQL: \b and \B as \y and \Y)
        $body = $this->dialect instanceof ISqlRegexRewriter
            ? $this->dialect->rewriteRegexBody($specification->getBody())
            : $specification->getBody();
        $param = $this->createParameter($body);
        $sql = $this->dialect->formatRegex($col, $param['name'], !str_contains($modifiers, 'i'));
        return new SqlWhereClause($sql, $param['binding']);
    }

    /**
     * `col IN (SELECT sq.c FROM t sq WHERE sq.c IS NOT NULL AND (<where>))` (2.5.0, forward 048, D20): the
     * selected column is always filtered with IS NOT NULL, so the negation stays two-valued.
     */
    private function translateInSubquery(string $col, InSubquerySpecification $specification): SqlWhereClause
    {
        $selected = $this->subqueryColumn($specification->getColumn());
        $where = $this->subqueryWhere($specification);

        return new SqlWhereClause(
            sprintf(
                '%s IN (SELECT %s FROM %s %s WHERE %s IS NOT NULL AND (%s))',
                $col,
                $selected,
                $this->quoteSubqueryIdentifier($specification->getTable()),
                $this->dialect->escapeIdentifier(AbstractSubquerySpecification::ALIAS),
                $selected,
                $where->getSql()
            ),
            $where->getParameters()
        );
    }

    /**
     * `EXISTS (SELECT 1 FROM t sq WHERE sq.fk = outer.col AND (<where>))` (2.5.0, forward 048, D20): an outer
     * property goes through the field mapping and is qualified with the outer table, unless the mapping
     * already qualified it.
     */
    private function translateExists(ExistsSubquerySpecification $specification): SqlWhereClause
    {
        $links = [];
        foreach ($specification->getOn() as $inner => $outer) {
            $mapped = $this->fieldMapper->mapField($outer);
            $outerColumn = (str_contains($mapped, '.') || str_contains($mapped, '('))
                ? $this->dialect->escapeIdentifier($mapped)
                : $this->quoteSubqueryIdentifier($specification->getOuterTable()) . '.' . $this->dialect->escapeIdentifier($mapped);
            $links[] = $this->subqueryColumn($inner) . ' = ' . $outerColumn;
        }
        $where = $this->subqueryWhere($specification);

        return new SqlWhereClause(
            sprintf(
                'EXISTS (SELECT 1 FROM %s %s WHERE %s AND (%s))',
                $this->quoteSubqueryIdentifier($specification->getTable()),
                $this->dialect->escapeIdentifier(AbstractSubquerySpecification::ALIAS),
                implode(' AND ', $links),
                $where->getSql()
            ),
            $where->getParameters()
        );
    }

    /**
     * The filter of the other table: a visitor of this dialect over the columns of `sq`, its parameters
     * prefixed apart (`:<prefix>s<N>_`).
     */
    private function subqueryWhere(AbstractSubquerySpecification $specification): SqlWhereClause
    {
        $visitor = new SqlQueryVisitor(
            $this->dialect,
            $specification->innerColumnMapper(),
            $this->paramPrefix . 's' . (++$this->subqueryCounter) . '_'
        );
        $where = $visitor->translate($specification->getWhere());

        return $where->isEmpty() ? new SqlWhereClause($this->dialect->getTrueCondition()) : $where;
    }

    /** A column of the other table, qualified with the alias. */
    private function subqueryColumn(string $column): string
    {
        return $this->dialect->escapeIdentifier(AbstractSubquerySpecification::ALIAS) . '.' . $this->quoteSubqueryIdentifier($column);
    }

    /**
     * An identifier of a subquery, segment by segment: a plain segment quoted by the dialect, a segment
     * written between double quotes re-quoted with the dialect's quote characters (the closing one doubled
     * inside).
     */
    private function quoteSubqueryIdentifier(string $identifier): string
    {
        $parts = [];
        foreach (AbstractSubquerySpecification::segments($identifier) as [$segment, $quoted]) {
            if (!$quoted) {
                $parts[] = $this->dialect->escapeIdentifier($segment);
                continue;
            }
            $probe = $this->dialect->escapeIdentifier('a');
            if (strlen($probe) !== 3) {
                // A dialect that does not delimit identifiers (Informix without DELIMIDENT)
                throw new UnsupportedSqlOperationException('a quoted identifier segment', $this->dialect->getFamily());
            }
            [$open, $close] = [$probe[0], $probe[2]];
            $parts[] = $open . str_replace($close, $close . $close, $segment) . $close;
        }

        return implode('.', $parts);
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
