<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\PhpUnit;

use Antevemus\ASpecification\Tests\Unit\Module27_FeatureFlagsTest;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * FeatureFlagsModuleTest - PHPUnit bridge over Module 27 (feature flags, forward 034).
 *
 * Runs Module27_FeatureFlagsTest, the suite also registered in `tests/run_all.php`, through
 * `vendor/bin/phpunit`, forwarding its assertion count, in the shape of ModuleSuitesTest.
 *
 * @version    2.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\PhpUnit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class FeatureFlagsModuleTest extends TestCase
{
    public function testModule27FeatureFlagsPasses(): void
    {
        $suite = new Module27_FeatureFlagsTest();

        try {
            $suite->run();
        } catch (Throwable $e) {
            $this->addToAssertionCount($suite->getAssertionCount());
            self::fail(sprintf(
                '%s failed after %d assertions: %s at %s:%d',
                Module27_FeatureFlagsTest::class,
                $suite->getAssertionCount(),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
        }

        $this->addToAssertionCount($suite->getAssertionCount());
        self::assertGreaterThan(0, $suite->getAssertionCount(), 'Module 27 ran without asserting anything');
    }
}
