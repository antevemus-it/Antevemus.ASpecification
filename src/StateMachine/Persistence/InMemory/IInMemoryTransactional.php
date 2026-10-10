<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence\InMemory;

/**
 * IInMemoryTransactional - In-Memory Store Taking Part in an InMemoryTransactionManager
 *
 * The in-memory stores keep plain arrays of immutable values: a transaction exports that state
 * when it begins and imports it back when it rolls back.
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence\InMemory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IInMemoryTransactional
{
    /**
     * Exports the current state (a copy: values are immutable, arrays are copied on write).
     *
     * @return array<mixed>
     */
    public function exportState(): array;

    /**
     * Restores a state previously exported.
     *
     * @param array<mixed> $state
     */
    public function importState(array $state): void;
}
