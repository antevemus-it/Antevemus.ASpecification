<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\Tests\TestCase;
use Antevemus\ASpecification\Repositories\File\SingleFileRepository;
use Antevemus\ASpecification\Repositories\File\FilePerEntityRepository;
use Antevemus\ASpecification\Repositories\File\InMemoryAndFileRepository;
use Antevemus\ASpecification\Repositories\File\FileNameSanitizer;
use Antevemus\ASpecification\Repositories\Serialization\JsonEntitySerializer;
use Antevemus\ASpecification\Repositories\Serialization\PhpNativeEntitySerializer;
use Antevemus\ASpecification\Contracts\Repositories\Exceptions\RepositoryException;
use Antevemus\ASpecification\Contracts\Repositories\IEntityPersistenceMetaData;
use Antevemus\ASpecification\Contracts\Repositories\PersistenceDefinition;
use Antevemus\ASpecification\Specifications\Collection\AllEntitiesSpecification;
use Antevemus\ASpecification\Specifications\Collection\UniqueEntitySpecification;
use Antevemus\ASpecification\Repositories\PersistentPartitionRepository;
use Antevemus\ASpecification\Repositories\InMemoryRepository;
use Antevemus\ASpecification\Entities\AbstractUUIDEntity;
use Antevemus\ASpecification\Tests\Support\ChildWriterEntity;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

class TestFileEntity extends AbstractUUIDEntity {
    public function __construct(public string $title = 'Item') {
        parent::__construct();
    }
}

/** Gadget de teste (BUG-20261007-HIJG): qualquer instanciação via unserialize deixa rastro. */
class M5WakeupGadget {
    public static bool $woke = false;
    public function __wakeup(): void { self::$woke = true; }
}

class Module5_FileRepositoriesTest extends TestCase
{
    public function run(): void
    {
        $tmp = sys_get_temp_dir() . '/test_aspec_m5_' . uniqid();
        mkdir($tmp, 0777, true);

        try {
            $this->testBinEnvelopeDoesNotInstantiateArbitraryClasses($tmp);
            $this->testBinRoundTripStillWorks($tmp);
            $this->testTwoInstancesOnSameFileDoNotOverwriteEachOther($tmp);
            $this->testConcurrentProcessesDoNotLoseUpdates($tmp);
            $this->testReadmeExample5HybridFactory($tmp);
            $this->testHybridContainsWorksThroughTheCache($tmp);
            $this->testPersistenceEnvelopeIsIgnoredOnRead();
            $this->testSerializedDocumentCarriesNoPersistenceEnvelope($tmp);
            $this->testFileRepositoriesRecordSessionMetadata($tmp);

            $u1 = new TestFileEntity("U1");
            $u2 = new TestFileEntity("U2");

            $singlePath = $tmp . '/audit_users.json';
            $jsonSer = new JsonEntitySerializer(TestFileEntity::class);
            $singleRepo = new SingleFileRepository($singlePath, TestFileEntity::class, PersistenceDefinition::ReadWrite, $jsonSer);
            $singleRepo->put($u1);
            $singleRepo->put($u2);
            $this->assertTrue(file_exists($singlePath));
            $this->assertEquals(2, $singleRepo->countAllEntities());

            // FilePerEntity
            $dirRepo = new FilePerEntityRepository($tmp . '/per_entity', TestFileEntity::class, PersistenceDefinition::ReadWrite, $jsonSer);
            $dirRepo->put($u1);
            $sanitized = FileNameSanitizer::sanitize((string)$u1->getEntityId());
            $this->assertTrue(file_exists($tmp . "/per_entity/{$sanitized}.json"));

            // Hybrid
            $hybrid = new InMemoryAndFileRepository($singleRepo);
            $hybrid->warmup();
            $this->assertTrue($hybrid->isWarmedUp());
            $this->assertEquals(2, $hybrid->countAllEntities());
            $hybrid->close();

            // Partição persistente
            $part = $singleRepo->makePartition(new AllEntitiesSpecification());
            $this->assertTrue($part instanceof PersistentPartitionRepository);
        } finally {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($tmp, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                ($f->isDir() ? 'rmdir' : 'unlink')($f->getRealPath());
            }
            @rmdir($tmp);
        }
    }

    /**
     * Forward 014 / R13 (RN-09): o exemplo 5 do README roda como escrito com a fábrica nomeada
     * InMemoryAndFileRepository::create(storagePath:, serializer:). O bloco abaixo é a transcrição
     * do README (EN, "5. Decoupled Persistence & Hybrid L1/L2 Cache Tiering"), com o caminho temporário.
     */
    private function testReadmeExample5HybridFactory(string $tmp): void
    {
        $storagePath = $tmp . '/readme_ex5/customers.json'; // o diretório pai ainda não existe
        $newCustomer = new TestFileEntity('Alice');

        // High-speed RAM read performance (L1) with durable disk persistence (L2)
        $repo = InMemoryAndFileRepository::create(
            storagePath: $storagePath,
            serializer: new JsonEntitySerializer(TestFileEntity::class)
        );

        $repo->put($newCustomer); // Stored in RAM and synchronized to disk atomically

        // Sincronizado em disco
        $this->assertTrue(file_exists($storagePath));
        $this->assertTrue(filesize($storagePath) > 0);

        // Lido da RAM (L1), sem passo extra de warmup
        $this->assertTrue($repo->isWarmedUp());
        $this->assertEquals(1, $repo->countAllEntities());
        $found = $repo->findAll(new AllEntitiesSpecification(TestFileEntity::class));
        $this->assertEquals(1, count($found));
        $this->assertEquals('Alice', $found[0]->title);

        // Camadas esperadas e identificador padrão
        $this->assertTrue($repo->getMemoryCache() instanceof InMemoryRepository);
        $this->assertTrue($repo->getFileBackend() instanceof SingleFileRepository);
        $this->assertEquals(PersistenceDefinition::ReadWrite, $repo->getPersistenceDefinition());
        $this->assertEquals('hybrid_customers.json', $repo->getRepositoryId());

        // Durabilidade: uma segunda instância sobre o mesmo arquivo lê a entidade de volta
        $again = InMemoryAndFileRepository::create(
            storagePath: $storagePath,
            serializer: new JsonEntitySerializer(TestFileEntity::class),
            repositoryId: 'customers_l1l2'
        );
        $this->assertEquals(1, $again->countAllEntities());
        $this->assertEquals('customers_l1l2', $again->getRepositoryId());
        $this->assertEquals(
            (string) $newCustomer->getEntityId(),
            (string) $again->findAll(new AllEntitiesSpecification(TestFileEntity::class))[0]->getEntityId()
        );

        // Cache L1 fornecido pelo chamador é respeitado
        $cache = new InMemoryRepository();
        $withCache = InMemoryAndFileRepository::create($storagePath, new JsonEntitySerializer(TestFileEntity::class), $cache);
        $this->assertTrue($withCache->getMemoryCache() === $cache);
        $this->assertEquals(1, $cache->count(new AllEntitiesSpecification(TestFileEntity::class)));

        $repo->close();
        $again->close();
        $withCache->close();
    }

    /**
     * BUG-20261007-ORNH (regressão): o híbrido do exemplo 5 do README responde contains() pelo cache L1.
     * Antes da correção: Error "Call to undefined method InMemoryRepository::contains()".
     */
    private function testHybridContainsWorksThroughTheCache(string $tmp): void
    {
        $storagePath = $tmp . '/contains/customers.json';
        $alice = new TestFileEntity('Alice');
        $bob = new TestFileEntity('Bob');

        $repo = InMemoryAndFileRepository::create(
            storagePath: $storagePath,
            serializer: new JsonEntitySerializer(TestFileEntity::class)
        );
        $repo->put($alice);

        $this->assertTrue($repo->contains($alice), 'entidade gravada é contida pelo híbrido');
        $this->assertFalse($repo->contains($bob), 'entidade nunca gravada não é contida');

        // Segunda instância sobre o mesmo arquivo: o cache aquecido por create() responde também
        $again = InMemoryAndFileRepository::create($storagePath, new JsonEntitySerializer(TestFileEntity::class));
        $this->assertTrue($again->contains($alice), 'cache aquecido do arquivo contém a entidade persistida');

        $this->assertTrue($repo->remove($alice));
        $this->assertFalse($repo->contains($alice), 'entidade removida deixa de ser contida');

        // As classes de arquivo mantêm o contains() que já tinham
        $this->assertFalse($repo->getFileBackend()->contains($alice));
        $repo->close();
        $again->close();
    }

    /**
     * BUG-20261007-5MWT (reprodução): deserialize() de um documento com "__persistence_metadata"
     * chamava métodos inexistentes de EntityPersistenceMetaData (Error com contador >= 1) ou devolvia
     * PersistentEntity, violando o retorno IEntity (TypeError com contadores 0). Agora o envelope é
     * ignorado e a entidade de "__entity_data" é hidratada.
     */
    private function testPersistenceEnvelopeIsIgnoredOnRead(): void
    {
        $ser = new JsonEntitySerializer(TestFileEntity::class);
        $original = new TestFileEntity('Enveloped');
        $entityData = json_decode($ser->serialize($original), true);

        foreach ([1, 0] as $count) {
            $document = json_encode([
                '__class' => TestFileEntity::class,
                '__is_persistent_envelope' => true,
                '__persistence_metadata' => [
                    'access_count' => $count,
                    'write_count' => $count,
                    'last_access_at' => null,
                    'last_write_at' => null,
                ],
                '__entity_data' => $entityData,
            ]);

            $entity = $ser->deserialize($document, TestFileEntity::class);
            $this->assertInstanceOf(TestFileEntity::class, $entity, "envelope com contadores {$count}: devolve a entidade");
            $this->assertEquals('Enveloped', $entity->title);
            $this->assertEquals((string) $original->getEntityId(), (string) $entity->getEntityId());
        }
    }

    /**
     * BUG-20261007-5MWT (regressão): nenhum documento produzido pelo serializer ou pelo repositório
     * de arquivo carrega o envelope; a ida e volta de uma entidade comum continua intacta.
     */
    private function testSerializedDocumentCarriesNoPersistenceEnvelope(string $tmp): void
    {
        $ser = new JsonEntitySerializer(TestFileEntity::class);
        $entity = new TestFileEntity('Plain');

        $json = $ser->serialize($entity);
        $decoded = json_decode($json, true);
        $this->assertTrue(is_array($decoded));
        $this->assertFalse(array_key_exists('__is_persistent_envelope', $decoded));
        $this->assertFalse(array_key_exists('__persistence_metadata', $decoded));
        $this->assertEquals(TestFileEntity::class, $decoded['__class']);

        $back = $ser->deserialize($json, TestFileEntity::class);
        $this->assertEquals('Plain', $back->title);

        $path = $tmp . '/no_envelope.json';
        $repo = new SingleFileRepository($path, TestFileEntity::class, PersistenceDefinition::ReadWrite, $ser);
        $repo->put($entity);
        $this->assertFalse(str_contains((string) file_get_contents($path), '__persistence_metadata'));
    }

    /**
     * BUG-20261007-6NMZ (reprodução + regressão): os repositórios de arquivo registram metadados por
     * entidade na sessão corrente. Antes da correção recordWriteMetadata()/recordReadMetadata() não
     * tinham chamadores e getEntityMetaData() devolvia null para toda entidade.
     */
    private function testFileRepositoriesRecordSessionMetadata(string $tmp): void
    {
        $ser = new JsonEntitySerializer(TestFileEntity::class);
        $all = new AllEntitiesSpecification(TestFileEntity::class);

        $single = new SingleFileRepository($tmp . '/meta_single.json', TestFileEntity::class, PersistenceDefinition::ReadWrite, $ser);
        $perEntity = new FilePerEntityRepository($tmp . '/meta_dir', TestFileEntity::class, PersistenceDefinition::ReadWrite, $ser);

        foreach (['single' => $single, 'per-entity' => $perEntity] as $label => $repo) {
            $e = new TestFileEntity("Meta {$label}");
            $this->assertTrue($repo->getEntityMetaData($e) === null, "{$label}: entidade nunca tocada não tem metadados");

            $repo->put($e);
            $meta = $repo->getEntityMetaData($e);
            $this->assertTrue($meta instanceof IEntityPersistenceMetaData, "{$label}: put() registra metadados");
            $this->assertEquals(0, $meta->getWriteCount(), "{$label}: a criação não conta como reescrita");
            $this->assertTrue($meta->getFirstWrite() !== null, "{$label}: a criação marca a primeira gravação");
            $this->assertEquals(0, $meta->getReadCount());

            $repo->put($e);
            $this->assertEquals(1, $repo->getEntityMetaData($e)->getWriteCount(), "{$label}: segundo put() incrementa a escrita");

            $found = $repo->findAll($all);
            $this->assertCount(1, $found);
            $this->assertEquals(1, $repo->getEntityMetaData($e)->getReadCount(), "{$label}: findAll() registra uma leitura");
            $this->assertTrue($repo->getEntityMetaData($e)->getLastRead() !== null);

            $repo->findSingle(new UniqueEntitySpecification($e));
            $this->assertEquals(2, $repo->getEntityMetaData($e)->getReadCount(), "{$label}: findSingle() registra outra leitura");

            $this->assertTrue($repo->remove($e));
            $this->assertTrue($repo->getEntityMetaData($e) === null, "{$label}: remove() esquece os metadados");

            $repo->put($e);
            $repo->clear();
            $this->assertTrue($repo->getEntityMetaData($e) === null, "{$label}: clear() esquece todos os metadados");
        }

        // Semântica de sessão: nova instância sobre o mesmo arquivo não herda metadados
        $persisted = new TestFileEntity('Persisted');
        $single->put($persisted);
        $reopened = new SingleFileRepository($tmp . '/meta_single.json', TestFileEntity::class, PersistenceDefinition::ReadWrite, $ser);
        $this->assertTrue($reopened->getEntityMetaData($persisted) === null, 'metadados são da sessão corrente, não do arquivo (carregar do disco não é uma leitura servida)');
        $reopened->findAll($all);
        $this->assertEquals(1, $reopened->getEntityMetaData($persisted)->getReadCount());
        $this->assertEquals(1, $reopened->countAllEntities());

        // Híbrido delega ao backend de arquivo
        $hybrid = InMemoryAndFileRepository::create($tmp . '/meta_hybrid.json', $ser);
        $h = new TestFileEntity('Hybrid');
        $hybrid->put($h);
        $this->assertTrue($hybrid->getEntityMetaData($h) instanceof IEntityPersistenceMetaData, 'híbrido expõe os metadados do L2');
        $hybrid->close();

        // Partição persistente continua declarando não suportado (005 RN-14)
        $this->assertThrows(RepositoryException::class, function () use ($single, $persisted): void {
            $single->makePartition()->getEntityMetaData($persisted);
        });
    }

    /**
     * BUG-20261007-HIJG (reprodução): objeto gravado no envelope .bin não pode ser instanciado.
     * Antes da correção o envelope era lido com allowed_classes => true e o __wakeup executava.
     */
    private function testBinEnvelopeDoesNotInstantiateArbitraryClasses(string $tmp): void
    {
        $binPath = $tmp . '/poisoned.bin';
        M5WakeupGadget::$woke = false;
        file_put_contents($binPath, serialize([
            '__meta'   => ['repository_id' => 'x', 'entity_type' => TestFileEntity::class, 'count' => 1],
            'entities' => [new M5WakeupGadget()],
        ]));

        $repo = new SingleFileRepository(
            $binPath,
            TestFileEntity::class,
            PersistenceDefinition::ReadWrite,
            new PhpNativeEntitySerializer(TestFileEntity::class)
        );

        $this->assertEquals(0, $repo->countAllEntities(), 'Objeto fora da whitelist não pode virar entidade');
        $this->assertFalse(M5WakeupGadget::$woke, 'unserialize do envelope .bin instanciou classe fora da whitelist');
    }

    /**
     * BUG-20261007-HIJG (regressão): entidades legítimas no .bin continuam sendo reconstruídas pela whitelist.
     */
    private function testBinRoundTripStillWorks(string $tmp): void
    {
        $binPath = $tmp . '/legit.bin';
        $ser = new PhpNativeEntitySerializer(TestFileEntity::class);

        $repo = new SingleFileRepository($binPath, TestFileEntity::class, PersistenceDefinition::ReadWrite, $ser);
        $repo->put(new TestFileEntity('Bin'));
        $this->assertTrue(file_exists($binPath));

        $reopened = new SingleFileRepository($binPath, TestFileEntity::class, PersistenceDefinition::ReadWrite, $ser);
        $this->assertEquals(1, $reopened->countAllEntities());
        $found = array_values($reopened->findAll(new AllEntitiesSpecification()));
        $this->assertInstanceOf(TestFileEntity::class, $found[0]);
        $this->assertEquals('Bin', $found[0]->title);
    }

    /**
     * BUG-20261007-7RZJ (reprodução em um processo): duas instâncias ReadWrite sobre o mesmo
     * arquivo. Antes da correção cada uma gravava o próprio mapa inteiro por cima do outro
     * (load-once + store do mapa), e a última escrita apagava a entidade da outra instância.
     */
    private function testTwoInstancesOnSameFileDoNotOverwriteEachOther(string $tmp): void
    {
        $path = $tmp . '/shared.json';
        $ser = new JsonEntitySerializer(TestFileEntity::class);

        $a = new SingleFileRepository($path, TestFileEntity::class, PersistenceDefinition::ReadWrite, $ser);
        $b = new SingleFileRepository($path, TestFileEntity::class, PersistenceDefinition::ReadWrite, $ser);

        $fromA = new TestFileEntity('from A');
        $fromB = new TestFileEntity('from B');
        $a->put($fromA);
        $b->put($fromB);

        $reopened = new SingleFileRepository($path, TestFileEntity::class, PersistenceDefinition::ReadWrite, $ser);
        $this->assertEquals(2, $reopened->countAllEntities(), 'put() de B apagou a entidade gravada por A (lost update)');

        $this->assertTrue($b->remove($fromA), 'remove() deve enxergar a entidade gravada pela outra instância');
        $reopened = new SingleFileRepository($path, TestFileEntity::class, PersistenceDefinition::ReadWrite, $ser);
        $this->assertEquals(1, $reopened->countAllEntities());
    }

    /**
     * BUG-20261007-7RZJ (reprodução multiprocesso): 1 semente + 4 processos x 5 put() = 21 entidades.
     * Antes da correção o lock era tomado no próprio arquivo, que o rename() substitui por outro
     * inode, e o store() gravava o mapa inteiro carregado uma vez: sobravam entre 6 e 18 entidades.
     */
    private function testConcurrentProcessesDoNotLoseUpdates(string $tmp): void
    {
        if (!function_exists('proc_open')) {
            echo "\n    [aviso] proc_open indisponível: teste multiprocesso do SingleFileRepository pulado.\n";
            return;
        }

        $path = $tmp . '/concurrent.json';
        $ser = new JsonEntitySerializer(ChildWriterEntity::class);
        $seed = new SingleFileRepository($path, ChildWriterEntity::class, PersistenceDefinition::ReadWrite, $ser);
        $seed->put(new ChildWriterEntity('seed'));

        $script = dirname(__DIR__) . '/Support/single_file_writer.php';
        $writers = 4;
        $putsPerWriter = 5;
        $processes = [];
        $pipes = [];

        for ($w = 1; $w <= $writers; $w++) {
            $spec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = proc_open([PHP_BINARY, $script, $path, "w{$w}", (string) $putsPerWriter], $spec, $procPipes, null, ['XDEBUG_MODE' => 'off']);
            $this->assertTrue(is_resource($proc), "Falha ao iniciar o escritor {$w}");
            $processes[$w] = $proc;
            $pipes[$w] = $procPipes;
        }

        $failures = [];
        for ($w = 1; $w <= $writers; $w++) {
            $out = trim((string) stream_get_contents($pipes[$w][1]));
            $err = trim((string) stream_get_contents($pipes[$w][2]));
            fclose($pipes[$w][1]);
            fclose($pipes[$w][2]);
            $code = proc_close($processes[$w]);
            if ($code !== 0 || $out !== (string) $putsPerWriter) {
                $failures[] = "escritor {$w}: exit={$code} out='{$out}' err='{$err}'";
            }
        }
        $this->assertTrue($failures === [], 'Escritores filhos falharam: ' . implode(' | ', $failures));

        $final = new SingleFileRepository($path, ChildWriterEntity::class, PersistenceDefinition::ReadWrite, $ser);
        $expected = 1 + $writers * $putsPerWriter;
        $this->assertEquals($expected, $final->countAllEntities(), "Lost update entre processos: esperado {$expected} entidades no arquivo");
    }
}
