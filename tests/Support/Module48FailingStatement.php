<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use PDOStatement;

/**
 * A statement of Module 48 (2.14.0, forward 063) whose execute() fails in silence with a given errorInfo() (set as
 * PDO::ATTR_STATEMENT_CLASS), as a driver in ERRMODE_SILENT does.
 *
 * @version 2.14.0
 */
final class Module48FailingStatement extends PDOStatement
{
    /** @var array<int, mixed> */
    public static array $info = [];

    protected function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        return false;
    }

    public function errorInfo(): array
    {
        return self::$info;
    }
}
