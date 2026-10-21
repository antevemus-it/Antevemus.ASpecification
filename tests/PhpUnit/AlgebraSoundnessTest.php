<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\PhpUnit;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Engine\DocumentGroupSpecificationBuilder;
use Antevemus\ASpecification\Engine\DocumentLeafSpecification;
use Antevemus\ASpecification\Engine\DocumentRequirementMode;
use Antevemus\ASpecification\Engine\DocumentRuleDefinition;
use Antevemus\ASpecification\Engine\DocumentSetSpecification;
use Antevemus\ASpecification\Engine\RuleBoundSpecification;
use Antevemus\ASpecification\Engine\RuleDefinition;
use Antevemus\ASpecification\Entities\AbstractEntity;
use Antevemus\ASpecification\FeatureFlags\FeatureEnabledSpecification;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagDefinition;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagRegistry;
use Antevemus\ASpecification\FeatureFlags\FeatureFlags;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagState;
use Antevemus\ASpecification\FeatureFlags\InMemoryFeatureFlagStore;
use Antevemus\ASpecification\Repositories\InMemoryRepository;
use Antevemus\ASpecification\Repositories\PartitionRepository;
use Antevemus\ASpecification\Repositories\Pdo\InQuerySpecification;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\ColumnType;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\EntityMapping;
use Antevemus\ASpecification\Repositories\Pdo\PdoRepository;
use Antevemus\ASpecification\Resolution\CallableDataSource;
use Antevemus\ASpecification\Resolution\DataSourceRegistry;
use Antevemus\ASpecification\Resolution\ExternalDataSpecification;
use Antevemus\ASpecification\Specifications\LabelledSpecification;
use Antevemus\ASpecification\Specifications\Subquery\ExistsSubquerySpecification;
use Antevemus\ASpecification\Specifications\Subquery\InSubquerySpecification;
use Antevemus\ASpecification\Serialization\Exceptions\NonSerializableSpecificationException;
use Antevemus\ASpecification\Serialization\SpecificationCodec;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\AndSpecification;
use Antevemus\ASpecification\Specifications\Collection\AllEntitiesSpecification;
use Antevemus\ASpecification\Specifications\Collection\CollectionSpecification;
use Antevemus\ASpecification\Specifications\Collection\UniqueEntitySpecification;
use Antevemus\ASpecification\Specifications\Comparison\EqualSpecification;
use Antevemus\ASpecification\Specifications\Comparison\InSpecification;
use Antevemus\ASpecification\Specifications\Comparison\IsNullSpecification;
use Antevemus\ASpecification\Specifications\Comparison\LooseEqualSpecification;
use Antevemus\ASpecification\Specifications\Comparison\NotEqualSpecification;
use Antevemus\ASpecification\Specifications\Comparison\NotNullSpecification;
use Antevemus\ASpecification\Specifications\Comparison\SameInstantSpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysFalseSpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysTrueSpecification;
use Antevemus\ASpecification\Specifications\Logical\DefaultValueSpecification;
use Antevemus\ASpecification\Specifications\Logical\JointDenialSpecification;
use Antevemus\ASpecification\Specifications\NotSpecification;
use Antevemus\ASpecification\Specifications\OrSpecification;
use Antevemus\ASpecification\Specifications\PredicateSpecification;
use Antevemus\ASpecification\Specifications\PropertySpecification;
use Antevemus\ASpecification\Specifications\Reflection\MethodParameterizedSpecification;
use Antevemus\ASpecification\Specifications\String\DateStringSpecification;
use Antevemus\ASpecification\Specifications\String\EnumNameStringSpecification;
use Antevemus\ASpecification\Specifications\String\RegexSpecification;
use Antevemus\ASpecification\Specifications\String\TextComparison;
use Antevemus\ASpecification\Specifications\String\WildcardExpressionMatcherIgnoreCaseStringSpecification;
use Antevemus\ASpecification\Specifications\String\WildcardSpecification;
use Antevemus\ASpecification\StateMachine\Event;
use Antevemus\ASpecification\StateMachine\EventOrigin;
use Antevemus\ASpecification\StateMachine\EventOriginSpecification;
use Antevemus\ASpecification\StateMachine\InStateSpecification;
use Antevemus\ASpecification\StateMachine\MachineSnapshot;
use Antevemus\ASpecification\StateMachine\PayloadHasSpecification;
use Antevemus\ASpecification\StateMachine\TransitionCandidate;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use FilesystemIterator;
use PDO;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionProperty;
use Throwable;

/** Value object without identity of its own: compared by identity by equalTo(), loosely by looselyEqualTo(). */
final class AsbMoney
{
    public function __construct(public mixed $amount)
    {
    }
}

/** A date-time of a user class (the comparison of date-times must ignore the class). */
final class AsbDate extends DateTimeImmutable
{
}

/** Entity with a chosen identifier, a name and an "active" flag. */
class AsbCustomer extends AbstractEntity
{
    public function __construct(private readonly mixed $id, public readonly string $name = 'ana', public readonly bool $active = true)
    {
        parent::__construct();
    }

    public function getEntityId(): mixed
    {
        return $this->id;
    }
}

/** A VIP customer is a customer. */
final class AsbVip extends AsbCustomer
{
}

/** Entity class unrelated to the customer. */
final class AsbOrder extends AbstractEntity
{
    public function __construct(private readonly mixed $id, public readonly string $name = 'order')
    {
        parent::__construct();
    }

    public function getEntityId(): mixed
    {
        return $this->id;
    }
}

/** Plain object with one public property, the candidate of the property restrictions. */
final class AsbBox
{
    public function __construct(public mixed $x)
    {
    }
}

/** Target of the method-call specifications. */
final class AsbClock
{
    public function day(DateTimeInterface $at): string
    {
        return $at->format('Y-m-d');
    }

    public function isAfterNewYear(DateTimeInterface $at): bool
    {
        return $at >= new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
    }

    public function total(int $n): int
    {
        return $n * 3;
    }
}

/** Row of the subquery (InQuerySpecification) table. */
final class AsbRow extends AbstractEntity
{
    public function __construct(private ?int $id, public string $label)
    {
        parent::__construct();
    }

    public function getEntityId(): ?int
    {
        return $this->id;
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}

enum AsbPlan: string
{
    case Gold = 'gold';
    case Silver = 'silver';
}

/** User subclass of AllEntitiesSpecification with a predicate of its own (D4 case 3). */
final class AsbActiveCustomers extends AllEntitiesSpecification
{
    public function isSatisfiedBy(?object $candidate): bool
    {
        return parent::isSatisfiedBy($candidate) && $candidate instanceof AsbCustomer && $candidate->active;
    }
}

/** User subclass of UniqueEntitySpecification with a predicate of its own. */
final class AsbActiveUnique extends UniqueEntitySpecification
{
    public function isSatisfiedBy(?object $candidate): bool
    {
        return parent::isSatisfiedBy($candidate) && $candidate instanceof AsbCustomer && $candidate->active;
    }
}

/** User subclass of EqualSpecification with a predicate of its own: case-insensitive text, null accepted. */
final class AsbCaseInsensitiveEqual extends EqualSpecification
{
    public function isSatisfiedBy(mixed $candidate): bool
    {
        if ($candidate === null) {
            return true;
        }

        return is_string($candidate) && is_string($this->getValue()) && strcasecmp($candidate, $this->getValue()) === 0;
    }
}

/** User subclass of InSpecification that also accepts any numeric text of a member. */
final class AsbNumericTextIn extends InSpecification
{
    public function isSatisfiedBy(mixed $candidate): bool
    {
        foreach ($this->getValues() as $value) {
            if ((is_int($value) || is_float($value)) && is_string($candidate) && is_numeric($candidate) && (float) $candidate === (float) $value) {
                return true;
            }
        }

        return parent::isSatisfiedBy($candidate);
    }
}

/** User subclass of NotNullSpecification that refuses the empty text too. */
final class AsbPresent extends NotNullSpecification
{
    public function isSatisfiedBy(mixed $candidate): bool
    {
        return $candidate !== null && $candidate !== '';
    }
}

/** User subclass of IsNullSpecification that accepts the empty text too. */
final class AsbBlank extends IsNullSpecification
{
    public function isSatisfiedBy(mixed $candidate): bool
    {
        return $candidate === null || $candidate === '';
    }
}

/** User leaf with a predicate of its own and the default algebra: even integers. */
final class AsbEven extends AbstractSpecification
{
    public function __construct(private readonly int $offset = 0)
    {
    }

    public function isSatisfiedBy(mixed $candidate): bool
    {
        return is_int($candidate) && ($candidate + $this->offset) % 2 === 0;
    }

    public function getType(): string
    {
        return 'mixed';
    }
}

/**
 * AlgebraSoundnessTest - Soundness bench of the specification algebra (forward 037, D2).
 *
 * Principle (§1 of the forward): the algebra never promises more than the evaluation delivers. For
 * every pair of specifications A, B of a pool and every candidate c of a pool:
 *
 * - A->equals(B) ⇒ A(c) == B(c) and the same annotated failures (because()/withCode())
 * - A->isGeneralizationOf(B) ⇒ (B(c) ⇒ A(c)); A->isSpecialCaseOf(B) ⇒ (A(c) ⇒ B(c))
 * - A->isDisjointWith(B) ⇒ ¬(A(c) ∧ B(c))
 * - Spec::isTautology(A) / Spec::isContradiction(A) ⇒ A(c) always true / false (c not null, 025 D4)
 * - Spec::simplify(A)(c) == A(c) (c not null) and the annotated failures are kept (025 D3)
 * - A->and(B) / A->or(B) answer as the tree built without deduplication
 * - addPartition()/findPartition() never confuse specifications that answer differently
 *
 * Only defined answers are compared: an evaluation that raises (a type error, a missing method) is
 * outside the truth-value guarantee (forward 025 D4), so a pair is checked on the candidates both
 * sides answer. The pool covers every concrete specification class of src/ (a guard fails when a new
 * one appears), the anonymous classes of the factory, user subclasses with predicates of their own
 * and annotated variants; composites (and, or, not, nor, the fluent and()/or(), annotations,
 * property restrictions) are generated with a fixed seed.
 *
 * Cost (D7): the fixed part runs in the default suite. ASPEC_SOUNDNESS_EXTENDED=1 widens it (more
 * and deeper random trees, every composite pair instead of the pairs of the same family);
 * ASPEC_SOUNDNESS_DUMP=<file> writes every violation found as JSON lines (diagnosis).
 *
 * Serialization (2.4.0, forward 041, RN-02 and RN-04): every specification of the pool that the
 * SpecificationCodec writes comes back equal (`decode(encode(s))->equals(s)`, both ways), with the same
 * answers on every candidate and the same document (`encode(decode(encode(s))) === encode(s)`, and the
 * same JSON text); the ones it refuses are refused for a reason of the contract (D3/D4: a callback, an
 * external data leaf, a feature flag, a guard of the state machine, a subquery, a rule binding, a document
 * requirement, a user class, a value object); the decoded specification translates to the same SQL (ANSI,
 * PostgreSQL, MySQL, same parameters) and the same TCriteria as the original.
 *
 * Labels and subqueries (2.5.0, forward 048, RN-02 and RN-04): the pool holds labelled targets (nested and
 * annotated) and the subqueries of Spec::inSubquery()/Spec::existsIn(), which answer ERROR on every
 * candidate (they are evaluated by the database only) and are refused by the codec as out of the contract;
 * labelling any specification of the pool keeps its answers, its failures (reported against the label),
 * its SQL and its TCriteria, and comes back equal from the codec.
 *
 * Integer leaves (2.9.0, forward 044, RN-02): the pool holds int-typed leaves of the six operators
 * (asInteger(), Spec::int()), whose bounds close against each other (int(x > 10) ⟂ int(x < 11)), the
 * candidates 10, 10.0, 10.5, '10', 11 and 12, and trees that mix them with the numeric leaves, generated
 * from a seed of their own so that every specification of 2.5.0 is still in the pool as it was; a guard
 * fails when a class asInteger() accepts is missing int-typed.
 *
 * @version    2.9.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\PhpUnit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class AlgebraSoundnessTest extends TestCase
{
    private const OUTCOME_FALSE = 0;
    private const OUTCOME_TRUE = 1;
    private const OUTCOME_ERROR = 2;

    /** Seed of the generated composites. */
    private const SEED = 20261013;

    /** @var array{candidates: array<string, mixed>, specs: list<array{label: string, spec: ISpecification, family: string, composite: bool}>, truth: list<list<int>>}|null */
    private static ?array $world = null;

    private static ?DataSourceRegistry $previousRegistry = null;

    private static bool $registrySwapped = false;

    public static function tearDownAfterClass(): void
    {
        if (self::$registrySwapped) {
            DataSourceRegistry::setDefault(self::$previousRegistry);
            self::$registrySwapped = false;
        }
        self::$world = null;
    }

    // ==========================================
    // Guard
    // ==========================================

    public function testEveryConcreteSpecificationClassOfTheLibraryIsInThePool(): void
    {
        $covered = [];
        foreach (self::world()['specs'] as $entry) {
            self::collectClasses($entry['spec'], $covered);
        }

        $missing = [];
        foreach (self::libraryClasses() as $class) {
            if (!isset($covered[$class]) && !in_array($class, self::unavailableClasses(), true)) {
                $missing[] = $class;
            }
        }

        self::assertNotEmpty(self::libraryClasses(), 'The scan of src/ found no specification class: the scan is broken');
        self::assertSame([], $missing, 'Concrete specification classes of src/ missing from the soundness pool: add them to leaves()');
    }

    /**
     * 2.9.0 (forward 044): every class that asInteger() accepts has an int-typed leaf in the pool, alone and
     * inside the generated trees, and the pool holds the candidates that tell an int-typed leaf apart (10,
     * 10.0, 10.5, '10').
     */
    public function testEveryIntegerLeafClassIsInThePoolIntTyped(): void
    {
        $integerClasses = [];
        $inTrees = 0;
        foreach (self::world()['specs'] as $entry) {
            $found = [];
            self::collectIntegerLeaves($entry['spec'], $found);
            $integerClasses += $found;
            $inTrees += $entry['composite'] && $found !== [] ? 1 : 0;
        }
        ksort($integerClasses);
        $expected = [
            \Antevemus\ASpecification\Specifications\Comparison\EqualSpecification::class => true,
            \Antevemus\ASpecification\Specifications\Comparison\GreaterThanOrEqualSpecification::class => true,
            \Antevemus\ASpecification\Specifications\Comparison\GreaterThanSpecification::class => true,
            \Antevemus\ASpecification\Specifications\Comparison\LessThanOrEqualSpecification::class => true,
            \Antevemus\ASpecification\Specifications\Comparison\LessThanSpecification::class => true,
            \Antevemus\ASpecification\Specifications\Comparison\NotEqualSpecification::class => true,
        ];
        ksort($expected);
        self::assertSame($expected, $integerClasses, 'every class asInteger() accepts is in the pool int-typed');
        self::assertGreaterThan(40, $inTrees, 'int-typed leaves inside the generated trees');
        $candidates = self::world()['candidates'];
        foreach (['10' => 10, '10.0' => 10.0, '10.5' => 10.5, "'10'" => '10'] as $label => $value) {
            self::assertArrayHasKey($label, $candidates);
            self::assertSame($value, $candidates[$label]);
        }
    }

    // ==========================================
    // Promises over pairs
    // ==========================================

    public function testEqualsNeverJoinsSpecificationsThatAnswerOrReportDifferently(): void
    {
        $world = self::world();
        $violations = [];
        foreach (self::pairs() as [$i, $j]) {
            $a = $world['specs'][$i];
            $b = $world['specs'][$j];
            if (!self::query(static fn(): bool => $a['spec']->equals($b['spec']))) {
                continue;
            }
            $c = self::pairWitness('equals', $i, $j);
            if ($c !== null) {
                $violations[] = self::violation('equals', $a, $b, $c, 'equal specifications answer differently');
                continue;
            }
            $c = self::firstAnnotationDifference($a['spec'], $b['spec'], $world['truth'][$i], $world['truth'][$j]);
            if ($c !== null) {
                $violations[] = self::violation('equals-annotations', $a, $b, $c, 'equal specifications report different annotated failures');
            }
        }

        self::assertNoViolations($violations);
    }

    public function testGeneralizationAndSpecialCaseAreSound(): void
    {
        $world = self::world();
        $violations = [];
        foreach (self::pairs() as [$i, $j]) {
            $a = $world['specs'][$i];
            $b = $world['specs'][$j];
            if (self::query(static fn(): bool => $a['spec']->isGeneralizationOf($b['spec']))) {
                $c = self::pairWitness('isGeneralizationOf', $i, $j);
                if ($c !== null) {
                    $violations[] = self::violation('isGeneralizationOf', $a, $b, $c, 'B accepts a candidate A refuses');
                }
            }
            if (self::query(static fn(): bool => $a['spec']->isSpecialCaseOf($b['spec']))) {
                $c = self::pairWitness('isSpecialCaseOf', $i, $j);
                if ($c !== null) {
                    $violations[] = self::violation('isSpecialCaseOf', $a, $b, $c, 'A accepts a candidate B refuses');
                }
            }
        }

        self::assertNoViolations($violations);
    }

    public function testDisjointnessIsSound(): void
    {
        $world = self::world();
        $violations = [];
        foreach (self::pairs() as [$i, $j]) {
            $a = $world['specs'][$i];
            $b = $world['specs'][$j];
            if (!self::query(static fn(): bool => $a['spec']->isDisjointWith($b['spec']))) {
                continue;
            }
            $c = self::pairWitness('isDisjointWith', $i, $j);
            if ($c !== null) {
                $violations[] = self::violation('isDisjointWith', $a, $b, $c, 'a candidate satisfies both');
            }
        }

        self::assertNoViolations($violations);
    }

    // ==========================================
    // Promises over one specification
    // ==========================================

    public function testTautologyAndContradictionAreSound(): void
    {
        $world = self::world();
        $violations = [];
        foreach ($world['specs'] as $i => $entry) {
            $tautology = self::query(static fn(): bool => Spec::isTautology($entry['spec']));
            $contradiction = self::query(static fn(): bool => Spec::isContradiction($entry['spec']));
            $candidates = array_values($world['candidates']);
            foreach ($world['truth'][$i] as $k => $outcome) {
                if ($k === 0) {
                    continue; // null (forward 025 D4)
                }
                if ($tautology && $outcome === self::OUTCOME_FALSE) {
                    $violations[] = self::violation('isTautology', $entry, null, $k, 'a candidate does not satisfy it');
                    break;
                }
                if ($contradiction && $outcome === self::OUTCOME_TRUE) {
                    $violations[] = self::violation('isContradiction', $entry, null, $k, 'a candidate satisfies it');
                    break;
                }
            }
        }

        self::assertNoViolations($violations);
    }

    public function testSimplifyKeepsTheAnswerAndTheAnnotatedFailures(): void
    {
        $world = self::world();
        $candidates = array_values($world['candidates']);
        $violations = [];
        foreach ($world['specs'] as $i => $entry) {
            try {
                $simplified = Spec::simplify($entry['spec']);
            } catch (Throwable $e) {
                $violations[] = self::violation('simplify-raised', $entry, null, 1, $e::class . ': ' . $e->getMessage());
                continue;
            }
            if ($simplified === $entry['spec']) {
                continue;
            }
            $vocabulary = self::annotationVocabulary($entry['spec']);
            foreach ($candidates as $k => $candidate) {
                if ($k === 0) {
                    continue; // null (forward 025 D4)
                }
                $before = $world['truth'][$i][$k];
                if ($before === self::OUTCOME_ERROR) {
                    continue;
                }
                $after = self::outcome($simplified, $candidate);
                if ($after !== $before) {
                    $violations[] = self::violation('simplify', $entry, null, $k, sprintf('answer %s became %s (simplified: %s)', self::show($before), self::show($after), self::describe($simplified)));
                    break;
                }
                if ($before === self::OUTCOME_FALSE && $vocabulary !== []) {
                    $mine = self::annotatedFailures($entry['spec'], $candidate, $vocabulary);
                    $theirs = self::annotatedFailures($simplified, $candidate, $vocabulary);
                    if ($mine !== null && $theirs !== null && $mine !== $theirs) {
                        $violations[] = self::violation('simplify-annotations', $entry, null, $k, sprintf('annotated failures changed (simplified: %s)', self::describe($simplified)));
                        break;
                    }
                }
            }
        }

        self::assertNoViolations($violations);
    }

    public function testFluentAndOrAnswerAsTheTreeBuiltWithoutDeduplication(): void
    {
        $world = self::world();
        $candidates = array_values($world['candidates']);
        $violations = [];
        foreach (self::pairs() as [$i, $j]) {
            $a = $world['specs'][$i];
            $b = $world['specs'][$j];
            foreach (['and' => AndSpecification::class, 'or' => OrSpecification::class] as $operator => $class) {
                try {
                    $fluent = $a['spec']->{$operator}($b['spec']);
                } catch (\InvalidArgumentException) {
                    continue; // a typed composite refuses the pair (types, disjoint operands): nothing is built
                }
                if ($fluent instanceof $class && $fluent->getLeftSide() === $a['spec'] && $fluent->getRightSide() === $b['spec']) {
                    continue;
                }
                $direct = new $class($a['spec'], $b['spec']);
                foreach ($candidates as $k => $candidate) {
                    $expected = self::outcome($direct, $candidate);
                    $actual = self::outcome($fluent, $candidate);
                    if ($expected !== self::OUTCOME_ERROR && $actual !== $expected) {
                        $violations[] = self::violation($operator . '()', $a, $b, $k, sprintf('fluent %s answers %s, the tree %s', $operator, self::show($actual), self::show($expected)));
                        break;
                    }
                }
            }
        }

        self::assertNoViolations($violations);
    }

    public function testPartitionsNeverConfuseSpecificationsThatAnswerDifferently(): void
    {
        $world = self::world();
        $entityIndexes = [];
        foreach ($world['specs'] as $i => $entry) {
            if ($entry['family'] === 'entity') {
                $entityIndexes[] = $i;
            }
        }
        $violations = [];
        foreach ($entityIndexes as $i) {
            foreach ($entityIndexes as $j) {
                if ($i === $j) {
                    continue;
                }
                $a = $world['specs'][$i];
                $b = $world['specs'][$j];
                try {
                    $root = PartitionRepository::create(new InMemoryRepository());
                    $root->addPartition($a['spec']);
                    $root->addPartition($b['spec']);
                    $found = ['A' => [$i, $root->findPartition($a['spec'])], 'B' => [$j, $root->findPartition($b['spec'])]];
                    $nodes = $root->getAllPartitions();
                } catch (Throwable) {
                    continue; // a partition the repository refuses (overlapping siblings): nothing to confuse
                }

                foreach ($found as $side => [$index, $partition]) {
                    if ($partition === null || $partition->isRoot()) {
                        continue;
                    }
                    $partitionSpec = $partition->getSpecification();
                    $c = self::firstImplicationFailure($world['truth'][$index], self::truthOf($partitionSpec));
                    if ($c !== null) {
                        $violations[] = self::violation('findPartition', $a, $b, $c, "findPartition({$side}) returned a partition that does not hold every candidate of {$side}");
                    }
                }
                $kept = ['A' => false, 'B' => false];
                foreach ($nodes as $node) {
                    $kept['A'] = $kept['A'] || $node->getSpecification() === $a['spec'];
                    $kept['B'] = $kept['B'] || $node->getSpecification() === $b['spec'];
                }
                if (!$kept['A'] && self::firstDisagreement($world['truth'][$i], $world['truth'][$j]) !== null) {
                    $violations[] = self::violation('addPartition', $a, $b, self::firstDisagreement($world['truth'][$i], $world['truth'][$j]), 'the partition of B replaced the partition of A, which answers differently');
                }
            }
        }

        self::assertNoViolations($violations);
    }

    // ==========================================
    // Serialization (forward 041)
    // ==========================================

    /**
     * RN-02 / D5: the round trip over the whole pool (leaves, negations, generated composites, annotated
     * variants). Every specification the codec writes comes back equal, answering the same on every
     * candidate, and its document (array and JSON text) is canonical; every refusal is one of the
     * contract.
     */
    public function testSerializationRoundTripKeepsEverySerializableSpecificationOfThePool(): void
    {
        $world = self::world();
        $codec = new SpecificationCodec();
        $violations = [];
        $serialized = 0;
        $refused = [];
        foreach ($world['specs'] as $i => $entry) {
            try {
                $document = $codec->encode($entry['spec']);
            } catch (NonSerializableSpecificationException $e) {
                if (!self::isRefusalOfTheContract($e)) {
                    $violations[] = self::violation('serialization-refused', $entry, null, 1, $e->getMessage());
                }
                $group = self::refusalGroup($e);
                $refused[$group] = ($refused[$group] ?? 0) + 1;
                continue;
            } catch (Throwable $e) {
                $violations[] = self::violation('serialization-raised', $entry, null, 1, $e::class . ': ' . $e->getMessage());
                continue;
            }
            $serialized++;
            try {
                $decoded = $codec->decode($document);
                $again = $codec->encode($decoded);
                $json = $codec->toJson($entry['spec']);
                $fromJson = $codec->fromJson($json);
                $jsonAgain = $codec->toJson($fromJson);
            } catch (Throwable $e) {
                $violations[] = self::violation('round-trip-raised', $entry, null, 1, $e::class . ': ' . $e->getMessage());
                continue;
            }
            if (!$decoded->equals($entry['spec']) || !$entry['spec']->equals($decoded) || !$fromJson->equals($entry['spec'])) {
                $violations[] = self::violation('round-trip-equals', $entry, null, 1, 'decoded: ' . json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                continue;
            }
            if ($again !== $document || $jsonAgain !== $json) {
                $violations[] = self::violation('round-trip-canonical', $entry, null, 1, 'document changed: ' . $json . ' -> ' . $jsonAgain);
                continue;
            }
            $k = self::firstDisagreement($world['truth'][$i], self::truthOf($decoded));
            if ($k !== null || self::truthOf($fromJson) !== $world['truth'][$i]) {
                $violations[] = self::violation('round-trip-answers', $entry, null, $k ?? 1, 'the decoded specification answers differently');
            }
        }

        self::assertNoViolations($violations);
        ksort($refused);
        $summary = [];
        foreach ($refused as $group => $count) {
            $summary[] = "{$group} {$count}";
        }
        fwrite(STDOUT, sprintf(
            "\n    [041 RN-02] pool de %d especificações: %d serializadas (ida e volta OK), %d recusadas pelo contrato (%s)\n",
            count($world['specs']),
            $serialized,
            array_sum($refused),
            implode(', ', $summary)
        ));
        // Not vacuous: the fixed pool writes 497 specifications (510 with ext-intl), the extended one more
        self::assertGreaterThanOrEqual(400, $serialized, 'The pool must keep hundreds of serializable specifications');
    }

    /**
     * RN-02: the specifications left out of the format (D4) are refused with the exception and the reason:
     * a callback, a method call whose result is a callback, an external data leaf, a feature flag, a guard
     * of the state machine; and a value without a serialized form (D3).
     */
    public function testSerializationRefusesTheSpecificationsOutOfTheContract(): void
    {
        $codec = new SpecificationCodec();
        $byLabel = [];
        foreach (self::world()['specs'] as $entry) {
            $byLabel[$entry['label']] ??= $entry['spec'];
        }
        $expected = [
            'predicate(is_int)' => [PredicateSpecification::class, 'callback', 'code, not data'],
            'external(asb.x, equal(1))' => [ExternalDataSpecification::class, 'external', 'data source'],
            'featureEnabled(on)' => [FeatureEnabledSpecification::class, 'featureEnabled', 'FeatureFlags service'],
            'inState(r.a)' => [InStateSpecification::class, 'inState', 'guard of the state machine'],
            'eventOrigin(external)' => [EventOriginSpecification::class, 'eventOrigin', 'guard of the state machine'],
            'payloadHas(f)' => [PayloadHasSpecification::class, 'payloadHas', 'guard of the state machine'],
            "inQuery(rows.id where label startsWith 'a')" => [InQuerySpecification::class, 'inQuery', 'PdoRepository'],
            'ruleBound(property(x, equal(1)))' => [RuleBoundSpecification::class, 'ruleBound', 'catalog rule'],
            "inSubquery(x in asb_rows.id where label startsWith 'a')" => [InSubquerySpecification::class, 'property.inSubquery', 'names a table'],
            "existsIn(asb_rows.id = asb_box.x where label startsWith 'a')" => [ExistsSubquerySpecification::class, 'existsIn', 'names a table'],
            'user even' => [AsbEven::class, '<AsbEven>', 'does not implement ISerializableSpecification'],
            "user caseInsensitiveEqual('a')" => [AsbCaseInsensitiveEqual::class, '<AsbCaseInsensitiveEqual>', 'not registered in the codec'],
        ];
        foreach ($expected as $label => [$class, $path, $reason]) {
            self::assertArrayHasKey($label, $byLabel, "pool leaf {$label}");
            try {
                $codec->encode($byLabel[$label]);
                self::fail("{$label} must be refused");
            } catch (NonSerializableSpecificationException $e) {
                self::assertSame($class, $e->specificationClass, $label);
                self::assertSame($path, $e->path, $label);
                self::assertStringContainsString($reason, $e->getMessage(), $label);
            }
        }

        // A method call whose result is a callback (D4: "MethodParameterized with a closure")
        try {
            $codec->encode(Spec::calling('total', [3], new PredicateSpecification(static fn(mixed $n): bool => $n > 5)));
            self::fail('a method call with a callback must be refused');
        } catch (NonSerializableSpecificationException $e) {
            self::assertSame('method.callback', $e->path);
            self::assertSame(PredicateSpecification::class, $e->specificationClass);
        }

        // Values without a serialized form (D3): a value object, inside a property
        try {
            $codec->encode($byLabel['property(x, equal(Money(1)))']);
            self::fail('a value object must be refused');
        } catch (NonSerializableSpecificationException $e) {
            self::assertNull($e->specificationClass);
            self::assertSame('property.eq.v', $e->path);
            self::assertStringContainsString(AsbMoney::class, $e->getMessage());
        }
        try {
            $codec->encode($byLabel['in(Money(1), Money(1)#2)']);
            self::fail('a value object in a set must be refused');
        } catch (NonSerializableSpecificationException $e) {
            self::assertSame('in.v[0]', $e->path);
        }
    }

    /**
     * RN-04: the decoded specification translates to the same SQL (ANSI, PostgreSQL, MySQL; same text, same
     * parameters) and to the same TCriteria (dump) as the original, for every serializable specification of
     * the pool; a translation that refuses the original refuses the decoded one with the same exception.
     */
    public function testDecodedSpecificationsTranslateToTheSameSqlAndCriteria(): void
    {
        $codec = new SpecificationCodec();
        $violations = [];
        $compared = 0;
        $translated = ['ansi' => 0, 'pgsql' => 0, 'mysql' => 0, 'criteria' => 0];
        foreach (self::world()['specs'] as $entry) {
            try {
                $decoded = $codec->decode($codec->encode($entry['spec']));
            } catch (NonSerializableSpecificationException) {
                continue;
            }
            $compared++;
            foreach (['ansi', 'pgsql', 'mysql'] as $dialect) {
                $expected = self::sqlOf($entry['spec'], $dialect);
                if ($expected !== self::sqlOf($decoded, $dialect)) {
                    $violations[] = self::violation('sql-parity', $entry, null, 1, $dialect . ': ' . json_encode([$expected, self::sqlOf($decoded, $dialect)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                } elseif (!isset($expected['error'])) {
                    $translated[$dialect]++;
                }
            }
            $expected = self::criteriaOf($entry['spec']);
            if ($expected !== self::criteriaOf($decoded)) {
                $violations[] = self::violation('criteria-parity', $entry, null, 1, json_encode([$expected, self::criteriaOf($decoded)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } elseif (!isset($expected['error'])) {
                $translated['criteria']++;
            }
        }

        self::assertNoViolations($violations);
        fwrite(STDOUT, sprintf(
            "\n    [041 RN-04] %d árvores decodificadas: mesmo SQL (ANSI %d, PostgreSQL %d, MySQL %d traduzidas; as demais recusadas igual) e mesmo TCriteria (%d traduzidas)\n",
            $compared,
            $translated['ansi'],
            $translated['pgsql'],
            $translated['mysql'],
            $translated['criteria']
        ));
        self::assertGreaterThan(0, $translated['ansi'], 'some decoded trees translate to SQL');
        self::assertGreaterThan(0, $translated['criteria'], 'some decoded trees translate to TCriteria');
    }

    /**
     * Forward 048, RN-02 (L3, 2.5.0): for every specification s of the pool, `labelled(L, s)` answers as s on
     * every candidate (truth and isError), reports the same failures (message and code) against the label
     * (`property` = L and `metadata['label']` = L, the technical property kept in `metadata['property_path']`;
     * a failure labelled inside keeps its label), keeps its node through simplify(), translates to the same
     * SQL (ANSI, PostgreSQL, MySQL) and TCriteria as s, and comes back equal from the codec when s serializes.
     */
    public function testLabellingKeepsTheAnswerAndReportsEveryFailureAgainstTheLabel(): void
    {
        $world = self::world();
        $codec = new SpecificationCodec();
        $label = 'Target: 3 (maximum 2)';
        $violations = [];
        $failures = 0;
        $serialized = 0;
        foreach ($world['specs'] as $i => $entry) {
            $spec = $entry['spec'];
            $labelled = Spec::labelled($label, $spec);
            $k = self::firstDisagreement($world['truth'][$i], self::truthOf($labelled));
            if ($k !== null) {
                $violations[] = self::violation('label-answer', $entry, null, $k, 'the labelled specification answers differently');
                continue;
            }
            foreach (array_values($world['candidates']) as $k => $candidate) {
                set_error_handler(static function (int $severity, string $message): never {
                    throw new \ErrorException($message, 0, $severity);
                });
                try {
                    $plain = $spec->evaluate($candidate);
                    $marked = $labelled->evaluate($candidate);
                } catch (Throwable) {
                    continue;
                } finally {
                    restore_error_handler();
                }
                if ($plain->isSatisfied !== $marked->isSatisfied || $plain->isError !== $marked->isError || count($plain->failures) !== count($marked->failures)) {
                    $violations[] = self::violation('label-result', $entry, null, $k, 'satisfied, isError or the number of failures changed');
                    continue 2;
                }
                foreach ($plain->failures as $f => $failure) {
                    $reported = $marked->failures[$f];
                    $inner = $failure->metadata[LabelledSpecification::METADATA_LABEL] ?? null;
                    $expectedProperty = $inner === null ? $label : $failure->property;
                    $expectedPath = ($inner === null && $failure->property !== null && $failure->property !== $label)
                        ? $failure->property
                        : ($failure->metadata[LabelledSpecification::METADATA_PROPERTY_PATH] ?? null);
                    if ($reported->property !== $expectedProperty
                        || ($reported->metadata[LabelledSpecification::METADATA_LABEL] ?? null) !== ($inner ?? $label)
                        || ($reported->metadata[LabelledSpecification::METADATA_PROPERTY_PATH] ?? null) !== $expectedPath
                        || $reported->message !== $failure->message
                        || $reported->code !== $failure->code) {
                        $violations[] = self::violation('label-failure', $entry, null, $k, json_encode([$failure->property, $reported->property, $reported->metadata[LabelledSpecification::METADATA_PROPERTY_PATH] ?? null]));
                        continue 3;
                    }
                    $failures++;
                }
            }
            $simplified = Spec::simplify($labelled);
            if (!$simplified instanceof LabelledSpecification || $simplified->getLabel() !== $label) {
                $violations[] = self::violation('label-simplify', $entry, null, 1, 'simplify() dropped the label');
            }
            foreach (['ansi', 'pgsql', 'mysql'] as $dialect) {
                if (self::sqlOf($spec, $dialect) !== self::sqlOf($labelled, $dialect)) {
                    $violations[] = self::violation('label-sql', $entry, null, 1, $dialect . ': the label changed the SQL');
                }
            }
            if (self::criteriaOf($spec) !== self::criteriaOf($labelled)) {
                $violations[] = self::violation('label-criteria', $entry, null, 1, 'the label changed the TCriteria');
            }
            try {
                $codec->encode($spec);
            } catch (NonSerializableSpecificationException) {
                continue;
            }
            $decoded = $codec->decode($codec->encode($labelled));
            if (!$decoded->equals($labelled) || !$labelled->equals($decoded)) {
                $violations[] = self::violation('label-codec', $entry, null, 1, 'the labelled specification did not come back equal');
            }
            $serialized++;
        }

        self::assertNoViolations($violations);
        fwrite(STDOUT, sprintf(
            "\n    [048 RN-02 L3] %d especificações rotuladas: mesma resposta, %d falhas rotuladas, mesmo SQL/TCriteria, %d de ida e volta no codec\n",
            count($world['specs']),
            $failures,
            $serialized
        ));
        self::assertGreaterThan(1000, $failures, 'The pool must produce failures to label');
    }

    /**
     * A refusal of the contract: a specification out of the format (D4), a class of the application that
     * is not registered, or a value without a serialized form (the value objects of the pool).
     */
    private static function isRefusalOfTheContract(NonSerializableSpecificationException $e): bool
    {
        $class = $e->specificationClass;
        if ($class === null) {
            return str_contains($e->getMessage(), AsbMoney::class);
        }
        if (str_contains($class, '\\Tests\\')) {
            return true;
        }
        foreach ([PredicateSpecification::class, ExternalDataSpecification::class, FeatureEnabledSpecification::class, InStateSpecification::class, EventOriginSpecification::class, PayloadHasSpecification::class, InQuerySpecification::class, RuleBoundSpecification::class, DocumentLeafSpecification::class, DocumentSetSpecification::class, InSubquerySpecification::class, ExistsSubquerySpecification::class] as $outOfContract) {
            if (is_a($class, $outOfContract, true)) {
                return true;
            }
        }

        return false;
    }

    private static function refusalGroup(NonSerializableSpecificationException $e): string
    {
        if ($e->specificationClass === null) {
            return 'valor AsbMoney';
        }
        $parts = explode('\\', $e->specificationClass);

        return (string) end($parts);
    }

    /**
     * @return array<string, mixed>
     */
    private static function sqlOf(ISpecification $spec, string $dialect): array
    {
        try {
            $where = Spec::toSql($spec, $dialect);
        } catch (Throwable $e) {
            return ['error' => $e::class];
        }
        $parameters = [];
        foreach ($where->getParameters() as $name => $value) {
            $parameters[$name] = $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s.u P') : $value;
        }

        return ['sql' => $where->toSql(), 'parameters' => $parameters];
    }

    /**
     * @return array<string, string>
     */
    private static function criteriaOf(ISpecification $spec): array
    {
        try {
            return ['dump' => Spec::toCriteria($spec)->dump()];
        } catch (Throwable $e) {
            return ['error' => $e::class];
        }
    }

    // ==========================================
    // The world: candidates, specifications and their answers
    // ==========================================

    /**
     * @return array{candidates: array<string, mixed>, specs: list<array{label: string, spec: ISpecification, family: string, composite: bool}>, truth: list<list<int>>}
     */
    private static function world(): array
    {
        if (self::$world !== null) {
            return self::$world;
        }

        $candidates = self::candidates();
        $leaves = self::leaves();
        $specs = [];
        foreach ($leaves as [$label, $spec, $family]) {
            $specs[] = ['label' => $label, 'spec' => $spec, 'family' => $family, 'composite' => false];
            $specs[] = ['label' => "not({$label})", 'spec' => new NotSpecification($spec), 'family' => $family, 'composite' => true];
        }
        foreach (self::composites($leaves) as [$label, $spec, $family]) {
            $specs[] = ['label' => $label, 'spec' => $spec, 'family' => $family, 'composite' => true];
        }

        $values = array_values($candidates);
        $truth = [];
        foreach ($specs as $entry) {
            $row = [];
            foreach ($values as $candidate) {
                $row[] = self::outcome($entry['spec'], $candidate);
            }
            $truth[] = $row;
        }

        return self::$world = ['candidates' => $candidates, 'specs' => $specs, 'truth' => $truth];
    }

    /**
     * Candidates exercising the boundaries: null first (index 0, outside the tautology and simplify
     * promises), scalars of every type with look-alikes ('1'/1/1.0/true), numeric and non-numeric text,
     * accents, date-times at the same instant in other objects, classes and zones, value objects alike
     * but distinct, entities of sibling and child classes, property holders, state-machine candidates,
     * document holders, iterables and method-call targets.
     *
     * @return array<string, mixed>
     */
    private static function candidates(): array
    {
        $utc = new DateTimeZone('UTC');
        $d = self::d();

        return [
            'null' => null,
            '0' => 0, '1' => 1, '2' => 2, '5' => 5, '6' => 6, '10' => 10, '-1' => -1,
            '11' => 11, '12' => 12, // 2.9.0: the integers between and around the bounds of the int-typed leaves
            '1.0' => 1.0, '4.5' => 4.5, '5.0' => 5.0, '5.5' => 5.5, '9.5' => 9.5, '10.5' => 10.5,
            '10.0' => 10.0, // 2.9.0 (forward 044): a float without a fractional part, refused by the int-typed leaves
            'NAN' => NAN, // 2.2.0 (#70): refused by the ordered leaves, equal to nothing for the equality ones
            "''" => '', "'1'" => '1', "'5'" => '5', "'10'" => '10', "'9'" => '9', "'1a'" => '1a', "'1e1'" => '1e1',
            "'a'" => 'a', "'b'" => 'b', "'ab'" => 'ab', "'A'" => 'A', "'abc'" => 'abc', "'ação'" => 'ação', "'acao'" => 'acao', "'AÇÃO'" => 'AÇÃO',
            "'2026-01-01'" => '2026-01-01', "'Gold'" => 'Gold',
            'true' => true, 'false' => false,
            '[]' => [], '[1]' => [1], '[1,2]' => [1, 2], '[5,6]' => [5, 6], "['a']" => ['a'],
            'd' => $d,
            'clone(d)' => new DateTimeImmutable('2026-01-01 01:00:00', $utc),
            'mutable(d)' => new DateTime('2026-01-01 01:00:00', $utc),
            'sp(d)' => $d->setTimezone(new DateTimeZone('America/Sao_Paulo')),
            'offset(d)' => new DateTime('2025-12-31 22:00:00', new DateTimeZone('-03:00')),
            'user(d)' => new AsbDate('2026-01-01 01:00:00', $utc),
            'd+1µs' => new DateTimeImmutable('2026-01-01 01:00:00.000001', $utc),
            'later' => self::later(),
            'earlier' => self::earlier(),
            'Money(1)' => self::m1(),
            'Money(1)#2' => new AsbMoney(1),
            "Money('1')" => new AsbMoney('1'),
            'Money(null)' => new AsbMoney(null),
            'Money(false)' => new AsbMoney(false),
            'stdClass' => new \stdClass(),
            'Customer#1' => new AsbCustomer(1, 'ana'),
            'Customer#1(inactive)' => new AsbCustomer(1, 'bob', false),
            "Customer#'1'" => new AsbCustomer('1', 'Ana'),
            'Customer#2' => new AsbCustomer(2, 'bia'),
            'Vip#1' => new AsbVip(1, 'ana'),
            'Vip#2(inactive)' => new AsbVip(2, 'carl', false),
            'Order#1' => new AsbOrder(1),
            'Order#2' => new AsbOrder(2, 'abc'),
            'Box(null)' => new AsbBox(null),
            'Box(1)' => new AsbBox(1),
            'Box(5)' => new AsbBox(5),
            'Box(10)' => new AsbBox(10),
            'Box(10.5)' => new AsbBox(10.5),
            "Box('a')" => new AsbBox('a'),
            "Box('A')" => new AsbBox('A'),
            "Box('')" => new AsbBox(''),
            "Box('1')" => new AsbBox('1'),
            'Box(true)' => new AsbBox(true),
            'Box(d)' => new AsbBox($d),
            'Box(sp(d))' => new AsbBox($d->setTimezone(new DateTimeZone('America/Sao_Paulo'))),
            'Box(later)' => new AsbBox(self::later()),
            'Box(Money(1))' => new AsbBox(self::m1()),
            'Box(Money(1)#2)' => new AsbBox(new AsbMoney(1)),
            'Transition(r=a, external, f)' => self::transition('a', EventOrigin::EXTERNAL, ['f' => 'v']),
            'Transition(r=b, internal)' => self::transition('b', EventOrigin::INTERNAL, []),
            'Documents(pf: rg)' => (object) ['tipo' => 'pf', 'documents' => ['rg']],
            'Documents(pj: rg, cnh)' => (object) ['tipo' => 'pj', 'documents' => ['rg', 'cnh']],
            'Documents(none)' => (object) ['tipo' => 'pf', 'documents' => []],
            'Clock' => new AsbClock(),
        ];
    }

    /**
     * Leaves of the pool: [label, specification, family]. The family groups the specifications whose
     * candidates overlap, so that composites and pairs are generated where they mean something.
     *
     * @return list<array{0: string, 1: ISpecification, 2: string, 3?: bool}>
     */
    private static function leaves(): array
    {
        $d = self::d();
        $sp = $d->setTimezone(new DateTimeZone('America/Sao_Paulo'));
        $mutable = new DateTime('2026-01-01 01:00:00', new DateTimeZone('UTC'));
        $m1 = self::m1();
        $factory = Spec::getFactory();
        $leaves = [];
        $add = static function (string $family, string $label, ISpecification $spec) use (&$leaves): void {
            $leaves[] = [$label, $spec, $family];
        };

        // Numbers
        foreach ([1, 5, 5.0, 0] as $v) {
            $add('number', 'equal(' . var_export($v, true) . ')', Spec::equal($v));
        }
        $add('number', 'notEqual(5)', Spec::notEqual(5));
        $add('number', 'notEqual(1)', Spec::notEqual(1));
        foreach ([5, 6, 5.5, 10] as $v) {
            $add('number', 'lessThan(' . var_export($v, true) . ')', Spec::lessThan($v));
            $add('number', 'greaterThan(' . var_export($v, true) . ')', Spec::greaterThan($v));
        }
        $add('number', 'lessThanOrEqualTo(5)', Spec::lessThanOrEqualTo(5));
        // Integer bounds with a fraction between them (forward 037 P2: no integer closing)
        $add('number', 'greaterThan(4)', Spec::greaterThan(4));
        $add('number', 'lessThan(11)', Spec::lessThan(11));
        $add('number', 'greaterThan(9)', Spec::greaterThan(9));
        $add('number', 'greaterThan(10.0)', Spec::greaterThan(10.0));
        $add('number', "labelled('N', lessThan(5))", Spec::labelled('N', Spec::lessThan(5)));
        $add('number', 'greaterThanOrEqualTo(5)', Spec::greaterThanOrEqualTo(5));
        $add('number', 'greaterThanOrEqualTo(6)', Spec::greaterThanOrEqualTo(6));
        $add('number', 'in(1, 2)', Spec::in(1, 2));
        $add('number', 'in(1)', Spec::in(1));
        $add('number', 'in(5, 6)', Spec::in(5, 6));
        $add('number', "in(1, '1')", Spec::in(1, '1'));
        $add('number', 'in(null, 1)', Spec::in(null, 1));
        $add('number', 'notIn(1, 2)', Spec::notIn(1, 2));
        $add('number', 'looselyEqualTo(1)', Spec::looselyEqualTo(1));
        $add('number', "looselyEqualTo('1')", Spec::looselyEqualTo('1'));
        $add('number', 'looselyEqualTo(0)', Spec::looselyEqualTo(0));
        $add('number', 'user even', new AsbEven());
        $add('number', 'user odd', new AsbEven(1));
        $add('number', 'user numericTextIn(1, 5)', new AsbNumericTextIn([1, 5]));
        $add('number', 'hasSize(equal(2))', $factory->collection()->hasSize(Spec::equal(2)));

        // Text
        $add('text', "equal('a')", Spec::equal('a'));
        $add('text', "equal('1')", Spec::equal('1'));
        $add('text', "equal('10')", Spec::equal('10'));
        $add('text', "notEqual('a')", Spec::notEqual('a'));
        $add('text', "lessThan('b')", Spec::lessThan('b'));
        $add('text', "greaterThan('a')", Spec::greaterThan('a'));
        $add('text', "greaterThan('9')", Spec::greaterThan('9'));
        $add('text', "greaterThan('10')", Spec::greaterThan('10'));
        $add('text', "lessThan('10')", Spec::lessThan('10'));
        $add('text', "lessThanOrEqualTo('1e1')", Spec::lessThanOrEqualTo('1e1'));
        $add('text', "in('a', 'b')", Spec::in('a', 'b'));
        $add('text', "looselyEqualTo('a')", Spec::looselyEqualTo('a'));
        $add('text', "contains('a')", Spec::contains('a'));
        $add('text', "contains('a', ignore case)", Spec::contains('a', false));
        $add('text', "contains('b')", Spec::contains('b'));
        $add('text', "startsWith('a')", Spec::startsWith('a'));
        $add('text', "startsWith('ab')", Spec::startsWith('ab'));
        $add('text', "endsWith('b')", Spec::endsWith('b'));
        $add('text', "regex('/^a/')", Spec::regex('/^a/'));
        $add('text', "regex('/a/')", new RegexSpecification('/a/'));
        $add('text', "wildcard('a*')", new WildcardSpecification('a*'));
        $add('text', "wildcardIgnoreCase('A*')", new WildcardExpressionMatcherIgnoreCaseStringSpecification('A*'));
        // 2.3.0 (forward 039): the comparison of wildcard() and regex()
        $add('text', "wildcard('A*', ignore case)", Spec::wildcard('A*', false));
        $add('text', "regex('/^A/', ignore case)", Spec::regex('/^A/', TextComparison::IgnoreCase));
        $add('text', "equalIgnoreCase('a')", Spec::equalIgnoreCase('a'));
        $add('text', "equalIgnoreCase('acao')", Spec::equalIgnoreCase('acao'));
        $add('text', "dateString('Y-m-d')", new DateStringSpecification('Y-m-d'));
        $add('text', 'enumName(AsbPlan)', new EnumNameStringSpecification(AsbPlan::class));
        $add('text', 'hasLength(equal(1))', Spec::hasLength(Spec::equal(1)));
        $add('text', "user caseInsensitiveEqual('a')", new AsbCaseInsensitiveEqual('a'));
        $add('text', 'user present', new AsbPresent());
        $add('text', 'user blank', new AsbBlank());
        if (extension_loaded('intl')) {
            $add('text', "contains('ação', ignore case and accents)", Spec::contains('ação', TextComparison::IgnoreCaseAndAccents));
            $add('text', "equalIgnoreCaseAndAccents('acao')", Spec::equalIgnoreCaseAndAccents('acao'));
            $add('text', "startsWith('a', ignore case and accents)", Spec::startsWith('a', TextComparison::IgnoreCaseAndAccents));
            // 2.3.0 (forward 039): wildcard and regex with the folding, opaque to the algebra
            $add('text', "wildcard('*ação*', ignore case and accents)", Spec::wildcard('*ação*', TextComparison::IgnoreCaseAndAccents));
            $add('text', "regex('/^ac[aã]o$/', ignore case and accents)", Spec::regex('/^ac[aã]o$/', TextComparison::IgnoreCaseAndAccents));
        }

        // Date-times
        $add('date', 'equal(d)', Spec::equal($d));
        $add('date', 'equal(sp(d))', Spec::equal($sp));
        $add('date', 'equal(mutable(d))', Spec::equal($mutable));
        $add('date', 'equal(later)', Spec::equal(self::later()));
        $add('date', 'notEqual(d)', Spec::notEqual($d));
        $add('date', 'notEqual(sp(d))', Spec::notEqual($sp));
        $add('date', 'at(d)', Spec::at($d));
        $add('date', 'at(sp(d))', Spec::at($sp));
        $add('date', 'before(d)', Spec::before($d));
        $add('date', 'after(d)', Spec::after($d));
        $add('date', 'beforeOrAt(d)', Spec::beforeOrAt($d));
        $add('date', 'afterOrAt(sp(d))', Spec::afterOrAt($sp));
        $add('date', 'before(later)', Spec::before(self::later()));
        $add('date', 'after(earlier)', Spec::after(self::earlier()));
        $add('date', 'between(earlier, d)', Spec::between(self::earlier(), $d));
        $add('date', 'in(d)', Spec::in($d));
        $add('date', 'in(sp(d), later)', Spec::in($sp, self::later()));
        $add('date', 'in(later)', Spec::in(self::later()));
        $add('date', 'looselyEqualTo(d)', Spec::looselyEqualTo($d));
        $add('date', 'isPast()', $factory->date()->isPast());
        $add('date', 'isFuture()', $factory->date()->isFuture());
        $add('date', 'isToday()', $factory->date()->isToday());
        $add('date', 'specify(DateTime)', Spec::specify(DateTime::class));
        $add('date', 'specify(DateTimeImmutable)', Spec::specify(DateTimeImmutable::class));

        // Value objects
        $add('object', 'equal(Money(1))', Spec::equal($m1));
        $add('object', 'equal(Money(1)#2)', Spec::equal(new AsbMoney(1)));
        $add('object', 'notEqual(Money(1))', Spec::notEqual($m1));
        $add('object', 'looselyEqualTo(Money(1))', Spec::looselyEqualTo($m1));
        $add('object', "looselyEqualTo(Money('1'))", Spec::looselyEqualTo(new AsbMoney('1')));
        $add('object', 'in(Money(1))', Spec::in($m1));
        $add('object', 'in(Money(1), Money(1)#2)', Spec::in($m1, new AsbMoney(1)));
        $add('object', 'specify(AsbMoney)', Spec::specify(AsbMoney::class));

        // Null and constants (every family)
        $add('any', 'isNull()', Spec::isNull());
        $add('any', 'notNull()', Spec::isNotNull());
        $add('any', 'equal(null)', Spec::equal(null));
        $add('any', 'notEqual(null)', Spec::notEqual(null));
        $add('any', 'looselyEqualTo(null)', Spec::looselyEqualTo(null));
        $add('any', 'alwaysTrue()', Spec::alwaysTrue());
        $add('any', 'alwaysFalse()', Spec::alwaysFalse());
        $add('any', 'in()', Spec::in());
        $add('any', 'defaultValue()', new DefaultValueSpecification());
        $add('any', "defaultValue('string')", new DefaultValueSpecification('string'));
        $add('any', "defaultValue('number')", new DefaultValueSpecification('number'));
        $add('any', "defaultValue('bool')", new DefaultValueSpecification('bool'));
        $add('any', 'predicate(is_int)', new PredicateSpecification(static fn(mixed $c): bool => is_int($c)));
        $add('any', 'predicate(is_string)', new PredicateSpecification(static fn(mixed $c): bool => is_string($c)));

        // Entities
        $add('entity', 'all()', new AllEntitiesSpecification());
        $add('entity', 'all(Customer)', new AllEntitiesSpecification(AsbCustomer::class));
        $add('entity', 'all(Vip)', new AllEntitiesSpecification(AsbVip::class));
        $add('entity', 'all(Order)', new AllEntitiesSpecification(AsbOrder::class));
        $add('entity', 'user activeCustomers(Customer)', new AsbActiveCustomers(AsbCustomer::class));
        $add('entity', 'unique(1)', new UniqueEntitySpecification(1));
        $add('entity', 'unique(1, Customer)', new UniqueEntitySpecification(1, AsbCustomer::class));
        $add('entity', "unique('1', Customer)", new UniqueEntitySpecification('1', AsbCustomer::class));
        $add('entity', 'unique(1, Vip)', new UniqueEntitySpecification(1, AsbVip::class));
        $add('entity', 'unique(1, Order)', new UniqueEntitySpecification(1, AsbOrder::class));
        $add('entity', 'unique(2, Customer)', new UniqueEntitySpecification(2, AsbCustomer::class));
        $add('entity', 'user activeUnique(1, Customer)', new AsbActiveUnique(1, AsbCustomer::class));
        $add('entity', 'specify(Customer)', Spec::specify(AsbCustomer::class));
        $add('entity', 'specify(Vip)', Spec::specify(AsbVip::class));
        $add('entity', 'specify(Order)', Spec::specify(AsbOrder::class));
        $add('entity', 'specify(IEntity)', Spec::specify(IEntity::class));
        $add('entity', "property(name, startsWith('a'))", Spec::property('name', Spec::startsWith('a')));
        $add('entity', "property(name, equal('ana'))", Spec::property('name', Spec::equal('ana')));
        $add('entity', "specify(Customer).where(name, contains('a'))", Spec::specify(AsbCustomer::class)->where('name', Spec::contains('a')));
        $add('entity', 'property(active, equal(true))', Spec::property('active', Spec::equal(true)));
        $add('entity', "calling(getEntityId, [], equal(1))", Spec::calling('getEntityId', [], Spec::equal(1)));
        $add('entity', 'a(all(Customer))', $factory->a(new AllEntitiesSpecification(AsbCustomer::class)));
        $add('entity', 'a(not(all(Order)))', $factory->a(new NotSpecification(new AllEntitiesSpecification(AsbOrder::class))));
        $add('entity', 'a(unique(1))', $factory->a(new UniqueEntitySpecification(1)));
        $add('entity', 'a(unique(1)).because(w)', $factory->a(new UniqueEntitySpecification(1))->because('w'));

        // Property holders
        foreach ([
            'equal(1)' => Spec::equal(1),
            'isNull()' => Spec::isNull(),
            'notNull()' => Spec::isNotNull(),
            'in(1, 5)' => Spec::in(1, 5),
            'notEqual(5)' => Spec::notEqual(5),
            'at(d)' => Spec::at($d),
            'equal(d)' => Spec::equal($d),
            'equal(Money(1))' => Spec::equal($m1),
            "defaultValue('string')" => new DefaultValueSpecification('string'),
            "defaultValue('number')" => new DefaultValueSpecification('number'),
            "equal('a')" => Spec::equal('a'),
            "user caseInsensitiveEqual('a')" => new AsbCaseInsensitiveEqual('a'),
            'user blank' => new AsbBlank(),
            'alwaysTrue()' => Spec::alwaysTrue(),
            'not(equal(1))' => Spec::not(Spec::equal(1)),
        ] as $label => $inner) {
            $add('box', "property(x, {$label})", Spec::property('x', $inner));
        }
        $add('box', 'external(asb.x, equal(1))', Spec::external('asb.x', ['x' => 'x'], Spec::equal(1)));
        $add('box', 'external(asb.x, equal(5))', Spec::external('asb.x', ['x' => 'x'], Spec::equal(5)));
        $add('box', 'ruleBound(property(x, equal(1)))', new RuleBoundSpecification(Spec::property('x', Spec::equal(1)), new RuleDefinition(code: 'R-1', name: 'One', ruleType: 'one', scope: 's')));
        $add('box', 'ruleBound(property(x, equal(5)))', new RuleBoundSpecification(Spec::property('x', Spec::equal(5)), new RuleDefinition(code: 'R-5', name: 'Five', ruleType: 'five', scope: 's')));
        $add('box', 'specify(AsbBox)', Spec::specify(AsbBox::class));
        // 2.5.0 (forward 048, L3): labelled targets, nested labels (the innermost wins) and an annotated label
        $add('box', "labelled('L', property(x, equal(1)))", Spec::property('x', Spec::equal(1))->labelled('L'));
        $add('box', "labelled('M', property(x, equal(1)))", Spec::property('x', Spec::equal(1))->labelled('M'));
        $add('box', "labelled('L', property(x, equal(5)))", Spec::property('x', Spec::equal(5))->labelled('L'));
        $add('box', "labelled('L', labelled('M', property(x, equal(1))))", Spec::labelled('L', Spec::property('x', Spec::equal(1))->labelled('M')));
        $add('box', "labelled('L', property(x, equal(1))).withCode(C1)", Spec::property('x', Spec::equal(1))->labelled('L')->withCode('C1'));
        // 2.5.0 (forward 048, L5): subqueries are evaluated by the database only (an error on every candidate)
        $add('box', "inSubquery(x in asb_rows.id where label startsWith 'a')", Spec::inSubquery('x', 'asb_rows', 'id', Spec::property('label', Spec::startsWith('a'))));
        $add('box', "inSubquery(x in asb_rows.id where label startsWith 'b')", Spec::inSubquery('x', 'asb_rows', 'id', Spec::property('label', Spec::startsWith('b'))));
        $add('box', "existsIn(asb_rows.id = asb_box.x where label startsWith 'a')", Spec::existsIn('asb_rows', ['id' => 'x'], Spec::property('label', Spec::startsWith('a')), 'asb_box'));
        $add('box', "existsIn(asb_rows.id = asb_box.x where label startsWith 'b')", Spec::existsIn('asb_rows', ['id' => 'x'], Spec::property('label', Spec::startsWith('b')), 'asb_box'));

        // Iterables
        $add('iterable', 'all(equal(1))', new CollectionSpecification(Spec::equal(1)));
        $add('iterable', 'any(equal(1))', $factory->collection()->any(Spec::equal(1)));
        $add('iterable', 'none(equal(1))', $factory->collection()->none(Spec::equal(1)));
        $add('iterable', 'include(equal(1), greaterThan(4))', $factory->collection()->include(Spec::equal(1), Spec::greaterThan(4)));
        $add('iterable', 'includePercentageOf(greaterThan(40.0), equal(1))', $factory->collection()->includePercentageOf(Spec::greaterThan(40.0), Spec::equal(1)));
        $add('iterable', 'isEmpty()', Spec::isEmpty());

        // Method calls (date-time arguments by instant, forward 018 RN-04)
        $add('call', "calling(day, [d], equal('2026-01-01'))", Spec::calling('day', [$d], Spec::equal('2026-01-01')));
        $add('call', "calling(day, [sp(d)], equal('2026-01-01'))", Spec::calling('day', [$sp], Spec::equal('2026-01-01')));
        $add('call', "calling(day, [mutable(d)], equal('2025-12-31'))", Spec::calling('day', [$mutable], Spec::equal('2025-12-31')));
        $add('call', 'calling(isAfterNewYear, [d], isTrue)', Spec::calling('isAfterNewYear', [$d], Spec::isTrue()));
        $add('call', 'calling(isAfterNewYear, [earlier], isTrue)', Spec::calling('isAfterNewYear', [self::earlier()], Spec::isTrue()));
        $add('call', 'calling(total, [3], greaterThan(5))', Spec::calling('total', [3], Spec::greaterThan(5)));
        $add('call', 'calling(total, [3], greaterThan(10))', Spec::calling('total', [3], Spec::greaterThan(10)));
        $add('call', 'calling(total, [1], greaterThan(5))', new MethodParameterizedSpecification('total', [1], Spec::greaterThan(5)));

        // State machine guards
        $add('transition', 'inState(r.a)', new InStateSpecification('r', 'a'));
        $add('transition', 'inState(r.b)', new InStateSpecification('r', 'b'));
        $add('transition', 'eventOrigin(external)', new EventOriginSpecification(EventOrigin::EXTERNAL));
        $add('transition', 'eventOrigin(internal)', new EventOriginSpecification(EventOrigin::INTERNAL));
        $add('transition', 'payloadHas(f)', new PayloadHasSpecification('f'));

        // Documents (rule engine)
        $documents = new DocumentGroupSpecificationBuilder();
        $add('document', 'document(rg)', $documents->build([new DocumentRuleDefinition('g', 'rg', 's')]));
        $add('document', 'document(cnh)', $documents->build([new DocumentRuleDefinition('g', 'cnh', 's')]));
        $add('document', 'oneOfSet(rg, cnh)', $documents->build([
            new DocumentRuleDefinition('g', 'rg', 's', DocumentRequirementMode::ONE_OF_SET, 'id'),
            new DocumentRuleDefinition('g', 'cnh', 's', DocumentRequirementMode::ONE_OF_SET, 'id'),
        ]));
        $add('document', 'anyOf(rg, cnh)', $documents->build([
            new DocumentRuleDefinition('g', 'rg', 's', DocumentRequirementMode::ANY, 'id'),
            new DocumentRuleDefinition('g', 'cnh', 's', DocumentRequirementMode::ANY, 'id'),
        ]));

        // Feature flags and subqueries
        [$flags, $otherFlags] = self::featureFlags();
        $add('any', 'featureEnabled(on)', $flags->spec('asb_on'));
        $add('any', 'featureEnabled(off)', $flags->spec('asb_off'));
        $add('any', 'featureEnabled(on) of other flags', $otherFlags->spec('asb_on'));
        $rows = self::rowsRepository();
        $add('number', "inQuery(rows.id where label startsWith 'a')", new InQuerySpecification($rows, Spec::property('label', Spec::startsWith('a')), 'id'));
        $add('number', "inQuery(rows.id where label startsWith 'b')", new InQuerySpecification($rows, Spec::property('label', Spec::startsWith('b')), 'id'));

        // Int-typed leaves (2.9.0, forward 044): the six operators, the bounds two int-typed leaves close
        // (x > 10 is x >= 11, so int(x > 10) ⟂ int(x < 11)), the bounds at the end of the int range (left
        // open) and the factory forms. They are marked (4th element) so that the composites of 2.5.0 are
        // generated as before and theirs come from a seed of their own (composites()).
        $addInt = static function (string $label, ISpecification $spec) use (&$leaves): void {
            $leaves[] = [$label, $spec, 'number', true];
        };
        $addInt('int.greaterThan(10)', Spec::int()->greaterThan(10));
        $addInt('int.lessThan(11)', Spec::int()->lessThan(11));
        $addInt('int.greaterThanOrEqualTo(11)', Spec::int()->greaterThanOrEqualTo(11));
        $addInt('int.lessThanOrEqualTo(10)', Spec::int()->lessThanOrEqualTo(10));
        $addInt('int.greaterThan(4)', Spec::int()->greaterThan(4));
        $addInt('int.greaterThanOrEqualTo(5)', Spec::int()->greaterThanOrEqualTo(5));
        $addInt('int.lessThan(12)', Spec::int()->lessThan(12));
        $addInt('int.equalTo(10)', Spec::int()->equalTo(10));
        $addInt('int.equalTo(11)', Spec::int()->equalTo(11));
        $addInt('int.notEqualTo(10)', Spec::int()->notEqualTo(10));
        $addInt('int.notEqualTo(11)', Spec::int()->notEqualTo(11));
        $addInt('greaterThan(10)->asInteger()', Spec::greaterThan(10)->asInteger());
        $addInt('int.greaterThan(PHP_INT_MAX)', Spec::int()->greaterThan(PHP_INT_MAX));
        $addInt('int.lessThan(PHP_INT_MIN)', Spec::int()->lessThan(PHP_INT_MIN));
        $addInt('int.between(1, 12)', Spec::int()->between(1, 12));
        $addInt('int.between(11, 10)', Spec::int()->between(11, 10));
        $addInt('int.in(10, 11)', Spec::int()->in(10, 11));
        $addInt("labelled('M', int.lessThan(11))", Spec::labelled('M', Spec::int()->lessThan(11)));

        // Annotated variants (because()/withCode())
        $annotated = [];
        foreach ($leaves as $leaf) {
            [$label, $spec, $family] = $leaf;
            if (!$spec instanceof AbstractSpecification || !in_array($label, ['equal(1)', 'lessThan(5)', "contains('a')", 'in(1, 2)', 'isNull()', 'all(Customer)', 'unique(1)', 'equal(d)', 'at(d)', 'calling(total, [3], greaterThan(5))', "property(x, equal(1))", 'specify(Customer)', 'a(unique(1))', 'featureEnabled(on)', 'int.greaterThan(10)'], true)) {
                continue;
            }
            $integer = $leaf[3] ?? false;
            $annotated[] = ["{$label}.because(r1)", $spec->because('r1'), $family, $integer];
            $annotated[] = ["{$label}.because(r2)", $spec->because('r2'), $family, $integer];
            $annotated[] = ["{$label}.withCode(C1)", $spec->withCode('C1'), $family, $integer];
        }

        return array_merge($leaves, $annotated);
    }

    /**
     * Composites generated with a fixed seed inside each family (the 'any' leaves join every family).
     *
     * @param list<array{0: string, 1: ISpecification, 2: string, 3?: bool}> $leaves
     * @return list<array{0: string, 1: ISpecification, 2: string}>
     */
    private static function composites(array $leaves): array
    {
        $extended = self::extended();
        $byFamily = [];
        $integerLeaves = [];
        foreach ($leaves as $leaf) {
            if ($leaf[3] ?? false) {
                $integerLeaves[] = $leaf; // 2.9.0: below, with a seed of their own
                continue;
            }
            $byFamily[$leaf[2]][] = $leaf;
        }
        $any = $byFamily['any'] ?? [];

        mt_srand(self::SEED);
        $composites = [];
        foreach ($byFamily as $family => $members) {
            if ($family === 'any') {
                continue;
            }
            $pool = array_merge($members, $any);
            $count = $extended ? 120 : 24;
            for ($n = 0; $n < $count; $n++) {
                [$label, $spec] = self::randomTree($pool, $extended ? 4 : 3, $n);
                $composites[] = [$label, $spec, $family];
            }
        }

        // Property restrictions over generated trees of numbers and text (candidates: the boxes)
        foreach (['number', 'text', 'date'] as $family) {
            $pool = array_merge($byFamily[$family] ?? [], $any);
            for ($n = 0; $n < ($extended ? 40 : 10); $n++) {
                [$label, $spec] = self::randomTree($pool, 2, $n);
                $composites[] = ["property(x, {$label})", Spec::property('x', $spec), 'box'];
            }
        }

        // Typed composites (Spec::specify) combined through their own and()/or()
        $customer = Spec::specify(AsbCustomer::class);
        $composites[] = ["specify(Customer).where(name, startsWith('a')).andWhere(active, equal(true))", $customer->where('name', Spec::startsWith('a'))->andWhere('active', Spec::equal(true)), 'entity'];
        $composites[] = ["specify(Vip).where(name, equal('ana'))", Spec::specify(AsbVip::class)->where('name', Spec::equal('ana')), 'entity'];

        // 2.9.0 (forward 044): trees over the int-typed leaves mixed with the numeric ones (int × int closes
        // the bounds, int × mixed does not), and property restrictions over them, from a seed of their own so
        // that every composite above is the one of 2.5.0
        mt_srand(self::SEED + 44);
        $pool = array_merge($integerLeaves, $byFamily['number'] ?? [], $any);
        $integerOnly = array_merge($integerLeaves, $any);
        for ($n = 0; $n < ($extended ? 160 : 48); $n++) {
            [$label, $spec] = self::randomTree($n % 2 === 0 ? $integerOnly : $pool, $extended ? 4 : 3, $n);
            $composites[] = [$label, $spec, 'number'];
        }
        for ($n = 0; $n < ($extended ? 40 : 10); $n++) {
            [$label, $spec] = self::randomTree($integerOnly, 2, $n);
            $composites[] = ["property(x, {$label})", Spec::property('x', $spec), 'box'];
        }

        return $composites;
    }

    /**
     * @param list<array{0: string, 1: ISpecification, 2: string}> $pool
     * @return array{0: string, 1: ISpecification}
     */
    private static function randomTree(array $pool, int $depth, int $n): array
    {
        if ($depth === 0 || mt_rand(0, 4) === 0) {
            $leaf = $pool[mt_rand(0, count($pool) - 1)];

            return [$leaf[0], $leaf[1]];
        }

        $op = mt_rand(0, 7);
        [$l1, $s1] = self::randomTree($pool, $depth - 1, $n);
        if ($op === 0) {
            return ["not({$l1})", new NotSpecification($s1)];
        }
        if ($op === 1) {
            $reason = 'g' . mt_rand(1, 3);

            return mt_rand(0, 1) === 0 ? ["{$l1}.because({$reason})", self::annotate($s1, $reason, null)] : ["{$l1}.withCode({$reason})", self::annotate($s1, null, $reason)];
        }
        [$l2, $s2] = self::randomTree($pool, $depth - 1, $n);
        switch ($op) {
            case 2:
            case 3:
                return ["({$l1} AND {$l2})", new AndSpecification($s1, $s2)];
            case 4:
            case 5:
                return ["({$l1} OR {$l2})", new OrSpecification($s1, $s2)];
            case 6:
                return ["nor({$l1}, {$l2})", new JointDenialSpecification($s1, $s2)];
            default:
                try {
                    return mt_rand(0, 1) === 0 ? ["{$l1}->and({$l2})", $s1->and($s2)] : ["{$l1}->or({$l2})", $s1->or($s2)];
                } catch (\InvalidArgumentException) {
                    return ["({$l1} AND {$l2})", new AndSpecification($s1, $s2)];
                }
        }
    }

    private static function annotate(ISpecification $spec, ?string $reason, ?string $code): ISpecification
    {
        if (!$spec instanceof AbstractSpecification) {
            return $spec;
        }
        if ($reason !== null) {
            $spec = $spec->because($reason);
        }

        return $code !== null ? $spec->withCode($code) : $spec;
    }

    /**
     * Index pairs to check: every leaf (and its negation) against every other, and the composites
     * against every specification of the same family (every pair under ASPEC_SOUNDNESS_EXTENDED).
     *
     * @return \Generator<array{0: int, 1: int}>
     */
    private static function pairs(): \Generator
    {
        $specs = self::world()['specs'];
        $extended = self::extended();
        $count = count($specs);
        for ($i = 0; $i < $count; $i++) {
            for ($j = 0; $j < $count; $j++) {
                if ($i === $j) {
                    continue;
                }
                $a = $specs[$i];
                $b = $specs[$j];
                $sameFamily = $a['family'] === $b['family'] || $a['family'] === 'any' || $b['family'] === 'any';
                $bothLeaves = !$a['composite'] && !$b['composite'];
                if ($extended || $bothLeaves || $sameFamily) {
                    yield [$i, $j];
                }
            }
        }
    }

    // ==========================================
    // Evaluation helpers
    // ==========================================

    private static function outcome(ISpecification $spec, mixed $candidate): int
    {
        set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, 0, $severity);
        });
        try {
            return $spec->isSatisfiedBy($candidate) ? self::OUTCOME_TRUE : self::OUTCOME_FALSE;
        } catch (Throwable) {
            return self::OUTCOME_ERROR;
        } finally {
            restore_error_handler();
        }
    }

    /** @return list<int> */
    private static function truthOf(ISpecification $spec): array
    {
        $row = [];
        foreach (self::world()['candidates'] as $candidate) {
            $row[] = self::outcome($spec, $candidate);
        }

        return $row;
    }

    /** Runs an algebra query; an exception is "not proven". */
    private static function query(\Closure $query): bool
    {
        set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, 0, $severity);
        });
        try {
            return $query();
        } catch (Throwable) {
            return false;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Witness of a broken pair promise (the first candidate index), or null when the promise holds.
     * A promise broken only on the candidates of a pending decision is not reported (see
     * pendingDecision()).
     */
    private static function pairWitness(string $promise, int $i, int $j): ?int
    {
        $truth = self::world()['truth'];
        $k = self::witnessOn($promise, $truth[$i], $truth[$j], []);
        if ($k === null) {
            return null;
        }
        $pending = self::pendingDecision($promise, $i, $j);
        if ($pending === null) {
            return $k;
        }

        return self::witnessOn($promise, $truth[$i], $truth[$j], $pending);
    }

    /**
     * @param list<int> $a
     * @param list<int> $b
     * @param list<int> $skip Candidate indexes left out
     */
    private static function witnessOn(string $promise, array $a, array $b, array $skip): ?int
    {
        foreach ($a as $k => $outcome) {
            if (in_array($k, $skip, true)) {
                continue;
            }
            $broken = match ($promise) {
                'equals' => $outcome !== self::OUTCOME_ERROR && $b[$k] !== self::OUTCOME_ERROR && $outcome !== $b[$k],
                'isGeneralizationOf' => $b[$k] === self::OUTCOME_TRUE && $outcome === self::OUTCOME_FALSE,
                'isSpecialCaseOf' => $outcome === self::OUTCOME_TRUE && $b[$k] === self::OUTCOME_FALSE,
                'isDisjointWith' => $outcome === self::OUTCOME_TRUE && $b[$k] === self::OUTCOME_TRUE,
            };
            if ($broken) {
                return $k;
            }
        }

        return null;
    }

    /**
     * Candidates on which a known violation waits for a decision of the maintainer, because an old test
     * asserts the promise (forward 037, D6: the test is not changed in silence). Null when the pair is not
     * concerned. When a decision is pending, AlgebraSoundnessCasesTest must watch it, so that the
     * exception leaves the bench the day the algebra changes.
     *
     * Nothing is pending since 2.2.0: P1 (BUG-20261013-JPQT, #68: a typed default value refuses null, so
     * the typed defaults the algebra declares disjoint share no candidate) and P4 (BUG-20261013-XEIB, #70:
     * NAN is refused by the ordered leaves and is in the pool) were decided; their reproductions live in
     * AlgebraSoundnessCasesTest.
     *
     * @return list<int>|null
     */
    private static function pendingDecision(string $promise, int $i, int $j): ?array
    {
        return null;
    }

    /**
     * First candidate both rows answer (true/false) with different answers.
     *
     * @param list<int> $a
     * @param list<int> $b
     */
    private static function firstDisagreement(array $a, array $b): ?int
    {
        foreach ($a as $k => $outcome) {
            if ($outcome !== self::OUTCOME_ERROR && $b[$k] !== self::OUTCOME_ERROR && $outcome !== $b[$k]) {
                return $k;
            }
        }

        return null;
    }

    /**
     * First candidate accepted by $premise and refused by $conclusion.
     *
     * @param list<int> $premise
     * @param list<int> $conclusion
     */
    private static function firstImplicationFailure(array $premise, array $conclusion): ?int
    {
        foreach ($premise as $k => $outcome) {
            if ($outcome === self::OUTCOME_TRUE && $conclusion[$k] === self::OUTCOME_FALSE) {
                return $k;
            }
        }

        return null;
    }

    /**
     * First candidate on which two specifications report different annotated failures.
     *
     * @param list<int> $truthA
     * @param list<int> $truthB
     */
    private static function firstAnnotationDifference(ISpecification $a, ISpecification $b, array $truthA, array $truthB): ?int
    {
        $vocabulary = self::annotationVocabulary($a) + self::annotationVocabulary($b);
        if ($vocabulary === []) {
            return null;
        }
        foreach (array_values(self::world()['candidates']) as $k => $candidate) {
            if ($truthA[$k] !== self::OUTCOME_FALSE || $truthB[$k] !== self::OUTCOME_FALSE) {
                continue;
            }
            $mine = self::annotatedFailures($a, $candidate, $vocabulary);
            $theirs = self::annotatedFailures($b, $candidate, $vocabulary);
            if ($mine !== null && $theirs !== null && $mine !== $theirs) {
                return $k;
            }
        }

        return null;
    }

    /**
     * Messages and codes of the failures of evaluate() that are annotations of the given vocabulary, as
     * a sorted set (the order of the operands may change).
     *
     * @param array<string, true> $vocabulary
     * @return list<string>|null
     */
    private static function annotatedFailures(ISpecification $spec, mixed $candidate, array $vocabulary): ?array
    {
        set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, 0, $severity);
        });
        try {
            $result = $spec->evaluate($candidate);
        } catch (Throwable) {
            return null;
        } finally {
            restore_error_handler();
        }
        if ($result->isError) {
            // evaluate() does not short-circuit: an error there is outside the truth-value guarantee
            return null;
        }
        $found = [];
        foreach ($result->failures as $failure) {
            if (isset($vocabulary['m:' . $failure->message])) {
                $found['m:' . $failure->message] = true;
            }
            if ($failure->code !== null && isset($vocabulary['c:' . $failure->code])) {
                $found['c:' . $failure->code] = true;
            }
            $label = $failure->metadata[LabelledSpecification::METADATA_LABEL] ?? null;
            if (is_string($label) && isset($vocabulary['l:' . $label])) {
                $found['l:' . $label . '|' . (string) $failure->property] = true;
            }
        }
        ksort($found);

        // A set: two equal nodes with the same annotation may be merged (forward 025 D3), so the same
        // annotated failure may be reported once instead of twice
        return array_keys($found);
    }

    /**
     * Every because() reason and withCode() code held by a node of the tree.
     *
     * @return array<string, true>
     */
    private static function annotationVocabulary(ISpecification $spec): array
    {
        static $reason = null;
        static $code = null;
        $reason ??= new ReflectionProperty(AbstractSpecification::class, 'customReason');
        $code ??= new ReflectionProperty(AbstractSpecification::class, 'customCode');

        $vocabulary = [];
        $seen = [];
        $walk = static function (ISpecification $node) use (&$walk, &$vocabulary, &$seen, $reason, $code): void {
            $id = spl_object_id($node);
            if (isset($seen[$id])) {
                return;
            }
            $seen[$id] = true;
            if ($node instanceof LabelledSpecification) {
                $vocabulary['l:' . $node->getLabel()] = true;
            }
            if ($node instanceof AbstractSpecification) {
                if (($r = $reason->getValue($node)) !== null) {
                    $vocabulary['m:' . $r] = true;
                }
                if (($c = $code->getValue($node)) !== null) {
                    $vocabulary['c:' . $c] = true;
                }
            }
            foreach (self::childSpecifications($node) as $child) {
                $walk($child);
            }
        };
        $walk($spec);

        return $vocabulary;
    }

    /**
     * Specifications held by the properties of a specification (whole class chain, arrays included).
     *
     * @return list<ISpecification>
     */
    private static function childSpecifications(ISpecification $node): array
    {
        $children = [];
        for ($level = new ReflectionClass($node); $level !== false; $level = $level->getParentClass()) {
            foreach ($level->getProperties() as $property) {
                if ($property->isStatic() || $property->getDeclaringClass()->getName() !== $level->getName() || !$property->isInitialized($node)) {
                    continue;
                }
                $value = $property->getValue($node);
                if ($value instanceof \SplObjectStorage) {
                    $value = iterator_to_array($value, false);
                }
                foreach (is_array($value) ? $value : [$value] as $item) {
                    if ($item instanceof ISpecification && $item !== $node) {
                        $children[] = $item;
                    }
                }
            }
        }

        return $children;
    }

    /** @param array<class-string, true> $covered */
    private static function collectClasses(ISpecification $spec, array &$covered): void
    {
        if (isset($covered[$spec::class]) && !$spec instanceof ICompositeSpecification) {
            return;
        }
        $covered[$spec::class] = true;
        foreach (self::childSpecifications($spec) as $child) {
            self::collectClasses($child, $covered);
        }
    }

    /**
     * Classes that cannot be built in this PHP (the accent-insensitive comparison needs ext-intl; the
     * leaves are in the pool when it is loaded).
     *
     * @return list<class-string>
     */
    /**
     * The classes of the int-typed relational leaves inside a specification (2.9.0).
     *
     * @param array<class-string, true> $found
     */
    private static function collectIntegerLeaves(ISpecification $spec, array &$found): void
    {
        if ($spec instanceof \Antevemus\ASpecification\Specifications\Comparison\AbstractComparableValueBoundSpecification && $spec->isInteger()) {
            $found[$spec::class] = true;
        }
        foreach (self::childSpecifications($spec) as $child) {
            self::collectIntegerLeaves($child, $found);
        }
    }

    private static function unavailableClasses(): array
    {
        return extension_loaded('intl') ? [] : [\Antevemus\ASpecification\Specifications\String\EqualIgnoreCaseAndAccentsStringSpecification::class];
    }

    /**
     * Concrete, named specification classes of src/.
     *
     * @return list<class-string>
     */
    private static function libraryClasses(): array
    {
        static $classes = null;
        if ($classes !== null) {
            return $classes;
        }
        $src = dirname(__DIR__, 2) . '/src';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            if (preg_match('/^namespace\s+([^;]+);/m', $code, $ns) !== 1
                || preg_match_all('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $code, $found) < 1) {
                continue;
            }
            foreach ($found[1] as $short) {
                try {
                    class_exists($ns[1] . '\\' . $short);
                } catch (Throwable) {
                    // optional integrations that cannot load here are not specifications
                }
            }
        }
        $classes = [];
        foreach (get_declared_classes() as $class) {
            $reflection = new ReflectionClass($class);
            if (!str_starts_with($class, 'Antevemus\\ASpecification\\') || str_contains($class, '\\Tests\\')
                || $reflection->isAbstract() || $reflection->isAnonymous() || !$reflection->implementsInterface(ISpecification::class)) {
                continue;
            }
            $classes[] = $class;
        }
        sort($classes);

        return $classes;
    }

    // ==========================================
    // Reporting
    // ==========================================

    /**
     * @param array{label: string, spec: ISpecification, family: string, composite: bool} $a
     * @param array{label: string, spec: ISpecification, family: string, composite: bool}|null $b
     * @return array<string, string>
     */
    private static function violation(string $promise, array $a, ?array $b, int $candidateIndex, string $detail): array
    {
        $labels = array_keys(self::world()['candidates']);

        return [
            'promise' => $promise,
            'A' => $a['label'],
            'B' => $b['label'] ?? '',
            'candidate' => $labels[$candidateIndex] ?? (string) $candidateIndex,
            'detail' => $detail,
            'group' => $promise . ' | ' . self::shape($a['spec']) . ($b !== null ? ' × ' . self::shape($b['spec']) : ''),
        ];
    }

    /** @param list<array<string, string>> $violations */
    private static function assertNoViolations(array $violations): void
    {
        $dump = getenv('ASPEC_SOUNDNESS_DUMP');
        if (is_string($dump) && $dump !== '' && $violations !== []) {
            $lines = '';
            foreach ($violations as $violation) {
                $lines .= json_encode($violation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            }
            file_put_contents($dump, $lines, FILE_APPEND);
        }

        $groups = [];
        foreach ($violations as $violation) {
            $groups[$violation['group']] ??= $violation;
        }
        $message = sprintf("%d violation(s) in %d group(s):\n", count($violations), count($groups));
        foreach (array_slice($groups, 0, 40) as $group => $example) {
            $message .= sprintf("- %s\n    A = %s\n    B = %s\n    candidate %s: %s\n", $group, $example['A'], $example['B'], $example['candidate'], $example['detail']);
        }

        self::assertSame([], $violations, $message);
    }

    /** Short structural description: the classes of the tree, without values. */
    private static function shape(ISpecification $spec): string
    {
        $reflection = new ReflectionClass($spec);
        $name = $reflection->isAnonymous() ? 'anonymous(' . ($reflection->getParentClass() ? $reflection->getParentClass()->getShortName() : '') . ')' : $reflection->getShortName();
        if (in_array($spec::class, [AndSpecification::class, OrSpecification::class, NotSpecification::class, JointDenialSpecification::class, PropertySpecification::class], true)) {
            return $name;
        }

        return $name;
    }

    private static function describe(ISpecification $spec): string
    {
        try {
            return (string) $spec;
        } catch (Throwable) {
            return $spec::class;
        }
    }

    private static function show(int $outcome): string
    {
        return [self::OUTCOME_FALSE => 'false', self::OUTCOME_TRUE => 'true', self::OUTCOME_ERROR => 'error'][$outcome];
    }

    private static function extended(): bool
    {
        return in_array(getenv('ASPEC_SOUNDNESS_EXTENDED'), ['1', 'true', 'yes'], true);
    }

    // ==========================================
    // Fixtures
    // ==========================================

    private static function d(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-01 01:00:00', new DateTimeZone('UTC'));
    }

    private static function later(): DateTimeImmutable
    {
        static $later = null;

        return $later ??= new DateTimeImmutable('2026-03-01 00:00:00', new DateTimeZone('UTC'));
    }

    private static function earlier(): DateTimeImmutable
    {
        static $earlier = null;

        return $earlier ??= new DateTimeImmutable('2025-06-01 00:00:00', new DateTimeZone('UTC'));
    }

    private static function m1(): AsbMoney
    {
        static $m1 = null;

        return $m1 ??= new AsbMoney(1);
    }

    /** @param array<string, mixed> $payload */
    private static function transition(string $state, EventOrigin $origin, array $payload): TransitionCandidate
    {
        return new TransitionCandidate(
            null,
            new Event('go', new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC')), $payload, null, $origin),
            new MachineSnapshot('m', 1, ['r' => $state])
        );
    }

    /** @return array{0: FeatureFlags, 1: FeatureFlags} */
    private static function featureFlags(): array
    {
        $make = static function (): FeatureFlags {
            $store = new InMemoryFeatureFlagStore();
            $store->put(new FeatureFlagState('asb_on', true));
            $store->put(new FeatureFlagState('asb_off', false));

            return new FeatureFlags(new FeatureFlagRegistry([new FeatureFlagDefinition('asb_on', 'On'), new FeatureFlagDefinition('asb_off', 'Off')]), $store);
        };

        if (!self::$registrySwapped) {
            self::$previousRegistry = DataSourceRegistry::default();
            $registry = new DataSourceRegistry();
            $registry->register('asb.x', new CallableDataSource(static fn(array $parameters): mixed => $parameters['x'] ?? null));
            DataSourceRegistry::setDefault($registry);
            self::$registrySwapped = true;
        }

        return [$make(), $make()];
    }

    private static function rowsRepository(): PdoRepository
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE asb_row (id INTEGER PRIMARY KEY, label VARCHAR(20) NOT NULL)');
        $pdo->exec("INSERT INTO asb_row (id, label) VALUES (1, 'alpha'), (2, 'beta'), (5, 'about'), (6, 'bravo')");

        return new PdoRepository($pdo, EntityMapping::for(AsbRow::class)->table('asb_row')->id('id')->column('label', type: ColumnType::String));
    }
}
