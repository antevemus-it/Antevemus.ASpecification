<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Helpers;

use ArrayAccess;
use Closure;

/**
 * PropertyAccessor - Polymorphic and Resilient Property Extractor
 *
 * Provides unified, reflective resolution of object properties, attributes, getters,
 * and nested dot notation paths, compatible with DTOs, rich entities, and associative arrays.
 *
 * Features:
 * - Direct public and dynamic attribute access
 * - Getter method (getProperty, property) and boolean predicate (isProperty, hasProperty) resolution
 * - Array and ArrayAccess interface support
 * - Nested dot notation path traversal (e.g. 'user.address.city')
 * - Private and protected properties, declared anywhere in the class hierarchy, as the LAST resort
 *   (Domian ReflectionUtils.getFieldByName(): a specification may be bound to a field without a getter)
 * - Generates optimized Closures for functional pipelines and LINQ queries
 *
 * Resolution order of a single segment, first match wins:
 *   1. array / ArrayAccess key;
 *   2. public method getX(), x(), isX(), hasX();
 *   3. isset($object->x): public initialized property, or __get guarded by __isset;
 *   4. public property (reflection; an uninitialized typed property yields null);
 *   5. non-public property declared by the class or a superclass (reflection; since 1.4.4);
 *   6. null.
 *
 * @version    1.4.4
 * @package    Antevemus\ASpecification
 * @subpackage Helpers
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class PropertyAccessor
{
    /**
     * Retrieve the value of a property or nested dot-notation path from a candidate object or array.
     *
     * @param mixed $target Candidate object or array
     * @param string $property Property name or dot notation path ('user.address.city')
     * @return mixed Extracted value or null if unresolvable
     */
    public static function getValue(mixed $target, string $property): mixed
    {
        if ($target === null) {
            return null;
        }

        if (str_contains($property, '.')) {
            return self::getNestedValue($target, $property);
        }

        return self::extractSingleProperty($target, $property);
    }

    /**
     * Check whether a property or dot notation path exists on the candidate target.
     *
     * @param mixed $target
     * @param string $property
     * @return bool
     */
    public static function hasProperty(mixed $target, string $property): bool
    {
        if ($target === null) {
            return false;
        }

        if (str_contains($property, '.')) {
            $segments = explode('.', $property);
            $current = $target;
            foreach ($segments as $segment) {
                if ($current === null || !self::hasSingleProperty($current, $segment)) {
                    return false;
                }
                $current = self::extractSingleProperty($current, $segment);
            }
            return true;
        }

        return self::hasSingleProperty($target, $property);
    }

    /**
     * Extract a single property from an object or array.
     *
     * @param mixed $target
     * @param string $property
     * @return mixed
     */
    private static function extractSingleProperty(mixed $target, string $property): mixed
    {
        if (is_array($target) || $target instanceof ArrayAccess) {
            return $target[$property] ?? null;
        }

        if (is_object($target)) {
            $methods = [
                'get' . ucfirst($property),
                $property,
                'is' . ucfirst($property),
                'has' . ucfirst($property),
            ];

            foreach ($methods as $method) {
                if (method_exists($target, $method) && is_callable([$target, $method])) {
                    return $target->$method();
                }
            }

            if (isset($target->{$property})) {
                return $target->{$property};
            }

            if (property_exists($target, $property)) {
                try {
                    $ref = new \ReflectionProperty($target, $property);
                    if ($ref->isPublic()) {
                        return $ref->getValue($target);
                    }
                } catch (\Throwable) {
                    // Ignore
                }
            }

            // Last resort: a private/protected property declared by the class or a superclass
            // (Domian reads the field itself when no accessor exists).
            $field = ReflectionUtils::getFieldByName($property, $target);
            if ($field !== null) {
                try {
                    if ($field->isStatic()) {
                        return $field->getValue();
                    }
                    return $field->isInitialized($target) ? $field->getValue($target) : null;
                } catch (\Throwable) {
                    // Ignore
                }
            }
        }

        return null;
    }

    /**
     * Check whether an individual property exists on an object or array.
     *
     * @param mixed $target
     * @param string $property
     * @return bool
     */
    private static function hasSingleProperty(mixed $target, string $property): bool
    {
        if (is_array($target) || $target instanceof ArrayAccess) {
            return array_key_exists($property, (array)$target) || isset($target[$property]);
        }

        if (is_object($target)) {
            $methods = [
                'get' . ucfirst($property),
                $property,
                'is' . ucfirst($property),
                'has' . ucfirst($property),
            ];

            foreach ($methods as $method) {
                if (method_exists($target, $method) && is_callable([$target, $method])) {
                    return true;
                }
            }

            if (isset($target->{$property})) {
                return true;
            }

            if (property_exists($target, $property)) {
                try {
                    $ref = new \ReflectionProperty($target, $property);
                    if ($ref->isPublic()) {
                        return true;
                    }
                } catch (\Throwable) {
                    return false;
                }
            }

            // Last resort: a private/protected property declared by the class or a superclass
            return ReflectionUtils::hasField($target, $property);
        }

        return false;
    }

    /**
     * Extract nested value traversing dot-separated path segments.
     *
     * @param mixed $target
     * @param string $path
     * @return mixed
     */
    private static function getNestedValue(mixed $target, string $path): mixed
    {
        $current = $target;
        foreach (explode('.', $path) as $segment) {
            if ($current === null) {
                return null;
            }
            $current = self::extractSingleProperty($current, $segment);
        }
        return $current;
    }

    /**
     * Return a Closure extracting the specified property from any candidate.
     *
     * @param string $property
     * @return Closure(mixed): mixed
     */
    public static function getAccessor(string $property): Closure
    {
        return fn(mixed $item): mixed => self::getValue($item, $property);
    }
}
