<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Messaging\Testing\InMemoryMessagingBench;
use Antevemus\ASpecification\Messaging\Testing\IMessagingBench;
use Antevemus\ASpecification\Messaging\Testing\MessagingContractTestCase;

/**
 * A satellite that lowers the steps of the property sequence below the minimum of the battery (2.15.0, forward
 * 064): Module 49 requires the property scenario to fail.
 *
 * @version 2.15.0
 */
final class Module49LowStepsContract extends MessagingContractTestCase
{
    protected function createBench(): IMessagingBench
    {
        return new InMemoryMessagingBench();
    }

    protected function propertySteps(): int
    {
        return self::MIN_PROPERTY_STEPS - 1;
    }
}
