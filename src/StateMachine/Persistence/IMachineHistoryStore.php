<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence;

/**
 * IMachineHistoryStore - Port of the Append-Only History
 *
 * One row per region moved by an accepted event, plus the start and migration rows (forward 023,
 * D15, RN-07). The port has no update and no delete: the history is a trail, the snapshot is the
 * truth (§5). Appends run inside the caller's transaction.
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IMachineHistoryStore
{
    /**
     * Appends rows (never replaces one: a duplicate position is a storage error).
     *
     * @param list<HistoryEntry> $entries Rows to append
     */
    public function append(array $entries): void;

    /**
     * Rows of an instance in write order (instance version, then sort order).
     *
     * @param string $instanceId Instance id
     * @return list<HistoryEntry>
     */
    public function findByInstance(string $instanceId): array;
}
