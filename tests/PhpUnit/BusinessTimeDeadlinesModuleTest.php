<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\PhpUnit;

use Antevemus\ASpecification\Tests\Unit\Module41_BusinessTimeDeadlinesTest;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * BusinessTimeDeadlinesModuleTest - PHPUnit bridge over Module 41 (2.7.0: deadlines in business time; the
 * business-day and working-hours calendars, holidays, the pause in working seconds, a deadline crossing a
 * weekend and a holiday, a pause over a weekend, and the parity with the SLA clock of the first real consumer).
 *
 * Runs Module41_BusinessTimeDeadlinesTest, the suite also registered in `tests/run_all.php`, through
 * `vendor/bin/phpunit`, passing on its assertion count, in the shape of RuleEngineCriteriaAndAsyncDataModuleTest.
 *
 * @version    2.7.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\PhpUnit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class BusinessTimeDeadlinesModuleTest extends TestCase
{
    public function testModule41BusinessTimeDeadlinesPasses(): void
    {
        $suite = new Module41_BusinessTimeDeadlinesTest();

        try {
            $suite->run();
        } catch (Throwable $e) {
            $this->addToAssertionCount($suite->getAssertionCount());
            self::fail(sprintf(
                '%s failed after %d assertions: %s at %s:%d',
                Module41_BusinessTimeDeadlinesTest::class,
                $suite->getAssertionCount(),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
        }

        $this->addToAssertionCount($suite->getAssertionCount());
        self::assertGreaterThan(0, $suite->getAssertionCount(), 'Module 41 ran without asserting anything');
    }
}
