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
use Antevemus\ASpecification\Linq\ALinqBridge;
use Antevemus\ASpecification\Repositories\VolatilePartitionRepository;
use Antevemus\ASpecification\Specifications\Logical\AlwaysTrueSpecification;
use Antevemus\ASpecification\Tests\Support\CountingSpecification;
use Antevemus\ASpecification\Tests\Support\InstrumentedInMemoryRepository;
use Antevemus\ASpecification\Tests\Support\LazyProbeEntity;
use Generator;

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

        // Forward 017 (v1.5.0), RN-04: iterate*() lazy de verdade e findAsLazyCollection() sem getAll().
        $this->testRn04InMemoryIterateIsLazyWithoutCopy();
        $this->testRn04VolatilePartitionIterateIsLazy();
        $this->testRn04LazyCollectionNeverCallsGetAll();
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

    /**
     * RN-04 (forward 017, v1.5.0): InMemoryRepository::iterate() é um generator que avalia cada
     * entidade só quando puxada, sem getAll() e sem copiar o mapa; escritas durante a iteração não
     * a perturbam (snapshot copy-on-write).
     */
    private function testRn04InMemoryIterateIsLazyWithoutCopy(): void
    {
        $repo = new InstrumentedInMemoryRepository();
        for ($i = 0; $i < 1000; $i++) {
            $repo->put(new LazyProbeEntity($i));
        }

        $spec = new CountingSpecification(fn(LazyProbeEntity $e): bool => $e->n % 2 === 0);
        $iterator = $repo->iterate($spec);
        $this->assertTrue($iterator instanceof Generator, 'iterate() devolve um Generator');
        $this->assertEquals(0, $spec->evaluations, 'nada é avaliado antes do primeiro pull');

        $this->assertEquals(0, $iterator->current()->n);
        $this->assertEquals(1, $spec->evaluations, 'o primeiro item custa uma avaliação');
        $iterator->next();
        $this->assertEquals(2, $iterator->current()->n);
        $this->assertEquals(3, $spec->evaluations);

        // Escrita no meio da iteração: o generator continua sobre o snapshot
        $repo->put(new LazyProbeEntity(5000));
        $repo->remove(new LazyProbeEntity(4));
        $seen = [];
        for (; $iterator->valid(); $iterator->next()) {
            $seen[] = $iterator->current()->n;
        }
        $this->assertTrue(in_array(4, $seen, true), 'o snapshot ainda tem o removido');
        $this->assertFalse(in_array(5000, $seen, true), 'nem vê o inserido depois do início');
        $this->assertEquals(0, $repo->getAllCalls);

        // Aliases e repositório nulo
        $this->assertTrue($repo->iterateAll(new AlwaysTrueSpecification()) instanceof Generator);
        $this->assertEquals([], iterator_to_array((new NullRepository())->iterate(new AlwaysTrueSpecification())));
    }

    /**
     * RN-04: a partição volátil (InMemoryRepository promovido a DAG) consome o nó e cada partição
     * pelos próprios generators: o primeiro item custa só as avaliações para alcançá-lo.
     */
    private function testRn04VolatilePartitionIterateIsLazy(): void
    {
        $base = new InMemoryRepository();
        for ($i = 0; $i < 500; $i++) {
            $base->put(new LazyProbeEntity($i));
        }
        $partition = $base->makePartition();
        $this->assertInstanceOf(VolatilePartitionRepository::class, $partition);

        $spec = new CountingSpecification(fn(LazyProbeEntity $e): bool => $e->n >= 3);
        $iterator = $partition->iterate($spec);
        $this->assertEquals(0, $spec->evaluations);
        $this->assertEquals(3, $iterator->current()->n);
        $this->assertEquals(4, $spec->evaluations, 'quatro avaliações (0, 1, 2, 3) e não 500');
    }

    /**
     * Cenário Gherkin RN-04 "fonte lazy sem getAll()": InMemoryRepository com 100.000 entidades
     * instrumentado; findAsLazyCollection($spec)->take(10)->toArray() não chama getAll() e só visita
     * os itens necessários para 10 acertos. A coleção é re-iterável (um generator novo por travessia).
     */
    private function testRn04LazyCollectionNeverCallsGetAll(): void
    {
        $repo = new InstrumentedInMemoryRepository();
        $entities = [];
        for ($i = 0; $i < 100000; $i++) {
            $entities[] = new LazyProbeEntity($i);
        }
        $repo->putAll($entities);
        unset($entities);

        $spec = new CountingSpecification(fn(LazyProbeEntity $e): bool => $e->n % 3 === 0);

        // Sem a ponte: iterate() já é a fonte lazy
        $hits = [];
        foreach ($repo->iterate($spec) as $entity) {
            $hits[] = $entity->n;
            if (count($hits) === 10) {
                break;
            }
        }
        $this->assertEquals([0, 3, 6, 9, 12, 15, 18, 21, 24, 27], $hits);
        $this->assertEquals(28, $spec->evaluations, '10 acertos a cada 3: 28 avaliações, não 100.000');
        $this->assertEquals(0, $repo->getAllCalls);

        if (!ALinqBridge::isLazyAvailable()) {
            fwrite(STDOUT, "    [AVISO] antevemus/alinq-collection não encontrado; findAsLazyCollection() (RN-04) pulado.\n");
            return;
        }

        $spec->evaluations = 0;
        $repo->iterateCalls = 0;
        $lazy = $repo->findAsLazyCollection($spec);
        $this->assertEquals(0, $spec->evaluations, 'montar a coleção não avalia nada');
        $this->assertEquals(0, $repo->iterateCalls, 'nem pede o generator antes da travessia');

        $ten = $lazy->take(10)->toArray();
        $this->assertCount(10, $ten);
        $this->assertEquals(0, $ten[0]->n);
        $this->assertEquals(27, $ten[9]->n);
        $this->assertEquals(0, $repo->getAllCalls, 'getAll() não é chamado');
        $this->assertEquals(28, $spec->evaluations, 'só os itens necessários para 10 acertos são visitados');

        // Re-iterável: a segunda travessia pede um generator novo e devolve o mesmo resultado
        $again = $lazy->take(10)->toArray();
        $this->assertEquals(array_map(fn($e) => $e->n, $ten), array_map(fn($e) => $e->n, $again));
        $this->assertEquals(2, $repo->iterateCalls, 'um generator por travessia');

        $first = $repo->asLazyCollection()->first();
        $this->assertEquals(0, $first->n);
        $this->assertEquals(0, $repo->getAllCalls);
    }
}
