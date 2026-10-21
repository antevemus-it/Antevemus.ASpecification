<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\PhpUnit;

use Antevemus\ASpecification\Tests\Unit\Module43_DistributedCacheTest;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * DistributedCacheModuleTest - PHPUnit bridge over Module 43 (2.8.0: the rule catalog, the feature flags and the
 * routing clusters of a partitioned repository read through a shared PSR-16 cache).
 *
 * Runs Module43_DistributedCacheTest, the suite also registered in `tests/run_all.php`, through
 * `vendor/bin/phpunit`, forwarding its assertion count, in the shape of RuleEngineCriteriaAndAsyncDataModuleTest.
 * Without psr/simple-cache the module checks the library without it (and the transcriptions of its examples).
 *
 * @version    2.8.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\PhpUnit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class DistributedCacheModuleTest extends TestCase
{
    public function testModule43DistributedCachePasses(): void
    {
        $suite = new Module43_DistributedCacheTest();

        try {
            $suite->run();
        } catch (Throwable $e) {
            $this->addToAssertionCount($suite->getAssertionCount());
            self::fail(sprintf(
                '%s failed after %d assertions: %s at %s:%d',
                Module43_DistributedCacheTest::class,
                $suite->getAssertionCount(),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
        }

        $this->addToAssertionCount($suite->getAssertionCount());
        self::assertGreaterThan(0, $suite->getAssertionCount(), 'Module 43 ran without asserting anything');
    }
}
