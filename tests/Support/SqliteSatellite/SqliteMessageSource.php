<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support\SqliteSatellite;

use Antevemus\ASpecification\Messaging\Exceptions\MessagingException;
use Antevemus\ASpecification\Messaging\IMessageSource;
use Antevemus\ASpecification\Messaging\ReceivedMessage;
use PDO;
use PDOStatement;
use Throwable;

/*
 * The didactic satellite of docs/<language>/messaging/writing-a-satellite.md, example 27.2, transcribed token by
 * token between the markers below (Module 49 checks it). Not a broker for production.
 *
 * @version 2.15.0
 */

// DOC 27.2 >>>
/**
 * DIDACTIC: one member of one consumer reading the SQLite "broker" of SqliteMessageTransport.
 */
final class SqliteMessageSource implements IMessageSource
{
    /** @param list<string> $names The message names the consumer reads */
    public function __construct(
        private readonly PDO $pdo,
        public readonly string $consumer,
        public readonly array $names,
        public readonly string $member
    ) {
        // Declares the consumer: it reads what is stored from now on (an existing consumer keeps its cursor).
        $this->execute('INSERT OR IGNORE INTO broker_consumer (consumer, cursor) VALUES (?, (SELECT COALESCE(MAX(id), 0) FROM broker_entry))', [$consumer]);
        // A process that starts again gets back what its previous run left in flight.
        $this->recoverFrom($member);
    }

    public function receive(int $max, int $waitMs): array
    {
        $deadline = microtime(true) + $waitMs / 1000;
        while (true) {
            $deliveries = $this->take($max);
            if ($deliveries !== [] || microtime(true) >= $deadline) {
                return $deliveries;
            }
            usleep(10000);
        }
    }

    public function ack(ReceivedMessage $message): void
    {
        $this->answer($message, 'DELETE FROM broker_delivery', []);
    }

    public function reject(ReceivedMessage $message, bool $requeue, string $reason): void
    {
        if ($requeue) {
            $this->answer($message, "UPDATE broker_delivery SET state = 'ready', member = NULL", []);

            return;
        }
        // The dead letter queue keeps the reason and the count; the reason never carries the payload.
        $this->answer($message, "UPDATE broker_delivery SET state = 'dead', member = NULL, reason = ?, dead_seq = (SELECT COALESCE(MAX(dead_seq), 0) + 1 FROM broker_delivery)", [$reason]);
    }

    /** What member $member of this consumer left in flight goes back to the queue (XAUTOCLAIM, a closed channel). */
    public function recoverFrom(string $member): int
    {
        return $this->execute("UPDATE broker_delivery SET state = 'ready', member = NULL WHERE consumer = ? AND state = 'in_flight' AND member = ?", [$this->consumer, $member])->rowCount();
    }

    /** @return list<array{messageId: ?string, reason: string, deliveryCount: int}> */
    public function deadLetters(): array
    {
        $rows = $this->execute("SELECT e.id, e.body, d.reason, d.delivery_count FROM broker_delivery d JOIN broker_entry e ON e.id = d.entry_id WHERE d.consumer = ? AND d.state = 'dead' ORDER BY d.dead_seq", [$this->consumer]);
        $dead = [];
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $delivery = ReceivedMessage::decode((string) $row['body'], (int) $row['delivery_count'], (string) $row['id']);
            $dead[] = ['messageId' => $delivery->isValid() ? $delivery->message()->messageId : null, 'reason' => (string) $row['reason'], 'deliveryCount' => (int) $row['delivery_count']];
        }

        return $dead;
    }

    /** @return list<ReceivedMessage> */
    private function take(int $max): array
    {
        $this->pdo->beginTransaction();
        try {
            // 1. What was given back (rejected with requeue, left by a dead member) comes first, in the order stored.
            $ids = $this->execute("SELECT entry_id FROM broker_delivery WHERE consumer = ? AND state = 'ready' ORDER BY entry_id LIMIT {$max}", [$this->consumer])->fetchAll(PDO::FETCH_COLUMN);
            // 2. Then what this consumer has not seen yet, from its cursor on.
            if (count($ids) < $max) {
                $cursor = (int) $this->execute('SELECT cursor FROM broker_consumer WHERE consumer = ?', [$this->consumer])->fetchColumn();
                $names = implode(', ', array_fill(0, count($this->names), '?'));
                $fresh = $this->execute("SELECT id FROM broker_entry WHERE id > ? AND name IN ({$names}) ORDER BY id LIMIT " . ($max - count($ids)), [$cursor, ...$this->names])->fetchAll(PDO::FETCH_COLUMN);
                foreach ($fresh as $id) {
                    $this->execute("INSERT INTO broker_delivery (consumer, entry_id, state, delivery_count) VALUES (?, ?, 'ready', 0)", [$this->consumer, $id]);
                    $this->execute('UPDATE broker_consumer SET cursor = ? WHERE consumer = ?', [$id, $this->consumer]);
                }
                $ids = [...$ids, ...$fresh];
            }
            $deliveries = [];
            foreach ($ids as $id) {
                $this->execute("UPDATE broker_delivery SET state = 'in_flight', member = ?, delivery_count = delivery_count + 1 WHERE consumer = ? AND entry_id = ?", [$this->member, $this->consumer, $id]);
                $row = $this->execute('SELECT e.body, d.delivery_count FROM broker_entry e JOIN broker_delivery d ON d.entry_id = e.id WHERE d.consumer = ? AND e.id = ?', [$this->consumer, $id])->fetch(PDO::FETCH_ASSOC);
                // Whatever the body is, it is delivered: decode() turns what is not a message into an invalid delivery.
                $deliveries[] = ReceivedMessage::decode((string) $row['body'], (int) $row['delivery_count'], (string) $id);
            }
            $this->pdo->commit();

            return $deliveries;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Answers a delivery this member holds in flight; anything else is a bug of the consumer, made visible. */
    private function answer(ReceivedMessage $message, string $statement, array $values): void
    {
        $id = ctype_digit($message->brokerId) ? (int) $message->brokerId : 0;
        $answered = $this->execute("{$statement} WHERE consumer = ? AND entry_id = ? AND state = 'in_flight' AND member = ?", [...$values, $this->consumer, $id, $this->member])->rowCount();
        if ($answered !== 1) {
            throw new MessagingException(sprintf('Delivery %s is not in flight on member "%s" of consumer "%s": it was already answered, or it came from another one.', $message->brokerId, $this->member, $this->consumer));
        }
    }

    private function execute(string $sql, array $values): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($values);

        return $statement;
    }
}
// <<< DOC 27.2
