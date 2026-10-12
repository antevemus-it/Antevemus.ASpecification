<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Attributes;

use Attribute;

/**
 * Specifiable - Opt-in marker that lets a specification read and write a non-public property by name
 *
 * The library only receives a property name as text (`Spec::property()`, `where()`, the delta of
 * `updateWithDelta()`, the ALinq visitor) and cannot tell a domain rule written by the author of the
 * entity from a name that came from a request (a filter or an ordering built by a screen). Since 1.6.1
 * a private or protected property is reached by name only when the author of the class marked it:
 *
 * - on a property, that private or protected property is readable by name (the shared accessor `PropertyAccess` of antevemus/adeep-property-access, last
 *   resolution step) and writable by a delta specification (`SpecificationHelper::updateEntityState()`,
 *   when the entity has no public setter for it);
 * - on a class, the same holds for every property DECLARED by that class. It does not reach the
 *   properties of a parent class nor of a subclass: each class in the hierarchy opts in for the
 *   properties it declares.
 *
 * An unmarked non-public property behaves as a property that does not exist, as before 1.4.4: a
 * property specification over it is an evaluation error, `PropertyAccess::hasProperty()` answers
 * `false` and a delta that names it is refused. Without the opt-in, a range over a private field
 * (`Spec::property('password_hash', Spec::between('a', 'b'))`) works as a read oracle for its value,
 * and a delta works as mass assignment that skips the setter.
 *
 * `#[Antevemus\ALinq\Attributes\Specifiable]` (ALinq 1.4.1) and, since 2.0.0,
 * `#[Antevemus\ADeepPropertyAccess\Attributes\Specifiable]` are accepted as the same marker, by name,
 * on both paths; this attribute stays the one to use with this library (the shared accessor
 * recognizes it by name too). Public getters, `__get` guarded by `__isset` and public properties need
 * no marker.
 *
 * Features:
 * - Property target: one private or protected property reachable by name
 * - Class target: every property declared by the class, never inherited up or down the hierarchy
 * - Checked by ReflectionUtils::isSpecifiable(), shared by the read and the write paths
 *
 * @version    2.0.0
 * @package    Antevemus\ASpecification
 * @subpackage Attributes
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_CLASS)]
final class Specifiable
{
}
