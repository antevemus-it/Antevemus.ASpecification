<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Helpers;

use Antevemus\ASpecification\Contracts\Helpers\IInstrumentationUtils;
use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Repositories\IPartitionRepository;
use Antevemus\ASpecification\Contracts\Repositories\IPersistentRepository;
use Antevemus\ASpecification\Contracts\Repositories\IRepository;
use Antevemus\ASpecification\Repositories\PartitionRepository;
use RuntimeException;
use Throwable;

/**
 * InstrumentationUtils - Telemetry, Diagnostic, and Graph Inspection Utilities
 *
 * Provides static utility methods for memory consumption tracking, node counting,
 * hierarchy navigation across DAG partition repositories, and structured dumps of specification trees.
 *
 * Completed in 1.4.4 with the constants and message builders of
 * net.sourceforge.domian.util.InstrumentationUtils (Domian, Copyright 2006-2010 the original author or
 * authors, Apache License 2.0; see THIRD_PARTY_NOTICES.md), in PHP form: the "thread number" of the
 * Java messages is the process id (and the fiber id when a fiber runs), the memory message reads
 * memory_get_usage() against memory_limit, and the stack trace helpers read debug_backtrace() or the
 * trace of a Throwable instead of the JVM stack.
 *
 * Features:
 * - Formatting of allocated and peak memory in human-readable SI units (B, KB, MB)
 * - Visual hierarchical inspection of partitioned repositories and subpartitions
 * - Accurate counting of nodes and recursive depth across partition trees
 * - Hierarchical formatted dumps of specification AST trees (composites and leaves)
 * - Domian message builders: "Testing X.y()...", "#pid  message", "[mem: used/limit]", stack traces,
 *   US-style pretty printing of large numbers, partition repository listing
 *
 * @version    1.4.4
 * @package    Antevemus\ASpecification
 * @subpackage Helpers
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class InstrumentationUtils implements IInstrumentationUtils
{
    public const DOTS = '...';
    public const NOT_SUPPORTED = 'NOT SUPPORTED';
    public const NOT_YET_SUPPORTED = 'NOT YET SUPPORTED';
    public const REDEFINED_ELSEWHERE = 'REDEFINED ELSEWHERE';
    public const REDEFINED = 'REDEFINED';
    public const NOT_APPLICABLE = 'N/A';
    public const SKIPPED = 'SKIPPED';
    public const TODO = 'TODO';
    public const TEMPORARILY_ISOLATED = 'TEMPORARILY RELOCATED JUST TO RUN TEST ISOLATED';
    public const TEMPORARY = 'TEMPORARY';

    /** Three spaces (Java repeat(" ", 3)). */
    public const DEBUG_LEVEL_INDENTATION = '   ';

    /** Six spaces (Java repeat(DEBUG_LEVEL_INDENTATION, 2)). */
    public const TRACE_LEVEL_INDENTATION = '      ';

    /**
     * Private constructor to prevent instantiation of static utility class.
     */
    private function __construct()
    {
    }

    ///////////////////////////////////////////////////////////////////////////
    // Memory and structure (original to this library)
    ///////////////////////////////////////////////////////////////////////////

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

    ///////////////////////////////////////////////////////////////////////////
    // Domian message builders
    ///////////////////////////////////////////////////////////////////////////

    /**
     * "Testing Class.method()..." or, with a deviation, "Testing method ... [deviation]"
     * (the three Java overloads of buildTestingOfMethodString()).
     *
     * @param string $methodName
     * @param object|null $instance Test target, used wherever test methods are inherited
     * @param string|null $deviation
     * @return string
     */
    public static function buildTestingOfMethodString(string $methodName, ?object $instance = null, ?string $deviation = null): string
    {
        if ($deviation === null || trim($deviation) === '') {
            return 'Testing ' . ($instance === null ? $methodName . '()' : self::buildSimpleClassMethodString($instance, $methodName)) . self::DOTS;
        }
        return 'Testing ' . $methodName . ' ' . self::DOTS . ' [' . $deviation . ']';
    }

    /**
     * "ShortClassName.method()".
     *
     * @param object $instance
     * @param string $methodName
     * @return string
     */
    public static function buildSimpleClassMethodString(object $instance, string $methodName): string
    {
        return (new \ReflectionClass($instance))->getShortName() . '.' . $methodName . '()';
    }

    /**
     * "   #N [ShortClassName.method()]".
     *
     * @param int $iterationNumber
     * @param object $instance
     * @param string $methodName
     * @return string
     */
    public static function buildDebugLogLevelIterationMessage(int $iterationNumber, object $instance, string $methodName): string
    {
        return self::DEBUG_LEVEL_INDENTATION . '#' . $iterationNumber . ' [' . self::buildSimpleClassMethodString($instance, $methodName) . ']';
    }

    /**
     * "#pid    message" or, with an instance and method, "#pid    Class[objectId].method()   message"
     * (the two Java overloads of buildThreadNumberAndMessage(); the thread id becomes the process id,
     * suffixed with the fiber id when a fiber is running).
     *
     * @param string $message
     * @param object|null $instance
     * @param string|null $methodName
     * @return string
     */
    public static function buildThreadNumberAndMessage(string $message, ?object $instance = null, ?string $methodName = null): string
    {
        $line = sprintf('#%-6s', self::currentExecutionContextId());
        if ($instance !== null) {
            $line .= sprintf('%-75s', get_class($instance) . '[' . spl_object_id($instance) . '].' . ($methodName ?? '') . '()');
            $line .= '   ';
        }
        return $line . $message;
    }

    /**
     * "[mem: <used memory in MB>MB/<memory limit in MB>MB]" (Java reads the JVM heap; here the current
     * usage and the memory_limit, or the peak usage when the limit is unlimited).
     *
     * @return string
     */
    public static function buildMemoryConsumptionMessage(): string
    {
        $used = intdiv(memory_get_usage(true), 1_000_000);
        $limit = self::memoryLimitBytes();
        $total = $limit > 0 ? intdiv($limit, 1_000_000) : intdiv(memory_get_peak_usage(true), 1_000_000);
        return '[mem: ' . $used . 'MB/' . $total . 'MB]';
    }

    /**
     * Indented stack trace lines of the current call, skipping the first $skipLines
     * (the frame of this helper itself is always skipped).
     *
     * @param int $numberOfStackTraceLines Number of lines wanted
     * @param int $skipLines Number of lines to skip, counting from the top
     * @return string
     */
    public static function getStackTrace(int $numberOfStackTraceLines, int $skipLines = 0): string
    {
        return self::getStackTraceOf(
            new RuntimeException('Provoked by ' . self::class . '::getStackTrace()'),
            $numberOfStackTraceLines,
            $skipLines + 1
        );
    }

    /**
     * Indented stack trace lines harvested from a Throwable, skipping the first $skipLines.
     *
     * @param Throwable $throwable
     * @param int $numberOfStackTraceLines Number of lines wanted
     * @param int $skipLines Number of lines to skip, counting from the top
     * @return string
     */
    public static function getStackTraceOf(Throwable $throwable, int $numberOfStackTraceLines, int $skipLines = 0): string
    {
        $lines = explode(PHP_EOL, $throwable->getTraceAsString());
        $output = '';
        $printed = 0;

        foreach ($lines as $index => $line) {
            if ($printed >= $numberOfStackTraceLines) {
                break;
            }
            if ($index >= $skipLines) {
                $output .= self::TRACE_LEVEL_INDENTATION . $line . PHP_EOL;
                $printed++;
            }
        }

        return $output;
    }

    /**
     * The message followed by a trailing stack trace (of the current call, or of $throwable when given).
     *
     * @param string $message
     * @param int $numberOfStackTraceLines Number of lines wanted; 0 appends nothing
     * @param int $skipLines Number of lines to skip, counting from the top
     * @param Throwable|null $throwable
     * @return string
     */
    public static function buildMessageWithStackTrace(string $message, int $numberOfStackTraceLines, int $skipLines = 0, ?Throwable $throwable = null): string
    {
        if ($numberOfStackTraceLines <= 0) {
            return $message;
        }

        $trace = $throwable === null
            ? self::getStackTrace($numberOfStackTraceLines, $skipLines + 1)
            : self::getStackTraceOf($throwable, $numberOfStackTraceLines, $skipLines);

        return $message . PHP_EOL . $trace;
    }

    /**
     * US-style pretty-printing of large numbers, adding a comma for every thousand.
     *
     * @param int $bigNumber
     * @return string
     */
    public static function prettyPrintLargeNumber(int $bigNumber): string
    {
        return number_format($bigNumber, 0, '.', ',');
    }

    /**
     * Listing of a partition repository in the Domian format: the root followed by one line per
     * sub-partition, each with [repository id] [underlying type] [n entities of that node only] [specification].
     *
     * @param IPartitionRepository $repository
     * @return string
     */
    public static function printPartitionRepository(IPartitionRepository $repository): string
    {
        $output = PHP_EOL . 'Root partition:  ' . self::DEBUG_LEVEL_INDENTATION . self::describePartition($repository) . PHP_EOL;

        $index = 0;
        foreach ($repository->getAllPartitions() as $partition) {
            if ($partition === $repository || !$partition instanceof IPartitionRepository) {
                continue;
            }
            $output .= self::DEBUG_LEVEL_INDENTATION . 'Sub partition ' . (++$index) . ': ' . self::describePartition($partition) . PHP_EOL;
        }

        return $output;
    }

    ///////////////////////////////////////////////////////////////////////////
    // Internals
    ///////////////////////////////////////////////////////////////////////////

    private static function describePartition(IPartitionRepository $partition): string
    {
        $underlying = $partition->getUnderlyingRepository();
        $id = $underlying instanceof IPersistentRepository
            ? (string) $underlying->getRepositoryId()
            : '<not persistent/no repo id>';
        $spec = $partition->getSpecification();

        return '[' . $id . '] '
            . '[' . (new \ReflectionClass($underlying))->getShortName() . '] '
            . '[' . count($partition->getEntitiesOfThisPartitionOnly()) . ' entities] '
            . '[' . ($spec !== null ? (string) $spec : '<no partition specification>') . ']';
    }

    private static function currentExecutionContextId(): string
    {
        $fiber = \Fiber::getCurrent();
        $pid = (string) getmypid();
        return $fiber === null ? $pid : $pid . '/' . spl_object_id($fiber);
    }

    private static function memoryLimitBytes(): int
    {
        $limit = (string) ini_get('memory_limit');
        if ($limit === '' || $limit === '-1') {
            return 0;
        }
        $unit = strtolower(substr($limit, -1));
        $value = (int) $limit;
        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
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
