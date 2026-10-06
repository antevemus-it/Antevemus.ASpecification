<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Helpers;

use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Repositories\IPartitionRepository;
use Antevemus\ASpecification\Contracts\Repositories\IRepository;

/**
 * IInstrumentationUtils - Contract for Instrumentation and Telemetry Utilities
 *
 * Provides telemetry capabilities, resource consumption measurement, and structural
 * inspection for DAG partition trees and specification Abstract Syntax Trees.
 *
 * Features:
 * - Allocated and peak memory consumption diagnostic
 * - Visual tree representation and inspection of partitioned repositories
 * - Node counting and navigation across partition DAGs
 * - Formatted hierarchical specification AST dump
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Helpers
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IInstrumentationUtils
{
    /**
     * Return formatted allocated memory usage in human-readable units (B, KB, MB).
     *
     * @param bool $realUsage If true, returns real system-allocated memory
     * @return string
     */
    public static function formatMemoryUsage(bool $realUsage = true): string;

    /**
     * Generate a textual tree representation of a partitioned repository hierarchy.
     *
     * @param IRepository $repository Repository to inspect
     * @param int $indent Initial indentation level
     * @return string
     */
    public static function inspectRepositoryHierarchy(IRepository $repository, int $indent = 0): string;

    /**
     * Calculate total number of nodes (child and recursive subpartitions) in partition graph.
     *
     * @param IPartitionRepository $partition Root or intermediary partition
     * @return int
     */
    public static function countPartitionNodes(IPartitionRepository $partition): int;

    /**
     * Generate a textual tree representation of a specification's structure.
     *
     * @param ISpecification $specification Specification to inspect
     * @param int $indent Initial indentation level
     * @return string
     */
    public static function dumpSpecificationTree(ISpecification $specification, int $indent = 0): string;
}
