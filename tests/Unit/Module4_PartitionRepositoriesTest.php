<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\Tests\TestCase;
use Antevemus\ASpecification\Repositories\PartitionRepository;
use Antevemus\ASpecification\Repositories\InMemoryRepository;
use Antevemus\ASpecification\Specifications\Collection\UniqueEntitySpecification;
use Antevemus\ASpecification\Specifications\Collection\AllEntitiesSpecification;
use Antevemus\ASpecification\Entities\AbstractUUIDEntity;
use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;

/** Entidade com uma etiqueta, para as partições do BUG-20261007-C5YG. */
class M4TaggedEntity extends AbstractUUIDEntity
{
    public function __construct(public string $tag)
    {
        parent::__construct();
    }
}

/** Folha parametrizada SEM equals() próprio: herda a igualdade estrutural da base. */
class M4TagIs extends AbstractSpecification
{
    public function __construct(private string $tag)
    {
    }

    public function getType(): string
    {
        return M4TaggedEntity::class;
    }

    public function isSatisfiedBy(?object $candidate): bool
    {
        return $candidate instanceof M4TaggedEntity && $candidate->tag === $this->tag;
    }
}

class Module4_PartitionRepositoriesTest extends TestCase
{
    public function run(): void
    {
        $this->testSiblingLeavesOfSameClassAreNotEquivalent();
        $this->testEquivalentLeafReplacesPartitionWithoutLosingEntities();
        $this->testCollectPartitionsAcceptsLeafWithoutCustomEquals();

        $u1 = new class extends AbstractUUIDEntity {};
        $memRepo = new InMemoryRepository();
        $adminSpec = new UniqueEntitySpecification($u1);

        $partitionRoot = PartitionRepository::create($memRepo);
        $adminPartition = $partitionRoot->makePartition($adminSpec);

        $this->assertTrue($partitionRoot instanceof PartitionRepository);
        $this->assertEquals($adminSpec, $adminPartition->getSpecification());

        // Roteamento: entidade colocada na raiz é roteada para a partição que satisfaz adminSpec
        $partitionRoot->put($u1);
        $this->assertEquals(1, $adminPartition->count(new AllEntitiesSpecification()));
        $this->assertEquals(1, $partitionRoot->count(new AllEntitiesSpecification()));
    }

    /**
     * BUG-20261007-C5YG (reprodução): duas folhas da mesma classe com parâmetros diferentes
     * NÃO são equivalentes. Antes da correção TagIs('a') "equivalia" a TagIs('b') (mesma classe,
     * mesma string), a segunda partição substituía a primeira e as entidades ficavam órfãs.
     */
    private function testSiblingLeavesOfSameClassAreNotEquivalent(): void
    {
        $root = PartitionRepository::create(new InMemoryRepository());
        $all = new AllEntitiesSpecification();

        $this->assertFalse((new M4TagIs('a'))->equals(new M4TagIs('b')), 'TagIs(a) não pode ser igual a TagIs(b)');
        $this->assertTrue((new M4TagIs('a'))->equals(new M4TagIs('a')), 'TagIs(a) tem de ser igual a TagIs(a)');

        $partA = $root->addPartition(new M4TagIs('a'));
        $partA->put(new M4TaggedEntity('a'));
        $partA->put(new M4TaggedEntity('a'));
        $this->assertEquals(2, $root->count($all));

        $partB = $root->addPartition(new M4TagIs('b'));
        $partB->put(new M4TaggedEntity('b'));

        $this->assertEquals(3, $root->count($all), 'addPartition(TagIs b) orfanou as entidades de TagIs a');
        $this->assertCount(2, $root->getDirectPartitions(), 'TagIs(a) e TagIs(b) devem ser partições irmãs');
        $this->assertEquals(2, $partA->count($all));
        $this->assertEquals(1, $partB->count($all));
    }

    /**
     * BUG-20261007-C5YG (regressão): equivalência real continua substituindo (RN-02 (a)) sem perder entidades.
     */
    private function testEquivalentLeafReplacesPartitionWithoutLosingEntities(): void
    {
        $root = PartitionRepository::create(new InMemoryRepository());
        $all = new AllEntitiesSpecification();

        $first = $root->addPartition(new M4TagIs('a'));
        $first->put(new M4TaggedEntity('a'));
        $first->put(new M4TaggedEntity('a'));

        $second = $root->addPartition(new M4TagIs('a'));

        $this->assertTrue($second !== $first, 'Equivalente substitui a partição (RN-02 (a))');
        $this->assertCount(1, $root->getDirectPartitions());
        $this->assertEquals(2, $root->count($all), 'Entidades da partição substituída migram para a nova');
        $this->assertEquals(2, $second->count($all));
        $this->assertTrue($root->findPartition(new M4TagIs('a')) === $second);
    }

    /**
     * BUG-20261007-C5YG (regressão): collectPartitions() chamava equals() sem method_exists e
     * lançava Error para folhas sem o trait. Agora equals() faz parte do contrato ISpecification.
     */
    private function testCollectPartitionsAcceptsLeafWithoutCustomEquals(): void
    {
        $root = PartitionRepository::create(new InMemoryRepository());
        $root->addPartition(new M4TagIs('a'));
        $root->addPartition(new M4TagIs('b'));

        $anonymous = new class extends AbstractSpecification {
            public function getType(): string
            {
                return M4TaggedEntity::class;
            }

            public function isSatisfiedBy(?object $candidate): bool
            {
                return $candidate instanceof M4TaggedEntity;
            }
        };
        $root->addPartition($anonymous);

        $this->assertTrue($anonymous instanceof ISpecification);
        $this->assertCount(1, $root->collectPartitions(new M4TagIs('a')));
        $this->assertCount(1, $root->collectPartitions($anonymous));
    }
}
