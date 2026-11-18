<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support\SqliteSatellite;

use Antevemus\ASpecification\Messaging\Exceptions\MessagingException;
use Antevemus\ASpecification\Messaging\IMessageTransport;
use Antevemus\ASpecification\Messaging\TransportSendResult;
use PDO;
use PDOException;

/*
 * The didactic satellite of docs/<language>/messaging/writing-a-satellite.md, example 27.1, transcribed token by
 * token between the markers below (Module 49 checks it): run by the contract battery in Module 49 and in
 * tests/PhpUnit/SqliteContractTest.php. Not a broker for production.
 *
 * @version 2.15.0
 */

// DOC 27.1 >>>
/**
 * DIDACTIC: a "broker" over SQLite tables, to show what a transport does. Not for production.
 */
final class SqliteMessageTransport implements IMessageTransport
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** The tables of the broker: the log, the cursor of each consumer, the deliveries of each consumer. */
    public static function declareTables(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS broker_entry (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, tenant TEXT NULL, body TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS broker_consumer (consumer TEXT PRIMARY KEY, cursor INTEGER NOT NULL)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS broker_delivery (consumer TEXT NOT NULL, entry_id INTEGER NOT NULL, state TEXT NOT NULL, member TEXT NULL, delivery_count INTEGER NOT NULL, reason TEXT NULL, dead_seq INTEGER NULL, PRIMARY KEY (consumer, entry_id))');
    }

    public function send(array $batch): TransportSendResult
    {
        $stored = [];
        try {
            $this->pdo->beginTransaction();
            $insert = $this->pdo->prepare('INSERT INTO broker_entry (name, tenant, body) VALUES (?, ?, ?)');
            foreach ($batch as $message) {
                // The body is the wire form; the tenant travels beside it, where a consumer could filter on it.
                $insert->execute([$message->name, $message->tenant, $message->toJson()]);
                $stored[$message->messageId] = (string) $this->pdo->lastInsertId();
            }
            $this->pdo->commit(); // stored durably: now, and only now, the batch is confirmed
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            // The message of a driver may quote a value: name the cause by its code, never the payload.
            throw new MessagingException(sprintf('The SQLite broker did not store the batch of %d message(s) (SQLSTATE %s).', count($batch), $e->getCode()));
        }
        $result = new TransportSendResult();
        foreach ($stored as $messageId => $brokerId) {
            $result = $result->withConfirmed($messageId, $brokerId);
        }

        return $result;
    }
}
// <<< DOC 27.1
