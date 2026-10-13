<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\PhpUnit;

use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\Exceptions\MissingExtensionException;
use Antevemus\ASpecification\Specifications\String\TextComparison;
use Antevemus\ASpecification\Tests\Unit\Module28_AccentInsensitiveTextTest;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * AccentInsensitiveTextModuleTest - PHPUnit bridge over Module 28 (accent-insensitive text, forward 035).
 *
 * Runs Module28_AccentInsensitiveTextTest, the suite also registered in `tests/run_all.php`, through
 * `vendor/bin/phpunit`, forwarding its assertion count, in the shape of ModuleSuitesTest. The module
 * checks the refusal when ext-intl is missing and the whole battery when it is loaded; the two tests
 * below make that split visible in PHPUnit: one requires ext-intl, the other is skipped where it is
 * loaded.
 *
 * @version    2.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\PhpUnit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class AccentInsensitiveTextModuleTest extends TestCase
{
    public function testModule28AccentInsensitiveTextPasses(): void
    {
        $suite = new Module28_AccentInsensitiveTextTest();

        try {
            $suite->run();
        } catch (Throwable $e) {
            $this->addToAssertionCount($suite->getAssertionCount());
            self::fail(sprintf(
                '%s failed after %d assertions: %s at %s:%d',
                Module28_AccentInsensitiveTextTest::class,
                $suite->getAssertionCount(),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
        }

        $this->addToAssertionCount($suite->getAssertionCount());
        self::assertGreaterThan(0, $suite->getAssertionCount(), 'Module 28 ran without asserting anything');
    }

    #[RequiresPhpExtension('intl')]
    public function testIgnoreCaseAndAccentsFindsTheAccentedWord(): void
    {
        $search = Spec::contains('configuracao', TextComparison::IgnoreCaseAndAccents);

        self::assertTrue($search->isSatisfiedBy('Configuração'));
        self::assertTrue($search->isSatisfiedBy('CONFIGURAÇÃO'));
        self::assertFalse(Spec::contains('configuracao', false)->isSatisfiedBy('Configuração'));
        self::assertFalse(Spec::contains('strasse', TextComparison::IgnoreCaseAndAccents)->isSatisfiedBy('Straße'));
        self::assertTrue(Spec::equalIgnoreCaseAndAccents('sao paulo')->isSatisfiedBy('São Paulo'));
    }

    public function testIgnoreCaseAndAccentsIsRefusedWithoutIntl(): void
    {
        if (extension_loaded('intl')) {
            self::markTestSkipped('ext-intl is loaded; the refusal is checked where it is missing.');
        }

        $this->expectException(MissingExtensionException::class);
        $this->expectExceptionMessage('ext-intl');
        Spec::contains('configuracao', TextComparison::IgnoreCaseAndAccents);
    }
}
