<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Messaging\Outbox;

use Antevemus\ASpecification\Messaging\Exceptions\MessagingPersistenceException;
use Antevemus\ASpecification\Messaging\Message;
use Antevemus\ASpecification\Messaging\Support\MessagingSupport;
use Antevemus\ASpecification\Messaging\Support\PdoMessagingTable;
use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use PDO;

/**
 * PdoMessageOutbox - Message Outbox over PDO
 *
 * Table `message_outbox` of `resources/sql/messaging.ansi.sql` (forward 063, D4), in the conventions
 * of the domain event outbox (1.11.0) and of the state machine stores (column map, 1.8.0):
 * - Portable, parameterized SQL; the table, the optional schema and the real columns validated as
 *   plain identifiers; `columns:` maps a canonical column to the real one of a consumer table
 * - enqueue() checks the message id before inserting (no INSERT ... ON CONFLICT, which is not
 *   portable, and no failed INSERT, which aborts a PostgreSQL transaction); the UNIQUE constraint
 *   of the DDL is the last line of defence when two writers race
 * - Queue heads read with a portable NOT EXISTS (no window function); a row without an ordering
 *   key is always a head; marks are single-row UPDATEs by primary key with `attempts = attempts + 1`
 * - The payload and the headers as JSON text (TEXT columns), instants in UTC as fixed-width text
 * - A failed statement is a MessagingPersistenceException with the SQLSTATE and the driver code,
 *   never the driver message (RN-05: PostgreSQL quotes the failing row, which holds the payload)
 *
 * Share the PDO connection (and its transaction) with the write that produces the facts, so that the
 * write and its messages commit or roll back together (RN-02).
 *
 * @version    2.14.0
 * @package    Antevemus\ASpecification
 * @subpackage Messaging\Outbox
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class PdoMessageOutbox implements IMessageOutbox
{
    /** Default table name, mirroring the reference DDL. */
    public const DEFAULT_TABLE = 'message_outbox';

    /** Canonical columns of the table, in DDL order. */
    public const COLUMNS = [
        'entry_id', 'message_id', 'message_name', 'ordering_key', 'tenant', 'headers', 'payload', 'occurred_at',
        'status', 'attempts', 'last_error', 'enqueued_at', 'delivered_at', 'last_attempt_at',
    ];

    private readonly PdoMessagingTable $table;

    /**
     * @param PDO $pdo Connection to the database holding the table (its error mode is left untouched)
     * @param string $table Table name
     * @param string $schema Optional schema (or database) qualifying the table; '' = unqualified
     * @param array<string, string> $columns Real column by canonical column (COLUMNS); missing ones keep their name
     * @throws InvalidArgumentException When a name is not a plain SQL identifier or a column is unknown
     * @throws \Antevemus\ASpecification\Sql\Exceptions\UnsupportedSqlOperationException When the
     *         driver's dialect does not implement ISqlPagination
     */
    public function __construct(PDO $pdo, string $table = self::DEFAULT_TABLE, string $schema = '', array $columns = [])
    {
        $this->table = new PdoMessagingTable($pdo, $table, $schema, $columns, self::COLUMNS, 'Message outbox', self::class);
    }

    /** The connection. */
    public function getPdo(): PDO
    {
        return $this->table->getPdo();
    }

    /** Schema-qualified table name. */
    public function getTable(): string
    {
        return $this->table->table();
    }

    /**
     * Real column name of every canonical column.
     *
     * @return array<string, string>
     */
    public function getColumns(): array
    {
        return $this->table->getColumns();
    }

    /** {@inheritdoc} */
    public function enqueue(array $entries): int
    {
        $written = 0;
        foreach ($entries as $entry) {
            if ($this->find($entry->messageId()) !== null) {
                continue;
            }
            $message = $entry->message;
            $this->table->insert([
                'entry_id' => $entry->entryId,
                'message_id' => $message->messageId,
                'message_name' => $message->name,
                'ordering_key' => $message->orderingKey,
                'tenant' => $message->tenant,
                'headers' => json_encode((object) $message->headers, MessagingSupport::JSON_FLAGS),
                'payload' => $message->payloadJson(),
                'occurred_at' => MessagingSupport::toText($message->occurredAt),
                'status' => $entry->status->value,
                'attempts' => $entry->attempts,
                'last_error' => $entry->lastError,
                'enqueued_at' => MessagingSupport::toText($entry->enqueuedAt),
                'delivered_at' => $entry->deliveredAt === null ? null : MessagingSupport::toText($entry->deliveredAt),
                'last_attempt_at' => $entry->lastAttemptAt === null ? null : MessagingSupport::toText($entry->lastAttemptAt),
            ], 'enqueue', $message->messageId);
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
        $t = $this->table;
        $params = [];
        $open = static function (string $alias) use ($t, $maxAttempts, &$params): string {
            $condition = sprintf("%s IN ('pending', 'failed')", $t->column('status', $alias));
            if ($maxAttempts !== null) {
                $condition .= sprintf(' AND %s < :max_attempts_%s', $t->column('attempts', $alias), $alias);
                $params[':max_attempts_' . $alias] = $maxAttempts;
            }

            return $condition;
        };
        $sql = sprintf(
            'SELECT %s FROM %s o WHERE %s AND (%s IS NULL OR NOT EXISTS (SELECT 1 FROM %s p WHERE %s = %s AND %s AND %s < %s)) ORDER BY %s',
            $t->selectList('o'),
            $t->table(),
            $open('o'),
            $t->column('ordering_key', 'o'),
            $t->table(),
            $t->column('ordering_key', 'p'),
            $t->column('ordering_key', 'o'),
            $open('p'),
            $t->column('entry_id', 'p'),
            $t->column('entry_id', 'o'),
            $t->column('entry_id', 'o')
        );

        return array_map($this->hydrate(...), $t->fetchAll($t->limit($sql, $limit), $params, 'fetch pending'));
    }

    /** {@inheritdoc} */
    public function markDelivered(MessageOutboxEntry $entry, DateTimeImmutable $at): void
    {
        $this->update($entry, 'mark delivered', [
            'status' => MessageOutboxStatus::DELIVERED->value,
            'delivered_at' => MessagingSupport::toText($at),
            'last_attempt_at' => MessagingSupport::toText($at),
        ]);
    }

    /** {@inheritdoc} */
    public function markFailed(MessageOutboxEntry $entry, string $reason, DateTimeImmutable $at): void
    {
        $this->update($entry, 'mark failed', [
            'status' => MessageOutboxStatus::FAILED->value,
            'last_error' => MessagingSupport::truncate($reason),
            'last_attempt_at' => MessagingSupport::toText($at),
        ]);
    }

    /** {@inheritdoc} */
    public function find(string $messageId): ?MessageOutboxEntry
    {
        $t = $this->table;
        $rows = $t->fetchAll(
            sprintf('SELECT %s FROM %s WHERE %s = :message_id', $t->selectList(), $t->table(), $t->column('message_id')),
            [':message_id' => $messageId],
            'find',
            $messageId
        );

        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    /** {@inheritdoc} */
    public function findByOrderingKey(string $orderingKey): array
    {
        $t = $this->table;

        return array_map($this->hydrate(...), $t->fetchAll(
            sprintf('SELECT %s FROM %s WHERE %s = :ordering_key ORDER BY %s', $t->selectList(), $t->table(), $t->column('ordering_key'), $t->column('entry_id')),
            [':ordering_key' => $orderingKey],
            'find by ordering key'
        ));
    }

    /** {@inheritdoc} */
    public function count(): int
    {
        return (int) $this->table->execute(sprintf('SELECT COUNT(*) FROM %s', $this->table->table()), [], 'count')->fetchColumn();
    }

    /**
     * @param array<string, string> $set Canonical column => value (attempts is always incremented)
     * @throws MessagingPersistenceException
     */
    private function update(MessageOutboxEntry $entry, string $operation, array $set): void
    {
        $t = $this->table;
        $assignments = [sprintf('%1$s = %1$s + 1', $t->column('attempts'))];
        $params = [];
        foreach ($set as $column => $value) {
            $assignments[] = sprintf('%s = :%s', $t->column($column), $column);
            $params[':' . $column] = $value;
        }
        $params[':entry_id'] = $entry->entryId;
        $statement = $t->execute(
            sprintf('UPDATE %s SET %s WHERE %s = :entry_id', $t->table(), implode(', ', $assignments), $t->column('entry_id')),
            $params,
            $operation,
            $entry->messageId()
        );
        if ($statement->rowCount() !== 1) {
            throw new MessagingPersistenceException(sprintf('Message outbox entry %s (%s) does not exist.', $entry->entryId, $entry->message->describe()));
        }
    }

    /**
     * @param array<string, mixed> $row
     * @throws MessagingPersistenceException When the stored row is not a valid entry
     */
    private function hydrate(array $row): MessageOutboxEntry
    {
        $entryId = (string) $row['entry_id'];
        $status = MessageOutboxStatus::tryFrom((string) $row['status'])
            ?? throw new MessagingPersistenceException(sprintf('Stored message outbox entry %s has an unknown status.', $entryId));
        $instant = static fn(mixed $text): ?DateTimeImmutable => $text === null ? null
            : (MessagingSupport::fromText((string) $text)
                ?? throw new MessagingPersistenceException(sprintf('Stored message outbox entry %s has an instant that is not YYYY-MM-DD HH:MM:SS.ffffff.', $entryId)));
        try {
            $headers = json_decode((string) $row['headers'], true, 2, JSON_THROW_ON_ERROR);
            $payload = json_decode((string) $row['payload'], true, Message::MAX_PAYLOAD_DEPTH + 1, JSON_THROW_ON_ERROR);
            $message = new Message(
                (string) $row['message_name'],
                is_array($payload) ? $payload : throw new MessagingPersistenceException(sprintf('Stored message outbox entry %s has a payload that is not a JSON array or object.', $entryId)),
                $row['ordering_key'] === null ? null : (string) $row['ordering_key'],
                $row['tenant'] === null ? null : (string) $row['tenant'],
                is_array($headers) ? $headers : [],
                (string) $row['message_id'],
                $instant($row['occurred_at']) ?? throw new MessagingPersistenceException(sprintf('Stored message outbox entry %s has no occurred_at.', $entryId))
            );

            return new MessageOutboxEntry(
                $entryId,
                $message,
                $instant($row['enqueued_at']) ?? throw new MessagingPersistenceException(sprintf('Stored message outbox entry %s has no enqueued_at.', $entryId)),
                $status,
                (int) $row['attempts'],
                $row['last_error'] === null ? null : (string) $row['last_error'],
                $instant($row['delivered_at']),
                $instant($row['last_attempt_at'])
            );
        } catch (JsonException) {
            throw new MessagingPersistenceException(sprintf('Stored message outbox entry %s has a payload or headers that are not valid JSON (or nested deeper than the message allows).', $entryId));
        } catch (InvalidArgumentException $e) {
            throw new MessagingPersistenceException(sprintf('Stored message outbox entry %s is not a valid message: %s', $entryId, $e->getMessage()));
        }
    }
}
