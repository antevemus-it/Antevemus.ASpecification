<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\PhpUnit;

use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\Exceptions\MissingExtensionException;
use Antevemus\ASpecification\Specifications\String\TextComparison;
use Antevemus\ASpecification\Tests\Unit\Module29_WildcardRegexAccentInsensitiveTest;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * WildcardRegexAccentInsensitiveModuleTest - PHPUnit bridge over Module 29 (wildcard and regex ignoring
 * accents, forward 039).
 *
 * Runs Module29_WildcardRegexAccentInsensitiveTest, the suite also registered in `tests/run_all.php`,
 * through `vendor/bin/phpunit`, forwarding its assertion count, in the shape of
 * AccentInsensitiveTextModuleTest. The module checks the refusal when ext-intl is missing and the whole
 * battery when it is loaded; the two tests below make that split visible in PHPUnit.
 *
 * @version    2.3.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\PhpUnit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class WildcardRegexAccentInsensitiveModuleTest extends TestCase
{
    public function testModule29WildcardRegexAccentInsensitivePasses(): void
    {
        $suite = new Module29_WildcardRegexAccentInsensitiveTest();

        try {
            $suite->run();
        } catch (Throwable $e) {
            $this->addToAssertionCount($suite->getAssertionCount());
            self::fail(sprintf(
                '%s failed after %d assertions: %s at %s:%d',
                Module29_WildcardRegexAccentInsensitiveTest::class,
                $suite->getAssertionCount(),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
        }

        $this->addToAssertionCount($suite->getAssertionCount());
        self::assertGreaterThan(0, $suite->getAssertionCount(), 'Module 29 ran without asserting anything');
    }

    #[RequiresPhpExtension('intl')]
    public function testWildcardAndRegexIgnoringAccentsFindTheAccentedWord(): void
    {
        $glob = Spec::wildcard('*configura*', TextComparison::IgnoreCaseAndAccents);
        $regex = Spec::regex('/^cora[cç][aã]o$/', TextComparison::IgnoreCaseAndAccents);

        self::assertTrue($glob->isSatisfiedBy('Configuração'));
        self::assertTrue(Spec::regex('/^configura/', TextComparison::IgnoreCaseAndAccents)->isSatisfiedBy('CONFIGURAÇÃO'));
        self::assertTrue($regex->isSatisfiedBy('CORAÇÃO'));
        self::assertTrue($regex->isSatisfiedBy('coracao'));
        self::assertFalse(Spec::wildcard('*configuracao*', false)->isSatisfiedBy('Configuração'));
        self::assertFalse(Spec::regex('/\D/', TextComparison::IgnoreCaseAndAccents)->isSatisfiedBy('2026'));
    }

    public function testIgnoreCaseAndAccentsIsRefusedWithoutIntl(): void
    {
        if (extension_loaded('intl')) {
            self::markTestSkipped('ext-intl is loaded; the refusal is checked where it is missing.');
        }

        $this->expectException(MissingExtensionException::class);
        $this->expectExceptionMessage('ext-intl');
        Spec::regex('/^configura/', TextComparison::IgnoreCaseAndAccents);
    }
}
