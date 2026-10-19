<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\PhpUnit;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ISerializableSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Serialization\Exceptions\NonSerializableSpecificationException;
use Antevemus\ASpecification\Serialization\Exceptions\SpecificationCodecException;
use Antevemus\ASpecification\Serialization\SpecificationCodec;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\AndSpecification;
use Antevemus\ASpecification\Specifications\Collection\AllEntitiesSpecification;
use Antevemus\ASpecification\Specifications\Collection\CollectionSpecification;
use Antevemus\ASpecification\Specifications\Collection\UniqueEntitySpecification;
use Antevemus\ASpecification\Specifications\Comparison\EqualSpecification;
use Antevemus\ASpecification\Specifications\Comparison\GreaterThanSpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysFalseSpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysTrueSpecification;
use Antevemus\ASpecification\Specifications\Logical\DefaultValueSpecification;
use Antevemus\ASpecification\Specifications\Logical\JointDenialSpecification;
use Antevemus\ASpecification\Specifications\NotSpecification;
use Antevemus\ASpecification\Specifications\OrSpecification;
use Antevemus\ASpecification\Specifications\PropertySpecification;
use Antevemus\ASpecification\Specifications\Reflection\MethodParameterizedSpecification;
use Antevemus\ASpecification\Specifications\String\DateStringSpecification;
use Antevemus\ASpecification\Specifications\String\EnumNameStringSpecification;
use Antevemus\ASpecification\Specifications\String\RegexSpecification;
use Antevemus\ASpecification\Specifications\String\TextComparison;
use Antevemus\ASpecification\Specifications\String\WildcardExpressionMatcherIgnoreCaseStringSpecification;
use Antevemus\ASpecification\Specifications\String\WildcardSpecification;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use TypeError;

/** Enum of the application, outside the default allowlist. */
enum SctPlan: string
{
    case Gold = 'gold';
    case Silver = 'silver';
}

/** Unit enum of the application. */
enum SctColor
{
    case Red;
    case Blue;
}

/** Leaf of the application with the contract: multiples of a divisor. */
final class SctMultipleOf extends AbstractSpecification implements ISerializableSpecification
{
    public function __construct(private readonly int $divisor)
    {
    }

    public function isSatisfiedBy(mixed $candidate): bool
    {
        return is_int($candidate) && $candidate % $this->divisor === 0;
    }

    public function getType(): string
    {
        return 'mixed';
    }

    public function toArray(): array
    {
        return ['d' => $this->divisor];
    }

    public static function fromArray(array $data, SpecificationCodec $codec): static
    {
        $codec->expectKeys($data, ['d']);

        return new static($codec->value($data, 'd'));
    }
}

/** Leaf of the application whose toArray() uses a key the codec keeps for itself. */
final class SctReservedKey extends AbstractSpecification implements ISerializableSpecification
{
    public function isSatisfiedBy(mixed $candidate): bool
    {
        return true;
    }

    public function getType(): string
    {
        return 'mixed';
    }

    public function toArray(): array
    {
        return ['code' => 'x'];
    }

    public static function fromArray(array $data, SpecificationCodec $codec): static
    {
        return new static();
    }
}

/** Leaf of the application that embeds another document: decodes and encodes inside fromArray(). */
final class SctEmbedded extends AbstractSpecification implements ISerializableSpecification
{
    public function __construct(private readonly ISpecification $inner)
    {
    }

    public function isSatisfiedBy(mixed $candidate): bool
    {
        return $this->inner->isSatisfiedBy($candidate);
    }

    public function getType(): string
    {
        return 'mixed';
    }

    public function toArray(): array
    {
        return ['doc' => (new SpecificationCodec())->toJson($this->inner)];
    }

    public static function fromArray(array $data, SpecificationCodec $codec): static
    {
        $codec->expectKeys($data, ['doc'], ['fail']);
        $inner = $codec->fromJson($codec->string($data, 'doc'));
        $codec->toJson($inner);
        if (array_key_exists('fail', $data)) {
            throw $codec->invalid('after the embedded document');
        }

        return new static($inner);
    }
}

/** Abstract serializable class: it cannot be registered. */
abstract class SctAbstractLeaf extends AbstractSpecification implements ISerializableSpecification
{
}

/** Subclass of a serializable leaf with a predicate of its own. */
final class SctLooseText extends EqualSpecification
{
    public function isSatisfiedBy(mixed $candidate): bool
    {
        return is_string($candidate) && strcasecmp($candidate, (string) $this->getValue()) === 0;
    }
}

/**
 * SpecificationCodecTest - The format, the values, the limits and the errors of SpecificationCodec
 * (2.4.0, forward 041, D1–D6).
 *
 * The round trip over the whole soundness pool and the SQL/TCriteria parity live in AlgebraSoundnessTest;
 * the fuzz with the marker file and the catalog/flag integration in Module31_SpecificationSerializationTest.
 * Since 2.5.0 the 45th name, `labelled` (forward 048, D11).
 *
 * @version    2.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\PhpUnit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class SpecificationCodecTest extends TestCase
{
    // ==========================================
    // D1: format of every library node
    // ==========================================

    /**
     * @return iterable<string, array{0: ISpecification, 1: string}>
     */
    public static function documents(): iterable
    {
        $factory = Spec::getFactory();
        $d = new DateTimeImmutable('2026-01-31 09:30:00.000123', new DateTimeZone('-03:00'));

        yield 'and' => [Spec::equal(1)->and(Spec::equal(2)), '{"t":"and","s":[{"t":"eq","v":1},{"t":"eq","v":2}]}'];
        yield 'or' => [Spec::equal(1)->or(Spec::equal(2)), '{"t":"or","s":[{"t":"eq","v":1},{"t":"eq","v":2}]}'];
        yield 'not' => [Spec::not(Spec::isNull()), '{"t":"not","s":{"t":"isNull"}}'];
        yield 'nor' => [Spec::nor(Spec::isTrue(), Spec::isFalse()), '{"t":"nor","s":[{"t":"eq","v":true},{"t":"eq","v":false}]}'];
        yield 'labelled' => [Spec::labelled('Ongoing claims: 3', Spec::lessThan(3)), '{"t":"labelled","label":"Ongoing claims: 3","s":{"t":"lt","v":3}}'];
        yield 'labelled annotated' => [Spec::labelled('L', Spec::isNull())->withCode('C')->because('r'), '{"t":"labelled","label":"L","s":{"t":"isNull"},"because":"r","code":"C"}'];
        yield 'property' => [Spec::property('age', Spec::greaterThanOrEqualTo(18)), '{"t":"property","p":"age","s":{"t":"gte","v":18}}'];
        yield 'property with base' => [Spec::property('age', Spec::lessThan(5), Spec::alwaysFalse()), '{"t":"property","p":"age","base":{"t":"false"},"s":{"t":"lt","v":5}}'];
        yield 'property with a typed true base' => [Spec::property('age', Spec::lessThan(5), new AlwaysTrueSpecification('stdClass')), '{"t":"property","p":"age","base":{"t":"true","type":"stdClass"},"s":{"t":"lt","v":5}}'];
        yield 'property with an annotated true base' => [Spec::property('age', Spec::lessThan(5), Spec::alwaysTrue()->because('b')), '{"t":"property","p":"age","base":{"t":"true","because":"b"},"s":{"t":"lt","v":5}}'];
        yield 'method' => [Spec::calling('total', [3, [1, 'a'], null], Spec::greaterThan(5)), '{"t":"method","m":"total","args":[3,[1,"a"],null],"s":{"t":"gt","v":5}}'];
        yield 'method typed' => [new MethodParameterizedSpecification('total', [], Spec::isTrue(), 'stdClass'), '{"t":"method","m":"total","args":[],"type":"stdClass","s":{"t":"eq","v":true}}'];
        yield 'type' => [Spec::specify(stdClass::class), '{"t":"type","type":"stdClass"}'];
        yield 'a' => [$factory->a(new AllEntitiesSpecification()), '{"t":"a","s":{"t":"allEntities"}}'];
        yield 'true' => [Spec::alwaysTrue(), '{"t":"true"}'];
        yield 'false typed' => [new AlwaysFalseSpecification('stdClass'), '{"t":"false","type":"stdClass"}'];
        yield 'eq' => [Spec::equal('a'), '{"t":"eq","v":"a"}'];
        yield 'neq' => [Spec::notEqual(null), '{"t":"neq","v":null}'];
        yield 'gt' => [Spec::greaterThan(1.5), '{"t":"gt","v":1.5}'];
        yield 'gte' => [Spec::greaterThanOrEqualTo(2), '{"t":"gte","v":2}'];
        yield 'lt' => [Spec::lessThan('b'), '{"t":"lt","v":"b"}'];
        yield 'lte' => [Spec::lessThanOrEqualTo(1.0), '{"t":"lte","v":1.0}'];
        yield 'looseEq' => [Spec::looselyEqualTo('1'), '{"t":"looseEq","v":"1"}'];
        yield 'at' => [Spec::at($d), '{"t":"at","v":{"$date":"2026-01-31T09:30:00.000123-03:00"}}'];
        yield 'in' => [Spec::in(1, 'x', null, 2.0), '{"t":"in","v":[1,"x",null,2.0]}'];
        yield 'isNull' => [Spec::isNull(), '{"t":"isNull"}'];
        yield 'notNull' => [Spec::isNotNull(), '{"t":"notNull"}'];
        yield 'defaultValue' => [new DefaultValueSpecification(), '{"t":"defaultValue"}'];
        yield 'defaultValue typed' => [new DefaultValueSpecification('string'), '{"t":"defaultValue","type":"string"}'];
        yield 'contains' => [Spec::contains('a'), '{"t":"contains","v":"a"}'];
        yield 'contains ignoring case' => [Spec::contains('a', false), '{"t":"contains","v":"a","cmp":"ignore_case"}'];
        yield 'startsWith' => [Spec::startsWith('ab', TextComparison::CaseSensitive), '{"t":"startsWith","v":"ab"}'];
        yield 'endsWith' => [Spec::endsWith('z', false), '{"t":"endsWith","v":"z","cmp":"ignore_case"}'];
        yield 'regex' => [Spec::regex('/^a/'), '{"t":"regex","p":"/^a/"}'];
        yield 'regex case sensitive' => [Spec::regex('/^a/', TextComparison::CaseSensitive), '{"t":"regex","p":"/^a/","cmp":"case_sensitive"}'];
        yield 'regex ignoring case' => [Spec::regex('/^a/', TextComparison::IgnoreCase), '{"t":"regex","p":"/^a/i","cmp":"ignore_case"}'];
        yield 'wildcard' => [new WildcardSpecification('a*'), '{"t":"wildcard","p":"a*"}'];
        yield 'wildcard ignoring case' => [Spec::wildcard('a?', false), '{"t":"wildcard","p":"a?","cmp":"ignore_case"}'];
        yield 'wildcardIgnoreCase' => [new WildcardExpressionMatcherIgnoreCaseStringSpecification('A*'), '{"t":"wildcardIgnoreCase","p":"A*"}'];
        yield 'equalIgnoreCase' => [Spec::equalIgnoreCase('Ana'), '{"t":"equalIgnoreCase","v":"Ana"}'];
        if (extension_loaded('intl')) {
            yield 'equalIgnoreCaseAndAccents' => [Spec::equalIgnoreCaseAndAccents('São'), '{"t":"equalIgnoreCaseAndAccents","v":"São"}'];
            yield 'contains ignoring case and accents' => [Spec::contains('ação', TextComparison::IgnoreCaseAndAccents), '{"t":"contains","v":"ação","cmp":"ignore_case_and_accents"}'];
            yield 'regex ignoring case and accents' => [Spec::regex('/^ação/', TextComparison::IgnoreCaseAndAccents), '{"t":"regex","p":"/^acao/iu","cmp":"ignore_case_and_accents"}'];
        }
        yield 'dateString' => [new DateStringSpecification('d/m/Y'), '{"t":"dateString","format":"d/m/Y"}'];
        yield 'enumName' => [new EnumNameStringSpecification(SctPlan::class), '{"t":"enumName","enum":"Antevemus\\\\ASpecification\\\\Tests\\\\PhpUnit\\\\SctPlan"}'];
        yield 'allEntities' => [new AllEntitiesSpecification(), '{"t":"allEntities"}'];
        yield 'allEntities typed' => [new AllEntitiesSpecification(stdClass::class), '{"t":"allEntities","type":"stdClass"}'];
        yield 'entity' => [new UniqueEntitySpecification(7), '{"t":"entity","id":7}'];
        yield 'entity typed' => [new UniqueEntitySpecification('7', stdClass::class), '{"t":"entity","id":"7","type":"stdClass"}'];
        yield 'all' => [new CollectionSpecification(Spec::equal(1)), '{"t":"all","s":{"t":"eq","v":1}}'];
        yield 'any' => [$factory->collection()->any(Spec::equal(1)), '{"t":"any","s":{"t":"eq","v":1}}'];
        yield 'none' => [$factory->collection()->none(Spec::equal(1)), '{"t":"none","s":{"t":"eq","v":1}}'];
        yield 'hasSize' => [Spec::isEmpty(), '{"t":"hasSize","s":{"t":"eq","v":0}}'];
        yield 'hasLength' => [Spec::hasLength(Spec::lessThan(3)), '{"t":"hasLength","s":{"t":"lt","v":3}}'];
        yield 'include' => [$factory->collection()->include(Spec::greaterThan(1), Spec::equal(1)), '{"t":"include","n":{"t":"gt","v":1},"s":{"t":"eq","v":1}}'];
        yield 'includePercentageOf' => [$factory->collection()->includePercentageOf(Spec::greaterThan(40.0), Spec::equal(1)), '{"t":"includePercentageOf","n":{"t":"gt","v":40.0},"s":{"t":"eq","v":1}}'];
        yield 'isPast' => [$factory->date()->isPast(), '{"t":"isPast"}'];
        yield 'isFuture' => [$factory->date()->isFuture(), '{"t":"isFuture"}'];
        yield 'isToday' => [$factory->date()->isToday(), '{"t":"isToday"}'];
        yield 'annotations' => [Spec::equal(1)->because('one')->withCode('E1'), '{"t":"eq","v":1,"because":"one","code":"E1"}'];
    }

    #[DataProvider('documents')]
    public function testEveryLibraryNodeHasItsCanonicalDocument(ISpecification $specification, string $node): void
    {
        $codec = new SpecificationCodec();
        $json = '{"v":1,"spec":' . $node . '}';

        self::assertSame($json, $codec->toJson($specification));
        $decoded = $codec->fromJson($json);
        self::assertSame($specification::class, $decoded::class, 'D9: the same class');
        self::assertTrue($decoded->equals($specification) && $specification->equals($decoded));
        self::assertSame($json, $codec->toJson($decoded), 'D5: canonical');
        self::assertSame($codec->encode($specification), $codec->encode($codec->decode($codec->encode($specification))));
    }

    #[DataProvider('documents')]
    public function testEveryLibraryNodeRefusesAnUnknownKeyAndAMissingOne(ISpecification $specification, string $node): void
    {
        $codec = new SpecificationCodec();
        $document = $codec->encode($specification);
        $type = $document['spec']['t'];
        $polluted = $document;
        $polluted['spec']['zz'] = 1;
        try {
            $codec->decode($polluted);
            self::fail('an unknown key is refused: ' . $node);
        } catch (SpecificationCodecException $e) {
            self::assertSame($type, $e->path);
            self::assertStringStartsWith('Unknown key "zz" (expected: ', $e->getMessage());
        }
        foreach (array_keys($document['spec']) as $key) {
            if (in_array($key, ['t', 'because', 'code'], true)) {
                continue;
            }
            $missing = $document;
            unset($missing['spec'][$key]);
            try {
                $decoded = $codec->decode($missing);
                // An optional parameter: its absence is its default, so the document read back differs
                self::assertNotSame($document, $codec->encode($decoded), "without {$key}: " . $node);
            } catch (SpecificationCodecException $e) {
                self::assertSame($type, $e->path);
                self::assertSame('Missing key "' . $key . '".', $e->reason, $node);
            }
        }
    }

    public function testEveryRegisteredNameOfTheLibraryHasADocument(): void
    {
        $seen = [];
        foreach (self::documents() as [$specification]) {
            $walk = static function (array $node) use (&$walk, &$seen): void {
                if (isset($node['t'])) {
                    $seen[$node['t']] = true;
                }
                foreach ($node as $value) {
                    if (is_array($value)) {
                        $walk($value);
                    }
                }
            };
            $walk((new SpecificationCodec())->encode($specification)['spec']);
        }
        $names = array_keys(SpecificationCodec::coreTypes());
        $missing = array_values(array_diff($names, array_keys($seen), extension_loaded('intl') ? [] : ['equalIgnoreCaseAndAccents']));

        self::assertSame([], $missing, 'every name of coreTypes() is covered by documents()');
        self::assertCount(45, $names, '44 names of 2.4.0 plus labelled (2.5.0)');
        self::assertSame($names, array_keys((new SpecificationCodec())->types()));
    }

    public function testCoreTypesAreTheClassesBuiltByTheFactory(): void
    {
        $types = SpecificationCodec::coreTypes();
        self::assertSame($types, SpecificationCodec::coreTypes(), 'resolved once');
        self::assertSame(Spec::specify(stdClass::class)::class, $types['type']);
        self::assertSame(Spec::isEmpty()::class, $types['hasSize']);
        self::assertSame(EqualSpecification::class, $types['eq']);
        foreach ($types as $class) {
            self::assertTrue(is_subclass_of($class, ISerializableSpecification::class), $class);
        }
    }

    public function testAChainOfConjunctionsIsOneListAndOtherShapesStayNested(): void
    {
        $codec = new SpecificationCodec();
        [$a, $b, $c, $d] = [Spec::equal(1), Spec::equal(2), Spec::equal(3), Spec::equal(4)];

        $chain = new AndSpecification(new AndSpecification(new AndSpecification($a, $b), $c), $d);
        self::assertSame('{"v":1,"spec":{"t":"and","s":[{"t":"eq","v":1},{"t":"eq","v":2},{"t":"eq","v":3},{"t":"eq","v":4}]}}', $codec->toJson($chain));
        self::assertTrue($codec->fromJson($codec->toJson($chain))->equals($chain));

        $right = new AndSpecification($a, new AndSpecification($b, $c));
        self::assertSame('{"v":1,"spec":{"t":"and","s":[{"t":"eq","v":1},{"t":"and","s":[{"t":"eq","v":2},{"t":"eq","v":3}]}]}}', $codec->toJson($right));

        $annotated = new AndSpecification((new AndSpecification($a, $b))->because('inner'), $c);
        self::assertSame('{"v":1,"spec":{"t":"and","s":[{"t":"and","s":[{"t":"eq","v":1},{"t":"eq","v":2}],"because":"inner"},{"t":"eq","v":3}]}}', $codec->toJson($annotated));
        $coded = new AndSpecification((new AndSpecification($a, $b))->withCode('C'), $c);
        self::assertSame('{"v":1,"spec":{"t":"and","s":[{"t":"and","s":[{"t":"eq","v":1},{"t":"eq","v":2}],"code":"C"},{"t":"eq","v":3}]}}', $codec->toJson($coded));

        $mixed = new AndSpecification(new OrSpecification($a, $b), $c);
        self::assertSame('{"v":1,"spec":{"t":"and","s":[{"t":"or","s":[{"t":"eq","v":1},{"t":"eq","v":2}]},{"t":"eq","v":3}]}}', $codec->toJson($mixed));
        $orChain = new OrSpecification(new OrSpecification($a, $b), $c);
        self::assertSame('{"v":1,"spec":{"t":"or","s":[{"t":"eq","v":1},{"t":"eq","v":2},{"t":"eq","v":3}]}}', $codec->toJson($orChain));
        self::assertTrue($codec->fromJson($codec->toJson($orChain))->equals($orChain));
        $orAnnotated = new OrSpecification((new OrSpecification($a, $b))->withCode('O'), $c);
        self::assertSame('{"v":1,"spec":{"t":"or","s":[{"t":"or","s":[{"t":"eq","v":1},{"t":"eq","v":2}],"code":"O"},{"t":"eq","v":3}]}}', $codec->toJson($orAnnotated));
        $orReason = new OrSpecification((new OrSpecification($a, $b))->because('R'), $c);
        self::assertSame('{"v":1,"spec":{"t":"or","s":[{"t":"or","s":[{"t":"eq","v":1},{"t":"eq","v":2}],"because":"R"},{"t":"eq","v":3}]}}', $codec->toJson($orReason));
    }

    public function testDecodingAListOfOperandsFoldsItToTheLeft(): void
    {
        $codec = new SpecificationCodec();
        $spec = $codec->fromJson('{"v":1,"spec":{"t":"or","s":[{"t":"eq","v":1},{"t":"eq","v":2},{"t":"eq","v":3}]}}');
        self::assertTrue($spec->equals(new OrSpecification(new OrSpecification(Spec::equal(1), Spec::equal(2)), Spec::equal(3))));
        self::assertFalse($spec->equals(new OrSpecification(Spec::equal(1), new OrSpecification(Spec::equal(2), Spec::equal(3)))));
        $and = $codec->fromJson('{"v":1,"spec":{"t":"and","s":[{"t":"eq","v":1},{"t":"eq","v":2},{"t":"eq","v":3}]}}');
        self::assertTrue($and->equals(new AndSpecification(new AndSpecification(Spec::equal(1), Spec::equal(2)), Spec::equal(3))));
        self::assertFalse($and->equals(new AndSpecification(Spec::equal(1), new AndSpecification(Spec::equal(2), Spec::equal(3)))));
    }

    // ==========================================
    // D3: values
    // ==========================================

    public function testIntegersStayIntegersAndFloatsStayFloats(): void
    {
        $codec = new SpecificationCodec();
        $one = $codec->fromJson($codec->toJson(Spec::equal(1)));
        $oneFloat = $codec->fromJson($codec->toJson(Spec::equal(1.0)));

        self::assertSame('{"v":1,"spec":{"t":"eq","v":1.0}}', $codec->toJson(Spec::equal(1.0)));
        self::assertSame(1, $one instanceof EqualSpecification ? $one->getValue() : null);
        self::assertSame(1.0, $oneFloat instanceof EqualSpecification ? $oneFloat->getValue() : null);
        self::assertFalse($one->equals($oneFloat), '1 and 1.0 are different leaves');
        self::assertTrue($oneFloat->isSatisfiedBy(1.0));
        self::assertTrue($one->isSatisfiedBy(1));

        $big = $codec->fromJson($codec->toJson(Spec::greaterThan(1.0e25)));
        self::assertSame(1.0e25, $big instanceof GreaterThanSpecification ? $big->getValue() : null);
        $small = $codec->fromJson($codec->toJson(Spec::lessThan(0.1)));
        self::assertTrue($small->equals(Spec::lessThan(0.1)), 'shortest round-trip representation');
        $max = $codec->fromJson($codec->toJson(Spec::equal(PHP_INT_MAX)));
        self::assertSame(PHP_INT_MAX, $max instanceof EqualSpecification ? $max->getValue() : null);
    }

    public function testDateTimesKeepTheInstantTheMicrosecondsAndTheOffset(): void
    {
        $codec = new SpecificationCodec();
        $named = new DateTimeImmutable('2026-01-31 09:30:00.000001', new DateTimeZone('America/Sao_Paulo'));
        $mutable = new DateTime('2026-07-01 23:59:59', new DateTimeZone('+05:30'));

        $document = $codec->encode(Spec::in($named, $mutable));
        self::assertSame(['$date' => '2026-01-31T09:30:00.000001-03:00'], $document['spec']['v'][0]);
        self::assertSame(['$date' => '2026-07-01T23:59:59.000000+05:30'], $document['spec']['v'][1]);

        $decoded = $codec->decode($document);
        $values = $decoded instanceof \Antevemus\ASpecification\Specifications\Comparison\InSpecification ? $decoded->getValues() : [];
        self::assertInstanceOf(DateTimeImmutable::class, $values[0]);
        self::assertSame('2026-01-31T09:30:00.000001-03:00', $values[0]->format(SpecificationCodec::DATE_FORMAT));
        self::assertSame($named->format('U.u'), $values[0]->format('U.u'));
        self::assertSame('+05:30', $values[1]->format('P'));
        self::assertTrue($decoded->isSatisfiedBy($named), 'the same instant');
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function acceptedDates(): iterable
    {
        yield 'Z' => ['2026-01-31T12:00:00Z', '2026-01-31T12:00:00.000000+00:00'];
        yield 'without fraction' => ['2026-01-31T12:00:00-03:00', '2026-01-31T12:00:00.000000-03:00'];
        yield 'milliseconds' => ['2026-01-31T12:00:00.5+01:00', '2026-01-31T12:00:00.500000+01:00'];
        yield 'leap day' => ['2028-02-29T00:00:00.000000+00:00', '2028-02-29T00:00:00.000000+00:00'];
    }

    #[DataProvider('acceptedDates')]
    public function testRfc3339DatesAreRead(string $text, string $canonical): void
    {
        $codec = new SpecificationCodec();
        $spec = $codec->decode(['v' => 1, 'spec' => ['t' => 'at', 'v' => ['$date' => $text]]]);

        self::assertSame(['v' => 1, 'spec' => ['t' => 'at', 'v' => ['$date' => $canonical]]], $codec->encode($spec));
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function refusedDates(): iterable
    {
        yield 'no offset' => [['$date' => '2026-01-31T12:00:00']];
        yield 'date only' => [['$date' => '2026-01-31']];
        yield 'space' => [['$date' => '2026-01-31 12:00:00+00:00']];
        yield 'seven fraction digits' => [['$date' => '2026-01-31T12:00:00.1234567+00:00']];
        yield 'day overflow' => [['$date' => '2026-02-30T00:00:00+00:00']];
        yield 'month 13' => [['$date' => '2026-13-01T00:00:00+00:00']];
        yield 'hour 25' => [['$date' => '2026-01-01T25:00:00+00:00']];
        yield 'not a string' => [['$date' => 20260131]];
        yield 'extra key' => [['$date' => '2026-01-31T12:00:00Z', 'tz' => 'UTC']];
        yield 'surrounding text' => [['$date' => ' 2026-01-31T12:00:00Z']];
        yield 'trailing text' => [['$date' => '2026-01-31T12:00:00Zx']];
    }

    #[DataProvider('refusedDates')]
    public function testMalformedDatesAreRefused(mixed $value): void
    {
        $this->expectException(SpecificationCodecException::class);
        $this->expectExceptionMessageMatches('/date|date-time/');

        (new SpecificationCodec())->decode(['v' => 1, 'spec' => ['t' => 'at', 'v' => $value]]);
    }

    public function testEnumCasesNeedTheAllowlist(): void
    {
        $codec = new SpecificationCodec();
        self::assertTrue($codec->isEnumAllowed(TextComparison::class), 'TextComparison is allowed by default');
        self::assertFalse($codec->isEnumAllowed(SctPlan::class));

        $text = Spec::equal(TextComparison::IgnoreCase);
        self::assertSame('{"v":1,"spec":{"t":"eq","v":{"$enum":"Antevemus\\\\ASpecification\\\\Specifications\\\\String\\\\TextComparison","case":"IgnoreCase"}}}', $codec->toJson($text));
        self::assertTrue($codec->fromJson($codec->toJson($text))->isSatisfiedBy(TextComparison::IgnoreCase));

        try {
            $codec->encode(Spec::property('plan', Spec::equal(SctPlan::Gold)));
            self::fail('an enum outside the allowlist is refused');
        } catch (NonSerializableSpecificationException $e) {
            self::assertSame('property.eq.v', $e->path);
            self::assertNull($e->specificationClass);
            self::assertStringContainsString(SctPlan::class, $e->getMessage());
        }
        $document = ['v' => 1, 'spec' => ['t' => 'eq', 'v' => ['$enum' => SctPlan::class, 'case' => 'Gold']]];
        try {
            $codec->decode($document);
            self::fail('a document never loads an enum outside the allowlist');
        } catch (NonSerializableSpecificationException $e) {
            self::assertSame('eq.v', $e->path);
            self::assertStringContainsString('allowlist', $e->getMessage());
        }

        $allowed = new SpecificationCodec(allowedEnums: [SctPlan::class]);
        self::assertTrue($allowed->isEnumAllowed(SctPlan::class));
        self::assertSame(SctPlan::Gold, ($allowed->decode($document) instanceof EqualSpecification) ? $allowed->decode($document)->getValue() : null);
        $unit = (new SpecificationCodec())->allowEnum(SctColor::class);
        $color = Spec::in(SctColor::Red, SctColor::Blue);
        self::assertTrue($unit->decode($unit->encode($color))->equals($color));
        self::assertSame(['$enum' => SctColor::class, 'case' => 'Blue'], $unit->encode($color)['spec']['v'][1]);
        $method = Spec::calling('plan', [SctPlan::Silver], Spec::isTrue());
        self::assertTrue($allowed->decode($allowed->encode($method))->equals($method));
    }

    /**
     * @return iterable<string, array{0: mixed, 1: string}>
     */
    public static function malformedEnums(): iterable
    {
        yield 'unknown case' => [['$enum' => TextComparison::class, 'case' => 'Loose'], 'has no case "Loose"'];
        yield 'case by value' => [['$enum' => TextComparison::class, 'case' => 'ignore_case'], 'has no case'];
        yield 'class constant' => [['$enum' => TextComparison::class, 'case' => 'class'], 'has no case'];
        yield 'no case' => [['$enum' => TextComparison::class], 'An enum case is'];
        yield 'extra key' => [['$enum' => TextComparison::class, 'case' => 'IgnoreCase', 'x' => 1], 'An enum case is'];
        yield 'case not a string' => [['$enum' => TextComparison::class, 'case' => 1], 'An enum case is'];
        yield 'class not a string' => [['$enum' => 1, 'case' => 'IgnoreCase'], 'An enum case is'];
        yield 'leading backslash' => [['$enum' => '\\' . TextComparison::class, 'case' => 'IgnoreCase'], 'allowlist'];
        yield 'lower case class' => [['$enum' => strtolower(TextComparison::class), 'case' => 'IgnoreCase'], 'allowlist'];
    }

    #[DataProvider('malformedEnums')]
    public function testMalformedEnumValuesAreRefused(mixed $value, string $message): void
    {
        $this->expectException(SpecificationCodecException::class);
        $this->expectExceptionMessage($message);

        (new SpecificationCodec())->decode(['v' => 1, 'spec' => ['t' => 'eq', 'v' => $value]]);
    }

    public function testAllowEnumRefusesAClassThatIsNotAnEnum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"stdClass" is not an enum');

        new SpecificationCodec(allowedEnums: [stdClass::class]);
    }

    /**
     * @return iterable<string, array{0: ISpecification, 1: string, 2: string}>
     */
    public static function valuesWithoutASerializedForm(): iterable
    {
        yield 'closure' => [Spec::equal(static fn(): bool => true), 'eq.v', 'A closure is code, not data'];
        yield 'object' => [Spec::equal(new stdClass()), 'eq.v', 'A value of type stdClass has no serialized form'];
        yield 'resource' => [Spec::equal(STDIN), 'eq.v', 'A value of type resource (stream) has no serialized form'];
        yield 'associative array' => [Spec::equal(['a' => 1]), 'eq.v', 'An associative array has no serialized form'];
        yield 'NAN' => [Spec::equal(NAN), 'eq.v', 'non-finite float'];
        yield 'INF' => [Spec::lessThan(INF), 'lt.v', 'non-finite float'];
        yield 'object in a list' => [Spec::in(1, 2, new stdClass()), 'in.v[2]', 'stdClass'];
        yield 'object in a nested list' => [Spec::in(1, [2, new stdClass()]), 'in.v[1][1]', 'stdClass'];
    }

    #[DataProvider('valuesWithoutASerializedForm')]
    public function testValuesWithoutASerializedFormAreRefused(ISpecification $specification, string $path, string $message): void
    {
        try {
            (new SpecificationCodec())->encode($specification);
            self::fail('refused');
        } catch (NonSerializableSpecificationException $e) {
            self::assertSame($path, $e->path);
            self::assertNull($e->specificationClass);
            self::assertStringContainsString($message, $e->getMessage());
            self::assertStringEndsWith('(at ' . $path . ')', $e->getMessage());
        }
    }

    // ==========================================
    // D2: refusals of classes, registration
    // ==========================================

    public function testRefusedClassesAreNamedWithTheirPosition(): void
    {
        $codec = new SpecificationCodec();
        $tree = Spec::equal(1)->and(Spec::not(new SctMultipleOf(2)));
        try {
            $codec->encode($tree);
            self::fail('an unregistered leaf is refused');
        } catch (NonSerializableSpecificationException $e) {
            self::assertSame('and[1].not.<SctMultipleOf>', $e->path);
            self::assertSame(SctMultipleOf::class, $e->specificationClass);
            self::assertStringContainsString('SctMultipleOf is not registered in the codec', $e->getMessage());
        }
        try {
            $codec->encode(Spec::property('name', new SctLooseText('ana'), Spec::specify(stdClass::class)));
            self::fail('a subclass of a serializable leaf is refused');
        } catch (NonSerializableSpecificationException $e) {
            self::assertSame('property.<SctLooseText>', $e->path);
            self::assertStringContainsString('may redefine its predicate', $e->getMessage());
        }
        $anonymous = new class extends AbstractSpecification {
            public function isSatisfiedBy(mixed $candidate): bool
            {
                return true;
            }

            public function getType(): string
            {
                return 'mixed';
            }
        };
        try {
            $codec->encode(Spec::not($anonymous));
            self::fail('an anonymous leaf is refused');
        } catch (NonSerializableSpecificationException $e) {
            self::assertSame('not.<anonymous AbstractSpecification>', $e->path);
            self::assertStringContainsString('anonymous AbstractSpecification does not implement ISerializableSpecification', $e->getMessage());
            self::assertStringNotContainsString("\0", $e->getMessage());
        }
    }

    public function testALeafOfTheApplicationIsRegisteredUnderItsOwnName(): void
    {
        $codec = (new SpecificationCodec())->register('multipleOf', SctMultipleOf::class);
        $spec = Spec::property('n', new SctMultipleOf(3))->withCode('M3');

        self::assertSame('{"v":1,"spec":{"t":"property","p":"n","s":{"t":"multipleOf","d":3},"code":"M3"}}', $codec->toJson($spec));
        self::assertTrue($codec->fromJson($codec->toJson($spec))->equals($spec));
        self::assertSame(SctMultipleOf::class, $codec->types()['multipleOf']);
        self::assertSame($codec, $codec->register('multipleOf', SctMultipleOf::class), 'registering again the same pair is accepted');
        self::assertArrayNotHasKey('multipleOf', (new SpecificationCodec())->types(), 'the registry belongs to the instance');
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function invalidRegistrations(): iterable
    {
        yield 'name with a dot' => ['my.leaf', SctMultipleOf::class, 'Invalid specification name "my.leaf"'];
        yield 'name starting with a digit' => ['1leaf', SctMultipleOf::class, 'Invalid specification name'];
        yield 'empty name' => ['', SctMultipleOf::class, 'Invalid specification name'];
        yield 'name too long' => [str_repeat('a', 65), SctMultipleOf::class, 'Invalid specification name'];
        yield 'not serializable' => ['leaf', stdClass::class, 'must be a concrete class implementing'];
        yield 'abstract' => ['leaf', SctAbstractLeaf::class, 'must be a concrete class implementing'];
        yield 'unknown class' => ['leaf', 'No\\Such\\Leaf', 'must be a concrete class implementing'];
        yield 'library name taken' => ['eq', SctMultipleOf::class, 'The specification name "eq" is already registered'];
        yield 'library class renamed' => ['myEq', EqualSpecification::class, 'is already registered as "eq"'];
    }

    #[DataProvider('invalidRegistrations')]
    public function testInvalidRegistrationsAreRefused(string $type, string $class, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        (new SpecificationCodec())->register($type, $class);
    }

    public function testRegistrationAcceptsTheLongestNameAndCanonicalizesTheClass(): void
    {
        $name = 'a' . str_repeat('b', 63);
        $codec = (new SpecificationCodec())->register($name, strtolower(SctMultipleOf::class));

        self::assertSame(SctMultipleOf::class, $codec->types()[$name]);
        self::assertSame('{"v":1,"spec":{"t":"' . $name . '","d":2}}', $codec->toJson(new SctMultipleOf(2)));
    }

    public function testAToArrayUsingAReservedKeyIsRefused(): void
    {
        $codec = (new SpecificationCodec())->register('reserved', SctReservedKey::class);
        $this->expectException(SpecificationCodecException::class);
        $this->expectExceptionMessage('toArray() of SctReservedKey returned the key "code"');

        $codec->encode(new SctReservedKey());
    }

    // ==========================================
    // D6: malformed documents and limits
    // ==========================================

    /**
     * @return iterable<string, array{0: string, 1: class-string, 2: string, 3: string}>
     */
    public static function malformedJson(): iterable
    {
        $e = SpecificationCodecException::class;
        $n = NonSerializableSpecificationException::class;
        yield 'not JSON' => ['{"v":1,', $e, '', 'Malformed specification JSON'];
        yield 'empty' => ['', $e, '', 'Malformed specification JSON'];
        yield 'scalar' => ['1', $e, '', 'is a JSON object'];
        yield 'null' => ['null', $e, '', 'is a JSON object'];
        yield 'no envelope' => ['{"t":"eq","v":1}', $e, '', 'A specification document is {"v": 1'];
        yield 'extra envelope key' => ['{"v":1,"spec":{"t":"isNull"},"x":1}', $e, '', 'A specification document is {"v": 1'];
        yield 'version 2' => ['{"v":2,"spec":{"t":"isNull"}}', $e, '', 'Unsupported specification format version 2'];
        yield 'version as text' => ['{"v":"1","spec":{"t":"isNull"}}', $e, '', "version '1'"];
        yield 'version as float' => ['{"v":1.0,"spec":{"t":"isNull"}}', $e, '', 'version 1.0'];
        yield 'version as list' => ['{"v":[1],"spec":{"t":"isNull"}}', $e, '', 'version array'];
        yield 'spec not a node' => ['{"v":1,"spec":"eq"}', $e, '', 'must be a node'];
        yield 'node without t' => ['{"v":1,"spec":{"v":1}}', $e, '?', 'needs a string "t"'];
        yield 't not a string' => ['{"v":1,"spec":{"t":1}}', $e, '?', 'needs a string "t"'];
        yield 'unknown t' => ['{"v":1,"spec":{"t":"Antevemus\\\\ASpecification\\\\Specifications\\\\Comparison\\\\EqualSpecification","v":1}}', $n, 'Antevemus\\ASpecification\\Specifications\\Comparison\\EqualSpecification', 'Unknown specification name'];
        yield 'unknown key' => ['{"v":1,"spec":{"t":"eq","v":1,"w":2}}', $e, 'eq', 'Unknown key "w" (expected: v)'];
        yield 'unknown key of a leaf without keys' => ['{"v":1,"spec":{"t":"isNull","v":1}}', $e, 'isNull', 'Unknown key "v" (expected: none)'];
        yield 'missing key' => ['{"v":1,"spec":{"t":"eq"}}', $e, 'eq', 'Missing key "v"'];
        yield 'missing child' => ['{"v":1,"spec":{"t":"not"}}', $e, 'not', 'Missing key "s"'];
        yield 'child not a node' => ['{"v":1,"spec":{"t":"not","s":1}}', $e, 'not', '"s" must be a specification node'];
        yield 'child list instead of node' => ['{"v":1,"spec":{"t":"not","s":[{"t":"isNull"}]}}', $e, 'not', '"s" must be a specification node'];
        yield 'one operand' => ['{"v":1,"spec":{"t":"and","s":[{"t":"isNull"}]}}', $e, 'and', '"s" must hold at least 2 specification nodes, 1 given'];
        yield 'no operand' => ['{"v":1,"spec":{"t":"or","s":[]}}', $e, 'or', '"s" must hold at least 2 specification nodes, 0 given'];
        yield 'three operands of nor' => ['{"v":1,"spec":{"t":"nor","s":[{"t":"isNull"},{"t":"isNull"},{"t":"isNull"}]}}', $e, 'nor', '"s" must hold 2 specification nodes, 3 given'];
        yield 'operands not a list' => ['{"v":1,"spec":{"t":"and","s":{"t":"isNull"}}}', $e, 'and', '"s" must be a list of specification nodes'];
        yield 'operand not a node' => ['{"v":1,"spec":{"t":"and","s":[{"t":"isNull"},1]}}', $e, 'and', '"s" must be a list of specification nodes'];
        yield 'value that is a node' => ['{"v":1,"spec":{"t":"eq","v":{"t":"isNull"}}}', $e, 'eq', '"v" must be a value, not a specification node'];
        yield 'node inside a value list' => ['{"v":1,"spec":{"t":"in","v":[1,{"t":"isNull"}]}}', $e, 'in', '"v" must be a value, not a specification node'];
        yield 'set that is not a list' => ['{"v":1,"spec":{"t":"in","v":1}}', $e, 'in', '"v" must be a list'];
        yield 'object value' => ['{"v":1,"spec":{"t":"eq","v":{"a":1}}}', $e, 'eq.v', 'An object of a specification document is a node'];
        yield 'string expected' => ['{"v":1,"spec":{"t":"property","p":1,"s":{"t":"isNull"}}}', $e, 'property', '"p" must be a string'];
        yield 'optional string expected' => ['{"v":1,"spec":{"t":"true","type":1}}', $e, 'true', '"type" must be a string'];
        yield 'comparison unknown' => ['{"v":1,"spec":{"t":"contains","v":"a","cmp":"loose"}}', $e, 'contains', '"cmp" must be one of case_sensitive, ignore_case, ignore_case_and_accents'];
        yield 'comparison not a string' => ['{"v":1,"spec":{"t":"wildcard","p":"a*","cmp":false}}', $e, 'wildcard', '"cmp" must be one of'];
        yield 'because not a string' => ['{"v":1,"spec":{"t":"isNull","because":1}}', $e, 'isNull', '"because" must be a string'];
        yield 'code not a string' => ['{"v":1,"spec":{"t":"isNull","code":[]}}', $e, 'isNull', '"code" must be a string'];
        yield 'invalid PCRE' => ['{"v":1,"spec":{"t":"regex","p":"/(/"}}', $e, 'regex', 'is not a valid PCRE pattern'];
        yield 'unknown type' => ['{"v":1,"spec":{"t":"type","type":"No\\\\Such\\\\Type"}}', $e, 'type', 'Type "No\\Such\\Type" does not exist'];
        yield 'constructor refusal' => ['{"v":1,"spec":{"t":"gt","v":null}}', $e, 'gt', 'Invalid parameters for "gt"'];
        yield 'constructor bound refusal' => ['{"v":1,"spec":{"t":"lt","v":true}}', $e, 'lt', 'Invalid parameters for "lt"'];
        yield 'enum name of a class' => ['{"v":1,"spec":{"t":"enumName","enum":"stdClass"}}', $e, 'enumName', 'not a valid PHP Enum'];
        yield 'entity identifier' => ['{"v":1,"spec":{"t":"entity","id":1.5}}', $e, 'entity', 'Invalid parameters for "entity"'];
        yield 'method name' => ['{"v":1,"spec":{"t":"method","m":"a-b","args":[],"s":{"t":"isNull"}}}', $e, 'method', 'Invalid method name'];
        yield 'nested position' => ['{"v":1,"spec":{"t":"and","s":[{"t":"eq","v":1},{"t":"not","s":{"t":"evil"}}]}}', $n, 'and[1].not.evil', 'Unknown specification name "evil"'];
        yield 'position under a named key' => ['{"v":1,"spec":{"t":"property","p":"x","base":{"t":"gt","v":null},"s":{"t":"isNull"}}}', $e, 'property.base.gt', 'Invalid parameters for "gt"'];
        yield 'position in a value list' => ['{"v":1,"spec":{"t":"in","v":[1,{"x":1}]}}', $e, 'in.v[1]', 'An object of a specification document'];
        yield 'NAN-like text is text' => ['{"v":1,"spec":{"t":"gt","v":"NAN"}}', $e, '', ''];
    }

    #[DataProvider('malformedJson')]
    public function testMalformedDocumentsAreRefusedWithTheirPosition(string $json, string $class, string $path, string $message): void
    {
        if ($message === '') {
            self::assertTrue((new SpecificationCodec())->fromJson($json)->equals(Spec::greaterThan('NAN')), 'a text is a text');

            return;
        }
        try {
            (new SpecificationCodec())->fromJson($json);
            self::fail('refused: ' . $json);
        } catch (SpecificationCodecException $e) {
            self::assertSame($class, $e::class, $e->getMessage());
            self::assertSame($path, $e->path);
            self::assertStringContainsString($message, $e->getMessage());
            self::assertStringContainsString($message, $e->reason);
            self::assertSame($path === '' ? $e->reason : $e->reason . ' (at ' . $path . ')', $e->getMessage());
            self::assertSame(0, $e->getCode());
            // Only a constructor's refusal and a JSON error are wrapped; the codec's own checks are not
            $wrapped = str_starts_with($e->reason, 'Invalid parameters for') || str_starts_with($e->reason, 'Malformed specification JSON');
            self::assertSame($wrapped, $e->getPrevious() !== null, 'previous of: ' . $e->reason);
        }
    }

    public function testConstructorRefusalsKeepTheOriginalException(): void
    {
        try {
            (new SpecificationCodec())->fromJson('{"v":1,"spec":{"t":"gt","v":null}}');
            self::fail('refused');
        } catch (SpecificationCodecException $e) {
            self::assertInstanceOf(TypeError::class, $e->getPrevious());
            self::assertSame(0, $e->getCode());
        }
        try {
            (new SpecificationCodec())->fromJson('{"v":1,"');
            self::fail('refused');
        } catch (SpecificationCodecException $e) {
            self::assertInstanceOf(\JsonException::class, $e->getPrevious());
            self::assertSame('Malformed specification JSON: Control character error, possibly incorrectly encoded.', $e->getMessage());
        }
    }

    public function testDecodeRefusesValuesThatAreNotPartOfADocument(): void
    {
        $codec = new SpecificationCodec();
        foreach ([[new stdClass(), 'A value of type stdClass is not part'], [NAN, 'non-finite float'], [-INF, 'non-finite float']] as [$value, $message]) {
            try {
                $codec->decode(['v' => 1, 'spec' => ['t' => 'eq', 'v' => $value]]);
                self::fail('refused');
            } catch (SpecificationCodecException $e) {
                self::assertSame('eq.v', $e->path);
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
        try {
            $codec->decode(['v' => 1, 'spec' => ['t' => 'eq', 0 => 1]]);
            self::fail('refused');
        } catch (SpecificationCodecException $e) {
            self::assertSame('Invalid key 0 in a specification node. (at eq)', $e->getMessage());
        }
        try {
            $codec->decode(['v' => 1, 'spec' => [['t' => 'isNull']]]);
            self::fail('refused');
        } catch (SpecificationCodecException $e) {
            self::assertSame('?', $e->path);
        }
    }

    public function testDepthLimit(): void
    {
        $codec = new SpecificationCodec();
        self::assertSame(64, $codec->getMaxDepth());
        self::assertSame(10000, $codec->getMaxNodes());

        $deep = static function (int $levels): ISpecification {
            $spec = Spec::isNull();
            for ($i = 1; $i < $levels; $i++) {
                $spec = new NotSpecification($spec);
            }

            return $spec;
        };
        $json64 = $codec->toJson($deep(64));
        self::assertTrue($codec->fromJson($json64)->equals($deep(64)), '64 levels are read');

        $json65 = str_replace('{"t":"isNull"}', '{"t":"not","s":{"t":"isNull"}}', $json64);
        try {
            $codec->fromJson($json65);
            self::fail('65 levels are refused');
        } catch (SpecificationCodecException $e) {
            self::assertStringContainsString('deeper than 64 levels', $e->getMessage());
            self::assertSame(65, substr_count($e->path, 'not') + substr_count($e->path, 'isNull'));
        }
        try {
            $codec->encode($deep(65));
            self::fail('65 levels are not written');
        } catch (SpecificationCodecException $e) {
            self::assertStringContainsString('deeper than 64 levels', $e->getMessage());
        }

        $shallow = new SpecificationCodec(3);
        self::assertSame(3, $shallow->getMaxDepth());
        self::assertTrue($shallow->decode($shallow->encode($deep(3)))->equals($deep(3)));
        $this->expectException(SpecificationCodecException::class);
        $this->expectExceptionMessage('deeper than 3 levels');
        $shallow->encode($deep(4));
    }

    public function testDepthLimitOfValues(): void
    {
        $codec = new SpecificationCodec(2);
        $nested = ['v' => 1, 'spec' => ['t' => 'in', 'v' => [[1]]]];
        self::assertTrue($codec->decode($nested)->equals(Spec::in([[1]])));
        self::assertSame($nested, $codec->encode(Spec::in([[1]])));
        try {
            $codec->decode(['v' => 1, 'spec' => ['t' => 'in', 'v' => [[[1]]]]]);
            self::fail('values nested deeper than the limit are refused');
        } catch (SpecificationCodecException $e) {
            self::assertSame('in.v[0][0]', $e->path);
            self::assertStringContainsString('nested deeper than 2 levels', $e->getMessage());
        }
        $this->expectException(SpecificationCodecException::class);
        $this->expectExceptionMessage('nested deeper than 2 levels');
        $codec->encode(Spec::in([[[1]]]));
    }

    public function testTheJsonTextIsBoundedBeforeItIsWalked(): void
    {
        $codec = new SpecificationCodec(2);
        $lists = static fn(int $count): string => '{"v":1,"spec":{"t":"in","v":' . str_repeat('[', $count) . '1' . str_repeat(']', $count) . '}}';

        // 3 x 2 + 2 = 8: a text nested 7 levels is walked (and refused by the value limit), 8 levels is not decoded
        try {
            $codec->fromJson($lists(5));
            self::fail('refused');
        } catch (SpecificationCodecException $e) {
            self::assertSame('A value is nested deeper than 2 levels (the depth limit of the codec). (at in.v[0][0])', $e->getMessage());
        }
        try {
            $codec->fromJson($lists(6));
            self::fail('refused');
        } catch (SpecificationCodecException $e) {
            self::assertSame('Malformed specification JSON: Maximum stack depth exceeded.', $e->getMessage());
        }
        // The deepest document the limits accept is written and read back
        $deepest = new AndSpecification(Spec::isNull(), Spec::in([[1]]));
        self::assertTrue($codec->fromJson($codec->toJson($deepest))->equals($deepest));
    }

    public function testADocumentEmbeddedByALeafKeepsTheStateOfTheOuterOne(): void
    {
        $codec = (new SpecificationCodec(maxNodes: 3))->register('embedded', SctEmbedded::class);
        $inner = '{"v":1,"spec":{"t":"nor","s":[{"t":"isNull"},{"t":"isNull"}]}}';
        $quoted = json_encode($inner, JSON_UNESCAPED_SLASHES);

        // and + embedded + isNull = 3 nodes: the 3 nodes of the embedded document are counted apart
        $spec = $codec->fromJson('{"v":1,"spec":{"t":"and","s":[{"t":"embedded","doc":' . $quoted . '},{"t":"isNull"}]}}');
        self::assertInstanceOf(AndSpecification::class, $spec);
        try {
            $codec->fromJson('{"v":1,"spec":{"t":"not","s":{"t":"embedded","doc":' . $quoted . ',"fail":true}}}');
            self::fail('refused');
        } catch (SpecificationCodecException $e) {
            self::assertSame('after the embedded document (at not.embedded)', $e->getMessage(), 'the position of the outer document');
        }
    }

    public function testNodeLimit(): void
    {
        $codec = new SpecificationCodec();
        $leaves = static fn(int $count): array => array_fill(0, $count, ['t' => 'isNull']);

        // 9 999 leaves + the root = 10 000 nodes
        $spec = $codec->decode(['v' => 1, 'spec' => ['t' => 'or', 's' => $leaves(9999)]]);
        self::assertInstanceOf(OrSpecification::class, $spec);
        try {
            $codec->decode(['v' => 1, 'spec' => ['t' => 'or', 's' => $leaves(10000)]]);
            self::fail('10 001 nodes are refused');
        } catch (SpecificationCodecException $e) {
            self::assertStringContainsString('more than 10000 nodes', $e->getMessage());
            self::assertSame('or[9999].isNull', $e->path);
        }
        $small = new SpecificationCodec(maxNodes: 4);
        self::assertSame(4, $small->getMaxNodes());
        $chain = new OrSpecification(new OrSpecification(Spec::isNull(), Spec::isNull()), Spec::isNull());
        self::assertCount(3, $small->encode($chain)['spec']['s'], 'a chain of three operands is 4 nodes');
        try {
            $small->encode(new NotSpecification($chain));
            self::fail('more nodes than the limit are not written');
        } catch (SpecificationCodecException $e) {
            self::assertStringContainsString('more than 4 nodes', $e->getMessage());
            self::assertSame('not.or[2].isNull', $e->path);
        }
        self::assertTrue($codec->decode($codec->encode(Spec::isNull()))->equals(Spec::isNull()), 'the count starts again on every call');
    }

    public function testLimitsBelowOneAreRefused(): void
    {
        foreach ([[0, 1], [1, 0], [-1, 10]] as [$depth, $nodes]) {
            try {
                new SpecificationCodec($depth, $nodes);
                self::fail('refused');
            } catch (InvalidArgumentException $e) {
                self::assertSame(sprintf('The limits of a specification codec must be at least 1 (depth %d, nodes %d given).', $depth, $nodes), $e->getMessage());
            }
        }
        $minimal = new SpecificationCodec(1, 1);
        self::assertSame('{"v":1,"spec":{"t":"isNull"}}', $minimal->toJson(Spec::isNull()));
    }

    public function testJsonOutput(): void
    {
        $codec = new SpecificationCodec();
        self::assertSame('{"v":1,"spec":{"t":"contains","v":"ação/1"}}', $codec->toJson(Spec::contains('ação/1')), 'unescaped slashes and unicode');
        self::assertSame('{"v":1,"spec":{"t":"eq","v":"<b>\'&\"</b>"}}', $codec->toJson(Spec::equal('<b>\'&"</b>')), 'no extra flag by default');
        self::assertSame("{\n    \"v\": 1,\n    \"spec\": {\n        \"t\": \"isNull\"\n    }\n}", $codec->toJson(Spec::isNull(), JSON_PRETTY_PRINT));
        try {
            $codec->toJson(Spec::equal("\xB1\x31"));
            self::fail('invalid UTF-8 has no JSON form');
        } catch (SpecificationCodecException $e) {
            self::assertSame('The specification has no JSON form: Malformed UTF-8 characters, possibly incorrectly encoded.', $e->getMessage());
            self::assertInstanceOf(\JsonException::class, $e->getPrevious());
        }
        self::assertTrue($codec->decode($codec->encode(Spec::equal("\xB1\x31")))->equals(Spec::equal("\xB1\x31")), 'the array form keeps any text');
    }

    public function testHelpersOfFromArray(): void
    {
        $codec = new SpecificationCodec();
        $data = ['s' => Spec::isNull(), 'l' => [Spec::isNull(), Spec::isNotNull()], 'x' => 'a', 'v' => [1, [2]], 'c' => 'ignore_case'];

        self::assertTrue($codec->specification($data, 's')->equals(Spec::isNull()));
        self::assertCount(2, $codec->specifications($data, 'l', 2, 2));
        self::assertCount(2, $codec->specifications($data, 'l'));
        self::assertCount(1, $codec->specifications(['l' => [Spec::isNull()]], 'l'), 'one specification by default');
        self::assertSame('a', $codec->string($data, 'x'));
        self::assertSame('a', $codec->optionalString($data, 'x', 'd'));
        self::assertSame('d', $codec->optionalString($data, 'missing', 'd'));
        self::assertNull($codec->optionalString($data, 'missing'));
        self::assertSame([1, [2]], $codec->values($data, 'v'));
        self::assertSame(TextComparison::IgnoreCase, $codec->comparison($data, 'c'));
        self::assertSame(TextComparison::CaseSensitive, $codec->comparison($data, 'missing', TextComparison::CaseSensitive));
        self::assertNull($codec->comparison($data, 'missing'));
        $codec->expectKeys($data, ['s', 'l'], ['x', 'v', 'c']);
        self::assertSame('', $codec->invalid('r')->path, 'outside a decode the position is the document');

        foreach ([
            [fn() => $codec->specifications($data, 'l', 3), '"l" must hold at least 3 specification nodes, 2 given.'],
            [fn() => $codec->specifications($data, 'l', 1, 1), '"l" must hold 1 specification nodes, 2 given.'],
            [fn() => $codec->specifications(['l' => [Spec::isNull()]], 'l', 2, 3), '"l" must hold 2 to 3 specification nodes, 1 given.'],
            [fn() => $codec->specifications(['l' => ['a' => Spec::isNull()]], 'l'), '"l" must be a list of specification nodes.'],
            [fn() => $codec->values(['v' => 1], 'v'), '"v" must be a list.'],
            [fn() => $codec->specifications(['l' => []], 'l'), '"l" must hold at least 1 specification nodes, 0 given.'],
            [fn() => $codec->value([], 'v'), 'Missing key "v".'],
            [fn() => $codec->expectKeys(['a' => 1], [], ['b']), 'Unknown key "a" (expected: b).'],
            [fn() => $codec->expectKeys(['a' => 1, 'b' => 2], ['b']), 'Unknown key "a" (expected: b).'],
            [fn() => $codec->string(['x' => null], 'x'), '"x" must be a string.'],
        ] as [$call, $message]) {
            try {
                $call();
                self::fail('refused: ' . $message);
            } catch (SpecificationCodecException $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
    }

    public function testAnnotationsOnEveryKindOfNode(): void
    {
        $codec = new SpecificationCodec();
        $tree = Spec::not(Spec::property('age', Spec::greaterThan(18)->because('adult'))->withCode('P'))->because('minor')->withCode('N');
        $document = $codec->encode($tree);

        self::assertSame(['t', 's', 'because', 'code'], array_keys($document['spec']), 'annotations after the parameters');
        self::assertTrue($codec->decode($document)->equals($tree));
        self::assertFalse($codec->decode($codec->encode($tree->because('other')))->equals($tree));
        $typed = Spec::specify(stdClass::class)->because('typed');
        self::assertTrue($codec->decode($codec->encode($typed))->equals($typed));
        self::assertFalse($codec->decode($codec->encode($typed))->equals(Spec::specify(stdClass::class)));
    }

    public function testPropertyBaseIsWrittenOnlyWhenItIsNotThePlainTautology(): void
    {
        $codec = new SpecificationCodec();
        $plain = new PropertySpecification(new AlwaysTrueSpecification(), 'x', Spec::isNull());
        self::assertArrayNotHasKey('base', $codec->encode($plain)['spec']);
        self::assertTrue($codec->decode($codec->encode($plain))->equals($plain));

        $typed = Spec::specify(stdClass::class)->where('x', Spec::isNull());
        $decoded = $codec->decode($codec->encode($typed));
        self::assertTrue($decoded->equals($typed));
        self::assertTrue($decoded->isSatisfiedBy((object) ['x' => null]));
        self::assertFalse($decoded->isSatisfiedBy(new DateTimeImmutable()), 'the type is kept');
    }

    public function testCodecStateIsRestoredAfterAFailure(): void
    {
        $codec = new SpecificationCodec(maxNodes: 2);
        try {
            $codec->decode(['v' => 1, 'spec' => ['t' => 'nor', 's' => [['t' => 'isNull'], ['t' => 'isNull']]]]);
            self::fail('3 nodes are refused');
        } catch (SpecificationCodecException) {
        }
        self::assertTrue($codec->decode(['v' => 1, 'spec' => ['t' => 'not', 's' => ['t' => 'isNull']]])->equals(Spec::not(Spec::isNull())));
        self::assertSame('', $codec->invalid('r')->path);
    }

    public function testDecodedSpecificationsAnswerAsTheOriginals(): void
    {
        $codec = new SpecificationCodec();
        $rule = Spec::specify(stdClass::class)
            ->where('age', Spec::greaterThanOrEqualTo(18))
            ->and(Spec::property('email', Spec::endsWith('@example.com', false)))
            ->and(Spec::property('plan', Spec::in('gold', 'silver')))
            ->and(Spec::property('since', Spec::before(new DateTimeImmutable('2026-01-01T00:00:00Z'))));
        $decoded = $codec->fromJson($codec->toJson($rule));

        foreach ([
            (object) ['age' => 30, 'email' => 'ANA@EXAMPLE.COM', 'plan' => 'gold', 'since' => new DateTimeImmutable('2025-05-01')],
            (object) ['age' => 17, 'email' => 'ana@example.com', 'plan' => 'gold', 'since' => new DateTimeImmutable('2025-05-01')],
            (object) ['age' => 30, 'email' => 'ana@other.com', 'plan' => 'gold', 'since' => new DateTimeImmutable('2025-05-01')],
            (object) ['age' => 30, 'email' => 'ana@example.com', 'plan' => 'bronze', 'since' => new DateTimeImmutable('2025-05-01')],
            (object) ['age' => 30, 'email' => 'ana@example.com', 'plan' => 'gold', 'since' => new DateTimeImmutable('2026-05-01')],
        ] as $candidate) {
            self::assertSame($rule->isSatisfiedBy($candidate), $decoded->isSatisfiedBy($candidate));
        }
        self::assertInstanceOf(DateTimeInterface::class, (new \ReflectionProperty(\Antevemus\ASpecification\Specifications\Comparison\AbstractComparableValueBoundSpecification::class, 'value'))->getValue($codec->fromJson('{"v":1,"spec":{"t":"lt","v":{"$date":"2026-01-01T00:00:00Z"}}}')));
        self::assertInstanceOf(JointDenialSpecification::class, $codec->fromJson('{"v":1,"spec":{"t":"nor","s":[{"t":"isNull"},{"t":"notNull"}]}}'));
        self::assertInstanceOf(RegexSpecification::class, $codec->fromJson('{"v":1,"spec":{"t":"regex","p":"/a/"}}'));
    }
}
