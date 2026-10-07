<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\Tests\TestCase;
use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Repositories\IRepository;
use Antevemus\ASpecification\Repositories\AbstractRepository;
use Antevemus\ASpecification\Repositories\InMemoryRepository;
use Antevemus\ASpecification\Repositories\NullRepository;
use Antevemus\ASpecification\Repositories\PartitionRepository;
use Antevemus\ASpecification\Specifications\Collection\AllEntitiesSpecification;
use Antevemus\ASpecification\Specifications\Collection\UniqueEntitySpecification;
use Antevemus\ASpecification\Entities\AbstractUUIDEntity;

class Module3_InMemoryRepositoriesTest extends TestCase
{
    public function run(): void
    {
        $u1 = new class extends AbstractUUIDEntity {};
        $u2 = new class extends AbstractUUIDEntity {};

        $memRepo = new InMemoryRepository();
        $memRepo->put($u1);
        $memRepo->put($u2);
        $this->assertEquals(2, $memRepo->count(new AllEntitiesSpecification()));

        $foundU1 = $memRepo->findSingle(new UniqueEntitySpecification($u1));
        $this->assertTrue($foundU1 !== null);
        $this->assertEquals($u1->getEntityId(), $foundU1->getEntityId());

        $memRepo->remove($u1);
        $this->assertEquals(1, $memRepo->count(new AllEntitiesSpecification()));

        $nullRepo = new NullRepository();
        $this->assertEquals(0, $nullRepo->count(new AllEntitiesSpecification()));

        $this->testContainsIsPartOfEveryRepository();
        $this->testContainsDefaultImplementationAndPartitions();
    }

    /**
     * BUG-20261007-ORNH (reprodução): contains() nunca entrou no contrato nem no InMemoryRepository.
     * Antes da correção, InMemoryRepository::contains() não existia (Error: Call to undefined method),
     * e o híbrido InMemoryAndFileRepository::contains() delegava a esse método fantasma.
     */
    private function testContainsIsPartOfEveryRepository(): void
    {
        $this->assertTrue(
            (new \ReflectionClass(IRepository::class))->hasMethod('contains'),
            'contains(IEntity): bool deve ser declarado por IRepository'
        );

        $u1 = new class extends AbstractUUIDEntity {};
        $u2 = new class extends AbstractUUIDEntity {};

        $memRepo = new InMemoryRepository();
        $memRepo->put($u1);
        $this->assertTrue($memRepo->contains($u1), 'entidade gravada deve ser contida');
        $this->assertFalse($memRepo->contains($u2), 'entidade nunca gravada não é contida');

        $memRepo->remove($u1);
        $this->assertFalse($memRepo->contains($u1), 'entidade removida deixa de ser contida');

        $nullRepo = new NullRepository();
        $nullRepo->put($u1);
        $this->assertFalse($nullRepo->contains($u1), 'NullRepository nunca contém nada');
    }

    /**
     * BUG-20261007-ORNH (regressão): a implementação padrão de AbstractRepository responde por
     * identidade (getEntityId) e, na falta, por equals(); a partição consulta o nó e as filhas.
     */
    private function testContainsDefaultImplementationAndPartitions(): void
    {
        $u1 = new class extends AbstractUUIDEntity {};
        $u2 = new class extends AbstractUUIDEntity {};

        // Repositório mínimo que NÃO sobrescreve contains(): usa o padrão de AbstractRepository
        $minimal = new class extends AbstractRepository {
            /** @var array<int, IEntity> */
            private array $items = [];
            public function countAllEntitiesSpecifiedBy(ISpecification $specification): int
            {
                return count($this->findAllEntitiesSpecifiedBy($specification));
            }
            public function iterateAllEntitiesSpecifiedBy(ISpecification $specification): iterable
            {
                foreach ($this->items as $e) {
                    if ($specification->isSatisfiedBy($e)) {
                        yield $e;
                    }
                }
            }
            public function findAllEntitiesSpecifiedBy(ISpecification $specification): array
            {
                return iterator_to_array($this->iterateAllEntitiesSpecifiedBy($specification), false);
            }
            public function put(IEntity $entity): void
            {
                $this->items[] = $entity;
            }
            public function putAll(array $collectionOfEntities): void
            {
                foreach ($collectionOfEntities as $e) {
                    $this->put($e);
                }
            }
            public function update(IEntity $entity): void
            {
            }
            public function updateWithDelta(IEntity $entity, ?ISpecification $deltaSpecification = null): void
            {
            }
            public function removeAllEntitiesSpecifiedBy(ISpecification $specification): int
            {
                return 0;
            }
            public function remove(IEntity $entity): bool
            {
                return false;
            }
        };
        $minimal->put($u1);
        $this->assertTrue($minimal->contains($u1), 'padrão de AbstractRepository: identidade por getEntityId()');
        $this->assertFalse($minimal->contains($u2));

        // Partição volátil: entidade roteada para a filha é contida pela raiz e pela filha
        $root = PartitionRepository::create(new InMemoryRepository());
        $child = $root->addPartition(new UniqueEntitySpecification($u1));
        $root->put($u1);
        $root->put($u2);
        $this->assertTrue($child->contains($u1), 'partição filha contém a entidade roteada');
        $this->assertFalse($child->contains($u2), 'partição filha não contém entidade do nó pai');
        $this->assertTrue($root->contains($u1), 'raiz enxerga a entidade da filha');
        $this->assertTrue($root->contains($u2), 'raiz enxerga a entidade do próprio nó');
        $root->remove($u1);
        $this->assertFalse($root->contains($u1));
    }
}
