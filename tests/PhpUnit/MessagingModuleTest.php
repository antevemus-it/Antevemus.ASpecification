<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\PhpUnit;

use Antevemus\ASpecification\Tests\Unit\Module48_MessagingTest;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * MessagingModuleTest - PHPUnit bridge over Module 48 (the broker-agnostic messaging hook;
 * 2.14.0, forward 063).
 *
 * Runs Module48_MessagingTest, the suite also registered in `tests/run_all.php`, through
 * `vendor/bin/phpunit`, forwarding its assertion count, in the shape of AuditedRuleCatalogModuleTest.
 *
 * @version    2.14.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\PhpUnit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class MessagingModuleTest extends TestCase
{
    public function testModule48MessagingPasses(): void
    {
        $suite = new Module48_MessagingTest();

        try {
            $suite->run();
        } catch (Throwable $e) {
            $this->addToAssertionCount($suite->getAssertionCount());
            self::fail(sprintf(
                '%s failed after %d assertions: %s at %s:%d',
                Module48_MessagingTest::class,
                $suite->getAssertionCount(),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
        }

        $this->addToAssertionCount($suite->getAssertionCount());
        self::assertGreaterThan(0, $suite->getAssertionCount(), 'Module 48 ran without asserting anything');
    }
}
