<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support\SqliteSatellite;

use Antevemus\ASpecification\Messaging\IMessageSource;
use Antevemus\ASpecification\Messaging\IMessageTransport;
use Antevemus\ASpecification\Messaging\Testing\IMessagingBench;
use LogicException;
use PDO;

/*
 * The didactic satellite of docs/<language>/messaging/writing-a-satellite.md, example 27.3, transcribed token by
 * token between the markers below (Module 49 checks it).
 *
 * @version 2.15.0
 */

// DOC 27.3 >>>
/**
 * DIDACTIC: the bench of the SQLite "broker" under the contract battery of the core.
 */
final class SqliteMessagingBench implements IMessagingBench
{
    private readonly PDO $pdo;

    public function __construct()
    {
        // A database in memory per bench: every scenario gets a broker of its own (isolation).
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        SqliteMessageTransport::declareTables($this->pdo);
    }

    public function transport(): IMessageTransport
    {
        return new SqliteMessageTransport($this->pdo);
    }

    public function transportWithLostConnection(): IMessageTransport
    {
        // A database without the tables of the broker: every send fails, and nothing is stored.
        return new SqliteMessageTransport(new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]));
    }

    public function source(string $consumer, array $names, string $member = 'm1'): IMessageSource
    {
        return new SqliteMessageSource($this->pdo, $consumer, $names, $member);
    }

    public function successor(IMessageSource $source): IMessageSource
    {
        $dead = self::own($source);
        $next = new SqliteMessageSource($this->pdo, $dead->consumer, $dead->names, $dead->member . '-next');
        $next->recoverFrom($dead->member); // Redis: XAUTOCLAIM after an idle time; RabbitMQ: the closed channel

        return $next;
    }

    public function restarted(IMessageSource $source): IMessageSource
    {
        $old = self::own($source);

        return new SqliteMessageSource($this->pdo, $old->consumer, $old->names, $old->member); // recovers on start
    }

    public function storeRaw(string $name, string $body): void
    {
        $this->pdo->prepare('INSERT INTO broker_entry (name, tenant, body) VALUES (?, NULL, ?)')->execute([$name, $body]);
    }

    public function deadLetters(IMessageSource $source): array
    {
        return self::own($source)->deadLetters();
    }

    public function receiveWaitMs(): int
    {
        return 0; // what send() stored is readable at once
    }

    public function close(): void
    {
        // The database lives in memory and goes away with the bench.
    }

    private static function own(IMessageSource $source): SqliteMessageSource
    {
        return $source instanceof SqliteMessageSource ? $source : throw new LogicException('Not a source of this bench.');
    }
}
// <<< DOC 27.3
