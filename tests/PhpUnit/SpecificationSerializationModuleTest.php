<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\PhpUnit;

use Antevemus\ASpecification\Tests\Unit\Module31_SpecificationSerializationTest;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * SpecificationSerializationModuleTest - PHPUnit bridge over Module 31 (serialization of specifications and
 * feature flags targeted by a catalog rule, forward 041).
 *
 * Runs Module31_SpecificationSerializationTest, the suite also registered in `tests/run_all.php`, through
 * `vendor/bin/phpunit`, forwarding its assertion count, in the shape of FeatureFlagsModuleTest.
 *
 * @version    2.4.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\PhpUnit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class SpecificationSerializationModuleTest extends TestCase
{
    public function testModule31SpecificationSerializationPasses(): void
    {
        $suite = new Module31_SpecificationSerializationTest();

        try {
            $suite->run();
        } catch (Throwable $e) {
            $this->addToAssertionCount($suite->getAssertionCount());
            self::fail(sprintf(
                '%s failed after %d assertions: %s at %s:%d',
                Module31_SpecificationSerializationTest::class,
                $suite->getAssertionCount(),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
        }

        $this->addToAssertionCount($suite->getAssertionCount());
        self::assertGreaterThan(0, $suite->getAssertionCount(), 'Module 31 ran without asserting anything');
    }
}
