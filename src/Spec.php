<?php

declare(strict_types=1);

/**
 * Spec - Unified static facade for the Specification pattern
 *
 * Provides static entry points and fluent ergonomic shortcuts for constructing,
 * composing, and evaluating specifications across the Antevemus.ASpecification library.
 *
 * Features:
 * - Fluent typed composite specification creation (specify)
 * - Logical composition shortcuts (allOf, anyOf, not)
 * - Value and relational comparison operators (is, equalTo, greaterThan, lessThan, in)
 * - Temporal and calendar date validations (before, isBefore, after, isAfter, between)
 * - String evaluation and collection cardinality (contains, startsWith, isEmpty, hasSize)
 * - Declarative attribute validation runner (validateAttributes, assertAttributes)
 * - Declarative method-call restriction (calling, 1.6.0)
 * - Structural tautology/contradiction detection (isTautology, isContradiction, 1.6.0)
 * - Dynamic redirection via __callStatic to underlying SpecificationFactory
 *
 * @version    1.6.0
 * @package    Antevemus\ASpecification
 * @subpackage Facade
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */

namespace Antevemus\ASpecification;

use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Factory\SpecificationFactory;
use Antevemus\ASpecification\Specifications\PropertySpecification;
use Antevemus\ASpecification\Specifications\Reflection\MethodParameterizedSpecification;
use DateTimeInterface;

final class Spec
{
    private static ?SpecificationFactory $factory = null;

    /**
     * Private constructor to prevent direct instantiation of static facade.
     */
    private function __construct()
    {
    }

    /**
     * Retrieves the shared singleton instance of SpecificationFactory.
     *
     * @return SpecificationFactory
     */
    public static function getFactory(): SpecificationFactory
    {
        if (self::$factory === null) {
            self::$factory = new SpecificationFactory();
        }
        return self::$factory;
    }

    /**
     * Configures or replaces the shared SpecificationFactory instance (useful for testing/mocking).
     *
     * @param SpecificationFactory|null $factory Custom factory instance
     * @return void
     */
    public static function setFactory(?SpecificationFactory $factory): void
    {
        self::$factory = $factory;
    }

    // ==========================================
    // 1. Type and Parameterized Specifications
    // ==========================================

    /**
     * Initiates construction of a parameterized composite specification bound to a domain class.
     *
     * @param string $type FQCN or interface name (e.g. Customer::class)
     * @return ICompositeSpecification
     */
    public static function specify(string $type): ICompositeSpecification
    {
        return self::getFactory()->specify($type);
    }

    /**
     * Creates a specification bound to an object property or entity attribute.
     *
     * @param string $propertyName Target property name or dot-notation path
     * @param ISpecification $specification Rule evaluated against property value
     * @param ISpecification|null $baseSpecification Base root specification (default: AlwaysTrue)
     * @return PropertySpecification
     */
    public static function property(
        string $propertyName,
        ISpecification $specification,
        ?ISpecification $baseSpecification = null
    ): PropertySpecification {
        return new PropertySpecification(
            $baseSpecification ?? self::alwaysTrue(),
            $propertyName,
            $specification
        );
    }

    /**
     * Creates a declarative method-call specification: calls the public method `$methodName` of the
     * candidate with `$arguments` and applies `$resultSpecification` to the returned value
     * (1.6.0, forward 018). Field access is where()/property(); this is its method counterpart.
     *
     * <code>
     * Spec::calling('isEligibleFor', [new DateTimeImmutable('2026-12-01')], Spec::isTrue());
     * </code>
     *
     * @param string $methodName Public method of the candidate to call
     * @param list<mixed> $arguments Positional, declarative arguments (scalars, null, arrays of those,
     *                               BackedEnum, DateTimeInterface)
     * @param ISpecification $resultSpecification Specification applied to the returned value
     * @return MethodParameterizedSpecification
     * @throws \InvalidArgumentException If the method name is invalid or an argument is not declarative
     */
    public static function calling(
        string $methodName,
        array $arguments,
        ISpecification $resultSpecification
    ): MethodParameterizedSpecification {
        return new MethodParameterizedSpecification($methodName, $arguments, $resultSpecification);
    }

    /**
     * Tells whether the structure of the specification proves it is satisfied by every (non-null)
     * candidate (1.6.0, forward 019). Structural and conservative: false means "not proven".
     *
     * @param ISpecification $specification Specification to inspect
     * @return bool
     */
    public static function isTautology(ISpecification $specification): bool
    {
        return $specification->isTautology();
    }

    /**
     * Tells whether the structure of the specification proves no candidate satisfies it
     * (1.6.0, forward 019). Structural and conservative: false means "not proven".
     *
     * @param ISpecification $specification Specification to inspect
     * @return bool
     */
    public static function isContradiction(ISpecification $specification): bool
    {
        return $specification->isContradiction();
    }

    // ==========================================
    // 2. Logical Composition Operators
    // ==========================================

    /**
     * Creates a logical conjunction (AND) requiring all given specifications to be met.
     *
     * @param ISpecification ...$specifications Specifications to combine
     * @return ISpecification
     */
    public static function allOf(ISpecification ...$specifications): ISpecification
    {
        return self::getFactory()->allOf(...$specifications);
    }

    /**
     * Creates a logical disjunction (OR) requiring at least one specification to be met.
     *
     * @param ISpecification ...$specifications Specifications to combine
     * @return ISpecification
     */
    public static function anyOf(ISpecification ...$specifications): ISpecification
    {
        return self::getFactory()->anyOf(...$specifications);
    }

    /**
     * Inverts the given specification via logical negation (NOT).
     *
     * @param ISpecification $specification Specification to invert
     * @return ISpecification
     */
    public static function not(ISpecification $specification): ISpecification
    {
        return self::getFactory()->not($specification);
    }

    /**
     * Creates a joint denial (logical NOR): satisfied only when NONE of the given specifications is met.
     *
     * Equivalent to NOT (a OR b OR ...). No argument → tautology; one → its negation;
     * two or more → `JointDenialSpecification`.
     *
     * @param ISpecification ...$specifications Specifications that must all be unsatisfied
     * @return ISpecification
     */
    public static function nor(ISpecification ...$specifications): ISpecification
    {
        return self::getFactory()->nor(...$specifications);
    }

    /**
     * Alias for nor().
     *
     * @param ISpecification ...$specifications Specifications that must all be unsatisfied
     * @return ISpecification
     */
    public static function noneOf(ISpecification ...$specifications): ISpecification
    {
        return self::getFactory()->noneOf(...$specifications);
    }

    // ==========================================
    // 3. Value Comparison and Identity
    // ==========================================

    /**
     * Creates an equality or identity leaf specification for a value.
     *
     * @param mixed $value Expected value
     * @return ISpecification
     */
    public static function is(mixed $value): ISpecification
    {
        return self::getFactory()->is($value);
    }

    /**
     * Specifies that candidate value must equal the expected value.
     *
     * @param mixed $value Expected value
     * @return ISpecification
     */
    public static function equalTo(mixed $value): ISpecification
    {
        return self::getFactory()->equalTo($value);
    }

    /**
     * Syntactic alias for equalTo().
     *
     * @param mixed $value Expected value
     * @return ISpecification
     */
    public static function equal(mixed $value): ISpecification
    {
        return self::getFactory()->equalTo($value);
    }

    /**
     * Opt-in LOOSE equality (`==`): PHP coercion applies (`5 == "5"`, `true == 1`).
     *
     * The default equalTo() is strict (`===`) and refuses incompatible candidate types with
     * IncompatibleTypeException. Use this when candidates arrive as strings from forms,
     * CSV or database drivers and the rule tolerates coercion.
     *
     * @param mixed $value Expected value
     * @return ISpecification
     */
    public static function looselyEqualTo(mixed $value): ISpecification
    {
        return self::getFactory()->looselyEqualTo($value);
    }

    /**
     * Specifies that candidate value must NOT equal the given value.
     *
     * @param mixed $value Value not allowed
     * @return ISpecification
     */
    public static function notEqual(mixed $value): ISpecification
    {
        return self::getFactory()->notEqual($value);
    }

    /**
     * Specifies that candidate value must be strictly greater than threshold.
     *
     * @param mixed $value Exclusive lower bound
     * @return ISpecification
     */
    public static function greaterThan(mixed $value): ISpecification
    {
        return self::getFactory()->greaterThan($value);
    }

    /**
     * Specifies that candidate value must be greater than or equal to threshold.
     *
     * @param mixed $value Inclusive lower bound
     * @return ISpecification
     */
    public static function greaterThanOrEqualTo(mixed $value): ISpecification
    {
        return self::getFactory()->greaterThanOrEqualTo($value);
    }

    /**
     * Specifies that candidate value must be strictly less than threshold.
     *
     * @param mixed $value Exclusive upper bound
     * @return ISpecification
     */
    public static function lessThan(mixed $value): ISpecification
    {
        return self::getFactory()->lessThan($value);
    }

    /**
     * Specifies that candidate value must be less than or equal to threshold.
     *
     * @param mixed $value Inclusive upper bound
     * @return ISpecification
     */
    public static function lessThanOrEqualTo(mixed $value): ISpecification
    {
        return self::getFactory()->lessThanOrEqualTo($value);
    }

    /**
     * Specifies that candidate value must belong to the given set.
     *
     * Accepts the values as variadic arguments or as a single array (PHP idiom):
     * `Spec::in(0, 2, 4)` and `Spec::in([0, 2, 4])` are equivalent. An empty set
     * (`in()` or `in([])`) never matches. To match against array values, pass a
     * list of arrays (`in([[1, 2], [3]])`) or several array arguments.
     *
     * Since 1.5.0 the result is one InSpecification leaf (strict typed equality, as
     * equalTo()), translated as `"col" IN (:p1, :p2)` by the SQL visitor, as
     * `TFilter('col', 'IN', [...])` by the TCriteria visitor and as `in_array(..., true)`
     * by the ALinq visitor; before, it was a chain of equalTo() OR equalTo().
     *
     * @param mixed ...$values Accepted values, or a single array holding them
     * @return ISpecification
     */
    public static function in(mixed ...$values): ISpecification
    {
        return self::getFactory()->in(...$values);
    }

    /**
     * Specifies that candidate value must NOT belong to the given set: `not(in(...))`.
     *
     * Accepts the same variadic or single-array forms as in(). `notIn()` of the empty set is
     * the tautology. Translated as `NOT ("col" IN (...))` by the SQL visitor and as
     * `TFilter('col', 'NOT IN', [...])` by the TCriteria visitor.
     *
     * @param mixed ...$values Rejected values, or a single array holding them
     * @return ISpecification
     */
    public static function notIn(mixed ...$values): ISpecification
    {
        return self::getFactory()->not(self::getFactory()->in(...$values));
    }

    // ==========================================
    // 4. Temporal Comparisons (Dates / Instants)
    // ==========================================

    /**
     * Specifies that date/value must precede the given upper bound.
     *
     * @param mixed $value Upper bound date/value
     * @return ISpecification
     */
    public static function before(mixed $value): ISpecification
    {
        return self::getFactory()->before($value);
    }

    /**
     * Fluent alias for before() mirroring Java Domain conventions.
     *
     * @param mixed $value Upper bound date/value
     * @return ISpecification
     */
    public static function isBefore(mixed $value): ISpecification
    {
        return self::getFactory()->isBefore($value);
    }

    /**
     * Specifies that date/value must succeed the given lower bound.
     *
     * @param mixed $value Lower bound date/value
     * @return ISpecification
     */
    public static function after(mixed $value): ISpecification
    {
        return self::getFactory()->after($value);
    }

    /**
     * Fluent alias for after() mirroring Java Domain conventions.
     *
     * @param mixed $value Lower bound date/value
     * @return ISpecification
     */
    public static function isAfter(mixed $value): ISpecification
    {
        return self::getFactory()->isAfter($value);
    }

    /**
     * Specifies exact chronological timestamp match.
     *
     * @param DateTimeInterface $date Target timestamp
     * @return ISpecification
     */
    public static function at(DateTimeInterface $date): ISpecification
    {
        return self::getFactory()->at($date);
    }

    /**
     * Syntactic alias for at().
     *
     * @param DateTimeInterface $date Target timestamp
     * @return ISpecification
     */
    public static function atTheSameTimeAs(DateTimeInterface $date): ISpecification
    {
        return self::getFactory()->atTheSameTimeAs($date);
    }

    /**
     * Specifies that date must be before or at the given threshold (<=).
     *
     * @param DateTimeInterface $date Inclusive upper bound
     * @return ISpecification
     */
    public static function beforeOrAt(DateTimeInterface $date): ISpecification
    {
        return self::getFactory()->beforeOrAt($date);
    }

    /**
     * Syntactic alias for beforeOrAt().
     *
     * @param DateTimeInterface $date Inclusive upper bound
     * @return ISpecification
     */
    public static function beforeOrAtTheSameTimeAs(DateTimeInterface $date): ISpecification
    {
        return self::getFactory()->beforeOrAtTheSameTimeAs($date);
    }

    /**
     * Fluent alias for beforeOrAt().
     *
     * @param DateTimeInterface $date Inclusive upper bound
     * @return ISpecification
     */
    public static function isBeforeOrAt(DateTimeInterface $date): ISpecification
    {
        return self::getFactory()->isBeforeOrAt($date);
    }

    /**
     * Long fluent alias for beforeOrAtTheSameTimeAs().
     *
     * @param DateTimeInterface $date Inclusive upper bound
     * @return ISpecification
     */
    public static function isBeforeOrAtTheSameTimeAs(DateTimeInterface $date): ISpecification
    {
        return self::getFactory()->isBeforeOrAtTheSameTimeAs($date);
    }

    /**
     * Specifies that date must be after or at the given threshold (>=).
     *
     * @param DateTimeInterface $date Inclusive lower bound
     * @return ISpecification
     */
    public static function afterOrAt(DateTimeInterface $date): ISpecification
    {
        return self::getFactory()->afterOrAt($date);
    }

    /**
     * Syntactic alias for afterOrAt().
     *
     * @param DateTimeInterface $date Inclusive lower bound
     * @return ISpecification
     */
    public static function afterOrAtTheSameTimeAs(DateTimeInterface $date): ISpecification
    {
        return self::getFactory()->afterOrAtTheSameTimeAs($date);
    }

    /**
     * Fluent alias for afterOrAt().
     *
     * @param DateTimeInterface $date Inclusive lower bound
     * @return ISpecification
     */
    public static function isAfterOrAt(DateTimeInterface $date): ISpecification
    {
        return self::getFactory()->isAfterOrAt($date);
    }

    /**
     * Long fluent alias for afterOrAtTheSameTimeAs().
     *
     * @param DateTimeInterface $date Inclusive lower bound
     * @return ISpecification
     */
    public static function isAfterOrAtTheSameTimeAs(DateTimeInterface $date): ISpecification
    {
        return self::getFactory()->isAfterOrAtTheSameTimeAs($date);
    }

    /**
     * Specifies closed temporal interval [start, end].
     *
     * @param DateTimeInterface $start Interval start timestamp
     * @param DateTimeInterface $end Interval end timestamp
     * @return ISpecification
     */
    public static function between(DateTimeInterface $start, DateTimeInterface $end): ISpecification
    {
        return self::getFactory()->between($start, $end);
    }

    // ==========================================
    // 5. Constants and Special States
    // ==========================================

    /**
     * Creates universal tautology specification always satisfied (True).
     *
     * @return ISpecification
     */
    public static function alwaysTrue(): ISpecification
    {
        return self::getFactory()->alwaysTrue();
    }

    /**
     * Creates universal contradiction specification never satisfied (False).
     *
     * @return ISpecification
     */
    public static function alwaysFalse(): ISpecification
    {
        return self::getFactory()->alwaysFalse();
    }

    /**
     * Specifies candidate must be strictly null (`=== null`).
     *
     * @return ISpecification
     */
    public static function isNull(): ISpecification
    {
        return self::getFactory()->isNull();
    }

    /**
     * Specifies candidate must NOT be null (`!== null`).
     *
     * @return ISpecification
     */
    public static function isNotNull(): ISpecification
    {
        return self::getFactory()->isNotNull();
    }

    /**
     * Specifies candidate must be strictly boolean true.
     *
     * @return ISpecification
     */
    public static function isTrue(): ISpecification
    {
        return self::getFactory()->isTrue();
    }

    /**
     * Specifies candidate must be strictly boolean false.
     *
     * @return ISpecification
     */
    public static function isFalse(): ISpecification
    {
        return self::getFactory()->isFalse();
    }

    /**
     * Specifies string must be empty or composed solely of whitespace.
     *
     * @return ISpecification
     */
    public static function isBlank(): ISpecification
    {
        return self::getFactory()->isBlank();
    }

    /**
     * Specifies candidate equals default value of its type (null, false, 0, empty).
     *
     * @return ISpecification
     */
    public static function defaultValue(): ISpecification
    {
        return self::getFactory()->defaultValue();
    }

    // ==========================================
    // 6. Strings and Collections
    // ==========================================

    /**
     * Specifies string must match regular expression pattern.
     *
     * @param string $pattern Regular expression in PCRE format (e.g. '/^[0-9]+$/')
     * @return ISpecification
     */
    public static function matches(string $pattern): ISpecification
    {
        return self::getFactory()->matches($pattern);
    }

    /**
     * Syntactic alias for matches().
     *
     * @param string $pattern PCRE pattern
     * @return ISpecification
     */
    public static function regex(string $pattern): ISpecification
    {
        return self::getFactory()->matches($pattern);
    }

    /**
     * Specifies string must match wildcard expression (* and ?).
     *
     * @param string $pattern Wildcard string
     * @return ISpecification
     */
    public static function wildcard(string $pattern): ISpecification
    {
        return self::getFactory()->matchesWildcard($pattern);
    }

    /**
     * Specifies string must match wildcard expression ignoring case.
     *
     * @param string $pattern Wildcard string
     * @return ISpecification
     */
    public static function wildcardIgnoreCase(string $pattern): ISpecification
    {
        return self::getFactory()->matchesWildcardIgnoringCase($pattern);
    }

    /**
     * Long alias for wildcardIgnoreCase().
     *
     * @param string $pattern Wildcard string
     * @return ISpecification
     */
    public static function wildcardExpressionMatcherIgnoreCase(string $pattern): ISpecification
    {
        return self::getFactory()->matchesWildcardIgnoringCase($pattern);
    }

    /**
     * Specifies string equals target value ignoring case.
     *
     * @param string $value Expected string
     * @return ISpecification
     */
    public static function equalIgnoreCase(string $value): ISpecification
    {
        return self::getFactory()->equalIgnoringCase($value);
    }

    /**
     * Specifies string contains given substring.
     *
     * @param string $substring Search substring
     * @param bool $caseSensitive Case sensitivity toggle (default: true)
     * @return ISpecification
     */
    public static function contains(string $substring, bool $caseSensitive = true): ISpecification
    {
        return self::getFactory()->contains($substring, $caseSensitive);
    }

    /**
     * Specifies string begins with prefix.
     *
     * @param string $prefix Expected prefix
     * @param bool $caseSensitive Case sensitivity toggle (default: true)
     * @return ISpecification
     */
    public static function startsWith(string $prefix, bool $caseSensitive = true): ISpecification
    {
        return self::getFactory()->startsWith($prefix, $caseSensitive);
    }

    /**
     * Specifies string ends with suffix.
     *
     * @param string $suffix Expected suffix
     * @param bool $caseSensitive Case sensitivity toggle (default: true)
     * @return ISpecification
     */
    public static function endsWith(string $suffix, bool $caseSensitive = true): ISpecification
    {
        return self::getFactory()->endsWith($suffix, $caseSensitive);
    }

    /**
     * Specifies collection, array, or string is empty (count or length is 0).
     *
     * @return ISpecification
     */
    public static function isEmpty(): ISpecification
    {
        return self::getFactory()->isEmpty();
    }

    /**
     * Specifies collection size satisfies the given size specification.
     *
     * @param ISpecification $sizeSpecification Specification applied to element count
     * @return ISpecification
     */
    public static function hasSize(ISpecification $sizeSpecification): ISpecification
    {
        return self::getFactory()->hasSize($sizeSpecification);
    }

    /**
     * Specifies string character count satisfies length specification.
     *
     * @param ISpecification $lengthSpecification Specification applied to string length
     * @return ISpecification
     */
    public static function hasLength(ISpecification $lengthSpecification): ISpecification
    {
        return self::getFactory()->hasLength($lengthSpecification);
    }

    /**
     * Specifies string matches a declared case name of a PHP 8+ UnitEnum or BackedEnum.
     *
     * @param class-string $enumClass Enum class name
     * @return ISpecification
     */
    public static function enumCase(string $enumClass): ISpecification
    {
        return self::getFactory()->enumCase($enumClass);
    }

    // ==========================================
    // 7. Dynamic Rule Engine
    // ==========================================

    /**
     * Creates and instantiates a DynamicSpecificationEngine ready for rule orchestration.
     *
     * @param \Antevemus\ASpecification\Contracts\Engine\IRuleCatalog|null $catalog Rule catalog
     * @param \Antevemus\ASpecification\Contracts\Engine\IRuleSpecificationRegistry|null $registry Handlers registry
     * @return \Antevemus\ASpecification\Engine\DynamicSpecificationEngine
     */
    public static function engine(
        ?\Antevemus\ASpecification\Contracts\Engine\IRuleCatalog $catalog = null,
        ?\Antevemus\ASpecification\Contracts\Engine\IRuleSpecificationRegistry $registry = null
    ): \Antevemus\ASpecification\Engine\DynamicSpecificationEngine {
        return new \Antevemus\ASpecification\Engine\DynamicSpecificationEngine(
            $catalog ?? new \Antevemus\ASpecification\Engine\InMemoryRuleCatalog(),
            $registry ?? new \Antevemus\ASpecification\Engine\RuleSpecificationRegistry()
        );
    }

    /**
     * Creates a new instance of RuleSpecificationRegistry for registering handlers.
     *
     * @return \Antevemus\ASpecification\Engine\RuleSpecificationRegistry
     */
    public static function ruleRegistry(): \Antevemus\ASpecification\Engine\RuleSpecificationRegistry
    {
        return new \Antevemus\ASpecification\Engine\RuleSpecificationRegistry();
    }

    // ==========================================
    // 8. SQL Parser & Query Visitor (Multi-SGBD)
    // ==========================================

    /**
     * Translates a specification into a parameterized WHERE clause (Multi-SGBD).
     *
     * @param \Antevemus\ASpecification\Contracts\ISpecification $specification Specification to translate
     * @param \Antevemus\ASpecification\Contracts\Sql\ISqlDialect|\Antevemus\ASpecification\Sql\SqlDialect|string $dialect Target dialect (pgsql, mysql, sqlsrv, oracle, firebird, etc.)
     * @param \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array<string, string>|\Closure|null $fieldMap Optional property-to-column mapping
     * @param \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array<string, string>|\Closure|null $fieldMapper Alias of $fieldMap (the name used by the README); both given must be identical
     * @return \Antevemus\ASpecification\Contracts\Sql\ISqlWhereClause
     * @throws \InvalidArgumentException When $fieldMap and $fieldMapper are both given and differ
     */
    public static function toSql(
        \Antevemus\ASpecification\Contracts\ISpecification $specification,
        \Antevemus\ASpecification\Contracts\Sql\ISqlDialect|\Antevemus\ASpecification\Sql\SqlDialect|string $dialect = 'ansi',
        \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array|\Closure|null $fieldMap = null,
        \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array|\Closure|null $fieldMapper = null
    ): \Antevemus\ASpecification\Contracts\Sql\ISqlWhereClause {
        $fieldMap = self::resolveFieldMap($fieldMap, $fieldMapper);
        return (new \Antevemus\ASpecification\Sql\SqlQueryVisitor($dialect, $fieldMap))->translate($specification);
    }

    /**
     * Resolves the property-to-column mapping from its two accepted parameter names.
     *
     * `fieldMap` is the canonical name; `fieldMapper` is the alias used by the README.
     * Either one may be given. When both are given they must be identical (same mapper
     * instance, same closure, or arrays with the same pairs), otherwise the call is ambiguous.
     *
     * @param \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array<string, string>|\Closure|null $fieldMap
     * @param \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array<string, string>|\Closure|null $fieldMapper
     * @return \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array<string, string>|\Closure|null
     * @throws \InvalidArgumentException When both are given and differ
     */
    public static function resolveFieldMap(
        \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array|\Closure|null $fieldMap,
        \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array|\Closure|null $fieldMapper
    ): \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array|\Closure|null {
        if ($fieldMap === null) {
            return $fieldMapper;
        }
        if ($fieldMapper !== null && $fieldMapper !== $fieldMap) {
            throw new \InvalidArgumentException(
                'Ambiguous field mapping: "fieldMap" and its alias "fieldMapper" were both given with different values; pass only one of them.'
            );
        }
        return $fieldMap;
    }

    /**
     * Creates an instance of SqlQueryVisitor configured for dialect and field mappings.
     *
     * @param \Antevemus\ASpecification\Contracts\Sql\ISqlDialect|\Antevemus\ASpecification\Sql\SqlDialect|string $dialect Target dialect
     * @param \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array<string, string>|\Closure|null $fieldMap Field mapper
     * @return \Antevemus\ASpecification\Sql\SqlQueryVisitor
     */
    public static function sqlVisitor(
        \Antevemus\ASpecification\Contracts\Sql\ISqlDialect|\Antevemus\ASpecification\Sql\SqlDialect|string $dialect = 'ansi',
        \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array|\Closure|null $fieldMap = null
    ): \Antevemus\ASpecification\Sql\SqlQueryVisitor {
        return new \Antevemus\ASpecification\Sql\SqlQueryVisitor($dialect, $fieldMap);
    }

    // ==========================================
    // 9. TCriteria Builder (Adianti Database Bridge)
    // ==========================================

    /**
     * Translates a specification into an Adianti Framework TCriteria object.
     *
     * @param \Antevemus\ASpecification\Contracts\ISpecification $specification Specification to translate
     * @param \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array<string, string>|\Closure|null $fieldMap Optional property-to-column mapping
     * @param array<string, mixed> $properties Criteria options ('order', 'limit', 'offset', 'direction', 'group')
     * @param \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array<string, string>|\Closure|null $fieldMapper Alias of $fieldMap (the name used by the README); both given must be identical
     * @return mixed TCriteria instance
     * @throws \InvalidArgumentException When $fieldMap and $fieldMapper are both given and differ
     */
    public static function toCriteria(
        \Antevemus\ASpecification\Contracts\ISpecification $specification,
        \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array|\Closure|null $fieldMap = null,
        array $properties = [],
        \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array|\Closure|null $fieldMapper = null
    ): mixed {
        $fieldMap = self::resolveFieldMap($fieldMap, $fieldMapper);
        return \Antevemus\ASpecification\Criteria\TCriteriaBuilder::fromSpecification($specification, $fieldMap, $properties);
    }

    /**
     * Creates an instance of TCriteriaBuilder for fluent compilation.
     *
     * @param \Antevemus\ASpecification\Contracts\ISpecification $specification Specification to compile
     * @param \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array<string, string>|\Closure|null $fieldMap Field mapper
     * @param \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array<string, string>|\Closure|null $fieldMapper Alias of $fieldMap (the name used by the README); both given must be identical
     * @return \Antevemus\ASpecification\Criteria\TCriteriaBuilder
     * @throws \InvalidArgumentException When $fieldMap and $fieldMapper are both given and differ
     */
    public static function criteriaBuilder(
        \Antevemus\ASpecification\Contracts\ISpecification $specification,
        \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array|\Closure|null $fieldMap = null,
        \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array|\Closure|null $fieldMapper = null
    ): \Antevemus\ASpecification\Criteria\TCriteriaBuilder {
        $fieldMap = self::resolveFieldMap($fieldMap, $fieldMapper);
        return new \Antevemus\ASpecification\Criteria\TCriteriaBuilder($specification, $fieldMap);
    }

    // ==========================================
    // 10. Declarative Attributes Validation (PHP 8.4)
    // ==========================================

    /**
     * Validate an object annotated with #[AssertSpec] and #[ValidateRule] attributes.
     *
     * @param object $target The target object (DTO, Entity, Value Object, Form Request)
     * @return \Antevemus\ASpecification\Results\SpecificationResult
     */
    public static function validateAttributes(object $target): \Antevemus\ASpecification\Results\SpecificationResult
    {
        return \Antevemus\ASpecification\Attributes\AttributeValidator::validate($target);
    }

    /**
     * Assert that an object satisfies all #[AssertSpec] and #[ValidateRule] attributes,
     * throwing an AttributeValidationException on any failure.
     *
     * @param object $target The target object to validate
     * @throws \Antevemus\ASpecification\Attributes\Exceptions\AttributeValidationException
     */
    public static function assertAttributes(object $target): void
    {
        \Antevemus\ASpecification\Attributes\AttributeValidator::assert($target);
    }

    // ==========================================
    // 11. ALinq & Lazy Streaming Integration
    // ==========================================

    /**
     * Converts an iterable or repository (any IRepository) into a fluent ALinqCollection.
     *
     * @param iterable|\Antevemus\ASpecification\Contracts\Repositories\IRepository $items
     * @return \Antevemus\ALinq\Interfaces\IALinqCollection
     */
    public static function linq(iterable|\Antevemus\ASpecification\Contracts\Repositories\IRepository $items): \Antevemus\ALinq\Interfaces\IALinqCollection
    {
        return \Antevemus\ASpecification\Linq\ALinqBridge::toCollection($items);
    }

    /**
     * Filters an iterable or repository with a specification, returning an ALinqCollection.
     *
     * @param iterable|\Antevemus\ASpecification\Contracts\Repositories\IRepository $items
     * @param \Antevemus\ASpecification\Contracts\ISpecification $specification
     * @return \Antevemus\ALinq\Interfaces\IALinqCollection Filtered collection
     */
    public static function filterLinq(
        iterable|\Antevemus\ASpecification\Contracts\Repositories\IRepository $items,
        \Antevemus\ASpecification\Contracts\ISpecification $specification
    ): \Antevemus\ALinq\Interfaces\IALinqCollection {
        return \Antevemus\ASpecification\Linq\ALinqBridge::filter($items, $specification);
    }

    /**
     * Converts an iterable, generator factory, or repository into a lazy ALinqLazyCollection (a repository is read
     * through iterate(), a new generator per traversal).
     *
     * @param iterable|callable|\Antevemus\ASpecification\Contracts\Repositories\IRepository $source
     * @return \Antevemus\ALinq\Interfaces\IALinqLazyCollection
     */
    public static function linqLazy(iterable|callable|\Antevemus\ASpecification\Contracts\Repositories\IRepository $source): \Antevemus\ALinq\Interfaces\IALinqLazyCollection
    {
        return \Antevemus\ASpecification\Linq\ALinqBridge::toLazyCollection($source);
    }

    /**
     * Filters a stream or generator with constant O(1) RAM using a specification, returning an ALinqLazyCollection.
     *
     * @param iterable|callable|\Antevemus\ASpecification\Contracts\Repositories\IRepository $source
     * @param \Antevemus\ASpecification\Contracts\ISpecification $specification
     * @return \Antevemus\ALinq\Interfaces\IALinqLazyCollection Filtered lazy collection
     */
    public static function filterLazy(
        iterable|callable|\Antevemus\ASpecification\Contracts\Repositories\IRepository $source,
        \Antevemus\ASpecification\Contracts\ISpecification $specification
    ): \Antevemus\ALinq\Interfaces\IALinqLazyCollection {
        return \Antevemus\ASpecification\Linq\ALinqBridge::filterLazy($source, $specification);
    }

    // ==========================================
    // 12. Dynamic Factory Fallback
    // ==========================================

    /**
     * Forwards undeclared static calls to the underlying SpecificationFactory instance.
     *
     * @param string $name Method name
     * @param array<mixed> $arguments Method arguments
     * @return mixed
     */
    public static function __callStatic(string $name, array $arguments): mixed
    {
        return self::getFactory()->$name(...$arguments);
    }
}
