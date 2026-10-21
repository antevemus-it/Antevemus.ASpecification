<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\PhpUnit;

use Antevemus\ASpecification\Tests\Unit\Module44_IntegerLeavesTest;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * IntegerLeavesModuleTest - PHPUnit bridge over Module 44 (forward 044, milestone 25: the optional integer mode
 * of the relational leaves, asInteger() and Spec::int()).
 *
 * Runs Module44_IntegerLeavesTest, the suite also registered in `tests/run_all.php`, through
 * `vendor/bin/phpunit`, forwarding its assertion count, in the shape of RuleEngineCriteriaAndAsyncDataModuleTest.
 *
 * @version    2.9.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\PhpUnit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class IntegerLeavesModuleTest extends TestCase
{
    public function testModule44IntegerLeavesPasses(): void
    {
        $suite = new Module44_IntegerLeavesTest();

        try {
            $suite->run();
        } catch (Throwable $e) {
            $this->addToAssertionCount($suite->getAssertionCount());
            self::fail(sprintf(
                '%s failed after %d assertions: %s at %s:%d',
                Module44_IntegerLeavesTest::class,
                $suite->getAssertionCount(),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
        }

        $this->addToAssertionCount($suite->getAssertionCount());
        self::assertGreaterThan(0, $suite->getAssertionCount(), 'Module 44 ran without asserting anything');
    }
}
