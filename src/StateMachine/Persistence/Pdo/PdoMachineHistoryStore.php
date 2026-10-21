<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence\Pdo;

use Antevemus\ASpecification\StateMachine\DefinitionSupport;
use Antevemus\ASpecification\StateMachine\EventOrigin;
use Antevemus\ASpecification\StateMachine\Exceptions\MachinePersistenceException;
use Antevemus\ASpecification\StateMachine\Persistence\HistoryEntry;
use Antevemus\ASpecification\StateMachine\Persistence\IMachineDefinitionRepository;
use Antevemus\ASpecification\StateMachine\Persistence\IMachineHistoryStore;
use Antevemus\ASpecification\StateMachine\Persistence\PersistenceSupport;
use Antevemus\ASpecification\StateMachine\Runner\MachineRunner;
use InvalidArgumentException;

/**
 * PdoMachineHistoryStore - Append-Only History over PDO
 *
 * Table `state_machine_history` (forward 023, D15): INSERT only, read by instance in write order
 * (instance version, sort order). A duplicate position is a primary key violation.
 *
 * Since 2.7.0, with the opt-in columns `from_state_code` and `to_state_code` mapped
 * (PdoMachineSchema::COLUMNS_SINCE_2_7), each row also carries the legacy codes of its states, derived
 * from the definition the step ran on (for the source state of a migration row, from the version the
 * instance left), as integers when the codes are, so a report can join the legacy tables in plain SQL.
 * The store then needs the definition repository; mapping the columns without it is refused on
 * construction. The codes are a projection: findByInstance() reads the rows as before.
 *
 * @version    2.7.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence\Pdo
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class PdoMachineHistoryStore implements IMachineHistoryStore
{
    /**
     * @param PdoMachineSchema $schema Tables, column map and statements
     * @param IMachineDefinitionRepository|null $definitions Definitions the steps ran on; required when the
     *                                                      code columns are mapped (2.7.0)
     * @throws InvalidArgumentException When a code column is mapped without a definition repository
     */
    public function __construct(
        private readonly PdoMachineSchema $schema,
        private readonly ?IMachineDefinitionRepository $definitions = null
    ) {
        if (($schema->hasColumn('from_state_code') || $schema->hasColumn('to_state_code')) && $definitions === null) {
            throw new InvalidArgumentException(
                'The columns from_state_code/to_state_code are mapped, but the history store has no definition repository to derive the state codes from: pass it (MachinePersistence::pdo(..., definitions: $repository)).'
            );
        }
    }

    /** {@inheritdoc} */
    public function append(array $entries): void
    {
        foreach ($entries as $entry) {
            $row = [
                'instance_id' => $entry->instanceId,
                'instance_version' => $entry->instanceVersion,
                'sort_order' => $entry->sortOrder,
                'machine_key' => $entry->machineKey,
                'machine_version' => $entry->machineVersion,
                'region' => $entry->region,
                'from_state' => $entry->fromState,
                'to_state' => $entry->toState,
                'event_type' => $entry->eventType,
                'transition_id' => $entry->transitionId,
                'payload' => PersistenceSupport::encodeJson($entry->payload),
                'actor' => $entry->actor,
                'idempotency_key' => $entry->idempotencyKey,
                'event_origin' => $entry->origin->value,
                'occurred_at' => PersistenceSupport::toText($entry->occurredAt),
            ];
            if ($this->schema->hasColumn('from_state_code')) {
                $fromVersion = $entry->eventType === MachineRunner::MIGRATE_EVENT && is_int($entry->payload['from_version'] ?? null)
                    ? $entry->payload['from_version']
                    : $entry->machineVersion;
                $row['from_state_code'] = $entry->fromState === null ? null : $this->code($entry->machineKey, $fromVersion, $entry->region, $entry->fromState);
            }
            if ($this->schema->hasColumn('to_state_code')) {
                $row['to_state_code'] = $this->code($entry->machineKey, $entry->machineVersion, $entry->region, $entry->toState);
            }
            $this->schema->insert('history', $row);
        }
    }

    /**
     * The normalized legacy code of a state of a definition version, or null.
     */
    private function code(string $machineKey, int $version, string $region, string $state): int|string|null
    {
        $code = $this->definitions?->get($machineKey, $version)->codeOf($region, $state);

        return $code === null ? null : DefinitionSupport::normalizeCode($code);
    }

    /** {@inheritdoc} */
    public function findByInstance(string $instanceId): array
    {
        $rows = $this->schema->fetchAll(sprintf(
            'SELECT %s FROM %s WHERE %s = :instance_id ORDER BY %s, %s',
            $this->schema->selectList(PdoMachineSchema::TABLE_COLUMNS['history']),
            $this->schema->table('history'),
            $this->schema->column('instance_id'),
            $this->schema->column('instance_version'),
            $this->schema->column('sort_order')
        ), [':instance_id' => $instanceId]);

        return array_map(static function (array $row): HistoryEntry {
            $origin = EventOrigin::tryFrom((string) $row['event_origin'])
                ?? throw new MachinePersistenceException(sprintf('Stored event origin "%s" is unknown.', (string) $row['event_origin']));

            return new HistoryEntry(
                (string) $row['instance_id'],
                (int) $row['instance_version'],
                (int) $row['sort_order'],
                (string) $row['machine_key'],
                (int) $row['machine_version'],
                (string) $row['region'],
                $row['from_state'] === null ? null : (string) $row['from_state'],
                (string) $row['to_state'],
                (string) $row['event_type'],
                $row['transition_id'] === null ? null : (string) $row['transition_id'],
                $row['payload'] === null ? [] : PersistenceSupport::decodeJson((string) $row['payload'], 'history payload'),
                $row['actor'] === null ? null : (string) $row['actor'],
                $row['idempotency_key'] === null ? null : (string) $row['idempotency_key'],
                $origin,
                PersistenceSupport::fromText((string) $row['occurred_at'])
            );
        }, $rows);
    }
}
