<?php

declare(strict_types=1);

/**
 * ALinqBridge - Integration and Interoperability Bridge between ASpecification and ALinqCollection
 *
 * Fluent facade and adapter connecting Evans & Fowler specification trees from Antevemus.ASpecification
 * with LINQ collection processing pipelines and generator-based streaming from Antevemus.AlinqCollection.
 *
 * Features:
 * - Conversion of iterables and in-memory repositories into ALinqCollection instances
 * - Deferred streaming conversion into ALinqLazyCollection for O(1) memory overhead
 * - Direct collection filtering using ISpecification trees via ALinqSpecificationVisitor
 * - Runtime detection of antevemus/alinq-collection and lazy capabilities
 * - Fluent chaining with sorting, projection, slicing, and grouping operations
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Linq
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */

namespace Antevemus\ASpecification\Linq;

use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Repositories\InMemoryRepository;
use RuntimeException;

final class ALinqBridge
{
    /**
     * Determine whether the Antevemus\ALinq library is installed and available in runtime.
     *
     * @return bool
     */
    public static function isAvailable(): bool
    {
        return class_exists(\Antevemus\ALinq\ALinqCollection::class);
    }

    /**
     * Determine whether ALinqLazyCollection is available in runtime (requires antevemus/alinq-collection >= 1.1.0).
     *
     * @return bool
     */
    public static function isLazyAvailable(): bool
    {
        return class_exists(\Antevemus\ALinq\ALinqLazyCollection::class);
    }

    // ==========================================
    // 1. In-Memory Eager Collections
    // ==========================================

    /**
     * Convert an iterable collection of items, array, or repository into an ALinqCollection instance.
     *
     * @param iterable|InMemoryRepository $items
     * @return object Returns an instance of \Antevemus\ALinq\ALinqCollection
     * @throws RuntimeException When the antevemus/alinq-collection package is not installed
     */
    public static function toCollection(iterable|InMemoryRepository $items): object
    {
        self::ensureAvailable();

        if ($items instanceof InMemoryRepository) {
            return \Antevemus\ALinq\ALinqCollection::from($items->getAll());
        }

        $array = is_array($items) ? array_values($items) : iterator_to_array($items, false);
        return \Antevemus\ALinq\ALinqCollection::from($array);
    }

    /**
     * Filter an iterable dataset by compiling an ISpecification into an executable LINQ predicate.
     *
     * @param iterable|InMemoryRepository $items
     * @param ISpecification $specification
     * @return object Returns a filtered \Antevemus\ALinq\ALinqCollection instance
     */
    public static function filter(iterable|InMemoryRepository $items, ISpecification $specification): object
    {
        $collection = self::toCollection($items);
        $predicate = ALinqSpecificationVisitor::createPredicate($specification);

        /** @var \Antevemus\ALinq\ALinqCollection $collection */
        return $collection->where($predicate);
    }

    /**
     * Extract all entities from an InMemoryRepository as an ALinqCollection.
     *
     * @param InMemoryRepository $repository
     * @return object Returns an instance of \Antevemus\ALinq\ALinqCollection
     */
    public static function fromRepository(InMemoryRepository $repository): object
    {
        return self::toCollection($repository->getAll());
    }

    /**
     * Query an InMemoryRepository applying a specification and returning the result as ALinqCollection.
     *
     * @param InMemoryRepository $repository
     * @param ISpecification $specification
     * @return object Returns an instance of \Antevemus\ALinq\ALinqCollection
     */
    public static function queryRepository(InMemoryRepository $repository, ISpecification $specification): object
    {
        $entities = $repository->findAllEntitiesSpecifiedBy($specification);
        return self::toCollection($entities);
    }

    // ==========================================
    // 2. Generator-Based Lazy Streaming (O(1) RAM)
    // ==========================================

    /**
     * Convert an iterable, generator factory closure, or InMemoryRepository into an ALinqLazyCollection.
     *
     * Enables constant O(1) memory overhead processing over massive or infinite data streams.
     *
     * @param iterable|callable|InMemoryRepository $source
     * @return object Returns an instance of \Antevemus\ALinq\ALinqLazyCollection
     * @throws RuntimeException When ALinqLazyCollection is not available
     */
    public static function toLazyCollection(iterable|callable|InMemoryRepository $source): object
    {
        self::ensureLazyAvailable();

        if ($source instanceof InMemoryRepository) {
            return \Antevemus\ALinq\ALinqLazyCollection::from(static fn(): array => $source->getAll());
        }

        return \Antevemus\ALinq\ALinqLazyCollection::from($source);
    }

    /**
     * Filter a streaming or lazy dataset with constant O(1) memory by compiling an ISpecification into a LINQ predicate.
     *
     * @param iterable|callable|InMemoryRepository $source
     * @param ISpecification $specification
     * @return object Returns a filtered \Antevemus\ALinq\ALinqLazyCollection instance
     */
    public static function filterLazy(iterable|callable|InMemoryRepository $source, ISpecification $specification): object
    {
        $lazyCollection = self::toLazyCollection($source);
        $predicate = ALinqSpecificationVisitor::createPredicate($specification);

        /** @var \Antevemus\ALinq\ALinqLazyCollection $lazyCollection */
        return $lazyCollection->where($predicate);
    }

    /**
     * Extract all entities from an InMemoryRepository as an ALinqLazyCollection stream.
     *
     * @param InMemoryRepository $repository
     * @return object Returns an instance of \Antevemus\ALinq\ALinqLazyCollection
     */
    public static function fromRepositoryLazy(InMemoryRepository $repository): object
    {
        return self::toLazyCollection($repository);
    }

    /**
     * Query an InMemoryRepository applying a specification lazily as an ALinqLazyCollection stream.
     *
     * @param InMemoryRepository $repository
     * @param ISpecification $specification
     * @return object Returns an instance of \Antevemus\ALinq\ALinqLazyCollection
     */
    public static function queryRepositoryLazy(InMemoryRepository $repository, ISpecification $specification): object
    {
        return self::filterLazy($repository, $specification);
    }

    // ==========================================
    // 3. Validation Helpers
    // ==========================================

    /**
     * Ensure the ALinqCollection class is available or throw an exception.
     */
    private static function ensureAvailable(): void
    {
        if (!self::isAvailable()) {
            throw new RuntimeException(
                "The library 'antevemus/alinq-collection' is required to use ALinqBridge. " .
                "Install it via composer require antevemus/alinq-collection."
            );
        }
    }

    /**
     * Ensure the ALinqLazyCollection class is available or throw an exception.
     */
    private static function ensureLazyAvailable(): void
    {
        if (!self::isLazyAvailable()) {
            throw new RuntimeException(
                "The class 'Antevemus\\ALinq\\ALinqLazyCollection' (antevemus/alinq-collection >= 1.1.0) is required for lazy operations. " .
                "Install it via composer require antevemus/alinq-collection."
            );
        }
    }
}
