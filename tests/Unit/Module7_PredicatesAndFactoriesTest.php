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
use Antevemus\ASpecification\Spec;
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
    }
}
