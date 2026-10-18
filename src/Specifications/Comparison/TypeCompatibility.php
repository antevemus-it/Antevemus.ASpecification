<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Comparison;

use Antevemus\ASpecification\Specifications\Exceptions\IncompatibleTypeException;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * TypeCompatibility - Type guards for comparison and equality leaf specifications.
 *
 * Centralizes the rule "never compare mixed types silently". Two families of checks:
 *
 * - Ordering (`<`, `>`): a numeric bound (int|float) requires a numeric candidate (int|float);
 *   a string bound requires a string candidate. Numeric strings are NOT coerced.
 * - Strict equality (`===`): both operands must share the same scalar type (int, float, bool,
 *   string), or both be arrays, or both be objects. int and float are distinct types.
 *
 * The value test of the equality leaves (sameValue(), 2.1.0): date-times by instant, anything else by
 * strict identity (`===`).
 *
 * Null candidates are never a type error: each specification keeps its own null semantics.
 * Subsumption algebra uses the boolean predicates (no exception) because it compares two
 * specifications, not a candidate.
 *
 * @version    2.2.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Comparison
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class TypeCompatibility
{
    private function __construct()
    {
    }

    /**
     * True when $a and $b can be ordered against each other without coercion.
     */
    public static function isOrderable(mixed $a, mixed $b): bool
    {
        if (self::isUnordered($a) || self::isUnordered($b)) {
            return false;
        }
        if (self::isNumber($a) && self::isNumber($b)) {
            return true;
        }
        if (is_string($a) && is_string($b)) {
            return true;
        }
        if ($a instanceof DateTimeInterface && $b instanceof DateTimeInterface) {
            return true;
        }
        return false;
    }

    /**
     * True when $a and $b can be tested for strict equality meaningfully (same type family).
     */
    public static function isEquatable(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return true;
        }
        if (is_object($a) && is_object($b)) {
            return true;
        }
        if (is_array($a) && is_array($b)) {
            return true;
        }
        return is_scalar($a) && is_scalar($b) && gettype($a) === gettype($b);
    }

    /**
     * True when equalTo()/in() accept $candidate for $value and notEqual() refuses it (2.1.0): two
     * date-times denote the same value when they denote the same instant, whatever their class or time
     * zone (as the relational leaves, at() and SQL compare them); anything else is compared strictly
     * (`===`: scalars by type and value, arrays by type, order and value, other objects by identity).
     * Up to 2.0.0 date-times were compared by identity here (BUG-20261013-LIZI).
     */
    public static function sameValue(mixed $candidate, mixed $value): bool
    {
        if ($candidate instanceof DateTimeInterface && $value instanceof DateTimeInterface) {
            return $candidate == $value;
        }

        return $candidate === $value;
    }

    /**
     * Throws when the candidate cannot be ordered against the bound value. Null passes through.
     *
     * @throws IncompatibleTypeException
     */
    public static function assertOrderable(mixed $candidate, mixed $value, string $ruleName): void
    {
        if ($candidate === null) {
            return;
        }
        if (self::isUnordered($candidate)) {
            throw new IncompatibleTypeException($ruleName, $candidate, $value, sprintf(
                '%s cannot order the candidate NAN: it is unordered (NAN < v, NAN > v and NAN == v are all false), so the leaf and its inversion would disagree on it; refuse it before evaluating, or compare it with equalTo()/notEqual().',
                $ruleName
            ));
        }
        if (!self::isOrderable($candidate, $value)) {
            throw new IncompatibleTypeException($ruleName, $candidate, $value);
        }
    }

    /**
     * The bound of an ordered leaf (`<`, `<=`, `>`, `>=`) must order something: NAN is refused at
     * construction (2.2.0, BUG-20261013-XEIB), because `x < NAN`, `x > NAN` and `x == NAN` are all
     * false for every x, so the leaf would accept no candidate and its inversion every one.
     *
     * @throws InvalidArgumentException
     */
    public static function assertOrderableBound(mixed $value, string $ruleName): void
    {
        if (self::isUnordered($value)) {
            throw new InvalidArgumentException(sprintf(
                '%s cannot take NAN as its bound: NAN orders nothing (x < NAN, x > NAN and x == NAN are all false for every x), so the leaf would accept no candidate and its inversion would accept every one.',
                $ruleName
            ));
        }
    }

    /**
     * True for the one value PHP cannot order: a float NAN (2.2.0). It is neither a number nor a
     * text for the purposes of isOrderable(); the equality rules still answer it (NAN equals nothing).
     */
    public static function isUnordered(mixed $v): bool
    {
        return is_float($v) && is_nan($v);
    }

    /**
     * Throws when the candidate cannot be strictly compared with the bound value. Null passes through.
     *
     * @throws IncompatibleTypeException
     */
    public static function assertEquatable(mixed $candidate, mixed $value, string $ruleName): void
    {
        if (!self::isEquatable($candidate, $value)) {
            throw new IncompatibleTypeException($ruleName, $candidate, $value);
        }
    }

    /**
     * Returns false for null, true for DateTimeInterface, throws for anything else.
     *
     * @throws IncompatibleTypeException
     */
    public static function isDateCandidate(mixed $candidate, string $ruleName, mixed $value = null): bool
    {
        if ($candidate === null) {
            return false;
        }
        if ($candidate instanceof DateTimeInterface) {
            return true;
        }
        throw new IncompatibleTypeException($ruleName, $candidate, $value);
    }

    private static function isNumber(mixed $v): bool
    {
        return is_int($v) || is_float($v);
    }
}
