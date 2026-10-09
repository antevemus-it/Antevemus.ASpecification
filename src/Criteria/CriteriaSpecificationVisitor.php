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
use Antevemus\ASpecification\Criteria\Exceptions\UnsafeCriteriaValueException;
use Antevemus\ASpecification\Specifications\AndSpecification;
use Antevemus\ASpecification\Specifications\Comparison\EqualSpecification;
use Antevemus\ASpecification\Specifications\Comparison\GreaterThanOrEqualSpecification;
use Antevemus\ASpecification\Specifications\Comparison\GreaterThanSpecification;
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
use Antevemus\ASpecification\Specifications\OrSpecification;
use Antevemus\ASpecification\Specifications\PropertySpecification;
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
 *
 * @implements ISpecificationVisitor<TExpression>
 * @version    1.4.4
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
    }

    /**
     * Public entrypoint to compile a specification tree into a complete TCriteria instance.
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
        $this->currentProperty = $this->fieldMapper->map($specification->getPropertyName());

        try {
            return $this->visit($specification->getInnerSpecification());
        } finally {
            $this->currentProperty = $previousProperty;
        }
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
        // Universal Tautology and Contradiction (column-independent)
        if ($specification instanceof AlwaysTrueSpecification) {
            return new TFilter('1', '=', 1);
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
                new TFilter($col, 'IS', null),

            $specification instanceof WildcardSpecification =>
                $this->translateWildcard($col, $specification->getPattern(), false),

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
            return new TFilter($col, 'IS', null);
        }
        return new TFilter($col, '=', $val);
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
     * Translate case-insensitive equality via LIKE with case-insensitive flag enabled.
     *
     * @param string $col
     * @param mixed $value
     * @return TFilter
     */
    private function translateEqualIgnoreCase(string $col, mixed $value): TFilter
    {
        return $this->likeFilter($col, 'LIKE', $value, true);
    }

    /**
     * Translate case-insensitive inequality via NOT LIKE with case-insensitive flag enabled.
     *
     * @param string $col
     * @param mixed $value
     * @return TFilter
     */
    private function translateNotEqualIgnoreCase(string $col, mixed $value): TFilter
    {
        return $this->likeFilter($col, 'NOT LIKE', $value, true);
    }

    /**
     * Translate logical negation applying De Morgan laws and relational operator inversion.
     *
     * @param ISpecification $inner
     * @return TExpression
     */
    private function visitNot(ISpecification $inner): TExpression
    {
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
                new TFilter('1', '=', 1),

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
        $this->currentProperty = $this->fieldMapper->map($specification->getPropertyName());

        try {
            return $this->visitNot($specification->getInnerSpecification());
        } finally {
            $this->currentProperty = $previousProperty;
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
        $criteria = new TCriteria();
        $criteria->add($this->visitNot($left));
        $criteria->add($this->visitNot($right), $operator);
        return $criteria;
    }

    /**
     * Logically invert a leaf specification bound to current property.
     *
     * @param ISpecification $inner
     * @return TFilter
     */
    private function visitNotLeaf(ISpecification $inner): TFilter
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

        $col = $this->currentProperty;
        if ($col === null) {
            throw new NonTranslatableCriteriaException(
                $inner,
                "Negated leaf specification must be bound to a property."
            );
        }

        $this->assertSafeValue($col, $inner);

        return match (true) {
            $inner instanceof EqualSpecification =>
                $this->translateNotEqual($col, $inner->getValue()),

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
                new TFilter($col, 'IS', null),

            $inner instanceof IsNullSpecification =>
                new TFilter($col, 'IS NOT', null),

            $inner instanceof WildcardSpecification =>
                $this->translateNotWildcard($col, $inner->getPattern(), false),

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
}
