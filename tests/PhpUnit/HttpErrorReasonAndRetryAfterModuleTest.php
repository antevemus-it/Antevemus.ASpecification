<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\PhpUnit;

use Antevemus\ASpecification\Tests\Unit\Module46_HttpErrorReasonAndRetryAfterTest;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * HttpErrorReasonAndRetryAfterModuleTest - PHPUnit bridge over Module 46 (the reason and the retry delay of a
 * failed HTTP call; 2.12.0, forward 059).
 *
 * Runs Module46_HttpErrorReasonAndRetryAfterTest, the suite also registered in `tests/run_all.php`, through
 * `vendor/bin/phpunit`, forwarding its assertion count, in the shape of StateMachineAdoptionModuleTest.
 *
 * @version    2.12.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\PhpUnit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class HttpErrorReasonAndRetryAfterModuleTest extends TestCase
{
    public function testModule46HttpErrorReasonAndRetryAfterPasses(): void
    {
        $suite = new Module46_HttpErrorReasonAndRetryAfterTest();

        try {
            $suite->run();
        } catch (Throwable $e) {
            $this->addToAssertionCount($suite->getAssertionCount());
            self::fail(sprintf(
                '%s failed after %d assertions: %s at %s:%d',
                Module46_HttpErrorReasonAndRetryAfterTest::class,
                $suite->getAssertionCount(),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
        }

        $this->addToAssertionCount($suite->getAssertionCount());
        self::assertGreaterThan(0, $suite->getAssertionCount(), 'Module 46 ran without asserting anything');
    }
}
