<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Messaging\Testing\IMessagingBench;
use Antevemus\ASpecification\Messaging\Testing\MessagingContractTestCase;

/**
 * The contract battery over Module49FaultyBench (2.15.0, forward 064): run by Module 49 only, one fault at a time
 * ($fault), never discovered by PHPUnit (it lives outside tests/PhpUnit, and its scenarios fail on purpose).
 *
 * @version 2.15.0
 */
final class Module49FaultyContract extends MessagingContractTestCase
{
    public static Module49Fault $fault = Module49Fault::CONFIRMS_WITHOUT_STORING;

    protected function createBench(): IMessagingBench
    {
        return new Module49FaultyBench(self::$fault);
    }
}
