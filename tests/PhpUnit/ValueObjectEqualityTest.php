<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\PhpUnit;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\AndSpecification;
use Antevemus\ASpecification\Specifications\Comparison\InSpecification;
use Antevemus\ASpecification\Specifications\NotSpecification;
use Antevemus\ASpecification\Specifications\OrSpecification;
use Antevemus\ASpecification\Specifications\PredicateSpecification;
use Antevemus\ASpecification\Specifications\String\TextComparison;
use Closure;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Throwable;

/** A value object whose properties PHP's `==` compares loosely ('1' == 1, null == false). */
final class VoMoney
{
    public function __construct(public readonly mixed $amount)
    {
    }
}

/** A user leaf keeping a value object in its state (the default structural equals() applies). */
final class VoHoldsValueLeaf extends AbstractSpecification
{
    public function __construct(private readonly mixed $reference)
    {
    }

    public function isSatisfiedBy(mixed $candidate): bool
    {
        return $candidate === $this->reference;
    }

    public function getType(): string
    {
        return 'mixed';
    }
}

/**
 * ValueObjectEqualityTest - Regression of BUG-20261013-2DAZ (2.1.0).
 *
 * Up to 2.0.0 AbstractSpecification::specificationValuesEqual() compared an object that is not a
 * specification with `==` (so `equalTo(new Money('1'))` equaled `equalTo(new Money(1))`, and
 * `Money(null)` equaled `Money(false)`), while equalTo()/notEqual()/in() test an object candidate by
 * identity: simplify() merged leaves that answer differently.
 *
 * Date-times (forward 037, D1, BUG-20261013-LIZI): since 2.1.0 equalTo()/notEqual()/in() compare
 * date-times by instant, as the relational leaves, at(), the algebra and SQL do; the equality and the
 * point algebra follow (equalTo($d) equals and generalizes equalTo(clone $d)).
 *
 * Measured semantics, by leaf (the equality may never be looser than this):
 * - equalTo / notEqual / in(): `===` for scalars and objects (identity), `==` (instant) for date-times
 * - looselyEqualTo: `==` (the equality keeps identity, which is stricter)
 * - lessThan/lessThanOrEqualTo/greaterThan/greaterThanOrEqualTo, between(): `<`/`>` (date-times by
 *   instant, whatever class or time zone)
 * - at() (SameInstantSpecification): `==` on date-times (by instant)
 *
 * @version    2.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\PhpUnit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class ValueObjectEqualityTest extends TestCase
{
    /** @var array<string, VoMoney> */
    private static array $money = [];

    /** @var array<string, DateTimeInterface> */
    private static array $dates = [];

    public static function setUpBeforeClass(): void
    {
        self::$money = [
            "M('1')" => new VoMoney('1'),
            'M(1)' => new VoMoney(1),
            'M(1)#2' => new VoMoney(1),
            'M(null)' => new VoMoney(null),
            'M(false)' => new VoMoney(false),
            "M('0')" => new VoMoney('0'),
            'M(2)' => new VoMoney(2),
        ];

        $utc = new DateTimeZone('UTC');
        $d = new DateTime('2026-01-01 01:00:00', $utc);
        self::$dates = [
            'd' => $d,
            'clone(d)' => clone $d,
            'imm(d)' => DateTimeImmutable::createFromMutable($d),
            'tz(d)' => (clone $d)->setTimezone(new DateTimeZone('America/Sao_Paulo')),
            'later' => new DateTimeImmutable('2026-01-02 00:00:00', $utc),
            'clone(later)' => new DateTime('2026-01-02 00:00:00', $utc),
            'earlier' => new DateTimeImmutable('2025-06-01 00:00:00', $utc),
        ];
    }

    /** Reproduction (bug.md, lines of Money). */
    public function testReproductionLooseValueObjectEquality(): void
    {
        self::assertFalse(Spec::equal(new VoMoney('1'))->equals(Spec::equal(new VoMoney(1))));
        self::assertFalse(Spec::equal(new VoMoney(null))->equals(Spec::equal(new VoMoney(false))));

        $c = new VoMoney(1);
        $tree = Spec::equal(new VoMoney('1'))->or(Spec::equal($c));
        self::assertTrue($tree->isSatisfiedBy($c));
        self::assertTrue(Spec::simplify($tree)->isSatisfiedBy($c), 'simplify() changed the answer');
    }

    /**
     * For every leaf that keeps a value, two leaves called equal answer alike for every candidate, and
     * the equalities the evaluation allows are kept.
     */
    public function testEqualityIsNeverLooserThanTheEvaluationOfEachLeaf(): void
    {
        $m = self::$money;
        $d = self::$dates;
        $sameMoney = $m['M(1)'];

        $cases = [
            // [label, left, right, expected equals(), candidates]
            ["equal(M('1')) / equal(M(1))", Spec::equal($m["M('1')"]), Spec::equal($m['M(1)']), false, $m],
            ['equal(M(null)) / equal(M(false))', Spec::equal($m['M(null)']), Spec::equal($m['M(false)']), false, $m],
            ['equal(M(1)) / equal(M(1)#2)', Spec::equal($m['M(1)']), Spec::equal($m['M(1)#2']), false, $m],
            ['equal(M(1)) / equal(same object)', Spec::equal($sameMoney), Spec::equal($sameMoney), true, $m],
            ["notEqual(M('1')) / notEqual(M(1))", Spec::notEqual($m["M('1')"]), Spec::notEqual($m['M(1)']), false, $m],
            ['looselyEqualTo(M(null)) / looselyEqualTo(M(false))', Spec::looselyEqualTo($m['M(null)']), Spec::looselyEqualTo($m['M(false)']), false, $m],
            ['in(M(1)) / in(M(1)#2)', new InSpecification([$m['M(1)']]), new InSpecification([$m['M(1)#2']]), false, $m],
            ['property(equal(M(1))) / property(equal(M(1)#2))', Spec::property('amount', Spec::equal($m['M(1)'])), Spec::property('amount', Spec::equal($m['M(1)#2'])), false, array_map(static fn($x) => new VoMoney($x), $m)],
            ['user leaf(M(1)) / user leaf(M(1)#2)', new VoHoldsValueLeaf($m['M(1)']), new VoHoldsValueLeaf($m['M(1)#2']), false, $m],
            ['user leaf(same) / user leaf(same)', new VoHoldsValueLeaf($sameMoney), new VoHoldsValueLeaf($sameMoney), true, $m],
            ['user leaf(d) / user leaf(clone d)', new VoHoldsValueLeaf($d['d']), new VoHoldsValueLeaf($d['clone(d)']), false, $d],
            // by instant, as the evaluation compares (equalTo/notEqual/in since 2.1.0, D1)
            ['equal(d) / equal(clone d)', Spec::equal($d['d']), Spec::equal($d['clone(d)']), true, $d],
            ['equal(d) / equal(imm d)', Spec::equal($d['d']), Spec::equal($d['imm(d)']), true, $d],
            ['equal(d) / equal(tz d)', Spec::equal($d['d']), Spec::equal($d['tz(d)']), true, $d],
            ['equal(d) / equal(d)', Spec::equal($d['d']), Spec::equal($d['d']), true, $d],
            ['notEqual(d) / notEqual(clone d)', Spec::notEqual($d['d']), Spec::notEqual($d['clone(d)']), true, $d],
            ['in(d) / in(clone d)', new InSpecification([$d['d']]), new InSpecification([$d['clone(d)']]), true, $d],
            ['in(d, clone d) / in(imm d)', new InSpecification([$d['d'], $d['clone(d)']]), new InSpecification([$d['imm(d)']]), true, $d],
            ['equal(d) / equal(later)', Spec::equal($d['d']), Spec::equal($d['later']), false, $d],
            ['at(d) / at(imm d)', Spec::at($d['d']), Spec::at($d['imm(d)']), true, $d],
            ['at(d) / at(tz d)', Spec::at($d['d']), Spec::at($d['tz(d)']), true, $d],
            ['before(d) / before(d)', Spec::before($d['d']), Spec::before($d['d']), true, $d],
            ['before(d) / before(imm d)', Spec::before($d['d']), Spec::before($d['imm(d)']), true, $d],
            ['lessThanOrEqualTo(d) / lessThanOrEqualTo(tz d)', Spec::lessThanOrEqualTo($d['d']), Spec::lessThanOrEqualTo($d['tz(d)']), true, $d],
            ['after(d) / after(clone d)', Spec::after($d['d']), Spec::after($d['clone(d)']), true, $d],
            ['greaterThanOrEqualTo(d) / greaterThanOrEqualTo(imm d)', Spec::greaterThanOrEqualTo($d['d']), Spec::greaterThanOrEqualTo($d['imm(d)']), true, $d],
            ['between(d, later) / between(tz d, clone later)', Spec::between($d['d'], $d['later']), Spec::between($d['tz(d)'], $d['clone(later)']), true, $d],
            ['before(d) / before(later)', Spec::before($d['d']), Spec::before($d['later']), false, $d],
            ['at(d) / at(later)', Spec::at($d['d']), Spec::at($d['later']), false, $d],
        ];

        foreach ($cases as [$label, $left, $right, $expected, $candidates]) {
            self::assertSame($expected, $left->equals($right), $label);
            self::assertSame($expected, $right->equals($left), $label . ' (symmetry)');
            if ($expected) {
                foreach ($candidates as $name => $candidate) {
                    self::assertSame(self::answer($left, $candidate), self::answer($right, $candidate), "$label: equal leaves answer differently for $name");
                }
            } else {
                $differ = false;
                foreach ($candidates as $candidate) {
                    $differ = $differ || self::answer($left, $candidate) !== self::answer($right, $candidate);
                }
                self::assertTrue($differ, "$label: the pair should be told apart by a candidate of the pool");
            }
        }
    }

    /** Other object state (closures, enum cases, arrays of objects) keeps the identity rule. */
    public function testOtherObjectStateIsComparedByIdentity(): void
    {
        $closure = static fn(mixed $x): bool => $x === 1;
        self::assertTrue((new PredicateSpecification($closure))->equals(new PredicateSpecification($closure)));
        self::assertFalse((new PredicateSpecification($closure))->equals(new PredicateSpecification(static fn(mixed $x): bool => $x === 1)));
        self::assertTrue(Spec::contains('a', TextComparison::IgnoreCase)->equals(Spec::contains('a', TextComparison::IgnoreCase)), 'enum case');
        $a = new VoMoney(1);
        self::assertTrue((new InSpecification([$a, 2]))->equals(new InSpecification([2, $a])), 'set equality ignores order');
        self::assertFalse((new InSpecification([$a, 2]))->equals(new InSpecification([new VoMoney(1), 2])));
    }

    /** The equality points of the algebra follow the evaluation: date-times by instant (2.1.0, D1). */
    public function testDatePointsOfTheAlgebraFollowTheEvaluation(): void
    {
        $d = self::$dates;
        $equalD = Spec::equal($d['d']);
        $equalClone = Spec::equal($d['clone(d)']);

        self::assertTrue($equalD->isGeneralizationOf($equalClone), 'clone(d) is the same instant');
        self::assertTrue($equalD->isGeneralizationOf(Spec::at($d['tz(d)'])), 'at(tz d) accepts the date-times equalTo(d) accepts');
        self::assertTrue(Spec::at($d['d'])->isGeneralizationOf(Spec::equal($d['imm(d)'])));
        self::assertTrue(Spec::notEqual($d['d'])->isGeneralizationOf(Spec::notEqual($d['clone(d)'])));
        self::assertFalse(Spec::notEqual($d['d'])->isGeneralizationOf($equalClone), 'notEqual(d) refuses clone(d)');
        self::assertTrue(Spec::notEqual($d['d'])->isDisjointWith($equalClone), 'clone(d) is the instant notEqual(d) refuses');
        self::assertTrue(Spec::notEqual($d['d'])->isDisjointWith($equalD));
        self::assertFalse(Spec::notEqual($d['d'])->isDisjointWith(Spec::equal($d['later'])));
        self::assertTrue(Spec::at($d['d'])->isDisjointWith(Spec::not(Spec::at($d['d']))));
        self::assertTrue(Spec::notEqual($d['d'])->isGeneralizationOf(Spec::not(Spec::at($d['d']))));
        self::assertTrue(Spec::isTautology(new OrSpecification($equalD, $equalClone->not())), 'every non-null candidate satisfies one side');
        self::assertTrue(Spec::isContradiction(new AndSpecification(Spec::notEqual($d['d']), $equalClone)), 'no candidate satisfies both');
        self::assertTrue(Spec::in($d['d'])->isGeneralizationOf(Spec::equal($d['tz(d)'])), 'Module 1: in() takes date-times by instant');

        // Every proof of the algebra holds for the candidates of the pool.
        $leaves = [];
        foreach ($d as $name => $value) {
            $leaves["equal($name)"] = Spec::equal($value);
            $leaves["notEqual($name)"] = Spec::notEqual($value);
            $leaves["at($name)"] = Spec::at($value);
            $leaves["not at($name)"] = Spec::not(Spec::at($value));
            $leaves["before($name)"] = Spec::before($value);
            $leaves["afterOrAt($name)"] = Spec::afterOrAt($value);
        }
        $candidates = self::dateCandidates();
        foreach ($leaves as $a => $left) {
            foreach ($leaves as $b => $right) {
                if ($left->isGeneralizationOf($right)) {
                    foreach ($candidates as $name => $candidate) {
                        self::assertFalse($right->isSatisfiedBy($candidate) && !$left->isSatisfiedBy($candidate), "$a ⊇ $b is false for $name");
                    }
                }
                if ($left->isDisjointWith($right)) {
                    foreach ($candidates as $name => $candidate) {
                        self::assertFalse($left->isSatisfiedBy($candidate) && $right->isSatisfiedBy($candidate), "$a ⟂ $b is false for $name");
                    }
                }
            }
        }
    }

    public function testSimplifyKeepsTheAnswerOfFixedTrees(): void
    {
        $m = self::$money;
        $d = self::$dates;
        $c = new VoMoney(1);

        $money = $m + ['$c' => $c];
        foreach ([
            "equal(M('1')) OR equal(\$c)" => new OrSpecification(Spec::equal($m["M('1')"]), Spec::equal($c)),
            'notEqual(M(null)) AND notEqual(M(false))' => new AndSpecification(Spec::notEqual($m['M(null)']), Spec::notEqual($m['M(false)'])),
            'equal(M(1)) OR NOT equal(M(1)#2)' => new OrSpecification(Spec::equal($m['M(1)']), Spec::not(Spec::equal($m['M(1)#2']))),
        ] as $label => $tree) {
            self::assertSameAnswers($tree, Spec::simplify($tree), $money, $label);
        }

        foreach ([
            'equal(d) OR equal(clone d)' => new OrSpecification(Spec::equal($d['d']), Spec::equal($d['clone(d)'])),
            'equal(d) OR equal(imm d)' => new OrSpecification(Spec::equal($d['d']), Spec::equal($d['imm(d)'])),
            'notEqual(d) AND equal(clone d)' => new AndSpecification(Spec::notEqual($d['d']), Spec::equal($d['clone(d)'])),
            'notEqual(d) AND notEqual(clone d)' => new AndSpecification(Spec::notEqual($d['d']), Spec::notEqual($d['clone(d)'])),
            'at(d) AND equal(d)' => new AndSpecification(Spec::at($d['d']), Spec::equal($d['d'])),
            'equal(d) OR NOT equal(clone d)' => new OrSpecification(Spec::equal($d['d']), Spec::not(Spec::equal($d['clone(d)']))),
            'NOT at(d) AND notEqual(d)' => new AndSpecification(Spec::not(Spec::at($d['d'])), Spec::notEqual($d['d'])),
        ] as $label => $tree) {
            self::assertSameAnswers($tree, Spec::simplify($tree), self::dateCandidates(), $label);
        }
    }

    /**
     * RN-02 battery with value objects: equalTo/notEqual/in over objects that PHP's `==` confuses
     * (distinct alike objects, '1'/1, null/false), against a reference closure.
     */
    public function testRandomValueObjectTreesAgreeWithTheirSimplificationAndAReference(): void
    {
        $pool = array_values(self::$money);
        $candidates = self::$money + ['fresh M(1)' => new VoMoney(1), "fresh M('1')" => new VoMoney('1'), 'fresh M(null)' => new VoMoney(null)];

        $leaf = static function () use ($pool): array {
            $value = $pool[mt_rand(0, count($pool) - 1)];
            $other = $pool[mt_rand(0, count($pool) - 1)];
            $kind = mt_rand(0, 2);
            $make = match ($kind) {
                0 => static fn(): ISpecification => Spec::equal($value),
                1 => static fn(): ISpecification => Spec::notEqual($value),
                default => static fn(): ISpecification => new InSpecification([$value, $other]),
            };
            $reference = match ($kind) {
                0 => static fn(mixed $c): bool => $c === $value,
                1 => static fn(mixed $c): bool => $c !== $value,
                default => static fn(mixed $c): bool => $c === $value || $c === $other,
            };

            return [$make, $reference, ['equal', 'notEqual', 'in'][$kind] . '#' . spl_object_id($value)];
        };

        self::runBattery(20261013, 300, $leaf, $candidates);
    }

    /**
     * RN-02 battery with date-times: equalTo/notEqual/in() and at() (instant, 2.1.0 D1), the
     * relational leaves and between() (instant) over date-times of different objects, classes and time
     * zones at the same instant.
     */
    public function testRandomDateTreesAgreeWithTheirSimplificationAndAReference(): void
    {
        $pool = array_values(self::$dates);

        $leaf = static function () use ($pool): array {
            $value = $pool[mt_rand(0, count($pool) - 1)];
            $end = $pool[mt_rand(0, count($pool) - 1)];
            $kind = mt_rand(0, 8);
            $make = match ($kind) {
                0 => static fn(): ISpecification => Spec::equal($value),
                1 => static fn(): ISpecification => Spec::notEqual($value),
                2 => static fn(): ISpecification => Spec::at($value),
                3 => static fn(): ISpecification => Spec::lessThan($value),
                4 => static fn(): ISpecification => Spec::lessThanOrEqualTo($value),
                5 => static fn(): ISpecification => Spec::greaterThan($value),
                6 => static fn(): ISpecification => Spec::greaterThanOrEqualTo($value),
                7 => static fn(): ISpecification => Spec::between($value, $end),
                default => static fn(): ISpecification => Spec::in($value, $end),
            };
            $reference = match ($kind) {
                0 => static fn(mixed $c): bool => $c == $value,
                1 => static fn(mixed $c): bool => $c != $value,
                2 => static fn(mixed $c): bool => $c == $value,
                3 => static fn(mixed $c): bool => $c < $value,
                4 => static fn(mixed $c): bool => $c <= $value,
                5 => static fn(mixed $c): bool => $c > $value,
                6 => static fn(mixed $c): bool => $c >= $value,
                7 => static fn(mixed $c): bool => $c >= $value && $c <= $end,
                default => static fn(mixed $c): bool => $c == $value || $c == $end,
            };

            return [$make, $reference, ['equal', 'notEqual', 'at', 'lt', 'le', 'gt', 'ge', 'between', 'in'][$kind] . '#' . spl_object_id($value)];
        };

        self::runBattery(20261014, 300, $leaf, self::dateCandidates());
    }

    // ==========================================
    // Helpers
    // ==========================================

    /** @return array<string, DateTimeInterface> */
    private static function dateCandidates(): array
    {
        return self::$dates + [
            'fresh imm(d)' => new DateTimeImmutable('2026-01-01 01:00:00', new DateTimeZone('UTC')),
            'fresh d(-03:00)' => new DateTime('2025-12-31 22:00:00', new DateTimeZone('-03:00')),
            'middle' => new DateTimeImmutable('2026-01-01 12:00:00', new DateTimeZone('UTC')),
            'fresh later' => new DateTimeImmutable('2026-01-02 00:00:00', new DateTimeZone('UTC')),
        ];
    }

    private static function answer(ISpecification $specification, mixed $candidate): string
    {
        try {
            return $specification->isSatisfiedBy($candidate) ? 'true' : 'false';
        } catch (Throwable $e) {
            return $e::class;
        }
    }

    /** @param array<string, mixed> $candidates */
    private static function assertSameAnswers(ISpecification $tree, ISpecification $simplified, array $candidates, string $label): void
    {
        foreach ($candidates as $name => $candidate) {
            self::assertSame(self::answer($tree, $candidate), self::answer($simplified, $candidate), "simplify($label) changed the answer for $name");
        }
    }

    /**
     * Each tree is built twice (direct constructors and the fluent API, whose and()/or() deduplicate
     * equal operands, from fresh leaf instances that share the values) next to a reference closure;
     * the four answers must match the reference for every candidate.
     *
     * @param Closure(): array{0: Closure(): ISpecification, 1: Closure(mixed): bool, 2: string} $leaf
     * @param array<string, mixed> $candidates
     */
    private static function runBattery(int $seed, int $trees, Closure $leaf, array $candidates): void
    {
        mt_srand($seed);
        $checkedFluent = 0;
        $shrunk = 0;

        for ($n = 0; $n < $trees; $n++) {
            [$direct, $fluent, $reference, $text] = self::randomTree(3, $leaf);
            $simplifiedDirect = Spec::simplify($direct);
            $simplifiedFluent = $fluent !== null ? Spec::simplify($fluent) : null;
            $checkedFluent += $fluent !== null ? 1 : 0;
            $shrunk += $simplifiedDirect !== $direct ? 1 : 0;

            foreach ($candidates as $name => $candidate) {
                $expected = $reference($candidate) ? 'true' : 'false';
                $context = sprintf('tree #%d %s, candidate %s', $n, $text, $name);
                self::assertSame($expected, self::answer($direct, $candidate), 'direct tree: ' . $context);
                self::assertSame($expected, self::answer($simplifiedDirect, $candidate), 'simplify(direct): ' . $context);
                if ($fluent !== null) {
                    self::assertSame($expected, self::answer($fluent, $candidate), 'fluent tree: ' . $context);
                    self::assertSame($expected, self::answer($simplifiedFluent, $candidate), 'simplify(fluent): ' . $context);
                }
            }
        }

        self::assertGreaterThan(100, $checkedFluent, 'Too few fluent trees were built to exercise and()/or()');
        self::assertGreaterThan(30, $shrunk, 'Too few trees were simplified to exercise simplify()');
    }

    /**
     * @param Closure(): array{0: Closure(): ISpecification, 1: Closure(mixed): bool, 2: string} $leaf
     * @return array{0: ISpecification, 1: ISpecification|null, 2: Closure(mixed): bool, 3: string}
     */
    private static function randomTree(int $depth, Closure $leaf): array
    {
        if ($depth === 0 || mt_rand(0, 3) === 0) {
            [$make, $reference, $text] = $leaf();

            // The fluent tree gets its own leaf instance: equal leaves meet only through equals().
            return [$make(), $make(), $reference, $text];
        }

        $op = mt_rand(0, 2);
        if ($op === 0) {
            [$d, $f, $r, $t] = self::randomTree($depth - 1, $leaf);

            return [new NotSpecification($d), $f?->not(), static fn(mixed $c): bool => !$r($c), 'NOT ' . $t];
        }

        [$d1, $f1, $r1, $t1] = self::randomTree($depth - 1, $leaf);
        [$d2, $f2, $r2, $t2] = self::randomTree($depth - 1, $leaf);
        $fluent = null;
        try {
            if ($f1 !== null && $f2 !== null) {
                $fluent = $op === 1 ? $f1->and($f2) : $f1->or($f2);
            }
        } catch (InvalidArgumentException) {
            // A composite refuses the conjunction of disjoint operands: only the direct tree is checked.
        }

        return $op === 1
            ? [new AndSpecification($d1, $d2), $fluent, static fn(mixed $c): bool => $r1($c) && $r2($c), "({$t1} AND {$t2})"]
            : [new OrSpecification($d1, $d2), $fluent, static fn(mixed $c): bool => $r1($c) || $r2($c), "({$t1} OR {$t2})"];
    }
}
