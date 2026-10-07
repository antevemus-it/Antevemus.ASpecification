<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\Tests\TestCase;
use Antevemus\ASpecification\Specifications\SpecificationPredicate;
use Antevemus\ASpecification\Specifications\Comparison\GreaterThanSpecification;
use Antevemus\ASpecification\Factory\SpecificationFactory;
use Antevemus\ASpecification\Helpers\SpecificationHelper;
use Antevemus\ASpecification\Contracts\ISpecificationVisitor;
use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Entities\AbstractEntity;
use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Linq\ALinqSpecificationVisitor;
use Antevemus\ASpecification\Specifications\Logical\AlwaysTrueSpecification;
use Antevemus\ASpecification\Specifications\Logical\JointDenialSpecification;
use Antevemus\ASpecification\Specifications\NotSpecification;
use Antevemus\ASpecification\Spec;
use function Antevemus\ASpecification\DSL\nor;
use function Antevemus\ASpecification\DSL\noneOf;
use DateTimeImmutable;
use stdClass;
use RuntimeException;

enum DummyStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
}

class Module7_PredicatesAndFactoriesTest extends TestCase
{
    public function run(): void
    {
        // 1. SpecificationPredicate
        $gt1 = new GreaterThanSpecification(1);
        $arr = [0, 1, 2, 3];
        $filtered = array_values(array_filter($arr, SpecificationPredicate::from($gt1)));
        $this->assertEquals([2, 3], $filtered);

        $negated = array_values(array_filter($arr, SpecificationPredicate::negate($gt1)));
        $this->assertEquals([0, 1], $negated);

        $predObj = new SpecificationPredicate($gt1);
        $this->assertEquals([2, 3], array_values(array_filter($arr, $predObj)));
        $this->assertEquals([0, 1], array_values(array_filter($arr, $predObj->inverted())));

        // 2. SpecificationFactory
        $factory = new SpecificationFactory();
        $this->assertTrue($factory->isNull()->isSatisfiedBy(null));
        $this->assertTrue($factory->equalTo(5)->isSatisfiedBy(5));
        $this->assertTrue($factory->string()->contains("hello")->isSatisfiedBy("say hello world"));
        $this->assertTrue($factory->date()->isToday()->isSatisfiedBy(new DateTimeImmutable()));
        $this->assertTrue($factory->collection()->all($factory->greaterThan(0))->isSatisfiedBy([1, 2, 3]));
        $this->assertTrue($factory->type()->the(DateTimeImmutable::class)->isSatisfiedBy(new DateTimeImmutable()));

        // Testes de defaultValue e enumCase via Factory e Spec Facade
        $defaultSpec = Spec::defaultValue();
        $this->assertTrue($defaultSpec->isSatisfiedBy(null));
        $this->assertTrue($defaultSpec->isSatisfiedBy(""));
        $this->assertTrue($defaultSpec->isSatisfiedBy("   "));
        $this->assertTrue($defaultSpec->isSatisfiedBy(0));
        $this->assertTrue($defaultSpec->isSatisfiedBy(false));
        $this->assertTrue($defaultSpec->isSatisfiedBy([]));
        $this->assertFalse($defaultSpec->isSatisfiedBy("filled"));
        $this->assertFalse($defaultSpec->isSatisfiedBy(42));
        $this->assertFalse($defaultSpec->isSatisfiedBy(true));

        $enumSpec = Spec::enumCase(DummyStatus::class);
        $this->assertTrue($enumSpec->isSatisfiedBy("ACTIVE"));
        $this->assertTrue($enumSpec->isSatisfiedBy("INACTIVE"));
        $this->assertFalse($enumSpec->isSatisfiedBy("PENDING"));
        $this->assertFalse($enumSpec->isSatisfiedBy(999));
        $this->assertTrue($factory->enumCase(DummyStatus::class)->isSatisfiedBy("ACTIVE"));
        $this->assertTrue($factory->defaultValue()->isSatisfiedBy(null));

        // 3. SpecificationHelper
        $helper = new SpecificationHelper();
        $dateSpec = new class extends AbstractSpecification {
            public function isSatisfiedBy(mixed $c): bool { return true; }
            public function getType(): string { return DateTimeImmutable::class; }
        };
        $this->assertFalse($helper->typeSafeIsSatisfiedBy($dateSpec, new stdClass()));
        $this->assertTrue($helper->typeSafeIsSatisfiedBy($dateSpec, new DateTimeImmutable()));
        $this->assertFalse($helper->typeSafeIsSatisfiedBy($dateSpec, null));

        // 3-tier identity extraction
        $entity = new class("id-999") extends AbstractEntity {
            public function __construct(private string $id) { parent::__construct(); }
            public function getEntityId(): mixed { return $this->id; }
        };
        $uniqueSpec = $helper->createUniqueSpecificationFor($entity);
        $this->assertEquals("id-999", $uniqueSpec->getExpectedId());

        $plainObj = new class { public function getId(): int { return 42; } };
        $uniqueSpec2 = $helper->createUniqueSpecificationFor($plainObj);
        $this->assertEquals("42", $uniqueSpec2->getExpectedId());

        $this->assertThrows(RuntimeException::class, function() use ($helper) {
            $helper->createUniqueSpecificationFor(new stdClass());
        });

        // 4. Visitor traversal
        $visitor = new class implements ISpecificationVisitor {
            public int $visitedLeaf = 0;
            public int $visitedComposite = 0;
            public function visitComposite(ICompositeSpecification $specification): mixed {
                $this->visitedComposite++;
                return "COMPOSITE";
            }
            public function visitLeaf(ISpecification $specification): mixed {
                $this->visitedLeaf++;
                return "LEAF";
            }
        };

        $this->assertEquals("LEAF", $visitor->visitLeaf($gt1));
        $this->assertEquals("COMPOSITE", $visitor->visitComposite($gt1->and(new GreaterThanSpecification(0))));
        $this->assertEquals(1, $visitor->visitedLeaf);
        $this->assertEquals(1, $visitor->visitedComposite);

        // 5. Forward 014 R8: NOR / Joint Denial exposto pela fábrica, pela facade e pela DSL
        $this->testNorIsExposedEverywhere($factory);
    }

    /**
     * Forward 014 RN-07: nor()/noneOf() = NOT (a OR b ...), via JointDenialSpecification,
     * traduzido pelos visitors SQL, TCriteria e ALinq.
     */
    private function testNorIsExposedEverywhere(SpecificationFactory $factory): void
    {
        $a = Spec::property('status', Spec::equalTo('A'));
        $b = Spec::property('status', Spec::equalTo('B'));
        $c = Spec::property('status', Spec::equalTo('C'));

        // aridade
        $this->assertInstanceOf(AlwaysTrueSpecification::class, Spec::nor());
        $this->assertInstanceOf(NotSpecification::class, Spec::nor($a));
        $this->assertInstanceOf(JointDenialSpecification::class, Spec::nor($a, $b));
        $this->assertInstanceOf(JointDenialSpecification::class, Spec::nor($a, $b, $c));
        $this->assertInstanceOf(JointDenialSpecification::class, Spec::noneOf($a, $b));
        $this->assertInstanceOf(JointDenialSpecification::class, $factory->nor($a, $b));
        $this->assertInstanceOf(JointDenialSpecification::class, $factory->noneOf($a, $b));
        $this->assertInstanceOf(JointDenialSpecification::class, nor($a, $b));
        $this->assertInstanceOf(JointDenialSpecification::class, noneOf($a, $b));

        // semântica: satisfeito só quando nenhum é satisfeito
        $none = Spec::nor($a, $b, $c);
        foreach (['A', 'B', 'C'] as $hit) {
            $this->assertFalse($none->isSatisfiedBy((object) ['status' => $hit]), "nor deve rejeitar {$hit}");
        }
        $this->assertTrue($none->isSatisfiedBy((object) ['status' => 'D']));
        $this->assertTrue(Spec::nor($a)->isSatisfiedBy((object) ['status' => 'B']));
        $this->assertFalse(Spec::nor($a)->isSatisfiedBy((object) ['status' => 'A']));
        $this->assertTrue(Spec::nor()->isSatisfiedBy((object) ['status' => 'A']));
        $this->assertFalse($none->evaluate((object) ['status' => 'A'])->isSatisfied);
        $this->assertTrue(str_contains((string) Spec::nor($a, $b), ' NOR '));

        // SQL: NOT (a OR b), valores por binding
        $sql = Spec::toSql(Spec::nor($a, $b), 'pgsql');
        $this->assertEquals('NOT (("status" = :p1 OR "status" = :p2))', $sql->toSql());
        $this->assertEquals([':p1' => 'A', ':p2' => 'B'], $sql->getParameters());
        $this->assertEquals('NOT (("status" = :p1 OR ("status" = :p2 OR "status" = :p3)))', Spec::toSql($none, 'pgsql')->toSql());

        // TCriteria (stubs do Adianti sob demanda): De Morgan, NOT a AND NOT b; negado volta a a OR b
        if (!class_exists(\Adianti\Database\TCriteria::class)) {
            foreach (glob(__DIR__ . '/../Stubs/Adianti/*.php') as $stub) {
                require_once $stub;
            }
        }
        $this->assertEquals("(status <> 'A' AND status <> 'B')", Spec::toCriteria(Spec::nor($a, $b))->dump());
        $this->assertEquals("(status <> 'A' AND (status <> 'B' AND status <> 'C'))", Spec::toCriteria($none)->dump());
        $this->assertEquals("(status = 'A' OR status = 'B')", Spec::toCriteria(Spec::not(Spec::nor($a, $b)))->dump());

        // ALinq
        $predicate = ALinqSpecificationVisitor::createPredicate($none);
        $this->assertFalse($predicate((object) ['status' => 'A']));
        $this->assertTrue($predicate((object) ['status' => 'D']));
    }
}
