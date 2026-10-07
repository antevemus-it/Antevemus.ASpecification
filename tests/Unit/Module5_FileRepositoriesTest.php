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
use Antevemus\ASpecification\Contracts\Repositories\PersistenceDefinition;
use Antevemus\ASpecification\Specifications\Collection\AllEntitiesSpecification;
use Antevemus\ASpecification\Repositories\PersistentPartitionRepository;
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
