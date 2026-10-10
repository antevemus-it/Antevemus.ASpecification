<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence;

use Antevemus\ASpecification\StateMachine\Persistence\InMemory\InMemoryEffectOutbox;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\InMemoryIdempotencyStore;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\InMemoryMachineHistoryStore;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\InMemoryMachineInstanceStore;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\InMemoryMachineRejectionLog;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\InMemoryTransactionManager;
use Antevemus\ASpecification\StateMachine\Persistence\Pdo\PdoEffectOutbox;
use Antevemus\ASpecification\StateMachine\Persistence\Pdo\PdoIdempotencyStore;
use Antevemus\ASpecification\StateMachine\Persistence\Pdo\PdoMachineHistoryStore;
use Antevemus\ASpecification\StateMachine\Persistence\Pdo\PdoMachineInstanceStore;
use Antevemus\ASpecification\StateMachine\Persistence\Pdo\PdoMachineRejectionLog;
use Antevemus\ASpecification\StateMachine\Persistence\Pdo\PdoMachineSchema;
use Antevemus\ASpecification\StateMachine\Persistence\Pdo\PdoTransactionManager;
use PDO;

/**
 * MachinePersistence - The Ports the MachineRunner Writes Through
 *
 * Bundles the persistence ports of forward 023 (D13, D14): instances (CAS), append-only history,
 * outbox, idempotency records, the transaction boundary shared by all of them and the optional
 * rejection log (RN-07, off by default). Two ready-made sets share the same contract and answer
 * the same (§4 item 4): inMemory() and pdo() over `resources/sql/state_machine.ansi.sql`. Ports
 * can also be mixed by hand, as long as they share one transaction boundary.
 *
 * <code>
 * $persistence = MachinePersistence::pdo($pdo);                 // or MachinePersistence::inMemory()
 * $runner = new MachineRunner(Spec::stateMachine($guards), $definitions, $persistence);
 * </code>
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class MachinePersistence
{
    /**
     * @param IMachineInstanceStore $instances Instance store (CAS)
     * @param IMachineHistoryStore $history Append-only history
     * @param IEffectOutbox $outbox Transactional outbox
     * @param IIdempotencyStore $idempotency Processed idempotency keys
     * @param ITransactionManager $transactions Transaction boundary covering every port above
     * @param IMachineRejectionLog|null $rejections Optional rejection log (null = off)
     */
    public function __construct(
        public readonly IMachineInstanceStore $instances,
        public readonly IMachineHistoryStore $history,
        public readonly IEffectOutbox $outbox,
        public readonly IIdempotencyStore $idempotency,
        public readonly ITransactionManager $transactions,
        public readonly ?IMachineRejectionLog $rejections = null
    ) {
    }

    /**
     * In-memory ports sharing one InMemoryTransactionManager (tests and simple use).
     *
     * @param bool $withRejectionLog Whether to keep the optional rejection log
     * @return self
     */
    public static function inMemory(bool $withRejectionLog = false): self
    {
        $instances = new InMemoryMachineInstanceStore();
        $history = new InMemoryMachineHistoryStore();
        $outbox = new InMemoryEffectOutbox();
        $idempotency = new InMemoryIdempotencyStore();
        $rejections = $withRejectionLog ? new InMemoryMachineRejectionLog() : null;
        $participants = [$instances, $history, $outbox, $idempotency];
        if ($rejections !== null) {
            $participants[] = $rejections;
        }

        return new self($instances, $history, $outbox, $idempotency, new InMemoryTransactionManager($participants), $rejections);
    }

    /**
     * PDO ports over the tables of `resources/sql/state_machine.ansi.sql` on one connection.
     *
     * @param PDO $pdo Connection (its error mode is left untouched)
     * @param string $schema Optional schema (or database) qualifying every table
     * @param array<string, string> $tables Table names by role (see PdoMachineSchema::DEFAULT_TABLES)
     * @param array<string, string> $columns Real column names by canonical column
     * @param bool $withRejectionLog Whether to write the optional rejection log
     * @return self
     * @throws \InvalidArgumentException When a name is not a plain SQL identifier or is unknown
     */
    public static function pdo(
        PDO $pdo,
        string $schema = '',
        array $tables = [],
        array $columns = [],
        bool $withRejectionLog = false
    ): self {
        $definition = new PdoMachineSchema($pdo, $schema, $tables, $columns);

        return new self(
            new PdoMachineInstanceStore($definition),
            new PdoMachineHistoryStore($definition),
            new PdoEffectOutbox($definition),
            new PdoIdempotencyStore($definition),
            new PdoTransactionManager($pdo),
            $withRejectionLog ? new PdoMachineRejectionLog($definition) : null
        );
    }
}
