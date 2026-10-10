<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence\Pdo;

use Antevemus\ASpecification\StateMachine\EventOrigin;
use Antevemus\ASpecification\StateMachine\Exceptions\MachinePersistenceException;
use Antevemus\ASpecification\StateMachine\Persistence\IMachineRejectionLog;
use Antevemus\ASpecification\StateMachine\Persistence\PersistenceSupport;
use Antevemus\ASpecification\StateMachine\Persistence\RejectionEntry;
use Antevemus\ASpecification\StateMachine\TransitionStatus;

/**
 * PdoMachineRejectionLog - Optional Rejection Log over PDO
 *
 * Table `state_machine_rejection` (forward 023, RN-07); rows of an instance are read in id order
 * (ULIDs: generation order).
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence\Pdo
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class PdoMachineRejectionLog implements IMachineRejectionLog
{
    public function __construct(private readonly PdoMachineSchema $schema)
    {
    }

    /** {@inheritdoc} */
    public function record(RejectionEntry $entry): void
    {
        $this->schema->insert('rejection', [
            'rejection_id' => $entry->rejectionId,
            'instance_id' => $entry->instanceId,
            'instance_version' => $entry->instanceVersion,
            'machine_key' => $entry->machineKey,
            'machine_version' => $entry->machineVersion,
            'status' => $entry->status->value,
            'event_type' => $entry->eventType,
            'payload' => PersistenceSupport::encodeJson($entry->payload),
            'actor' => $entry->actor,
            'idempotency_key' => $entry->idempotencyKey,
            'event_origin' => $entry->origin->value,
            'reason' => PersistenceSupport::encodeJson($entry->reason),
            'occurred_at' => PersistenceSupport::toText($entry->occurredAt),
        ]);
    }

    /** {@inheritdoc} */
    public function findByInstance(string $instanceId): array
    {
        $rows = $this->schema->fetchAll(sprintf(
            'SELECT %s FROM %s WHERE %s = :instance_id ORDER BY %s',
            $this->schema->selectList(PdoMachineSchema::TABLE_COLUMNS['rejection']),
            $this->schema->table('rejection'),
            $this->schema->column('instance_id'),
            $this->schema->column('rejection_id')
        ), [':instance_id' => $instanceId]);

        return array_map(static function (array $row): RejectionEntry {
            $status = TransitionStatus::tryFrom((string) $row['status'])
                ?? throw new MachinePersistenceException(sprintf('Stored rejection status "%s" is unknown.', (string) $row['status']));
            $origin = EventOrigin::tryFrom((string) $row['event_origin'])
                ?? throw new MachinePersistenceException(sprintf('Stored event origin "%s" is unknown.', (string) $row['event_origin']));

            return new RejectionEntry(
                (string) $row['rejection_id'],
                (string) $row['instance_id'],
                (int) $row['instance_version'],
                (string) $row['machine_key'],
                (int) $row['machine_version'],
                $status,
                (string) $row['event_type'],
                $row['payload'] === null ? [] : PersistenceSupport::decodeJson((string) $row['payload'], 'rejection payload'),
                $row['actor'] === null ? null : (string) $row['actor'],
                $row['idempotency_key'] === null ? null : (string) $row['idempotency_key'],
                $origin,
                PersistenceSupport::decodeJson((string) $row['reason'], 'rejection reason'),
                PersistenceSupport::fromText((string) $row['occurred_at'])
            );
        }, $rows);
    }
}
