<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\PhpUnit;

use Antevemus\ASpecification\Tests\Unit\Module47_AuditedRuleCatalogTest;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * AuditedRuleCatalogModuleTest - PHPUnit bridge over Module 47 (audited, versioned writes to the rule catalog;
 * 2.13.0, forward 060).
 *
 * Runs Module47_AuditedRuleCatalogTest, the suite also registered in `tests/run_all.php`, through
 * `vendor/bin/phpunit`, forwarding its assertion count, in the shape of HttpErrorReasonAndRetryAfterModuleTest.
 *
 * @version    2.13.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\PhpUnit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class AuditedRuleCatalogModuleTest extends TestCase
{
    public function testModule47AuditedRuleCatalogPasses(): void
    {
        $suite = new Module47_AuditedRuleCatalogTest();

        try {
            $suite->run();
        } catch (Throwable $e) {
            $this->addToAssertionCount($suite->getAssertionCount());
            self::fail(sprintf(
                '%s failed after %d assertions: %s at %s:%d',
                Module47_AuditedRuleCatalogTest::class,
                $suite->getAssertionCount(),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
        }

        $this->addToAssertionCount($suite->getAssertionCount());
        self::assertGreaterThan(0, $suite->getAssertionCount(), 'Module 47 ran without asserting anything');
    }
}
