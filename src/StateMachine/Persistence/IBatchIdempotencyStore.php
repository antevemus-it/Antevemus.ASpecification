<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence;

use Antevemus\ASpecification\StateMachine\TransitionResult;

/**
 * IBatchIdempotencyStore - Many Idempotency Keys Looked Up at Once
 *
 * Optional port (2.10.0) of an idempotency store: MachineRunner::sendMany() asks for the keys of a
 * whole chunk of events in one call (one `IN (...)` query in the PDO store) instead of one find() per
 * event. A store that does not implement it keeps working: sendMany() then calls find() per event.
 * Both stores of the library implement it.
 *
 * @version    2.10.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IBatchIdempotencyStore extends IIdempotencyStore
{
    /**
     * The recorded results of the keys, by instance and key; a key never processed for its instance is
     * absent from the answer. Each result equals what find() gives for the same pair.
     *
     * @param array<string, list<string>> $keysByInstance Instance id => idempotency keys
     * @return array<string, array<string, TransitionResult>> Instance id => key => recorded result
     */
    public function findMany(array $keysByInstance): array;
}
