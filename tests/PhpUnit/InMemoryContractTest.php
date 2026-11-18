<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\PhpUnit;

use Antevemus\ASpecification\Messaging\Testing\IMessagingBench;
use Antevemus\ASpecification\Messaging\Testing\InMemoryMessagingBench;
use Antevemus\ASpecification\Messaging\Testing\MessagingContractTestCase;

/*
 * The contract battery of the core over its in-memory reference: example 27 of docs/<language>/messaging/
 * writing-a-satellite.md, transcribed token by token between the markers below (Module 49 checks it and runs every
 * scenario of this class too).
 *
 * @version 2.15.0
 */

// DOC 27 >>>
final class InMemoryContractTest extends MessagingContractTestCase
{
    protected function createBench(): IMessagingBench
    {
        return new InMemoryMessagingBench();
    }
}
// <<< DOC 27
