<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\PhpUnit;

use Antevemus\ASpecification\Tests\Unit\Module42_WriteBehindRepositoryTest;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * WriteBehindRepositoryModuleTest - PHPUnit bridge over Module 42 (the write-behind hybrid repository,
 * roadmap milestone 23).
 *
 * Runs Module42_WriteBehindRepositoryTest, the suite also registered in `tests/run_all.php`, through
 * `vendor/bin/phpunit`, forwarding its assertion count, in the shape of RuleEngineCriteriaAndAsyncDataModuleTest.
 *
 * @version    2.8.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\PhpUnit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class WriteBehindRepositoryModuleTest extends TestCase
{
    public function testModule42WriteBehindRepositoryPasses(): void
    {
        $suite = new Module42_WriteBehindRepositoryTest();

        try {
            $suite->run();
        } catch (Throwable $e) {
            $this->addToAssertionCount($suite->getAssertionCount());
            self::fail(sprintf(
                '%s failed after %d assertions: %s at %s:%d',
                Module42_WriteBehindRepositoryTest::class,
                $suite->getAssertionCount(),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
        }

        $this->addToAssertionCount($suite->getAssertionCount());
        self::assertGreaterThan(0, $suite->getAssertionCount(), 'Module 42 ran without asserting anything');
    }
}
