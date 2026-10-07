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
 * - Dynamic redirection via __callStatic to underlying SpecificationFactory
 *
 * @version    1.1.0
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
     * @param mixed ...$values Accepted values
     * @return ISpecification
     */
    public static function in(mixed ...$values): ISpecification
    {
        return self::getFactory()->in(...$values);
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
     * @return \Antevemus\ASpecification\Contracts\Sql\ISqlWhereClause
     */
    public static function toSql(
        \Antevemus\ASpecification\Contracts\ISpecification $specification,
        \Antevemus\ASpecification\Contracts\Sql\ISqlDialect|\Antevemus\ASpecification\Sql\SqlDialect|string $dialect = 'ansi',
        \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array|\Closure|null $fieldMap = null
    ): \Antevemus\ASpecification\Contracts\Sql\ISqlWhereClause {
        return (new \Antevemus\ASpecification\Sql\SqlQueryVisitor($dialect, $fieldMap))->translate($specification);
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
     * @return mixed TCriteria instance
     */
    public static function toCriteria(
        \Antevemus\ASpecification\Contracts\ISpecification $specification,
        \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array|\Closure|null $fieldMap = null,
        array $properties = []
    ): mixed {
        return \Antevemus\ASpecification\Criteria\TCriteriaBuilder::fromSpecification($specification, $fieldMap, $properties);
    }

    /**
     * Creates an instance of TCriteriaBuilder for fluent compilation.
     *
     * @param \Antevemus\ASpecification\Contracts\ISpecification $specification Specification to compile
     * @param \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array<string, string>|\Closure|null $fieldMap Field mapper
     * @return \Antevemus\ASpecification\Criteria\TCriteriaBuilder
     */
    public static function criteriaBuilder(
        \Antevemus\ASpecification\Contracts\ISpecification $specification,
        \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array|\Closure|null $fieldMap = null
    ): \Antevemus\ASpecification\Criteria\TCriteriaBuilder {
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
     * Converts an iterable or InMemoryRepository into a fluent ALinqCollection.
     *
     * @param iterable|\Antevemus\ASpecification\Repositories\InMemoryRepository $items
     * @return object Instance of \Antevemus\ALinq\ALinqCollection
     */
    public static function linq(iterable|\Antevemus\ASpecification\Repositories\InMemoryRepository $items): object
    {
        return \Antevemus\ASpecification\Linq\ALinqBridge::toCollection($items);
    }

    /**
     * Filters an iterable or InMemoryRepository with a specification, returning an ALinqCollection.
     *
     * @param iterable|\Antevemus\ASpecification\Repositories\InMemoryRepository $items
     * @param \Antevemus\ASpecification\Contracts\ISpecification $specification
     * @return object Filtered \Antevemus\ALinq\ALinqCollection
     */
    public static function filterLinq(
        iterable|\Antevemus\ASpecification\Repositories\InMemoryRepository $items,
        \Antevemus\ASpecification\Contracts\ISpecification $specification
    ): object {
        return \Antevemus\ASpecification\Linq\ALinqBridge::filter($items, $specification);
    }

    /**
     * Converts an iterable, generator, or InMemoryRepository into a streaming ALinqLazyCollection with O(1) RAM.
     *
     * @param iterable|callable|\Antevemus\ASpecification\Repositories\InMemoryRepository $source
     * @return object Instance of \Antevemus\ALinq\ALinqLazyCollection
     */
    public static function linqLazy(iterable|callable|\Antevemus\ASpecification\Repositories\InMemoryRepository $source): object
    {
        return \Antevemus\ASpecification\Linq\ALinqBridge::toLazyCollection($source);
    }

    /**
     * Filters a stream or generator with constant O(1) RAM using a specification, returning an ALinqLazyCollection.
     *
     * @param iterable|callable|\Antevemus\ASpecification\Repositories\InMemoryRepository $source
     * @param \Antevemus\ASpecification\Contracts\ISpecification $specification
     * @return object Filtered \Antevemus\ALinq\ALinqLazyCollection
     */
    public static function filterLazy(
        iterable|callable|\Antevemus\ASpecification\Repositories\InMemoryRepository $source,
        \Antevemus\ASpecification\Contracts\ISpecification $specification
    ): object {
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
