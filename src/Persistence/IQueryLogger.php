<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Persistence;

/**
 * IQueryLogger - Receives Every SQL Statement a Persistence Component Runs
 *
 * The port behind PdoRepository::withQueryLogger() (since 1.13.0, forward 031, D1; the equivalent of
 * the Adianti TLogger). log() is called once per statement, after it ran or failed:
 * - $sql is the statement text exactly as prepared: placeholders, never values
 * - $parameterCount is the number of bound parameters
 * - $milliseconds is the time spent preparing, binding and executing (for a streamed SELECT, the
 *   fetches after the first row are not included)
 * - $parameters holds the bound values by placeholder (by 0-based position for a statement with `?`
 *   placeholders, as the multi-row INSERT of PdoRepository::insertAll()) ONLY when the component was
 *   told to log them (`withQueryLogger($logger, logParameters: true)`); otherwise it is null. Values
 *   can be personal data (RN-02): they stay out of the log unless the owner of the data turns them on.
 *
 * An exception thrown by a logger is ignored: a broken log never changes the outcome of a statement.
 *
 * @version    1.13.0
 * @package    Antevemus\ASpecification
 * @subpackage Persistence
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IQueryLogger
{
    /**
     * @param string $sql Statement text (placeholders only)
     * @param int $parameterCount Number of bound parameters
     * @param float $milliseconds Time to prepare, bind and execute
     * @param array<string|int, mixed>|null $parameters Bound values by placeholder (or position), or null when values are not logged
     */
    public function log(string $sql, int $parameterCount, float $milliseconds, ?array $parameters): void;
}
