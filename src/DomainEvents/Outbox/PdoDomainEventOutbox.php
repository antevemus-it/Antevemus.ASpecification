<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\DomainEvents\Outbox;

use Antevemus\ASpecification\Contracts\Sql\ISqlDialect;
use Antevemus\ASpecification\DomainEvents\DomainEvent;
use Antevemus\ASpecification\DomainEvents\DomainEventKind;
use Antevemus\ASpecification\DomainEvents\Exceptions\DomainEventPersistenceException;
use Antevemus\ASpecification\Sql\Dialects\AbstractSqlDialect;
use Antevemus\ASpecification\Sql\Dialects\SqlDialectFactory;
use Antevemus\ASpecification\Sql\SqlDialect;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * PdoDomainEventOutbox - Domain Event Outbox over PDO
 *
 * Table `domain_event_outbox` of `resources/sql/domain_events.ansi.sql` (forward 029, D5), in the
 * conventions of the 1.8.0 PdoEffectOutbox:
 * - Portable, parameterized SQL; the table name (and optional schema) validated as plain
 *   identifiers; named placeholders used once each (safe under native prepares)
 * - enqueue() checks the idempotency key before inserting (no INSERT ... ON CONFLICT, which is
 *   not portable, and no failed INSERT, which aborts a PostgreSQL transaction); the UNIQUE
 *   constraint of the DDL is the last line of defence when two writers race
 * - Queue heads read with a portable NOT EXISTS (no window function); marks are single-row
 *   UPDATEs by primary key with `attempts = attempts + 1`
 * - Instants in UTC as fixed-width text; the error mode of the connection is left untouched:
 *   failed statements are detected both ways and reported as DomainEventPersistenceException
 *   with the driver message and SQLSTATE
 *
 * Share the PDO connection (and its transaction manager) with the repository that is written, so
 * that the write and its events commit or roll back together.
 *
 * @version    1.11.0
 * @package    Antevemus\ASpecification
 * @subpackage DomainEvents\Outbox
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class PdoDomainEventOutbox implements IDomainEventOutbox
{
    /** Default table name, mirroring the reference DDL. */
    public const DEFAULT_TABLE = 'domain_event_outbox';

    /** Columns of the table, in DDL order. */
    public const COLUMNS = [
        'event_id', 'idempotency_key', 'event_name', 'trigger_name', 'kind', 'entity_class', 'entity_id',
        'payload', 'occurred_at', 'status', 'attempts', 'last_error', 'delivered_at', 'last_attempt_at',
    ];

    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    private readonly string $table;

    private readonly ISqlDialect $dialect;

    /**
     * @param PDO $pdo Connection to the database holding the table (its error mode is left untouched)
     * @param string $table Table name
     * @param string $schema Optional schema (or database) qualifying the table; '' = unqualified
     * @throws InvalidArgumentException When the table or the schema is not a plain SQL identifier
     */
    public function __construct(
        private readonly PDO $pdo,
        string $table = self::DEFAULT_TABLE,
        string $schema = ''
    ) {
        foreach (['table' => $table, 'schema' => $schema] as $what => $name) {
            if (($what === 'table' || $name !== '') && preg_match(self::IDENTIFIER, $name) !== 1) {
                throw new InvalidArgumentException(sprintf('Domain event outbox %s "%s" is not a plain SQL identifier.', $what, $name));
            }
        }
        $this->table = $schema !== '' ? $schema . '.' . $table : $table;
        $this->dialect = SqlDialectFactory::create(SqlDialect::fromDriver((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)));
    }

    /** The connection. */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /** Schema-qualified table name. */
    public function getTable(): string
    {
        return $this->table;
    }

    /** {@inheritdoc} */
    public function enqueue(array $entries): int
    {
        $written = 0;
        foreach ($entries as $entry) {
            if ($this->findByIdempotencyKey($entry->idempotencyKey()) !== null) {
                continue;
            }
            $event = $entry->event;
            $row = [
                'event_id' => $entry->eventId,
                'idempotency_key' => $entry->idempotencyKey(),
                'event_name' => $event->name,
                'trigger_name' => $event->triggerName,
                'kind' => $event->kind->value,
                'entity_class' => $event->entityClass,
                'entity_id' => $event->entityId,
                'payload' => DomainEventSupport::encodeJson($event->getPayload()),
                'occurred_at' => DomainEventSupport::toText($event->occurredAt),
                'status' => $entry->status->value,
                'attempts' => $entry->attempts,
                'last_error' => $entry->lastError,
                'delivered_at' => $entry->deliveredAt === null ? null : DomainEventSupport::toText($entry->deliveredAt),
                'last_attempt_at' => $entry->lastAttemptAt === null ? null : DomainEventSupport::toText($entry->lastAttemptAt),
            ];
            $params = [];
            foreach ($row as $column => $value) {
                $params[':' . $column] = $value;
            }
            $this->execute(sprintf(
                'INSERT INTO %s (%s) VALUES (%s)',
                $this->table,
                implode(', ', array_keys($row)),
                implode(', ', array_keys($params))
            ), $params);
            $written++;
        }

        return $written;
    }

    /** {@inheritdoc} */
    public function fetchPending(int $limit = 100, ?int $maxAttempts = null): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException(sprintf('The limit must be at least 1, %d given.', $limit));
        }
        $params = [];
        $open = static fn(string $alias, string $suffix): string => "{$alias}.status IN ('pending', 'failed')"
            . ($maxAttempts === null ? '' : " AND {$alias}.attempts < :max_attempts_{$suffix}");
        if ($maxAttempts !== null) {
            $params[':max_attempts_o'] = $maxAttempts;
            $params[':max_attempts_p'] = $maxAttempts;
        }
        $sql = sprintf(
            'SELECT %s FROM %s o WHERE %s AND NOT EXISTS (SELECT 1 FROM %s p WHERE p.entity_class = o.entity_class AND p.entity_id = o.entity_id AND %s AND p.event_id < o.event_id) ORDER BY o.event_id',
            $this->selectList('o'),
            $this->table,
            $open('o', 'o'),
            $this->table,
            $open('p', 'p')
        );
        if ($this->dialect instanceof AbstractSqlDialect) {
            $sql = $this->dialect->paginate($sql, $limit);
        }

        return array_slice(array_map($this->hydrate(...), $this->fetchAll($sql, $params)), 0, $limit);
    }

    /** {@inheritdoc} */
    public function markDelivered(DomainEventOutboxEntry $entry, DateTimeImmutable $at): void
    {
        $this->update($entry, 'status = :status, attempts = attempts + 1, delivered_at = :delivered_at, last_attempt_at = :last_attempt_at', [
            ':status' => DomainEventOutboxStatus::DELIVERED->value,
            ':delivered_at' => DomainEventSupport::toText($at),
            ':last_attempt_at' => DomainEventSupport::toText($at),
        ]);
    }

    /** {@inheritdoc} */
    public function markFailed(DomainEventOutboxEntry $entry, string $error, DateTimeImmutable $at): void
    {
        $this->update($entry, 'status = :status, attempts = attempts + 1, last_error = :last_error, last_attempt_at = :last_attempt_at', [
            ':status' => DomainEventOutboxStatus::FAILED->value,
            ':last_error' => DomainEventSupport::truncate($error),
            ':last_attempt_at' => DomainEventSupport::toText($at),
        ]);
    }

    /** {@inheritdoc} */
    public function findByEntity(string $entityClass, string $entityId): array
    {
        return array_map($this->hydrate(...), $this->fetchAll(sprintf(
            'SELECT %s FROM %s WHERE entity_class = :entity_class AND entity_id = :entity_id ORDER BY event_id',
            $this->selectList(''),
            $this->table
        ), [':entity_class' => $entityClass, ':entity_id' => $entityId]));
    }

    /** {@inheritdoc} */
    public function findByIdempotencyKey(string $idempotencyKey): ?DomainEventOutboxEntry
    {
        $rows = $this->fetchAll(sprintf(
            'SELECT %s FROM %s WHERE idempotency_key = :idempotency_key',
            $this->selectList(''),
            $this->table
        ), [':idempotency_key' => $idempotencyKey]);

        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    /** {@inheritdoc} */
    public function count(): int
    {
        return (int) $this->execute(sprintf('SELECT COUNT(*) FROM %s', $this->table))->fetchColumn();
    }

    /**
     * @param array<string, mixed> $params
     */
    private function update(DomainEventOutboxEntry $entry, string $set, array $params): void
    {
        $statement = $this->execute(
            sprintf('UPDATE %s SET %s WHERE event_id = :event_id', $this->table, $set),
            $params + [':event_id' => $entry->eventId]
        );
        if ($statement->rowCount() !== 1) {
            throw new DomainEventPersistenceException(sprintf('Domain event outbox entry %s does not exist.', $entry->eventId));
        }
    }

    private function selectList(string $alias): string
    {
        return implode(', ', array_map(
            static fn(string $c): string => $alias === '' ? $c : $alias . '.' . $c . ' AS ' . $c,
            self::COLUMNS
        ));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): DomainEventOutboxEntry
    {
        $kind = DomainEventKind::tryFrom((string) $row['kind'])
            ?? throw new DomainEventPersistenceException(sprintf('Stored domain event kind "%s" is unknown.', (string) $row['kind']));
        $status = DomainEventOutboxStatus::tryFrom((string) $row['status'])
            ?? throw new DomainEventPersistenceException(sprintf('Stored outbox status "%s" is unknown.', (string) $row['status']));
        try {
            $event = new DomainEvent(
                (string) $row['event_name'],
                (string) $row['trigger_name'],
                $kind,
                (string) $row['entity_class'],
                (string) $row['entity_id'],
                DomainEventSupport::fromText((string) $row['occurred_at'])
            );
        } catch (InvalidArgumentException $e) {
            throw new DomainEventPersistenceException('Stored domain event is invalid: ' . $e->getMessage(), 0, $e);
        }

        return new DomainEventOutboxEntry(
            (string) $row['event_id'],
            $event,
            $status,
            (int) $row['attempts'],
            $row['last_error'] === null ? null : (string) $row['last_error'],
            $row['delivered_at'] === null ? null : DomainEventSupport::fromText((string) $row['delivered_at']),
            $row['last_attempt_at'] === null ? null : DomainEventSupport::fromText((string) $row['last_attempt_at'])
        );
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function fetchAll(string $sql, array $params = []): array
    {
        $rows = $this->execute($sql, $params)->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn(array $row): array => array_change_key_case($row, CASE_LOWER), $rows);
    }

    /**
     * @param array<string, mixed> $params
     * @throws DomainEventPersistenceException
     */
    private function execute(string $sql, array $params = []): PDOStatement
    {
        try {
            $statement = $this->pdo->prepare($sql);
            if (!$statement instanceof PDOStatement) {
                throw $this->driverError($this->pdo->errorInfo());
            }
            foreach ($params as $name => $value) {
                $statement->bindValue($name, $value, match (true) {
                    $value === null => PDO::PARAM_NULL,
                    is_int($value) => PDO::PARAM_INT,
                    default => PDO::PARAM_STR,
                });
            }
            if (!$statement->execute()) {
                throw $this->driverError($statement->errorInfo());
            }
        } catch (PDOException $e) {
            $info = $e->errorInfo;
            throw new DomainEventPersistenceException(
                'Domain event outbox statement failed: ' . $e->getMessage(),
                0,
                $e,
                is_array($info) && isset($info[0]) ? (string) $info[0] : (is_string($e->getCode()) ? $e->getCode() : null)
            );
        }

        return $statement;
    }

    /**
     * @param array<int, mixed> $errorInfo PDO errorInfo() triple
     */
    private function driverError(array $errorInfo): DomainEventPersistenceException
    {
        return new DomainEventPersistenceException(
            sprintf('Domain event outbox statement failed: [%s] %s', (string) ($errorInfo[0] ?? ''), (string) ($errorInfo[2] ?? 'unknown driver error')),
            0,
            null,
            isset($errorInfo[0]) ? (string) $errorInfo[0] : null
        );
    }
}
