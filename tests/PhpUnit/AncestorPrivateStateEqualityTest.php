<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\PhpUnit;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Entities\AbstractUUIDEntity;
use Antevemus\ASpecification\Repositories\InMemoryRepository;
use Antevemus\ASpecification\Repositories\PartitionRepository;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\AndSpecification;
use Antevemus\ASpecification\Specifications\Collection\AllEntitiesSpecification;
use Antevemus\ASpecification\Specifications\NotSpecification;
use Antevemus\ASpecification\Specifications\OrSpecification;
use Antevemus\ASpecification\Specifications\String\ContainsSpecification;
use Antevemus\ASpecification\Specifications\String\EndsWithSpecification;
use Antevemus\ASpecification\Specifications\String\StartsWithSpecification;
use Antevemus\ASpecification\Specifications\String\TextComparison;
use Closure;
use FilesystemIterator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Throwable;

/** Entity with a name, for partitions keyed by a text predicate. */
final class EqNamedEntity extends AbstractUUIDEntity
{
    public function __construct(public string $name)
    {
        parent::__construct();
    }
}

/** Parameter kept in a private property of an intermediate ancestor (the shape of RegexSpecification). */
abstract class EqPrivateKeyBase extends AbstractSpecification
{
    public function __construct(private readonly string $key)
    {
    }

    public function isSatisfiedBy(mixed $candidate): bool
    {
        return $candidate === $this->key;
    }

    public function getType(): string
    {
        return 'string';
    }
}

/** Declares a private property with the ancestor's name: two distinct slots that both count. */
final class EqShadowingLeaf extends EqPrivateKeyBase
{
    public function __construct(string $parentKey, private readonly string $key = 'same')
    {
        parent::__construct($parentKey);
    }

    public function ownKey(): string
    {
        return $this->key;
    }
}

/** A leaf that accepts dynamic properties. */
#[\AllowDynamicProperties]
final class EqDynamicLeaf extends AbstractSpecification
{
    public function isSatisfiedBy(mixed $candidate): bool
    {
        return true;
    }

    public function getType(): string
    {
        return 'mixed';
    }
}

/**
 * AncestorPrivateStateEqualityTest - Regression of the default structural equals() (2.1.0).
 *
 * Up to 2.0.0 AbstractSpecification::equals() read ReflectionObject::getProperties(), which omits
 * the `private` properties declared by ancestors. ContainsSpecification, StartsWithSpecification and
 * EndsWithSpecification keep all of their parameters there (LiteralPatternSpecification::$literal and
 * $comparison, RegexSpecification::$pattern), so any two of the same class were "equal" since 1.4.0:
 * simplify() changed answers, the subsumption algebra declared contains('a') a special case of
 * contains('b'), and()/or() of composites dropped an operand, and a partition of `contains('b')`
 * replaced the partition of `contains('a')`. Every check below fails with the former equals().
 *
 * @version    2.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\PhpUnit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class AncestorPrivateStateEqualityTest extends TestCase
{
    /** Candidates over which a tree and its simplification must agree. */
    private const CANDIDATES = ['', 'a', 'b', 'ab', 'ba', 'A', 'B', 'Ab', 'aB', 'aba', 'bab', 'abab', 'xyz', 'bA'];

    /**
     * Pairs of the same class that denote different predicates, by affected class. A class of src/
     * whose distinctive state lives in an ancestor's private property must have an entry (see
     * testEveryAffectedClassOfTheLibraryIsCovered()).
     *
     * @return array<class-string, list<array{0: ISpecification, 1: ISpecification}>>
     */
    private static function distinctPairs(): array
    {
        $pairs = [];
        foreach ([
            ContainsSpecification::class => static fn(string $l, bool|TextComparison $c = true) => Spec::contains($l, $c),
            StartsWithSpecification::class => static fn(string $l, bool|TextComparison $c = true) => Spec::startsWith($l, $c),
            EndsWithSpecification::class => static fn(string $l, bool|TextComparison $c = true) => Spec::endsWith($l, $c),
        ] as $class => $make) {
            $pairs[$class] = [
                [$make('a'), $make('b')],                  // literal
                [$make('a', true), $make('a', false)],     // case sensitivity
                [$make('ab'), $make('a')],
            ];
            if (extension_loaded('intl')) {
                $pairs[$class][] = [$make('a', TextComparison::IgnoreCase), $make('a', TextComparison::IgnoreCaseAndAccents)];
            }
        }

        return $pairs;
    }

    public function testEveryAffectedClassOfTheLibraryIsCovered(): void
    {
        $affected = self::affectedLibraryClasses();

        self::assertNotEmpty($affected, 'The scan found no class with ancestor private state: the scan is broken');
        foreach ($affected as $class => $hidden) {
            self::assertArrayHasKey(
                $class,
                self::distinctPairs(),
                sprintf('%s keeps state in ancestor private properties (%s): add a distinct pair', $class, implode(', ', $hidden))
            );
        }
    }

    public function testDistinctPairsOfAffectedClassesAreNotEqual(): void
    {
        foreach (self::distinctPairs() as $class => $pairs) {
            foreach ($pairs as $i => [$x, $y]) {
                self::assertInstanceOf($class, $x);
                self::assertInstanceOf($class, $y);
                self::assertFalse($x->equals($y), sprintf('%s pair #%d: different predicates reported equal', $class, $i));
                self::assertFalse($y->equals($x), sprintf('%s pair #%d: equality is not symmetric', $class, $i));
            }
        }
    }

    public function testEqualPairsOfAffectedClassesStayEqual(): void
    {
        foreach ([
            [Spec::contains('a'), Spec::contains('a')],
            [Spec::contains('a', false), Spec::contains('a', TextComparison::IgnoreCase)],
            [Spec::startsWith('ab'), Spec::startsWith('ab', TextComparison::CaseSensitive)],
            [Spec::endsWith('b', false), Spec::endsWith('b', false)],
            [Spec::contains('a')->because('r')->withCode('C'), Spec::contains('a')->because('r')->withCode('C')],
        ] as $i => [$x, $y]) {
            self::assertNotSame($x, $y);
            self::assertTrue($x->equals($y), sprintf('equal pair #%d reported different', $i));
            self::assertTrue($y->equals($x), sprintf('equal pair #%d: equality is not symmetric', $i));
        }
    }

    /** The base's own state (because()/withCode()) changes evaluate(), so it keeps taking part. */
    public function testReasonAndCodeOfTheBaseStillCount(): void
    {
        self::assertFalse(Spec::contains('a')->because('x')->equals(Spec::contains('a')->because('y')));
        self::assertFalse(Spec::contains('a')->withCode('X')->equals(Spec::contains('a')->withCode('Y')));
        self::assertFalse(Spec::contains('a')->withCode('X')->equals(Spec::contains('a')));
    }

    public function testNestedAffectedLeavesMakeTheirParentsDifferent(): void
    {
        $a = Spec::contains('a');
        $b = Spec::contains('b');

        self::assertFalse($a->not()->equals($b->not()));
        self::assertFalse(Spec::property('name', $a)->equals(Spec::property('name', $b)));
        self::assertTrue(Spec::property('name', $a)->equals(Spec::property('name', Spec::contains('a'))));
        self::assertFalse((new AndSpecification($a, Spec::endsWith('x')))->equals(new AndSpecification($b, Spec::endsWith('x'))));
    }

    /** The mechanism, on a user hierarchy: an ancestor's private slot counts, even when shadowed by name. */
    public function testUserHierarchyWithAncestorPrivateStateAndShadowing(): void
    {
        self::assertFalse((new EqShadowingLeaf('a'))->equals(new EqShadowingLeaf('b')), 'Ancestor private slot ignored');
        self::assertFalse((new EqShadowingLeaf('a', 'x'))->equals(new EqShadowingLeaf('a', 'y')), 'Own private slot ignored');
        self::assertTrue((new EqShadowingLeaf('a', 'x'))->equals(new EqShadowingLeaf('a', 'x')));
    }

    public function testDynamicPropertiesAreComparedBothWays(): void
    {
        $plain = new EqDynamicLeaf();
        $extra = new EqDynamicLeaf();
        $extra->extra = 1;
        $same = new EqDynamicLeaf();
        $same->extra = 1;

        self::assertFalse($plain->equals($extra));
        self::assertFalse($extra->equals($plain));
        self::assertTrue($extra->equals($same));
        self::assertTrue($plain->equals(new EqDynamicLeaf()));
    }

    public function testSimplifyKeepsTheAnswerOfTreesWithAffectedLeaves(): void
    {
        $a = Spec::contains('a');
        $b = Spec::contains('b');

        foreach ([
            'a AND b' => $a->and($b),
            'a OR b' => $a->or($b),
            'a OR NOT b' => $a->or($b->not()),
            'a AND NOT b' => $a->and($b->not()),
            'startsWith a AND startsWith b' => Spec::startsWith('a')->and(Spec::startsWith('b')),
            'endsWith a OR endsWith b' => Spec::endsWith('a')->or(Spec::endsWith('b')),
            'contains a (cs) AND NOT contains a (ci)' => Spec::contains('a')->and(Spec::contains('a', false)->not()),
        ] as $label => $tree) {
            $simplified = Spec::simplify($tree);
            foreach (self::CANDIDATES as $candidate) {
                self::assertSame(
                    $tree->isSatisfiedBy($candidate),
                    $simplified->isSatisfiedBy($candidate),
                    sprintf('simplify(%s) changed the answer for "%s"', $label, $candidate)
                );
            }
        }

        self::assertFalse(Spec::isContradiction($a->and($b->not())), 'contains(a) AND NOT contains(b) is satisfiable ("a")');
        self::assertFalse(Spec::isTautology($a->or($b->not())), 'contains(a) OR NOT contains(b) is false for "b"');
    }

    /**
     * Random battery: each tree is built twice (direct constructors and the fluent API, whose and()/or()
     * deduplicate equal operands) next to a reference closure; the four answers (direct, fluent, and the
     * simplification of each) must match the reference for every candidate.
     */
    public function testRandomTreesAgreeWithTheirSimplificationAndAReference(): void
    {
        mt_srand(20261012);
        $checkedFluent = 0;

        for ($n = 0; $n < 300; $n++) {
            [$direct, $fluent, $reference, $text] = self::randomTree(3);
            $simplifiedDirect = Spec::simplify($direct);
            $simplifiedFluent = $fluent !== null ? Spec::simplify($fluent) : null;
            $checkedFluent += $fluent !== null ? 1 : 0;

            foreach (self::CANDIDATES as $candidate) {
                $expected = $reference($candidate);
                $context = sprintf('tree #%d %s, candidate "%s"', $n, $text, $candidate);
                self::assertSame($expected, $direct->isSatisfiedBy($candidate), 'direct tree: ' . $context);
                self::assertSame($expected, $simplifiedDirect->isSatisfiedBy($candidate), 'simplify(direct): ' . $context);
                if ($fluent !== null) {
                    self::assertSame($expected, $fluent->isSatisfiedBy($candidate), 'fluent tree: ' . $context);
                    self::assertSame($expected, $simplifiedFluent->isSatisfiedBy($candidate), 'simplify(fluent): ' . $context);
                }
            }
        }

        self::assertGreaterThan(100, $checkedFluent, 'Too few fluent trees were built to exercise and()/or()');
    }

    public function testSubsumptionDoesNotConfuseDifferentLiterals(): void
    {
        $a = Spec::contains('a');
        $b = Spec::contains('b');

        self::assertFalse($a->isSpecialCaseOf($b));
        self::assertFalse($a->isGeneralizationOf($b));
        self::assertFalse($a->isDisjointWith($b->not()), '"a" satisfies contains(a) and NOT contains(b)');
        self::assertFalse(Spec::startsWith('a')->isSpecialCaseOf(Spec::startsWith('b')));
        self::assertFalse(Spec::endsWith('a', true)->isSpecialCaseOf(Spec::endsWith('a', false)->not()));

        self::assertTrue($a->isSpecialCaseOf(Spec::contains('a')), 'An equal specification is a special case of itself');
        self::assertTrue($a->isGeneralizationOf(Spec::contains('a')));
    }

    public function testCompositeAndOrKeepADifferentOperand(): void
    {
        $left = Spec::contains('a')->and(Spec::contains('x'));
        $right = Spec::contains('b')->and(Spec::contains('y'));
        $both = $left->and($right);

        self::assertNotSame($left, $both, 'and() dropped an operand it took for an equal one');
        self::assertFalse($both->isSatisfiedBy('ax'));
        self::assertTrue($both->isSatisfiedBy('axby'));

        $either = Spec::contains('a')->or(Spec::contains('x'))->or(Spec::contains('b')->or(Spec::contains('y')));
        self::assertTrue($either->isSatisfiedBy('b'), 'or() dropped an operand it took for an equal one');
    }

    public function testPartitionsOfDifferentLiteralsStaySiblings(): void
    {
        $root = PartitionRepository::create(new InMemoryRepository());
        $all = new AllEntitiesSpecification();

        $partA = $root->addPartition(Spec::property('name', Spec::contains('a')));
        $partA->put(new EqNamedEntity('alpha'));
        $partA->put(new EqNamedEntity('gamma'));
        $partB = $root->addPartition(Spec::property('name', Spec::contains('b')));

        self::assertCount(2, $root->getDirectPartitions(), 'name~b replaced the partition of name~a');
        self::assertSame(2, $root->count($all));
        self::assertSame(2, $partA->count($all));
        self::assertSame($partA, $root->findPartition(Spec::property('name', Spec::contains('a'))));
        self::assertSame($partB, $root->findPartition(Spec::property('name', Spec::contains('b'))));

        $lonely = PartitionRepository::create(new InMemoryRepository());
        $onlyA = $lonely->addPartition(Spec::property('name', Spec::contains('a')));
        self::assertNotSame($onlyA, $lonely->findPartition(Spec::property('name', Spec::contains('b'))), 'findPartition returned the partition of another specification');
        self::assertSame($lonely, $lonely->findPartition(Spec::property('name', Spec::contains('b'))));
    }

    /**
     * Concrete specification classes of src/ that inherit AbstractSpecification::equals() and keep
     * state in a private property declared by an ancestor, with those properties.
     *
     * @return array<class-string, list<string>>
     */
    private static function affectedLibraryClasses(): array
    {
        $src = dirname(__DIR__, 2) . '/src';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            if (preg_match('/^namespace\s+([^;]+);/m', $code, $ns) !== 1
                || preg_match_all('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $code, $classes) < 1) {
                continue;
            }
            foreach ($classes[1] as $short) {
                try {
                    class_exists($ns[1] . '\\' . $short);
                } catch (Throwable) {
                    // Optional integrations (Adianti) are not loadable here; none is a specification.
                }
            }
        }

        $affected = [];
        foreach (get_declared_classes() as $class) {
            if (!is_subclass_of($class, AbstractSpecification::class) || !str_starts_with($class, 'Antevemus\\ASpecification\\')) {
                continue;
            }
            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || str_contains($class, '\\Tests\\')
                || $reflection->getMethod('equals')->getDeclaringClass()->getName() !== AbstractSpecification::class) {
                continue;
            }
            $hidden = [];
            for ($parent = $reflection->getParentClass(); $parent !== false; $parent = $parent->getParentClass()) {
                foreach ($parent->getProperties() as $property) {
                    if ($property->isPrivate() && !$property->isStatic() && $property->getDeclaringClass()->getName() === $parent->getName()) {
                        $hidden[] = $parent->getShortName() . '::$' . $property->getName();
                    }
                }
            }
            if ($hidden !== []) {
                $affected[$class] = $hidden;
            }
        }

        return $affected;
    }

    /**
     * A random tree of string specifications: [direct, fluent or null, reference, text].
     *
     * @return array{0: ISpecification, 1: ISpecification|null, 2: Closure(string): bool, 3: string}
     */
    private static function randomTree(int $depth): array
    {
        if ($depth === 0 || mt_rand(0, 3) === 0) {
            return self::randomLeaf();
        }

        $op = mt_rand(0, 2);
        if ($op === 0) {
            [$d, $f, $r, $t] = self::randomTree($depth - 1);

            return [new NotSpecification($d), $f?->not(), static fn(string $c): bool => !$r($c), 'NOT ' . $t];
        }

        [$d1, $f1, $r1, $t1] = self::randomTree($depth - 1);
        [$d2, $f2, $r2, $t2] = self::randomTree($depth - 1);
        $fluent = null;
        try {
            if ($f1 !== null && $f2 !== null) {
                $fluent = $op === 1 ? $f1->and($f2) : $f1->or($f2);
            }
        } catch (InvalidArgumentException) {
            // A composite refuses the conjunction of disjoint operands: only the direct tree is checked.
        }

        return $op === 1
            ? [new AndSpecification($d1, $d2), $fluent, static fn(string $c): bool => $r1($c) && $r2($c), "({$t1} AND {$t2})"]
            : [new OrSpecification($d1, $d2), $fluent, static fn(string $c): bool => $r1($c) || $r2($c), "({$t1} OR {$t2})"];
    }

    /**
     * @return array{0: ISpecification, 1: ISpecification, 2: Closure(string): bool, 3: string}
     */
    private static function randomLeaf(): array
    {
        $literal = ['a', 'b', 'ab', 'A'][mt_rand(0, 3)];
        $caseSensitive = mt_rand(0, 2) !== 0;
        $kind = mt_rand(0, 2);
        $norm = static fn(string $s): string => $caseSensitive ? $s : mb_strtolower($s);

        [$spec, $reference, $name] = match ($kind) {
            0 => [Spec::contains($literal, $caseSensitive), static fn(string $c): bool => str_contains($norm($c), $norm($literal)), 'contains'],
            1 => [Spec::startsWith($literal, $caseSensitive), static fn(string $c): bool => str_starts_with($norm($c), $norm($literal)), 'startsWith'],
            default => [Spec::endsWith($literal, $caseSensitive), static fn(string $c): bool => str_ends_with($norm($c), $norm($literal)), 'endsWith'],
        };

        // The fluent tree gets its own instance: equal leaves meet only through equals(), never by identity.
        return [$spec, self::rebuild($kind, $literal, $caseSensitive), $reference,
            sprintf('%s(%s,%s)', $name, $literal, $caseSensitive ? 'cs' : 'ci')];
    }

    private static function rebuild(int $kind, string $literal, bool $caseSensitive): ISpecification
    {
        return match ($kind) {
            0 => Spec::contains($literal, $caseSensitive),
            1 => Spec::startsWith($literal, $caseSensitive),
            default => Spec::endsWith($literal, $caseSensitive),
        };
    }
}
