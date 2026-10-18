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
use Antevemus\ASpecification\Engine\RuleBoundSpecification;
use Antevemus\ASpecification\Resolution\ExternalDataSpecification;
use Antevemus\ASpecification\Criteria\Exceptions\UnsafeCriteriaValueException;
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
use Antevemus\ASpecification\Sql\LikePattern;
use Antevemus\ASpecification\Sql\FieldMapper;

/**
 * CriteriaSpecificationVisitor - Specification AST Translator to Adianti Framework TCriteria
 *
 * Recursively traverses domain specification trees through the GoF Visitor pattern,
 * compiling an equivalent hierarchy of TCriteria, TFilter, and TExpression objects.
 *
 * Features:
 * - Compiles leaf specifications into TFilter instances (=, <>, >, <, LIKE, IS, IS NOT)
 * - Object-relational mapping of property names to database columns via IFieldMapper
 * - Supports De Morgan algebraic logic inversion for negations (NotSpecification)
 * - Preserves boolean operator precedence via nested TCriteria sub-instances
 * - Case-insensitive filter translation for textual specifications
 * - REGEXP for generic regular expressions, with the `i` modifier carried as an inline flag
 * - Set membership (InSpecification, 1.5.0) as `TFilter('col', 'IN', [...])` and its negation as
 *   `TFilter('col', 'NOT IN', [...])`
 * - A declarative method call (MethodParameterizedSpecification, 1.6.0) is opaque to TCriteria: it
 *   raises NonTranslatableCriteriaException, as the inline closure of must() does
 * - External data (ExternalDataSpecification, 1.10.0) only exists when the tree is evaluated in PHP by
 *   a DataResolver: it raises NonTranslatableCriteriaException, negated or not
 * - The null semantics of the in-memory evaluation (1.11.0). TCriteria has no NOT: negations are pushed
 *   down to the filters, so the criteria is an AND/OR tree in which a filter that SQL answers unknown
 *   for a NULL column excludes the row exactly as a false one. A plain comparison inside a property is
 *   false for a null value in memory and needs nothing; a negated comparison is TRUE for a null value
 *   in memory (`not(property('age', greaterThan(18)))`), so it becomes `(age IS NULL OR age <= 18)`;
 *   an AND/OR/NOT/NOR node directly inside a property rejects a null value in memory, so its negation
 *   becomes `(col IS NULL OR (...))`, and the node itself `(col IS NOT NULL AND (...))` when one of its
 *   filters is true for a NULL column (`IS NULL`, `1 = 1`); otherwise the row is already left out. The null checks, equality
 *   to null and a set holding null are already exact. Before 1.11.0, `not(property('age',
 *   greaterThan(18)))` was `(age <= 18)` and left out a row whose age was NULL
 * - A text comparison that ignores case and accents (TextComparison::IgnoreCaseAndAccents,
 *   equalIgnoreCaseAndAccents(); 2.1.0) raises NonTranslatableCriteriaException, negated or not: the
 *   folding is a different SQL expression per database, a TCriteria does not know which database will
 *   run it, and TFilter renders `variable operator value` with the value quoted inside, so the folding
 *   cannot wrap the value without concatenating it. Spec::toSql() with the dialect, or a PdoRepository,
 *   translates it for PostgreSQL and MySQL/MariaDB. 2.3.0: the same for `wildcard()` and `regex()` built
 *   with IgnoreCaseAndAccents; `wildcard()` built with `false`/IgnoreCase is the case-insensitive LIKE
 *   of wildcardIgnoreCase(), and `regex()` built with IgnoreCase carries the `i` modifier (`(?i)`)
 * - The criteria handed to the caller is safe to receive more conditions by AND (BUG-20261013-4CLQ).
 *   TRepository::load()/count()/update()/delete() of Adianti add the soft delete filter (DELETEDAT) and
 *   the PREFILTERS of the model to the SAME object with `add(..., AND)`, and TCriteria::dump() does not
 *   group what is already there; mergeCriteria() copies the expressions with their operators. A root
 *   built with OR at its top level (`or()`, `anyOf()`, `not(and())`, a negated comparison
 *   `(col IS NULL OR ...)`, a set holding null) would become `a OR b AND deleted_at IS NULL AND (...)`
 *   = `a OR (b AND ...)`: deleted rows and rows of other units leaked. buildCriteria() now nests such
 *   a root in a criteria of its own, `((a OR b))`; a root whose top level is AND, or a single filter,
 *   goes out as before
 *
 * @implements ISpecificationVisitor<TExpression>
 * @version    2.3.0
 * @package    Antevemus\ASpecification
 * @subpackage Criteria
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class CriteriaSpecificationVisitor implements ISpecificationVisitor
{
    private readonly IFieldMapper $fieldMapper;
    private ?string $currentProperty = null;

    /**
     * Whether the current column may still be NULL where the expression being built is evaluated: true
     * inside a property, false once a null check on that column encloses the expression (1.11.0).
     */
    private bool $currentNullable = false;

    /**
     * Number of filters built so far that are TRUE for a NULL column (`col IS NULL`, the constant
     * `1 = 1`); every other filter is false or unknown there (1.11.0).
     */
    private int $nullTolerantFilters = 0;

    /**
     * The criteria built by this visitor whose top-level operator is OR (BUG-20261013-4CLQ). Every
     * TCriteria of the tree is built by combine() with ONE operator, so the visitor records it instead of
     * reading the private operators of TCriteria back.
     *
     * @var \WeakMap<TCriteria, true>
     */
    private \WeakMap $disjunctions;

    /**
     * @param IFieldMapper|array<string, string>|callable(string): string|null $fieldMapper
     * @throws CriteriaBuilderException If the Adianti TCriteria class is not loaded
     */
    public function __construct(IFieldMapper|array|callable|null $fieldMapper = null)
    {
        if (!class_exists(TCriteria::class)) {
            throw new CriteriaBuilderException(
                "The class \\Adianti\\Database\\TCriteria is not loaded in the runtime environment."
            );
        }

        $this->fieldMapper = FieldMapper::from($fieldMapper);
        $this->disjunctions = new \WeakMap();
    }

    /**
     * Public entrypoint to compile a specification tree into a complete TCriteria instance.
     *
     * The criteria returned is safe for the caller (or TRepository) to `add()` more conditions with AND
     * (BUG-20261013-4CLQ): a root whose top-level operator is OR is nested in a criteria of its own, so
     * `add($filter)` gives `((a OR b) AND filter)`, never `(a OR b AND filter)`. A root whose top level
     * is AND, or a single filter, is returned as before (the same SQL). The returned criteria carries the
     * default properties of a new TCriteria; TCriteriaBuilder sets limit/offset/order/direction/group on it.
     *
     * @param ISpecification $specification
     * @return TCriteria
     */
    public function buildCriteria(ISpecification $specification): TCriteria
    {
        $expression = $this->visit($specification);

        if ($expression instanceof TCriteria && !isset($this->disjunctions[$expression])) {
            return $expression;
        }

        $criteria = new TCriteria();
        $criteria->add($expression);
        return $criteria;
    }

    /**
     * The criteria `(left operator right)`, recorded as a disjunction when the operator is OR (see
     * buildCriteria()). Every TCriteria of the compiled tree is built here.
     *
     * @param TExpression $left
     * @param TExpression $right
     * @param string $operator TExpression::AND_OPERATOR or TExpression::OR_OPERATOR
     * @return TCriteria
     */
    private function combine(TExpression $left, TExpression $right, string $operator): TCriteria
    {
        $criteria = new TCriteria();
        $criteria->add($left);
        $criteria->add($right, $operator);
        if ($operator === TExpression::OR_OPERATOR) {
            $this->disjunctions[$criteria] = true;
        }

        return $criteria;
    }

    /**
     * Visit any specification node by dispatching to appropriate handler method.
     *
     * @param ISpecification $specification
     * @return TExpression
     */
    public function visit(ISpecification $specification): TExpression
    {
        if ($specification instanceof RuleBoundSpecification) {
            return $this->visit($specification->getInnerSpecification());
        }

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
        if ($this->currentProperty !== null && $this->currentNullable && self::isLogicalNode($specification)) {
            // In memory an AND/OR/NOT/NOR node rejects a null value: false for a NULL column (1.11.0)
            return $this->withNullCheck(TExpression::AND_OPERATOR, fn(): TExpression => $this->visitComposite($specification), true);
        }

        return match (true) {
            $specification instanceof PropertySpecification =>
                $this->visitPropertySpecification($specification),

            $specification instanceof AndSpecification =>
                $this->buildBinaryCriteria($specification->getLeftSide(), $specification->getRightSide(), TExpression::AND_OPERATOR),

            $specification instanceof OrSpecification =>
                $this->buildBinaryCriteria($specification->getLeftSide(), $specification->getRightSide(), TExpression::OR_OPERATOR),

            $specification instanceof NotSpecification =>
                $this->visitNot($specification->getSpecification()),

            // NOR = NOT (left OR right) = NOT left AND NOT right (De Morgan)
            $specification instanceof JointDenialSpecification =>
                $this->buildBinaryNotCriteria($specification->getLeftSide(), $specification->getRightSide(), TExpression::AND_OPERATOR),

            default => throw new NonTranslatableCriteriaException($specification, "Unknown composite specification node."),
        };
    }

    /**
     * Set target property context and visit the wrapped inner specification.
     *
     * @param PropertySpecification $specification
     * @return TExpression
     */
    private function visitPropertySpecification(PropertySpecification $specification): TExpression
    {
        $previousProperty = $this->currentProperty;
        $previousNullable = $this->currentNullable;
        $this->currentProperty = $this->fieldMapper->map($specification->getPropertyName());
        $this->currentNullable = true;

        try {
            return $this->visit($specification->getInnerSpecification());
        } finally {
            $this->currentProperty = $previousProperty;
            $this->currentNullable = $previousNullable;
        }
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
     * Combine a null check on the current column with an expression built while the column is known
     * to be (AND: `col IS NOT NULL AND ...`) or allowed to be (OR: `col IS NULL OR ...`) NULL; the
     * expression is built with the column treated as not null, so it carries no further null check.
     *
     * With `$onlyWhenTolerant`, the `IS NOT NULL` check is added only when the expression holds a filter
     * that is true for a NULL column: a criteria is an AND/OR tree (negations are pushed down to the
     * filters), so without such a filter it already leaves a NULL row out.
     *
     * @param string $operator TExpression::AND_OPERATOR or TExpression::OR_OPERATOR
     * @param \Closure(): TExpression $build
     * @param bool $onlyWhenTolerant
     * @return TExpression
     */
    private function withNullCheck(string $operator, \Closure $build, bool $onlyWhenTolerant = false): TExpression
    {
        $column = (string) $this->currentProperty;
        $tolerantBefore = $this->nullTolerantFilters;
        $this->currentNullable = false;
        try {
            $expression = $build();
        } finally {
            $this->currentNullable = true;
        }

        if ($onlyWhenTolerant && $this->nullTolerantFilters === $tolerantBefore) {
            return $expression;
        }
        if ($operator === TExpression::OR_OPERATOR) {
            $this->nullTolerantFilters++;
        }

        return $this->combine(new TFilter($column, $operator === TExpression::AND_OPERATOR ? 'IS NOT' : 'IS', null), $expression, $operator);
    }

    /**
     * Construct a new TCriteria instance combining left and right branches with a logical operator.
     *
     * @param ISpecification $left
     * @param ISpecification $right
     * @param string $operator
     * @return TCriteria
     */
    private function buildBinaryCriteria(ISpecification $left, ISpecification $right, string $operator): TCriteria
    {
        $leftExpression = $this->visit($left);

        return $this->combine($leftExpression, $this->visit($right), $operator);
    }

    /**
     * {@inheritdoc}
     */
    public function visitLeaf(ISpecification $specification): TExpression
    {
        // Universal Tautology and Contradiction (column-independent)
        if ($specification instanceof AlwaysTrueSpecification) {
            return $this->nullTolerant(new TFilter('1', '=', 1));
        }

        if ($specification instanceof AlwaysFalseSpecification) {
            return new TFilter('1', '=', 0);
        }

        if ($specification instanceof PredicateSpecification) {
            throw new NonTranslatableCriteriaException(
                $specification,
                "An inline PHP closure (must()) is opaque and cannot be converted to TCriteria; express the rule with property specifications."
            );
        }

        if ($specification instanceof MethodParameterizedSpecification) {
            throw self::methodCallNotTranslatable($specification);
        }

        if ($specification instanceof ExternalDataSpecification) {
            throw self::externalDataNotTranslatable($specification);
        }

        if (self::ignoresAccents($specification)) {
            throw self::accentInsensitiveNotTranslatable($specification);
        }

        $col = $this->currentProperty;
        if ($col === null) {
            throw new NonTranslatableCriteriaException(
                $specification,
                "Leaf specifications must be bound to a property/column via PropertySpecification."
            );
        }

        $this->assertSafeValue($col, $specification);

        return match (true) {
            $specification instanceof EqualSpecification =>
                $this->translateEqual($col, $specification->getValue()),

            $specification instanceof InSpecification =>
                $this->translateIn($col, $specification, false),

            $specification instanceof NotEqualSpecification =>
                $this->translateNotEqual($col, $specification->getValue()),

            $specification instanceof GreaterThanSpecification =>
                new TFilter($col, '>', $specification->getValue()),

            $specification instanceof LessThanSpecification =>
                new TFilter($col, '<', $specification->getValue()),

            $specification instanceof GreaterThanOrEqualSpecification =>
                new TFilter($col, '>=', $specification->getValue()),

            $specification instanceof LessThanOrEqualSpecification =>
                new TFilter($col, '<=', $specification->getValue()),

            $specification instanceof NotNullSpecification =>
                new TFilter($col, 'IS NOT', null),

            $specification instanceof IsNullSpecification =>
                $this->nullTolerant(new TFilter($col, 'IS', null)),

            $specification instanceof WildcardSpecification =>
                $this->translateWildcard($col, $specification->getPattern(), !$specification->isCaseSensitive()),

            $specification instanceof WildcardExpressionMatcherIgnoreCaseStringSpecification =>
                $this->translateWildcard($col, $specification->getPattern(), true),

            $specification instanceof EqualIgnoreCaseStringSpecification =>
                $this->translateEqualIgnoreCase($col, $specification->getValue()),

            // startsWith/endsWith/contains: portable LIKE, never REGEXP (BUG-20261007-3TVR)
            $specification instanceof LiteralPatternSpecification =>
                $this->translateLiteralPattern($col, $specification, false),

            $specification instanceof RegexSpecification =>
                new TFilter($col, 'REGEXP', $this->regexPattern($specification)),

            $specification instanceof IValueBoundSpecification =>
                new TFilter($col, '=', $specification->getValue()),

            default => throw new NonTranslatableCriteriaException($specification),
        };
    }

    /**
     * Translate equality to TFilter, treating null as SQL IS.
     *
     * @param string $col
     * @param mixed $val
     * @return TFilter
     */
    private function translateEqual(string $col, mixed $val): TFilter
    {
        if ($val === null) {
            return $this->nullTolerant(new TFilter($col, 'IS', null));
        }
        return new TFilter($col, '=', $val);
    }

    /**
     * Translate set membership as TFilter('col', 'IN', [...]) (or 'NOT IN' when negated).
     *
     * The empty set is the contradiction `1 = 0` (its negation the tautology `1 = 1`). SQL `IN`
     * never matches NULL, so a null member is carried by an explicit `IS NULL` (`IS NOT NULL`
     * when negated) combined with OR (AND when negated, De Morgan).
     *
     * @param string $col
     * @param InSpecification $specification
     * @param bool $negated Whether the leaf is being inverted (NOT IN)
     * @return TExpression
     */
    private function translateIn(string $col, InSpecification $specification, bool $negated): TExpression
    {
        $values = $specification->getValues();
        if ($values === []) {
            return $negated ? $this->nullTolerant(new TFilter('1', '=', 1)) : new TFilter('1', '=', 0);
        }

        $nonNull = array_values(array_filter($values, static fn(mixed $value): bool => $value !== null));
        $hasNull = count($nonNull) !== count($values);
        $nullFilter = $negated ? new TFilter($col, 'IS NOT', null) : $this->nullTolerant(new TFilter($col, 'IS', null));

        if ($nonNull === []) {
            return $nullFilter;
        }

        $inFilter = new TFilter($col, $negated ? 'NOT IN' : 'IN', $nonNull);
        if (!$hasNull) {
            return $inFilter;
        }

        return $this->combine($inFilter, $nullFilter, $negated ? TExpression::AND_OPERATOR : TExpression::OR_OPERATOR);
    }

    /**
     * Translate inequality to TFilter, treating null as SQL IS NOT.
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
     * Translate wildcard patterns: * becomes %, ? becomes _, and the literal %, _ and ! of the
     * glob are escaped so the LIKE matches what fnmatch matches in memory (BUG-20261007-ZY6E).
     *
     * @param string $col
     * @param string $rawPattern
     * @param bool $caseInsensitive
     * @return TFilter
     */
    private function translateWildcard(string $col, string $rawPattern, bool $caseInsensitive): TFilter
    {
        return $this->likeFilter($col, 'LIKE', LikePattern::fromGlob($rawPattern), $caseInsensitive);
    }

    /**
     * Translate startsWith/endsWith/contains as a portable LIKE over the literal, with the same
     * wildcard escaping as the SQL visitor (BUG-20261007-3TVR). A generic RegexSpecification keeps
     * going out as REGEXP (with its case flag inline, see regexPattern()); these three leaves are
     * recognised before that branch.
     *
     * @param string $col
     * @param LiteralPatternSpecification $specification
     * @param bool $negated Whether the leaf is being inverted (De Morgan)
     * @return TFilter
     */
    private function translateLiteralPattern(string $col, LiteralPatternSpecification $specification, bool $negated): TFilter
    {
        return $this->likeFilter(
            $col,
            $negated ? 'NOT LIKE' : 'LIKE',
            LikePattern::fromLiteral($specification),
            !$specification->isCaseSensitive()
        );
    }

    /**
     * Build a LIKE / NOT LIKE filter. Case-insensitive leaves use TCaseInsensitiveFilter,
     * because the real TCriteria::dump() resets every child's flag to its own (false by
     * default) and a plain TFilter would silently become case-sensitive (BUG-20261007-M646).
     * A pattern that relies on escaped wildcards is emitted by TEscapedLikeFilter, the only way
     * to get an ESCAPE clause past TFilter::dump() without raw SQL.
     *
     * @param string $col
     * @param string $operator 'LIKE' or 'NOT LIKE'
     * @param LikePattern|string $value Escaped pattern, or a plain value (equalIgnoreCase)
     * @param bool $caseInsensitive
     * @return TFilter
     */
    private function likeFilter(string $col, string $operator, LikePattern|string $value, bool $caseInsensitive): TFilter
    {
        if ($value instanceof LikePattern) {
            if ($value->escaped) {
                return new TEscapedLikeFilter($col, $operator, $value->pattern, LikePattern::ESCAPE, $caseInsensitive);
            }
            $value = $value->pattern;
        }

        return $caseInsensitive
            ? new TCaseInsensitiveFilter($col, $operator, $value)
            : new TFilter($col, $operator, $value);
    }

    /**
     * Translate negated wildcard patterns (NOT LIKE) with the same escaping as translateWildcard().
     *
     * @param string $col
     * @param string $rawPattern
     * @param bool $caseInsensitive
     * @return TFilter
     */
    private function translateNotWildcard(string $col, string $rawPattern, bool $caseInsensitive): TFilter
    {
        return $this->likeFilter($col, 'NOT LIKE', LikePattern::fromGlob($rawPattern), $caseInsensitive);
    }

    /**
     * Translate case-insensitive equality via LIKE with case-insensitive flag enabled; `%` and `_` in
     * the value are escaped (1.14.0), so they match themselves only.
     *
     * @param string $col
     * @param mixed $value
     * @return TFilter
     */
    private function translateEqualIgnoreCase(string $col, mixed $value): TFilter
    {
        return $this->likeFilter($col, 'LIKE', LikePattern::exact((string) $value), true);
    }

    /**
     * Translate case-insensitive inequality via NOT LIKE with case-insensitive flag enabled; `%` and
     * `_` in the value are escaped (1.14.0).
     *
     * @param string $col
     * @param mixed $value
     * @return TFilter
     */
    private function translateNotEqualIgnoreCase(string $col, mixed $value): TFilter
    {
        return $this->likeFilter($col, 'NOT LIKE', LikePattern::exact((string) $value), true);
    }

    /**
     * Translate logical negation applying De Morgan laws and relational operator inversion.
     *
     * @param ISpecification $inner
     * @return TExpression
     */
    private function visitNot(ISpecification $inner): TExpression
    {
        if ($this->currentProperty !== null && $this->currentNullable && self::isLogicalNode($inner)) {
            // The node is false for a null value in memory, so its negation is true there (1.11.0)
            return $this->withNullCheck(TExpression::OR_OPERATOR, fn(): TExpression => $this->visitNot($inner));
        }

        return match (true) {
            $inner instanceof RuleBoundSpecification =>
                $this->visitNot($inner->getInnerSpecification()),

            $inner instanceof NotSpecification =>
                $this->visit($inner->getSpecification()),

            $inner instanceof AndSpecification =>
                $this->buildBinaryNotCriteria($inner->getLeftSide(), $inner->getRightSide(), TExpression::OR_OPERATOR),

            $inner instanceof OrSpecification =>
                $this->buildBinaryNotCriteria($inner->getLeftSide(), $inner->getRightSide(), TExpression::AND_OPERATOR),

            // NOT (NOR(a, b)) = a OR b
            $inner instanceof JointDenialSpecification =>
                $this->buildBinaryCriteria($inner->getLeftSide(), $inner->getRightSide(), TExpression::OR_OPERATOR),

            $inner instanceof PropertySpecification =>
                $this->visitNotPropertySpecification($inner),

            $inner instanceof AlwaysTrueSpecification =>
                new TFilter('1', '=', 0),

            $inner instanceof AlwaysFalseSpecification =>
                $this->nullTolerant(new TFilter('1', '=', 1)),

            default => $this->visitNotLeaf($inner),
        };
    }

    /**
     * Set target property context and visit the negated inner specification.
     *
     * @param PropertySpecification $specification
     * @return TExpression
     */
    private function visitNotPropertySpecification(PropertySpecification $specification): TExpression
    {
        $previousProperty = $this->currentProperty;
        $previousNullable = $this->currentNullable;
        $this->currentProperty = $this->fieldMapper->map($specification->getPropertyName());
        $this->currentNullable = true;

        try {
            return $this->visitNot($specification->getInnerSpecification());
        } finally {
            $this->currentProperty = $previousProperty;
            $this->currentNullable = $previousNullable;
        }
    }

    /**
     * Construct a new TCriteria instance combining negated left and right branches with a logical operator.
     *
     * @param ISpecification $left
     * @param ISpecification $right
     * @param string $operator
     * @return TCriteria
     */
    private function buildBinaryNotCriteria(ISpecification $left, ISpecification $right, string $operator): TCriteria
    {
        $leftExpression = $this->visitNot($left);

        return $this->combine($leftExpression, $this->visitNot($right), $operator);
    }

    /**
     * Logically invert a leaf specification bound to current property.
     *
     * @param ISpecification $inner
     * @return TExpression A TFilter, or a TCriteria for a negated set holding null
     */
    private function visitNotLeaf(ISpecification $inner): TExpression
    {
        if ($inner instanceof RuleBoundSpecification) {
            return $this->visitNotLeaf($inner->getInnerSpecification());
        }

        if ($inner instanceof PredicateSpecification) {
            throw new NonTranslatableCriteriaException(
                $inner,
                "An inline PHP closure (must()) is opaque and cannot be converted to TCriteria; express the rule with property specifications."
            );
        }

        if ($inner instanceof MethodParameterizedSpecification) {
            throw self::methodCallNotTranslatable($inner);
        }

        if ($inner instanceof ExternalDataSpecification) {
            throw self::externalDataNotTranslatable($inner);
        }

        if (self::ignoresAccents($inner)) {
            throw self::accentInsensitiveNotTranslatable($inner);
        }

        $col = $this->currentProperty;
        if ($col === null) {
            throw new NonTranslatableCriteriaException(
                $inner,
                "Negated leaf specification must be bound to a property."
            );
        }

        $this->assertSafeValue($col, $inner);

        $negated = match (true) {
            $inner instanceof EqualSpecification =>
                $this->translateNotEqual($col, $inner->getValue()),

            $inner instanceof InSpecification =>
                $this->translateIn($col, $inner, true),

            $inner instanceof NotEqualSpecification =>
                $this->translateEqual($col, $inner->getValue()),

            $inner instanceof GreaterThanSpecification =>
                new TFilter($col, '<=', $inner->getValue()),

            $inner instanceof LessThanSpecification =>
                new TFilter($col, '>=', $inner->getValue()),

            $inner instanceof GreaterThanOrEqualSpecification =>
                new TFilter($col, '<', $inner->getValue()),

            $inner instanceof LessThanOrEqualSpecification =>
                new TFilter($col, '>', $inner->getValue()),

            $inner instanceof NotNullSpecification =>
                $this->nullTolerant(new TFilter($col, 'IS', null)),

            $inner instanceof IsNullSpecification =>
                new TFilter($col, 'IS NOT', null),

            $inner instanceof WildcardSpecification =>
                $this->translateNotWildcard($col, $inner->getPattern(), !$inner->isCaseSensitive()),

            $inner instanceof WildcardExpressionMatcherIgnoreCaseStringSpecification =>
                $this->translateNotWildcard($col, $inner->getPattern(), true),

            $inner instanceof EqualIgnoreCaseStringSpecification =>
                $this->translateNotEqualIgnoreCase($col, $inner->getValue()),

            $inner instanceof LiteralPatternSpecification =>
                $this->translateLiteralPattern($col, $inner, true),

            $inner instanceof RegexSpecification =>
                new TFilter($col, 'NOT REGEXP', $this->regexPattern($inner)),

            default => throw new NonTranslatableCriteriaException($inner, "Unable to logically invert specified rule."),
        };

        if (!$this->currentNullable || self::isNullAwareLeaf($inner)) {
            return $negated;
        }

        // A comparison is false for a null value in memory, so its negation is true there (1.11.0):
        // `col IS NULL OR <inverted comparison>`, where SQL alone would answer unknown
        return $this->combine($this->nullTolerant(new TFilter($col, 'IS', null)), $negated, TExpression::OR_OPERATOR);
    }

    /**
     * Count a filter that is true for a NULL column (see withNullCheck()).
     */
    private function nullTolerant(TFilter $filter): TFilter
    {
        $this->nullTolerantFilters++;

        return $filter;
    }

    /**
     * Whether the filter of a leaf already answers for a NULL column what the leaf answers for a null
     * value: the null checks, equality and inequality to null, a set holding null and the empty set.
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
     * Refuse values that the Adianti TFilter would emit as raw SQL (BUG-20261007-KJ36).
     *
     * TFilter::transform() treats three string shapes as unescaped SQL, even in prepared mode:
     * a value starting with "(SELECT", a value containing "{session." and a value starting with
     * "NOESC:". There is no way to escape them without changing the literal, so the visitor
     * fails closed before the TFilter is built. Arrays (IN lists) are checked element by element.
     *
     * @param string $col Mapped column, for the error message
     * @param ISpecification $leaf Leaf about to be translated
     * @throws UnsafeCriteriaValueException
     */
    private function assertSafeValue(string $col, ISpecification $leaf): void
    {
        $value = match (true) {
            // The literal is what reaches the TFilter as a LIKE operand (prefix first, without the regex anchor)
            $leaf instanceof LiteralPatternSpecification => $leaf->getLiteral(),
            $leaf instanceof WildcardSpecification,
            $leaf instanceof WildcardExpressionMatcherIgnoreCaseStringSpecification,
            $leaf instanceof RegexSpecification => $leaf->getPattern(),
            $leaf instanceof InSpecification => $leaf->getValues(),
            $leaf instanceof IValueBoundSpecification => $leaf->getValue(),
            method_exists($leaf, 'getValue') => $leaf->getValue(),
            default => null,
        };

        foreach (is_array($value) ? $value : [$value] as $item) {
            if (!is_string($item)) {
                continue;
            }
            $probe = ltrim($item);
            if (strncasecmp($probe, '(SELECT', 7) === 0) {
                throw new UnsafeCriteriaValueException($col, 'value starts with "(SELECT" (subselect passthrough)');
            }
            if (str_contains($probe, '{session.')) {
                throw new UnsafeCriteriaValueException($col, 'value contains "{session." (session variable passthrough)');
            }
            if (strncmp($probe, 'NOESC:', 6) === 0) {
                throw new UnsafeCriteriaValueException($col, 'value starts with "NOESC:" (no-escape passthrough)');
            }
        }
    }

    /**
     * Value bound to the REGEXP operator: the pattern body (PHP delimiters stripped) prefixed by an
     * inline case flag that carries the `i` modifier (BUG-20261007-K7RM).
     *
     * TCriteria has no notion of dialect, so the modifier cannot be mapped to an operator the way the
     * SQL visitor does (`~*`, `REGEXP BINARY`); the flag travels inside the pattern instead: `(?i)` when
     * the specification is case-insensitive, `(?-i)` otherwise, because MySQL's REGEXP is
     * case-insensitive by default on non-binary columns. Both flags are understood by the engines
     * where the REGEXP operator exists (MySQL 8 ICU, MariaDB PCRE, a PCRE function registered on
     * SQLite). `u` is accepted (the engine applies its own charset); any other modifier has no
     * equivalent and is refused. A legacy pattern without delimiters goes out unchanged.
     *
     * @param RegexSpecification $specification
     * @return string
     * @throws NonTranslatableCriteriaException When the pattern carries a modifier other than i or u
     */
    private function regexPattern(RegexSpecification $specification): string
    {
        $body = $specification->getBody();
        if ($body === $specification->getPattern()) {
            return $body;
        }

        $modifiers = $specification->getModifiers();
        $unsupported = str_replace(['i', 'u'], '', $modifiers);
        if ($unsupported !== '') {
            throw new NonTranslatableCriteriaException(
                $specification,
                "REGEX modifier \"{$unsupported}\" has no equivalent in TCriteria (only \"i\" and \"u\" are supported)."
            );
        }

        return (str_contains($modifiers, 'i') ? '(?i)' : '(?-i)') . $body;
    }

    /**
     * The exception for a declarative method call: it is evaluated in PHP, never in the database.
     */
    private static function methodCallNotTranslatable(MethodParameterizedSpecification $specification): NonTranslatableCriteriaException
    {
        return new NonTranslatableCriteriaException(
            $specification,
            sprintf(
                "A method call on the candidate (%s) is evaluated in PHP and cannot be converted to TCriteria; express the rule with property specifications.",
                $specification->getRuleName()
            )
        );
    }

    /**
     * Whether a leaf compares text ignoring case and accents (2.1.0; wildcard and regex, 2.3.0).
     */
    private static function ignoresAccents(ISpecification $specification): bool
    {
        // RegexSpecification covers contains()/startsWith()/endsWith() (LiteralPatternSpecification) and,
        // since 2.3.0, regex(); WildcardSpecification is wildcard() (2.3.0)
        return $specification instanceof EqualIgnoreCaseAndAccentsStringSpecification
            || ($specification instanceof RegexSpecification && $specification->ignoresAccents())
            || ($specification instanceof WildcardSpecification && $specification->ignoresAccents());
    }

    /**
     * The exception for a text comparison that ignores case and accents (2.1.0): its folding is an
     * expression of the database, which a TCriteria does not know.
     */
    private static function accentInsensitiveNotTranslatable(ISpecification $specification): NonTranslatableCriteriaException
    {
        return new NonTranslatableCriteriaException(
            $specification,
            "A text comparison that ignores case and accents cannot be converted to TCriteria: the folding is a different SQL expression per database and TFilter cannot wrap its value in it; use Spec::toSql() with the dialect (PostgreSQL; MySQL/MariaDB except regex()), a PdoRepository, or evaluate it in PHP."
        );
    }

    /**
     * The "non translatable" exception of an external data leaf (1.10.0): the data only exists when
     * the tree is evaluated in PHP by a DataResolver.
     */
    private static function externalDataNotTranslatable(ExternalDataSpecification $specification): NonTranslatableCriteriaException
    {
        return new NonTranslatableCriteriaException(
            $specification,
            sprintf(
                "External data (source '%s') is fetched by a DataResolver when the tree is evaluated in PHP and cannot be converted to TCriteria; filter with the other rules and evaluate the external part in PHP.",
                $specification->getSource()
            )
        );
    }
}
