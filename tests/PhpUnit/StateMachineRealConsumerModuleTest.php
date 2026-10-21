<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\PhpUnit;

use Antevemus\ASpecification\Tests\Unit\Module40_StateMachineRealConsumerTest;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * StateMachineRealConsumerModuleTest - PHPUnit bridge over Module 40 (the state machine for a real consumer:
 * legacy state codes, versioned definitions with drafts over PDO, tenants; 2.7.0).
 *
 * Runs Module40_StateMachineRealConsumerTest, the suite also registered in `tests/run_all.php`, through
 * `vendor/bin/phpunit`, forwarding its assertion count, in the shape of FeatureFlagsIIModuleTest.
 *
 * @version    2.7.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\PhpUnit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class StateMachineRealConsumerModuleTest extends TestCase
{
    public function testModule40StateMachineRealConsumerPasses(): void
    {
        $suite = new Module40_StateMachineRealConsumerTest();

        try {
            $suite->run();
        } catch (Throwable $e) {
            $this->addToAssertionCount($suite->getAssertionCount());
            self::fail(sprintf(
                '%s failed after %d assertions: %s at %s:%d',
                Module40_StateMachineRealConsumerTest::class,
                $suite->getAssertionCount(),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
        }

        $this->addToAssertionCount($suite->getAssertionCount());
        self::assertGreaterThan(0, $suite->getAssertionCount(), 'Module 40 ran without asserting anything');
    }
}
