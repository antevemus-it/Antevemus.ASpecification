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
use Antevemus\ASpecification\Contracts\Repositories\IPartitionRepository;
use Antevemus\ASpecification\Specifications\Logical\AlwaysTrueSpecification;
use InvalidArgumentException;
use LogicException;

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

/** Folha "a etiqueta é uma destas", com álgebra exata de conjuntos e contador de avaliações (RN-06, 1.6.0). */
class M4TagIn extends AbstractSpecification
{
    public static int $calls = 0;

    /** @var array<string> */
    public readonly array $tags;

    /** @param array<string> $tags */
    public function __construct(array $tags)
    {
        $tags = array_values(array_unique($tags));
        sort($tags);
        $this->tags = $tags;
    }

    public function getType(): string
    {
        return M4TaggedEntity::class;
    }

    public function isSatisfiedBy(?object $candidate): bool
    {
        self::$calls++;
        return $candidate instanceof M4TaggedEntity && in_array($candidate->tag, $this->tags, true);
    }

    public function isGeneralizationOf(ISpecification $otherSpecification): bool
    {
        return $otherSpecification instanceof self && array_diff($otherSpecification->tags, $this->tags) === [];
    }

    public function isSpecialCaseOf(ISpecification $otherSpecification): bool
    {
        return $otherSpecification instanceof self && array_diff($this->tags, $otherSpecification->tags) === [];
    }

    public function isDisjointWith(ISpecification $otherSpecification): bool
    {
        return $otherSpecification instanceof self && array_intersect($this->tags, $otherSpecification->tags) === [];
    }

    public function equals(mixed $other): bool
    {
        return $other instanceof self && $other->tags === $this->tags;
    }
}

/** Folha cuja álgebra de disjunção falha: o índice nunca a agrupa (RN-06). */
class M4BrokenAlgebraTag extends M4TagIn
{
    public function isDisjointWith(ISpecification $otherSpecification): bool
    {
        throw new LogicException('algebra unavailable');
    }
}

/** Nó de partição de classe do usuário: o índice da subárvore não assume o percurso dele. */
class M4UserPartition extends PartitionRepository
{
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

        // Forward 020 (v1.6.0), RN-06: índice materializado do DAG (ordem topológica e clusters de irmãs disjuntas).
        $this->testRn06DefaultsFollowTheBenchmark();
        $this->testRn06IndexedAndRecursiveWalksAgree();
        $this->testRn06RoutingClustersSkipDisjointSiblings();
        $this->testRn06IndexFollowsStructuralChanges();

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

    /**
     * RN-06: os padrões refletem o portão do benchmark (tests/Benchmark/REPORT-dag-index.md): índice da
     * subárvore ligado, clusters de roteamento desligados.
     */
    private function testRn06DefaultsFollowTheBenchmark(): void
    {
        $this->assertTrue(PartitionRepository::isDagIndexEnabled(), 'índice da subárvore ligado por padrão');
        $this->assertFalse(PartitionRepository::isDagRoutingEnabled(), 'clusters de roteamento desligados por padrão');
    }

    /**
     * RN-06: com o índice desligado, só a subárvore, ou subárvore + clusters, o grafo responde igual: mesmas
     * entidades na mesma ordem em findAll/iterate (inclusive com poda e diamantes), mesmas contagens em
     * count/removeAll, mesmo contains/remove. Grafos e entidades sorteados com semente fixa.
     */
    private function testRn06IndexedAndRecursiveWalksAgree(): void
    {
        $alphabet = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'];
        $modes = ['off' => [false, false], 'subtree' => [true, false], 'full' => [true, true]];
        $initial = [PartitionRepository::isDagIndexEnabled(), PartitionRepository::isDagRoutingEnabled()];

        try {
            for ($seed = 1; $seed <= 12; $seed++) {
                mt_srand($seed);
                $partitionTags = [];
                for ($p = 0; $p < 9; $p++) {
                    $size = mt_rand(1, 4);
                    $tags = [];
                    for ($k = 0; $k < $size; $k++) {
                        $tags[] = $alphabet[mt_rand(0, count($alphabet) - 1)];
                    }
                    $partitionTags[] = $tags;
                }
                $entityTags = [];
                for ($e = 0; $e < 40; $e++) {
                    $entityTags[] = $alphabet[mt_rand(0, count($alphabet) - 1)];
                }
                $queries = [['a'], ['b', 'c'], ['h'], ['a', 'd', 'g'], $alphabet];

                $outcomes = [];
                foreach ($modes as $mode => [$index, $routing]) {
                    PartitionRepository::setDagIndexEnabled($index);
                    PartitionRepository::setDagRoutingEnabled($routing);

                    $root = PartitionRepository::create(new InMemoryRepository());
                    foreach ($partitionTags as $tags) {
                        $root->addPartition(new M4TagIn($tags));
                    }
                    $entities = [];
                    foreach ($entityTags as $i => $tag) {
                        $entities[$i] = new M4TaggedEntity($tag);
                        $root->put($entities[$i]);
                    }
                    $position = array_flip(array_map(fn($e) => $e->getEntityId(), $entities));
                    $ids = fn(iterable $found): array => array_map(fn($e) => $position[$e->getEntityId()], is_array($found) ? $found : iterator_to_array($found, false));

                    $outcome = ['all' => $ids($root->findAll(new AlwaysTrueSpecification()))];
                    foreach ($queries as $q => $tags) {
                        $outcome["find{$q}"] = $ids($root->findAll(new M4TagIn($tags)));
                        $outcome["iterate{$q}"] = $ids($root->iterate(new M4TagIn($tags)));
                        $outcome["count{$q}"] = $root->count(new M4TagIn($tags));
                    }
                    foreach ($root->getDirectPartitions() as $d => $child) {
                        $outcome["child{$d}"] = $ids($child->findAll(new AlwaysTrueSpecification()));
                    }
                    $outcome['contains'] = array_map(fn($e) => $root->contains($e), $entities);
                    $outcome['remove'] = [$root->remove($entities[0]), $root->remove($entities[0]), $root->contains($entities[0])];
                    $outcome['removeAll'] = [$root->removeAll(new M4TagIn(['a', 'b'])), $root->count(new AlwaysTrueSpecification())];
                    $outcome['after'] = $ids($root->findAll(new AlwaysTrueSpecification()));
                    $outcomes[$mode] = $outcome;
                }

                $this->assertTrue($outcomes['subtree'] === $outcomes['off'], "semente {$seed}: índice da subárvore responde como o percurso recursivo");
                $this->assertTrue($outcomes['full'] === $outcomes['off'], "semente {$seed}: clusters de roteamento não mudam o resultado");
                $this->assertEquals(count($entityTags), count($outcomes['off']['all']), "semente {$seed}: cada entidade uma vez");
            }
        } finally {
            PartitionRepository::setDagIndexEnabled($initial[0]);
            PartitionRepository::setDagRoutingEnabled($initial[1]);
        }
    }

    /**
     * RN-06 (i): com os clusters ligados, put() para de testar as irmãs disjuntas assim que uma aceita a
     * entidade; irmãs que se sobrepõem ficam em clusters separados e ambas recebem a entidade; uma álgebra
     * que falha nunca agrupa; o cluster é mantido incrementalmente quando partições entram ou saem.
     */
    private function testRn06RoutingClustersSkipDisjointSiblings(): void
    {
        $initial = PartitionRepository::isDagRoutingEnabled();
        try {
            $evaluationsToPut = function (bool $routing, string $tag): int {
                PartitionRepository::setDagRoutingEnabled($routing);
                $root = PartitionRepository::create(new InMemoryRepository());
                foreach (['a', 'b', 'c', 'd', 'e'] as $t) {
                    $root->addPartition(new M4TagIn([$t]));
                }
                $root->put(new M4TaggedEntity('z0')); // nenhum cluster aceita: fica na raiz (e constrói o índice)
                M4TagIn::$calls = 0;
                $root->put(new M4TaggedEntity($tag));
                return M4TagIn::$calls;
            };

            // 5 irmãs + a verificação da própria folha
            $this->assertEquals(6, $evaluationsToPut(false, 'a'), 'sem clusters: as cinco irmãs são testadas');
            $this->assertEquals(2, $evaluationsToPut(true, 'a'), 'com clusters: a primeira aceita, as outras quatro são puladas');
            $this->assertEquals(6, $evaluationsToPut(true, 'e'), 'a última do cluster ainda custa as cinco');

            PartitionRepository::setDagRoutingEnabled(true);

            // Sobreposição: clusters separados, a entidade vai para as duas
            $root = PartitionRepository::create(new InMemoryRepository());
            $ab = $root->addPartition(new M4TagIn(['a', 'b']));
            $bc = $root->addPartition(new M4TagIn(['b', 'c']));
            $b = new M4TaggedEntity('b');
            $root->put($b);
            $this->assertTrue($ab->contains($b) && $bc->contains($b), 'irmãs que se sobrepõem recebem a entidade');

            // Manutenção incremental: uma irmã disjunta nova entra no cluster; uma sobreposta não
            $d = $root->addPartition(new M4TagIn(['d']));
            $ax = $root->addPartition(new M4TagIn(['a', 'x']));
            $a = new M4TaggedEntity('a');
            $root->put($a);
            $this->assertTrue($ab->contains($a) && $ax->contains($a), "'a' vai para {a,b} e {a,x}");
            $this->assertFalse($d->contains($a) || $bc->contains($a));
            $root->put(new M4TaggedEntity('d'));
            $this->assertEquals(1, $d->count(new AlwaysTrueSpecification()));

            // Substituição de partição equivalente (RN-02 (a)) atualiza o índice
            $newAb = $root->addPartition(new M4TagIn(['a', 'b']));
            $this->assertTrue($newAb !== $ab);
            $a2 = new M4TaggedEntity('a');
            $root->put($a2);
            $this->assertTrue($newAb->contains($a2), 'a partição que substituiu recebe as novas entidades');
            $this->assertFalse($ab->contains($a2), 'a substituída não recebe mais nada');

            // Álgebra que falha: nenhum agrupamento, a entidade vai para todas as irmãs que a aceitam
            $root = PartitionRepository::create(new InMemoryRepository());
            $p1 = $root->addPartition(new M4BrokenAlgebraTag(['k']));
            $p2 = $root->addPartition(new M4BrokenAlgebraTag(['k', 'm']));
            $k = new M4TaggedEntity('k');
            $root->put($k);
            $this->assertTrue($p1->contains($k) && $p2->contains($k), 'sem prova de disjunção não há atalho');
        } finally {
            PartitionRepository::setDagRoutingEnabled($initial);
        }
    }

    /**
     * RN-06 (ii): o índice da subárvore é refeito depois de cada mudança estrutural (addPartition* com
     * generalização, especialização e substituição); um nó de classe do usuário mantém o percurso recursivo;
     * consultas num nó interno usam a subárvore dele.
     */
    private function testRn06IndexFollowsStructuralChanges(): void
    {
        $root = PartitionRepository::create(new InMemoryRepository());
        $root->addPartition(new M4TagIn(['a']));
        foreach (['a', 'a', 'b', 'c'] as $tag) {
            $root->put(new M4TaggedEntity($tag));
        }
        $all = new AlwaysTrueSpecification();
        $this->assertEquals(4, $root->count($all));

        // Generalização acima de {a}: o índice construído antes fica velho e é refeito
        $abc = $root->addPartition(new M4TagIn(['a', 'b', 'c']));
        $this->assertEquals(4, $root->count($all));
        $this->assertEquals(4, $abc->count($all), 'consulta no nó interno percorre a subárvore dele');
        $this->assertEquals(2, $abc->count(new M4TagIn(['a'])));
        $this->assertCount(0, $root->getEntitiesOfThisPartitionOnly(), 'a raiz não guarda mais nada');

        // Especialização e remoção pela ordem topológica
        $root->addPartition(new M4TagIn(['b']));
        $this->assertEquals(1, $root->removeAll(new M4TagIn(['b'])));
        $this->assertEquals(3, $root->count($all));

        // Nó de classe do usuário: sem índice da subárvore, mesmo resultado
        $user = new M4UserPartition(new InMemoryRepository());
        $user->addPartition(new M4TagIn(['a']));
        $user->put(new M4TaggedEntity('a'));
        $user->put(new M4TaggedEntity('q'));
        $this->assertEquals(2, $user->count($all));
        $this->assertTrue($user->getDirectPartitions()[0] instanceof IPartitionRepository);
    }
}
