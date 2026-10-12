<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Helpers;

use Antevemus\ADeepPropertyAccess\PropertyAccess;
use Closure;

/**
 * PropertyAccessor - Deprecated Facade over the Shared Property Accessor
 *
 * Since 2.0.0 the property resolution of this library lives in `antevemus/adeep-property-access`
 * (`Antevemus\ADeepPropertyAccess\PropertyAccess`), the package shared with ALinq: both libraries
 * read the same value from the same candidate by construction. Every internal reader of the library
 * calls the package directly; this class only delegates, so that code calling it keeps working.
 *
 * Resolution order of a single segment, first match wins (the package's contract):
 *   1. array key, or `ArrayAccess` offset (existence through `offsetExists()`);
 *   2. public method `getX()`, `x()`, `isX()`, `hasX()` without required parameters (never `__call()`,
 *      never a name starting with `__`);
 *   3. `isset($object->x)`: public initialized property, dynamic property, `__get` guarded by `__isset`;
 *   4. declared public property (an uninitialized typed property yields null);
 *   5. private or protected property marked `#[Specifiable]` (this library's attribute, ALinq's or the
 *      package's, recognized by name), on the property or on the class that declares it, declared by
 *      the class or an ancestor;
 *   6. null.
 * A path with a dot is always a path. An unmarked private or protected property is not resolved: the
 * name may come from a request, and without the author's opt-in a range over a private field would
 * work as a read oracle for its value.
 *
 * Answers that changed with the package (2.0.0): an empty name yields null instead of throwing
 * `InvalidArgumentException`; an `ArrayAccess` object answers through its offsets only (a public
 * property of an `ArrayAccess` object is no longer seen by hasProperty()); an unset public property
 * with `__get()` and no `__isset()` yields null; a method with a required parameter, a method reached
 * only through `__call()` and a method whose name starts with `__` are never called as getters.
 *
 * No runtime notice is raised: the facade sits on the hot path of every property specification.
 *
 * @deprecated 2.0.0 Removed in 3.0.0; use Antevemus\ADeepPropertyAccess\PropertyAccess
 *             (getValue(), hasProperty(), accessor())
 * @version    2.0.0
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
     * @deprecated 2.0.0 Removed in 3.0.0; use PropertyAccess::getValue()
     */
    public static function getValue(mixed $target, string $property): mixed
    {
        return PropertyAccess::getValue($target, $property);
    }

    /**
     * Check whether a property or dot notation path exists on the candidate target.
     *
     * @param mixed $target
     * @param string $property
     * @return bool
     * @deprecated 2.0.0 Removed in 3.0.0; use PropertyAccess::hasProperty()
     */
    public static function hasProperty(mixed $target, string $property): bool
    {
        return PropertyAccess::hasProperty($target, $property);
    }

    /**
     * Return a Closure extracting the specified property from any candidate.
     *
     * @param string $property
     * @return Closure(mixed): mixed
     * @deprecated 2.0.0 Removed in 3.0.0; use PropertyAccess::accessor()
     */
    public static function getAccessor(string $property): Closure
    {
        return PropertyAccess::accessor($property);
    }
}
