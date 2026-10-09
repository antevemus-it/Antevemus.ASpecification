<?php

declare(strict_types=1);

/**
 * functions.php - Global DSL functions for Antevemus.ASpecification
 *
 * Provides pure helper functions in namespace Antevemus\ASpecification\DSL
 * for natural, spoken-like specification and business rule authoring
 * via the "use function" language construct.
 *
 * Features:
 * - Fluent candidate root specification initiation (specify)
 * - Logical predicate composition (allOf, anyOf, not, nor, noneOf)
 * - Identity and relational comparison (is, equalTo, equal, notEqual, greaterThan, lessThan, in, notIn)
 * - Temporal and date validation (before, isBefore, after, isAfter, at, between)
 * - String evaluation and pattern matching (matches, contains, startsWith, endsWith)
 * - Declarative method call on the candidate (calling, 1.6.0)
 *
 * @version    1.6.0
 * @package    Antevemus\ASpecification
 * @subpackage DSL
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */

namespace Antevemus\ASpecification\DSL;

use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Specifications\PropertySpecification;
use Antevemus\ASpecification\Spec;
use DateTimeInterface;

/**
 * Initiates fluent composite specification construction for a target class or candidate type.
 *
 * Enables natural chaining of `where()`, `and()`, `or()`, and `not()` clauses.
 *
 * @param class-string|string $type Target class, interface, or primitive type name
 * @return ICompositeSpecification Initialized fluent composite specification
 */
function specify(string $type): ICompositeSpecification
{
    return Spec::specify($type);
}

/**
 * Creates a specification bound to an object property or array key.
 *
 * Supports public properties, getters (getProp, prop), boolean methods (isProp, hasProp),
 * ArrayAccess, and dot-notation paths ('user.address.city').
 *
 * Usage example:
 * <code>
 * $isEligible = prop('age', greaterThanOrEqualTo(18))
 *     ->and(prop('status', equal('ACTIVE')))
 *     ->and(prop('address.city', equal('New York')));
 * </code>
 *
 * @param string $propertyName Property name or dot-separated path
 * @param ISpecification $specification Rule to evaluate against the property value
 * @param ISpecification|null $baseSpecification Base type specification (default: AlwaysTrue)
 * @return PropertySpecification
 */
function prop(
    string $propertyName,
    ISpecification $specification,
    ?ISpecification $baseSpecification = null
): PropertySpecification {
    return Spec::property($propertyName, $specification, $baseSpecification);
}

/**
 * Syntactic alias for prop().
 *
 * @param string $propertyName Property name or dot-separated path
 * @param ISpecification $specification Rule to evaluate against the property value
 * @param ISpecification|null $baseSpecification Base type specification (default: AlwaysTrue)
 * @return PropertySpecification
 */
function property(
    string $propertyName,
    ISpecification $specification,
    ?ISpecification $baseSpecification = null
): PropertySpecification {
    return Spec::property($propertyName, $specification, $baseSpecification);
}

/**
 * Creates a declarative method-call specification: calls the public method of the candidate with the
 * given arguments and applies the result specification to the returned value (1.6.0, forward 018).
 *
 * <code>
 * calling('isEligibleFor', [new DateTimeImmutable('2026-12-01')], isTrue());
 * </code>
 *
 * @param string $methodName Public method of the candidate to call
 * @param list<mixed> $arguments Positional, declarative arguments
 * @param ISpecification $resultSpecification Specification applied to the returned value
 * @return ISpecification
 */
function calling(string $methodName, array $arguments, ISpecification $resultSpecification): ISpecification
{
    return Spec::calling($methodName, $arguments, $resultSpecification);
}

/**
 * Creates a conjunction (AND) specification requiring ALL provided specifications to be satisfied.
 *
 * Returns AlwaysTrueSpecification if no arguments are provided.
 *
 * @param ISpecification ...$specifications Variable list of specifications to combine with AND
 * @return ISpecification Composite conjunction specification
 */
function allOf(ISpecification ...$specifications): ISpecification
{
    return Spec::allOf(...$specifications);
}

/**
 * Creates a disjunction (OR) specification requiring AT LEAST ONE specification to be satisfied.
 *
 * Returns AlwaysFalseSpecification if no arguments are provided.
 *
 * @param ISpecification ...$specifications Variable list of specifications to combine with OR
 * @return ISpecification Composite disjunction specification
 */
function anyOf(ISpecification ...$specifications): ISpecification
{
    return Spec::anyOf(...$specifications);
}

/**
 * Creates a logical negation (NOT) specification that inverts the outcome of the given specification.
 *
 * @param ISpecification $specification Specification whose condition is to be inverted
 * @return ISpecification Inverted specification
 */
function not(ISpecification $specification): ISpecification
{
    return Spec::not($specification);
}

/**
 * Creates a joint denial (logical NOR) specification satisfied only when NONE of the given specifications is met.
 *
 * Equivalent to NOT (a OR b OR ...). Returns AlwaysTrueSpecification if no arguments are provided.
 *
 * @param ISpecification ...$specifications Specifications that must all be unsatisfied
 * @return ISpecification NOR specification
 */
function nor(ISpecification ...$specifications): ISpecification
{
    return Spec::nor(...$specifications);
}

/**
 * Alias for nor().
 *
 * @param ISpecification ...$specifications Specifications that must all be unsatisfied
 * @return ISpecification NOR specification
 */
function noneOf(ISpecification ...$specifications): ISpecification
{
    return Spec::noneOf(...$specifications);
}

/**
 * Creates an identity/strict equality specification for the given value.
 *
 * @param mixed $value Expected value
 * @return ISpecification Leaf equality specification
 */
function is(mixed $value): ISpecification
{
    return Spec::is($value);
}

/**
 * Creates a strict equality (`===`) leaf specification.
 *
 * @param mixed $value Expected value
 * @return ISpecification Leaf equality specification
 */
function equalTo(mixed $value): ISpecification
{
    return Spec::equalTo($value);
}

/**
 * Creates an opt-in loose equality (`==`) leaf specification (PHP coercion applies).
 *
 * @param mixed $value Expected value
 * @return ISpecification Loose equality specification
 */
function looselyEqualTo(mixed $value): ISpecification
{
    return Spec::looselyEqualTo($value);
}

/**
 * Syntactic alias for equalTo().
 *
 * @param mixed $value Expected value
 * @return ISpecification Leaf equality specification
 */
function equal(mixed $value): ISpecification
{
    return Spec::equal($value);
}

/**
 * Creates a strict inequality (`!==`) leaf specification.
 *
 * @param mixed $value Value candidate must NOT possess
 * @return ISpecification Leaf inequality specification
 */
function notEqual(mixed $value): ISpecification
{
    return Spec::notEqual($value);
}

/**
 * Creates a greater-than (`>`) comparison specification.
 *
 * @param mixed $value Exclusive lower bound
 * @return ISpecification Greater-than comparison specification
 */
function greaterThan(mixed $value): ISpecification
{
    return Spec::greaterThan($value);
}

/**
 * Creates a greater-than-or-equal-to (`>=`) comparison specification.
 *
 * @param mixed $value Inclusive lower bound
 * @return ISpecification Greater-than-or-equal comparison specification
 */
function greaterThanOrEqualTo(mixed $value): ISpecification
{
    return Spec::greaterThanOrEqualTo($value);
}

/**
 * Creates a less-than (`<`) comparison specification.
 *
 * @param mixed $value Exclusive upper bound
 * @return ISpecification Less-than comparison specification
 */
function lessThan(mixed $value): ISpecification
{
    return Spec::lessThan($value);
}

/**
 * Creates a less-than-or-equal-to (`<=`) comparison specification.
 *
 * @param mixed $value Inclusive upper bound
 * @return ISpecification Less-than-or-equal comparison specification
 */
function lessThanOrEqualTo(mixed $value): ISpecification
{
    return Spec::lessThanOrEqualTo($value);
}

/**
 * Creates a set membership specification (one InSpecification leaf, translated as IN).
 *
 * The values may be passed variadically or as a single array: `in(0, 2, 4)` and
 * `in([0, 2, 4])` are equivalent. An empty set never matches.
 *
 * @param mixed ...$values Set of accepted values, or a single array holding them
 * @return ISpecification Set membership specification
 */
function in(mixed ...$values): ISpecification
{
    return Spec::in(...$values);
}

/**
 * Creates the negated set membership specification: not(in(...)).
 *
 * @param mixed ...$values Set of rejected values, or a single array holding them
 * @return ISpecification Negated set membership specification
 */
function notIn(mixed ...$values): ISpecification
{
    return Spec::notIn(...$values);
}

/**
 * Creates a temporal/ordering specification checking if a value precedes the given threshold.
 *
 * @param mixed $value Reference upper bound or date
 * @return ISpecification Precedence specification
 */
function before(mixed $value): ISpecification
{
    return Spec::before($value);
}

/**
 * Syntactic alias for before().
 *
 * @param mixed $value Reference upper bound or date
 * @return ISpecification Precedence specification
 */
function isBefore(mixed $value): ISpecification
{
    return Spec::isBefore($value);
}

/**
 * Creates a temporal/ordering specification checking if a value succeeds the given threshold.
 *
 * @param mixed $value Reference lower bound or date
 * @return ISpecification Succession specification
 */
function after(mixed $value): ISpecification
{
    return Spec::after($value);
}

/**
 * Syntactic alias for after().
 *
 * @param mixed $value Reference lower bound or date
 * @return ISpecification Succession specification
 */
function isAfter(mixed $value): ISpecification
{
    return Spec::isAfter($value);
}

/**
 * Creates a temporal specification requiring chronological exactness with the given instant.
 *
 * @param DateTimeInterface $date Exact reference timestamp
 * @return ISpecification Temporal equality specification
 */
function at(DateTimeInterface $date): ISpecification
{
    return Spec::at($date);
}

/**
 * Syntactic alias for at().
 *
 * @param DateTimeInterface $date Exact reference timestamp
 * @return ISpecification Temporal equality specification
 */
function atTheSameTimeAs(DateTimeInterface $date): ISpecification
{
    return Spec::atTheSameTimeAs($date);
}

/**
 * Creates a temporal specification evaluating whether an instant is before or at the given threshold (<=).
 *
 * @param DateTimeInterface $date Inclusive upper bound timestamp
 * @return ISpecification Temporal less-than-or-equal specification
 */
function beforeOrAt(DateTimeInterface $date): ISpecification
{
    return Spec::beforeOrAt($date);
}

/**
 * Syntactic alias for beforeOrAt().
 *
 * @param DateTimeInterface $date Inclusive upper bound timestamp
 * @return ISpecification Temporal less-than-or-equal specification
 */
function beforeOrAtTheSameTimeAs(DateTimeInterface $date): ISpecification
{
    return Spec::beforeOrAtTheSameTimeAs($date);
}

/**
 * Syntactic fluent alias for beforeOrAt().
 *
 * @param DateTimeInterface $date Inclusive upper bound timestamp
 * @return ISpecification Temporal less-than-or-equal specification
 */
function isBeforeOrAt(DateTimeInterface $date): ISpecification
{
    return Spec::isBeforeOrAt($date);
}

/**
 * Syntactic fluent long alias for beforeOrAt().
 *
 * @param DateTimeInterface $date Inclusive upper bound timestamp
 * @return ISpecification Temporal less-than-or-equal specification
 */
function isBeforeOrAtTheSameTimeAs(DateTimeInterface $date): ISpecification
{
    return Spec::isBeforeOrAtTheSameTimeAs($date);
}

/**
 * Creates a temporal specification evaluating whether an instant is after or at the given threshold (>=).
 *
 * @param DateTimeInterface $date Inclusive lower bound timestamp
 * @return ISpecification Temporal greater-than-or-equal specification
 */
function afterOrAt(DateTimeInterface $date): ISpecification
{
    return Spec::afterOrAt($date);
}

/**
 * Syntactic alias for afterOrAt().
 *
 * @param DateTimeInterface $date Inclusive lower bound timestamp
 * @return ISpecification Temporal greater-than-or-equal specification
 */
function afterOrAtTheSameTimeAs(DateTimeInterface $date): ISpecification
{
    return Spec::afterOrAtTheSameTimeAs($date);
}

/**
 * Syntactic fluent alias for afterOrAt().
 *
 * @param DateTimeInterface $date Inclusive lower bound timestamp
 * @return ISpecification Temporal greater-than-or-equal specification
 */
function isAfterOrAt(DateTimeInterface $date): ISpecification
{
    return Spec::isAfterOrAt($date);
}

/**
 * Syntactic fluent long alias for afterOrAt().
 *
 * @param DateTimeInterface $date Inclusive lower bound timestamp
 * @return ISpecification Temporal greater-than-or-equal specification
 */
function isAfterOrAtTheSameTimeAs(DateTimeInterface $date): ISpecification
{
    return Spec::isAfterOrAtTheSameTimeAs($date);
}

/**
 * Creates a temporal interval specification checking if a date falls between start and end (inclusive).
 *
 * @param DateTimeInterface $start Interval start timestamp
 * @param DateTimeInterface $end Interval end timestamp
 * @return ISpecification Closed interval membership specification
 */
function between(DateTimeInterface $start, DateTimeInterface $end): ISpecification
{
    return Spec::between($start, $end);
}

/**
 * Returns a universal tautological specification satisfied by any candidate.
 *
 * @return ISpecification Instance of AlwaysTrueSpecification
 */
function alwaysTrue(): ISpecification
{
    return Spec::alwaysTrue();
}

/**
 * Returns a universal contradictory specification never satisfied by any candidate.
 *
 * @return ISpecification Instance of AlwaysFalseSpecification
 */
function alwaysFalse(): ISpecification
{
    return Spec::alwaysFalse();
}

/**
 * Creates a specification verifying that candidate or property is strictly null (`=== null`).
 *
 * @return ISpecification Nullity specification
 */
function isNull(): ISpecification
{
    return Spec::isNull();
}

/**
 * Creates a specification verifying that candidate or property is non-null (`!== null`).
 *
 * @return ISpecification Non-nullity specification
 */
function isNotNull(): ISpecification
{
    return Spec::isNotNull();
}

/**
 * Creates a specification verifying that candidate or property is strictly true (`=== true`).
 *
 * @return ISpecification Boolean truth specification
 */
function isTrue(): ISpecification
{
    return Spec::isTrue();
}

/**
 * Creates a specification verifying that candidate or property is strictly false (`=== false`).
 *
 * @return ISpecification Boolean falsehood specification
 */
function isFalse(): ISpecification
{
    return Spec::isFalse();
}

/**
 * Creates a specification verifying that a string is empty or contains only whitespace.
 *
 * @return ISpecification Blank string specification
 */
function isBlank(): ISpecification
{
    return Spec::isBlank();
}

/**
 * Creates a specification validating a string against a PCRE regular expression (`preg_match`).
 *
 * @param string $pattern PCRE regex pattern (e.g., '/^[0-9]{3}\.[0-9]{3}\.[0-9]{3}-[0-9]{2}$/')
 * @return ISpecification Regex matching specification
 */
function matches(string $pattern): ISpecification
{
    return Spec::matches($pattern);
}

/**
 * Creates a specification checking if a string contains the given substring.
 *
 * @param string $substring Search substring
 * @param bool $caseSensitive Case sensitivity toggle (default: true)
 * @return ISpecification Substring containment specification
 */
function contains(string $substring, bool $caseSensitive = true): ISpecification
{
    return Spec::contains($substring, $caseSensitive);
}

/**
 * Creates a specification checking if a string starts with the given prefix.
 *
 * @param string $prefix Expected prefix
 * @param bool $caseSensitive Case sensitivity toggle (default: true)
 * @return ISpecification String prefix specification
 */
function startsWith(string $prefix, bool $caseSensitive = true): ISpecification
{
    return Spec::startsWith($prefix, $caseSensitive);
}

/**
 * Creates a specification checking if a string ends with the given suffix.
 *
 * @param string $suffix Expected suffix
 * @param bool $caseSensitive Case sensitivity toggle (default: true)
 * @return ISpecification String suffix specification
 */
function endsWith(string $suffix, bool $caseSensitive = true): ISpecification
{
    return Spec::endsWith($suffix, $caseSensitive);
}

/**
 * Creates a specification checking if a collection, array, or string is empty (count or length is 0).
 *
 * @return ISpecification Emptiness specification
 */
function isEmpty(): ISpecification
{
    return Spec::isEmpty();
}

/**
 * Creates a collection size specification checking count against a size rule.
 *
 * @param ISpecification $sizeSpecification Specification evaluated on integer count (e.g. equalTo(5))
 * @return ISpecification Collection size specification
 */
function hasSize(ISpecification $sizeSpecification): ISpecification
{
    return Spec::hasSize($sizeSpecification);
}

/**
 * Creates a string length specification checking character count against a length rule.
 *
 * @param ISpecification $lengthSpecification Specification evaluated on string length (e.g. greaterThan(10))
 * @return ISpecification String length specification
 */
function hasLength(ISpecification $lengthSpecification): ISpecification
{
    return Spec::hasLength($lengthSpecification);
}

// ==========================================
// Domian SpecificationFactory aliases (1.4.4)
// ==========================================

/**
 * Domian `allEntities()`: every entity (AllEntitiesSpecification).
 *
 * @return ISpecification
 */
function allEntities(): ISpecification
{
    return Spec::allEntities();
}

/**
 * Domian alias for allEntities().
 *
 * @return ISpecification
 */
function entities(): ISpecification
{
    return Spec::entities();
}

/**
 * Domian alias for allEntities().
 *
 * @return ISpecification
 */
function entity(): ISpecification
{
    return Spec::entity();
}

/**
 * Domian `allObjects()`: every non-null candidate (NotNull on top of everything).
 *
 * @return ISpecification
 */
function allObjects(): ISpecification
{
    return Spec::allObjects();
}

/**
 * Domian alias for alwaysTrue().
 *
 * @return ISpecification
 */
function createTautology(): ISpecification
{
    return Spec::alwaysTrue();
}

/**
 * Domian alias for alwaysFalse().
 *
 * @return ISpecification
 */
function createContradiction(): ISpecification
{
    return Spec::alwaysFalse();
}

/**
 * Domian alias for greaterThan().
 *
 * @param mixed $value Exclusive lower bound
 * @return ISpecification
 */
function isGreaterThan(mixed $value): ISpecification
{
    return Spec::greaterThan($value);
}

/**
 * Domian alias for greaterThanOrEqualTo().
 *
 * @param mixed $value Inclusive lower bound
 * @return ISpecification
 */
function isGreaterThanOrEqualTo(mixed $value): ISpecification
{
    return Spec::greaterThanOrEqualTo($value);
}

/**
 * Domian alias for lessThan().
 *
 * @param mixed $value Exclusive upper bound
 * @return ISpecification
 */
function isLessThan(mixed $value): ISpecification
{
    return Spec::lessThan($value);
}

/**
 * Domian alias for lessThanOrEqualTo().
 *
 * @param mixed $value Inclusive upper bound
 * @return ISpecification
 */
function isLessThanOrEqualTo(mixed $value): ISpecification
{
    return Spec::lessThanOrEqualTo($value);
}

/**
 * Domian alias for equalTo().
 *
 * @param mixed $value Expected value
 * @return ISpecification
 */
function objectEqualTo(mixed $value): ISpecification
{
    return Spec::equalTo($value);
}

/**
 * Domian alias for equalTo().
 *
 * @param mixed $value Expected value
 * @return ISpecification
 */
function anObjectEqualTo(mixed $value): ISpecification
{
    return Spec::equalTo($value);
}

/**
 * Domian `blankString()`: null or a blank string (null IS blank, unlike isBlank()).
 *
 * @return ISpecification
 */
function blankString(): ISpecification
{
    return Spec::blankString();
}

/**
 * Domian alias for blankString().
 *
 * @return ISpecification
 */
function isBlankString(): ISpecification
{
    return Spec::blankString();
}

/**
 * Domian `defaultNumber()`: null or zero.
 *
 * @return ISpecification
 */
function defaultNumber(): ISpecification
{
    return Spec::defaultNumber();
}

/**
 * Domian alias for defaultNumber().
 *
 * @return ISpecification
 */
function isDefaultNumber(): ISpecification
{
    return Spec::defaultNumber();
}

/**
 * Domian `defaultValueOfType(T)`: null or the default value of the given type only.
 *
 * @param string $type Restricting type (string, number, bool, array)
 * @return ISpecification
 */
function defaultValueOfType(string $type): ISpecification
{
    return Spec::defaultValueOfType($type);
}

/**
 * Domian alias for enumCase().
 *
 * @param class-string $enumClass Enum class name
 * @return ISpecification
 */
function isEnum(string $enumClass): ISpecification
{
    return Spec::enumCase($enumClass);
}

/**
 * Domian alias for matchesWildcard() / wildcard().
 *
 * @param string $wildcardExpression Wildcard expression (* and ?)
 * @return ISpecification
 */
function matchesWildcardExpression(string $wildcardExpression): ISpecification
{
    return Spec::wildcard($wildcardExpression);
}

/**
 * Domian alias for matchesWildcardIgnoringCase() / wildcardIgnoreCase().
 *
 * @param string $wildcardExpression Wildcard expression (* and ?)
 * @return ISpecification
 */
function matchesWildcardExpressionIgnoringCase(string $wildcardExpression): ISpecification
{
    return Spec::wildcardIgnoreCase($wildcardExpression);
}
