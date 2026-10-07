<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Comparison;

use Antevemus\ASpecification\Specifications\Exceptions\IncompatibleTypeException;
use DateTimeInterface;

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
 * Null candidates are never a type error: each specification keeps its own null semantics.
 * Subsumption algebra uses the boolean predicates (no exception) because it compares two
 * specifications, not a candidate.
 *
 * @version    1.1.0
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
     * Throws when the candidate cannot be ordered against the bound value. Null passes through.
     *
     * @throws IncompatibleTypeException
     */
    public static function assertOrderable(mixed $candidate, mixed $value, string $ruleName): void
    {
        if ($candidate === null) {
            return;
        }
        if (!self::isOrderable($candidate, $value)) {
            throw new IncompatibleTypeException($ruleName, $candidate, $value);
        }
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
