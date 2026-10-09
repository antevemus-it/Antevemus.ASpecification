<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Tests\TestCase;
use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Repositories\IRepository;
use Antevemus\ASpecification\Contracts\Repositories\IVolatileRepository;
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
use InvalidArgumentException;

/** Intervalo [lo, hi) sobre LazyProbeEntity::$n, com álgebra exata, para as partições do RN-05 (1.6.0). */
class M3NumberRange extends AbstractSpecification
{
    public function __construct(public readonly int $lo, public readonly int $hi)
    {
    }

    public function getType(): string
    {
        return LazyProbeEntity::class;
    }

    public function isSatisfiedBy(?object $candidate): bool
    {
        return $candidate instanceof LazyProbeEntity && $candidate->n >= $this->lo && $candidate->n < $this->hi;
    }

    public function isGeneralizationOf(ISpecification $otherSpecification): bool
    {
        return $otherSpecification instanceof self && $otherSpecification->lo >= $this->lo && $otherSpecification->hi <= $this->hi;
    }

    public function isSpecialCaseOf(ISpecification $otherSpecification): bool
    {
        return $otherSpecification instanceof self && $this->lo >= $otherSpecification->lo && $this->hi <= $otherSpecification->hi;
    }

    public function isDisjointWith(ISpecification $otherSpecification): bool
    {
        return $otherSpecification instanceof self && ($otherSpecification->hi <= $this->lo || $otherSpecification->lo >= $this->hi);
    }

    public function equals(mixed $other): bool
    {
        return $other instanceof self && $other->lo === $this->lo && $other->hi === $this->hi;
    }
}

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

        // Forward 020 (v1.6.0), RN-05: TTL e auto-prune nos repositórios voláteis.
        $this->testRn05TtlIsPartOfTheVolatileContract();
        $this->testRn05ExpiredEntriesAreInvisibleAndEvicted();
        $this->testRn05WriteRefreshesTheTtlAndTtlCanBeChanged();
        $this->testRn05VolatilePartitionsShareTheTtl();
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

    /**
     * RN-05 (forward 020, v1.6.0): withTtl()/prune() entram no contrato de IVolatileRepository; o
     * repositório nulo aceita e não guarda nada; TTL negativo é recusado; 0 desliga.
     */
    private function testRn05TtlIsPartOfTheVolatileContract(): void
    {
        $contract = new \ReflectionClass(IVolatileRepository::class);
        $this->assertTrue($contract->hasMethod('withTtl'), 'withTtl(int): static no contrato');
        $this->assertTrue($contract->hasMethod('prune'), 'prune(): int no contrato');

        $repo = new InMemoryRepository();
        $this->assertTrue($repo->getTtl() === null, 'sem TTL por padrão');
        $this->assertTrue($repo->withTtl(30) === $repo, 'fluente: devolve a própria instância');
        $this->assertEquals(30, $repo->getTtl());
        $this->assertTrue($repo->withTtl(0)->getTtl() === null, 'withTtl(0) desliga a expiração');
        $this->assertEquals(0, $repo->prune(), 'sem TTL nada expira');
        $this->assertThrows(InvalidArgumentException::class, fn() => $repo->withTtl(-1));

        $null = new NullRepository();
        $this->assertTrue($null->withTtl(10) === $null);
        $this->assertEquals(0, $null->prune());
        $this->assertThrows(InvalidArgumentException::class, fn() => $null->withTtl(-5));
    }

    /**
     * RN-05: entrada expirada não volta em findAll, iterate, count, findSingle, contains e getAll; o acesso a remove
     * (lazy) e prune() remove todas de uma vez. Relógio injetado (segundos).
     */
    private function testRn05ExpiredEntriesAreInvisibleAndEvicted(): void
    {
        $now = 1000.0;
        $repo = (new InMemoryRepository())->withClock(function () use (&$now): float {
            return $now;
        })->withTtl(10);
        $all = new AlwaysTrueSpecification();

        $repo->put(new LazyProbeEntity(1));      // expira em 1010
        $now = 1005.0;
        $repo->put(new LazyProbeEntity(2));      // expira em 1015

        $now = 1009.999;
        $this->assertEquals(2, $repo->count($all), 'antes do prazo as duas estão vivas');

        $now = 1010.0;                            // a entrada 1 expira exatamente no prazo
        $this->assertEquals(1, $repo->count($all));
        $this->assertEquals([2], array_map(fn($e) => $e->n, $repo->findAll($all)));
        $this->assertEquals([2], array_map(fn($e) => $e->n, iterator_to_array($repo->iterate($all), false)));
        $this->assertFalse($repo->contains(new LazyProbeEntity(1)), 'contains() não enxerga a expirada');
        $this->assertTrue($repo->findSingle(new M3NumberRange(1, 2)) === null, 'findSingle() também não');
        $this->assertCount(1, $repo->getAll());
        $this->assertFalse($repo->remove(new LazyProbeEntity(1)), 'remover uma expirada não remove nada');
        $this->assertEquals(0, $repo->prune(), 'a leitura já removeu a expirada (lazy)');

        // A iteração (fora do permit do synchronizer) só pula a expirada; quem remove é a próxima leitura ou prune()
        $repo->put(new LazyProbeEntity(3));       // expira em 1020
        $now = 1020.0;                            // 2 e 3 expiradas, nenhuma leitura ainda
        $this->assertEquals([], iterator_to_array($repo->iterate($all), false));
        $this->assertEquals(2, $repo->prune(), 'prune() remove as duas de uma vez');
        $this->assertEquals(0, $repo->prune(), 'e não sobra nada para a segunda chamada');

        // Desligar a expiração depois da remoção não ressuscita nada
        $this->assertEquals(0, $repo->withTtl(0)->count($all));

        // removeAll() e findAll() também ignoram (e removem) as expiradas
        $repo->withTtl(5);
        $repo->putAll([new LazyProbeEntity(10), new LazyProbeEntity(11)]);
        $now = 1023.0;
        $repo->put(new LazyProbeEntity(12));      // expira em 1028
        $now = 1025.0;                            // 10 e 11 expiradas
        $this->assertEquals(1, $repo->removeAll($all), 'só a viva é contada como removida');
        $this->assertEquals(0, $repo->prune());
    }

    /**
     * RN-05: o prazo conta da última escrita (put/update renovam); entradas gravadas antes de ligar o TTL
     * contam a partir de quando ele foi ligado; mudar o TTL aplica a nova duração a todas.
     */
    private function testRn05WriteRefreshesTheTtlAndTtlCanBeChanged(): void
    {
        $now = 0;
        $repo = (new InMemoryRepository())->withClock(function () use (&$now): int {
            return $now;
        });
        $all = new AlwaysTrueSpecification();

        $repo->put(new LazyProbeEntity(1));       // gravada sem TTL
        $now = 100;
        $repo->withTtl(10);                       // conta de 100: expira em 110
        $now = 108;
        $repo->update(new LazyProbeEntity(1));    // renovada: expira em 118
        $now = 115;
        $this->assertEquals(1, $repo->count($all), 'update() renovou o prazo');
        $now = 118;
        $this->assertEquals(0, $repo->count($all), 'e o novo prazo vence');

        $now = 200;
        $repo->put(new LazyProbeEntity(2));
        $now = 205;
        $repo->withTtl(3);                        // nova duração sobre a última escrita (200): já vencida
        $this->assertEquals(0, $repo->count($all));
        $repo->put(new LazyProbeEntity(3));
        $repo->withTtl(60);
        $now = 264;
        $this->assertEquals(1, $repo->count($all), 'aumentar o TTL estende o prazo das existentes');

        // Relógio do sistema quando nenhum é injetado
        $system = (new InMemoryRepository())->withTtl(3600);
        $system->put(new LazyProbeEntity(9));
        $this->assertTrue($system->contains(new LazyProbeEntity(9)));
        $this->assertTrue($system->withClock(null) === $system);
    }

    /**
     * RN-05: no DAG volátil withTtl() vale para o nó e todas as partições; partições criadas depois por
     * addPartition() herdam TTL e relógio; prune() soma as remoções de cada repositório uma vez.
     */
    private function testRn05VolatilePartitionsShareTheTtl(): void
    {
        $now = 0;
        $base = (new InMemoryRepository())->withClock(function () use (&$now): int {
            return $now;
        });
        $root = $base->makePartition();
        $this->assertInstanceOf(VolatilePartitionRepository::class, $root);
        $low = $root->addPartition(new M3NumberRange(0, 100));

        $this->assertTrue($root->withTtl(10) === $root);
        $this->assertEquals(10, $base->getTtl());
        $this->assertEquals(10, $low->getUnderlyingRepository()->getTtl(), 'partição existente recebe o TTL');

        $high = $root->addPartition(new M3NumberRange(100, 200));
        $this->assertEquals(10, $high->getUnderlyingRepository()->getTtl(), 'partição nova herda o TTL do pai');

        $root->put(new LazyProbeEntity(5));       // em low
        $root->put(new LazyProbeEntity(150));     // em high (relógio herdado: expira em 10)
        $root->put(new LazyProbeEntity(500));     // fica na raiz
        $now = 5;
        $root->put(new LazyProbeEntity(6));       // em low, expira em 15
        $all = new AlwaysTrueSpecification();
        $this->assertEquals(4, $root->count($all));

        $now = 10;
        $this->assertEquals([6], array_map(fn($e) => $e->n, $root->findAll($all)));
        $this->assertFalse($root->contains(new LazyProbeEntity(150)), 'expirada na partição herdada');
        $now = 15;
        $this->assertEquals(1, $root->prune(), 'só sobrou a 6 para remover (as outras saíram na leitura)');
        $this->assertEquals(0, $root->count($all));

        // TTL 0 desliga em todo o grafo; negativo é recusado
        $root->withTtl(0);
        $this->assertTrue($high->getUnderlyingRepository()->getTtl() === null);
        $this->assertThrows(InvalidArgumentException::class, fn() => $root->withTtl(-1));
    }
}
