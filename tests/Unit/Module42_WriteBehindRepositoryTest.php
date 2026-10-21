<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\Contracts\Repositories\Exceptions\RepositoryException;
use Antevemus\ASpecification\Contracts\Repositories\IPersistentRepository;
use Antevemus\ASpecification\Contracts\Repositories\IWriteBehindBackend;
use Antevemus\ASpecification\Contracts\Repositories\PersistenceDefinition;
use Antevemus\ASpecification\Entities\AbstractEntity;
use Antevemus\ASpecification\Repositories\File\FilePerEntityRepository;
use Antevemus\ASpecification\Repositories\File\InMemoryAndFileRepository;
use Antevemus\ASpecification\Repositories\File\SingleFileRepository;
use Antevemus\ASpecification\Repositories\PersistentPartitionRepository;
use Antevemus\ASpecification\Repositories\RepositoryPersistenceMetaData;
use Antevemus\ASpecification\Repositories\Serialization\JsonEntitySerializer;
use Antevemus\ASpecification\Repositories\VolatilePartitionRepository;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\Collection\AllEntitiesSpecification;
use Antevemus\ASpecification\Specifications\Collection\UniqueEntitySpecification;
use Antevemus\ASpecification\Tests\Support\ArmedFailingSerializer;
use Antevemus\ASpecification\Tests\Support\ReadmeBlocks;
use Antevemus\ASpecification\Tests\Support\RecordingPersistentStore;
use Antevemus\ASpecification\Tests\Support\WriteBehindEntity;
use Antevemus\ASpecification\Tests\Support\WriteBehindEntity as Customer;
use Antevemus\ASpecification\Tests\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** An entity whose id is chosen by the test (the `_repository` collision of FilePerEntityRepository). */
final class Module42NamedEntity extends AbstractEntity
{
    public function __construct(private string $id, public string $title = '')
    {
        parent::__construct();
    }

    public function getEntityId(): string
    {
        return $this->id;
    }
}

/**
 * Module42_WriteBehindRepositoryTest - the write-behind hybrid repository (roadmap milestone 23, the Domian
 * INMEMORY_AND_FILE mode), v2.8.0.
 *
 * Covers:
 * - the modes of before 2.8.0 unchanged (ReadWrite write-through, Snapshot, ReadOnly; a document of 2.6.1
 *   loads, a write-through instance keeps the record of a write-behind one);
 * - MemoryAsyncFile: writes in the cache only, counted as pending; persist()/store() write the whole cache;
 *   load() discards what was not persisted; close() persists when dirty; persistEvery(); persistOnShutdown()
 *   in a child process (normal end, no registration, SIGKILL, repository dropped);
 * - durability with injected failures (a temporary file that cannot be created, a serializer that throws,
 *   a delegated store that fails): the exception reaches the caller, the cache and the pending count are
 *   intact, the previous document is byte-identical, a later persist() writes everything;
 * - a property: any sequence of puts, updates and removals, persisted and reloaded, equals the write-through
 *   result (single file and one file per entity), with its broken control;
 * - the repository record (RepositoryPersistenceMetaData) in the single document and in `_repository.json`,
 *   malformed and future records refused; MemoryAsyncDelegated over a backend that is not a file; the
 *   volatile partition; example 5.1 of docs/<language>/repositories/persistence-and-cache.md, the same code
 *   in both languages and run as written; a guard over the public API of the new and changed classes.
 *
 * @version    2.8.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Unit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class Module42_WriteBehindRepositoryTest extends TestCase
{
    /** Seed of the property runs (fixed: a failure is reproducible). */
    private const SEED = 42042;

    /**
     * The public methods this module exercises, per class (the guard compares them with the methods each
     * class declares: a new public method fails the suite until it is tested and listed here).
     */
    private const COVERED_API = [
        InMemoryAndFileRepository::class => [
            '__construct', 'create', 'getMemoryCache', 'getFileBackend', 'getRepositoryId', 'getPersistenceDefinition', 'getType',
            'warmup', 'isWarmedUp', 'getDataDirectory', 'getFormatDescription', 'getEntityMetaData', 'isMemoryBased', 'isDirty',
            'pendingWrites', 'persistEvery', 'persistOnShutdown', 'getRepositoryMetaData', 'persist', 'load', 'store', 'close', 'put',
            'putAll', 'update', 'updateWithDelta', 'remove', 'removeAllEntitiesSpecifiedBy', 'clear', 'findAllEntitiesSpecifiedBy',
            'countAllEntitiesSpecifiedBy', 'iterateAllEntitiesSpecifiedBy', 'contains', 'getEntitySpecifiedBy', 'countAllEntities',
            'makePartition',
        ],
        RepositoryPersistenceMetaData::class => [
            '__construct', 'restore', 'fromArray', 'toArray', 'getFormatVersion', 'getLastLoad', 'getLastPersist', 'getReadCount',
            'getWriteCount', 'getFirstRead', 'getLastRead', 'getFirstWrite', 'getLastWrite', 'registerRead', 'registerWrite',
        ],
        IWriteBehindBackend::class => ['persistSnapshot', 'getRepositoryMetaData'],
    ];

    private string $tmp;

    private AllEntitiesSpecification $all;

    public function run(): void
    {
        $this->tmp = sys_get_temp_dir() . '/test_aspec_m42_' . uniqid();
        mkdir($this->tmp, 0777, true);
        $this->all = new AllEntitiesSpecification(WriteBehindEntity::class);

        try {
            $this->testWriteThroughModesAnswerAsBefore();
            $this->testDocumentsOfBeforeTheRecordLoadAndKeepIt();
            $this->testWriteBehindWritesTouchOnlyTheCache();
            $this->testPersistWritesTheWholeCacheAndCountsTheRecord();
            $this->testLoadDiscardsWhatWasNotPersisted();
            $this->testCloseAndPersistEvery();
            $this->testPersistOnShutdownInAChildProcess();
            $this->testDurabilityWhenTheTemporaryFileCannotBeCreated();
            $this->testDurabilityWhenTheSerializationFails();
            $this->testDurabilityOfOneFilePerEntity();
            $this->testDurabilityOfADelegatedStore();
            $this->testPerEntityMetadataCountsOneWritePerPersist();
            $this->testFilePerEntityWriteBehindAndItsRecordFile();
            $this->testRecordFileNameIsReservedOnlyWhenItIsTheRecord();
            $this->testMalformedRecordsAreRefused();
            $this->testMemoryAsyncDelegatedOverABackendThatIsNotAFile();
            $this->testPartitionOfAWriteBehindRepository();
            $this->testAccessorsAndRefusals();
            $this->testEdgesOfTheWritePaths();
            $this->testPropertyWriteBehindEqualsWriteThrough();
            $this->testExample5_1BlocksAreTheSameInBothLanguages();
            $this->testExample5_1RunsAsWritten();
            $this->testPublicApiGuard();
        } finally {
            self::removeTree($this->tmp);
        }
    }

    // ==========================================
    // The modes of before 2.8.0 (RN-01)
    // ==========================================

    private function testWriteThroughModesAnswerAsBefore(): void
    {
        $path = $this->tmp . '/rw/customers.json';
        $repo = InMemoryAndFileRepository::create($path, new JsonEntitySerializer(WriteBehindEntity::class));
        $alice = new WriteBehindEntity('Alice');
        $repo->put($alice);

        $this->assertEquals(['Alice'], $this->titlesInFile($path), 'ReadWrite: put() writes the file in the same call');
        $this->assertFalse($repo->isDirty(), 'ReadWrite: never dirty');
        $this->assertEquals(0, $repo->pendingWrites(), 'ReadWrite: nothing pending');
        $this->assertFalse($repo->isMemoryBased(), 'ReadWrite is not memory based (Domian FILE)');
        $this->assertEquals(['__meta', 'entities'], array_keys($this->document($path)), 'ReadWrite writes the document of 2.6.1: no repository record');
        $before = (string) file_get_contents($path);
        $repo->persist();
        $this->assertEquals(['__meta', 'entities'], array_keys($this->document($path)), 'ReadWrite: persist() is store()');
        $this->assertEquals(json_decode($before, true)['entities'], $this->document($path)['entities'], 'ReadWrite: persist() rewrites the same entities');
        $this->assertInstanceOf(PersistentPartitionRepository::class, $repo->makePartition(), 'ReadWrite: persistent partition, as before');
        $this->assertEquals([0, 0, null, null], $this->recordCounts($repo->getRepositoryMetaData()), 'ReadWrite: no record in the file, an empty one');

        $e = $this->assertThrows(RepositoryException::class, fn() => $repo->persistEvery(10));
        $this->assertTrue(str_contains($e->getMessage(), 'persistEvery() needs a write-behind hybrid repository') && str_contains($e->getMessage(), 'ReadWrite'), $e->getMessage());
        $e = $this->assertThrows(RepositoryException::class, fn() => $repo->persistOnShutdown());
        $this->assertTrue(str_contains($e->getMessage(), 'persistOnShutdown() needs a write-behind hybrid repository'), $e->getMessage());
        $repo->remove($alice);
        $this->assertEquals([], $this->titlesInFile($path), 'ReadWrite: remove() reaches the file in the same call');
        $repo->close();

        // Snapshot: the backend keeps the write in memory until store(); the hybrid has nothing pending.
        $snapshotPath = $this->tmp . '/snapshot/customers.json';
        $snapshot = InMemoryAndFileRepository::create($snapshotPath, new JsonEntitySerializer(WriteBehindEntity::class), persistenceDefinition: PersistenceDefinition::Snapshot);
        $snapshot->put(new WriteBehindEntity('Bob'));
        $this->assertFalse(file_exists($snapshotPath), 'Snapshot: the file waits for store()');
        $this->assertEquals(0, $snapshot->pendingWrites(), 'Snapshot is not write-behind');
        $this->assertInstanceOf(PersistentPartitionRepository::class, $snapshot->makePartition(), 'Snapshot: persistent partition, as before');
        $snapshot->store();
        $this->assertEquals(['Bob'], $this->titlesInFile($snapshotPath), 'Snapshot: store() writes');
        $this->assertEquals(['__meta', 'entities'], array_keys($this->document($snapshotPath)), 'Snapshot writes no repository record');
        $snapshot->close();

        $readOnly = InMemoryAndFileRepository::create($snapshotPath, new JsonEntitySerializer(WriteBehindEntity::class), persistenceDefinition: PersistenceDefinition::ReadOnly);
        $this->assertEquals(1, $readOnly->countAllEntities(), 'ReadOnly reads');
        $this->assertThrows(RepositoryException::class, fn() => $readOnly->put(new WriteBehindEntity('refused')));
        $this->assertThrows(RepositoryException::class, fn() => $readOnly->persistEvery(1));
    }

    private function testDocumentsOfBeforeTheRecordLoadAndKeepIt(): void
    {
        // A document as 2.6.1 writes it (no __repository_metadata) loads in write-behind; the first load starts the record.
        $path = $this->tmp . '/old/customers.json';
        $writer = InMemoryAndFileRepository::create($path, new JsonEntitySerializer(WriteBehindEntity::class));
        $writer->putAll([new WriteBehindEntity('A'), new WriteBehindEntity('B')]);
        $writer->close();
        $this->assertEquals(['__meta', 'entities'], array_keys($this->document($path)));

        $behind = InMemoryAndFileRepository::create($path, new JsonEntitySerializer(WriteBehindEntity::class), persistenceDefinition: PersistenceDefinition::MemoryAsyncFile);
        $this->assertEquals(2, $behind->countAllEntities(), 'the document of before the record loads');
        $this->assertEquals([1, 0, 'load', null], $this->recordCounts($behind->getRepositoryMetaData()), 'the first load starts the record');
        $behind->put(new WriteBehindEntity('C'));
        $behind->persist();
        $stored = $this->document($path)[RepositoryPersistenceMetaData::KEY];
        $this->assertEquals([1, 1, 1], [$stored['format_version'], $stored['read_count'], $stored['write_count']], 'persist() stores the record with the document');
        $this->assertEquals(['__meta', RepositoryPersistenceMetaData::KEY, 'entities'], array_keys($this->document($path)), 'the record sits between __meta and entities');

        // A write-through instance over that document keeps the record as it was, and reports it unchanged.
        $through = InMemoryAndFileRepository::create($path, new JsonEntitySerializer(WriteBehindEntity::class));
        $through->put(new WriteBehindEntity('D'));
        $this->assertEquals($stored, $this->document($path)[RepositoryPersistenceMetaData::KEY], 'a ReadWrite write keeps the record of the write-behind instance');
        $this->assertEquals([1, 1, 'load', 'persist'], $this->recordCounts($through->getRepositoryMetaData()), 'ReadWrite reports the stored record without counting');
        $this->assertEquals(['A', 'B', 'C', 'D'], $this->titlesInFile($path));
        $through->close();
        $this->assertEquals($stored, $this->document($path)[RepositoryPersistenceMetaData::KEY], 'close() of the write-through instance keeps it too');
    }

    // ==========================================
    // MemoryAsyncFile: the cache first, the file on persist()
    // ==========================================

    private function testWriteBehindWritesTouchOnlyTheCache(): void
    {
        $path = $this->tmp . '/wb/customers.json';
        $repo = InMemoryAndFileRepository::create($path, new JsonEntitySerializer(WriteBehindEntity::class), persistenceDefinition: PersistenceDefinition::MemoryAsyncFile);
        $this->assertTrue($repo->isMemoryBased(), 'MemoryAsyncFile is memory based (Domian INMEMORY_AND_FILE)');
        $this->assertTrue($repo->getPersistenceDefinition()->supportsAsynchronousPersistence());
        $this->assertFalse($repo->isDirty(), 'clean after create()');

        $a = new WriteBehindEntity('A', 1);
        $b = new WriteBehindEntity('B', 2);
        $c = new WriteBehindEntity('C', 3);
        $d = new WriteBehindEntity('D', 4);
        $repo->put($a);
        $this->assertEquals(1, $repo->pendingWrites(), 'put() counts one');
        $repo->putAll([$b, $c, $d]);
        $this->assertEquals(4, $repo->pendingWrites(), 'putAll() counts one per entity');
        $a->counter = 10;
        $repo->update($a);
        $this->assertEquals(5, $repo->pendingWrites(), 'update() counts one');
        $repo->updateWithDelta($b, Spec::property('title', Spec::equalTo('B2')));
        $this->assertEquals('B2', $b->title, 'updateWithDelta() applies the delta');
        $this->assertEquals(6, $repo->pendingWrites(), 'updateWithDelta() counts one');
        $this->assertTrue($repo->remove($c), 'remove() of a cached entity');
        $this->assertEquals(7, $repo->pendingWrites(), 'remove() counts one');
        $this->assertFalse($repo->remove($c), 'remove() of an entity no longer there');
        $this->assertEquals(7, $repo->pendingWrites(), 'a remove() that finds nothing counts nothing');
        $this->assertEquals(0, $repo->removeAll(Spec::property('counter', Spec::greaterThan(100))), 'removeAll() of nothing');
        $this->assertEquals(7, $repo->pendingWrites());
        $repo->putAll([]);
        $this->assertEquals(7, $repo->pendingWrites(), 'putAll([]) counts nothing');
        $this->assertFalse(file_exists($path), 'no write reached the file');

        // Queries answer from the cache, pending writes included.
        $this->assertTrue($repo->isDirty());
        $this->assertEquals(3, $repo->countAllEntities());
        $this->assertEquals(3, $repo->count($this->all));
        $this->assertTrue($repo->contains($a) && !$repo->contains($c));
        $this->assertEquals(10, $repo->findSingle(new UniqueEntitySpecification($a->getEntityId()))?->counter);
        $this->assertEquals('B2', $repo->getEntitySpecifiedBy(new UniqueEntitySpecification($b->getEntityId()))?->title);
        $this->assertEquals(3, count(iterator_to_array($repo->iterate($this->all), false)));
        $this->assertEquals(['A', 'B2', 'D'], self::titles($repo->findAll($this->all)));

        $repo->persist();
        $this->assertFalse($repo->isDirty());
        $this->assertEquals(0, $repo->pendingWrites());
        $this->assertEquals(['A', 'B2', 'D'], $this->titlesInFile($path), 'persist() writes the cache');
        $bytes = (string) file_get_contents($path);

        $this->assertEquals(2, $repo->removeAll(Spec::property('counter', Spec::greaterThan(3))), 'removeAll() in the cache');
        $this->assertEquals(2, $repo->pendingWrites(), 'removeAll() counts the entities removed');
        $repo->clear();
        $this->assertEquals(3, $repo->pendingWrites(), 'clear() counts the entities it removed');
        $repo->clear();
        $this->assertEquals(3, $repo->pendingWrites(), 'clear() of an empty cache counts nothing');
        $this->assertEquals(0, $repo->countAllEntities(), 'the cache is empty');
        $this->assertEquals($bytes, (string) file_get_contents($path), 'removeAll() and clear() did not touch the file');
        $repo->persist();
        $this->assertEquals([], $this->titlesInFile($path), 'persist() of an empty cache empties the document');
        $repo->close();
    }

    private function testPersistWritesTheWholeCacheAndCountsTheRecord(): void
    {
        $path = $this->tmp . '/record/customers.json';
        $repo = new InMemoryAndFileRepository(
            new SingleFileRepository($path, new JsonEntitySerializer(WriteBehindEntity::class), PersistenceDefinition::MemoryAsyncFile),
            null,
            PersistenceDefinition::MemoryAsyncFile
        );
        $this->assertEquals([0, 0, null, null], $this->recordCounts($repo->getRepositoryMetaData()), 'never loaded nor persisted');
        $this->assertFalse($repo->isWarmedUp(), 'the constructor does not load');
        $repo->load();
        $this->assertTrue($repo->isWarmedUp());
        $this->assertEquals([1, 0, 'load', null], $this->recordCounts($repo->getRepositoryMetaData()), 'load() counts a read');
        $repo->put(new WriteBehindEntity('A'));
        $this->assertEquals([1, 0, 'load', null], $this->recordCounts($repo->getRepositoryMetaData()), 'put() does not touch the record');
        $repo->findAll($this->all);
        $this->assertEquals([1, 0, 'load', null], $this->recordCounts($repo->getRepositoryMetaData()), 'a query does not touch the record');
        $repo->persist();
        $record = $repo->getRepositoryMetaData();
        $this->assertEquals([1, 1, 'load', 'persist'], $this->recordCounts($record), 'persist() counts a write');
        $this->assertTrue($record->getFirstWrite() == $record->getLastPersist(), 'first and last persist are the same instant after one persist');
        $this->assertEquals(RepositoryPersistenceMetaData::FORMAT_VERSION, $record->getFormatVersion());
        $repo->store();
        $this->assertEquals([1, 2, 'load', 'persist'], $this->recordCounts($repo->getRepositoryMetaData()), 'store() is persist(), even with nothing pending');
        $this->assertTrue($repo->getRepositoryMetaData()->getLastPersist() >= $record->getLastPersist(), 'the last persist moves forward');
        $this->assertTrue($record !== $repo->getRepositoryMetaData(), 'each persist works on a copy of the record');
        $this->assertEquals(2, $this->document($path)[RepositoryPersistenceMetaData::KEY]['write_count'], 'the stored record follows');
        $stored = $this->document($path)[RepositoryPersistenceMetaData::KEY];
        $this->assertEquals(['format_version', 'read_count', 'write_count', 'first_read_at', 'last_read_at', 'first_write_at', 'last_write_at'], array_keys($stored), 'the fields of the stored record');
        $this->assertTrue(RepositoryPersistenceMetaData::fromArray($stored, 'test')->toArray() === $stored, 'the stored record reads back to itself');
        $this->assertTrue((new \DateTimeImmutable($stored['last_write_at'])) == $repo->getRepositoryMetaData()->getLastWrite(), 'timestamps with microseconds survive the round trip');

        $record = $repo->getRepositoryMetaData();
        $this->assertTrue($record->getFirstRead() == $record->getLastLoad() && $record->getLastRead() === $record->getLastLoad(), 'getLastLoad() is the last read');
        $this->assertTrue($record->getLastWrite() === $record->getLastPersist(), 'getLastPersist() is the last write');
        $fixed = new \DateTimeImmutable('2026-10-21T10:00:00.000001+00:00');
        $copy = clone $record;
        $copy->registerRead($fixed);
        $copy->registerWrite($fixed);
        $this->assertTrue($copy->getLastLoad() === $fixed && $copy->getLastPersist() === $fixed && $copy->getFirstRead() == $record->getFirstRead(), 'register*() keeps the first and moves the last');
        $repo->close();
    }

    private function testLoadDiscardsWhatWasNotPersisted(): void
    {
        $path = $this->tmp . '/discard/customers.json';
        $repo = InMemoryAndFileRepository::create($path, new JsonEntitySerializer(WriteBehindEntity::class), persistenceDefinition: PersistenceDefinition::MemoryAsyncFile);
        $kept = new WriteBehindEntity('kept', 1);
        $repo->put($kept);
        $repo->persist();

        $kept->counter = 99;
        $repo->update($kept);
        $lost = new WriteBehindEntity('lost');
        $repo->put($lost);
        $this->assertEquals(2, $repo->countAllEntities());
        $repo->load();

        $this->assertFalse($repo->isDirty(), 'load() clears the pending count');
        $this->assertEquals(1, $repo->countAllEntities(), 'the put not persisted is discarded');
        $this->assertFalse($repo->contains($lost));
        $reloaded = $repo->findSingle(new UniqueEntitySpecification($kept->getEntityId()));
        $this->assertTrue($reloaded instanceof WriteBehindEntity && $reloaded !== $kept, 'the cache holds the stored entity, a new object');
        $this->assertEquals(1, $reloaded->counter, 'the update not persisted is discarded');

        $repo->remove($reloaded);
        $repo->load();
        $this->assertEquals(1, $repo->countAllEntities(), 'a removal not persisted is discarded too');
        $repo->close();
    }

    private function testCloseAndPersistEvery(): void
    {
        $path = $this->tmp . '/close/customers.json';
        $repo = InMemoryAndFileRepository::create($path, new JsonEntitySerializer(WriteBehindEntity::class), persistenceDefinition: PersistenceDefinition::MemoryAsyncFile);
        $repo->close();
        $this->assertFalse(file_exists($path), 'close() of a clean repository writes nothing');
        $repo->put(new WriteBehindEntity('A'));
        $repo->close();
        $this->assertEquals(['A'], $this->titlesInFile($path), 'close() persists a dirty repository');
        $this->assertFalse($repo->isDirty());
        $bytes = (string) file_get_contents($path);
        $repo->close();
        $this->assertEquals($bytes, (string) file_get_contents($path), 'a second close() writes nothing');

        $this->assertTrue($repo->persistEvery(3) === $repo, 'persistEvery() is fluent');
        $repo->put(new WriteBehindEntity('B'));
        $repo->put(new WriteBehindEntity('C'));
        $this->assertEquals(2, $repo->pendingWrites());
        $this->assertEquals(['A'], $this->titlesInFile($path), 'below the threshold nothing is written');
        $repo->put(new WriteBehindEntity('D'));
        $this->assertEquals(0, $repo->pendingWrites(), 'the third write persists');
        $this->assertEquals(['A', 'B', 'C', 'D'], $this->titlesInFile($path));
        $repo->putAll([new WriteBehindEntity('E'), new WriteBehindEntity('F'), new WriteBehindEntity('G'), new WriteBehindEntity('H')]);
        $this->assertEquals(0, $repo->pendingWrites(), 'a putAll() past the threshold persists once, after the batch');
        $this->assertEquals(8, count($this->titlesInFile($path)));
        $this->assertEquals(3, $repo->getRepositoryMetaData()->getWriteCount(), 'three persists: the close() of a dirty repository and two automatic ones');
        $repo->persistEvery(0);
        for ($i = 0; $i < 5; $i++) {
            $repo->put(new WriteBehindEntity("off {$i}"));
        }
        $this->assertEquals(5, $repo->pendingWrites(), 'persistEvery(0) turns it off');
        $e = $this->assertThrows(\InvalidArgumentException::class, fn() => $repo->persistEvery(-1));
        $this->assertTrue(str_contains($e->getMessage(), '-1 given'), $e->getMessage());
        $repo->persistEvery(1);
        $repo->remove($repo->findAll($this->all)[0]);
        $this->assertEquals(0, $repo->pendingWrites(), 'persistEvery(1) persists after a removal too');
        $this->assertEquals(12, count($this->titlesInFile($path)));
        $repo->close();
    }

    private function testPersistOnShutdownInAChildProcess(): void
    {
        if (!function_exists('proc_open')) {
            echo "\n    [notice] proc_open unavailable: persistOnShutdown() child processes skipped.\n";
            return;
        }
        $expected = ['shutdown' => ['child-1', 'child-2', 'child-3'], 'none' => null, 'kill' => null, 'dropped' => null];
        foreach ($expected as $mode => $titles) {
            $path = $this->tmp . "/shutdown-{$mode}/customers.json";
            [$output, $exit] = self::runChild($path, $mode, 3);
            $this->assertEquals('3', $output, "{$mode}: the child had 3 pending writes");
            $this->assertEquals($mode === 'kill' ? 'signal 9' : 'exit 0', $exit, "{$mode}: how the child ended");
            if ($titles === null) {
                $this->assertFalse(file_exists($path), "{$mode}: nothing persisted");
                continue;
            }
            $this->assertEquals($titles, $this->titlesInFile($path), "{$mode}: persisted at the end of the script");
            $this->assertEquals(1, $this->document($path)[RepositoryPersistenceMetaData::KEY]['write_count'], "{$mode}: one persist (registered once)");
        }
    }

    // ==========================================
    // Durability with injected failures (RN-03)
    // ==========================================

    private function testDurabilityWhenTheTemporaryFileCannotBeCreated(): void
    {
        // The temporary file is "<document>.tmp.<8 hex>": with a 250-byte file name the document (and its
        // .lock) can exist and the temporary file cannot (255 bytes is the limit of a name), as root too.
        $directory = $this->tmp . '/longname';
        mkdir($directory);
        $path = $directory . '/' . str_repeat('c', 245) . '.json';
        $seed = InMemoryAndFileRepository::create($this->tmp . '/longname-seed.json', new JsonEntitySerializer(WriteBehindEntity::class), persistenceDefinition: PersistenceDefinition::MemoryAsyncFile);
        $seed->putAll([new WriteBehindEntity('stored 1'), new WriteBehindEntity('stored 2')]);
        $seed->persist();
        $this->assertTrue(copy($this->tmp . '/longname-seed.json', $path), 'the previous document, under the long name');
        $previous = (string) file_get_contents($path);

        $repo = InMemoryAndFileRepository::create($path, new JsonEntitySerializer(WriteBehindEntity::class), persistenceDefinition: PersistenceDefinition::MemoryAsyncFile);
        $this->assertEquals(2, $repo->countAllEntities(), 'the long name is readable');
        $repo->put(new WriteBehindEntity('pending'));
        $recordBefore = $repo->getRepositoryMetaData()->toArray();
        $e = $this->assertThrows(RepositoryException::class, fn() => $repo->persist());
        $this->assertTrue(str_contains($e->getMessage(), 'Failed to open the temporary file descriptor'), $e->getMessage());
        $this->assertEquals($previous, (string) file_get_contents($path), 'the previous document is byte-identical');
        $this->assertEquals(0, count((array) glob($directory . '/*.tmp.*')), 'no temporary file left');
        $this->assertTrue($repo->isDirty(), 'still dirty');
        $this->assertEquals(1, $repo->pendingWrites(), 'the pending count is intact');
        $this->assertEquals(['pending', 'stored 1', 'stored 2'], self::titles($repo->findAll($this->all)), 'the cache is intact');
        $this->assertEquals($recordBefore, $repo->getRepositoryMetaData()->toArray(), 'the record did not count the failed persist');
        $this->assertThrows(RepositoryException::class, fn() => $repo->close());
        $this->assertTrue($repo->isDirty(), 'close() reports the same failure and keeps the cache');
    }

    private function testDurabilityWhenTheSerializationFails(): void
    {
        $path = $this->tmp . '/armed/customers.json';
        $serializer = new ArmedFailingSerializer(WriteBehindEntity::class);
        $backend = new SingleFileRepository($path, $serializer, PersistenceDefinition::MemoryAsyncFile);
        $repo = new InMemoryAndFileRepository($backend, null, PersistenceDefinition::MemoryAsyncFile);
        $repo->load();
        $repo->putAll([new WriteBehindEntity('one'), new WriteBehindEntity('two')]);
        $repo->persist();
        $previous = (string) file_get_contents($path);
        $backendRecord = $backend->getRepositoryMetaData();
        $recordBefore = $repo->getRepositoryMetaData()->toArray();
        $one = $repo->findSingle(Spec::property('title', Spec::equalTo('one')));
        $metadataBefore = $backend->getEntityMetaData($one)?->getWriteCount();

        $repo->put(new WriteBehindEntity('three'));
        $one->counter = 7;
        $repo->update($one);
        $serializer->failOnTitle = 'three';
        $e = $this->assertThrows(\RuntimeException::class, fn() => $repo->persist());
        $this->assertTrue(str_contains($e->getMessage(), 'injected failure'), $e->getMessage());
        $this->assertEquals($previous, (string) file_get_contents($path), 'the previous document is byte-identical');
        $this->assertTrue($repo->isDirty() && $repo->pendingWrites() === 2, 'still dirty, 2 pending');
        $this->assertEquals(['one', 'three', 'two'], self::titles($repo->findAll($this->all)), 'the cache is intact');
        $this->assertEquals($recordBefore, $repo->getRepositoryMetaData()->toArray(), 'the record is unchanged');
        $this->assertTrue($backend->getRepositoryMetaData() === $backendRecord, 'the backend restored its record');
        $this->assertEquals(2, $backend->countTotal(), 'the backend restored its entities');
        $this->assertEquals($metadataBefore, $backend->getEntityMetaData($one)?->getWriteCount(), 'the backend restored the per-entity records');

        $serializer->failOnTitle = null;
        $repo->persist();
        $this->assertFalse($repo->isDirty(), 'the next persist() succeeds');
        $this->assertEquals(['one', 'three', 'two'], $this->titlesInFile($path), 'nothing pending was lost');
        $this->assertEquals(7, $this->entitiesInFile($path)['one']['counter'], 'the update reached the file');
        $this->assertEquals($recordBefore['write_count'] + 1, $repo->getRepositoryMetaData()->getWriteCount(), 'one write for the successful persist only');
        $this->assertEquals($metadataBefore + 1, $backend->getEntityMetaData($one)?->getWriteCount(), 'one per-entity write for the successful persist only');
        $repo->close();
    }

    private function testDurabilityOfOneFilePerEntity(): void
    {
        $directory = $this->tmp . '/armed-fpe';
        $serializer = new ArmedFailingSerializer(WriteBehindEntity::class);
        $repo = new InMemoryAndFileRepository(new FilePerEntityRepository($directory, $serializer, PersistenceDefinition::MemoryAsyncFile), null, PersistenceDefinition::MemoryAsyncFile);
        $repo->load();
        $repo->putAll([new WriteBehindEntity('one'), new WriteBehindEntity('two')]);
        $repo->persist();
        $record = (string) file_get_contents($directory . '/' . FilePerEntityRepository::REPOSITORY_RECORD_FILE);

        $repo->put(new WriteBehindEntity('three'));
        $serializer->failOnTitle = 'three';
        $this->assertThrows(\RuntimeException::class, fn() => $repo->persist());
        $this->assertTrue($repo->isDirty(), 'still dirty');
        $this->assertEquals(3, $repo->countAllEntities(), 'the cache is intact');
        $this->assertEquals($record, (string) file_get_contents($directory . '/' . FilePerEntityRepository::REPOSITORY_RECORD_FILE), 'the record file is written last: unchanged');

        $serializer->failOnTitle = null;
        $repo->persist();
        $fresh = new FilePerEntityRepository($directory, new JsonEntitySerializer(WriteBehindEntity::class));
        $this->assertEquals(['one', 'three', 'two'], self::titles($fresh->findAll($this->all)), 'the directory holds the cache after the retry');
        $repo->close();
    }

    private function testDurabilityOfADelegatedStore(): void
    {
        $store = new RecordingPersistentStore();
        $repo = new InMemoryAndFileRepository($store, null, PersistenceDefinition::MemoryAsyncDelegated);
        $repo->load();
        $repo->put(new WriteBehindEntity('one'));
        $store->failOnStore = true;
        $this->assertThrows(\RuntimeException::class, fn() => $repo->persist());
        $this->assertTrue($repo->isDirty() && $repo->pendingWrites() === 1, 'still dirty');
        $this->assertEquals(0, $repo->getRepositoryMetaData()->getWriteCount(), 'the record did not count it');
        $store->failOnStore = false;
        $repo->persist();
        $this->assertFalse($repo->isDirty());
        $this->assertEquals(1, $store->count($this->all), 'the store holds the cache');
        $repo->close();
    }

    // ==========================================
    // Per-entity records, one file per entity, the record file
    // ==========================================

    private function testPerEntityMetadataCountsOneWritePerPersist(): void
    {
        $path = $this->tmp . '/per-entity/customers.json';
        $repo = InMemoryAndFileRepository::create($path, new JsonEntitySerializer(WriteBehindEntity::class), persistenceDefinition: PersistenceDefinition::MemoryAsyncFile);
        $a = new WriteBehindEntity('a');
        $b = new WriteBehindEntity('b');
        $repo->putAll([$a, $b]);
        $this->assertTrue($repo->getEntityMetaData($a) === null, 'no record before the first persist');
        $repo->put($a);
        $repo->put($a);
        $repo->persist();
        $this->assertEquals(1, $repo->getEntityMetaData($a)?->getWriteCount(), 'three puts, one persist: one write');
        $repo->put($b);
        $repo->persist();
        $this->assertEquals([1, 2], [$repo->getEntityMetaData($a)?->getWriteCount(), $repo->getEntityMetaData($b)?->getWriteCount()], 'an entity not written since keeps its record');
        $this->assertEquals(2, $this->entitiesInFile($path)['b'][SingleFileRepository::PERSISTENCE_METADATA_KEY]['write_count'], 'the per-entity record is in the file');
        $repo->remove($a);
        $repo->persist();
        $this->assertTrue($repo->getEntityMetaData($a) === null, 'a removed entity loses its record');
        $repo->close();
    }

    private function testFilePerEntityWriteBehindAndItsRecordFile(): void
    {
        $directory = $this->tmp . '/fpe';
        $backend = new FilePerEntityRepository($directory, new JsonEntitySerializer(WriteBehindEntity::class), PersistenceDefinition::MemoryAsyncFile);
        $repo = new InMemoryAndFileRepository($backend, null, PersistenceDefinition::MemoryAsyncFile);
        $repo->load();
        $this->assertTrue($backend->getRepositoryMetaData() === null, 'an empty directory has no record');
        $a = new WriteBehindEntity('a');
        $b = new WriteBehindEntity('b');
        $repo->putAll([$a, $b]);
        $this->assertEquals([], self::filesIn($directory), 'put() writes no file');
        $repo->persist();
        $recordFile = $backend->getRepositoryRecordPath();
        $this->assertEquals($directory . '/' . FilePerEntityRepository::REPOSITORY_RECORD_FILE, $recordFile);
        $this->assertEquals(3, count(self::filesIn($directory)), 'two entity files and the record');
        $this->assertEquals(1, json_decode((string) file_get_contents($recordFile), true)[RepositoryPersistenceMetaData::KEY]['write_count']);
        $this->assertEquals(2, $backend->countTotal(), 'the record file is not an entity');
        $this->assertEquals(2, $backend->countAllEntities());
        $this->assertEquals(2, count(iterator_to_array($backend->iterate($this->all), false)), 'nor in the lazy iteration');

        $repo->remove($a);
        $this->assertEquals(3, count(self::filesIn($directory)), 'remove() deletes no file');
        $repo->close();
        $this->assertEquals(2, count(self::filesIn($directory)), 'the persist of close() deletes the file of the removed entity');
        $this->assertFalse(file_exists($backend->getFilePath($a->getEntityId())));

        $again = new InMemoryAndFileRepository(new FilePerEntityRepository($directory, new JsonEntitySerializer(WriteBehindEntity::class), PersistenceDefinition::MemoryAsyncFile), null, PersistenceDefinition::MemoryAsyncFile);
        $this->assertEquals(0, $again->countAllEntities(), 'a new instance is empty until load() (Domian)');
        $again->load();
        $this->assertEquals(['b'], self::titles($again->findAll($this->all)));
        $this->assertEquals([2, 2], [$again->getRepositoryMetaData()->getReadCount(), $again->getRepositoryMetaData()->getWriteCount()], 'the record of _repository.json, plus this load');
        $again->close();
    }

    private function testRecordFileNameIsReservedOnlyWhenItIsTheRecord(): void
    {
        // Without a record, `_repository.json` is an ordinary entity file, as before 2.8.0.
        $directory = $this->tmp . '/fpe-named';
        $plain = new FilePerEntityRepository($directory, new JsonEntitySerializer(Module42NamedEntity::class));
        $plain->put(new Module42NamedEntity('_repository', 'an entity'));
        $plain->put(new Module42NamedEntity('other', 'another'));
        $named = new AllEntitiesSpecification(Module42NamedEntity::class);
        $this->assertEquals(2, $plain->countAllEntities(), 'the entity "_repository" is listed');
        $this->assertEquals('an entity', $plain->findSingle(new UniqueEntitySpecification('_repository'))?->title);
        $this->assertTrue($plain->getRepositoryMetaData() === null, 'and it is not a record');
        $plain->load();
        $this->assertTrue($plain->getRepositoryMetaData() === null, 'load() does not read an entity as the record');

        // Once the directory holds the record, the name is the record's.
        $recordDirectory = $this->tmp . '/fpe-reserved';
        $repo = new InMemoryAndFileRepository(new FilePerEntityRepository($recordDirectory, new JsonEntitySerializer(Module42NamedEntity::class), PersistenceDefinition::MemoryAsyncFile), null, PersistenceDefinition::MemoryAsyncFile);
        $repo->load();
        $repo->put(new Module42NamedEntity('first'));
        $repo->persist();
        $repo->put(new Module42NamedEntity('_repository', 'collides'));
        $e = $this->assertThrows(RepositoryException::class, fn() => $repo->persist());
        $this->assertTrue(str_contains($e->getMessage(), 'the repository record of'), $e->getMessage());
        $this->assertTrue($repo->isDirty(), 'the refused persist leaves the cache dirty');
        $direct = new FilePerEntityRepository($recordDirectory, new JsonEntitySerializer(Module42NamedEntity::class));
        $this->assertThrows(RepositoryException::class, fn() => $direct->put(new Module42NamedEntity('_repository')));
        $this->assertEquals(1, $direct->count($named), 'the record is not an entity');
    }

    private function testMalformedRecordsAreRefused(): void
    {
        $path = $this->tmp . '/malformed/customers.json';
        $writer = InMemoryAndFileRepository::create($path, new JsonEntitySerializer(WriteBehindEntity::class), persistenceDefinition: PersistenceDefinition::MemoryAsyncFile);
        $writer->put(new WriteBehindEntity('a'));
        $writer->persist();
        $document = $this->document($path);

        $cases = [
            'not an object' => ['value' => 'text', 'message' => 'expected an object, got string'],
            'negative count' => ['value' => ['read_count' => -1] + $document[RepositoryPersistenceMetaData::KEY], 'message' => 'read_count must be a non-negative integer'],
            'future format' => ['value' => ['format_version' => 2] + $document[RepositoryPersistenceMetaData::KEY], 'message' => 'format_version 2 is not readable by this version (1 to 1)'],
            'format zero' => ['value' => ['format_version' => 0] + $document[RepositoryPersistenceMetaData::KEY], 'message' => 'format_version 0 is not readable'],
            'bad timestamp' => ['value' => ['last_read_at' => 'zz-invalid-zz'] + $document[RepositoryPersistenceMetaData::KEY], 'message' => 'last_read_at "zz-invalid-zz" is not a readable timestamp'],
            'timestamp type' => ['value' => ['first_write_at' => 12] + $document[RepositoryPersistenceMetaData::KEY], 'message' => 'first_write_at must be an ISO 8601 timestamp or null'],
            'string count' => ['value' => ['write_count' => '3'] + $document[RepositoryPersistenceMetaData::KEY], 'message' => 'write_count must be a non-negative integer'],
        ];
        foreach ($cases as $label => $case) {
            $broken = $document;
            $broken[RepositoryPersistenceMetaData::KEY] = $case['value'];
            file_put_contents($path, json_encode($broken));
            $e = $this->assertThrows(RepositoryException::class, fn() => InMemoryAndFileRepository::create($path, new JsonEntitySerializer(WriteBehindEntity::class), persistenceDefinition: PersistenceDefinition::MemoryAsyncFile));
            $this->assertTrue(str_contains($e->getMessage(), 'Invalid __repository_metadata in ' . $path) && str_contains($e->getMessage(), $case['message']), "{$label}: {$e->getMessage()}");
        }

        // Defaults: a record with only some fields reads the others as zero / null / the current format.
        $partial = RepositoryPersistenceMetaData::fromArray(['read_count' => 2], 'test');
        $this->assertEquals([2, 0, null, null, 1], [$partial->getReadCount(), $partial->getWriteCount(), $partial->getFirstWrite(), $partial->getLastRead(), $partial->getFormatVersion()]);
        $e = $this->assertThrows(\InvalidArgumentException::class, fn() => RepositoryPersistenceMetaData::restore(0, -1, null, null, null, null));
        $this->assertTrue(str_contains($e->getMessage(), 'read_count 0, write_count -1'), $e->getMessage());
        $e = $this->assertThrows(\InvalidArgumentException::class, fn() => RepositoryPersistenceMetaData::restore(-1, 0, null, null, null, null));
        $this->assertTrue(str_contains($e->getMessage(), 'read_count -1'), $e->getMessage());
        $e = $this->assertThrows(\InvalidArgumentException::class, fn() => RepositoryPersistenceMetaData::restore(0, 0, null, null, null, null, 2));
        $this->assertTrue(str_contains($e->getMessage(), 'Unknown repository persistence metadata format 2 (this version reads 1 to 1)'), $e->getMessage());
        $this->assertThrows(\InvalidArgumentException::class, fn() => RepositoryPersistenceMetaData::restore(0, 0, null, null, null, null, 0));
        $this->assertEquals(1, RepositoryPersistenceMetaData::restore(0, 0, null, null, null, null, 1)->getFormatVersion());

        // A malformed _repository.json is refused by load() too.
        $directory = $this->tmp . '/malformed-fpe';
        mkdir($directory);
        file_put_contents($directory . '/' . FilePerEntityRepository::REPOSITORY_RECORD_FILE, json_encode([RepositoryPersistenceMetaData::KEY => ['read_count' => 'x']]));
        $backend = new FilePerEntityRepository($directory, new JsonEntitySerializer(WriteBehindEntity::class));
        $e = $this->assertThrows(RepositoryException::class, fn() => $backend->load());
        $this->assertTrue(str_contains($e->getMessage(), FilePerEntityRepository::REPOSITORY_RECORD_FILE), $e->getMessage());
    }

    private function testMemoryAsyncDelegatedOverABackendThatIsNotAFile(): void
    {
        $store = new RecordingPersistentStore();
        $repo = new InMemoryAndFileRepository($store, null, PersistenceDefinition::MemoryAsyncDelegated);
        $this->assertTrue($repo->isMemoryBased());
        $repo->load();
        $this->assertEquals(['load'], $store->calls);
        $kept = new WriteBehindEntity('kept');
        $repo->putAll([$kept, new WriteBehindEntity('temporary')]);
        $repo->remove($repo->findSingle(Spec::property('title', Spec::equalTo('temporary'))));
        $this->assertEquals(['load'], $store->calls, 'writes stay in the cache');
        $this->assertEquals(0, $store->count($this->all));
        $repo->persist();
        $this->assertEquals(['load', 'putAll', 'store'], $store->calls, 'persist(): removeAll + putAll + store');
        $this->assertEquals(['kept'], self::titles($store->findAll($this->all)));
        $this->assertEquals([1, 1], [$repo->getRepositoryMetaData()->getReadCount(), $repo->getRepositoryMetaData()->getWriteCount()], 'the record is kept in memory');

        $repo->put(new WriteBehindEntity('discarded'));
        $repo->load();
        $this->assertEquals(['kept'], self::titles($repo->findAll($this->all)), 'load() discards what was not persisted');
        $this->assertEquals(2, $repo->getRepositoryMetaData()->getReadCount(), 'the record kept in memory counts the load');
        $repo->put(new WriteBehindEntity('at close'));
        $repo->close();
        $this->assertEquals(['load', 'putAll', 'store', 'load', 'putAll', 'store', 'close'], $store->calls, 'close(): persist, then close of the store');
        $this->assertEquals(['at close', 'kept'], self::titles($store->findAll($this->all)));
        $this->assertTrue($repo->getRepositoryMetaData()->getWriteCount() === 2);
    }

    private function testPartitionOfAWriteBehindRepository(): void
    {
        $path = $this->tmp . '/partition/customers.json';
        $repo = InMemoryAndFileRepository::create($path, new JsonEntitySerializer(WriteBehindEntity::class), persistenceDefinition: PersistenceDefinition::MemoryAsyncFile);
        $partition = $repo->makePartition(Spec::property('counter', Spec::greaterThan(10)));
        $this->assertInstanceOf(VolatilePartitionRepository::class, $partition, 'D6: a volatile partition');
        $this->assertFalse($partition instanceof IPersistentRepository, 'no load()/store()/close() of its own');
        $partition->put(new WriteBehindEntity('big', 20));
        $this->assertEquals(1, $repo->pendingWrites(), 'a write through the partition is pending in the repository');
        $this->assertFalse(file_exists($path));
        $this->assertEquals(1, $partition->count(new AllEntitiesSpecification(WriteBehindEntity::class)));
        $repo->persist();
        $this->assertEquals(['big'], $this->titlesInFile($path), 'it reaches the file with the persist() of the repository');
        $repo->close();
    }

    private function testAccessorsAndRefusals(): void
    {
        $path = $this->tmp . '/accessors/customers.json';
        $repo = InMemoryAndFileRepository::create($path, new JsonEntitySerializer(WriteBehindEntity::class), persistenceDefinition: PersistenceDefinition::MemoryAsyncFile);
        $this->assertInstanceOf(\Antevemus\ASpecification\Repositories\InMemoryRepository::class, $repo->getMemoryCache());
        $this->assertInstanceOf(SingleFileRepository::class, $repo->getFileBackend());
        $this->assertEquals(PersistenceDefinition::MemoryAsyncFile, $repo->getFileBackend()->getPersistenceDefinition(), 'create() gives the backend the same mode');
        $this->assertEquals('hybrid_customers.json', $repo->getRepositoryId());
        $this->assertEquals($this->tmp . '/accessors', $repo->getDataDirectory());
        $this->assertEquals('Hybrid InMemory / File Storage (application/json)', $repo->getFormatDescription());
        $this->assertEquals(WriteBehindEntity::class, $repo->getType());
        $this->assertTrue($repo->persistOnShutdown() === $repo && $repo->persistOnShutdown() === $repo, 'persistOnShutdown() is fluent and registers once');
        $repo->put(new WriteBehindEntity('closed before the end'));
        $repo->close();
        $this->assertFalse($repo->isDirty(), 'closed: nothing left for the shutdown function');

        $this->assertThrows(RepositoryException::class, fn() => new InMemoryAndFileRepository(new \Antevemus\ASpecification\Repositories\InMemoryRepository(), new \Antevemus\ASpecification\Repositories\InMemoryRepository()));
        $reversed = new InMemoryAndFileRepository(new \Antevemus\ASpecification\Repositories\InMemoryRepository(), new SingleFileRepository($this->tmp . '/accessors/reversed.json', new JsonEntitySerializer(WriteBehindEntity::class)), PersistenceDefinition::MemoryAsyncFile);
        $this->assertInstanceOf(SingleFileRepository::class, $reversed->getFileBackend(), 'the cache first, the backend second');
        file_put_contents($this->tmp . '/accessors/a-file', 'x');
        $e = $this->assertThrows(RepositoryException::class, fn() => InMemoryAndFileRepository::create($this->tmp . '/accessors/a-file/sub/customers.json', new JsonEntitySerializer(WriteBehindEntity::class)));
        $this->assertTrue(str_contains($e->getMessage(), 'Unable to create the storage directory'), $e->getMessage());

        // A backend that persists nothing (Transient) receives the snapshot and writes no file.
        foreach ([
            new SingleFileRepository($this->tmp . '/accessors/transient.json', new JsonEntitySerializer(WriteBehindEntity::class), PersistenceDefinition::Transient),
            new FilePerEntityRepository($this->tmp . '/accessors/transient-dir', new JsonEntitySerializer(WriteBehindEntity::class), PersistenceDefinition::Transient),
        ] as $backend) {
            $transient = new InMemoryAndFileRepository($backend, null, PersistenceDefinition::MemoryAsyncFile);
            $transient->load();
            $transient->put(new WriteBehindEntity('volatile'));
            $transient->persist();
            $this->assertFalse($transient->isDirty(), get_class($backend) . ' Transient: the persist() succeeds');
            $this->assertFalse(file_exists($this->tmp . '/accessors/transient.json'), get_class($backend) . ' Transient: no file');
        }
        $this->assertEquals([], self::filesIn($this->tmp . '/accessors/transient-dir'), 'Transient: the directory stays empty');

        // A ReadOnly backend refuses the snapshot.
        $readOnly = new SingleFileRepository($path, new JsonEntitySerializer(WriteBehindEntity::class), PersistenceDefinition::ReadOnly);
        $this->assertThrows(RepositoryException::class, fn() => $readOnly->persistSnapshot([], [], new RepositoryPersistenceMetaData()));
        $readOnlyDirectory = new FilePerEntityRepository($this->tmp . '/accessors/ro-dir', new JsonEntitySerializer(WriteBehindEntity::class), PersistenceDefinition::ReadOnly);
        $this->assertThrows(RepositoryException::class, fn() => $readOnlyDirectory->persistSnapshot([], [], new RepositoryPersistenceMetaData()));
    }

    private function testEdgesOfTheWritePaths(): void
    {
        $serializer = new JsonEntitySerializer(WriteBehindEntity::class);

        // Snapshot: persist() is the store() of the backend.
        $snapshotPath = $this->tmp . '/edges/snapshot.json';
        $snapshot = InMemoryAndFileRepository::create($snapshotPath, $serializer, persistenceDefinition: PersistenceDefinition::Snapshot);
        $snapshot->put(new WriteBehindEntity('snap'));
        $snapshot->persist();
        $this->assertEquals(['snap'], $this->titlesInFile($snapshotPath), 'Snapshot: persist() writes the file');

        // ReadOnly: every write is refused by the hybrid before it touches the cache.
        $readOnly = InMemoryAndFileRepository::create($snapshotPath, $serializer, persistenceDefinition: PersistenceDefinition::ReadOnly);
        $stored = $readOnly->findAll($this->all)[0];
        $writes = [
            'put' => fn() => $readOnly->put(new WriteBehindEntity('refused')),
            'putAll' => fn() => $readOnly->putAll([new WriteBehindEntity('refused')]),
            'update' => fn() => $readOnly->update(new WriteBehindEntity('refused')),
            'remove' => fn() => $readOnly->remove($stored),
            'removeAll' => fn() => $readOnly->removeAll($this->all),
            'clear' => fn() => $readOnly->clear(),
        ];
        foreach ($writes as $operation => $write) {
            $e = $this->assertThrows(RepositoryException::class, $write);
            $this->assertTrue(str_contains($e->getMessage(), "hybrid repository 'hybrid_snapshot.json' is in ReadOnly mode"), "{$operation}: {$e->getMessage()}");
            $this->assertEquals(1, $readOnly->countAllEntities(), "ReadOnly {$operation}: the cache is untouched");
        }

        // Write-through remove(): true when only the file still had the entity (written by another instance).
        $sharedPath = $this->tmp . '/edges/shared.json';
        $first = InMemoryAndFileRepository::create($sharedPath, $serializer);
        $second = InMemoryAndFileRepository::create($sharedPath, $serializer);
        $late = new WriteBehindEntity('late');
        $second->put($late);
        $this->assertFalse($first->contains($late), 'the first cache does not have it');
        $this->assertTrue($first->remove($late), 'removed from the file: true');
        $this->assertEquals([], $this->titlesInFile($sharedPath));

        // The constructor: a backend and a cache in either order, two backends refused.
        $cache = new \Antevemus\ASpecification\Repositories\InMemoryRepository();
        $withCache = new InMemoryAndFileRepository(new SingleFileRepository($this->tmp . '/edges/cache.json', $serializer), $cache, PersistenceDefinition::MemoryAsyncFile);
        $this->assertTrue($withCache->getMemoryCache() === $cache, 'the given cache is the cache');
        $withCache->put(new WriteBehindEntity('in the given cache'));
        $this->assertEquals(1, $cache->count($this->all));
        $e = $this->assertThrows(RepositoryException::class, fn() => new InMemoryAndFileRepository(new SingleFileRepository($this->tmp . '/edges/a.json', $serializer), new SingleFileRepository($this->tmp . '/edges/b.json', $serializer)));
        $this->assertTrue(str_contains($e->getMessage(), 'requires an IPersistentRepository'), $e->getMessage());

        // load() works on copies: the backend's record and a record read before are not changed by it.
        $recordPath = $this->tmp . '/edges/record.json';
        $writer = InMemoryAndFileRepository::create($recordPath, $serializer, persistenceDefinition: PersistenceDefinition::MemoryAsyncFile);
        $writer->persist();
        $backend = new SingleFileRepository($recordPath, $serializer, PersistenceDefinition::MemoryAsyncFile);
        $reader = new InMemoryAndFileRepository($backend, null, PersistenceDefinition::MemoryAsyncFile);
        $before = $reader->getRepositoryMetaData();
        $reader->load();
        $this->assertEquals(1, $backend->getRepositoryMetaData()?->getReadCount(), 'the backend keeps the stored record');
        $this->assertEquals(2, $reader->getRepositoryMetaData()->getReadCount(), 'the hybrid counts its load on a copy');
        $this->assertEquals(0, $before->getReadCount(), 'a record read before load() is not changed');

        // A delegated store: store() is persist(), a removal reaches the store, clear() stays in the cache.
        $store = new RecordingPersistentStore();
        $delegated = new InMemoryAndFileRepository($store, null, PersistenceDefinition::MemoryAsyncDelegated);
        $this->assertEquals(\Antevemus\ASpecification\Contracts\Entities\IEntity::class, $delegated->getType(), 'a backend without getType(): IEntity');
        $delegated->load();
        $empty = $delegated->getRepositoryMetaData();
        $delegated->load();
        $this->assertEquals(1, $empty->getReadCount(), 'the record kept in memory is replaced by a copy on load()');
        $gone = new WriteBehindEntity('gone');
        $delegated->putAll([$gone, new WriteBehindEntity('stays')]);
        $delegated->store();
        $this->assertEquals(['load', 'load', 'putAll', 'store'], $store->calls, 'store(): one persist, no second store()');
        $delegated->remove($gone);
        $delegated->persist();
        $this->assertEquals(['stays'], self::titles($store->findAll($this->all)), 'the removal reached the store');
        $delegated->clear();
        $this->assertEquals(1, $store->count($this->all), 'clear() stays in the cache until persist()');
        $this->assertEquals(1, $delegated->pendingWrites());

        // A failed automatic persist leaves the writes pending; an operation that writes nothing does not retry it.
        $armed = new ArmedFailingSerializer(WriteBehindEntity::class);
        $auto = new InMemoryAndFileRepository(new SingleFileRepository($this->tmp . '/edges/auto.json', $armed, PersistenceDefinition::MemoryAsyncFile), null, PersistenceDefinition::MemoryAsyncFile);
        $auto->load();
        $auto->persistEvery(2);
        $auto->put(new WriteBehindEntity('a'));
        $armed->failOnTitle = 'b';
        $this->assertThrows(\RuntimeException::class, fn() => $auto->put(new WriteBehindEntity('b')));
        $this->assertEquals(2, $auto->pendingWrites(), 'the failed automatic persist leaves both pending');
        $serializations = $armed->serializations;
        $this->assertEquals(0, $auto->removeAll(Spec::property('counter', Spec::greaterThan(1000))), 'a removeAll() that finds nothing');
        $auto->putAll([]);
        $this->assertEquals($serializations, $armed->serializations, 'and an empty putAll() do not try to persist again');
        $armed->failOnTitle = null;
        $auto->put(new WriteBehindEntity('c'));
        $this->assertEquals(0, $auto->pendingWrites(), 'the next write persists all three');
        $this->assertEquals(['a', 'b', 'c'], $this->titlesInFile($this->tmp . '/edges/auto.json'));

        // The record: defaults of a partial block, nulls exported, the first write kept.
        $this->assertEquals(0, RepositoryPersistenceMetaData::fromArray(['write_count' => 1], 'test')->getReadCount(), 'read_count defaults to 0');
        $this->assertEquals(['format_version' => 1, 'read_count' => 0, 'write_count' => 0, 'first_read_at' => null, 'last_read_at' => null, 'first_write_at' => null, 'last_write_at' => null], (new RepositoryPersistenceMetaData())->toArray());
        $record = new RepositoryPersistenceMetaData();
        $early = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $record->registerWrite($early);
        $record->registerWrite(new \DateTimeImmutable('2026-02-01T00:00:00+00:00'));
        $this->assertTrue($record->getFirstWrite() === $early && $record->getLastWrite() != $early && $record->getWriteCount() === 2, 'the first write is kept');
    }

    // ==========================================
    // Property: write-behind, persisted and reloaded, equals write-through
    // ==========================================

    private function testPropertyWriteBehindEqualsWriteThrough(): void
    {
        mt_srand(self::SEED);
        $runs = ['single' => 150, 'per-entity' => 40];
        $discriminating = 0;
        $operations = 0;
        $checks = 0;
        foreach ($runs as $storage => $count) {
            for ($run = 0; $run < $count; $run++) {
                $base = "{$this->tmp}/property/{$storage}-{$run}";
                [$through, $behind, $readThrough, $readBehind] = $this->propertyPair($storage, $base);
                $live = [];
                $steps = mt_rand(1, 25);
                for ($step = 0; $step < $steps; $step++) {
                    $operations++;
                    $this->applyRandomOperation($through, $behind, $live);
                    if (mt_rand(0, 6) === 0) {
                        $behind->persist();
                    }
                    if (self::state($through->findAll($this->all)) !== self::state($behind->findAll($this->all))) {
                        throw new \AssertionError("{$storage} run {$run} step {$step}: the queries of the write-behind cache differ from write-through");
                    }
                }

                // Broken control: a reload without the final persist() reads the storage as it is now.
                $pending = $behind->pendingWrites();
                $control = self::state($readBehind()->findAll($this->all));
                $expected = self::state($readThrough()->findAll($this->all));
                $behind->persist();
                $behind->load();
                $checks++;
                $this->assertTrue(self::state($behind->findAll($this->all)) === $expected, "{$storage} run {$run}: persist + load equals write-through");
                $this->assertTrue(self::state($readBehind()->findAll($this->all)) === $expected, "{$storage} run {$run}: the storage equals write-through");
                if ($pending > 0 && $control !== $expected) {
                    $discriminating++;
                }
                $through->close();
                $behind->close();
            }
        }
        $this->assertTrue($discriminating > 50, "the property discriminates: {$discriminating} runs where a reload without persist() differs");
        $this->assertTrue($operations > 2000 && $checks === 190, "{$operations} operations in {$checks} runs");
    }

    /**
     * @return array{InMemoryAndFileRepository, InMemoryAndFileRepository, \Closure, \Closure}
     */
    private function propertyPair(string $storage, string $base): array
    {
        $serializer = new JsonEntitySerializer(WriteBehindEntity::class);
        if ($storage === 'single') {
            return [
                InMemoryAndFileRepository::create("{$base}/through.json", $serializer),
                InMemoryAndFileRepository::create("{$base}/behind.json", $serializer, persistenceDefinition: PersistenceDefinition::MemoryAsyncFile),
                static fn() => new SingleFileRepository("{$base}/through.json", $serializer),
                static fn() => new SingleFileRepository("{$base}/behind.json", $serializer),
            ];
        }
        $through = new InMemoryAndFileRepository(new FilePerEntityRepository("{$base}/through", $serializer));
        $behind = new InMemoryAndFileRepository(new FilePerEntityRepository("{$base}/behind", $serializer, PersistenceDefinition::MemoryAsyncFile), null, PersistenceDefinition::MemoryAsyncFile);
        $through->load();
        $behind->load();

        return [
            $through,
            $behind,
            static fn() => new FilePerEntityRepository("{$base}/through", $serializer),
            static fn() => new FilePerEntityRepository("{$base}/behind", $serializer),
        ];
    }

    /**
     * One random operation, the same on both sides: a new entity, an update of a live one (mutated in place,
     * as callers do), a removal (sometimes of an entity already removed), a removeAll() by counter, rarely a
     * clear().
     *
     * @param array<int, WriteBehindEntity> $live
     */
    private function applyRandomOperation(InMemoryAndFileRepository $through, InMemoryAndFileRepository $behind, array &$live): void
    {
        $roll = mt_rand(0, 99);
        if ($live === [] || $roll < 35) {
            $entity = new WriteBehindEntity('e' . mt_rand(0, 999), mt_rand(0, 20));
            $live[] = $entity;
            $through->put($entity);
            $behind->put($entity);
            return;
        }
        $index = array_rand($live);
        $entity = $live[$index];
        if ($roll < 65) {
            $entity->counter = mt_rand(0, 20);
            $entity->title = 'u' . mt_rand(0, 999);
            $through->update($entity);
            $behind->update($entity);
            return;
        }
        if ($roll < 88) {
            $removed = [$through->remove($entity), $behind->remove($entity)];
            if ($removed[0] !== $removed[1]) {
                throw new \AssertionError('remove() answers differently');
            }
            if (mt_rand(0, 1) === 0) {
                unset($live[$index]);
            }
            return;
        }
        if ($roll < 97) {
            $threshold = mt_rand(5, 20);
            $spec = Spec::property('counter', Spec::greaterThan($threshold));
            if ($through->removeAll($spec) !== $behind->removeAll($spec)) {
                throw new \AssertionError('removeAll() answers differently');
            }
            return;
        }
        $through->clear();
        $behind->clear();
        $live = [];
    }

    // ==========================================
    // Example 5.1 (docs/<language>/repositories/persistence-and-cache.md)
    // ==========================================

    private function testExample5_1BlocksAreTheSameInBothLanguages(): void
    {
        $root = dirname(__DIR__, 2);
        $english = ReadmeBlocks::of($root . '/docs/en/repositories/persistence-and-cache.md', '5.1');
        $portuguese = ReadmeBlocks::of($root . '/docs/pt-BR/repositories/persistence-and-cache.md', '5.1');
        $this->assertCount(1, $english, 'one PHP block in example 5.1 (EN)');
        $this->assertCount(1, $portuguese, 'one PHP block in example 5.1 (pt-BR)');
        $this->assertEquals($english[0] ?? null, $portuguese[0] ?? null, 'example 5.1: the same block, byte for byte');
    }

    /**
     * Example 5.1 runs as written: the code between the markers is the block of the page token by token (its
     * `use` lines are imports of this file, `Customer` is WriteBehindEntity, and its first statement, the
     * storage path, is replaced by a temporary one; a bare expression of the page is kept here as `$rN = ...`).
     */
    private function testExample5_1RunsAsWritten(): void
    {
        $storagePath = $this->tmp . '/example-5.1/customers.json';
        // README 5.1 >>>
        $repo = InMemoryAndFileRepository::create(
            storagePath: $storagePath,
            serializer: new JsonEntitySerializer(Customer::class),
            persistenceDefinition: PersistenceDefinition::MemoryAsyncFile
        );

        for ($i = 1; $i <= 1000; $i++) {
            $repo->put(new Customer("Customer {$i}"));
        }

        $r1 = $repo->pendingWrites();                                  // 1000
        $r2 = $repo->countAllEntities();                               // 1000
        $r3 = file_exists($storagePath);                               // false

        $repo->persist();
        $r4 = $repo->isDirty();                                        // false

        $draft = new Customer('Draft');
        $repo->put($draft);
        $repo->load();
        $r5 = $repo->contains($draft);                                 // false

        $r6 = $repo->getRepositoryMetaData()->getReadCount();          // 2
        $r7 = $repo->getRepositoryMetaData()->getWriteCount();         // 1

        $again = InMemoryAndFileRepository::create(
            storagePath: $storagePath,
            serializer: new JsonEntitySerializer(Customer::class),
            persistenceDefinition: PersistenceDefinition::MemoryAsyncFile
        );
        $r8 = $again->countAllEntities();                              // 1000

        $repo->persistEvery(500);
        for ($i = 1001; $i <= 1500; $i++) {
            $repo->put(new Customer("Customer {$i}"));
        }
        $r9 = $repo->pendingWrites();                                  // 0
        $r10 = $repo->getRepositoryMetaData()->getWriteCount();        // 2

        $repo->close();
        // <<< README 5.1

        $this->assertEquals([1000, 1000, false, false, false, 2, 1, 1000, 0, 2], [$r1, $r2, $r3, $r4, $r5, $r6, $r7, $r8, $r9, $r10], 'example 5.1: the results written in its comments');
        $this->assertEquals(1500, count($this->titlesInFile($storagePath)), 'example 5.1: 1 500 customers in the file');
        $this->assertTranscribed5_1();
    }

    /** The code between the markers of example 5.1 is the block of docs/en/repositories/persistence-and-cache.md. */
    private function assertTranscribed5_1(): void
    {
        $source = (string) file_get_contents(__FILE__);
        $start = strpos($source, "// README 5.1 >>>\n");
        $end = strpos($source, "// <<< README 5.1\n");
        $this->assertTrue($start !== false && $end !== false && $end > $start, 'transcription of example 5.1 marked');
        $tokens = ReadmeBlocks::codeTokens(substr($source, (int) $start, (int) $end - (int) $start));
        $transcribed = [];
        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            if (preg_match('/^\$r\d+$/', $tokens[$i]) === 1 && ($tokens[$i + 1] ?? null) === '=') {
                $i++;
                continue;
            }
            $transcribed[] = $tokens[$i];
        }
        $block = ReadmeBlocks::of(dirname(__DIR__, 2) . '/docs/en/repositories/persistence-and-cache.md', '5.1')[0] ?? '';
        preg_match_all('/^use ([^;]+);$/m', $block, $imports);
        $this->assertCount(3, $imports[1], 'example 5.1 imports three names');
        foreach ($imports[1] as $import) {
            $this->assertTrue(str_contains($source, "\nuse {$import};\n"), "example 5.1: {$import} is imported by this file too");
        }
        $this->assertEquals(1, preg_match_all('/^\$storagePath = \'[^\']+\';$/m', $block), 'example 5.1 sets its storage path once, in its own statement');
        $code = ReadmeBlocks::codeTokens((string) preg_replace(['/^use [^;]+;$/m', '/^\$storagePath = \'[^\']+\';$/m'], '', $block));
        $this->assertTrue(count($code) > 150 && $transcribed === $code, sprintf(
            'example 5.1: the transcription is the code of the page (%d tokens transcribed, %d on the page)',
            count($transcribed),
            count($code)
        ));
    }

    // ==========================================
    // Guard: every public method of the new and changed API is exercised here
    // ==========================================

    private function testPublicApiGuard(): void
    {
        foreach (self::COVERED_API as $class => $covered) {
            $reflection = new \ReflectionClass($class);
            $declared = [];
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() === $class) {
                    $declared[] = $method->getName();
                }
            }
            sort($declared);
            $listed = $covered;
            sort($listed);
            $this->assertEquals($listed, $declared, "{$class}: every public method it declares is exercised by Module 42 (list it in COVERED_API once tested)");
        }
        foreach ([SingleFileRepository::class, FilePerEntityRepository::class] as $backend) {
            $this->assertTrue(is_subclass_of($backend, IWriteBehindBackend::class), "{$backend} is a write-behind backend");
        }
    }

    // ==========================================
    // Helpers
    // ==========================================

    /**
     * @return array<string, mixed>
     */
    private function document(string $path): array
    {
        $document = json_decode((string) file_get_contents($path), true);
        if (!is_array($document)) {
            throw new \AssertionError("{$path} is not a JSON document");
        }

        return $document;
    }

    /**
     * @return array<string, array<string, mixed>> entity data by title
     */
    private function entitiesInFile(string $path): array
    {
        $entities = [];
        foreach ($this->document($path)['entities'] as $entity) {
            $entities[$entity['title']] = $entity;
        }

        return $entities;
    }

    /**
     * @return list<string> titles of the entities in the document, sorted
     */
    private function titlesInFile(string $path): array
    {
        $titles = array_keys($this->entitiesInFile($path));
        sort($titles);

        return array_map('strval', $titles);
    }

    /**
     * @return array{int, int, ?string, ?string} read count, write count, whether a load and a persist happened
     */
    private function recordCounts(RepositoryPersistenceMetaData $record): array
    {
        return [
            $record->getReadCount(),
            $record->getWriteCount(),
            $record->getLastLoad() !== null ? 'load' : null,
            $record->getLastPersist() !== null ? 'persist' : null,
        ];
    }

    /**
     * @param array<object> $entities
     * @return list<string>
     */
    private static function titles(array $entities): array
    {
        $titles = array_map(static fn(object $entity): string => $entity->title, $entities);
        sort($titles);

        return $titles;
    }

    /**
     * @param array<WriteBehindEntity> $entities
     * @return array<string, string> id => title and counter, sorted by id
     */
    private static function state(array $entities): array
    {
        $state = [];
        foreach ($entities as $entity) {
            $state[(string) $entity->getEntityId()] = "{$entity->title}#{$entity->counter}";
        }
        ksort($state);

        return $state;
    }

    /**
     * @return list<string> the names in a directory, sorted (none when it does not exist)
     */
    private static function filesIn(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $files = array_values(array_diff((array) scandir($directory), ['.', '..']));
        sort($files);

        return array_map('strval', $files);
    }

    /**
     * Runs tests/Support/write_behind_child.php and returns its output and how it ended ("exit <code>" or
     * "signal <number>").
     *
     * @return array{string, string}
     */
    private static function runChild(string $path, string $mode, int $count): array
    {
        $script = dirname(__DIR__) . '/Support/write_behind_child.php';
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open([PHP_BINARY, $script, $path, $mode, (string) $count], $descriptors, $pipes, null, ['XDEBUG_MODE' => 'off']);
        if (!is_resource($process)) {
            throw new \AssertionError('the child process did not start');
        }
        fclose($pipes[0]);
        $output = trim((string) stream_get_contents($pipes[1]));
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        do {
            $status = proc_get_status($process);
        } while ($status['running'] && usleep(1000) === null);
        proc_close($process);
        if ($errors !== '' && !str_contains($errors, 'Xdebug')) {
            throw new \AssertionError("the child ({$mode}) wrote to stderr: {$errors}");
        }

        return [$output, $status['signaled'] ? "signal {$status['termsig']}" : "exit {$status['exitcode']}"];
    }

    private static function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            ($file->isDir() ? 'rmdir' : 'unlink')($file->getPathname());
        }
        @rmdir($directory);
    }
}
