<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Parity;

use Antevemus\ASpecification\AbstractCompositeSpecification;
use Antevemus\ASpecification\Attributes\Specifiable;
use Antevemus\ASpecification\Concurrent\NullSynchronizer;
use Antevemus\ASpecification\Concurrent\SemaphoreSynchronizer;
use Antevemus\ASpecification\Contracts\Concurrent\ISynchronizer;
use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\Helpers\IStopWatch;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Repositories\IFakeRepository;
use Antevemus\ASpecification\Contracts\Repositories\IVolatileRepository;
use Antevemus\ASpecification\Contracts\Repositories\PersistenceDefinition;
use Antevemus\ASpecification\Entities\AbstractEntity;
use Antevemus\ASpecification\Factory\StrictReturnsNullFactory;
use Antevemus\ASpecification\Helpers\InstrumentationUtils;
use Antevemus\ASpecification\Helpers\PropertyAccessor;
use Antevemus\ASpecification\Helpers\ReflectionUtils;
use Antevemus\ASpecification\Helpers\SpecificationHelper;
use Antevemus\ASpecification\Helpers\StopWatch;
use Antevemus\ASpecification\Linq\ALinqSpecificationVisitor;
use Antevemus\ASpecification\Repositories\FakePartitionRepository;
use Antevemus\ASpecification\Repositories\InMemoryRepository;
use Antevemus\ASpecification\Repositories\NullRepository;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\Collection\AllEntitiesSpecification;
use DateTimeImmutable;
use Fiber;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Antevemus\ASpecification\Repositories\File\FilePerEntityRepository;
use Antevemus\ASpecification\Repositories\File\SingleFileRepository;
use Antevemus\ASpecification\Repositories\Serialization\JsonEntitySerializer;

/**
 * DomianConcurrencyEntityUtilTest - Transcription of the Domian (Java) tests covering concurrency,
 * entities, utilities and the object factory, one test per Java behaviour.
 *
 * Sources (Domian trunk r1209, Apache License 2.0): domian-api StopWatchTest and AbstractEntityTest;
 * domian-core SemaphoreSynchronizerTest, ReflectionUtilsTest, StrictReturnsNullFactoryTest,
 * NullRepositoryTest, AbstractDomianCoreRepository (synchronizer) and SpecificationUtils.updateEntityState().
 * Each test names the Java test it transcribes.
 *
 * Since 1.6.1 a private or protected property is reached by name only when the author of the class
 * marked it #[Specifiable]; the fixtures that stand for the Domian test domain carry the marker, and
 * the opt-in section below covers the unmarked cases.
 *
 * @version    2.0.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Parity
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class DomianConcurrencyEntityUtilTest extends TestCase
{
    /** One "sleep(51)" of the Java StopWatchTest, shortened to keep the suite fast. */
    private const TICK_US = 30_000;
    private const TICK_NS = 30_000_000;

    ///////////////////////////////////////////////////////////////////////////
    // StopWatchTest (domian-api)
    ///////////////////////////////////////////////////////////////////////////

    #[Test]
    public function readyStateShouldBeTheInitialState(): void
    {
        self::assertSame(IStopWatch::STATE_READY, (new StopWatch())->getState());
    }

    #[Test]
    public function readyStateShouldBeIdempotent(): void
    {
        $stopWatch = new StopWatch();
        $stopWatch->stop();
        self::assertSame(IStopWatch::STATE_READY, $stopWatch->getState());
    }

    #[Test]
    public function shouldGoFromReadyStateToStartedStateWhenStartIsInvoked(): void
    {
        self::assertSame(IStopWatch::STATE_STARTED, (new StopWatch())->start()->getState());
    }

    #[Test]
    public function startedStateShouldBeIdempotent(): void
    {
        self::assertSame(IStopWatch::STATE_STARTED, (new StopWatch())->start()->start()->getState());
    }

    #[Test]
    public function shouldGoFromStartedStateToStoppedStateWhenStoppedIsInvoked(): void
    {
        self::assertSame(IStopWatch::STATE_STOPPED, (new StopWatch())->start()->stop()->getState());
    }

    #[Test]
    public function stoppedStateShouldBeIdempotent(): void
    {
        self::assertSame(IStopWatch::STATE_STOPPED, (new StopWatch())->start()->stop()->stop()->getState());
    }

    #[Test]
    public function shouldGoFromStoppedStateToStartedStateWhenStartIsInvoked(): void
    {
        self::assertSame(IStopWatch::STATE_STARTED, (new StopWatch())->start()->stop()->start()->getState());
    }

    #[Test]
    public function shouldNotStartWhenInstantiated(): void
    {
        $stopWatch = new StopWatch();
        usleep(self::TICK_US);
        self::assertSame(0, $stopWatch->getElapsedTime());
    }

    #[Test]
    public function shouldStartWhenInvokingStart(): void
    {
        $stopWatch = new StopWatch();
        usleep(self::TICK_US);
        $stopWatch->start();
        self::assertLessThan(5_000_000, $stopWatch->getElapsedTime(), 'the time before start() is not counted');
    }

    #[Test]
    public function startShouldBeIdempotent(): void
    {
        $stopWatch = new StopWatch();
        $stopWatch->start();
        usleep(self::TICK_US);
        $stopWatch->start();
        self::assertGreaterThan(self::TICK_NS * 0.9, $stopWatch->getElapsedTime(), 'start() in STARTED must not restart the count');
    }

    #[Test]
    public function shouldSuspendTimeWhenInvokingStop(): void
    {
        $stopWatch = new StopWatch();
        $stopWatch->start();
        usleep(self::TICK_US);
        $stopWatch->stop();
        usleep(self::TICK_US);
        self::assertLessThan(2 * self::TICK_NS, $stopWatch->getElapsedTime());
    }

    #[Test]
    public function shouldResumeRunningTimeWhenInvokingStart(): void
    {
        $stopWatch = new StopWatch();
        $stopWatch->start();
        usleep(self::TICK_US);
        $stopWatch->stop();
        usleep(self::TICK_US);
        $stopWatch->start();
        usleep(self::TICK_US);
        $elapsed = $stopWatch->getElapsedTime();
        self::assertGreaterThan(2 * self::TICK_NS * 0.9, $elapsed, 'the interval before stop() is kept');
        self::assertLessThan(3 * self::TICK_NS, $elapsed, 'the pause is not counted');
    }

    #[Test]
    public function stopShouldBeIdempotent(): void
    {
        $stopWatch = new StopWatch();
        $stopWatch->start();
        usleep(self::TICK_US);
        $stopWatch->stop();
        usleep(self::TICK_US);
        $stopWatch->stop();
        $elapsed = $stopWatch->getElapsedTime();
        self::assertGreaterThan(self::TICK_NS * 0.9, $elapsed);
        self::assertLessThan(2 * self::TICK_NS, $elapsed);
    }

    #[Test]
    public function lapTime(): void
    {
        $stopWatch = new StopWatch();
        $stopWatch->start();
        usleep(self::TICK_US);
        $lapTime1 = $stopWatch->getLapTime();
        self::assertGreaterThan(self::TICK_NS * 0.9, $lapTime1);
        self::assertLessThan(self::TICK_NS * 1.5, $lapTime1);

        usleep(self::TICK_US);
        $lapTime2 = $stopWatch->getLapTime();
        self::assertGreaterThan(self::TICK_NS * 0.9, $lapTime2);
        self::assertLessThan(self::TICK_NS * 1.5, $lapTime2);
        self::assertCount(2, $stopWatch->getLaps());
    }

    #[Test]
    public function lapTimeIsZeroAndRecordsNothingUnlessStarted(): void
    {
        $ready = new StopWatch();
        self::assertSame(0, $ready->getLapTime(), 'Java getLapTime() in READY');
        self::assertSame([], $ready->getLaps());

        $stopped = new StopWatch();
        $stopped->start();
        usleep(2_000);
        $stopped->stop();
        usleep(2_000);
        self::assertSame(0, $stopped->lap(), 'Java getLapTime() in STOPPED');
        self::assertSame([], $stopped->getLaps());
    }

    #[Test]
    public function printFormatsWithPlainFlooring(): void
    {
        self::assertSame('999 us', StopWatch::print(999_999));
        self::assertSame('1 ms', StopWatch::print(1_999_999));
        self::assertSame('12 ms', StopWatch::print(12_345_678));
        self::assertSame('1 s 234 ms', StopWatch::print(1_234_567_890));
        self::assertSame('0 us', StopWatch::print(0));

        $stopWatch = new StopWatch();
        self::assertSame('0 us', (string) $stopWatch);
        self::assertSame($stopWatch->toString(), $stopWatch->elapsedTimeToString());
        self::assertSame('0 us', $stopWatch->lapTimeToString());
    }

    ///////////////////////////////////////////////////////////////////////////
    // AbstractEntityTest / AbstractUUIDEntityTest (domian-api)
    ///////////////////////////////////////////////////////////////////////////

    #[Test]
    public function shouldNotAllowEqualsWhereNotEvenTheTypeIsCorrect(): void
    {
        $maleCustomer = new ParityCustomer(102);
        $order = new ParityOrder(102);

        self::assertFalse($maleCustomer->equals($order), 'Customer(102) is not an Order(102), whatever the id');
        self::assertFalse($order->equals($maleCustomer));
        self::assertFalse($maleCustomer->equals(new ParityForeignEntity(102)), 'an IEntity that is not an AbstractEntity is never equal');
    }

    #[Test]
    public function entitiesWithTheSameIdAreEqualBothWays(): void
    {
        // AbstractUUIDEntityTest.testEquals: two instances carrying the same identifier
        $one = new ParityCustomer(7);
        $other = new ParityCustomer(7);

        self::assertTrue($one->equals($other));
        self::assertTrue($other->equals($one));
        self::assertSame($one->hashCode(), $other->hashCode(), 'hashCode() is consistent with equals()');
        self::assertFalse($one->equals(new ParityCustomer(8)));
        self::assertTrue($one->equals($one));
    }

    #[Test]
    public function subclassesStayWithinTheTypeFamily(): void
    {
        $customer = new ParityCustomer(5);
        $vip = new ParityVipCustomer(5);

        self::assertTrue($customer->equals($vip), 'a VipCustomer still is the Customer it extends');
        self::assertTrue($vip->equals($customer));
    }

    #[Test]
    public function toStringListsTheEntityProperties(): void
    {
        $customer = new ParityCustomer(42);
        $customer->setName('Eirik');
        $text = (string) $customer;

        self::assertStringStartsWith('ParityCustomer[', $text);
        self::assertStringContainsString('id=42', $text);
        self::assertStringContainsString('name=Eirik', $text);
        self::assertStringContainsString('timeOfCreation=', $text);
        self::assertSame($text, $customer->toString());
    }

    ///////////////////////////////////////////////////////////////////////////
    // SemaphoreSynchronizerTest (domian-core)
    ///////////////////////////////////////////////////////////////////////////

    #[Test]
    public function shouldNotDeadlockWhenInvokingAnConcurrentMethodFromAnotherConcurrentMethod(): void
    {
        $synchronizer = new SemaphoreSynchronizer();
        self::assertTrue($synchronizer->callConcurrently(fn(): bool => $this->concurrentMethod($synchronizer)));
        self::assertSame($synchronizer->getMaxPermits(), $synchronizer->getAvailablePermits());
    }

    #[Test]
    public function shouldNotDeadlockWhenInvokingAnExclusiveMethodFromAConcurrentMethod(): void
    {
        $synchronizer = new SemaphoreSynchronizer();
        $result = null;
        $synchronizer->runConcurrently(function () use ($synchronizer, &$result): void {
            $result = $this->exclusiveMethod($synchronizer);
        });
        self::assertSame(123, $result);
        self::assertSame($synchronizer->getMaxPermits(), $synchronizer->getAvailablePermits());
        self::assertFalse($synchronizer->isExclusiveLocked());
    }

    #[Test]
    public function shouldNotDeadlockWhenInvokingAConcurrentMethodFromAnExclusiveMethod(): void
    {
        $synchronizer = new SemaphoreSynchronizer();
        self::assertTrue($synchronizer->callExclusively(fn(): bool => $this->concurrentMethod($synchronizer)));
        self::assertSame($synchronizer->getMaxPermits(), $synchronizer->getAvailablePermits());
    }

    #[Test]
    public function shouldNotDeadlockWhenInvokingAnExclusiveMethodFromAnotherExclusiveMethod(): void
    {
        $synchronizer = new SemaphoreSynchronizer();
        self::assertSame(123, $synchronizer->callExclusively(fn(): int => $this->exclusiveMethod($synchronizer)));
        self::assertFalse($synchronizer->isExclusiveLocked());
    }

    #[Test]
    public function anotherExecutionContextCannotBeWaitedForInASingleProcess(): void
    {
        // Java blocks until the other thread releases; a PHP process cannot wait for a fiber it must resume itself
        $synchronizer = new SemaphoreSynchronizer(3);
        $reader = new Fiber(function () use ($synchronizer): void {
            $synchronizer->runConcurrently(function (): void {
                Fiber::suspend();
            });
        });
        $reader->start();
        self::assertSame(2, $synchronizer->getAvailablePermits());
        self::assertFalse($synchronizer->hasAcquiredPermit(), 'the main flow holds nothing');

        try {
            $synchronizer->callExclusively(fn(): bool => true);
            self::fail('Should have refused: a suspended fiber holds a concurrent permit');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Java would wait', $e->getMessage());
        }

        self::assertTrue($synchronizer->callConcurrently(fn(): bool => true), 'other readers are still welcome');
        $reader->resume();
        self::assertSame(3, $synchronizer->getAvailablePermits());
        self::assertTrue($synchronizer->callExclusively(fn(): bool => true));
    }

    private function concurrentMethod(ISynchronizer $synchronizer): bool
    {
        return $synchronizer->callConcurrently(fn(): bool => true);
    }

    private function exclusiveMethod(ISynchronizer $synchronizer): int
    {
        return $synchronizer->callExclusively(fn(): int => 123);
    }

    ///////////////////////////////////////////////////////////////////////////
    // AbstractDomianCoreRepository.synchronizer (domian-core)
    ///////////////////////////////////////////////////////////////////////////

    #[Test]
    public function repositoriesCarryANullSynchronizerByDefault(): void
    {
        $repository = new InMemoryRepository();
        self::assertInstanceOf(NullSynchronizer::class, $repository->getSynchronizer());
        self::assertInstanceOf(NullSynchronizer::class, (new NullRepository())->getSynchronizer());

        $semaphore = new SemaphoreSynchronizer();
        self::assertSame($repository, $repository->withSynchronizer($semaphore));
        self::assertSame($semaphore, $repository->getSynchronizer());

        $viaConstructor = new InMemoryRepository([], 'repo-1', $semaphore);
        self::assertSame($semaphore, $viaConstructor->getSynchronizer());
        self::assertSame('repo-1', $viaConstructor->getRepositoryId());
    }

    #[Test]
    public function readsRunConcurrentlyAndWritesRunExclusively(): void
    {
        $spy = new RecordingSynchronizer();
        $repository = new InMemoryRepository([], null, $spy);
        $entity = new ParityCustomer(1);

        $repository->put($entity);
        $repository->putAll([new ParityCustomer(2)]);
        $repository->update($entity);
        $repository->updateWithDelta($entity, null);
        self::assertSame(['exclusive', 'exclusive', 'exclusive', 'exclusive'], $spy->outermostModes());

        $spy->reset();
        $repository->count(new AllEntitiesSpecification());
        $repository->findAll(new AllEntitiesSpecification());
        $repository->findSingle(Spec::property('id', Spec::is(1)));
        $repository->contains($entity);
        iterator_to_array($repository->iterate(new AllEntitiesSpecification()), false);
        $repository->getAll();
        self::assertSame(['concurrent', 'concurrent', 'concurrent', 'concurrent', 'concurrent', 'concurrent'], $spy->outermostModes());

        $spy->reset();
        $repository->remove($entity);
        $repository->removeAll(new AllEntitiesSpecification());
        $repository->clear();
        self::assertSame(['exclusive', 'exclusive', 'exclusive'], $spy->outermostModes());
    }

    #[Test]
    public function nestedRepositoryOperationsDoNotDeadlockUnderTheSemaphoreSynchronizer(): void
    {
        $synchronizer = new SemaphoreSynchronizer();
        $repository = new InMemoryRepository([new ParityCustomer(1), new ParityCustomer(2)], null, $synchronizer);

        $repository->putAll([new ParityCustomer(3)]);          // putAll -> put
        $repository->update(new ParityCustomer(3));             // update -> put
        self::assertSame(3, $repository->count(new AllEntitiesSpecification()));
        self::assertNotNull($repository->findSingle(Spec::property('id', Spec::is(2)))); // findSingle -> findAll
        self::assertTrue($repository->contains(new ParityCustomer(1)));
        self::assertSame($synchronizer->getMaxPermits(), $synchronizer->getAvailablePermits());
        self::assertFalse($synchronizer->isExclusiveLocked());
    }

    ///////////////////////////////////////////////////////////////////////////
    // SpecificationUtils.updateEntityState() / Repository.update(entity, delta) (domian-core)
    ///////////////////////////////////////////////////////////////////////////

    #[Test]
    public function updateWithDeltaAppliesTheBoundValuesToTheEntity(): void
    {
        $repository = new InMemoryRepository();
        $customer = new ParityCustomer(10);
        $customer->setName('John');
        $repository->put($customer);

        $delta = Spec::specify(ParityCustomer::class)
            ->where('name', Spec::is('Peter'))
            ->and('age', Spec::is(31))
            ->and('secret', Spec::is('s3cr3t'));

        $repository->updateWithDelta($customer, $delta);

        self::assertSame('Peter', $customer->getName(), 'public setter used');
        self::assertSame(31, $customer->age, 'public property set');
        self::assertSame('s3cr3t', ReflectionUtils::getFieldValue($customer, 'secret'), 'private field without setter set directly, as the Java Field.set() (class marked #[Specifiable])');
        self::assertSame($customer, $repository->findSingle(Spec::property('name', Spec::is('Peter'))));
    }

    #[Test]
    public function updateWithDeltaAppliesTheDeltaInTheFileRepositoriesToo(): void
    {
        $dir = sys_get_temp_dir() . '/aspec_parity_delta_' . uniqid();
        mkdir($dir, 0777, true);
        try {
            $serializer = new JsonEntitySerializer(ParityCustomer::class);
            $repositories = [
                new SingleFileRepository($dir . '/single.json', ParityCustomer::class, PersistenceDefinition::ReadWrite, $serializer),
                new FilePerEntityRepository($dir . '/per-entity', ParityCustomer::class, PersistenceDefinition::ReadWrite, $serializer),
            ];
            foreach ($repositories as $repository) {
                $customer = new ParityCustomer(10, 'John');
                $repository->put($customer);
                $repository->updateWithDelta($customer, Spec::specify(ParityCustomer::class)->where('name', Spec::is('Peter')));
                self::assertSame('Peter', $customer->getName(), $repository::class . ' applies the delta before persisting');
                self::assertNotNull($repository->findSingle(Spec::property('name', Spec::is('Peter'))), $repository::class . ' persisted the updated entity');
            }
        } finally {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($dir);
        }
    }

    #[Test]
    public function updateWithDeltaRejectsAnEntityOfAnotherType(): void
    {
        $repository = new InMemoryRepository();
        $order = new ParityOrder(1);
        $repository->put($order);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be of same type as parameterized type declaring class');
        $repository->updateWithDelta($order, Spec::specify(ParityCustomer::class)->where('name', Spec::is('x')));
    }

    #[Test]
    public function updateEntityStateIgnoresClausesWithoutAValueAndANullDelta(): void
    {
        $customer = new ParityCustomer(3);
        $customer->setName('Ann');

        SpecificationHelper::updateEntityState($customer, null);
        SpecificationHelper::updateEntityState($customer, Spec::property('name', Spec::isNotNull()));
        self::assertSame('Ann', $customer->getName());

        SpecificationHelper::updateEntityState($customer, Spec::property('name', Spec::nor(Spec::is('a'), Spec::is('b'))));
        self::assertNull($customer->getName(), 'Java "extra support for notNull": a joint denial clears the property');
    }

    ///////////////////////////////////////////////////////////////////////////
    // NullRepositoryTest (domian-core)
    ///////////////////////////////////////////////////////////////////////////

    #[Test]
    public function nullRepositoryIsAFakeRepositoryAndStoresNothing(): void
    {
        $repository = new NullRepository();
        self::assertInstanceOf(IFakeRepository::class, $repository);
        self::assertInstanceOf(IVolatileRepository::class, $repository, 'marker of earlier versions kept');
        self::assertInstanceOf(FakePartitionRepository::class, $repository->makePartition());

        $entity = new ParityCustomer(1);
        $repository->put($entity);                                   // shouldStoreNoEntities
        $repository->putAll([$entity, new ParityCustomer(2)]);       // shouldStoreNoBulkEntities
        self::assertSame(0, $repository->countAll(new AllEntitiesSpecification()));
        self::assertFalse($repository->contains($entity));
        self::assertFalse($repository->remove($entity));             // shouldHaveNothingToRemove
        self::assertSame(0, $repository->removeAll(new AllEntitiesSpecification()));
        self::assertSame([], $repository->findAll(new AllEntitiesSpecification()));   // shouldNotReturnNullCollectionsOrIterators
        self::assertSame([], iterator_to_array($repository->iterateAll(new AllEntitiesSpecification()), false));
        self::assertNull($repository->findSingle(new AllEntitiesSpecification()));
    }

    ///////////////////////////////////////////////////////////////////////////
    // PersistenceDefinition (domian-api)
    ///////////////////////////////////////////////////////////////////////////

    #[Test]
    public function persistenceDefinitionAnswersLikeTheDomianEnum(): void
    {
        // FILE, DELEGATED, INMEMORY, INMEMORY_AND_FILE, INMEMORY_AND_DELEGATED
        self::assertTrue(PersistenceDefinition::FileOnly->isNotMemoryBased());
        self::assertTrue(PersistenceDefinition::DelegatedOnly->isNotMemoryBased());
        self::assertFalse(PersistenceDefinition::MemoryOnly->isNotMemoryBased());
        self::assertFalse(PersistenceDefinition::MemoryAsyncFile->isNotMemoryBased());
        self::assertFalse(PersistenceDefinition::MemoryAsyncDelegated->isNotMemoryBased());
        self::assertTrue(PersistenceDefinition::FileOnly->isFileBasedOnly());
        self::assertTrue(PersistenceDefinition::MemoryOnly->isMemoryBasedOnly());
        self::assertTrue(PersistenceDefinition::MemoryAsyncFile->supportsAsynchronousPersistence());

        // Extra cases of this library, by the Domian definition they implement
        self::assertTrue(PersistenceDefinition::Transient->isMemoryOnly(), 'Transient = INMEMORY (no persistence)');
        self::assertTrue(PersistenceDefinition::Transient->isMemoryBased());
        self::assertFalse(PersistenceDefinition::Transient->isFileBased());
        foreach ([PersistenceDefinition::ReadWrite, PersistenceDefinition::ReadOnly, PersistenceDefinition::WriteOnly] as $fileMode) {
            self::assertTrue($fileMode->isFileOnly(), $fileMode->name . ' = FILE');
            self::assertTrue($fileMode->isNotMemoryBased(), $fileMode->name . ' = FILE');
        }
        self::assertTrue(PersistenceDefinition::Snapshot->isMemoryBased(), 'Snapshot = INMEMORY_AND_FILE');
        self::assertTrue(PersistenceDefinition::Snapshot->isFileBased());
        self::assertTrue(PersistenceDefinition::Snapshot->isAsyncSupported());
        self::assertFalse(PersistenceDefinition::Snapshot->isFileOnly());
    }

    ///////////////////////////////////////////////////////////////////////////
    // ReflectionUtilsTest (domian-core) and the private field case of ParameterizedSpecification
    ///////////////////////////////////////////////////////////////////////////

    #[Test]
    public function testGetFieldByName_NullFieldName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Field name parameter cannot be null');
        ReflectionUtils::getFieldByName('', ParityOrder::class);
    }

    #[Test]
    public function testGetFieldByName_NullType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Type parameter cannot be null');
        ReflectionUtils::getFieldByName('orderId', '');
    }

    #[Test]
    public function testGetFieldByName_NonExisting(): void
    {
        self::assertNull(ReflectionUtils::getFieldByName('nonExistingField', ParityOrder::class));
    }

    #[Test]
    public function testGetFieldByName(): void
    {
        $field = ReflectionUtils::getFieldByName('id', ParityOrder::class);
        self::assertNotNull($field);
        self::assertSame('id', $field->getName());
        self::assertSame('int', (string) $field->getType());
    }

    #[Test]
    public function testGetFieldByName_ShouldAcceptFieldsInSuperclasses(): void
    {
        $secret = ReflectionUtils::getFieldByName('secret', ParityVipCustomer::class);
        self::assertNotNull($secret, 'private field of the superclass');
        self::assertSame(ParityCustomer::class, $secret->getDeclaringClass()->getName());
        self::assertTrue($secret->isPrivate());

        $since = ReflectionUtils::getFieldByName('vipSince', ParityVipCustomer::class);
        self::assertNotNull($since);
        self::assertSame(ParityVipCustomer::class, $since->getDeclaringClass()->getName());
    }

    #[Test]
    public function testGetMethodByName_NullMethodName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Method name parameter cannot be null');
        ReflectionUtils::getMethodByName('', ParityOrder::class);
    }

    #[Test]
    public function testGetMethodByName(): void
    {
        self::assertNull(ReflectionUtils::getMethodByName('nonExistingMethod', ParityOrder::class));
        self::assertSame('isPending', ReflectionUtils::getMethodByName('isPending', ParityOrder::class)?->getName());
        self::assertSame('cancelled', ReflectionUtils::getMethodByName('cancelled', ParityOrder::class, 1)?->getName());
        self::assertNull(ReflectionUtils::getMethodByName('cancelled', ParityOrder::class, 0), 'parameter count must match');
        self::assertSame('setName', ReflectionUtils::getMethodByName('setName', ParityVipCustomer::class)?->getName(), 'method of the superclass');
        self::assertSame('getName', ReflectionUtils::getMethodByNameWithPossiblePrefix('name', ParityCustomer::class)?->getName());
    }

    #[Test]
    public function testInvokeMethod(): void
    {
        $customer = new ParityCustomer(1234);
        $customer->setName('John');

        self::assertSame('John', ReflectionUtils::invokeMethod($customer, 'getName'));
        self::assertNull(ReflectionUtils::invokeMethod($customer, 'setName'), 'missing argument: null, not an error');
        self::assertNull(ReflectionUtils::invokeMethod($customer, 'setName', []));
        self::assertNull(ReflectionUtils::invokeMethod($customer, 'setName', ['Peter']));
        self::assertSame('Peter', ReflectionUtils::invokeMethod($customer, 'getName'));
        self::assertNull(ReflectionUtils::invokeMethod($customer, 'nonExistingMethod'));
        self::assertTrue(ReflectionUtils::invokeBooleanMethod(new ParityOrder(1), 'isPending'));
    }

    #[Test]
    public function testCloneOrDeepCopyIfNotImmutable(): void
    {
        $line = new ParityOrderLine('pen', 2);
        $order = new ParityOrder(9);
        $order->lines = [$line];
        $order->placedAt = new DateTimeImmutable('2020-01-01');

        $copy = ReflectionUtils::cloneOrDeepCopyIfNotImmutable($order, true); // an entity: copied only when asked

        self::assertNotSame($order, $copy);
        self::assertSame(9, $copy->getEntityId());
        self::assertNotSame($line, $copy->lines[0], 'nested objects are copied too');
        self::assertSame('pen', $copy->lines[0]->product);
        self::assertSame($order->placedAt, $copy->placedAt, 'immutable values are shared');
        self::assertSame(42, ReflectionUtils::cloneOrDeepCopyIfNotImmutable(42));
        self::assertSame(ParityColor::Red, ReflectionUtils::replicate(ParityColor::Red));
    }

    #[Test]
    public function testCloneOrDeepCopyIfNotImmutable_RecursiveReferencesAndDepthThreshold(): void
    {
        $a = new ParityNode('a');
        $b = new ParityNode('b');
        $a->next = $b;
        $b->next = $a;

        $copy = ReflectionUtils::cloneOrDeepCopyIfNotImmutable($a);
        self::assertNotSame($a, $copy);
        self::assertNotSame($b, $copy->next);
        self::assertSame($copy, $copy->next->next, 'the cycle is reproduced on the copies');

        $head = new ParityNode('0');
        $node = $head;
        for ($i = 1; $i <= 6; $i++) {
            $node->next = new ParityNode((string) $i);
            $node = $node->next;
        }
        // Java testCloneOrDeepCopyIfNotImmutable_RecursiveDepthTreshold: threshold 5 keeps levels 0..5, level 6 becomes null
        $limited = ReflectionUtils::cloneOrDeepCopyIfNotImmutable($head, false, 5);
        $level5 = $limited->next->next->next->next->next;
        self::assertSame('5', $level5->name);
        self::assertNull($level5->next, 'deeper than the threshold becomes null');
        self::assertNotNull($head->next->next->next->next->next->next, 'the original is untouched');
        self::assertNull(ReflectionUtils::cloneOrDeepCopyIfNotImmutable($head, false, 0)->next, 'threshold 0: level 1 becomes null');
    }

    #[Test]
    public function testCloneOrDeepCopyIfNotImmutable_EntitiesAreNotCopiedUnlessAsked(): void
    {
        $customer = new ParityCustomer(1);
        self::assertSame($customer, ReflectionUtils::cloneOrDeepCopyIfNotImmutable($customer));
        self::assertNotSame($customer, ReflectionUtils::cloneOrDeepCopyIfNotImmutable($customer, true));
        self::assertTrue(ReflectionUtils::isEntity($customer));
        self::assertFalse(ReflectionUtils::isEntity(new ParityOrderLine('x', 1)));
    }

    #[Test]
    public function testCanCastFromTo(): void
    {
        self::assertTrue(ReflectionUtils::canCastFromTo(ParityVipCustomer::class, ParityCustomer::class));
        self::assertFalse(ReflectionUtils::canCastFromTo(ParityCustomer::class, ParityVipCustomer::class));
        self::assertTrue(ReflectionUtils::canCastAtLeastOneWay(ParityCustomer::class, ParityVipCustomer::class));
        self::assertFalse(ReflectionUtils::canCastAtLeastOneWay(ParityCustomer::class, ParityOrder::class));
        self::assertTrue(ReflectionUtils::canCastFromTo(ParityCustomer::class, IEntity::class));
        self::assertTrue(ReflectionUtils::canCastFromTo('int', 'float'));
        self::assertFalse(ReflectionUtils::canCastFromTo('float', 'int'));
        self::assertTrue(ReflectionUtils::canCastFromTo('string', 'mixed'));
        self::assertFalse(ReflectionUtils::canCastFromTo('null', 'string'));
    }

    #[Test]
    public function aSpecificationCanBeBoundToAPrivateFieldWithoutAGetter(): void
    {
        // Domian: a(Child.class).where("own", is(x)) reads the private field itself (getFieldByName);
        // since 1.6.1 both classes of the fixture opt in with #[Specifiable]
        $child = new ParityChild();

        self::assertTrue(Spec::property('own', Spec::equalTo('child-private'))->isSatisfiedBy($child));
        self::assertTrue(Spec::property('secret', Spec::equalTo('base-private'))->isSatisfiedBy($child), 'private field of the superclass');
        self::assertTrue(Spec::property('prot', Spec::equalTo(7))->isSatisfiedBy($child));
        self::assertTrue(Spec::specify(ParityChild::class)->where('own', Spec::is('child-private'))->isSatisfiedBy($child));
        self::assertSame('child-private', PropertyAccessor::getValue($child, 'own'));
        self::assertTrue(PropertyAccessor::hasProperty($child, 'secret'));
        self::assertFalse(PropertyAccessor::hasProperty($child, 'nope'));
        $this->expectException(InvalidArgumentException::class);
        Spec::property('nope', Spec::equalTo(1))->isSatisfiedBy($child);
    }

    #[Test]
    public function thePrivateFieldIsTheLastResolutionStep(): void
    {
        $shadowed = new ParityShadowed();
        self::assertSame('getter', PropertyAccessor::getValue($shadowed, 'name'), 'a public getter wins over the private field');
        self::assertSame('magic', PropertyAccessor::getValue($shadowed, 'ghost'), '__get guarded by __isset wins over the private field');
        self::assertSame('pub', PropertyAccessor::getValue($shadowed, 'exposed'), 'public property wins');
        self::assertNull(PropertyAccessor::getValue($shadowed, 'lazy'), 'uninitialized typed private property (marked) yields null');
        self::assertTrue(PropertyAccessor::hasProperty($shadowed, 'lazy'));
    }

    ///////////////////////////////////////////////////////////////////////////
    // #[Specifiable] opt-in (1.6.1): a non-public property is reached by name only when marked
    ///////////////////////////////////////////////////////////////////////////

    #[Test]
    public function anUnmarkedPrivatePropertyIsNotReadByName(): void
    {
        $account = new ParityGuardedAccount(1);

        // Control: the marked property of the same object is read
        self::assertSame('active', PropertyAccessor::getValue($account, 'status'));
        self::assertTrue(PropertyAccessor::hasProperty($account, 'status'));
        self::assertTrue(Spec::property('status', Spec::equalTo('active'))->isSatisfiedBy($account));

        // Unmarked private and protected: the result of a property that does not exist
        foreach (['passwordHash', 'failedLogins'] as $name) {
            self::assertNull(PropertyAccessor::getValue($account, $name), $name);
            self::assertFalse(PropertyAccessor::hasProperty($account, $name), $name);
            self::assertSame(
                PropertyAccessor::hasProperty($account, 'nope'),
                PropertyAccessor::hasProperty($account, $name),
                'indistinguishable from a missing property'
            );
            $result = Spec::property($name, Spec::isNotNull())->evaluate($account);
            self::assertTrue($result->isError, $name . ': evaluation error, as a missing property');
            self::assertSame(
                sprintf('Property "%s" not found or not accessible on candidate of type "%s"', $name, ParityGuardedAccount::class),
                $result->exception?->getMessage()
            );
        }
        self::assertFalse(PropertyAccessor::hasProperty($account, 'id'), 'the promoted private id is not marked either');

        // Dot notation stops at the unmarked segment
        self::assertFalse(PropertyAccessor::hasProperty((object) ['account' => $account], 'account.passwordHash'));
        self::assertNull(PropertyAccessor::getValue((object) ['account' => $account], 'account.passwordHash'));
        self::assertSame('active', PropertyAccessor::getValue((object) ['account' => $account], 'account.status'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Property "passwordHash" not found or not accessible');
        Spec::property('passwordHash', Spec::isNotNull())->isSatisfiedBy($account);
    }

    #[Test]
    public function aDeltaCannotWriteAnUnmarkedPrivateProperty(): void
    {
        $repository = new InMemoryRepository();
        $account = new ParityGuardedAccount(2);
        $repository->put($account);

        // Setter first (private property, public setter: no marker needed), marked property written directly
        $repository->updateWithDelta($account, Spec::specify(ParityGuardedAccount::class)
            ->where('email', Spec::is('Ann@Example.com'))
            ->and('status', Spec::is('blocked')));
        self::assertSame('ann@example.com', ReflectionUtils::getFieldValue($account, 'email'), 'the setter ran');
        self::assertSame('blocked', ReflectionUtils::getFieldValue($account, 'status'));

        foreach (['passwordHash' => 'pwned', 'failedLogins' => 0, 'id' => 99] as $name => $value) {
            try {
                SpecificationHelper::updateEntityState($account, Spec::property($name, Spec::is($value)));
                self::fail($name . ' must not be written');
            } catch (InvalidArgumentException $e) {
                self::assertSame(
                    sprintf('Property "%s" of %s is not public and not marked #[Specifiable]; it cannot be written by a delta specification', $name, ParityGuardedAccount::class),
                    $e->getMessage()
                );
            }
        }
        self::assertSame('h4sh', ReflectionUtils::getFieldValue($account, 'passwordHash'), 'mass assignment refused');
        self::assertSame(3, ReflectionUtils::getFieldValue($account, 'failedLogins'));
        self::assertSame(2, $account->getEntityId());

        // A missing property keeps its own message
        try {
            SpecificationHelper::updateEntityState($account, Spec::property('nope', Spec::is(1)));
            self::fail('missing property');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('not found', $e->getMessage());
        }
    }

    #[Test]
    public function aClassMarkerCoversOnlyThePropertiesDeclaredByThatClass(): void
    {
        // Marked parent, unmarked child: the parent's field is reachable, the child's own is not
        $childOfMarked = new ParityChildOfMarked();
        self::assertSame('base', PropertyAccessor::getValue($childOfMarked, 'baseSecret'));
        self::assertTrue(PropertyAccessor::hasProperty($childOfMarked, 'baseSecret'));
        self::assertNull(PropertyAccessor::getValue($childOfMarked, 'childSecret'));
        self::assertFalse(PropertyAccessor::hasProperty($childOfMarked, 'childSecret'), 'the marker does not go down');

        // Unmarked parent, marked child: the child's own field is reachable, the parent's is not
        $markedChild = new ParityMarkedChildOfUnmarked();
        self::assertSame('child', PropertyAccessor::getValue($markedChild, 'childSecret'));
        self::assertTrue(PropertyAccessor::hasProperty($markedChild, 'childSecret'));
        self::assertNull(PropertyAccessor::getValue($markedChild, 'baseSecret'));
        self::assertFalse(PropertyAccessor::hasProperty($markedChild, 'baseSecret'), 'the marker does not go up');

        // The same on the write side: ParityCustomer is marked, ParityVipCustomer is not
        $vip = new ParityVipCustomer(7);
        SpecificationHelper::updateEntityState($vip, Spec::property('secret', Spec::is('inherited')));
        self::assertSame('inherited', ReflectionUtils::getFieldValue($vip, 'secret'), 'declared by the marked class');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Property "vipSince" of ' . ParityVipCustomer::class . ' is not public and not marked #[Specifiable]');
        SpecificationHelper::updateEntityState($vip, Spec::property('vipSince', Spec::is(new DateTimeImmutable())));
    }

    #[Test]
    public function theALinqSpecifiableAttributeIsAcceptedByName(): void
    {
        // #[Antevemus\ALinq\Attributes\Specifiable] is recognized by name: no dependency on the ALinq package
        $onClass = new ParityALinqMarkedClass();
        self::assertSame('A1', PropertyAccessor::getValue($onClass, 'code'));
        self::assertTrue(Spec::property('code', Spec::equalTo('A1'))->isSatisfiedBy($onClass));

        $onProperty = new ParityALinqMarkedProperty();
        self::assertSame('B2', PropertyAccessor::getValue($onProperty, 'code'));
        self::assertFalse(PropertyAccessor::hasProperty($onProperty, 'other'), 'only the marked property');

        self::assertTrue(ReflectionUtils::isSpecifiable(new \ReflectionProperty(ParityALinqMarkedClass::class, 'code')));
        self::assertTrue(ReflectionUtils::isSpecifiable(new \ReflectionProperty(ParityALinqMarkedProperty::class, 'code')));
        self::assertFalse(ReflectionUtils::isSpecifiable(new \ReflectionProperty(ParityALinqMarkedProperty::class, 'other')));
        self::assertTrue(ReflectionUtils::isSpecifiable(new \ReflectionProperty(ParityGuardedAccount::class, 'status')));
        self::assertFalse(ReflectionUtils::isSpecifiable(new \ReflectionProperty(ParityGuardedAccount::class, 'passwordHash')));
        // 2.0.0: the marker of the shared accessor (antevemus/adeep-property-access) counts on the write path too
        self::assertSame(
            [Specifiable::class, 'Antevemus\ALinq\Attributes\Specifiable', 'Antevemus\ADeepPropertyAccess\Attributes\Specifiable'],
            ReflectionUtils::SPECIFIABLE_ATTRIBUTES
        );
    }

    #[Test]
    public function aRangeOverAnUnmarkedPrivatePropertyIsNotAReadOracle(): void
    {
        // The probe of the 1.6.1 finding: a name from a request, a range moved character by character
        $account = new ParityGuardedAccount(3);   // passwordHash = 'h4sh'
        $range = static fn(string $from, string $to): ISpecification =>
            Spec::greaterThanOrEqualTo($from)->and(Spec::lessThanOrEqualTo($to));
        $wouldMatch = Spec::property('passwordHash', $range('a', 'z'));
        $wouldMiss = Spec::property('passwordHash', $range('x', 'y'));
        $prefix = Spec::property('passwordHash', Spec::startsWith('h'));

        // Positive control: on the marked property the very same probe discriminates
        self::assertTrue(Spec::property('status', $range('a', 'z'))->isSatisfiedBy($account));
        self::assertFalse(Spec::property('status', $range('x', 'y'))->isSatisfiedBy($account));
        self::assertTrue(Spec::property('status', Spec::startsWith('a'))->isSatisfiedBy($account));

        // Negative: on the unmarked one both ranges give the same answer, which reveals nothing
        $hit = $wouldMatch->evaluate($account);
        $miss = $wouldMiss->evaluate($account);
        self::assertTrue($hit->isError);
        self::assertTrue($miss->isError);
        self::assertSame($hit->exception?->getMessage(), $miss->exception?->getMessage());
        self::assertSame($hit->exception?->getMessage(), $prefix->evaluate($account)->exception?->getMessage());
        self::assertStringNotContainsString('h4sh', (string) $hit->exception?->getMessage());
        self::assertFalse(Spec::not($wouldMatch)->evaluate($account)->isSatisfied, 'NOT does not approve it');
        self::assertFalse(Spec::not($wouldMiss)->evaluate($account)->isSatisfied);

        // A repository filter by it does not run (same exception for both ranges), nor the ALinq predicate
        $repository = new InMemoryRepository([$account]);
        $messages = [];
        foreach ([$wouldMatch, $wouldMiss, $prefix] as $probe) {
            try {
                $repository->findAll($probe);
                self::fail('the filter must not run over an unmarked private property');
            } catch (InvalidArgumentException $e) {
                $messages[] = $e->getMessage();
            }
            try {
                ALinqSpecificationVisitor::createPredicate($probe)($account);
                self::fail('the ALinq predicate must not run over an unmarked private property');
            } catch (InvalidArgumentException $e) {
                $messages[] = $e->getMessage();
            }
        }
        self::assertCount(1, array_unique($messages), 'one answer for every probe');
    }

    #[Test]
    public function theCompositePropertyReaderFollowsTheSameRule(): void
    {
        $reader = new class (ParityGuardedAccount::class) extends AbstractCompositeSpecification {
            protected function isSpecifyingAllInstancesOfItsType(): bool
            {
                return true;
            }

            public function read(object $object, string $name): mixed
            {
                return $this->getPropertyValue($object, $name);
            }
        };
        $account = new ParityGuardedAccount(4);

        self::assertSame('active', $reader->read($account, 'status'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Property "passwordHash" not found on ' . ParityGuardedAccount::class);
        $reader->read($account, 'passwordHash');
    }

    ///////////////////////////////////////////////////////////////////////////
    // StrictReturnsNullFactoryTest (domian-core; excluded from the Java build, implemented here)
    ///////////////////////////////////////////////////////////////////////////

    #[Test]
    public function testShouldReturnNullForNullSpecifications(): void
    {
        self::assertNull((new StrictReturnsNullFactory())->createObjectSpecifiedBy(null));
        self::assertNull((new StrictReturnsNullFactory())->create(null));
    }

    #[Test]
    public function testShouldReturnNullForTypeOnlySpecificationsWithNoDefaultConstructorAvailable(): void
    {
        // Java a(Double.class) -> null; Spec::specify() only accepts classes and interfaces, so the
        // constructor-only class plays the part of the type without a default constructor.
        self::assertNull((new StrictReturnsNullFactory())->createObjectSpecifiedBy(Spec::specify(ParityStrictClass::class)));
        self::assertNull((new StrictReturnsNullFactory())->createObjectSpecifiedBy(Spec::specify(ParityStaticFactoryOnlyClass::class)));
    }

    #[Test]
    public function testShouldReturnNullForUnsufficientSpecificationsWithNoDefaultConstructorAvailable(): void
    {
        $spec = Spec::specify(ParityStrictClass::class)
            ->where('field1', Spec::is('someValue'))
            ->and('field2', Spec::is(42));
        self::assertNull((new StrictReturnsNullFactory())->createObjectSpecifiedBy($spec), 'field3 is required by the constructor');
    }

    #[Test]
    public function testShouldCreateInstancesFromClassesWithDefaultConstructorsOutOfEmptyLeafSpecifications(): void
    {
        $factory = new StrictReturnsNullFactory();
        $spec = Spec::equalTo('');
        $defaultString = $factory->createObjectSpecifiedBy($spec);
        self::assertSame('', $defaultString);
        self::assertTrue($spec->isSatisfiedBy($defaultString));

        self::assertSame(18, $factory->createObjectSpecifiedBy(Spec::equalTo(18)));
        $twoDaysAgo = new DateTimeImmutable('-2 days');
        self::assertSame($twoDaysAgo, $factory->createObjectSpecifiedBy(Spec::equalTo($twoDaysAgo)));
    }

    #[Test]
    public function testShouldCreateInstancesFromClassesWithDefaultConstructorsOutOfEmptyCompositeSpecifications(): void
    {
        // Java a(String.class) -> new String(): a type-only specification of a class with a default
        // constructor yields a default instance (Spec::specify() does not take scalar type names).
        $factory = new StrictReturnsNullFactory();
        $setterOnly = $factory->createObjectSpecifiedBy(Spec::specify(ParitySetterOnlyClass::class));
        self::assertInstanceOf(ParitySetterOnlyClass::class, $setterOnly);
        self::assertEquals(new ParitySetterOnlyClass(), $setterOnly);
        self::assertNotSame($setterOnly, $factory->createObjectSpecifiedBy(Spec::specify(ParitySetterOnlyClass::class)));
    }

    #[Test]
    public function testShouldCreateMutableValueObject(): void
    {
        $spec = Spec::specify(ParityPublicClass::class)
            ->where('field1', Spec::is('someValue'))
            ->and('field2', Spec::is(42));

        $created = (new StrictReturnsNullFactory())->createObjectSpecifiedBy($spec);
        $expected = new ParityPublicClass('someValue', 42);

        self::assertInstanceOf(ParityPublicClass::class, $created);
        self::assertTrue($spec->isSatisfiedBy($created));
        self::assertEquals($expected, $created);
        self::assertNotSame($expected, $created);
    }

    #[Test]
    public function testShouldCreateImmutabeHibernateStyleValueObject(): void
    {
        $today = new DateTimeImmutable('today');
        $spec = Spec::specify(ParityHibernateStyleClass::class)
            ->where('field1', Spec::is('someValue'))
            ->and('field2', Spec::is(42))
            ->and('field3', Spec::is($today));

        $created = (new StrictReturnsNullFactory())->createObjectSpecifiedBy($spec);

        self::assertInstanceOf(ParityHibernateStyleClass::class, $created);
        self::assertTrue($spec->isSatisfiedBy($created), 'private fields read through the getters');
        self::assertEquals(new ParityHibernateStyleClass('someValue', 42, $today), $created);
    }

    #[Test]
    public function testShouldCreateImmutableValueObject(): void
    {
        $today = new DateTimeImmutable('today');
        $spec = Spec::specify(ParityImmutableClass::class)
            ->where('field1', Spec::is('someValue'))
            ->and('field2', Spec::is(42))
            ->and('field3', Spec::is($today));

        $created = (new StrictReturnsNullFactory())->createObjectSpecifiedBy($spec);

        self::assertInstanceOf(ParityImmutableClass::class, $created);
        self::assertTrue($spec->isSatisfiedBy($created));
        self::assertEquals(new ParityImmutableClass('someValue', 42, $today), $created);
    }

    #[Test]
    public function testShouldCreateConstructorStrictImmutableValueObject(): void
    {
        $today = new DateTimeImmutable('today');
        $spec = Spec::specify(ParityStrictClass::class)
            ->where('field1', Spec::is('someValue'))
            ->and('field2', Spec::is(42))
            ->and('field3', Spec::is($today));

        $created = (new StrictReturnsNullFactory())->createObjectSpecifiedBy($spec);

        self::assertInstanceOf(ParityStrictClass::class, $created);
        self::assertTrue($spec->isSatisfiedBy($created), 'private fields without accessors are read directly');
        self::assertEquals(new ParityStrictClass('someValue', 42, $today), $created);
    }

    #[Test]
    public function testShouldCreateMutableThroughSettersValueObject(): void
    {
        $today = new DateTimeImmutable('today');
        $spec = Spec::specify(ParitySetterOnlyClass::class)
            ->where('field1', Spec::is('someValue'))
            ->and('field2', Spec::is(42))
            ->and('field3', Spec::is($today));

        $created = (new StrictReturnsNullFactory())->createObjectSpecifiedBy($spec);

        $expected = new ParitySetterOnlyClass();
        $expected->setField1('someValue');
        $expected->setField2(42);
        $expected->setField3($today);

        self::assertInstanceOf(ParitySetterOnlyClass::class, $created);
        self::assertTrue($spec->isSatisfiedBy($created));
        self::assertEquals($expected, $created);
        self::assertNotSame($expected, $created);
    }

    #[Test]
    public function testShouldCreateValueObjectSupportingStaticFactoryMethodOnly(): void
    {
        $today = new DateTimeImmutable('today');
        $spec = Spec::specify(ParityStaticFactoryOnlyClass::class)
            ->where('field1', Spec::is('someValue'))
            ->and('field2', Spec::is(42))
            ->and('field3', Spec::is($today));

        $created = (new StrictReturnsNullFactory())->createObjectSpecifiedBy($spec);

        self::assertInstanceOf(ParityStaticFactoryOnlyClass::class, $created);
        self::assertTrue($spec->isSatisfiedBy($created));
        self::assertEquals(ParityStaticFactoryOnlyClass::createInstance('someValue', 42, $today), $created);
    }

    #[Test]
    public function testShouldNotAcceptInterfaceType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Specification type is an interface, unable to create object');
        (new StrictReturnsNullFactory())->createObjectSpecifiedBy(Spec::specify(ISpecification::class));
    }

    #[Test]
    public function strictFactoryReturnsNullInsteadOfAPartialObject(): void
    {
        $factory = new StrictReturnsNullFactory();

        // A clause with no public way in (private field, no setter, not a constructor parameter)
        self::assertNull($factory->createObjectSpecifiedBy(
            Spec::specify(ParitySetterOnlyClass::class)->where('hidden', Spec::is('x'))
        ));

        // A clause that is not bound to a value
        self::assertNull($factory->createObjectSpecifiedBy(
            Spec::specify(ParityPublicClass::class)->where('field1', Spec::isNotNull())->and('field2', Spec::is(1))
        ));

        // A built object that does not satisfy the specification (the setter normalizes the value)
        self::assertNull($factory->createObjectSpecifiedBy(
            Spec::specify(ParityNormalizingClass::class)->where('code', Spec::is('abc'))
        ));
    }

    ///////////////////////////////////////////////////////////////////////////
    // InstrumentationUtils (domian-api)
    ///////////////////////////////////////////////////////////////////////////

    #[Test]
    public function prettyPrintLargeNumber(): void
    {
        self::assertSame('1,234,567', InstrumentationUtils::prettyPrintLargeNumber(1234567));
        self::assertSame('999', InstrumentationUtils::prettyPrintLargeNumber(999));
        self::assertSame('1,000', InstrumentationUtils::prettyPrintLargeNumber(1000));
        self::assertSame('0', InstrumentationUtils::prettyPrintLargeNumber(0));
    }

    #[Test]
    public function instrumentationMessageBuilders(): void
    {
        self::assertSame('...', InstrumentationUtils::DOTS);
        self::assertSame('N/A', InstrumentationUtils::NOT_APPLICABLE);
        self::assertSame('   ', InstrumentationUtils::DEBUG_LEVEL_INDENTATION);
        self::assertSame('      ', InstrumentationUtils::TRACE_LEVEL_INDENTATION);

        $order = new ParityOrder(1);
        self::assertSame('Testing ParityOrder.isPending()...', InstrumentationUtils::buildTestingOfMethodString('isPending', $order));
        self::assertSame('Testing isPending()...', InstrumentationUtils::buildTestingOfMethodString('isPending'));
        self::assertSame('Testing isPending ... [slow]', InstrumentationUtils::buildTestingOfMethodString('isPending', $order, 'slow'));
        self::assertSame('ParityOrder.cancelled()', InstrumentationUtils::buildSimpleClassMethodString($order, 'cancelled'));
        self::assertSame('   #3 [ParityOrder.cancelled()]', InstrumentationUtils::buildDebugLogLevelIterationMessage(3, $order, 'cancelled'));

        $threadMessage = InstrumentationUtils::buildThreadNumberAndMessage('hello');
        self::assertMatchesRegularExpression('/^#\d+\s*hello$/', $threadMessage); // Java "#%-6d" + message
        self::assertStringContainsString('ParityOrder[', InstrumentationUtils::buildThreadNumberAndMessage('hello', $order, 'cancelled'));
        self::assertMatchesRegularExpression('/^\[mem: \d+MB\/\d+MB\]$/', InstrumentationUtils::buildMemoryConsumptionMessage());

        self::assertSame('plain', InstrumentationUtils::buildMessageWithStackTrace('plain', 0));
        $withTrace = InstrumentationUtils::buildMessageWithStackTrace('traced', 2);
        self::assertStringStartsWith('traced' . PHP_EOL . InstrumentationUtils::TRACE_LEVEL_INDENTATION . '#', $withTrace);
        self::assertCount(2, array_filter(explode(PHP_EOL, InstrumentationUtils::getStackTrace(2))));
        self::assertCount(1, array_filter(explode(PHP_EOL, InstrumentationUtils::getStackTraceOf(new RuntimeException('x'), 1))));

        $partition = (new InMemoryRepository())->makePartition();
        $listing = InstrumentationUtils::printPartitionRepository($partition);
        self::assertStringContainsString('Root partition:', $listing);
        self::assertStringContainsString('[InMemoryRepository]', $listing);
        self::assertStringContainsString('[0 entities]', $listing);
    }
}

///////////////////////////////////////////////////////////////////////////////
// Fixtures (the Domian test domain, reduced to what the transcribed tests use)
///////////////////////////////////////////////////////////////////////////////

#[Specifiable]
class ParityCustomer extends AbstractEntity
{
    public int $age = 0;
    private string $secret = '';

    public function __construct(private int $id, private ?string $name = null)
    {
        parent::__construct();
    }

    public function getEntityId(): int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
    }
}

class ParityVipCustomer extends ParityCustomer
{
    private ?DateTimeImmutable $vipSince = null;
}

class ParityOrder extends AbstractEntity
{
    /** @var array<int, ParityOrderLine> */
    public array $lines = [];
    public ?DateTimeImmutable $placedAt = null;
    private bool $pending = true;

    public function __construct(private int $id)
    {
        parent::__construct();
    }

    public function getEntityId(): int
    {
        return $this->id;
    }

    public function isPending(): bool
    {
        return $this->pending;
    }

    public function cancelled(bool $cancelled): self
    {
        $this->pending = !$cancelled;
        return $this;
    }
}

final class ParityOrderLine
{
    public function __construct(public string $product, public int $quantity)
    {
    }
}

final class ParityNode
{
    public ?ParityNode $next = null;

    public function __construct(public string $name)
    {
    }
}

enum ParityColor
{
    case Red;
    case Blue;
}

/** An IEntity that is not an AbstractEntity (the probe's OrderE). */
final class ParityForeignEntity implements IEntity
{
    public function __construct(private int $id)
    {
    }

    public function getEntityId(): int
    {
        return $this->id;
    }

    public function getTimeOfCreation(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    public function equals(IEntity $other): bool
    {
        return $other->getEntityId() === $this->id;
    }
}

#[Specifiable]
class ParityBase
{
    private string $secret = 'base-private';
    protected int $prot = 7;
}

#[Specifiable]
final class ParityChild extends ParityBase
{
    private string $own = 'child-private';
}

final class ParityShadowed
{
    public string $exposed = 'pub';
    private string $name = 'private';
    private string $ghost = 'private';
    #[Specifiable]
    private string $lazy;

    public function getName(): string
    {
        return 'getter';
    }

    public function __isset(string $name): bool
    {
        return $name === 'ghost';
    }

    public function __get(string $name): mixed
    {
        return $name === 'ghost' ? 'magic' : null;
    }
}

/** Java SomePublicClass: public fields, constructor with arguments. */
final class ParityPublicClass
{
    public function __construct(public string $field1, public int $field2)
    {
    }
}

/** Java SomeHibernateStyleClass: private fields, constructor with arguments, getters only. */
final class ParityHibernateStyleClass
{
    public function __construct(private string $field1, private int $field2, private DateTimeImmutable $field3)
    {
    }

    public function getField1(): string
    {
        return $this->field1;
    }

    public function getField2(): int
    {
        return $this->field2;
    }

    public function getField3(): DateTimeImmutable
    {
        return $this->field3;
    }
}

/** Java SomeClassForImmutableObjects: readonly promoted properties. */
final class ParityImmutableClass
{
    public function __construct(
        public readonly string $field1,
        public readonly int $field2,
        public readonly DateTimeImmutable $field3
    ) {
    }
}

/** Java SomeStrictClass: private fields, constructor only, no accessors at all. */
#[Specifiable]
final class ParityStrictClass
{
    public function __construct(private string $field1, private int $field2, private DateTimeImmutable $field3)
    {
    }
}

/** Java SomeSetterOnlyClass: default constructor and setters. */
#[Specifiable]
final class ParitySetterOnlyClass
{
    private string $field1 = '';
    private int $field2 = 0;
    private ?DateTimeImmutable $field3 = null;
    private string $hidden = '';

    public function setField1(string $field1): void
    {
        $this->field1 = $field1;
    }

    public function setField2(int $field2): void
    {
        $this->field2 = $field2;
    }

    public function setField3(DateTimeImmutable $field3): void
    {
        $this->field3 = $field3;
    }
}

/** Java SomeOnlySupportingStaticFactoryMethodClass: private constructor, static createInstance(). */
#[Specifiable]
final class ParityStaticFactoryOnlyClass
{
    private function __construct(private string $field1, private int $field2, private DateTimeImmutable $field3)
    {
    }

    public static function createInstance(string $field1, int $field2, DateTimeImmutable $field3): self
    {
        return new self($field1, $field2, $field3);
    }
}

/** A setter that normalizes the value: the built object does not satisfy the specification. */
final class ParityNormalizingClass
{
    private string $code = '';

    public function setCode(string $code): void
    {
        $this->code = strtoupper($code);
    }
}

/** 1.6.1: one marked and several unmarked non-public properties, no getters (oracle and mass-assignment probe). */
final class ParityGuardedAccount extends AbstractEntity
{
    #[Specifiable]
    private string $status = 'active';
    private string $passwordHash = 'h4sh';
    private string $email = '';
    protected int $failedLogins = 3;

    public function __construct(private int $id)
    {
        parent::__construct();
    }

    public function getEntityId(): int
    {
        return $this->id;
    }

    public function setEmail(string $email): void
    {
        $this->email = strtolower($email);
    }
}

#[Specifiable]
class ParityMarkedBase
{
    private string $baseSecret = 'base';
}

final class ParityChildOfMarked extends ParityMarkedBase
{
    private string $childSecret = 'child';
}

class ParityUnmarkedBase
{
    private string $baseSecret = 'base';
}

#[Specifiable]
final class ParityMarkedChildOfUnmarked extends ParityUnmarkedBase
{
    private string $childSecret = 'child';
}

/** The ALinq marker, by name only (the ALinq package is not required). */
#[\Antevemus\ALinq\Attributes\Specifiable]
final class ParityALinqMarkedClass
{
    private string $code = 'A1';
}

final class ParityALinqMarkedProperty
{
    #[\Antevemus\ALinq\Attributes\Specifiable]
    private string $code = 'B2';
    private string $other = 'x';
}

/** Records the mode of every synchronizer call; nested calls are kept apart from the outermost ones. */
final class RecordingSynchronizer implements ISynchronizer
{
    /** @var array<int, string> */
    private array $modes = [];
    private int $depth = 0;

    public function runConcurrently(callable $action): void
    {
        $this->callConcurrently($action);
    }

    public function callConcurrently(callable $action): mixed
    {
        return $this->record('concurrent', $action);
    }

    public function runExclusively(callable $action): void
    {
        $this->callExclusively($action);
    }

    public function callExclusively(callable $action): mixed
    {
        return $this->record('exclusive', $action);
    }

    /** @return array<int, string> */
    public function outermostModes(): array
    {
        return $this->modes;
    }

    public function reset(): void
    {
        $this->modes = [];
    }

    private function record(string $mode, callable $action): mixed
    {
        if ($this->depth === 0) {
            $this->modes[] = $mode;
        }
        $this->depth++;
        try {
            return $action();
        } finally {
            $this->depth--;
        }
    }
}
