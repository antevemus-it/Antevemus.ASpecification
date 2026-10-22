<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\PhpUnit;

use Antevemus\ASpecification\Repositories\Pdo\PdoValueConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PdoValueConverterCanonicalDecimalTest - the canonical text of a decimal aggregate (2.11.0).
 *
 * canonicalDecimal() moved from PdoRepository to PdoValueConverter with the same body. The expected texts are
 * the ones the 2.10.0 code gave for what the aggregates hand it: the decimal text of PostgreSQL/MySQL (trailing
 * zeros, a leading dot, a sign, spaces), the float of SQLite (CAST AS REAL: rounded to 15 significant digits,
 * never in exponent form) and an integer.
 *
 * @version 2.11.0
 */
final class PdoValueConverterCanonicalDecimalTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function values(): iterable
    {
        yield 'trailing zeros' => ['12.3400', '12.34'];
        yield 'only zeros after the point' => ['12.0000', '12'];
        yield 'integer text' => ['100', '100'];
        yield 'negative' => ['-0.50', '-0.5'];
        yield 'leading point' => ['.5', '0.5'];
        yield 'negative leading point' => ['-.5', '-0.5'];
        yield 'zero' => ['0.000', '0'];
        yield 'negative zero' => ['-0.000', '0'];
        yield 'empty' => ['', '0'];
        yield 'spaces' => [' 7.10 ', '7.1'];
        yield 'beyond the float precision' => ['12345678901234567890.120', '12345678901234567890.12'];
        yield 'float' => [12.34, '12.34'];
        yield 'float sum error' => [0.1 + 0.2, '0.3'];
        yield 'large float' => [1.0E+20, '100000000000000000000'];
        yield 'small float' => [1.0E-7, '0.0000001'];
        yield 'small negative float' => [-1.5E-10, '-0.00000000015'];
        yield 'integral float' => [100.0, '100'];
        yield 'negative zero float' => [-0.0, '0'];
        yield 'repeating float' => [1 / 3, '0.333333333333333'];
        yield 'int' => [42, '42'];
        yield 'int zero' => [0, '0'];
    }

    #[DataProvider('values')]
    public function testCanonicalText(mixed $raw, string $expected): void
    {
        self::assertSame($expected, PdoValueConverter::canonicalDecimal($raw));
    }
}
