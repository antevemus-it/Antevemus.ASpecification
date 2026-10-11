<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use PDO;
use PDOStatement;

/**
 * PDO that records every prepared statement (forward 028 tests: RN-04 statement text, RN-05 query
 * counts). Behaves exactly like PDO otherwise.
 *
 * @version 1.11.0
 */
class PdoRecordingPdo extends PDO
{
    /** @var list<string> */
    public array $statements = [];

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->statements[] = $query;

        return parent::prepare($query, $options);
    }

    public function reset(): void
    {
        $this->statements = [];
    }
}
