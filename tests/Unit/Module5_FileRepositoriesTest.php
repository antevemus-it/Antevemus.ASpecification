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
}
