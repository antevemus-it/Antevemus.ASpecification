<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\Contracts\Factory\IComparisonSpecificationFactory;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Factory\IntegerSpecificationFactory;
use Antevemus\ASpecification\Factory\SpecificationFactory;
use Antevemus\ASpecification\Linq\ALinqBridge;
use Antevemus\ASpecification\Linq\ALinqSpecificationVisitor;
use Antevemus\ASpecification\Serialization\Exceptions\SpecificationCodecException;
use Antevemus\ASpecification\Serialization\SpecificationCodec;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\AndSpecification;
use Antevemus\ASpecification\Specifications\Comparison\AbstractComparableValueBoundSpecification;
use Antevemus\ASpecification\Specifications\Comparison\EqualSpecification;
use Antevemus\ASpecification\Specifications\Comparison\GreaterThanOrEqualSpecification;
use Antevemus\ASpecification\Specifications\Comparison\GreaterThanSpecification;
use Antevemus\ASpecification\Specifications\Comparison\InSpecification;
use Antevemus\ASpecification\Specifications\Comparison\LessThanOrEqualSpecification;
use Antevemus\ASpecification\Specifications\Comparison\LessThanSpecification;
use Antevemus\ASpecification\Specifications\Comparison\NotEqualSpecification;
use Antevemus\ASpecification\Specifications\Exceptions\IncompatibleTypeException;
use Antevemus\ASpecification\Specifications\Logical\AlwaysFalseSpecification;
use Antevemus\ASpecification\Specifications\NotSpecification;
use Antevemus\ASpecification\Specifications\SpecificationAlgebra;
use Antevemus\ASpecification\Sql\SqlDialect;
use Antevemus\ASpecification\Tests\Support\ReadmeBlocks;
use Antevemus\ASpecification\Tests\TestCase;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use TypeError;
use function Antevemus\ASpecification\DSL\greaterThanOrEqualTo;

/** A user leaf with a predicate of its own: it cannot be int-typed (its predicate would not apply the guard). */
final class M44LooseBound extends GreaterThanSpecification
{
    public function isSatisfiedBy(mixed $candidate): bool
    {
        return is_numeric($candidate) && $candidate > $this->getValue();
    }
}

/** A user subclass that keeps the predicate of its operator: it can be int-typed. */
final class M44NamedBound extends GreaterThanSpecification
{
}

/**
 * Module44_IntegerLeavesTest - Forward 044 (2.9.0, milestone 25): the optional integer mode of the relational
 * leaves.
 *
 * - D1: asInteger() on the leaf (an immutable copy, getType() 'int', annotations kept, idempotent) and the
 *   Spec::int() factory (the eight methods, between() and in());
 * - D2: the int-typed leaf refuses every non-null candidate that is not an int (12.0, 10.5, '12', true, [12], an
 *   object, NAN, INF) with IncompatibleTypeException, and every bound that is not an int with
 *   InvalidArgumentException (10.5, 10.0, '10', a date-time, null, true); a leaf whose predicate is not the one of
 *   its operator (looselyEqualTo(), at(), a user subclass) with LogicException;
 * - D3: the narrowed return types of Spec, of the DSL and of the factory, read by reflection, while the factory
 *   interface keeps ISpecification;
 * - D4: the algebra of two int-typed leaves is exact over the integers (brute force over -100..100: disjointness
 *   and subsumption of =, <, <=, >, >= answer what the integers answer), sound with <> too, int x mixed answers
 *   as mixed x mixed (no closing), the inversion keeps the type, the bounds at PHP_INT_MAX/PHP_INT_MIN stay open,
 *   simplify() collapses what the closing proves and never adds a node;
 * - D5: the SQL of every dialect, the TCriteria and the ALinq filter of an int-typed leaf are those of the leaf
 *   without the mode; ALinq refuses a float as the leaf does;
 * - D6: the codec writes "int": true and reads it back int-typed, a document without "int" reads as before;
 * - RN-03: the consumer rule, a minimum adjustment periodicity in months (Spec::int()->greaterThanOrEqualTo(12)):
 *   10.5, 12.0 and '12' refused, 12 accepted, the SQL of today;
 * - examples 1.1 and 12.1 of docs/<language>/specifications run as written (transcribed token by token).
 *
 * @version 2.9.0
 */
class Module44_IntegerLeavesTest extends TestCase
{
    public function run(): void
    {
        $this->testAsIntegerMakesAnIntTypedCopy();
        $this->testIntTypedLeavesRefuseEveryCandidateThatIsNotAnInt();
        $this->testBoundsAndLeavesThatCannotBeIntTyped();
        $this->testIntegerFactory();
        $this->testNarrowedReturnTypes();
        $this->testInversionKeepsTheType();
        $this->testClosedAlgebraIsExactOverTheIntegers();
        $this->testIntAgainstMixedIsNotClosed();
        $this->testBoundsAtTheEndOfTheIntRangeStayOpen();
        $this->testSimplifyCollapsesWhatTheClosingProves();
        $this->testTranslationsDoNotChange();
        $this->testCodecKeepsTheType();
        $this->testTypedCompositesAndProperties();
        $this->testMinimumAdjustmentPeriodicityInMonths();
        $this->testReadme1_1RunsAsWritten();
        $this->testReadme12_1RunsAsWritten();
        $this->testReadmeBlocksAreTheSameInBothLanguages();
    }

    // ==========================================
    // D1: asInteger()
    // ==========================================

    private function testAsIntegerMakesAnIntTypedCopy(): void
    {
        foreach (self::mixedLeaves(10) as $label => $leaf) {
            $int = $leaf->asInteger();
            $this->assertTrue($int !== $leaf, "{$label}: a copy");
            $this->assertEquals($leaf::class, $int::class, "{$label}: the same class");
            $this->assertEquals('mixed', $leaf->getType(), "{$label}: the original is not changed");
            $this->assertFalse($leaf->isInteger(), "{$label}: the original is not int-typed");
            $this->assertEquals('int', $int->getType(), "{$label}: getType() 'int'");
            $this->assertTrue($int->isInteger(), "{$label}: isInteger()");
            $this->assertTrue($int->asInteger() === $int, "{$label}: idempotent, the same instance");
            $this->assertEquals($leaf->getValue(), $int->getValue(), "{$label}: the bound");
            $this->assertTrue($leaf->getRelationalOperator() === $int->getRelationalOperator(), "{$label}: the operator");
            $this->assertFalse($leaf->equals($int), "{$label}: an int-typed leaf is not equal to the leaf without the mode");
            $this->assertFalse($int->equals($leaf), "{$label}: symmetric");
            $this->assertTrue($int->equals($leaf->asInteger()), "{$label}: two int-typed copies are equal");
            $this->assertFalse($int->equals(self::mixedLeaves(11)[$label]->asInteger()), "{$label}: another bound");
            $this->assertEquals((string) $leaf, (string) $int, "{$label}: the same text");

            // Annotations survive in both orders
            $annotated = $leaf->because('Whole months only')->withCode('M44')->asInteger();
            $this->assertTrue($annotated->isInteger());
            $this->assertTrue($annotated->equals($int->because('Whole months only')->withCode('M44')), "{$label}: asInteger() and the annotations commute");
            $failing = $leaf->isSatisfiedBy(-50) ? ($leaf->isSatisfiedBy(50) ? 10 : 50) : -50;
            $this->assertEquals(['M44'], $annotated->evaluate($failing)->getCodes(), "{$label}: the code of a failure");
        }
        $this->assertTrue(Spec::greaterThan(10)->asInteger()->equals(Spec::int()->greaterThan(10)), 'asInteger() = Spec::int()');
    }

    private function testIntTypedLeavesRefuseEveryCandidateThatIsNotAnInt(): void
    {
        $refused = ['12.0' => 12.0, '10.5' => 10.5, '10.0' => 10.0, "'12'" => '12', "'10'" => '10', 'true' => true, 'false' => false, '[12]' => [12], 'object' => new \stdClass(), 'NAN' => NAN, 'INF' => INF, 'date' => new DateTimeImmutable('2026-01-01')];
        foreach (self::mixedLeaves(10) as $label => $leaf) {
            $int = $leaf->asInteger();
            foreach ([-1, 0, 9, 10, 11, 12, PHP_INT_MAX, PHP_INT_MIN] as $candidate) {
                $this->assertEquals($leaf->isSatisfiedBy($candidate), $int->isSatisfiedBy($candidate), "{$label}: the int {$candidate} is answered as by the leaf without the mode");
            }
            $this->assertEquals($leaf->isSatisfiedBy(null), $int->isSatisfiedBy(null), "{$label}: null is answered as by the leaf without the mode");
            foreach ($refused as $what => $candidate) {
                $e = $this->assertThrows(IncompatibleTypeException::class, fn() => $int->isSatisfiedBy($candidate), "{$label}: {$what} refused");
                $this->assertTrue(str_contains($e->getMessage(), 'is int-typed and refuses the candidate'), "{$label}: {$what}, the message says why");
                $shown = is_scalar($candidate) ? var_export($candidate, true) : get_debug_type($candidate);
                $this->assertTrue(str_contains($e->getMessage(), "the candidate {$shown} of type " . get_debug_type($candidate) . ':'), "{$label}: {$what}, the candidate and its type in the message");
                $this->assertTrue($e->candidate === $candidate || $what === 'NAN', "{$label}: {$what}, the candidate in the exception");
                $result = $int->evaluate($candidate);
                $this->assertTrue($result->isError && !$result->isSatisfied, "{$label}: {$what}, evaluate() answers an error");
                $this->assertTrue($result->exception instanceof IncompatibleTypeException, "{$label}: {$what}, with the exception");
            }
        }
        // The leaf without the mode answers the float (the promise of 2.1.0 stays)
        $this->assertTrue(Spec::greaterThan(10)->isSatisfiedBy(10.5));
        $this->assertTrue(Spec::lessThan(11)->isSatisfiedBy(10.5));
    }

    private function testBoundsAndLeavesThatCannotBeIntTyped(): void
    {
        foreach ([
            'greaterThan(10.5)' => fn() => Spec::greaterThan(10.5)->asInteger(),
            'greaterThan(10.0)' => fn() => Spec::greaterThan(10.0)->asInteger(),
            "lessThan('10')" => fn() => Spec::lessThan('10')->asInteger(),
            'lessThanOrEqualTo(date)' => fn() => Spec::lessThanOrEqualTo(new DateTimeImmutable('2026-01-01'))->asInteger(),
            'equalTo(null)' => fn() => Spec::equalTo(null)->asInteger(),
            'equalTo(true)' => fn() => Spec::equalTo(true)->asInteger(),
            'notEqual(1.0)' => fn() => Spec::notEqual(1.0)->asInteger(),
            'equalTo([1])' => fn() => Spec::equalTo([1])->asInteger(),
        ] as $label => $build) {
            $e = $this->assertThrows(InvalidArgumentException::class, $build, "{$label}: the bound is not an int");
            $this->assertTrue(str_contains($e->getMessage(), 'cannot be int-typed with a bound of type'), "{$label}: the message");
        }
        $this->assertTrue(str_contains($this->assertThrows(InvalidArgumentException::class, fn() => Spec::greaterThan(10.0)->asInteger())->getMessage(), '(10.0)'), 'the bound in the message');

        foreach ([
            'looselyEqualTo(1)' => fn() => Spec::looselyEqualTo(1)->asInteger(),
            'at(d)' => fn() => Spec::at(new DateTimeImmutable('2026-01-01'))->asInteger(),
            'user leaf with a predicate of its own' => fn() => (new M44LooseBound(10))->asInteger(),
        ] as $label => $build) {
            $e = $this->assertThrows(LogicException::class, $build, "{$label}: its predicate would not apply the guard");
            $this->assertFalse($e instanceof InvalidArgumentException, "{$label}: LogicException, not a bad argument");
            $this->assertTrue(str_contains($e->getMessage(), 'cannot be int-typed'), "{$label}: the message");
        }

        // A user subclass that keeps the predicate of its operator can be int-typed
        $named = (new M44NamedBound(10))->asInteger();
        $this->assertEquals('int', $named->getType());
        $this->assertThrows(IncompatibleTypeException::class, fn() => $named->isSatisfiedBy(10.5));
        $this->assertTrue($named->isSatisfiedBy(11));
    }

    // ==========================================
    // D1: Spec::int()
    // ==========================================

    private function testIntegerFactory(): void
    {
        $int = Spec::int();
        $this->assertInstanceOf(IntegerSpecificationFactory::class, $int);
        foreach ([
            [GreaterThanSpecification::class, $int->greaterThan(10), Spec::greaterThan(10)],
            [GreaterThanOrEqualSpecification::class, $int->greaterThanOrEqualTo(10), Spec::greaterThanOrEqualTo(10)],
            [LessThanSpecification::class, $int->lessThan(10), Spec::lessThan(10)],
            [LessThanOrEqualSpecification::class, $int->lessThanOrEqualTo(10), Spec::lessThanOrEqualTo(10)],
            [EqualSpecification::class, $int->equalTo(10), Spec::equalTo(10)],
            [NotEqualSpecification::class, $int->notEqualTo(10), Spec::notEqual(10)],
        ] as [$class, $leaf, $mixed]) {
            $this->assertEquals($class, $leaf::class, "Spec::int(): {$class}");
            $this->assertTrue($leaf->isInteger(), "Spec::int(): {$class} is int-typed");
            $this->assertTrue($leaf->equals($mixed->asInteger()), "Spec::int(): {$class} is the general leaf made int-typed");
        }

        // between(): the conjunction of the two int-typed leaves, as Spec::between() is for date-times
        $between = $int->between(1, 12);
        $this->assertInstanceOf(AndSpecification::class, $between);
        $this->assertTrue($between->getLeftSide()->equals($int->greaterThanOrEqualTo(1)) && $between->getRightSide()->equals($int->lessThanOrEqualTo(12)));
        $this->assertEquals('int', $between->getType());
        foreach ([0 => false, 1 => true, 6 => true, 12 => true, 13 => false] as $candidate => $expected) {
            $this->assertEquals($expected, $between->isSatisfiedBy($candidate), "between(1, 12): {$candidate}");
        }
        $this->assertThrows(IncompatibleTypeException::class, fn() => $between->isSatisfiedBy(6.0));
        $this->assertTrue(Spec::isContradiction($int->between(12, 1)), 'between(12, 1) is empty');
        $this->assertTrue(Spec::isContradiction($int->between(11, 10)), 'between(11, 10) is empty');
        $this->assertFalse(Spec::isContradiction($int->between(10, 10)), 'between(10, 10) holds 10');
        $this->assertTrue($int->between(10, 10)->isSatisfiedBy(10));

        // in(): the set leaf over ints, which already refuses what is not an int
        $in = $int->in(1, 2, 3);
        $this->assertInstanceOf(InSpecification::class, $in);
        $this->assertTrue($in->equals(Spec::in(1, 2, 3)) && $in->equals($int->in([3, 2, 1])), 'in(): the set leaf of Spec::in(), variadic or one array');
        $this->assertTrue($in->isSatisfiedBy(2) && !$in->isSatisfiedBy(4) && !$in->isSatisfiedBy(null));
        foreach ([2.0, '2', true] as $candidate) {
            $this->assertThrows(IncompatibleTypeException::class, fn() => $in->isSatisfiedBy($candidate), 'in(): a set of ints refuses ' . var_export($candidate, true));
        }
        $this->assertTrue($int->in(7)->equals(Spec::in(7)) && $int->in(7)->isSatisfiedBy(7), 'in(): one value');
        $this->assertTrue($int->in(['a' => 1, 'b' => 2])->equals(Spec::in(1, 2)), 'in(): the keys of the array are ignored');
        $this->assertFalse($int->in()->isSatisfiedBy(1), 'in(): the empty set');
        $this->assertTrue($int->in([])->isContradiction());
        $this->assertThrows(InvalidArgumentException::class, fn() => $int->in([1, 2.0]), 'in(): a float in the array');
        $this->assertThrows(InvalidArgumentException::class, fn() => $int->in(['1']), 'in(): a text in the array');
        $this->assertThrows(InvalidArgumentException::class, fn() => $int->in(1, [2]), 'in(): an array among the values');
        $this->assertThrows(TypeError::class, fn() => $int->greaterThan(10.5), 'the parameters are int (strict_types)');
        $this->assertThrows(TypeError::class, fn() => $int->in(1, 2.0), 'in(): a float argument (strict_types)');
    }

    // ==========================================
    // D3: the narrowed return types
    // ==========================================

    private function testNarrowedReturnTypes(): void
    {
        $leafOf = [
            'equalTo' => EqualSpecification::class,
            'equal' => EqualSpecification::class,
            'notEqual' => NotEqualSpecification::class,
            'greaterThan' => GreaterThanSpecification::class,
            'greaterThanOrEqualTo' => GreaterThanOrEqualSpecification::class,
            'lessThan' => LessThanSpecification::class,
            'lessThanOrEqualTo' => LessThanOrEqualSpecification::class,
        ];
        $typeOf = static fn(?\ReflectionType $type): string => $type instanceof ReflectionNamedType ? $type->getName() : (string) $type;
        foreach ($leafOf as $name => $class) {
            $this->assertEquals($class, $typeOf((new ReflectionMethod(Spec::class, $name))->getReturnType()), "Spec::{$name}()");
            $this->assertEquals($class, $typeOf((new ReflectionFunction('Antevemus\\ASpecification\\DSL\\' . $name))->getReturnType()), "DSL {$name}()");
        }
        foreach (['equalTo' => EqualSpecification::class, 'notEqualTo' => NotEqualSpecification::class, 'notEqual' => NotEqualSpecification::class,
                  'greaterThan' => GreaterThanSpecification::class, 'greaterThanOrEqualTo' => GreaterThanOrEqualSpecification::class,
                  'lessThan' => LessThanSpecification::class, 'lessThanOrEqualTo' => LessThanOrEqualSpecification::class] as $name => $class) {
            $this->assertEquals($class, $typeOf((new ReflectionMethod(SpecificationFactory::class, $name))->getReturnType()), "SpecificationFactory::{$name}()");
            $this->assertEquals($class, Spec::getFactory()->{$name}(5)::class, "SpecificationFactory::{$name}() builds the leaf");
        }
        $this->assertEquals(IntegerSpecificationFactory::class, $typeOf((new ReflectionMethod(Spec::class, 'int'))->getReturnType()), 'Spec::int()');
        // The interfaces of the factories keep ISpecification: their implementations do not break
        foreach (['equalTo', 'lessThan', 'lessThanOrEqualTo', 'greaterThan', 'greaterThanOrEqualTo'] as $name) {
            $this->assertEquals(ISpecification::class, $typeOf((new ReflectionMethod(IComparisonSpecificationFactory::class, $name))->getReturnType()), "IComparisonSpecificationFactory::{$name}() unchanged");
        }
        // The general factory and the segment accessor build the same leaves
        $this->assertTrue(Spec::getFactory()->comparison()->greaterThan(10)->equals(Spec::greaterThan(10)));
        $this->assertTrue(Spec::getFactory()->isGreaterThan(10)->equals(Spec::greaterThan(10)), 'the Domian alias');
        $this->assertTrue(greaterThanOrEqualTo(12)->asInteger()->equals(Spec::int()->greaterThanOrEqualTo(12)), 'the DSL function');
    }

    // ==========================================
    // D4: the algebra
    // ==========================================

    private function testInversionKeepsTheType(): void
    {
        foreach (self::mixedLeaves(10) as $label => $leaf) {
            $int = $leaf->asInteger();
            $inverted = $int->invert();
            $this->assertTrue($inverted instanceof AbstractComparableValueBoundSpecification && $inverted->isInteger(), "{$label}: the inversion is int-typed");
            $this->assertTrue($inverted->equals($leaf->invert()->asInteger()), "{$label}: the inversion of the leaf, int-typed");
            $resolved = SpecificationAlgebra::resolve(new NotSpecification($int));
            $this->assertTrue($resolved->equals($inverted), "{$label}: not() resolves to it");
            foreach ([9, 10, 11] as $candidate) {
                $this->assertEquals(!$int->isSatisfiedBy($candidate), $inverted->isSatisfiedBy($candidate), "{$label}: the inversion on {$candidate}");
            }
            $this->assertThrows(IncompatibleTypeException::class, fn() => Spec::not($int)->isSatisfiedBy(10.5), "{$label}: not() refuses a float too");
            $this->assertTrue($int->isDisjointWith(Spec::not($int)), "{$label}: A ⟂ ¬A");
        }
    }

    /**
     * Two int-typed leaves over the integers: for =, <, <=, >, >= the closed interval rules are exact (the algebra
     * answers what brute force over -100..100 answers, the values lying well inside); with <> they are sound.
     */
    private function testClosedAlgebraIsExactOverTheIntegers(): void
    {
        $domain = range(-100, 100);
        $leaves = [];
        foreach ([8, 9, 10, 11, 12] as $value) {
            foreach (self::mixedLeaves($value) as $label => $leaf) {
                $int = $leaf->asInteger();
                $set = [];
                foreach ($domain as $candidate) {
                    if ($int->isSatisfiedBy($candidate)) {
                        $set[$candidate] = true;
                    }
                }
                $leaves["{$label}@{$value}"] = [$int, $set, $label !== 'notEqual'];
            }
        }
        $exact = 0;
        foreach ($leaves as $la => [$a, $setA, $exactA]) {
            foreach ($leaves as $lb => [$b, $setB, $exactB]) {
                $intersects = array_intersect_key($setA, $setB) !== [];
                $contains = array_diff_key($setB, $setA) === [];
                $disjoint = $a->isDisjointWith($b);
                $generalizes = $a->isGeneralizationOf($b);
                if ($exactA && $exactB) {
                    $this->assertEquals(!$intersects, $disjoint, "{$la} ⟂ {$lb}: exact over the integers");
                    $this->assertEquals($contains, $generalizes, "{$la} ⊇ {$lb}: exact over the integers");
                    $this->assertEquals($contains, $b->isSpecialCaseOf($a), "{$lb} ⊆ {$la}: exact over the integers");
                    $exact++;
                    continue;
                }
                $this->assertTrue(!$disjoint || !$intersects, "{$la} ⟂ {$lb}: sound");
                $this->assertTrue(!$generalizes || $contains, "{$la} ⊇ {$lb}: sound");
            }
        }
        $this->assertEquals(625, $exact, '25 x 25 pairs of =, <, <=, >, >= checked exactly');

        // The pairs of the requirement
        $this->assertTrue(Spec::int()->greaterThan(10)->isDisjointWith(Spec::int()->lessThan(11)));
        $this->assertTrue(Spec::int()->lessThan(11)->isDisjointWith(Spec::int()->greaterThan(10)));
        $this->assertTrue(Spec::int()->greaterThan(10)->isGeneralizationOf(Spec::int()->greaterThanOrEqualTo(11)));
        $this->assertTrue(Spec::int()->greaterThanOrEqualTo(11)->isGeneralizationOf(Spec::int()->greaterThan(10)));
        $this->assertFalse(Spec::int()->greaterThan(10)->equals(Spec::int()->greaterThanOrEqualTo(11)), 'equals() stays structural');
        $this->assertTrue(Spec::int()->notEqualTo(10)->isGeneralizationOf(Spec::int()->greaterThan(10)));
        $this->assertTrue(Spec::int()->lessThanOrEqualTo(10)->isGeneralizationOf(Spec::int()->lessThan(11)));
        $this->assertTrue(Spec::int()->equalTo(11)->isDisjointWith(Spec::int()->greaterThan(11)));
        $this->assertFalse(Spec::int()->equalTo(11)->isDisjointWith(Spec::int()->greaterThan(10)));
    }

    /** int x mixed: the rules of 2.1.0 (no closing), pair by pair the answers of mixed x mixed. */
    private function testIntAgainstMixedIsNotClosed(): void
    {
        $values = [9, 10, 11, 10.5];
        $checked = 0;
        foreach ($values as $va) {
            foreach ($values as $vb) {
                foreach (self::mixedLeaves($va) as $la => $a) {
                    foreach (self::mixedLeaves($vb) as $lb => $b) {
                        $context = sprintf('%s(%s) / %s(%s)', $la, var_export($va, true), $lb, var_export($vb, true));
                        $plain = [$a->isDisjointWith($b), $a->isGeneralizationOf($b), $b->isGeneralizationOf($a)];
                        if (is_int($va)) {
                            $this->assertEquals($plain, [$a->asInteger()->isDisjointWith($b), $a->asInteger()->isGeneralizationOf($b), $b->isGeneralizationOf($a->asInteger())], "int x mixed {$context}");
                            $checked++;
                        }
                        if (is_int($vb)) {
                            $this->assertEquals($plain, [$a->isDisjointWith($b->asInteger()), $a->isGeneralizationOf($b->asInteger()), $b->asInteger()->isGeneralizationOf($a)], "mixed x int {$context}");
                            $checked++;
                        }
                    }
                }
            }
        }
        $this->assertEquals(864, $checked, 'every pair with an int side checked');
        $this->assertFalse(Spec::int()->greaterThan(10)->isDisjointWith(Spec::lessThan(11)), '10.5 satisfies lessThan(11)');
        $this->assertFalse(Spec::greaterThan(10)->isDisjointWith(Spec::lessThan(11)), 'RN-01: the leaves without the mode answer as in 2.1.0');
        $this->assertFalse(Spec::greaterThanOrEqualTo(5)->isGeneralizationOf(Spec::greaterThan(4)), 'RN-01');
    }

    private function testBoundsAtTheEndOfTheIntRangeStayOpen(): void
    {
        $int = Spec::int();
        $aboveMax = $int->greaterThan(PHP_INT_MAX);
        $belowMin = $int->lessThan(PHP_INT_MIN);
        $this->assertFalse($aboveMax->isSatisfiedBy(PHP_INT_MAX), 'no int above PHP_INT_MAX');
        $this->assertFalse($belowMin->isSatisfiedBy(PHP_INT_MIN), 'no int below PHP_INT_MIN');
        $this->assertFalse($aboveMax->isGeneralizationOf($int->greaterThanOrEqualTo(PHP_INT_MAX)), 'PHP_INT_MAX satisfies >= PHP_INT_MAX only');
        $this->assertTrue($int->greaterThanOrEqualTo(PHP_INT_MAX)->isGeneralizationOf($int->equalTo(PHP_INT_MAX)));
        $this->assertTrue($aboveMax->isDisjointWith($int->lessThanOrEqualTo(PHP_INT_MAX)));
        $this->assertTrue($belowMin->isDisjointWith($int->greaterThanOrEqualTo(PHP_INT_MIN)));
        $this->assertTrue($int->greaterThan(PHP_INT_MAX - 1)->isGeneralizationOf($int->equalTo(PHP_INT_MAX)), 'closed: > MAX-1 is >= MAX');
        $this->assertTrue($int->greaterThan(PHP_INT_MAX - 1)->isDisjointWith($int->lessThan(PHP_INT_MAX)), 'closed: >= MAX against <= MAX-1');
        $this->assertTrue($int->lessThan(PHP_INT_MIN + 1)->isDisjointWith($int->greaterThan(PHP_INT_MIN)), 'closed: <= MIN against >= MIN+1');
    }

    private function testSimplifyCollapsesWhatTheClosingProves(): void
    {
        $int = Spec::int();
        $over10 = $int->greaterThan(10);
        $under11 = $int->lessThan(11);

        $this->assertInstanceOf(AlwaysFalseSpecification::class, Spec::simplify($over10->and($under11)), 'x > 10 ∧ x < 11 over the integers is empty');
        $this->assertTrue(Spec::isContradiction($over10->and($under11)));
        $this->assertTrue(Spec::simplify($over10->or($int->greaterThanOrEqualTo(11)))->equals($int->greaterThanOrEqualTo(11)), 'x > 10 ∨ x >= 11 is one leaf');
        $this->assertTrue(Spec::simplify($int->greaterThanOrEqualTo(11)->and($over10))->equals($int->greaterThanOrEqualTo(11)), 'x >= 11 ∧ x > 10 is one leaf');
        $narrow = $over10->and($int->lessThan(12));
        $this->assertTrue(Spec::simplify($narrow) === $narrow, 'x > 10 ∧ x < 12 stays: simplify() never adds the node x = 11');
        foreach (range(5, 15) as $candidate) {
            $this->assertEquals($candidate === 11, $narrow->isSatisfiedBy($candidate), "x > 10 ∧ x < 12 on {$candidate}");
        }

        // Without the mode nothing changes (RN-01)
        $plain = Spec::greaterThan(10)->and(Spec::lessThan(11));
        $this->assertTrue(Spec::simplify($plain) === $plain, 'the leaves without the mode stay');
        $this->assertFalse(Spec::isContradiction($plain));
        $mixedPair = $over10->and(Spec::lessThan(11));
        $this->assertTrue(Spec::simplify($mixedPair) === $mixedPair, 'int x mixed stays');

        // An int-typed leaf answers ints only, like equalTo(11): the point it generalizes can go before it
        $this->assertTrue(Spec::simplify(Spec::equalTo(11)->or($over10))->equals($over10), 'equalTo(11) ∨ int(x > 10) is int(x > 10)');

        // An int-typed operand that is redundant goes only where the operand left raises as it did
        $chain = Spec::greaterThan(5)->and($int->greaterThan(10));
        $simplified = Spec::simplify($chain);
        foreach ([4, 11, 6] as $candidate) {
            $this->assertEquals($chain->isSatisfiedBy($candidate), $simplified->isSatisfiedBy($candidate), "the answer on {$candidate}");
        }
        foreach ([10.5, 4.5] as $candidate) {
            $before = $this->outcome($chain, $candidate);
            $after = $this->outcome($simplified, $candidate);
            $this->assertTrue($before === 'error' || $before === $after, "simplify() never raises where the chain answered ({$candidate})");
        }
    }

    // ==========================================
    // D5: the translations
    // ==========================================

    private function testTranslationsDoNotChange(): void
    {
        $dialects = 0;
        foreach (self::mixedLeaves(12) as $label => $leaf) {
            $plain = Spec::property('months', $leaf);
            $typed = Spec::property('months', $leaf->asInteger());
            foreach (SqlDialect::cases() as $dialect) {
                $a = Spec::toSql($plain, $dialect->value);
                $b = Spec::toSql($typed, $dialect->value);
                $this->assertEquals($a->getSql(), $b->getSql(), "{$label}: the SQL of {$dialect->value}");
                $this->assertEquals($a->getParameters(), $b->getParameters(), "{$label}: the parameters of {$dialect->value}");
                $this->assertEquals(Spec::toSql(Spec::not($plain), $dialect->value)->getSql(), Spec::toSql(Spec::not($typed), $dialect->value)->getSql(), "{$label}: negated, {$dialect->value}");
                $dialects++;
            }
            $this->assertEquals(Spec::toCriteria($plain)->dump(), Spec::toCriteria($typed)->dump(), "{$label}: the TCriteria");
            $this->assertEquals(Spec::toCriteria(Spec::not($plain))->dump(), Spec::toCriteria(Spec::not($typed))->dump(), "{$label}: the TCriteria, negated");

            // ALinq evaluates the leaf: the same answers on ints, the refusal on a float
            $plainPredicate = ALinqSpecificationVisitor::createPredicate($leaf);
            $typedPredicate = ALinqSpecificationVisitor::createPredicate($leaf->asInteger());
            foreach ([11, 12, 13] as $candidate) {
                $this->assertEquals($plainPredicate($candidate), $typedPredicate($candidate), "{$label}: ALinq on {$candidate}");
            }
            $this->assertThrows(IncompatibleTypeException::class, fn() => $typedPredicate(12.0), "{$label}: ALinq refuses 12.0");
        }
        $this->assertEquals(6 * count(SqlDialect::cases()), $dialects);
        $between = Spec::property('installments', Spec::int()->between(1, 12));
        $this->assertEquals(
            Spec::toSql(Spec::property('installments', Spec::greaterThanOrEqualTo(1)->and(Spec::lessThanOrEqualTo(12))), 'pgsql')->getSql(),
            Spec::toSql($between, 'pgsql')->getSql(),
            'between(): the SQL of the two leaves'
        );

        if (ALinqBridge::isAvailable()) {
            $rule = Spec::property('months', Spec::int()->greaterThanOrEqualTo(12));
            $kept = ALinqBridge::filter([(object) ['months' => 6], (object) ['months' => 12], (object) ['months' => 24]], $rule)->toArray();
            $this->assertCount(2, $kept, 'ALinq over a collection of ints');
            $this->assertThrows(IncompatibleTypeException::class, fn() => ALinqBridge::filter([(object) ['months' => 12], (object) ['months' => 12.0]], $rule)->toArray(), 'ALinq over a collection with 12.0');
        }
    }

    // ==========================================
    // D6: the codec
    // ==========================================

    private function testCodecKeepsTheType(): void
    {
        $codec = new SpecificationCodec();
        $names = ['equalTo' => 'eq', 'notEqual' => 'neq', 'greaterThan' => 'gt', 'greaterThanOrEqualTo' => 'gte', 'lessThan' => 'lt', 'lessThanOrEqualTo' => 'lte'];
        foreach (self::mixedLeaves(12) as $label => $leaf) {
            $int = $leaf->asInteger()->because('Whole months')->withCode('M44');
            $json = $codec->toJson($int);
            $this->assertEquals('{"v":1,"spec":{"t":"' . $names[$label] . '","v":12,"int":true,"because":"Whole months","code":"M44"}}', $json, "{$label}: the document");
            $decoded = $codec->fromJson($json);
            $this->assertTrue($decoded instanceof AbstractComparableValueBoundSpecification && $decoded->isInteger(), "{$label}: read back int-typed");
            $this->assertTrue($decoded->equals($int) && $int->equals($decoded), "{$label}: the same specification");
            $this->assertEquals($json, $codec->toJson($decoded), "{$label}: the same document");
            $this->assertThrows(IncompatibleTypeException::class, fn() => $decoded->isSatisfiedBy(12.0), "{$label}: the decoded leaf refuses 12.0");
            // Without the mode, the document of 2.4.0
            $this->assertEquals('{"v":1,"spec":{"t":"' . $names[$label] . '","v":12}}', $codec->toJson($leaf), "{$label}: a leaf without the mode is written as before");
            $this->assertFalse($codec->fromJson($codec->toJson($leaf))->isInteger(), "{$label}: and read as before");
        }
        $tree = Spec::property('months', Spec::int()->between(1, 12))->and(Spec::not(Spec::property('months', Spec::int()->equalTo(7))));
        $this->assertTrue($codec->fromJson($codec->toJson($tree))->equals($tree), 'a tree of int-typed leaves');
        foreach ([
            '{"v":1,"spec":{"t":"gt","v":10,"int":false}}',
            '{"v":1,"spec":{"t":"gt","v":10,"int":"true"}}',
            '{"v":1,"spec":{"t":"gt","v":10.5,"int":true}}',
            '{"v":1,"spec":{"t":"gt","v":"10","int":true}}',
            '{"v":1,"spec":{"t":"looseEq","v":1,"int":true}}',
            '{"v":1,"spec":{"t":"at","v":{"$date":"2026-01-01T00:00:00.000000+00:00"},"int":true}}',
        ] as $document) {
            $this->assertThrows(SpecificationCodecException::class, fn() => $codec->fromJson($document), "refused: {$document}");
        }
    }

    // ==========================================
    // Typed composites and properties
    // ==========================================

    private function testTypedCompositesAndProperties(): void
    {
        $rule = Spec::specify(\stdClass::class)->where('months', Spec::int()->greaterThanOrEqualTo(12));
        $this->assertTrue($rule->isSatisfiedBy((object) ['months' => 12]));
        $this->assertFalse($rule->isSatisfiedBy((object) ['months' => 6]));
        $this->assertTrue($rule->evaluate((object) ['months' => 12.0])->isError, 'the property holds a float');
        $this->assertFalse($rule->isSatisfiedBy((object) ['months' => null]), 'a null property is refused, as by the leaf without the mode');
        // getType() 'int' is a type: a typed composite of objects refuses to be conjoined with an int-typed leaf
        // over the candidate itself, which could never be both (with a leaf without the mode, the evaluation raises)
        $this->assertThrows(InvalidArgumentException::class, fn() => Spec::specify(\stdClass::class)->and(Spec::int()->greaterThan(1)));
        $this->assertEquals('int', Spec::int()->greaterThan(1)->and(Spec::lessThan(5))->getType(), 'a conjunction takes the type of its left side');
    }

    // ==========================================
    // RN-03: the consumer rule
    // ==========================================

    /** A minimum adjustment periodicity in months, the first integer rule of the consumer. */
    private function testMinimumAdjustmentPeriodicityInMonths(): void
    {
        $periodicity = Spec::property('adjustmentPeriodMonths', Spec::int()->greaterThanOrEqualTo(12))
            ->because('The adjustment periodicity is at least 12 months.')
            ->withCode('ADJ_PERIOD');
        $today = Spec::property('adjustmentPeriodMonths', Spec::greaterThanOrEqualTo(12))
            ->because('The adjustment periodicity is at least 12 months.')
            ->withCode('ADJ_PERIOD');

        $contract = static fn(mixed $months): object => (object) ['adjustmentPeriodMonths' => $months];
        $this->assertTrue($periodicity->isSatisfiedBy($contract(12)), '12 accepted');
        $this->assertTrue($periodicity->isSatisfiedBy($contract(24)), '24 accepted');
        $failure = $periodicity->evaluate($contract(6));
        $this->assertFalse($failure->isSatisfied || $failure->isError, '6 fails the rule');
        $this->assertEquals(['ADJ_PERIOD'], $failure->getCodes());
        foreach (['10.5' => 10.5, '12.0' => 12.0, "'12'" => '12'] as $label => $months) {
            $result = $periodicity->evaluate($contract($months));
            $this->assertTrue($result->isError && !$result->isSatisfied, "{$label} refused: an evaluation error, not a rule failure");
            $this->assertTrue($result->exception instanceof IncompatibleTypeException, "{$label}: IncompatibleTypeException");
            $this->assertThrows(IncompatibleTypeException::class, fn() => $periodicity->isSatisfiedBy($contract($months)), "{$label}: isSatisfiedBy() raises");
        }
        $this->assertTrue($today->isSatisfiedBy($contract(12.0)), 'today the rule accepts 12.0');

        foreach (['pgsql', 'mysql', 'sqlsrv', 'oci', 'sqlite', 'ansi'] as $dialect) {
            $this->assertEquals(Spec::toSql($today, $dialect)->getSql(), Spec::toSql($periodicity, $dialect)->getSql(), "the SQL of today ({$dialect})");
            $this->assertEquals(Spec::toSql($today, $dialect)->getParameters(), Spec::toSql($periodicity, $dialect)->getParameters());
        }
        $this->assertEquals('("adjustment_period_months" IS NOT NULL AND "adjustment_period_months" >= :p1)', Spec::toSql($periodicity, 'pgsql')->getSql());
        $this->assertEquals([':p1' => 12], Spec::toSql($periodicity, 'pgsql')->getParameters());
        $this->assertEquals(Spec::toCriteria($today)->dump(), Spec::toCriteria($periodicity)->dump(), 'the TCriteria of today');
    }

    // ==========================================
    // Examples 1.1 and 12.1 (docs/<language>/specifications)
    // ==========================================

    /** Example 1.1 runs as written (bare expressions of the page kept here as `$rN = ...`). */
    private function testReadme1_1RunsAsWritten(): void
    {
        // README 1.1 >>>
        // A rule about whole months: the int-typed leaf refuses what is not an int
        $minimumPeriod = Spec::int()->greaterThanOrEqualTo(12);          // same as Spec::greaterThanOrEqualTo(12)->asInteger()
        $r1 = $minimumPeriod->getType();                                 // 'int'
        $r2 = $minimumPeriod->isSatisfiedBy(12);                         // true
        $r3 = $minimumPeriod->isSatisfiedBy(6);                          // false
        $r4 = $minimumPeriod->evaluate(12.0)->isError;                   // true: a float, even without a fractional part
        $r5 = $minimumPeriod->evaluate(10.5)->isError;                   // true
        $r6 = $minimumPeriod->evaluate('12')->isError;                   // true: a numeric text; convert it where it is read
        $r7 = greaterThanOrEqualTo(12)->asInteger()->equals($minimumPeriod);   // true: the DSL function returns the leaf too
        $r8 = Spec::greaterThanOrEqualTo(12)->equals($minimumPeriod);    // false: that one accepts 12.5

        $rule = Spec::property('adjustmentPeriodMonths', $minimumPeriod)
            ->and(Spec::property('installments', Spec::int()->between(1, 12)));
        $r9 = $rule->isSatisfiedBy((object) ['adjustmentPeriodMonths' => 12, 'installments' => 10]);   // true
        $r10 = $rule->evaluate((object) ['adjustmentPeriodMonths' => '12', 'installments' => 10])->isError; // true
        $r11 = Spec::toSql($rule, 'pgsql')->getSql();                    // the SQL of the same rule without the integer mode
        // <<< README 1.1

        $this->assertEquals(['int', true, false, true, true, true, true, false, true, true], [$r1, $r2, $r3, $r4, $r5, $r6, $r7, $r8, $r9, $r10], 'example 1.1: the results written in its comments');
        $this->assertTrue(Spec::greaterThanOrEqualTo(12)->isSatisfiedBy(12.5), 'example 1.1: "that one accepts 12.5"');
        $without = Spec::property('adjustmentPeriodMonths', Spec::greaterThanOrEqualTo(12))
            ->and(Spec::property('installments', Spec::greaterThanOrEqualTo(1)->and(Spec::lessThanOrEqualTo(12))));
        $this->assertEquals(Spec::toSql($without, 'pgsql')->getSql(), $r11, 'example 1.1: the SQL of the same rule without the integer mode');
        $this->assertTranscribed('1.1', 'specifications/fluent-chaining', 2);
    }

    /** Example 12.1 runs as written. */
    private function testReadme12_1RunsAsWritten(): void
    {
        // README 12.1 >>>
        // Leaves that accept floats are not closed: 10.5 satisfies both
        $r1 = Spec::greaterThan(10)->isDisjointWith(Spec::lessThan(11));          // false

        // Int-typed on both sides, x > 10 is x >= 11: the closing is back
        $over10 = Spec::int()->greaterThan(10);
        $under11 = Spec::lessThan(11)->asInteger();
        $r2 = $over10->isDisjointWith($under11);                                  // true
        $r3 = Spec::int()->greaterThanOrEqualTo(11)->isGeneralizationOf($over10); // true: the same integers
        $r4 = Spec::isContradiction($over10->and($under11));                      // true
        $r5 = Spec::simplify($over10->and($under11));                             // alwaysFalse()
        $r6 = Spec::simplify($over10->or(Spec::int()->greaterThanOrEqualTo(11)))
            ->equals(Spec::int()->greaterThanOrEqualTo(11));                      // true: one leaf left

        // The promise holds because the evaluation refuses what lies between the integers
        $r7 = $over10->evaluate(10.5)->isError;                                   // true
        $r8 = $under11->evaluate(10.5)->isError;                                  // true

        // Only between two int-typed leaves: against a leaf that accepts floats nothing is closed
        $r9 = $over10->isDisjointWith(Spec::lessThan(11));                        // false
        // <<< README 12.1

        $this->assertEquals([false, true, true, true, true, true, true, false], [$r1, $r2, $r3, $r4, $r6, $r7, $r8, $r9], 'example 12.1: the results written in its comments');
        $this->assertInstanceOf(AlwaysFalseSpecification::class, $r5, 'example 12.1: alwaysFalse()');
        $this->assertTranscribed('12.1', 'specifications/tautology-and-simplification', 1);
    }

    private function testReadmeBlocksAreTheSameInBothLanguages(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['1.1' => 'specifications/fluent-chaining', '12.1' => 'specifications/tautology-and-simplification'] as $example => $page) {
            $english = ReadmeBlocks::of("{$root}/docs/en/{$page}.md", $example);
            $portuguese = ReadmeBlocks::of("{$root}/docs/pt-BR/{$page}.md", $example);
            $this->assertCount(1, $english, "one PHP block in example {$example} (EN)");
            $this->assertCount(1, $portuguese, "one PHP block in example {$example} (pt-BR)");
            $this->assertEquals(ReadmeBlocks::codeTokens($english[0]), ReadmeBlocks::codeTokens($portuguese[0]), "example {$example}: the same code except comments");
        }
    }

    // ==========================================
    // Support
    // ==========================================

    /**
     * The six relational leaves of the general factory for a bound, by the name of their factory.
     *
     * @return array<string, AbstractComparableValueBoundSpecification<mixed>>
     */
    private static function mixedLeaves(int|float $value): array
    {
        return [
            'equalTo' => Spec::equalTo($value),
            'notEqual' => Spec::notEqual($value),
            'greaterThan' => Spec::greaterThan($value),
            'greaterThanOrEqualTo' => Spec::greaterThanOrEqualTo($value),
            'lessThan' => Spec::lessThan($value),
            'lessThanOrEqualTo' => Spec::lessThanOrEqualTo($value),
        ];
    }

    private function outcome(ISpecification $specification, mixed $candidate): string
    {
        try {
            return $specification->isSatisfiedBy($candidate) ? 'true' : 'false';
        } catch (\Throwable) {
            return 'error';
        }
    }

    /**
     * The code between the markers of example $example is the block of docs/en/$page.md token by token (its
     * `use` lines are imports of this file; a bare expression of the page is kept here as `$rN = ...`).
     */
    private function assertTranscribed(string $example, string $page, int $imports): void
    {
        $source = (string) file_get_contents(__FILE__);
        $start = strpos($source, "// README {$example} >>>\n");
        $end = strpos($source, "// <<< README {$example}\n");
        $this->assertTrue($start !== false && $end !== false && $end > $start, "transcription of example {$example} marked");
        $tokens = ReadmeBlocks::codeTokens(substr($source, (int) $start, (int) $end - (int) $start));
        $transcribed = [];
        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            if (preg_match('/^\$r\d+$/', $tokens[$i]) === 1 && ($tokens[$i + 1] ?? null) === '=') {
                $i++;
                continue;
            }
            $transcribed[] = $tokens[$i];
        }
        $block = ReadmeBlocks::of(dirname(__DIR__, 2) . "/docs/en/{$page}.md", $example)[0] ?? '';
        preg_match_all('/^use ([^;]+);$/m', $block, $found);
        $this->assertCount($imports, $found[1], "example {$example} imports {$imports} name(s)");
        foreach ($found[1] as $import) {
            $this->assertTrue(str_contains($source, "\nuse {$import};\n"), "example {$example}: {$import} is imported by this file too");
        }
        $code = ReadmeBlocks::codeTokens((string) preg_replace('/^use [^;]+;$/m', '', $block));
        $this->assertTrue(count($code) > 50 && $transcribed === $code, sprintf(
            'example %s: the transcription is the code of the page (%d tokens transcribed, %d on the page)',
            $example,
            count($transcribed),
            count($code)
        ));
    }
}
