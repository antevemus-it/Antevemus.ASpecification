<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\PhpUnit;

use Antevemus\ASpecification\Tests\Unit\Module45_StateMachineAdoptionTest;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * StateMachineAdoptionModuleTest - PHPUnit bridge over Module 45 (adopting the state machine in a running
 * system: import of processes under way, the effect context, the classification of nextEvents() and the
 * primary reason of a rejection, wide JSON columns and sendMany(); 2.10.0).
 *
 * Runs Module45_StateMachineAdoptionTest, the suite also registered in `tests/run_all.php`, through
 * `vendor/bin/phpunit`, forwarding its assertion count, in the shape of FeatureFlagsIIModuleTest.
 *
 * @version    2.10.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\PhpUnit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class StateMachineAdoptionModuleTest extends TestCase
{
    public function testModule45StateMachineAdoptionPasses(): void
    {
        $suite = new Module45_StateMachineAdoptionTest();

        try {
            $suite->run();
        } catch (Throwable $e) {
            $this->addToAssertionCount($suite->getAssertionCount());
            self::fail(sprintf(
                '%s failed after %d assertions: %s at %s:%d',
                Module45_StateMachineAdoptionTest::class,
                $suite->getAssertionCount(),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
        }

        $this->addToAssertionCount($suite->getAssertionCount());
        self::assertGreaterThan(0, $suite->getAssertionCount(), 'Module 45 ran without asserting anything');
    }
}
