<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\PhpUnit;

use Antevemus\ASpecification\Tests\Unit\Module36_RuleEngineCriteriaAndAsyncDataTest;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * RuleEngineCriteriaAndAsyncDataModuleTest - PHPUnit bridge over Module 36 (forward 048: lookup-only rule
 * types, the most specific rule, target labels, TCriteria without accents and with subqueries, asynchronous
 * data sources, Adianti as the host).
 *
 * Runs Module36_RuleEngineCriteriaAndAsyncDataTest, the suite also registered in `tests/run_all.php`, through
 * `vendor/bin/phpunit`, forwarding its assertion count, in the shape of SpecificationSerializationModuleTest.
 *
 * @version    2.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\PhpUnit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class RuleEngineCriteriaAndAsyncDataModuleTest extends TestCase
{
    public function testModule36RuleEngineCriteriaAndAsyncDataPasses(): void
    {
        $suite = new Module36_RuleEngineCriteriaAndAsyncDataTest();

        try {
            $suite->run();
        } catch (Throwable $e) {
            $this->addToAssertionCount($suite->getAssertionCount());
            self::fail(sprintf(
                '%s failed after %d assertions: %s at %s:%d',
                Module36_RuleEngineCriteriaAndAsyncDataTest::class,
                $suite->getAssertionCount(),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
        }

        $this->addToAssertionCount($suite->getAssertionCount());
        self::assertGreaterThan(0, $suite->getAssertionCount(), 'Module 36 ran without asserting anything');
    }
}
