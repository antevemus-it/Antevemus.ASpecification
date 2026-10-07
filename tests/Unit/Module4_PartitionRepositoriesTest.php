<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\Tests\TestCase;
use Antevemus\ASpecification\Repositories\PartitionRepository;
use Antevemus\ASpecification\Repositories\InMemoryRepository;
use Antevemus\ASpecification\Repositories\File\SingleFileRepository;
use Antevemus\ASpecification\Repositories\Serialization\JsonEntitySerializer;
use Antevemus\ASpecification\Contracts\Repositories\Exceptions\PartitionCreationException;
use Antevemus\ASpecification\Contracts\Repositories\Exceptions\RepositoryException;
use Antevemus\ASpecification\Contracts\Repositories\PersistenceDefinition;
use Antevemus\ASpecification\Specifications\Collection\UniqueEntitySpecification;
use Antevemus\ASpecification\Specifications\Collection\AllEntitiesSpecification;
use Antevemus\ASpecification\Entities\AbstractUUIDEntity;
use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use InvalidArgumentException;

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
        $this->testAddPartitionWithIdOnPersistentNodeIsRefusedExplicitly();
        $this->testAddPartitionWithIdOnVolatileNodeKeepsTheId();

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

    /**
     * BUG-20261007-QFSJ (reprodução): addPartitionWithId() validava o id e fazia `new $repoClass()`
     * sem argumentos. Sobre um repositório de arquivo (storagePath obrigatório) isso terminava em
     * ArgumentCountError. Agora a recusa é explícita e tipada (PartitionCreationException), mandando
     * usar addPartitionWithRepository(); nenhum caminho de arquivo é derivado do id.
     */
    private function testAddPartitionWithIdOnPersistentNodeIsRefusedExplicitly(): void
    {
        $tmp = sys_get_temp_dir() . '/test_aspec_m4_' . uniqid();
        mkdir($tmp, 0777, true);

        try {
            $ser = new JsonEntitySerializer(M4TaggedEntity::class);
            $file = new SingleFileRepository($tmp . '/root.json', M4TaggedEntity::class, PersistenceDefinition::ReadWrite, $ser);
            $root = $file->makePartition();

            $e = $this->assertThrows(PartitionCreationException::class, function () use ($root): void {
                $root->addPartitionWithId(new M4TagIs('b'), 'tag-b');
            });
            $this->assertInstanceOf(RepositoryException::class, $e, 'a recusa é uma RepositoryException tipada');
            $this->assertTrue(str_contains($e->getMessage(), 'addPartitionWithRepository'), 'a mensagem aponta o caminho suportado');
            $this->assertTrue(str_contains($e->getMessage(), SingleFileRepository::class), 'a mensagem nomeia o tipo do nó');
            $this->assertFalse(file_exists($tmp . '/tag-b.json'), 'nenhum arquivo irmão é derivado do id');
            $this->assertCount(0, $root->getDirectPartitions(), 'nenhuma partição é criada na recusa');

            // Regras pré-existentes continuam: id vazio e id do pai são InvalidArgumentException
            $this->assertThrows(InvalidArgumentException::class, function () use ($root): void {
                $root->addPartitionWithId(new M4TagIs('b'), '   ');
            });
            $this->assertThrows(InvalidArgumentException::class, function () use ($root, $file): void {
                $root->addPartitionWithId(new M4TagIs('b'), $file->getRepositoryId());
            });

            // Caminho suportado (regressão): addPartitionWithRepository() com um repositório explícito
            $child = new SingleFileRepository($tmp . '/tag-b.json', M4TaggedEntity::class, PersistenceDefinition::ReadWrite, $ser, 'tag-b');
            $partB = $root->addPartitionWithRepository(new M4TagIs('b'), $child);
            $root->put(new M4TaggedEntity('b'));
            $this->assertEquals('tag-b', $partB->getUnderlyingRepository()->getRepositoryId());
            $this->assertTrue(file_exists($tmp . '/tag-b.json'), 'entidade roteada para a partição persistente explícita');
            $this->assertEquals(1, $root->count(new AllEntitiesSpecification()));
        } finally {
            foreach (glob($tmp . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($tmp);
        }
    }

    /**
     * BUG-20261007-QFSJ (regressão): em nó volátil a partição filha nasce do mesmo tipo e, quando o
     * tipo aceita um identificador no construtor (InMemoryRepository::__construct(..., repositoryId:)),
     * o id informado é preservado em vez de descartado.
     */
    private function testAddPartitionWithIdOnVolatileNodeKeepsTheId(): void
    {
        $root = PartitionRepository::create(new InMemoryRepository());
        $partB = $root->addPartitionWithId(new M4TagIs('b'), 'tag-b');

        $underlying = $partB->getUnderlyingRepository();
        $this->assertInstanceOf(InMemoryRepository::class, $underlying, 'a filha é do mesmo tipo do nó');
        $this->assertEquals('tag-b', $underlying->getRepositoryId(), 'o id informado é preservado');

        $root->put(new M4TaggedEntity('b'));
        $root->put(new M4TaggedEntity('a'));
        $this->assertEquals(1, $partB->count(new AllEntitiesSpecification()));
        $this->assertEquals(2, $root->count(new AllEntitiesSpecification()));

        // Sem id (addPartition) o repositório filho continua sem identificador
        $partA = $root->addPartition(new M4TagIs('a'));
        $this->assertTrue($partA->getUnderlyingRepository()->getRepositoryId() === null);

        $this->assertThrows(InvalidArgumentException::class, function () use ($root): void {
            $root->addPartitionWithId(new M4TagIs('c'), '');
        });
    }
}
