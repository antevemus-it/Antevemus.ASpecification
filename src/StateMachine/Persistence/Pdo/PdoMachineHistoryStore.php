<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence\Pdo;

use Antevemus\ASpecification\StateMachine\EventOrigin;
use Antevemus\ASpecification\StateMachine\Exceptions\MachinePersistenceException;
use Antevemus\ASpecification\StateMachine\Persistence\HistoryEntry;
use Antevemus\ASpecification\StateMachine\Persistence\IMachineHistoryStore;
use Antevemus\ASpecification\StateMachine\Persistence\PersistenceSupport;

/**
 * PdoMachineHistoryStore - Append-Only History over PDO
 *
 * Table `state_machine_history` (forward 023, D15): INSERT only, read by instance in write order
 * (instance version, sort order). A duplicate position is a primary key violation.
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence\Pdo
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class PdoMachineHistoryStore implements IMachineHistoryStore
{
    public function __construct(private readonly PdoMachineSchema $schema)
    {
    }

    /** {@inheritdoc} */
    public function append(array $entries): void
    {
        foreach ($entries as $entry) {
            $this->schema->insert('history', [
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
            ]);
        }
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
