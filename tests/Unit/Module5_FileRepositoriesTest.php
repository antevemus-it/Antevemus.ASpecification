<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\Tests\TestCase;
use Antevemus\ASpecification\Repositories\File\SingleFileRepository;
use Antevemus\ASpecification\Repositories\File\FilePerEntityRepository;
use Antevemus\ASpecification\Repositories\File\InMemoryAndFileRepository;
use Antevemus\ASpecification\Repositories\File\FileNameSanitizer;
use Antevemus\ASpecification\Repositories\Serialization\JsonEntitySerializer;
use Antevemus\ASpecification\Contracts\Repositories\Exceptions\RepositoryException;
use Antevemus\ASpecification\Contracts\Repositories\IEntityPersistenceMetaData;
use Antevemus\ASpecification\Contracts\Repositories\PersistenceDefinition;
use Antevemus\ASpecification\Specifications\Collection\AllEntitiesSpecification;
use Antevemus\ASpecification\Specifications\Collection\UniqueEntitySpecification;
use Antevemus\ASpecification\Repositories\PersistentPartitionRepository;
use Antevemus\ASpecification\Repositories\InMemoryRepository;
use Antevemus\ASpecification\Entities\AbstractUUIDEntity;
use Antevemus\ASpecification\Tests\Support\ChildWriterEntity;
use Antevemus\ASpecification\Tests\Support\CountingSpecification;
use Antevemus\ASpecification\Tests\Support\UpgradeOrder;
use Antevemus\ASpecification\Repositories\File\AbstractFileRepository;
use Antevemus\ASpecification\Contracts\Repositories\Serialization\IEntitySerializer;
use Antevemus\ASpecification\Contracts\Entities\IEntity;
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
            // 2.0.0 (forward 033, D8): sem serializer nativo, envelope JSON-only, .bin recusado.
            $this->testNativeSerializerIsGoneAndSrcHasNoUnserialize();
            $this->testBinEnvelopeIsRefusedWithoutInstantiatingAnything($tmp);
            $this->testV114BinFixturesAreRefusedWithTheUpgradeGuide($tmp);
            $this->testV114FixturesConvertedByTheRecipeAreReadBy200($tmp);
            $this->testNonJsonContentIsRefusedNotReadAsEmpty($tmp);
            $this->testEnvelopeIsJsonWhateverTheSerializer($tmp);
            $this->testTwoInstancesOnSameFileDoNotOverwriteEachOther($tmp);
            $this->testConcurrentProcessesDoNotLoseUpdates($tmp);
            $this->testReadmeExample5HybridFactory($tmp);
            $this->testHybridContainsWorksThroughTheCache($tmp);
            $this->testPersistenceEnvelopeIsIgnoredOnRead();
            $this->testSerializedDocumentCarriesNoPersistenceEnvelope($tmp);
            $this->testFileRepositoriesRecordSessionMetadata($tmp);
            // 2.0.0 (forward 033, D8; forward 020 RN-08; delta de dados do 006 §4): metadados no arquivo.
            $this->testPersistenceMetadataIsWrittenAndHydrated($tmp);
            $this->testReadsReachTheFileOnStoreAndClose($tmp);
            $this->testMalformedPersistenceMetadataIsRefused($tmp);

            // Forward 017 (v1.5.0), RN-04: iterate*() lazy nos repositórios de arquivo.
            $this->testRn04FileRepositoriesIterateLazily($tmp);

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

        // 2.0.0: o envelope é do repositório, que grava os metadados ao lado da entidade; o
        // serializer continua serializando só a entidade (acima) e o deserialize a recebe sem eles.
        $path = $tmp . '/no_envelope.json';
        $repo = new SingleFileRepository($path, TestFileEntity::class, PersistenceDefinition::ReadWrite, $ser);
        $repo->put($entity);
        $stored = json_decode((string) file_get_contents($path), true);
        $this->assertTrue(isset($stored['entities'][0]['__persistence_metadata']), 'o documento do repositório carrega os metadados');
        $this->assertFalse(array_key_exists('__is_persistent_envelope', $stored['entities'][0]));
        $this->assertEquals('Plain', $stored['entities'][0]['title']);
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
            // 2.0.0 (forward 020 RN-08, paridade Domian): a primeira gravação conta (antes: 0)
            $this->assertEquals(1, $meta->getWriteCount(), "{$label}: a criação conta como a primeira gravação");
            $this->assertTrue($meta->getFirstWrite() !== null, "{$label}: a criação marca a primeira gravação");
            $this->assertEquals(0, $meta->getReadCount());

            $repo->put($e);
            $this->assertEquals(2, $repo->getEntityMetaData($e)->getWriteCount(), "{$label}: segundo put() incrementa a escrita");

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

        // 2.0.0: nova instância sobre o mesmo arquivo herda os metadados gravados (antes: só da sessão);
        // carregar do disco não é uma leitura servida
        $persisted = new TestFileEntity('Persisted');
        $single->put($persisted);
        $reopened = new SingleFileRepository($tmp . '/meta_single.json', TestFileEntity::class, PersistenceDefinition::ReadWrite, $ser);
        $this->assertEquals(1, $reopened->getEntityMetaData($persisted)->getWriteCount(), 'metadados hidratados do arquivo');
        $this->assertEquals(0, $reopened->getEntityMetaData($persisted)->getReadCount(), 'carregar não conta leitura');
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

    /**
     * RN-04 (forward 017, v1.5.0): os repositórios de arquivo entregam generator de verdade.
     * SingleFileRepository: depois do load(), cada entidade é avaliada só quando puxada (antes,
     * findAll() materializava tudo e o generator só repassava o array). FilePerEntityRepository:
     * lê o diretório entrada a entrada (readdir), um arquivo por pull, sem scanEntityFiles()/glob.
     * InMemoryAndFileRepository: o generator do cache L1.
     */
    private function testRn04FileRepositoriesIterateLazily(string $tmp): void
    {
        $ser = new JsonEntitySerializer(TestFileEntity::class);
        $entities = [];
        for ($i = 0; $i < 20; $i++) {
            $entities[] = new TestFileEntity("lazy-{$i}");
        }

        // SingleFileRepository
        $single = new SingleFileRepository($tmp . '/rn04_single.json', TestFileEntity::class, PersistenceDefinition::ReadWrite, $ser);
        $single->putAll($entities);
        $reloaded = new SingleFileRepository($tmp . '/rn04_single.json', TestFileEntity::class, PersistenceDefinition::ReadWrite, $ser);
        $spec = new CountingSpecification(fn(TestFileEntity $e): bool => true);
        $iterator = $reloaded->iterate($spec);
        $this->assertTrue($iterator instanceof \Generator);
        $this->assertEquals(0, $spec->evaluations, 'nada avaliado antes do primeiro pull');
        $this->assertTrue($iterator->current() instanceof TestFileEntity);
        $this->assertEquals(1, $spec->evaluations, 'o primeiro item custa uma avaliação (antes: 20)');
        $this->assertCount(20, iterator_to_array($reloaded->iterate(new AllEntitiesSpecification()), false));

        // FilePerEntityRepository, instrumentado: conta listagens completas e leituras de arquivo
        $dir = new class($tmp . '/rn04_per_entity', TestFileEntity::class, PersistenceDefinition::ReadWrite, $ser) extends FilePerEntityRepository {
            public int $fullScans = 0;
            public int $fileReads = 0;
            protected function scanEntityFiles(): array
            {
                $this->fullScans++;
                return parent::scanEntityFiles();
            }
            protected function readEntityFromFile(string $filePath): ?\Antevemus\ASpecification\Contracts\Entities\IEntity
            {
                $this->fileReads++;
                return parent::readEntityFromFile($filePath);
            }
        };
        $dir->putAll($entities);
        file_put_contents($tmp . '/rn04_per_entity/notes.txt', 'outro tipo de arquivo: ignorado');
        $dir->fullScans = 0;
        $dir->fileReads = 0;

        $spec = new CountingSpecification(fn(TestFileEntity $e): bool => true);
        $iterator = $dir->iterate($spec);
        $this->assertEquals(0, $dir->fileReads, 'nenhum arquivo lido antes do primeiro pull');
        $this->assertTrue($iterator->current() instanceof TestFileEntity);
        $this->assertEquals(1, $dir->fileReads, 'um arquivo por entidade puxada');
        $this->assertEquals(1, $spec->evaluations);
        $iterator->next();
        $this->assertEquals(2, $dir->fileReads);
        $this->assertEquals(0, $dir->fullScans, 'a listagem do diretório nunca é materializada');

        $titles = [];
        foreach ($dir->iterate(new AllEntitiesSpecification()) as $entity) {
            $titles[] = $entity->title;
        }
        sort($titles);
        $expected = array_map(fn(TestFileEntity $e): string => $e->title, $entities);
        sort($expected);
        $this->assertEquals($expected, $titles, 'mesmo conjunto que findAll(), em ordem de diretório');
        $this->assertEquals(0, $dir->fullScans);

        // InMemoryAndFileRepository: generator do cache L1, primeiro item com uma avaliação
        $hybrid = new InMemoryAndFileRepository($reloaded);
        $hybrid->warmup();
        $spec = new CountingSpecification(fn(TestFileEntity $e): bool => true);
        $iterator = $hybrid->iterate($spec);
        $this->assertEquals(0, $spec->evaluations);
        $this->assertTrue($iterator->current() instanceof TestFileEntity);
        $this->assertEquals(1, $spec->evaluations);
        $hybrid->close();
    }

    // ------------------------------------------------------------------
    // 2.0.0 (forward 033, D8): serializer nativo removido, envelope JSON-only
    // ------------------------------------------------------------------

    /**
     * D8: a classe saiu e a meta do marco 13 vale como teste (`grep -rn unserialize src/` vazio,
     * inclusive em comentário).
     */
    private function testNativeSerializerIsGoneAndSrcHasNoUnserialize(): void
    {
        $this->assertFalse(
            class_exists('Antevemus\ASpecification\Repositories\Serialization\PhpNativeEntitySerializer'),
            'PhpNativeEntitySerializer foi removido na 2.0.0'
        );

        $hits = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src', RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->isFile() && str_contains((string) file_get_contents($file->getPathname()), 'unserialize')) {
                $hits[] = $file->getPathname();
            }
        }
        $this->assertEquals([], $hits, 'src/ não pode conter "unserialize" (marco 13): ' . implode(', ', $hits));
    }

    /**
     * BUG-20261007-HIJG (regressão, 2.0.0): o envelope .bin envenenado nem chega a ser lido; a
     * recusa vem antes de qualquer instanciação e aponta o guia de atualização.
     */
    private function testBinEnvelopeIsRefusedWithoutInstantiatingAnything(string $tmp): void
    {
        $binPath = $tmp . '/poisoned.bin';
        M5WakeupGadget::$woke = false;
        file_put_contents($binPath, serialize([
            '__meta'   => ['repository_id' => 'x', 'entity_type' => TestFileEntity::class, 'count' => 1],
            'entities' => [new M5WakeupGadget()],
        ]));

        $error = $this->assertThrows(RepositoryException::class, fn() => new SingleFileRepository(
            $binPath,
            TestFileEntity::class,
            PersistenceDefinition::ReadWrite,
            new JsonEntitySerializer(TestFileEntity::class)
        ));
        $this->assertTrue(str_contains($error->getMessage(), 'UPGRADE-2.0.md'), $error->getMessage());
        $this->assertFalse(M5WakeupGadget::$woke, 'nada do envelope .bin pode ser instanciado');
        $this->assertTrue(str_starts_with((string) file_get_contents($binPath), 'a:2:{'), 'o arquivo recusado fica intacto');
    }

    /**
     * Copia uma fixture (tests/Fixtures/file_repository/v1.14.0/...) para o diretório temporário: os
     * repositórios criam arquivos de lock ao lado do documento e a fixture versionada não pode sujar.
     */
    private function copyFixture(string $relative, string $target): string
    {
        $source = dirname(__DIR__) . '/Fixtures/file_repository/v1.14.0/' . $relative;
        if (is_dir($source)) {
            @mkdir($target, 0777, true);
            foreach (glob($source . '/*') as $file) {
                copy($file, $target . '/' . basename($file));
            }
        } else {
            @mkdir(dirname($target), 0777, true);
            copy($source, $target);
        }

        return $target;
    }

    /**
     * RN-04 (b): arquivos .bin gravados pela v1.14.0 real (PhpNativeEntitySerializer, fixtures
     * versionadas) são recusados pela 2.0.0 com a mensagem que cita o UPGRADE-2.0.md e a receita
     * na 1.14.x, nos dois repositórios de arquivo; nunca lidos como vazios.
     */
    private function testV114BinFixturesAreRefusedWithTheUpgradeGuide(string $tmp): void
    {
        $json = new JsonEntitySerializer(UpgradeOrder::class);
        $expectMessage = function (\Throwable $e, string $label): void {
            $this->assertTrue(str_contains($e->getMessage(), 'UPGRADE-2.0.md'), "{$label}: cita o guia: " . $e->getMessage());
            $this->assertTrue(str_contains($e->getMessage(), '1.14.x'), "{$label}: a conversão roda na 1.14.x");
            $this->assertTrue(str_contains($e->getMessage(), 'PhpNativeEntitySerializer'), "{$label}: nomeia o formato");
        };

        // SingleFileRepository: o envelope serialize() da 1.14.0, em qualquer modo que leia
        $single = $this->copyFixture('single/orders.bin', $tmp . '/v114/orders.bin');
        foreach ([PersistenceDefinition::ReadWrite, PersistenceDefinition::ReadOnly, PersistenceDefinition::Snapshot] as $mode) {
            $e = $this->assertThrows(RepositoryException::class, fn() => new SingleFileRepository($single, UpgradeOrder::class, $mode, $json));
            $expectMessage($e, "single {$mode->name}");
            $this->assertTrue(str_contains($e->getMessage(), $single), 'nomeia o arquivo');
        }
        $this->assertEquals(AbstractFileRepository::UPGRADE_GUIDE, 'UPGRADE-2.0.md');
        $this->assertTrue(is_file(dirname(__DIR__, 2) . '/' . AbstractFileRepository::UPGRADE_GUIDE), 'o guia citado existe na raiz do pacote');

        // FilePerEntityRepository: o diretório da 1.14.0 com <id>.bin
        $dir = $this->copyFixture('per_entity_bin', $tmp . '/v114/orders');
        $repo = new FilePerEntityRepository($dir, UpgradeOrder::class, PersistenceDefinition::ReadWrite, $json);
        $all = new AllEntitiesSpecification(UpgradeOrder::class);
        $expectMessage($this->assertThrows(RepositoryException::class, fn() => $repo->findAll($all)), 'dir findAll');
        $expectMessage($this->assertThrows(RepositoryException::class, fn() => iterator_to_array($repo->iterate($all))), 'dir iterate');
        $expectMessage($this->assertThrows(RepositoryException::class, fn() => $repo->countAllEntities()), 'dir count');

        $known = new UpgradeOrder('probe', 1, new \DateTimeImmutable());
        $idOf = basename((string) glob($dir . '/*.bin')[0], '.bin');
        $ref = new \ReflectionProperty(\Antevemus\ASpecification\Entities\AbstractUUIDEntity::class, 'entityId');
        $probe = (new \ReflectionClass(UpgradeOrder::class))->newInstanceWithoutConstructor();
        $ref->setValue($probe, $idOf);
        $expectMessage($this->assertThrows(RepositoryException::class, fn() => $repo->findSingle(new UniqueEntitySpecification($probe))), 'dir findSingle por id');
        $expectMessage($this->assertThrows(RepositoryException::class, fn() => $repo->contains($probe)), 'dir contains');
        $this->assertFalse($repo->contains($known), 'id sem .bin nem .json: simplesmente não existe');
        $this->assertCount(3, glob($dir . '/*.bin'), 'nada foi apagado nem regravado');
    }

    /**
     * RN-04 (a), última etapa na suíte: o JSON produzido pela receita do UPGRADE-2.0.md executada na
     * v1.14.0 real sobre as fixtures .bin (prova completa no relatório da E2) é lido pela 2.0.0 com
     * os mesmos valores: propriedades privadas e readonly, data com fuso, lista. Arquivo da 1.x não
     * traz metadados: getEntityMetaData() é null até a primeira leitura ou gravação.
     */
    private function testV114FixturesConvertedByTheRecipeAreReadBy200(string $tmp): void
    {
        $json = new JsonEntitySerializer(UpgradeOrder::class);
        $all = new AllEntitiesSpecification(UpgradeOrder::class);
        $describe = static fn(UpgradeOrder $o): string => implode('|', [$o->getCustomer(), $o->getAmountCents(), $o->getPlacedAt()->format(DATE_ATOM), implode(',', $o->tags)]);
        $expected = [
            'Ana|12345|2026-01-15T10:30:00-03:00|vip',
            'Bruno|990|2026-02-01T08:00:00+00:00|',
            'Carla|500000|2026-03-31T23:59:59-03:00|b2b,net30',
        ];

        $single = new SingleFileRepository($this->copyFixture('single/orders.json', $tmp . '/v114json/orders.json'), UpgradeOrder::class, PersistenceDefinition::ReadWrite, $json);
        $dir = new FilePerEntityRepository($this->copyFixture('per_entity_json', $tmp . '/v114json/orders'), UpgradeOrder::class, PersistenceDefinition::ReadWrite, $json);

        foreach (['single' => $single, 'per-entity' => $dir] as $label => $repo) {
            $orders = array_values($repo->findAll($all));
            $this->assertCount(3, $orders, "{$label}: as três entidades convertidas");
            $rows = array_map(static fn(UpgradeOrder $o): string => preg_replace('/^(single|per-entity) /', '', $describe($o)), $orders);
            sort($rows);
            $this->assertEquals($expected, $rows, "{$label}: mesmos valores que a 1.14.0 lia do .bin");
            foreach ($orders as $order) {
                $this->assertTrue((bool) preg_match('/^[0-9a-f-]{36}$/', (string) $order->getEntityId()), "{$label}: id preservado");
            }
        }

        // Sem metadados no arquivo da 1.x; a 2.0.0 passa a gravá-los na próxima escrita
        $order = array_values($dir->findAll($all))[0];
        $this->assertEquals(2, $dir->getEntityMetaData($order)->getReadCount(), 'duas leituras servidas nesta instância');
        $this->assertTrue($dir->getEntityMetaData($order)->getFirstWrite() === null, 'histórico de escrita desconhecido, não "agora"');
        $dir->put($order);
        $file = json_decode((string) file_get_contents($dir->getFilePath($order->getEntityId())), true);
        $this->assertEquals(1, $file['__persistence_metadata']['write_count']);
        $this->assertEquals(2, $file['__persistence_metadata']['read_count']);
        $this->assertEquals($order->getCustomer(), $file['customer'], 'a entidade continua no mesmo documento');
    }

    /**
     * 2.0.0 (Fixed): conteúdo que não é um documento JSON de entidades era lido como repositório vazio e
     * sobrescrito pela próxima gravação; agora é recusado e o arquivo fica intacto.
     */
    private function testNonJsonContentIsRefusedNotReadAsEmpty(string $tmp): void
    {
        $ser = new JsonEntitySerializer(TestFileEntity::class);
        foreach (['garbage' => "not json at all\n", 'list' => '[1, 2, 3]', 'object' => '{"rows": []}'] as $label => $content) {
            $path = $tmp . "/corrupt_{$label}.json";
            file_put_contents($path, $content);
            $e = $this->assertThrows(RepositoryException::class, fn() => new SingleFileRepository($path, TestFileEntity::class, PersistenceDefinition::ReadWrite, $ser));
            $this->assertTrue(str_contains($e->getMessage(), 'not a single-file entity document'), "{$label}: " . $e->getMessage());
            $this->assertEquals($content, file_get_contents($path), "{$label}: arquivo intacto");
        }

        // Vazio continua sendo repositório vazio; JSON da 1.x (sem metadados) continua legível
        file_put_contents($tmp . '/empty.json', "  \n");
        $this->assertEquals(0, (new SingleFileRepository($tmp . '/empty.json', TestFileEntity::class, PersistenceDefinition::ReadWrite, $ser))->countAllEntities());
        $old = new TestFileEntity('one-dot-x');
        file_put_contents($tmp . '/v1x.json', json_encode(['__meta' => ['count' => 1], 'entities' => [json_decode($ser->serialize($old), true)]]));
        $repo = new SingleFileRepository($tmp . '/v1x.json', TestFileEntity::class, PersistenceDefinition::ReadWrite, $ser);
        $this->assertTrue($repo->getEntityMetaData($old) === null, 'documento da 1.x não traz metadados');
        $this->assertEquals(1, $repo->countAllEntities());
    }

    /**
     * D8: o envelope é sempre JSON e do repositório, qualquer que seja o serializer; a saída de um
     * serializer que não produz objeto JSON vai como string, sem metadados, e volta intacta.
     */
    private function testEnvelopeIsJsonWhateverTheSerializer(string $tmp): void
    {
        $custom = new class implements IEntitySerializer {
            public function serialize(IEntity $entity): string
            {
                return 'title=' . $entity->title . ';id=' . $entity->getEntityId();
            }
            public function deserialize(string $data, string $entityClass): IEntity
            {
                parse_str(str_replace(';', '&', $data), $fields);
                $entity = (new \ReflectionClass(TestFileEntity::class))->newInstanceWithoutConstructor();
                (new \ReflectionProperty(\Antevemus\ASpecification\Entities\AbstractUUIDEntity::class, 'entityId'))->setValue($entity, (string) $fields['id']);
                $entity->title = (string) $fields['title'];
                return $entity;
            }
            public function getContentType(): string
            {
                return 'text/plain';
            }
            public function getFileExtension(): string
            {
                return 'txt';
            }
        };

        $path = $tmp . '/custom_serializer.store';
        $repo = new SingleFileRepository($path, TestFileEntity::class, PersistenceDefinition::ReadWrite, $custom);
        $entity = new TestFileEntity('custom');
        $repo->put($entity);

        $document = json_decode((string) file_get_contents($path), true);
        $this->assertTrue(is_array($document), 'o envelope é JSON mesmo com serializer não-JSON');
        $this->assertEquals('title=custom;id=' . $entity->getEntityId(), $document['entities'][0], 'a entidade vai como string');
        $this->assertEquals(1, $document['__meta']['count']);

        $reopened = new SingleFileRepository($path, TestFileEntity::class, PersistenceDefinition::ReadWrite, $custom);
        $back = array_values($reopened->findAll(new AllEntitiesSpecification()))[0];
        $this->assertEquals('custom', $back->title);
        $this->assertEquals((string) $entity->getEntityId(), (string) $back->getEntityId());
        $this->assertTrue($reopened->getEntityMetaData($entity)->getFirstWrite() === null, 'sem objeto JSON não há onde gravar metadados: só os da sessão');
    }

    // ------------------------------------------------------------------
    // 2.0.0: __persistence_metadata gravado e hidratado (delta 006 §4, forward 020 RN-08)
    // ------------------------------------------------------------------

    /**
     * Os dois repositórios de arquivo gravam o bloco com os nomes reais da EntityPersistenceMetaData,
     * e outra instância (outro processo) o hidrata: contadores e datas sobrevivem ao reabrir.
     */
    private function testPersistenceMetadataIsWrittenAndHydrated(string $tmp): void
    {
        $ser = new JsonEntitySerializer(TestFileEntity::class);
        $all = new AllEntitiesSpecification(TestFileEntity::class);
        $keys = ['read_count', 'write_count', 'first_read_at', 'last_read_at', 'first_write_at', 'last_write_at'];
        @mkdir($tmp . '/meta2', 0777, true);

        $factories = [
            'single' => fn(): SingleFileRepository => new SingleFileRepository($tmp . '/meta2/single.json', TestFileEntity::class, PersistenceDefinition::ReadWrite, $ser),
            'per-entity' => fn(): FilePerEntityRepository => new FilePerEntityRepository($tmp . '/meta2/dir', TestFileEntity::class, PersistenceDefinition::ReadWrite, $ser),
        ];
        foreach ($factories as $label => $open) {
            $repo = $open();
            $e = new TestFileEntity("meta {$label}");
            $repo->put($e);
            $repo->put($e);
            $first = $repo->getEntityMetaData($e)->getFirstWrite();

            $raw = $label === 'single'
                ? json_decode((string) file_get_contents($tmp . '/meta2/single.json'), true)['entities'][0]
                : json_decode((string) file_get_contents($repo->getFilePath($e->getEntityId())), true);
            $this->assertEquals($keys, array_keys($raw['__persistence_metadata']), "{$label}: nomes reais da EntityPersistenceMetaData");
            $this->assertEquals(2, $raw['__persistence_metadata']['write_count'], "{$label}: duas gravações");
            $this->assertEquals(0, $raw['__persistence_metadata']['read_count']);
            $this->assertTrue($raw['__persistence_metadata']['first_read_at'] === null);
            $this->assertEquals("meta {$label}", $raw['title'], "{$label}: o bloco fica ao lado dos campos da entidade");

            $other = $open();
            $meta = $other->getEntityMetaData($e);
            $this->assertTrue($meta !== null, "{$label}: outra instância hidrata o registro");
            $this->assertEquals(2, $meta->getWriteCount());
            $this->assertEquals($first->format('Y-m-d\TH:i:s.uP'), $meta->getFirstWrite()->format('Y-m-d\TH:i:s.uP'), "{$label}: data com microssegundos preservada");
            $this->assertEquals(0, $meta->getReadCount(), "{$label}: hidratar não conta leitura");

            $other->put($e);
            $this->assertEquals(3, $other->getEntityMetaData($e)->getWriteCount(), "{$label}: a gravação continua a contagem persistida");
            $found = array_values($other->findAll($all));
            $this->assertEquals("meta {$label}", $found[0]->title, "{$label}: o bloco não vaza para a entidade");
            $other->close();
        }
    }

    /**
     * Leituras servidas chegam ao arquivo com a próxima gravação, store() ou close(); no ReadWrite o
     * close() relê o documento sob lock e não apaga o que outra instância gravou nesse meio-tempo.
     */
    private function testReadsReachTheFileOnStoreAndClose(string $tmp): void
    {
        $ser = new JsonEntitySerializer(TestFileEntity::class);
        $all = new AllEntitiesSpecification(TestFileEntity::class);

        // SingleFileRepository (ReadWrite)
        $path = $tmp . '/reads/single.json';
        @mkdir($tmp . '/reads', 0777, true);
        $reader = new SingleFileRepository($path, TestFileEntity::class, PersistenceDefinition::ReadWrite, $ser);
        $a = new TestFileEntity('A');
        $reader->put($a);
        $reader->findAll($all);
        $reader->findAll($all);
        $writer = new SingleFileRepository($path, TestFileEntity::class, PersistenceDefinition::ReadWrite, $ser);
        $b = new TestFileEntity('B');
        $writer->put($b);
        $reader->close();
        $check = new SingleFileRepository($path, TestFileEntity::class, PersistenceDefinition::ReadOnly, $ser);
        $this->assertEquals(2, $check->getEntityMetaData($a)->getReadCount(), 'as leituras do leitor chegaram ao arquivo no close()');
        $this->assertEquals(1, $check->getEntityMetaData($b)->getWriteCount());
        $this->assertEquals(2, $check->countAllEntities(), 'close() do leitor não apagou B (gravado por outra instância depois que ele carregou)');

        // FilePerEntityRepository: store()/close() gravam o bloco das entidades lidas
        $dirPath = $tmp . '/reads/dir';
        $dir = new FilePerEntityRepository($dirPath, TestFileEntity::class, PersistenceDefinition::ReadWrite, $ser);
        $c = new TestFileEntity('C');
        $dir->put($c);
        $dir->findAll($all);
        $onDisk = fn(): array => json_decode((string) file_get_contents($dir->getFilePath($c->getEntityId())), true)['__persistence_metadata'];
        $this->assertEquals(0, $onDisk()['read_count'], 'a leitura ainda não foi gravada');
        $dir->store();
        $this->assertEquals(1, $onDisk()['read_count'], 'store() grava as leituras servidas');
        $this->assertEquals(1, $onDisk()['write_count'], 'sem contar uma gravação da entidade');
        $dir->findSingle(new UniqueEntitySpecification($c));
        $dir->close();
        $this->assertEquals(2, $onDisk()['read_count'], 'close() também');
        $this->assertEquals('C', json_decode((string) file_get_contents($dir->getFilePath($c->getEntityId())), true)['title']);
    }

    /** Um bloco de metadados malformado é recusado, nunca trocado por um registro vazio em silêncio. */
    private function testMalformedPersistenceMetadataIsRefused(string $tmp): void
    {
        $ser = new JsonEntitySerializer(TestFileEntity::class);
        $e = new TestFileEntity('bad meta');
        $entity = json_decode($ser->serialize($e), true);
        foreach (['not an object' => 'x', 'negative' => ['write_count' => -1], 'text count' => ['read_count' => '3'], 'bad date' => ['first_write_at' => 'yesterday-ish']] as $label => $block) {
            $path = $tmp . '/badmeta/' . md5($label) . '.json';
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, json_encode(['entities' => [$entity + ['__persistence_metadata' => $block]]]));
            $error = $this->assertThrows(RepositoryException::class, fn() => new SingleFileRepository($path, TestFileEntity::class, PersistenceDefinition::ReadWrite, $ser));
            $this->assertTrue(str_contains($error->getMessage(), '__persistence_metadata') && str_contains($error->getMessage(), (string) $e->getEntityId()), "{$label}: " . $error->getMessage());
        }
    }
}
