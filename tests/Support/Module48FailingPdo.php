<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use PDO;
use PDOStatement;
use Throwable;

/**
 * A SQLite connection of Module 48 (2.14.0, forward 063) that fails the way a driver may: prepare() returning false
 * with a given errorInfo() (error mode silent), or prepare() throwing a given PDOException.
 *
 * @version 2.14.0
 */
final class Module48FailingPdo extends PDO
{
    /** @var array<int, mixed>|null */
    public ?array $info = null;

    public ?Throwable $throw = null;

    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }
        if ($this->info !== null) {
            return false;
        }

        return parent::prepare($query, $options);
    }

    public function errorInfo(): array
    {
        return $this->info ?? parent::errorInfo();
    }
}
