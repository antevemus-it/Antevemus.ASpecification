<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use PDO;

/**
 * PDO that records every statement executed while ANOTHER statement of the connection still has an
 * open result set (forward 032, D3 tests): the property MySQL unbuffered queries require (one open
 * cursor per connection), checked on any driver. A result set is open from an execute() that returns
 * columns until it is fully fetched, its cursor closed or the statement freed.
 *
 * @version 1.14.0
 */
class PdoCursorTrackingPdo extends PDO
{
    /** @var list<string> "<statement> | while open: <statement>" */
    public array $violations = [];

    /** @var array<int, string> Statement object id => SQL of the open result sets */
    private array $open = [];

    public function __construct(string $dsn, ?string $username = null, ?string $password = null, ?array $options = null)
    {
        parent::__construct($dsn, $username, $password, $options);
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PdoCursorTrackingStatement::class, [$this]]);
    }

    /** Called before a statement executes. */
    public function beforeExecute(int $id, string $sql): void
    {
        unset($this->open[$id]);
        foreach ($this->open as $openSql) {
            $this->violations[] = $sql . ' | while open: ' . $openSql;
        }
    }

    /** Called when a statement returned a result set. */
    public function opened(int $id, string $sql): void
    {
        $this->open[$id] = $sql;
    }

    /** Called when a result set is consumed or closed. */
    public function closed(int $id): void
    {
        unset($this->open[$id]);
    }

    /** Number of result sets open now. */
    public function openCount(): int
    {
        return count($this->open);
    }

    public function reset(): void
    {
        $this->violations = [];
    }
}
