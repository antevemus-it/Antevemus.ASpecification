<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\PhpUnit;

use Antevemus\ASpecification\Messaging\Testing\IMessagingBench;
use Antevemus\ASpecification\Messaging\Testing\MessagingContractTestCase;
use Antevemus\ASpecification\Tests\Support\SqliteSatellite\SqliteMessagingBench;

/*
 * The contract battery of the core over the didactic SQLite satellite of docs/<language>/messaging/
 * writing-a-satellite.md, example 27.4, transcribed token by token between the markers below (Module 49 checks
 * it and runs every scenario of this class too).
 *
 * @version 2.15.0
 */

// DOC 27.4 >>>
final class SqliteContractTest extends MessagingContractTestCase
{
    protected function createBench(): IMessagingBench
    {
        return new SqliteMessagingBench();
    }

    protected function propertySteps(): int
    {
        return 300; // more than the minimum of the battery (150); fewer fails the scenario
    }
}
// <<< DOC 27.4
