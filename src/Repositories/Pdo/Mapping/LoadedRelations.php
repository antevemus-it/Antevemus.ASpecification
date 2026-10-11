<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Pdo\Mapping;

use WeakMap;
use WeakReference;

/**
 * LoadedRelations - Which Relations of Which Entity Objects Were Loaded (Internal)
 *
 * The memory of eager loading (since 1.13.0, forward 031, D7), kept outside the entities so that they
 * stay plain objects: a WeakMap keyed by the entity OBJECT (an entry disappears with the object), whose
 * value is, per relation property, what was loaded: the identifiers of the children of a collection
 * (with weak references to the child objects, to remove an orphan with the version it was loaded
 * with) or whether a reference was loaded non-null. Nothing holds an entity alive.
 *
 * Shared by every PdoRepository of the process (an entity loaded through one repository and saved
 * through another of the same table is known as loaded). Read it through
 * EntityMapping::isRelationLoaded() or PdoRepository::isLoaded().
 *
 * @internal
 * @version    1.13.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\Pdo\Mapping
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class LoadedRelations
{
    /** @var WeakMap<object, array<string, array{ids: array<string, WeakReference<object>|null>, present: bool}>>|null */
    private static ?WeakMap $loaded = null;

    private function __construct()
    {
    }

    /** True when the relation of the object was loaded (or written as a whole). */
    public static function isLoaded(object $entity, string $property): bool
    {
        return isset(self::map()[$entity][$property]);
    }

    /**
     * Records a loaded collection: the text form of each child identifier, with the child object.
     *
     * @param array<string, object> $children Identifier text => child
     */
    public static function markCollection(object $entity, string $property, array $children): void
    {
        $ids = [];
        foreach ($children as $key => $child) {
            $ids[(string) $key] = WeakReference::create($child);
        }
        self::store($entity, $property, ['ids' => $ids, 'present' => $ids !== []]);
    }

    /** Records a loaded reference (present = it was not null). */
    public static function markReference(object $entity, string $property, bool $present): void
    {
        self::store($entity, $property, ['ids' => [], 'present' => $present]);
    }

    /** Forgets what was loaded for one relation of the object. */
    public static function forget(object $entity, string $property): void
    {
        $map = self::map();
        if (isset($map[$entity][$property])) {
            $entry = $map[$entity];
            unset($entry[$property]);
            $map[$entity] = $entry;
        }
    }

    /**
     * The children loaded for a collection: identifier text => the child object when still alive
     * (null when it was freed); null when the collection was not loaded.
     *
     * @return array<string, object|null>|null
     */
    public static function loadedChildren(object $entity, string $property): ?array
    {
        $entry = self::map()[$entity][$property] ?? null;
        if ($entry === null) {
            return null;
        }
        $children = [];
        foreach ($entry['ids'] as $key => $reference) {
            $children[(string) $key] = $reference?->get();
        }

        return $children;
    }

    /** True when the reference was loaded and was not null; false otherwise (or not loaded). */
    public static function wasPresent(object $entity, string $property): bool
    {
        return (self::map()[$entity][$property]['present'] ?? false) === true;
    }

    /**
     * @param array{ids: array<string, WeakReference<object>|null>, present: bool} $state
     */
    private static function store(object $entity, string $property, array $state): void
    {
        $map = self::map();
        $entry = $map[$entity] ?? [];
        $entry[$property] = $state;
        $map[$entity] = $entry;
    }

    /** @return WeakMap<object, array<string, array{ids: array<string, WeakReference<object>|null>, present: bool}>> */
    private static function map(): WeakMap
    {
        return self::$loaded ??= new WeakMap();
    }
}
