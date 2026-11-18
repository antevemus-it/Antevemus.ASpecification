<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\PhpUnit;

use Antevemus\ASpecification\Tests\Unit\Module49_MessagingContractBatteryTest;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * MessagingContractBatteryModuleTest - PHPUnit bridge over Module 49 (the public contract battery of the messaging hook;
 * 2.15.0, forward 064).
 *
 * Runs Module49_MessagingContractBatteryTest, the suite also registered in `tests/run_all.php`, through
 * `vendor/bin/phpunit`, forwarding its assertion count, in the shape of MessagingModuleTest (Module 48).
 *
 * @version    2.15.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\PhpUnit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class MessagingContractBatteryModuleTest extends TestCase
{
    public function testModule49MessagingContractBatteryPasses(): void
    {
        $suite = new Module49_MessagingContractBatteryTest();

        try {
            $suite->run();
        } catch (Throwable $e) {
            $this->addToAssertionCount($suite->getAssertionCount());
            self::fail(sprintf(
                '%s failed after %d assertions: %s at %s:%d',
                Module49_MessagingContractBatteryTest::class,
                $suite->getAssertionCount(),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
        }

        $this->addToAssertionCount($suite->getAssertionCount());
        self::assertGreaterThan(0, $suite->getAssertionCount(), 'Module 49 ran without asserting anything');
    }
}
