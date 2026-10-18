<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\PhpUnit;

use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Entities\AbstractEntity;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\AndSpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysFalseSpecification;
use Antevemus\ASpecification\Specifications\Collection\AllEntitiesSpecification;
use Antevemus\ASpecification\Specifications\Collection\UniqueEntitySpecification;
use Antevemus\ASpecification\Specifications\Comparison\InSpecification;
use Antevemus\ASpecification\Specifications\Comparison\IsNullSpecification;
use Antevemus\ASpecification\Specifications\Comparison\NotNullSpecification;
use Antevemus\ASpecification\Specifications\Exceptions\IncompatibleTypeException;
use Antevemus\ASpecification\Specifications\Logical\DefaultValueSpecification;
use Antevemus\ASpecification\Specifications\NotSpecification;
use Antevemus\ASpecification\Specifications\SpecificationAlgebra;
use Antevemus\ASpecification\Specifications\OrSpecification;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class AscMoney
{
    public function __construct(public mixed $amount)
    {
    }
}

class AscCustomer extends AbstractEntity
{
    public function __construct(private readonly mixed $id, public readonly bool $active = true)
    {
        parent::__construct();
    }

    public function getEntityId(): mixed
    {
        return $this->id;
    }
}

final class AscVip extends AscCustomer
{
}

final class AscOrder extends AbstractEntity
{
    public function __construct(private readonly mixed $id)
    {
        parent::__construct();
    }

    public function getEntityId(): mixed
    {
        return $this->id;
    }
}

/** Only the active customers: narrower than every customer. */
final class AscActiveCustomers extends AllEntitiesSpecification
{
    public function isSatisfiedBy(?object $candidate): bool
    {
        return parent::isSatisfiedBy($candidate) && $candidate instanceof AscCustomer && $candidate->active;
    }
}

/** isNull() that also takes the empty text. */
final class AscBlank extends IsNullSpecification
{
    public function isSatisfiedBy(mixed $candidate): bool
    {
        return $candidate === null || $candidate === '';
    }
}

/** notNull() that also refuses the empty text. */
final class AscPresent extends NotNullSpecification
{
    public function isSatisfiedBy(mixed $candidate): bool
    {
        return $candidate !== null && $candidate !== '';
    }
}

/** in() that also takes the numeric text of a member. */
final class AscNumericTextIn extends InSpecification
{
    public function isSatisfiedBy(mixed $candidate): bool
    {
        return (is_string($candidate) && is_numeric($candidate) && in_array((int) $candidate, $this->getValues(), true))
            || parent::isSatisfiedBy($candidate);
    }
}

/** A negation with a predicate of its own: what it raises on is not read by the simplifier. */
final class AscStrictNot extends NotSpecification
{
    public function isSatisfiedBy(mixed $candidate): bool
    {
        if (!is_int($candidate)) {
            throw new \InvalidArgumentException('integers only');
        }

        return parent::isSatisfiedBy($candidate);
    }
}

/** A conjunction with a predicate of its own. */
final class AscStrictAnd extends AndSpecification
{
    public function isSatisfiedBy(mixed $candidate): bool
    {
        if (!is_int($candidate)) {
            throw new \InvalidArgumentException('integers only');
        }

        return parent::isSatisfiedBy($candidate);
    }
}

final class AscClock
{
    public function day(DateTimeInterface $at): string
    {
        return $at->format('Y-m-d');
    }

    public function zone(DateTimeInterface $at): string
    {
        return $at->getTimezone()->getName();
    }
}

/**
 * AlgebraSoundnessCasesTest - One reproduction per class of violation found by the soundness bench
 * (forward 037, D3/D4), each failing with the code of the 2.1.0 work before the forward.
 *
 * - D1 (BUG-20261013-LIZI): equalTo()/notEqual()/in() compare date-times by instant, and the algebra
 *   follows (D4 case 1)
 * - D4 case 2: calling() passes date-time arguments by instant, as its equals() compares them
 * - V1: null candidates (composites refuse null, some leaves accept it)
 * - V2 (D4 case 4): looselyEqualTo() inherited the strict algebra and inversion of equalTo()
 * - V3 (D4 case 3): a subclass with a predicate of its own inherited the algebra of its parent
 * - V4 (D4 case 5): type rules read getType() of specifications not bounded by it
 * - V5: interval rules on texts that PHP compares as numbers
 * - V6 (D4 case 6): the wrapper of Spec::a() with an asymmetric equals() that ignored its annotations
 * - P2 (BUG-20261013-ST36): integer bounds were closed (x > 10 ≡ x >= 11) although the leaves accept floats
 * - #71 (BUG-20261013-T6CS): subsumption dropped an operand that decided first, and the operand left
 *   raised where the original chain answered
 * - #69 (BUG-20261013-ZYEC): the absorbing constant went after the kept annotated operands
 * - P1 / #68 (BUG-20261013-JPQT, 2.2.0): typed default values accepted null, so the two the algebra declared
 *   disjoint shared a candidate; a typed default now refuses null ("is a text and it is blank")
 * - P4 / #70 (BUG-20261013-XEIB, 2.2.0): NAN satisfied ¬(x < v) and not its inversion x >= v; the ordered
 *   leaves now refuse it as a candidate and as a bound
 *
 * @version    2.2.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\PhpUnit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class AlgebraSoundnessCasesTest extends TestCase
{
    public function testD1DateTimesAreComparedByInstantByEqualNotEqualAndIn(): void
    {
        $a = new DateTimeImmutable('2025-01-01 10:00:00', new DateTimeZone('UTC'));
        $sameInstant = [
            'clone' => new DateTimeImmutable('2025-01-01 10:00:00', new DateTimeZone('UTC')),
            'mutable' => new DateTime('2025-01-01 10:00:00', new DateTimeZone('UTC')),
            'other zone' => $a->setTimezone(new DateTimeZone('America/Sao_Paulo')),
        ];
        foreach ($sameInstant as $label => $b) {
            self::assertTrue(Spec::equal($a)->isSatisfiedBy($b), "equal: {$label}");
            self::assertFalse(Spec::notEqual($a)->isSatisfiedBy($b), "notEqual: {$label}");
            self::assertTrue(Spec::in($a)->isSatisfiedBy($b), "in: {$label}");
            self::assertSame(Spec::at($a)->isSatisfiedBy($b), Spec::equal($a)->isSatisfiedBy($b), "as at(): {$label}");
        }
        $later = $a->modify('+1 second');
        self::assertFalse(Spec::equal($a)->isSatisfiedBy($later));
        self::assertTrue(Spec::notEqual($a)->isSatisfiedBy($later));
        self::assertFalse(Spec::in($a)->isSatisfiedBy($later));

        // Objects that are not date-times keep the identity
        $m = new AscMoney(1);
        self::assertFalse(Spec::equal($m)->isSatisfiedBy(new AscMoney(1)));
        self::assertTrue(Spec::notEqual($m)->isSatisfiedBy(new AscMoney(1)));
        self::assertFalse(Spec::in($m)->isSatisfiedBy(new AscMoney(1)));

        // The equality and the algebra follow (D4 case 1: the algebra of in() already took instants)
        self::assertTrue(Spec::equal($a)->equals(Spec::equal($sameInstant['other zone'])));
        self::assertTrue(Spec::in($a)->equals(Spec::in($sameInstant['mutable'], $sameInstant['clone'])));
        self::assertTrue(Spec::in($a)->isGeneralizationOf(Spec::equal($sameInstant['clone'])));
        self::assertTrue(Spec::notEqual($a)->isDisjointWith(Spec::equal($sameInstant['mutable'])));
        self::assertTrue(Spec::isContradiction(new AndSpecification(Spec::equal($a), Spec::notEqual($sameInstant['other zone']))));

        // A date-time value no longer bounds the candidates by its class
        self::assertFalse(Spec::specify(DateTime::class)->isDisjointWith(Spec::equal($a)), 'the mutable date-time satisfies both');
        self::assertFalse(Spec::specify(DateTimeImmutable::class)->isGeneralizationOf(Spec::equal($a)), 'equal(immutable) accepts a DateTime');
        self::assertFalse(Spec::specify(DateTimeImmutable::class)->isGeneralizationOf(Spec::in($a)));
        self::assertTrue(Spec::specify(DateTimeInterface::class)->isGeneralizationOf(Spec::equal($a)));
    }

    public function testD4Case2MethodCallsReceiveDateTimeArgumentsByInstant(): void
    {
        $utc = new DateTimeImmutable('2026-01-01 01:00:00', new DateTimeZone('UTC'));
        $saoPaulo = $utc->setTimezone(new DateTimeZone('America/Sao_Paulo'));
        $byUtc = Spec::calling('day', [$utc], Spec::equal('2026-01-01'));
        $bySaoPaulo = Spec::calling('day', [$saoPaulo], Spec::equal('2026-01-01'));

        self::assertTrue($byUtc->equals($bySaoPaulo), 'forward 018 RN-04: date-time arguments by instant');
        self::assertSame($byUtc->isSatisfiedBy(new AscClock()), $bySaoPaulo->isSatisfiedBy(new AscClock()), 'equal specifications answer alike');
        self::assertTrue(Spec::calling('zone', [$saoPaulo], Spec::equal(date_default_timezone_get()))->isSatisfiedBy(new AscClock()), 'the argument arrives in the default time zone');
        self::assertTrue(Spec::calling('zone', [new DateTime('2026-01-01 01:00:00', new DateTimeZone('Asia/Tokyo'))], Spec::equal(date_default_timezone_get()))->isSatisfiedBy(new AscClock()));
    }

    public function testV1NullCandidatesAreRefusedByCompositesAndAcceptedBySomeLeaves(): void
    {
        // isNull(), equalTo(null), defaultValue() and alwaysTrue() accept null; NOT/OR/AND and notNull() refuse it
        self::assertFalse(Spec::notIn(1, 2)->isGeneralizationOf(Spec::isNull()), 'notIn(1, 2) = ¬in(1, 2) refuses null');
        self::assertFalse(Spec::not(Spec::equal(5))->isGeneralizationOf(Spec::isNull()), '¬(x = 5) refuses null');
        self::assertFalse(Spec::isNotNull()->isGeneralizationOf(Spec::isNull()));
        self::assertFalse(Spec::isNotNull()->isGeneralizationOf(Spec::equal(null)));
        self::assertFalse(Spec::isNotNull()->isGeneralizationOf(Spec::alwaysTrue()));
        self::assertTrue(Spec::isNotNull()->isGeneralizationOf(Spec::equal(1)));
        self::assertFalse(Spec::notEqual(5)->isGeneralizationOf(Spec::equal(null)));
        self::assertFalse((new OrSpecification(Spec::isNull(), Spec::equal(1)))->isGeneralizationOf(Spec::isNull()));
        self::assertFalse((new AndSpecification(Spec::isNull(), Spec::isNull()))->isGeneralizationOf(Spec::isNull()));

        // The negations resolve to forms that refuse null too
        self::assertFalse(Spec::not(Spec::isNotNull())->isGeneralizationOf(Spec::isNull()), '¬notNull() is empty, not isNull()');
        self::assertFalse(Spec::not(Spec::notEqual(null))->isGeneralizationOf(Spec::equal(null)));
        self::assertFalse(Spec::not(Spec::not(Spec::isNull()))->isGeneralizationOf(Spec::isNull()), '¬¬isNull() refuses null');
        self::assertFalse(Spec::not(Spec::alwaysFalse())->isGeneralizationOf(Spec::isNull()), '¬∅ is notNull(), not alwaysTrue()');
        self::assertTrue(Spec::not(Spec::alwaysFalse())->isGeneralizationOf(Spec::equal(1)), 'Module 1: ¬∅ still generalizes the non-null values');

        foreach ([Spec::not(Spec::equal(5)), Spec::isNotNull(), Spec::not(Spec::isNotNull()), Spec::not(Spec::not(Spec::isNull()))] as $refusing) {
            self::assertFalse($refusing->isSatisfiedBy(null));
        }
    }

    public function testV2LooseEqualityHasItsOwnAlgebra(): void
    {
        self::assertTrue(Spec::looselyEqualTo(1)->isSatisfiedBy(1) && Spec::looselyEqualTo('1')->isSatisfiedBy(1));
        self::assertFalse(Spec::looselyEqualTo(1)->isDisjointWith(Spec::looselyEqualTo('1')), '1 satisfies both');
        self::assertFalse(Spec::equal(1)->isDisjointWith(Spec::looselyEqualTo('1')), '1 satisfies both');
        self::assertFalse(Spec::isContradiction(new AndSpecification(Spec::equal(1), Spec::looselyEqualTo('1'))));
        $tree = new AndSpecification(Spec::equal(1), Spec::looselyEqualTo('1'));
        self::assertTrue($tree->isSatisfiedBy(1));
        self::assertTrue(Spec::simplify($tree)->isSatisfiedBy(1), 'simplify() changed the answer');

        $m = new AscMoney(1);
        self::assertFalse(Spec::equal($m)->isGeneralizationOf(Spec::looselyEqualTo($m)), 'Money(1)#2 satisfies only the loose one');
        self::assertFalse(Spec::in($m)->isGeneralizationOf(Spec::looselyEqualTo($m)));
        self::assertFalse(Spec::notEqual($m)->isDisjointWith(Spec::looselyEqualTo($m)));

        // ¬looselyEqualTo('1') is not notEqualTo('1'): 1 is loosely equal to '1'
        self::assertFalse(Spec::not(Spec::looselyEqualTo('1'))->isSatisfiedBy(1));
        self::assertFalse(Spec::not(Spec::looselyEqualTo('1'))->isGeneralizationOf(Spec::equal(1)));
        self::assertInstanceOf(NotSpecification::class, Spec::looselyEqualTo('1')->invert());
    }

    public function testV3SubclassesWithAPredicateOfTheirOwnGetTheSharedAxiomsOnly(): void
    {
        $active = new AscActiveCustomers(AscCustomer::class);
        $all = new AllEntitiesSpecification(AscCustomer::class);
        $inactive = new AscCustomer(1, false);

        self::assertFalse($active->isGeneralizationOf($all), 'an inactive customer satisfies only all(Customer)');
        self::assertFalse($active->isGeneralizationOf(Spec::specify(AscCustomer::class)));
        $tree = new AndSpecification($all, $active);
        self::assertFalse($tree->isSatisfiedBy($inactive));
        self::assertFalse(Spec::simplify($tree)->isSatisfiedBy($inactive), 'simplify() dropped the narrower operand');
        self::assertFalse(Spec::not($active)->isDisjointWith($all), 'the inactive customer satisfies both');

        self::assertFalse((new AscBlank())->isDisjointWith(Spec::lessThan('b')), "'' satisfies both");
        self::assertFalse((new AscBlank())->isDisjointWith(Spec::isNotNull()));
        self::assertFalse(Spec::not(new AscBlank())->isGeneralizationOf(Spec::isNotNull()), "'' satisfies notNull() only");
        self::assertFalse((new AscPresent())->isGeneralizationOf(Spec::notEqual('a')), "'' satisfies notEqual('a') only");
        self::assertFalse((new AscNumericTextIn([1, 5]))->isDisjointWith(Spec::equal('1')), "'1' satisfies both");
    }

    public function testV4TypesProveDisjointnessOnlyForSpecificationsBoundedByThem(): void
    {
        $vip = new AscVip(1);
        self::assertTrue((new UniqueEntitySpecification(1, AscCustomer::class))->isSatisfiedBy($vip) && (new AllEntitiesSpecification(AscVip::class))->isSatisfiedBy($vip));
        self::assertFalse((new UniqueEntitySpecification(1, AscCustomer::class))->isDisjointWith(new AllEntitiesSpecification(AscVip::class)), 'D4 case 5');
        self::assertFalse((new UniqueEntitySpecification(1, AscCustomer::class))->isDisjointWith(Spec::specify(AscVip::class)));
        self::assertTrue((new UniqueEntitySpecification(1, AscCustomer::class))->isDisjointWith(new AllEntitiesSpecification(AscOrder::class)), 'customers are never orders');

        $customer = new AscCustomer(1);
        $notOrders = Spec::not(new AllEntitiesSpecification(AscOrder::class));
        self::assertTrue($notOrders->isSatisfiedBy($customer));
        self::assertFalse((new AllEntitiesSpecification(AscCustomer::class))->isDisjointWith($notOrders), 'Customer#1 satisfies both');
        self::assertFalse(Spec::specify(AscCustomer::class)->isDisjointWith($notOrders));
        self::assertFalse((new UniqueEntitySpecification(1, AscCustomer::class))->isDisjointWith(Spec::notEqual(new DateTimeImmutable())), 'notEqual(d) accepts any object');
        $tree = new AndSpecification(new AllEntitiesSpecification(AscCustomer::class), $notOrders);
        self::assertTrue(Spec::simplify($tree)->isSatisfiedBy($customer), 'simplify() turned a satisfiable conjunction into alwaysFalse()');
    }

    public function testV5TextBoundsAreOrderedOnlyWhenNeitherIsNumeric(): void
    {
        // PHP compares numeric strings as numbers and the rest byte by byte
        self::assertTrue(Spec::greaterThan('10')->isSatisfiedBy('1a') && !Spec::greaterThan('9')->isSatisfiedBy('1a'));
        self::assertFalse(Spec::greaterThan('9')->isGeneralizationOf(Spec::greaterThan('10')));
        self::assertFalse(Spec::greaterThan('10')->isDisjointWith(Spec::lessThanOrEqualTo('1e1')), "'1a' satisfies both");
        self::assertFalse(Spec::lessThan('10')->isGeneralizationOf(Spec::not(Spec::greaterThan('9'))));
        // Non-numeric texts keep their interval algebra
        self::assertTrue(Spec::greaterThan('a')->isGeneralizationOf(Spec::greaterThan('b')));
        self::assertTrue(Spec::lessThan('a')->isDisjointWith(Spec::greaterThan('b')));
    }

    public function testV6TheWrapperOfSpecAComparesItsAnnotationsSymmetrically(): void
    {
        $leaf = Spec::greaterThan(5);
        $wrapper = Spec::getFactory()->a($leaf);

        self::assertFalse($wrapper->because('x')->equals($wrapper), 'D4 case 6: the annotation changes what evaluate() reports');
        self::assertFalse($wrapper->equals($wrapper->withCode('C')));
        self::assertFalse($wrapper->equals($leaf), 'different classes, as $leaf->equals($wrapper)');
        self::assertFalse($leaf->equals($wrapper));
        self::assertTrue($wrapper->equals(Spec::getFactory()->a(Spec::greaterThan(5))));
        self::assertTrue($wrapper->because('x')->equals(Spec::getFactory()->a(Spec::greaterThan(5))->because('x')));
        self::assertTrue($wrapper->isGeneralizationOf(Spec::greaterThan(7)), 'the algebra still delegates to the wrapped leaf');

        $customers = Spec::getFactory()->a(new AllEntitiesSpecification(AscCustomer::class));
        $tree = new AndSpecification($customers->because('first'), $customers->because('second'));
        self::assertSame(['first', 'second'], array_map(static fn($f) => $f->message, Spec::simplify($tree)->evaluate(new AscOrder(1))->failures), 'simplify() merged wrappers that report differently');
    }

    public function testP2IntegerBoundsAreNotClosedBecauseTheLeavesAcceptFloats(): void
    {
        self::assertTrue(Spec::greaterThan(10)->isSatisfiedBy(10.5) && Spec::lessThan(11)->isSatisfiedBy(10.5));
        self::assertFalse(Spec::greaterThan(10)->isDisjointWith(Spec::lessThan(11)), '10.5 satisfies both');
        self::assertFalse(Spec::lessThan(11)->isDisjointWith(Spec::greaterThan(10)));
        self::assertFalse(Spec::isContradiction(new AndSpecification(Spec::greaterThan(10), Spec::lessThan(11))));
        $tree = new AndSpecification(Spec::greaterThan(10), Spec::lessThan(11));
        self::assertTrue($tree->isSatisfiedBy(10.5));
        self::assertTrue(Spec::simplify($tree)->isSatisfiedBy(10.5), 'simplify() collapsed the conjunction into alwaysFalse()');

        self::assertTrue(Spec::greaterThan(4)->isSatisfiedBy(4.5) && !Spec::greaterThanOrEqualTo(5)->isSatisfiedBy(4.5));
        self::assertFalse(Spec::greaterThanOrEqualTo(5)->isGeneralizationOf(Spec::greaterThan(4)), '4.5 satisfies only x > 4');
        $either = new OrSpecification(Spec::greaterThanOrEqualTo(5), Spec::greaterThan(4));
        self::assertTrue(Spec::simplify($either)->isSatisfiedBy(4.5), 'simplify() dropped x > 4 as a special case of x >= 5');

        // Bounds that hold without closing are kept
        self::assertTrue(Spec::greaterThan(10)->isDisjointWith(Spec::lessThanOrEqualTo(10)));
        self::assertTrue(Spec::greaterThanOrEqualTo(5)->isGeneralizationOf(Spec::greaterThan(5)));
        self::assertTrue(Spec::notEqual(5)->isGeneralizationOf(Spec::lessThanOrEqualTo(4)));
    }

    /**
     * #68 (P1): a typed default value is "a value of that type and its default" (2.2.0, decision of the
     * maintainer): null is not a text nor a number, so isBlankString() and defaultNumber() refuse it and
     * two typed defaults of different types are disjoint for real. The untyped defaultValue() is unchanged.
     */
    public function testP1TypedDefaultValuesRefuseNullSoDifferentTypesAreDisjoint(): void
    {
        $blank = new DefaultValueSpecification('string');
        $zero = new DefaultValueSpecification('number');
        self::assertFalse($blank->isSatisfiedBy(null), 'null is not a text');
        self::assertFalse($zero->isSatisfiedBy(null), 'null is not a number');
        self::assertFalse((new DefaultValueSpecification('bool'))->isSatisfiedBy(null));
        self::assertFalse((new DefaultValueSpecification('array'))->isSatisfiedBy(null));
        self::assertTrue($blank->isSatisfiedBy('   ') && $blank->isSatisfiedBy('') && !$blank->isSatisfiedBy(0));
        self::assertTrue($zero->isSatisfiedBy(0) && $zero->isSatisfiedBy(0.0) && !$zero->isSatisfiedBy(''));
        self::assertTrue($blank->isDisjointWith($zero) && $zero->isDisjointWith($blank), 'no candidate satisfies both');
        self::assertTrue(Spec::isBlankString()->isDisjointWith(Spec::createDefaultNumberSpecification()));
        self::assertInstanceOf(AlwaysFalseSpecification::class, Spec::simplify(new AndSpecification($blank, $zero)), 'the conjunction is a proven contradiction');
        self::assertTrue($blank->isDisjointWith(Spec::not($blank)));

        // The untyped default is unchanged: null, the blank text, zero, false and [] are default values of some type
        $any = new DefaultValueSpecification();
        self::assertTrue($any->isSatisfiedBy(null) && $any->isSatisfiedBy('') && $any->isSatisfiedBy(0) && $any->isSatisfiedBy(false) && $any->isSatisfiedBy([]));
        self::assertFalse($any->isDisjointWith($blank) || $any->isDisjointWith($zero), 'the untyped default shares a candidate with every typed one');
    }

    /**
     * #70 (P4): NAN is unordered (NAN < v, NAN > v and NAN == v are all false), so ¬(x < v) accepted it
     * and its inversion x >= v did not. The ordered leaves now refuse it as a candidate
     * (IncompatibleTypeException, as a text against a number) and as a bound (InvalidArgumentException);
     * the inversion holds for every candidate they answer, and the equality leaves keep answering it.
     */
    public function testP4NanIsRefusedByTheOrderedLeavesSoTheInversionHolds(): void
    {
        $refused = static function (callable $probe): bool {
            try {
                $probe();
                return false;
            } catch (IncompatibleTypeException) {
                return true;
            }
        };
        $leaves = ['lt' => Spec::lessThan(5.5), 'lte' => Spec::lessThanOrEqualTo(5.5), 'gt' => Spec::greaterThan(5.5), 'gte' => Spec::greaterThanOrEqualTo(5.5)];
        foreach ($leaves as $label => $leaf) {
            self::assertTrue($refused(static fn() => $leaf->isSatisfiedBy(NAN)), "{$label} refuses NAN");
            self::assertTrue($refused(static fn() => Spec::not($leaf)->isSatisfiedBy(NAN)), "not({$label}) refuses NAN as well");
            $inverse = SpecificationAlgebra::invert($leaf);
            self::assertNotNull($inverse, "{$label} is still invertible");
            self::assertTrue($refused(static fn() => $inverse->isSatisfiedBy(NAN)), "the inversion of {$label} refuses NAN as well");
            self::assertTrue($refused(static fn() => Spec::property('n', $leaf)->isSatisfiedBy((object) ['n' => NAN])), "property({$label}) refuses NAN");
        }
        self::assertTrue(Spec::greaterThanOrEqualTo(5.5)->isGeneralizationOf(Spec::not(Spec::lessThan(5.5))), 'the inversion ¬(x < v) ≡ x >= v is kept');
        self::assertTrue(Spec::not(Spec::lessThan(5.5))->isSatisfiedBy(6) && Spec::greaterThanOrEqualTo(5.5)->isSatisfiedBy(6));
        self::assertFalse(Spec::not(Spec::lessThan(5.5))->isSatisfiedBy(5) || Spec::greaterThanOrEqualTo(5.5)->isSatisfiedBy(5));
        self::assertTrue(Spec::lessThan(5.5)->isSatisfiedBy(INF) === false && Spec::greaterThan(5.5)->isSatisfiedBy(INF), 'INF is ordered and stays accepted');

        foreach (['lessThan', 'lessThanOrEqualTo', 'greaterThan', 'greaterThanOrEqualTo'] as $factory) {
            try {
                Spec::$factory(NAN);
                self::fail("{$factory}(NAN) was accepted as a bound");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('NAN', $e->getMessage());
            }
        }
        self::assertTrue(Spec::lessThan(INF)->isSatisfiedBy(5.5), 'INF is accepted as a bound');

        // The equality leaves answer NAN, consistently with their inversion: NAN is equal to nothing
        self::assertFalse(Spec::equalTo(5.5)->isSatisfiedBy(NAN));
        self::assertTrue(Spec::notEqual(5.5)->isSatisfiedBy(NAN));
        self::assertTrue(Spec::not(Spec::equalTo(5.5))->isSatisfiedBy(NAN));
        self::assertFalse(Spec::in(5.5, 6.5)->isSatisfiedBy(NAN));
        self::assertFalse(Spec::equalTo(NAN)->isSatisfiedBy(NAN), 'equalTo(NAN) accepts nothing, as === does');
        self::assertFalse((new DefaultValueSpecification('number'))->isSatisfiedBy(NAN), 'NAN is not zero');
    }

    /** #71: simplify() never raises where the original answered (forward 025 D4 allows only fewer errors). */
    public function testBug71SubsumptionKeepsTheOperandThatDecidesFirst(): void
    {
        $or = Spec::greaterThan(6)->or(Spec::notEqual(1));
        self::assertTrue($or->isSatisfiedBy(9.5), 'the OR decides at greaterThan(6)');
        self::assertTrue(Spec::notEqual(1)->isGeneralizationOf(Spec::greaterThan(6)), 'precondition: greaterThan(6) ⊆ notEqual(1)');
        self::assertTrue(Spec::simplify($or)->isSatisfiedBy(9.5), 'simplify() raised IncompatibleTypeException (float × int)');
        foreach ([7, 2, 1, 0] as $integer) {
            self::assertSame($or->isSatisfiedBy($integer), Spec::simplify($or)->isSatisfiedBy($integer));
        }

        // AND: the general operand comes first and decides false; the special one is never reached
        $and = Spec::lessThan(6)->and(Spec::equal(1));
        self::assertFalse($and->isSatisfiedBy(9.5), 'the AND decides at lessThan(6)');
        self::assertTrue(Spec::lessThan(6)->isGeneralizationOf(Spec::equal(1)), 'precondition: equalTo(1) ⊆ lessThan(6)');
        self::assertFalse(Spec::simplify($and)->isSatisfiedBy(9.5), 'simplify() raised in equalTo(1) (float × int)');

        // When the operand that stays comes first, the redundant one is still dropped
        self::assertTrue(Spec::simplify(Spec::notEqual(1)->or(Spec::greaterThan(6))) instanceof \Antevemus\ASpecification\Specifications\Comparison\NotEqualSpecification);
        self::assertTrue(Spec::simplify(Spec::greaterThan(6)->and(Spec::notEqual(1))) instanceof \Antevemus\ASpecification\Specifications\Comparison\GreaterThanSpecification);
        self::assertTrue(Spec::simplify(Spec::equal(1)->and(Spec::lessThan(6))) instanceof \Antevemus\ASpecification\Specifications\Comparison\EqualSpecification);

        // And when the operands it exposes raise only where it raised (same type guard), as well
        $in = Spec::in(1, 2);
        $one = Spec::equal(1);
        self::assertSame($one, Spec::simplify(new AndSpecification($in, $one)), 'in(1, 2) and equalTo(1) raise on the same candidates');
        self::assertSame(Spec::greaterThan(10)->getValue(), Spec::simplify(new AndSpecification(Spec::greaterThan(5), Spec::greaterThan(10)))->getValue());
        $or = new OrSpecification(Spec::equal(1), Spec::notEqual(1.5));
        self::assertSame($or, Spec::simplify($or), 'notEqual(1.5) raises on 1, which equalTo(1) answers: kept');
    }

    /** #69: the absorbing constant takes the place of the first operand removed, before the kept annotated ones. */
    public function testBug69TheAbsorbingConstantDecidesBeforeTheKeptOperandsItReplaced(): void
    {
        $at = Spec::at(new DateTimeImmutable('2026-01-01 01:00:00', new DateTimeZone('UTC')))->withCode('C1');
        $tree = new AndSpecification(new AndSpecification(Spec::isNull(), Spec::equal(5)), $at);
        self::assertFalse($tree->isSatisfiedBy(true), 'the original answers false at isNull()');
        $simplified = Spec::simplify($tree);
        self::assertFalse($simplified->isSatisfiedBy(true), 'simplify() raised in at(d), which the original never reached');
        self::assertInstanceOf(AndSpecification::class, $simplified);
        self::assertInstanceOf(\Antevemus\ASpecification\Specifications\Logical\AlwaysFalseSpecification::class, $simplified->getLeftSide());
        self::assertSame($at, $simplified->getRightSide(), 'the annotated operand stays (forward 025 D3)');
        self::assertSame(['C1'], array_map(static fn($f) => $f->code, array_values(array_filter($simplified->evaluate(new DateTimeImmutable('2025-01-01'))->failures, static fn($f) => $f->code !== null))));

        $or = new OrSpecification(new OrSpecification(Spec::isNotNull(), Spec::isNull()), $at);
        self::assertTrue($or->isSatisfiedBy(true));
        self::assertTrue(Spec::simplify($or)->isSatisfiedBy(true), 'the tautology answers true before reaching at(d)');

        // The kept operands before the first removed one keep their place (Module 20: annotated ∧ … stays on the left)
        $annotated = Spec::property('a', Spec::equal(1))->because('one');
        $active = Spec::property('status', Spec::equal('ACTIVE'));
        $contradiction = Spec::simplify(new AndSpecification(new AndSpecification($annotated, $active), $active->not()));
        self::assertSame($annotated, $contradiction->getLeftSide());
        self::assertInstanceOf(\Antevemus\ASpecification\Specifications\Logical\AlwaysFalseSpecification::class, $contradiction->getRightSide());
    }

    /**
     * #71: whether a redundant operand that comes first may be dropped depends on where the operands it
     * would expose raise, read from the type guards of the leaves (SpecificationSimplifier). Each row is
     * an OR or AND chain whose first operand is the redundant one; 'dropped' means simplify() removed it,
     * 'kept' that it did not. Either way the simplified chain answers wherever the original answers.
     */
    public function testBug71TheTypeGuardsOfTheOperandsDecideWhetherTheFirstOneMayBeDropped(): void
    {
        $d = new DateTimeImmutable('2026-01-01 01:00:00', new DateTimeZone('UTC'));
        $earlier = new DateTimeImmutable('2025-01-01 00:00:00', new DateTimeZone('UTC'));
        $m = new AscMoney(1);
        $or = static fn(ISpecification ...$s): ISpecification => self::chain(OrSpecification::class, $s);
        $and = static fn(ISpecification ...$s): ISpecification => self::chain(AndSpecification::class, $s);
        $calling = static fn(int $min): ISpecification => Spec::calling('total', [1], Spec::greaterThan($min));

        $rows = [
            // [label, chain, dropped?]
            ['equal(7.5) ∨ gt(5.5): numbers', $or(Spec::equal(7.5), Spec::greaterThan(5.5)), true],
            ["equal('b') ∨ gt('a'): texts", $or(Spec::equal('b'), Spec::greaterThan('a')), true],
            ['at(d) ∨ after(earlier): date-times', $or(Spec::at($d), Spec::after($earlier)), true],
            ['equal(d) ∨ after(earlier): after() raises on other objects', $or(Spec::equal($d), Spec::after($earlier)), false],
            ["equal(1) ∨ in(1, 'a')", $or(Spec::equal(1), Spec::in(1, 'a')), true],
            ['equal(1) ∨ in().because(none) ∨ gte(0): the empty set never raises', $or(Spec::equal(1), Spec::in()->because('none'), Spec::greaterThanOrEqualTo(0)), true],
            ['equal(1) ∨ notEqual(null): notEqual(null) never raises', $or(Spec::equal(1), Spec::notEqual(null)), true],
            ['at(d) ∨ notEqual(1): notEqual(1) raises on a date-time', $or(Spec::at($d), Spec::notEqual(1)), false],
            ['at(d) ∨ notEqual(Money): objects never raise there', $or(Spec::at($d), Spec::notEqual($m)), true],
            ['equal([1]) ∨ notEqual(true)', $or(Spec::equal([1]), Spec::notEqual(true)), false],
            ['equal(1.5) ∨ looselyEqualTo(5) ∨ gte(0): the loose equality is not read here', $or(Spec::equal(1.5), Spec::looselyEqualTo(5), Spec::greaterThanOrEqualTo(0)), false],
            ['equal(1) ∨ user numericTextIn(1) ∨ gte(0): a subclass of in() is not read here', $or(Spec::equal(1), new AscNumericTextIn([1]), Spec::greaterThanOrEqualTo(0)), false],
            ['equal(1) ∨ user strict ¬equal(2) ∨ gte(0): a subclass of NOT is not read here', $or(Spec::equal(1), new AscStrictNot(Spec::equal(2)), Spec::greaterThanOrEqualTo(0)), false],
            ['equal(1) ∨ user strict (gt(5) ∧ lt(9)) ∨ gte(0): a subclass of AND is not read here', $or(Spec::equal(1), new AscStrictAnd(Spec::greaterThan(5), Spec::lessThan(9)), Spec::greaterThanOrEqualTo(0)), false],
            ['gt(1) ∨ (gt(5) ∧ lt(9)) ∨ gt(0): a conjunction in between', $or(Spec::greaterThan(1), new AndSpecification(Spec::greaterThan(5), Spec::lessThan(9)), Spec::greaterThan(0)), true],
            ['gt(0) ∧ (gt(5) ∨ lt(3)) ∧ gt(1): a disjunction in between', $and(Spec::greaterThan(0), new OrSpecification(Spec::greaterThan(5), Spec::lessThan(3)), Spec::greaterThan(1)), true],
            ['gt(0) ∧ nor(gt(5), lt(3)) ∧ gt(1): a joint denial in between', $and(Spec::greaterThan(0), new \Antevemus\ASpecification\Specifications\Logical\JointDenialSpecification(Spec::greaterThan(5), Spec::lessThan(3)), Spec::greaterThan(1)), true],
            ['equal(1) ∨ notEqual(2.5) ∨ gte(0): notEqual(2.5) raises on an int', $or(Spec::equal(1), Spec::notEqual(2.5), Spec::greaterThanOrEqualTo(0)), false],
            ['equal(1) ∨ ¬equal(2)', $or(Spec::equal(1), Spec::not(Spec::equal(2))), true],
            ['equal(1) ∨ ¬equal(2.5)', $or(Spec::equal(1), Spec::not(Spec::equal(2.5))), false],
            ['equal(7) ∨ (gt(5) ∧ notEqual(8.5))', $or(Spec::equal(7), new AndSpecification(Spec::greaterThan(5), Spec::notEqual(8.5))), false],
            ['equal(7) ∨ (notEqual(8.5) ∧ gt(5))', $or(Spec::equal(7), new AndSpecification(Spec::notEqual(8.5), Spec::greaterThan(5))), false],
            ['equal(7) ∨ (gt(5) ∧ notEqual(8))', $or(Spec::equal(7), new AndSpecification(Spec::greaterThan(5), Spec::notEqual(8))), true],
            ['equal(Money) ∨ notEqual(1)', $or(Spec::equal($m), Spec::notEqual(1)), false],
            ['equal([1]) ∨ notEqual(1)', $or(Spec::equal([1]), Spec::notEqual(1)), false],
            ["equal('a') ∨ notEqual(1)", $or(Spec::equal('a'), Spec::notEqual(1)), false],
            ['equal(1.5) ∨ notEqual(1)', $or(Spec::equal(1.5), Spec::notEqual(1)), false],
            ['equal(true) ∨ notEqual(1)', $or(Spec::equal(true), Spec::notEqual(1)), false],
            ['equal(2) ∨ notEqual(1)', $or(Spec::equal(2), Spec::notEqual(1)), true],
            ['equal(Money) ∨ in(Money, 1)', $or(Spec::equal($m), Spec::in($m, 1)), true],
            ['all(Vip) ∨ notNull(): notNull() never raises', $or(new AllEntitiesSpecification(AscVip::class), Spec::isNotNull()), true],
            ['all(Vip) ∨ all(Customer): not read here, kept', $or(new AllEntitiesSpecification(AscVip::class), new AllEntitiesSpecification(AscCustomer::class)), false],
            ['calling(total, > 5) ∨ calling(total, > 3): a method call is never dropped', $or($calling(5), $calling(3)), false],
            ["property(a, equal('x')) ∨ property(a, in('x', 'y')): same property, same base", $or(Spec::property('a', Spec::equal('x')), Spec::property('a', Spec::in('x', 'y'))), true],
            ["property(a, equal(1)) ∨ property(a, notEqual(2.5)): the value raises", $or(Spec::property('a', Spec::equal(1)), Spec::property('a', Spec::notEqual(2.5))), false],
            ["Vip.where(name, equal('x')) ∨ Customer.where(name, in('x', 'y')): other bases, not read here", $or(new \Antevemus\ASpecification\Specifications\PropertySpecification(Spec::specify(AscVip::class), 'name', Spec::equal('x')), new \Antevemus\ASpecification\Specifications\PropertySpecification(Spec::specify(AscCustomer::class), 'name', Spec::in('x', 'y'))), false],
            ['lt(6) ∧ equal(1): equal(1) raises on a float', $and(Spec::lessThan(6), Spec::equal(1)), false],
            ['in(1, 2) ∧ equal(1): same type guard', $and(Spec::in(1, 2), Spec::equal(1)), true],
            ["gt('a') ∧ equal('b'): same type guard", $and(Spec::greaterThan('a'), Spec::equal('b')), true],
        ];
        $box = static fn(mixed $a): object => new class ($a) {
            public function __construct(public mixed $a)
            {
            }
        };
        $candidates = [0, 1, 2, 7, 9.5, 1.5, 'a', 'b', true, [1], $m, new AscMoney(1), $d, $earlier, new \stdClass(), new AscClock(), $box('x'), $box(1), $box(2.5), $box(null)];

        foreach ($rows as [$label, $chain, $dropped]) {
            $simplified = Spec::simplify($chain);
            $first = $chain instanceof OrSpecification
                ? \Antevemus\ASpecification\Specifications\SpecificationAlgebra::flattenDisjunction($chain)[0]
                : \Antevemus\ASpecification\Specifications\SpecificationAlgebra::flattenConjunction($chain)[0];
            $remaining = $chain instanceof OrSpecification
                ? \Antevemus\ASpecification\Specifications\SpecificationAlgebra::flattenDisjunction($simplified)
                : \Antevemus\ASpecification\Specifications\SpecificationAlgebra::flattenConjunction($simplified);
            self::assertSame($dropped, !in_array($first, $remaining, true), $label . ($dropped ? ': the first operand should be dropped' : ': the first operand should be kept'));
            foreach ($candidates as $k => $candidate) {
                try {
                    $expected = $chain->isSatisfiedBy($candidate);
                } catch (\Throwable) {
                    continue;
                }
                try {
                    $actual = $simplified->isSatisfiedBy($candidate);
                } catch (\Throwable $e) {
                    self::fail(sprintf('%s: simplify() raised %s on candidate #%d, which the original answered', $label, $e::class, $k));
                }
                self::assertSame($expected, $actual, sprintf('%s: candidate #%d', $label, $k));
            }
        }
    }

    /** #69: a kept operand that absorbs the chain by itself decides before the removed ones: no constant is added. */
    public function testBug69AKeptAbsorbingOperandBeforeTheRemovedOnesNeedsNoConstant(): void
    {
        $never = (new AndSpecification(Spec::equal(1), Spec::equal(2)))->because('never');
        self::assertTrue($never->isContradiction());
        $first = Spec::simplify(self::chain(AndSpecification::class, [$never, Spec::equal(3), Spec::equal(4)]));
        self::assertSame($never, $first, 'the annotated contradiction comes first and decides');

        $last = Spec::simplify(self::chain(AndSpecification::class, [Spec::equal(3), Spec::equal(4), $never]));
        self::assertInstanceOf(AndSpecification::class, $last);
        self::assertInstanceOf(\Antevemus\ASpecification\Specifications\Logical\AlwaysFalseSpecification::class, $last->getLeftSide());
        self::assertSame($never, $last->getRightSide(), 'the constant takes the place of equal(3), before the annotated contradiction');
    }

    /**
     * @param class-string $class
     * @param list<ISpecification> $operands
     */
    private static function chain(string $class, array $operands): ISpecification
    {
        $chain = array_shift($operands);
        foreach ($operands as $operand) {
            $chain = new $class($chain, $operand);
        }

        return $chain;
    }
}
