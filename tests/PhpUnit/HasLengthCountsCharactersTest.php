<?php
declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\PhpUnit;

use Antevemus\ASpecification\Spec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * HasLengthCountsCharactersTest - `Spec::hasLength()` measures characters, not bytes (2.11.1, request CK-1).
 *
 * The docblock of `Spec::hasLength()` always promised a "character count"; until 2.11.0 the anonymous
 * specification in `SpecificationFactory::hasLength()` measured with `strlen()`, so every accented
 * letter counted twice (three or four times for CJK and emoji). The CMS Kit page rules
 * (`hasLength(lessThanOrEqualTo(150))` on the title) refused 150-character Portuguese titles that fit
 * a `varchar(150)`, which PostgreSQL, MySQL (utf8mb4) and SQL Server (`nvarchar`) measure in characters.
 * `hasLength` is not translated to SQL (`NonTranslatableSpecificationException`), so there is no
 * memory × SQL parity to keep here: the fix is in memory only.
 *
 * @version    2.11.1
 * @package    Antevemus\ASpecification
 * @subpackage Tests\PhpUnit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class HasLengthCountsCharactersTest extends TestCase
{
    /** @return iterable<string, array{string, int}> text with exactly five characters, and its byte count */
    public static function fiveCharacterTexts(): iterable
    {
        yield 'ascii (1 byte each)' => ['abcde', 5];
        yield 'latin accents (2 bytes each)' => ['ááááá', 10];
        yield 'japanese (3 bytes each)' => ['日本語テキ', 15];
        yield 'emoji (4 bytes each)' => ['😀😀😀😀😀', 20];
        yield 'mixed widths' => ['aé日😀z', 11];
    }

    #[DataProvider('fiveCharacterTexts')]
    public function testFiveCharactersHaveLengthFiveWhateverTheByteCount(string $text, int $bytes): void
    {
        self::assertSame(5, mb_strlen($text, 'UTF-8'), 'fixture: five characters');
        self::assertSame($bytes, strlen($text), 'fixture: byte count');

        $exactlyFive = Spec::hasLength(Spec::equalTo(5));
        self::assertTrue($exactlyFive->isSatisfiedBy($text), "'{$text}' has five characters");
        self::assertTrue(Spec::hasLength(Spec::lessThanOrEqualTo(5))->isSatisfiedBy($text));
        self::assertFalse(Spec::hasLength(Spec::greaterThan(5))->isSatisfiedBy($text));
        self::assertFalse($exactlyFive->isSatisfiedBy($text . 'x'), 'six characters are not five');

        if ($bytes !== 5) {
            self::assertFalse(
                Spec::hasLength(Spec::equalTo($bytes))->isSatisfiedBy($text),
                "the byte count ({$bytes}) is not the length of '{$text}'"
            );
        }
    }

    /** The rule that exposed the defect: a 150-character title must fit "até 150 caracteres". */
    public function testACmsKitTitleOfOneHundredAndFiftyAccentedCharactersFits(): void
    {
        $max150 = Spec::hasLength(Spec::lessThanOrEqualTo(150));

        self::assertTrue($max150->isSatisfiedBy(str_repeat('ã', 150)), '150 characters, 300 bytes');
        self::assertFalse($max150->isSatisfiedBy(str_repeat('ã', 151)), '151 characters');
        self::assertTrue($max150->isSatisfiedBy(str_repeat('a', 150)));
        self::assertFalse($max150->isSatisfiedBy(str_repeat('a', 151)));
    }

    /** Property: for any text over a multibyte alphabet, the length is the character count and never the byte count. */
    public function testRandomTextsAreMeasuredInCharacters(): void
    {
        $alphabet = ['a', 'Z', ' ', 'é', 'ç', 'ã', 'ß', '日', '本', '😀', '🚀'];
        mt_srand(20261026);

        for ($round = 0; $round < 300; $round++) {
            $length = mt_rand(0, 60);
            $text = '';
            for ($i = 0; $i < $length; $i++) {
                $text .= $alphabet[mt_rand(0, count($alphabet) - 1)];
            }

            self::assertTrue(
                Spec::hasLength(Spec::equalTo($length))->isSatisfiedBy($text),
                "round {$round}: '{$text}' has {$length} characters"
            );
            self::assertFalse(Spec::hasLength(Spec::equalTo($length + 1))->isSatisfiedBy($text));

            $bytes = strlen($text);
            if ($bytes !== $length) {
                self::assertFalse(
                    Spec::hasLength(Spec::equalTo($bytes))->isSatisfiedBy($text),
                    "round {$round}: {$bytes} bytes is not the length of '{$text}'"
                );
            }
        }
    }

    public function testAnEmptyStringHasLengthZero(): void
    {
        self::assertTrue(Spec::hasLength(Spec::equalTo(0))->isSatisfiedBy(''));
        self::assertFalse(Spec::hasLength(Spec::greaterThan(0))->isSatisfiedBy(''));
    }

    public function testNonStringsNeverSatisfy(): void
    {
        $any = Spec::hasLength(Spec::greaterThanOrEqualTo(0));

        self::assertFalse($any->isSatisfiedBy(null));
        self::assertFalse($any->isSatisfiedBy(5));
        self::assertFalse($any->isSatisfiedBy([]));
        self::assertFalse($any->isSatisfiedBy(new \stdClass()));
    }
}
