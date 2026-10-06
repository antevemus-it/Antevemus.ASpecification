<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Helpers;

use Antevemus\ASpecification\Contracts\Helpers\IInstrumentationUtils;
use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Repositories\IPartitionRepository;
use Antevemus\ASpecification\Contracts\Repositories\IRepository;
use Antevemus\ASpecification\Repositories\PartitionRepository;

/**
 * InstrumentationUtils - Telemetry, Diagnostic, and Graph Inspection Utilities
 *
 * Provides static utility methods for memory consumption tracking, node counting,
 * hierarchy navigation across DAG partition repositories, and structured dumps of specification trees.
 *
 * Features:
 * - Formatting of allocated and peak memory in human-readable SI units (B, KB, MB)
 * - Visual hierarchical inspection of partitioned repositories and subpartitions
 * - Accurate counting of nodes and recursive depth across partition trees
 * - Hierarchical formatted dumps of specification AST trees (composites and leaves)
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Helpers
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class InstrumentationUtils implements IInstrumentationUtils
{
    /**
     * Private constructor to prevent instantiation of static utility class.
     */
    private function __construct()
    {
    }

    /**
     * {@inheritdoc}
     */
    public static function formatMemoryUsage(bool $realUsage = true): string
    {
        $bytes = memory_get_usage($realUsage);
        $peak = memory_get_peak_usage($realUsage);

        return sprintf(
            'Memory: %s (Peak: %s)',
            self::formatBytes($bytes),
            self::formatBytes($peak)
        );
    }

    /**
     * {@inheritdoc}
     */
    public static function inspectRepositoryHierarchy(IRepository $repository, int $indent = 0): string
    {
        $prefix = str_repeat('  ', $indent);
        $className = (new \ReflectionClass($repository))->getShortName();

        if ($repository instanceof IPartitionRepository) {
            $spec = $repository->getSpecification();
            $specClass = $spec !== null ? (new \ReflectionClass($spec))->getShortName() : 'all';
            $output = sprintf("%s* [Partition] %s (spec: %s)\n", $prefix, $className, $specClass);

            foreach ($repository->getDirectPartitions() as $child) {
                if ($child instanceof IRepository) {
                    $output .= self::inspectRepositoryHierarchy($child, $indent + 1);
                }
            }

            return $output;
        }

        return sprintf("%s- [Repository] %s\n", $prefix, $className);
    }

    /**
     * {@inheritdoc}
     */
    public static function countPartitionNodes(IPartitionRepository $partition): int
    {
        $count = 1; // Current root node

        foreach ($partition->getDirectPartitions() as $child) {
            if ($child instanceof IPartitionRepository) {
                $count += self::countPartitionNodes($child);
            }
        }

        return $count;
    }

    /**
     * {@inheritdoc}
     */
    public static function dumpSpecificationTree(ISpecification $specification, int $indent = 0): string
    {
        $prefix = str_repeat('  ', $indent);
        $className = (new \ReflectionClass($specification))->getShortName();
        $output = sprintf("%s- %s\n", $prefix, $className);

        if ($specification instanceof ICompositeSpecification) {
            try {
                $ref = new \ReflectionObject($specification);
                if ($ref->hasProperty('specifications')) {
                    $prop = $ref->getProperty('specifications');
                    $prop->setAccessible(true);
                    $children = $prop->getValue($specification);
                    if ($children instanceof \SplObjectStorage || is_iterable($children)) {
                        foreach ($children as $child) {
                            if ($child instanceof ISpecification) {
                                $output .= self::dumpSpecificationTree($child, $indent + 1);
                            }
                        }
                    }
                }
            } catch (\Throwable) {
                // Silent fallback
            }
        }

        return $output;
    }

    /**
     * Format byte count into human-readable unit string.
     *
     * @param int $bytes Byte count
     * @return string
     */
    private static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1048576) {
            return sprintf('%.2f KB', $bytes / 1024.0);
        }

        return sprintf('%.2f MB', $bytes / 1048576.0);
    }
}
