<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\PhpUnit;

use Antevemus\ASpecification\Tests\Unit\Module50_OutboxLockAndRetentionTest;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * OutboxLockAndRetentionModuleTest - PHPUnit bridge over Module 50 (the dispatchers of the three outboxes in production:
 * one at a time with a lease lock, a failure mark that never reopens a delivery, the retention; 2.16.0, forward 065).
 *
 * Runs Module50_OutboxLockAndRetentionTest, the suite also registered in `tests/run_all.php`, through
 * `vendor/bin/phpunit`, forwarding its assertion count, in the shape of MessagingModuleTest (Module 48).
 *
 * @version    2.16.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\PhpUnit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class OutboxLockAndRetentionModuleTest extends TestCase
{
    public function testModule50OutboxLockAndRetentionPasses(): void
    {
        $suite = new Module50_OutboxLockAndRetentionTest();

        try {
            $suite->run();
        } catch (Throwable $e) {
            $this->addToAssertionCount($suite->getAssertionCount());
            self::fail(sprintf(
                '%s failed after %d assertions: %s at %s:%d',
                Module50_OutboxLockAndRetentionTest::class,
                $suite->getAssertionCount(),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
        }

        $this->addToAssertionCount($suite->getAssertionCount());
        self::assertGreaterThan(0, $suite->getAssertionCount(), 'Module 50 ran without asserting anything');
    }
}
