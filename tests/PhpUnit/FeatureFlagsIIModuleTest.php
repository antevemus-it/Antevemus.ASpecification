<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\PhpUnit;

use Antevemus\ASpecification\Tests\Unit\Module30_FeatureFlagsIITest;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * FeatureFlagsIIModuleTest - PHPUnit bridge over Module 30 (feature flags II: validity window through the
 * service and percentage rollout, forward 040).
 *
 * Runs Module30_FeatureFlagsIITest, the suite also registered in `tests/run_all.php`, through
 * `vendor/bin/phpunit`, forwarding its assertion count, in the shape of FeatureFlagsModuleTest.
 *
 * @version    2.3.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\PhpUnit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class FeatureFlagsIIModuleTest extends TestCase
{
    public function testModule30FeatureFlagsIIPasses(): void
    {
        $suite = new Module30_FeatureFlagsIITest();

        try {
            $suite->run();
        } catch (Throwable $e) {
            $this->addToAssertionCount($suite->getAssertionCount());
            self::fail(sprintf(
                '%s failed after %d assertions: %s at %s:%d',
                Module30_FeatureFlagsIITest::class,
                $suite->getAssertionCount(),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
        }

        $this->addToAssertionCount($suite->getAssertionCount());
        self::assertGreaterThan(0, $suite->getAssertionCount(), 'Module 30 ran without asserting anything');
    }
}
