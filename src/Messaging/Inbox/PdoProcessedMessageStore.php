<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Messaging\Inbox;

use Antevemus\ASpecification\Messaging\Exceptions\MessagingPersistenceException;
use Antevemus\ASpecification\Messaging\Message;
use Antevemus\ASpecification\Messaging\Support\MessagingSupport;
use Antevemus\ASpecification\Messaging\Support\PdoMessagingTable;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

/**
 * PdoProcessedMessageStore - Inbox over PDO
 *
 * Table `message_inbox` of `resources/sql/messaging.ansi.sql` (forward 063, D8), in the conventions
 * of PdoMessageOutbox: portable parameterized SQL, the table, schema and columns validated, a column
 * map, the driver message never repeated. markProcessed() reads the key before inserting (a failed
 * INSERT would abort a PostgreSQL transaction); the primary key (consumer, idempotency_key) is the
 * last line of defence when two processes of one consumer race, and then the second one gets a
 * MessagingPersistenceException whose isIntegrityViolation() is true (MessageConsumer requeues the
 * message, which the next delivery finds handled).
 *
 * Give it the connection of the handlers' writes and a PdoTransactionManager over it, so that the
 * row and the effect commit together.
 *
 * @version    2.14.0
 * @package    Antevemus\ASpecification
 * @subpackage Messaging\Inbox
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class PdoProcessedMessageStore implements IProcessedMessageStore
{
    /** Default table name, mirroring the reference DDL. */
    public const DEFAULT_TABLE = 'message_inbox';

    /** Canonical columns of the table, in DDL order. */
    public const COLUMNS = ['consumer', 'idempotency_key', 'message_id', 'message_name', 'tenant', 'processed_at'];

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
        $this->table = new PdoMessagingTable($pdo, $table, $schema, $columns, self::COLUMNS, 'Message inbox', self::class);
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

    /** {@inheritdoc} */
    public function has(string $consumer, string $idempotencyKey): bool
    {
        return $this->find($consumer, $idempotencyKey) !== null;
    }

    /** {@inheritdoc} */
    public function markProcessed(string $consumer, Message $message, DateTimeImmutable $at): bool
    {
        $row = ProcessedMessage::of($consumer, $message, $at);
        if ($this->has($consumer, $row->idempotencyKey)) {
            return false;
        }
        $this->table->insert([
            'consumer' => $row->consumer,
            'idempotency_key' => $row->idempotencyKey,
            'message_id' => $row->messageId,
            'message_name' => $row->messageName,
            'tenant' => $row->tenant,
            'processed_at' => MessagingSupport::toText($row->processedAt),
        ], 'mark processed', $message->messageId);

        return true;
    }

    /** {@inheritdoc} */
    public function find(string $consumer, string $idempotencyKey): ?ProcessedMessage
    {
        $t = $this->table;
        $rows = $t->fetchAll(
            sprintf('SELECT %s FROM %s WHERE %s = :consumer AND %s = :idempotency_key', $t->selectList(), $t->table(), $t->column('consumer'), $t->column('idempotency_key')),
            [':consumer' => $consumer, ':idempotency_key' => $idempotencyKey],
            'find'
        );
        if ($rows === []) {
            return null;
        }
        $row = $rows[0];
        $processedAt = MessagingSupport::fromText((string) $row['processed_at'])
            ?? throw new MessagingPersistenceException(sprintf('Stored inbox row of consumer "%s" has a processed_at that is not YYYY-MM-DD HH:MM:SS.ffffff.', $consumer));
        try {
            return new ProcessedMessage(
                (string) $row['consumer'],
                (string) $row['idempotency_key'],
                (string) $row['message_id'],
                (string) $row['message_name'],
                $row['tenant'] === null ? null : (string) $row['tenant'],
                $processedAt
            );
        } catch (InvalidArgumentException $e) {
            throw new MessagingPersistenceException(sprintf('Stored inbox row is invalid: %s', $e->getMessage()));
        }
    }

    /** {@inheritdoc} */
    public function count(?string $consumer = null): int
    {
        $t = $this->table;
        if ($consumer === null) {
            return (int) $t->execute(sprintf('SELECT COUNT(*) FROM %s', $t->table()), [], 'count')->fetchColumn();
        }

        return (int) $t->execute(
            sprintf('SELECT COUNT(*) FROM %s WHERE %s = :consumer', $t->table(), $t->column('consumer')),
            [':consumer' => $consumer],
            'count'
        )->fetchColumn();
    }

    /** {@inheritdoc} */
    public function purgeProcessedBefore(string $consumer, DateTimeImmutable $before): int
    {
        $t = $this->table;

        return $t->execute(
            sprintf('DELETE FROM %s WHERE %s = :consumer AND %s < :before', $t->table(), $t->column('consumer'), $t->column('processed_at')),
            [':consumer' => $consumer, ':before' => MessagingSupport::toText($before)],
            'purge'
        )->rowCount();
    }
}
