<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence\Pdo;

use Antevemus\ASpecification\StateMachine\Effect;
use Antevemus\ASpecification\StateMachine\EffectPhase;
use Antevemus\ASpecification\StateMachine\Exceptions\MachinePersistenceException;
use Antevemus\ASpecification\StateMachine\Persistence\IEffectOutbox;
use Antevemus\ASpecification\StateMachine\Persistence\OutboxEntry;
use Antevemus\ASpecification\StateMachine\Persistence\OutboxStatus;
use Antevemus\ASpecification\StateMachine\Persistence\PersistenceSupport;
use DateTimeImmutable;

/**
 * PdoEffectOutbox - Transactional Outbox over PDO
 *
 * Table `state_machine_outbox` (forward 023, D12, RN-10). The queue heads are read with a
 * portable NOT EXISTS (no window function): an entry is a head when no earlier entry of the same
 * instance is still undelivered and not exhausted. Marks are single-row UPDATEs by primary key
 * with `attempts = attempts + 1`.
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence\Pdo
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class PdoEffectOutbox implements IEffectOutbox
{
    public function __construct(private readonly PdoMachineSchema $schema)
    {
    }

    /** {@inheritdoc} */
    public function enqueue(array $entries): void
    {
        foreach ($entries as $entry) {
            $this->schema->insert('outbox', [
                'instance_id' => $entry->instanceId,
                'instance_version' => $entry->instanceVersion,
                'sort_order' => $entry->sortOrder,
                'machine_key' => $entry->machineKey,
                'machine_version' => $entry->machineVersion,
                'action_name' => $entry->effect->action,
                'params' => PersistenceSupport::encodeJson($entry->effect->params),
                'region' => $entry->effect->region,
                'phase' => $entry->effect->phase->value,
                'state_name' => $entry->effect->state,
                'transition_id' => $entry->effect->transitionId,
                'status' => $entry->status->value,
                'attempts' => $entry->attempts,
                'last_error' => $entry->lastError,
                'enqueued_at' => PersistenceSupport::toText($entry->enqueuedAt),
                'delivered_at' => $entry->deliveredAt === null ? null : PersistenceSupport::toText($entry->deliveredAt),
                'last_attempt_at' => $entry->lastAttemptAt === null ? null : PersistenceSupport::toText($entry->lastAttemptAt),
            ]);
        }
    }

    /** {@inheritdoc} */
    public function fetchPending(int $limit = 100, ?int $maxAttempts = null): array
    {
        $s = $this->schema;
        $status = $s->column('status');
        $attempts = $s->column('attempts');
        $instance = $s->column('instance_id');
        $version = $s->column('instance_version');
        $order = $s->column('sort_order');
        $params = [];
        $open = static fn(string $alias, string $suffix) => "{$alias}.{$status} IN ('pending', 'failed')"
            . ($maxAttempts === null ? '' : " AND {$alias}.{$attempts} < :max_attempts_{$suffix}");
        if ($maxAttempts !== null) {
            $params[':max_attempts_o'] = $maxAttempts;
            $params[':max_attempts_p'] = $maxAttempts;
        }

        $sql = $s->limit(sprintf(
            'SELECT %s FROM %s o WHERE %s AND NOT EXISTS (SELECT 1 FROM %s p WHERE p.%s = o.%s AND %s AND (p.%s < o.%s OR (p.%s = o.%s AND p.%s < o.%s))) ORDER BY o.%s, o.%s',
            $s->selectList(PdoMachineSchema::TABLE_COLUMNS['outbox'], 'o'),
            $s->table('outbox'),
            $open('o', 'o'),
            $s->table('outbox'),
            $instance,
            $instance,
            $open('p', 'p'),
            $version,
            $version,
            $version,
            $version,
            $order,
            $order,
            $s->column('enqueued_at'),
            $instance
        ), $limit);

        return array_map($this->hydrate(...), $s->fetchAll($sql, $params));
    }

    /** {@inheritdoc} */
    public function markDelivered(OutboxEntry $entry, DateTimeImmutable $at): void
    {
        $s = $this->schema;
        $this->update($entry, sprintf(
            '%s = :status, %s = %s + 1, %s = :delivered_at, %s = :last_attempt_at',
            $s->column('status'),
            $s->column('attempts'),
            $s->column('attempts'),
            $s->column('delivered_at'),
            $s->column('last_attempt_at')
        ), [
            ':status' => OutboxStatus::DELIVERED->value,
            ':delivered_at' => PersistenceSupport::toText($at),
            ':last_attempt_at' => PersistenceSupport::toText($at),
        ]);
    }

    /** {@inheritdoc} */
    public function markFailed(OutboxEntry $entry, string $error, DateTimeImmutable $at): void
    {
        $s = $this->schema;
        $this->update($entry, sprintf(
            '%s = :status, %s = %s + 1, %s = :last_error, %s = :last_attempt_at',
            $s->column('status'),
            $s->column('attempts'),
            $s->column('attempts'),
            $s->column('last_error'),
            $s->column('last_attempt_at')
        ), [
            ':status' => OutboxStatus::FAILED->value,
            ':last_error' => $error,
            ':last_attempt_at' => PersistenceSupport::toText($at),
        ]);
    }

    /** {@inheritdoc} */
    public function findByInstance(string $instanceId): array
    {
        $rows = $this->schema->fetchAll(sprintf(
            'SELECT %s FROM %s WHERE %s = :instance_id ORDER BY %s, %s',
            $this->schema->selectList(PdoMachineSchema::TABLE_COLUMNS['outbox']),
            $this->schema->table('outbox'),
            $this->schema->column('instance_id'),
            $this->schema->column('instance_version'),
            $this->schema->column('sort_order')
        ), [':instance_id' => $instanceId]);

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function update(OutboxEntry $entry, string $set, array $params): void
    {
        $s = $this->schema;
        $statement = $s->execute(sprintf(
            'UPDATE %s SET %s WHERE %s = :instance_id AND %s = :instance_version AND %s = :sort_order',
            $s->table('outbox'),
            $set,
            $s->column('instance_id'),
            $s->column('instance_version'),
            $s->column('sort_order')
        ), $params + [
            ':instance_id' => $entry->instanceId,
            ':instance_version' => $entry->instanceVersion,
            ':sort_order' => $entry->sortOrder,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new MachinePersistenceException(sprintf('Outbox entry %s does not exist.', $entry->effectKey()));
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): OutboxEntry
    {
        $phase = EffectPhase::tryFrom((string) $row['phase'])
            ?? throw new MachinePersistenceException(sprintf('Stored effect phase "%s" is unknown.', (string) $row['phase']));
        $status = OutboxStatus::tryFrom((string) $row['status'])
            ?? throw new MachinePersistenceException(sprintf('Stored outbox status "%s" is unknown.', (string) $row['status']));

        return new OutboxEntry(
            (string) $row['instance_id'],
            (int) $row['instance_version'],
            (int) $row['sort_order'],
            (string) $row['machine_key'],
            (int) $row['machine_version'],
            new Effect(
                (string) $row['action_name'],
                $row['params'] === null ? [] : PersistenceSupport::decodeJson((string) $row['params'], 'effect params'),
                (string) $row['region'],
                $phase,
                (string) $row['state_name'],
                $row['transition_id'] === null ? null : (string) $row['transition_id']
            ),
            $status,
            (int) $row['attempts'],
            $row['last_error'] === null ? null : (string) $row['last_error'],
            PersistenceSupport::fromText((string) $row['enqueued_at']),
            $row['delivered_at'] === null ? null : PersistenceSupport::fromText((string) $row['delivered_at']),
            $row['last_attempt_at'] === null ? null : PersistenceSupport::fromText((string) $row['last_attempt_at'])
        );
    }
}
