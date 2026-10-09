<?php

declare(strict_types=1);

/**
 * ALinqBridge - Integration and Interoperability Bridge between ASpecification and ALinqCollection
 *
 * Fluent facade and adapter connecting Evans & Fowler specification trees from Antevemus.ASpecification
 * with LINQ collection processing pipelines and generator-based streaming from Antevemus.AlinqCollection.
 *
 * Features:
 * - Conversion of iterables and repositories (any IRepository) into ALinqCollection instances
 * - Lazy conversion into ALinqLazyCollection over IRepository::iterate(): the collection is built on a
 *   closure that asks the repository for a NEW generator on every traversal, so it stays re-iterable,
 *   never calls getAll() and only visits the entities the pipeline actually pulls
 * - Direct collection filtering using ISpecification trees via ALinqSpecificationVisitor; a repository
 *   source is filtered by the repository itself (iterate($specification)), so partitions prune
 * - Typed returns (antevemus/alinq-collection ^1.3): IALinqCollection and IALinqLazyCollection. Without
 *   the library every method throws before returning, so the declared types never load anything
 * - Runtime detection of antevemus/alinq-collection and lazy capabilities
 *
 * @version    1.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Linq
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */

namespace Antevemus\ASpecification\Linq;

use Antevemus\ALinq\Interfaces\IALinqCollection;
use Antevemus\ALinq\Interfaces\IALinqLazyCollection;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Repositories\IRepository;
use Antevemus\ASpecification\Specifications\Logical\AlwaysTrueSpecification;
use Generator;
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
     * A repository is read through iterate() with a tautology (every stored entity), whatever its
     * implementation (in memory, partition, file).
     *
     * @param iterable<mixed>|IRepository $items
     * @return IALinqCollection
     * @throws RuntimeException When the antevemus/alinq-collection package is not installed
     */
    public static function toCollection(iterable|IRepository $items): IALinqCollection
    {
        self::ensureAvailable();

        if ($items instanceof IRepository) {
            return \Antevemus\ALinq\ALinqCollection::from(
                iterator_to_array(self::iterateRepository($items, null), false)
            );
        }

        $array = is_array($items) ? array_values($items) : iterator_to_array($items, false);
        return \Antevemus\ALinq\ALinqCollection::from($array);
    }

    /**
     * Filter an iterable dataset by compiling an ISpecification into an executable LINQ predicate.
     *
     * @param iterable<mixed>|IRepository $items
     * @param ISpecification $specification
     * @return IALinqCollection Filtered collection
     */
    public static function filter(iterable|IRepository $items, ISpecification $specification): IALinqCollection
    {
        $collection = self::toCollection($items);
        $predicate = ALinqSpecificationVisitor::createPredicate($specification);

        return $collection->where($predicate);
    }

    /**
     * Extract all entities from a repository as an ALinqCollection.
     *
     * @param IRepository $repository
     * @return IALinqCollection
     */
    public static function fromRepository(IRepository $repository): IALinqCollection
    {
        return self::toCollection($repository);
    }

    /**
     * Query a repository applying a specification and returning the result as ALinqCollection.
     *
     * @param IRepository $repository
     * @param ISpecification $specification
     * @return IALinqCollection
     */
    public static function queryRepository(IRepository $repository, ISpecification $specification): IALinqCollection
    {
        $entities = $repository->findAllEntitiesSpecifiedBy($specification);
        return self::toCollection($entities);
    }

    // ==========================================
    // 2. Generator-Based Lazy Streaming
    // ==========================================

    /**
     * Convert an iterable, generator factory closure, or repository into an ALinqLazyCollection.
     *
     * A repository becomes `ALinqLazyCollection::from(fn() => $repository->iterate(...))`: the closure
     * returns a new generator on every traversal (the collection is re-iterable) and nothing is
     * materialized; `take(10)` on a 100,000-entity repository visits only what it needs.
     *
     * @param iterable<mixed>|callable|IRepository $source
     * @return IALinqLazyCollection
     * @throws RuntimeException When ALinqLazyCollection is not available
     */
    public static function toLazyCollection(iterable|callable|IRepository $source): IALinqLazyCollection
    {
        self::ensureLazyAvailable();

        if ($source instanceof IRepository) {
            return \Antevemus\ALinq\ALinqLazyCollection::from(
                static fn(): iterable => self::iterateRepository($source, null)
            );
        }

        return \Antevemus\ALinq\ALinqLazyCollection::from($source);
    }

    /**
     * Filter a streaming or lazy dataset by compiling an ISpecification into a LINQ predicate.
     *
     * A repository source is filtered by the repository itself (`iterate($specification)`, lazily,
     * with the partition pruning of the repository), every traversal asking for a new generator.
     *
     * @param iterable<mixed>|callable|IRepository $source
     * @param ISpecification $specification
     * @return IALinqLazyCollection Filtered lazy collection
     */
    public static function filterLazy(iterable|callable|IRepository $source, ISpecification $specification): IALinqLazyCollection
    {
        if ($source instanceof IRepository) {
            self::ensureLazyAvailable();

            return \Antevemus\ALinq\ALinqLazyCollection::from(
                static fn(): iterable => self::iterateRepository($source, $specification)
            );
        }

        $lazyCollection = self::toLazyCollection($source);
        $predicate = ALinqSpecificationVisitor::createPredicate($specification);

        return $lazyCollection->where($predicate);
    }

    /**
     * Extract all entities from a repository as an ALinqLazyCollection stream.
     *
     * @param IRepository $repository
     * @return IALinqLazyCollection
     */
    public static function fromRepositoryLazy(IRepository $repository): IALinqLazyCollection
    {
        return self::toLazyCollection($repository);
    }

    /**
     * Query a repository applying a specification lazily as an ALinqLazyCollection stream.
     *
     * @param IRepository $repository
     * @param ISpecification $specification
     * @return IALinqLazyCollection
     */
    public static function queryRepositoryLazy(IRepository $repository, ISpecification $specification): IALinqLazyCollection
    {
        return self::filterLazy($repository, $specification);
    }

    // ==========================================
    // 3. Helpers
    // ==========================================

    /**
     * One lazy pass over a repository: a generator that asks the repository for its own iterator
     * only when the traversal starts, so a repository error surfaces on traversal like the rest of
     * the lazy pipeline.
     *
     * @param IRepository $repository
     * @param ISpecification|null $specification Null = every stored entity
     * @return Generator<int, mixed>
     */
    private static function iterateRepository(IRepository $repository, ?ISpecification $specification): Generator
    {
        foreach ($repository->iterate($specification ?? new AlwaysTrueSpecification()) as $entity) {
            yield $entity;
        }
    }

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
