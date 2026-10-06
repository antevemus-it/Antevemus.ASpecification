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
 * - Logical predicate composition (allOf, anyOf, not)
 * - Identity and relational comparison (is, equalTo, equal, notEqual, greaterThan, lessThan, in)
 * - Temporal and date validation (before, isBefore, after, isAfter, at, between)
 * - String evaluation and pattern matching (matches, contains, startsWith, endsWith)
 *
 * @version    1.1.0
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
 * Creates a set membership specification (equivalent to IN / disjunction of equalities).
 *
 * @param mixed ...$values Set of accepted values
 * @return ISpecification Disjunctive set membership specification
 */
function in(mixed ...$values): ISpecification
{
    return Spec::in(...$values);
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
