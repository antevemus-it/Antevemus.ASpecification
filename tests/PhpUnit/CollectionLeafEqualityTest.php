<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\PhpUnit;

use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Entities\AbstractEntity;
use Antevemus\ASpecification\Repositories\InMemoryRepository;
use Antevemus\ASpecification\Repositories\PartitionRepository;
use Antevemus\ASpecification\Results\SpecificationResult;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\AndSpecification;
use Antevemus\ASpecification\Specifications\Collection\AllEntitiesSpecification;
use Antevemus\ASpecification\Specifications\Collection\UniqueEntitySpecification;
use Antevemus\ASpecification\Specifications\OrSpecification;
use PHPUnit\Framework\TestCase;

/** Entity with a chosen identifier and an "active" flag read by the subclasses below. */
class CleCustomer extends AbstractEntity
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

/** Subclass of the customer: a VIP customer is a customer. */
final class CleVipCustomer extends CleCustomer
{
}

/** Unrelated entity class. */
final class CleOrder extends AbstractEntity
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

/** A subclass that narrows the predicate: same state as the base, different answers. */
final class CleActiveUnique extends UniqueEntitySpecification
{
    public function isSatisfiedBy(?object $candidate): bool
    {
        return parent::isSatisfiedBy($candidate) && $candidate instanceof CleCustomer && $candidate->active;
    }
}

/** A subclass that only adds behaviour unrelated to the answer. */
final class CleNamedAllEntities extends AllEntitiesSpecification
{
    public function label(): string
    {
        return 'named';
    }
}

/**
 * CollectionLeafEqualityTest - Regression of BUG-20261013-7RQV (2.1.0).
 *
 * Up to 2.0.0 AllEntitiesSpecification::equals() and UniqueEntitySpecification::equals() ignored
 * because()/withCode(), accepted any subclass (`instanceof self`) and, for UniqueEntitySpecification,
 * treated IEntity::class as a wildcard inside equals(), which made the equality non-transitive
 * (`unique(1)` = `unique(1, Customer)` and = `unique(1, Order)`, but `Customer` ≠ `Order`). simplify(),
 * and()/or() of composites and the partitions then merged specifications that answer differently.
 * The wildcard now lives in the subsumption algebra, which is checked against the evaluation below.
 *
 * @version    2.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\PhpUnit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class CollectionLeafEqualityTest extends TestCase
{
    /** @return array<string, object> */
    private static function candidates(): array
    {
        return [
            'Customer#1' => new CleCustomer(1),
            'Customer#1(inactive)' => new CleCustomer(1, false),
            'Customer#"1"' => new CleCustomer('1'),
            'Vip#1' => new CleVipCustomer(1),
            'Order#1' => new CleOrder(1),
            'Customer#2' => new CleCustomer(2),
            'Order#2' => new CleOrder(2),
            'stdClass' => new \stdClass(),
        ];
    }

    /** Reproduction (bug.md, first two lines). */
    public function testAnnotationsTakePartInTheEquality(): void
    {
        self::assertFalse((new AllEntitiesSpecification())->withCode('X')->equals((new AllEntitiesSpecification())->withCode('Y')));
        self::assertFalse((new UniqueEntitySpecification(1))->because('a')->equals((new UniqueEntitySpecification(1))->because('b')));
        self::assertFalse((new AllEntitiesSpecification())->because('a')->equals(new AllEntitiesSpecification()));
        self::assertFalse((new UniqueEntitySpecification(1))->withCode('C')->equals(new UniqueEntitySpecification(1)));

        self::assertTrue((new AllEntitiesSpecification())->withCode('X')->equals((new AllEntitiesSpecification())->withCode('X')));
        self::assertTrue((new UniqueEntitySpecification(1))->because('a')->equals((new UniqueEntitySpecification(1))->because('a')));
        self::assertTrue((new AllEntitiesSpecification(CleCustomer::class))->equals(new AllEntitiesSpecification(CleCustomer::class)));
        self::assertFalse((new AllEntitiesSpecification(CleCustomer::class))->equals(new AllEntitiesSpecification(CleOrder::class)));
    }

    public function testTheSameConcreteClassIsRequired(): void
    {
        $base = new AllEntitiesSpecification(CleCustomer::class);
        $sub = new CleNamedAllEntities(CleCustomer::class);
        self::assertFalse($base->equals($sub));
        self::assertFalse($sub->equals($base));
        self::assertTrue($sub->equals(new CleNamedAllEntities(CleCustomer::class)));

        $unique = new UniqueEntitySpecification(1, CleCustomer::class);
        $active = new CleActiveUnique(1, CleCustomer::class);
        self::assertFalse($unique->equals($active));
        self::assertFalse($active->equals($unique));
        self::assertTrue($active->equals(new CleActiveUnique(1, CleCustomer::class)));
    }

    /** The identifier is compared as the evaluation reads it (its text); the reference entity is not read. */
    public function testUniqueEqualityFollowsTheEvaluation(): void
    {
        $customer = new CleCustomer(1);

        self::assertTrue((new UniqueEntitySpecification(1))->equals(new UniqueEntitySpecification('1')), 'the evaluation compares (string) forms');
        self::assertTrue((new UniqueEntitySpecification($customer))->equals(new UniqueEntitySpecification(1, CleCustomer::class)), 'the entity is never read');
        self::assertFalse((new UniqueEntitySpecification(1))->equals(new UniqueEntitySpecification(1, CleCustomer::class)), 'IEntity is no wildcard in equals()');
        self::assertFalse((new UniqueEntitySpecification(1))->equals(new UniqueEntitySpecification(1, CleOrder::class)));
        self::assertFalse((new UniqueEntitySpecification(1, CleCustomer::class))->equals(new UniqueEntitySpecification(2, CleCustomer::class)));
    }

    /** Equality is an equivalence relation, and equal specifications answer alike. */
    public function testEqualityIsTransitiveAndNeverLooserThanTheEvaluation(): void
    {
        $specs = [
            new UniqueEntitySpecification(1),
            new UniqueEntitySpecification('1'),
            new UniqueEntitySpecification(1, CleCustomer::class),
            new UniqueEntitySpecification(new CleCustomer(1)),
            new UniqueEntitySpecification(1, CleOrder::class),
            new UniqueEntitySpecification(1, CleVipCustomer::class),
            (new UniqueEntitySpecification(1))->because('a'),
            new CleActiveUnique(1, CleCustomer::class),
            new AllEntitiesSpecification(),
            new AllEntitiesSpecification(CleCustomer::class),
            new CleNamedAllEntities(CleCustomer::class),
            (new AllEntitiesSpecification())->withCode('X'),
        ];
        $candidates = self::candidates();

        foreach ($specs as $i => $a) {
            foreach ($specs as $j => $b) {
                self::assertSame($a->equals($b), $b->equals($a), "symmetry #$i/#$j");
                if (!$a->equals($b)) {
                    continue;
                }
                foreach ($candidates as $name => $candidate) {
                    self::assertSame(self::outcome($a, $candidate), self::outcome($b, $candidate), "#$i equals #$j but they differ on $name");
                }
                foreach ($specs as $k => $c) {
                    if ($b->equals($c)) {
                        self::assertTrue($a->equals($c), "transitivity #$i = #$j = #$k");
                    }
                }
            }
        }
    }

    /** The IEntity wildcard is a matter of subsumption, and every proof holds for the candidates. */
    public function testTheWildcardLivesInTheSubsumptionAndTheAlgebraIsSound(): void
    {
        $any = new UniqueEntitySpecification(1);
        $customer = new UniqueEntitySpecification(1, CleCustomer::class);
        $vip = new UniqueEntitySpecification(1, CleVipCustomer::class);
        $order = new UniqueEntitySpecification(1, CleOrder::class);

        self::assertTrue($any->isGeneralizationOf($customer));
        self::assertTrue($customer->isSpecialCaseOf($any));
        self::assertFalse($customer->isGeneralizationOf($any), 'unique(1, Customer) does not accept Order#1');
        self::assertTrue($customer->isGeneralizationOf($vip));
        self::assertFalse($vip->isGeneralizationOf($customer));
        self::assertTrue($any->because('a')->isGeneralizationOf($any), 'annotations do not change what is accepted');
        self::assertTrue($any->isGeneralizationOf($any->because('a')));
        self::assertTrue($customer->isDisjointWith($order));
        self::assertTrue($customer->isDisjointWith(new UniqueEntitySpecification(2, CleCustomer::class)));
        self::assertFalse($customer->isDisjointWith($vip), 'Vip#1 satisfies both');
        self::assertFalse($any->isDisjointWith($customer));
        self::assertFalse($any->because('a')->isDisjointWith($any));
        self::assertFalse(Spec::isContradiction(new AndSpecification($customer, $vip)), 'Vip#1 satisfies the conjunction');

        $pool = [
            $any, $customer, $vip, $order,
            new UniqueEntitySpecification('1', CleCustomer::class),
            new UniqueEntitySpecification(2),
            new UniqueEntitySpecification(2, CleCustomer::class),
            $customer->withCode('C'),
            new CleActiveUnique(1, CleCustomer::class),
            new CleActiveUnique(1),
            new AllEntitiesSpecification(),
            new AllEntitiesSpecification(CleCustomer::class),
            new AllEntitiesSpecification(CleOrder::class),
        ];
        $candidates = self::candidates();
        foreach ($pool as $i => $a) {
            foreach ($pool as $j => $b) {
                if ($a->isGeneralizationOf($b)) {
                    foreach ($candidates as $name => $candidate) {
                        self::assertFalse($b->isSatisfiedBy($candidate) && !$a->isSatisfiedBy($candidate), "#$i ⊇ #$j is false for $name");
                    }
                }
                if ($a->isDisjointWith($b)) {
                    foreach ($candidates as $name => $candidate) {
                        self::assertFalse($a->isSatisfiedBy($candidate) && $b->isSatisfiedBy($candidate), "#$i ⟂ #$j is false for $name");
                    }
                }
            }
        }
    }

    public function testSimplifyKeepsTheAnswerAndTheAnnotatedFailures(): void
    {
        $any = new UniqueEntitySpecification(1);
        $customer = new UniqueEntitySpecification(1, CleCustomer::class);
        $vip = new UniqueEntitySpecification(1, CleVipCustomer::class);
        $active = new CleActiveUnique(1, CleCustomer::class);

        foreach ([
            'unique(1, Customer) OR unique(1)' => new OrSpecification($customer, $any),
            'unique(1) AND unique(1, Customer)' => new AndSpecification($any, $customer),
            'unique(1, Customer) AND unique(1, Vip)' => new AndSpecification($customer, $vip),
            'unique(1, Customer) AND active(1, Customer)' => new AndSpecification($customer, $active),
            'active(1, Customer) OR unique(1, Customer)' => new OrSpecification($active, $customer),
            'unique#A OR unique#B' => new OrSpecification($any->withCode('A'), $any->withCode('B')),
            'all#X AND all#Y' => new AndSpecification((new AllEntitiesSpecification())->withCode('X'), (new AllEntitiesSpecification())->withCode('Y')),
            'all#X OR unique(1)#Y' => new OrSpecification((new AllEntitiesSpecification(CleOrder::class))->withCode('X'), $any->withCode('Y')),
        ] as $label => $tree) {
            $simplified = Spec::simplify($tree);
            foreach (self::candidates() as $name => $candidate) {
                self::assertSame($tree->isSatisfiedBy($candidate), $simplified->isSatisfiedBy($candidate), "simplify($label) changed the answer for $name");
                self::assertSame(
                    self::annotatedFailures($tree->evaluate($candidate)),
                    self::annotatedFailures($simplified->evaluate($candidate)),
                    "simplify($label) changed the annotated failures for $name"
                );
            }
        }
    }

    /** and()/or() of composites drop the other operand only when it is equal, annotations included. */
    public function testCompositeAndOrKeepAnOperandThatDiffers(): void
    {
        $order = new CleOrder(1);

        $left = (new AllEntitiesSpecification(CleCustomer::class))->withCode('X')->and(new UniqueEntitySpecification(1, CleCustomer::class));
        $right = (new AllEntitiesSpecification(CleCustomer::class))->withCode('Y')->and(new UniqueEntitySpecification(1, CleCustomer::class));
        $both = $left->and($right);
        self::assertNotSame($left, $both, 'and() dropped the operand annotated Y');
        self::assertContains('Y', array_map(static fn($f) => $f->code, $both->evaluate($order)->failures));

        $any = (new UniqueEntitySpecification(1))->and(Spec::alwaysTrue());
        $customer = (new UniqueEntitySpecification(1, CleCustomer::class))->and(Spec::alwaysTrue());
        self::assertFalse($any->and($customer)->isSatisfiedBy($order), 'and() dropped unique(1, Customer)');
        self::assertTrue($customer->or($any)->isSatisfiedBy($order), 'or() dropped unique(1)');
    }

    public function testPartitionsOfTheWildcardAndOfAConcreteClassStayApart(): void
    {
        $root = PartitionRepository::create(new InMemoryRepository());
        $anyPartition = $root->addPartition(new UniqueEntitySpecification(1));
        $customerPartition = $root->addPartition(new UniqueEntitySpecification(1, CleCustomer::class));

        self::assertNotSame($anyPartition, $customerPartition);
        self::assertSame($anyPartition, $root->findPartition(new UniqueEntitySpecification(1)), 'unique(1, Customer) replaced the partition of unique(1)');
        self::assertSame($customerPartition, $root->findPartition(new UniqueEntitySpecification(1, CleCustomer::class)));
    }

    private static function outcome(ISpecification $specification, object $candidate): string
    {
        $result = $specification->evaluate($candidate);

        return json_encode([$result->isSatisfied, self::annotatedFailures($result), array_map(static fn($f) => $f->message, $result->failures)]);
    }

    /**
     * The failures whose message or code come from because()/withCode() (the only ones D3 of forward
     * 025 guarantees across simplify()).
     *
     * @return list<string>
     */
    private static function annotatedFailures(SpecificationResult $result): array
    {
        $annotated = [];
        foreach ($result->failures as $failure) {
            if ($failure->code !== null || !str_starts_with($failure->message, 'Candidate failed to satisfy rule')) {
                $annotated[] = ($failure->code ?? '-') . ':' . $failure->message;
            }
        }
        sort($annotated);

        return $annotated;
    }
}
