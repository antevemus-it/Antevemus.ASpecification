<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Parity;

use Antevemus\ASpecification\Contracts\Repositories\PersistenceDefinition;
use Antevemus\ASpecification\Repositories\File\InMemoryAndFileRepository;
use Antevemus\ASpecification\Repositories\File\SingleFileRepository;
use Antevemus\ASpecification\Repositories\Serialization\JsonEntitySerializer;
use Antevemus\ASpecification\Specifications\Collection\AllEntitiesSpecification;
use Antevemus\ASpecification\Specifications\Collection\UniqueEntitySpecification;
use Antevemus\ASpecification\Tests\Support\WriteBehindEntity;
use PHPUnit\Framework\TestCase;

/**
 * DomianWriteBehindRepositoryTest - the Domian (Java) tests of the INMEMORY_AND_FILE repository
 * (InMemoryAndXStreamXmlFileRepository), transcribed over InMemoryAndFileRepository in MemoryAsyncFile.
 *
 * Sources (Domian trunk r1209, Apache License 2.0): the persistent-repository tests of domian-api and
 * domian-test-benchmark as they run over INMEMORY_AND_FILE: entityIdShouldBeGlobalAndPersistent,
 * shouldPersistAndLoadEntities, shouldRemovePersistedEntitiesBothWays,
 * ordinaryRepositryOperationsMightCancelStateChangesBeforeUpdateHasTakenPlace,
 * shouldUpdateMetaData_supportsAsynchronousPersistence and shouldHandleEmptyPersistentRepositories, plus
 * the mode of the repository (isMemoryBased(), supportsAsynchronousPersistence()) and "put() does not touch
 * the file". In Domian the repository writes `Entity[]` to one file and its metadata record to a second
 * one; here both live in the single JSON document (`entities` and `__repository_metadata`). Each test
 * names the Java test it transcribes. Until 2.8.0 these behaviours did not hold: the hybrid was
 * write-through in every mode.
 *
 * @version    2.8.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Parity
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class DomianWriteBehindRepositoryTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/domian_write_behind_' . uniqid();
        mkdir($this->directory, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->directory . '/*') as $file) {
            @unlink((string) $file);
        }
        @rmdir($this->directory);
    }

    /** A new INMEMORY_AND_FILE repository over the file, not loaded (Domian's constructor does not load). */
    private function repository(): InMemoryAndFileRepository
    {
        $serializer = new JsonEntitySerializer(WriteBehindEntity::class);

        return new InMemoryAndFileRepository(
            new SingleFileRepository($this->directory . '/entities.json', $serializer, PersistenceDefinition::MemoryAsyncFile),
            null,
            PersistenceDefinition::MemoryAsyncFile
        );
    }

    private function all(): AllEntitiesSpecification
    {
        return new AllEntitiesSpecification(WriteBehindEntity::class);
    }

    /** @return list<string> */
    private function titlesInFile(): array
    {
        $path = $this->directory . '/entities.json';
        if (!file_exists($path)) {
            return [];
        }
        $titles = array_map(static fn(array $entity): string => $entity['title'], json_decode((string) file_get_contents($path), true)['entities']);
        sort($titles);

        return $titles;
    }

    /** Java: PersistenceDefinition INMEMORY_AND_FILE (probe P7: isMemoryBased() and supportsAsynchronousPersistence() are true) */
    public function testTheRepositoryIsMemoryBasedWithAsynchronousPersistence(): void
    {
        $repository = $this->repository();
        self::assertSame(PersistenceDefinition::MemoryAsyncFile, $repository->getPersistenceDefinition());
        self::assertTrue($repository->isMemoryBased());
        self::assertTrue($repository->getPersistenceDefinition()->supportsAsynchronousPersistence());
        self::assertTrue($repository->getPersistenceDefinition()->isFileBased());
    }

    /** Java: INMEMORY_AND_FILE put()/remove() touch the memory only (probe P7: "entities in file" stays 0 until persist()) */
    public function testPutDoesNotTouchTheFileUntilPersist(): void
    {
        $repository = $this->repository();
        $repository->load();
        $repository->put(new WriteBehindEntity('one'));
        self::assertFileDoesNotExist($this->directory . '/entities.json');
        self::assertSame(1, $repository->count($this->all()));
        $repository->persist();
        self::assertSame(['one'], $this->titlesInFile());
    }

    /** Java: entityIdShouldBeGlobalAndPersistent */
    public function testEntityIdShouldBeGlobalAndPersistent(): void
    {
        $repository = $this->repository();
        $repository->load();
        $entity = new WriteBehindEntity('global');
        $repository->put($entity);
        $repository->persist();
        $repository->close();

        $reopened = $this->repository();
        $reopened->load();
        $found = $reopened->findSingle(new UniqueEntitySpecification($entity->getEntityId()));
        self::assertNotNull($found);
        self::assertSame((string) $entity->getEntityId(), (string) $found->getEntityId());
        self::assertNotSame($entity, $found);
    }

    /** Java: shouldPersistAndLoadEntities (a new INMEMORY_AND_FILE instance is empty until load()) */
    public function testShouldPersistAndLoadEntities(): void
    {
        $repository = $this->repository();
        $repository->load();
        $first = new WriteBehindEntity('first', 1);
        $second = new WriteBehindEntity('second', 2);
        $repository->putAll([$first, $second]);
        $repository->persist();
        $repository->close();

        $reopened = $this->repository();
        self::assertSame(0, $reopened->count($this->all()), 'empty before load()');
        $reopened->load();
        self::assertSame(2, $reopened->count($this->all()));
        $loaded = $reopened->findSingle(new UniqueEntitySpecification($second->getEntityId()));
        self::assertInstanceOf(WriteBehindEntity::class, $loaded);
        self::assertNotSame($second, $loaded);
        self::assertSame(2, $loaded->counter);
    }

    /** Java: shouldRemovePersistedEntitiesBothWays (load() before persist() brings the removed entities back) */
    public function testShouldRemovePersistedEntitiesBothWays(): void
    {
        $repository = $this->repository();
        $repository->load();
        $repository->putAll([new WriteBehindEntity('a'), new WriteBehindEntity('b'), new WriteBehindEntity('c')]);
        $repository->persist();

        self::assertSame(3, $repository->removeAll($this->all()));
        self::assertSame(0, $repository->count($this->all()));
        $repository->load();
        self::assertSame(3, $repository->count($this->all()), 'not persisted: load() recovers them');

        self::assertSame(3, $repository->removeAll($this->all()));
        $repository->persist();
        $repository->load();
        self::assertSame(0, $repository->count($this->all()), 'persisted: they are gone');
        self::assertSame([], $this->titlesInFile());
    }

    /** Java: ordinaryRepositryOperationsMightCancelStateChangesBeforeUpdateHasTakenPlace (load() discards what was not persisted) */
    public function testLoadCancelsStateChangesNotPersisted(): void
    {
        $repository = $this->repository();
        $repository->load();
        $entity = new WriteBehindEntity('before', 1);
        $repository->put($entity);
        $repository->persist();

        $entity->title = 'after';
        $entity->counter = 2;
        $repository->update($entity);
        self::assertSame('after', $repository->findSingle(new UniqueEntitySpecification($entity->getEntityId()))?->title);
        $repository->load();
        $reloaded = $repository->findSingle(new UniqueEntitySpecification($entity->getEntityId()));
        self::assertSame('before', $reloaded?->title);
        self::assertSame(1, $reloaded?->counter);
    }

    /** Java: shouldUpdateMetaData_supportsAsynchronousPersistence (the repository record: load = read, persist = write; put/find untouched) */
    public function testShouldUpdateMetaDataOfTheRepository(): void
    {
        $repository = $this->repository();
        $record = $repository->getRepositoryMetaData();
        self::assertSame([0, 0], [$record->getReadCount(), $record->getWriteCount()]);
        self::assertNull($record->getLastRead());
        self::assertNull($record->getLastWrite());

        $repository->load();
        $afterLoad = $repository->getRepositoryMetaData();
        self::assertSame([1, 0], [$afterLoad->getReadCount(), $afterLoad->getWriteCount()]);
        self::assertNotNull($afterLoad->getLastRead());
        self::assertNull($afterLoad->getLastWrite());

        $entity = new WriteBehindEntity('meta');
        $repository->put($entity);
        $repository->findAll($this->all());
        $repository->findSingle(new UniqueEntitySpecification($entity->getEntityId()));
        self::assertSame([1, 0], [$repository->getRepositoryMetaData()->getReadCount(), $repository->getRepositoryMetaData()->getWriteCount()], 'put/find do not touch it');

        $repository->persist();
        $afterPersist = $repository->getRepositoryMetaData();
        self::assertSame([1, 1], [$afterPersist->getReadCount(), $afterPersist->getWriteCount()]);
        self::assertNotNull($afterPersist->getLastWrite());
        self::assertGreaterThanOrEqual($afterLoad->getLastRead(), $afterPersist->getLastWrite());

        $repository->persist();
        $repository->load();
        $final = $repository->getRepositoryMetaData();
        self::assertSame([2, 2], [$final->getReadCount(), $final->getWriteCount()]);
        self::assertGreaterThanOrEqual($afterPersist->getLastWrite(), $final->getLastWrite());
        self::assertSame($final->getFirstRead()?->format('Y-m-d\TH:i:s.u'), $afterLoad->getFirstRead()?->format('Y-m-d\TH:i:s.u'), 'the first load is kept');

        $reopened = $this->repository();
        $reopened->load();
        self::assertSame([2, 2], [$reopened->getRepositoryMetaData()->getReadCount(), $reopened->getRepositoryMetaData()->getWriteCount()], 'the record stored by the last persist, plus this load');
    }

    /** Java: shouldHandleEmptyPersistentRepositories (load() of an empty repository is idempotent) */
    public function testShouldHandleEmptyPersistentRepositories(): void
    {
        $repository = $this->repository();
        $repository->load();
        $repository->load();
        self::assertSame(0, $repository->count($this->all()));
        $repository->persist();
        $repository->load();
        self::assertSame(0, $repository->count($this->all()));
        self::assertSame([], $this->titlesInFile());
        self::assertFalse($repository->isDirty());
    }
}
