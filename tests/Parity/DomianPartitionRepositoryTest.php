<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Parity;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Repositories\IEntityPersistenceMetaData;
use Antevemus\ASpecification\Contracts\Repositories\IPartitionRepository;
use Antevemus\ASpecification\Contracts\Repositories\IPersistentRepository;
use Antevemus\ASpecification\Contracts\Repositories\PersistenceDefinition;
use Antevemus\ASpecification\Entities\AbstractUUIDEntity;
use Antevemus\ASpecification\Repositories\File\SingleFileRepository;
use Antevemus\ASpecification\Repositories\InMemoryRepository;
use Antevemus\ASpecification\Repositories\PartitionRepository;
use Antevemus\ASpecification\Repositories\PersistentPartitionRepository;
use Antevemus\ASpecification\Repositories\Serialization\JsonEntitySerializer;
use Antevemus\ASpecification\Specifications\Collection\AllEntitiesSpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysTrueSpecification;
use Closure;
use Generator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Entity carrying a mutable tag: the tag stands for the Domian test-domain attributes
 * (customer / adult / pioneer / vip / order ...) so that one leaf specification models every
 * specification of AbstractPartitionedRepositoryTest.
 */
class DomianPartitionEntity extends AbstractUUIDEntity
{
    public function __construct(public string $tag = 'x')
    {
        parent::__construct();
    }
}

/**
 * Leaf specification with exact set algebra: "the tag is one of these". Subsumption is set
 * inclusion, disjunction is empty intersection, equality is set equality. It proves the DAG
 * independently of the subsumption algebra of the real specifications.
 */
class DomianPartitionTagIn extends AbstractSpecification
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
        return DomianPartitionEntity::class;
    }

    public function isSatisfiedBy(?object $candidate): bool
    {
        self::$calls++;
        return $candidate instanceof DomianPartitionEntity && in_array($candidate->tag, $this->tags, true);
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

    public function __toString(): string
    {
        return 'TagIn{' . implode(',', $this->tags) . '}';
    }
}

/**
 * Specification over repository objects (Domian's collectAllPartitionsWithRepositorySatisfying
 * takes a Specification<R extends Repository>): satisfied by leaf partitions.
 */
class DomianLeafPartitionSpec extends AbstractSpecification
{
    public function getType(): string
    {
        return IPartitionRepository::class;
    }

    public function isSatisfiedBy(?object $candidate): bool
    {
        return $candidate instanceof IPartitionRepository && $candidate->isLeaf();
    }
}

/**
 * In-memory repository that also claims the persistent contract and counts load/store/close,
 * so that the propagation of RN-14 through the graph can be observed.
 */
class DomianPartitionSpyRepository extends InMemoryRepository implements IPersistentRepository
{
    public int $loads = 0;
    public int $stores = 0;
    public int $closes = 0;

    public function __construct(string $id)
    {
        parent::__construct([], $id);
    }

    public function getRepositoryId(): string
    {
        return (string) parent::getRepositoryId();
    }

    public function getDataDirectory(): ?string
    {
        return null;
    }

    public function getPersistenceDefinition(): PersistenceDefinition
    {
        return PersistenceDefinition::MemoryOnly;
    }

    public function getFormatDescription(): string
    {
        return 'spy';
    }

    public function load(): void
    {
        $this->loads++;
    }

    public function store(): void
    {
        $this->stores++;
    }

    public function close(): void
    {
        $this->closes++;
    }

    public function getEntityMetaData(IEntity $entity): ?IEntityPersistenceMetaData
    {
        return null;
    }
}

/**
 * DomianPartitionRepositoryTest - executable transcription of Domian's partition behaviour.
 *
 * Sources: AbstractPartitionedRepositoryTest (domian-api), IntroductionExamplesTest and
 * MixedRepositoryImplementations_PartitionRepositoryTest (domian-test-benchmark), plus the
 * probes P1-P12 of docs/paridade-domian-2026-10-09/sondas/probe_B_partitions.php. One test per
 * behaviour; the Domian test domain is modelled with tags (see DomianPartitionTagIn). Native
 * PHPUnit suite, discovered through the "Domian Parity" testsuite of phpunit.xml.dist.
 *
 * Tag glossary: c = plain customer, a = adult customer, p = pioneer customer, ap = adult pioneer,
 * f = foreign, m/fem = male/female, mv/fv = male/female VIP, o = order, lo = large order,
 * ol = order line, n = new customer, cwo = customer with orders, oo = orphan order,
 * wc = order with customer.
 *
 * @version    1.4.4
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Parity
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class DomianPartitionRepositoryTest extends TestCase
{
    // ------------------------------------------------------------------ fixtures

    private function tag(string ...$tags): DomianPartitionTagIn
    {
        return new DomianPartitionTagIn($tags);
    }

    private function entity(string $tag): DomianPartitionEntity
    {
        return new DomianPartitionEntity($tag);
    }

    private function all(): AlwaysTrueSpecification
    {
        return new AlwaysTrueSpecification();
    }

    private function root(): IPartitionRepository
    {
        return PartitionRepository::create(new InMemoryRepository());
    }

    private function same(?object $expected, ?object $actual, string $message = 'expected the very same instance'): void
    {
        $this->assertTrue($expected !== null && $expected === $actual, $message);
    }

    /** @return \Throwable the exception that was thrown */
    private function assertThrows(string $expectedClass, Closure $fn): \Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->assertInstanceOf($expectedClass, $e);
            return $e;
        }
        $this->fail("Expected exception {$expectedClass} was not thrown");
    }

    /** @return array<string> sorted tags of the entities physically stored in the node */
    private function ownTags(IPartitionRepository $node): array
    {
        $tags = array_map(static fn (DomianPartitionEntity $e): string => $e->tag, $node->getEntitiesOfThisPartitionOnly());
        sort($tags);
        return $tags;
    }

    private function tempDir(string $suffix): string
    {
        $dir = sys_get_temp_dir() . '/aspec_domian_partition_' . $suffix . '_' . bin2hex(random_bytes(3));
        mkdir($dir, 0777, true);
        return $dir;
    }

    private function removeDir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    // ------------------------------------------------------------------ organization / invariants

    /** Java: shouldActAsOrdinaryRepositoryWhenNoPartitionsAreAdded */
    public function testShouldActAsOrdinaryRepositoryWhenNoPartitionsAreAdded(): void
    {
        $repo = $this->root();
        $customer122 = $this->entity('m');
        $order = $this->entity('o');

        $repo->put($this->entity('m'));
        $repo->put($this->entity('fv'));
        $repo->put($customer122);
        $repo->put($customer122); // Entity already exists
        $repo->put($order);
        $repo->put($this->entity('mv'));
        $repo->put($this->entity('ol'));

        $this->assertSame(6, $repo->count($this->all()));
        $this->assertCount(0, $repo->getAllPartitions());
        $this->assertCount(0, $repo->getDirectPartitions());
        $this->assertTrue($repo->isRoot() && $repo->isLeaf());
        $this->same($order, $repo->findSingle($this->tag('o')), 'findSingle on a root without partitions');
        $this->assertTrue($repo->contains($customer122));
    }

    /** Java: shouldIgnoreOrderOfPartitionAdded (the general partition is added last) */
    public function testShouldIgnoreOrderOfPartitionAdded(): void
    {
        $femaleVip = $this->tag('fv');
        $maleVip = $this->tag('mv');
        $orders = $this->tag('o', 'lo');
        $largeOrders = $this->tag('lo');
        $customers = $this->tag('m', 'fem', 'mv', 'fv');
        $vipCustomers = $this->tag('mv', 'fv');

        $repo = $this->root();
        $repo->addPartition($femaleVip);
        $repo->addPartitionWithId($maleVip, 'male-vip-customers');
        $repo->addPartitionWithId($orders, 'orders');
        $repo->addPartitionWithId($largeOrders, 'large-orders');
        $customerPartition = $repo->addPartition($customers);

        $customer122 = $this->entity('m');
        $order233 = $this->entity('o');
        $repo->put($this->entity('m'));
        $repo->put($this->entity('fv'));
        $repo->put($customer122);
        $repo->put($customer122);
        $repo->put($order233);
        $repo->put($this->entity('mv'));
        $repo->put($this->entity('ol'));

        $this->assertSame(6, $repo->count($this->all()), '4 customers, 1 order line and 1 order');
        $this->assertCount(2, $repo->getDirectPartitions(), 'customers and orders');
        $this->assertCount(1, $repo->getEntitiesOfThisPartitionOnly(), 'only the order line stays at the root');
        $this->assertCount(5, $repo->getAllPartitions());

        foreach ($repo->getAllPartitions() as $partition) {
            $spec = $partition->getSpecification();
            if ($spec->equals($customers)) {
                $this->same($customerPartition, $partition, 'the customers partition is the node returned by addPartition');
                $this->assertSame(4, $partition->countAll($this->all()));
                $this->assertSame(4, $partition->countAll($customers));
                $this->assertSame(2, $partition->countAll($vipCustomers));
                $this->assertCount(2, $partition->getDirectPartitions(), 'customers absorbed the two VIP partitions');
            } elseif ($spec->equals($maleVip)) {
                $this->assertSame(1, $partition->countAll($this->all()));
                $this->assertCount(0, $partition->getDirectPartitions());
                $this->assertSame('male-vip-customers', $partition->getUnderlyingRepository()->getRepositoryId());
            } elseif ($spec->equals($femaleVip)) {
                $this->assertSame(1, $partition->countAll($vipCustomers));
                $this->assertCount(0, $partition->getDirectPartitions());
            } elseif ($spec->equals($orders)) {
                $found = $partition->find($this->all());
                $this->assertCount(1, $found);
                $this->same($order233, $found[0], 'the order lives in the orders partition');
                $this->assertCount(1, $partition->getDirectPartitions(), 'orders absorbed large-orders');
            }
        }
    }

    /** Java: shouldAddEntitiesToPartitionsWhenPartitionsArePreAdded */
    public function testShouldAddEntitiesToPartitionsWhenPartitionsArePreAdded(): void
    {
        $customers = $this->tag('c', 'v');
        $vipCustomers = $this->tag('v');
        $orders = $this->tag('o');

        $repo = $this->root();
        $repo->addPartition($customers);
        $repo->addPartition($vipCustomers);
        $repo->addPartition($orders);

        $femaleVipCustomer = $this->entity('v');
        $order22 = $this->entity('o');
        $repo->put($this->entity('c'));
        $repo->put($femaleVipCustomer);
        $repo->put($order22);
        $repo->put($this->entity('ol'));

        $this->assertPartitionLayoutOfCustomersVipAndOrders($repo, $customers, $vipCustomers, $orders, $femaleVipCustomer, $order22);
    }

    /** Java: shouldAddEntitiesToPartitionsWhenPartitionsArePostAdded */
    public function testShouldAddEntitiesToPartitionsWhenPartitionsArePostAdded(): void
    {
        $customers = $this->tag('c', 'v');
        $vipCustomers = $this->tag('v');
        $orders = $this->tag('o');

        $repo = $this->root();
        $femaleVipCustomer = $this->entity('v');
        $order22 = $this->entity('o');
        $repo->put($this->entity('c'));
        $repo->put($femaleVipCustomer);
        $repo->put($order22);
        $repo->put($this->entity('ol'));

        $repo->addPartition($customers);
        $repo->addPartition($vipCustomers);
        $repo->addPartition($orders);

        $this->assertPartitionLayoutOfCustomersVipAndOrders($repo, $customers, $vipCustomers, $orders, $femaleVipCustomer, $order22);
    }

    private function assertPartitionLayoutOfCustomersVipAndOrders(
        IPartitionRepository $repo,
        ISpecification $customers,
        ISpecification $vipCustomers,
        ISpecification $orders,
        DomianPartitionEntity $femaleVipCustomer,
        DomianPartitionEntity $order22
    ): void {
        $this->assertCount(3, $repo->getAllPartitions());
        $this->assertSame(4, $repo->count($this->all()));
        $this->assertCount(2, $repo->getDirectPartitions());

        foreach ($repo->getAllPartitions() as $partition) {
            $spec = $partition->getSpecification();
            if ($spec->equals($customers)) {
                $this->assertSame(2, $partition->countAll($this->all()));
                $this->assertCount(1, $partition->getDirectPartitions());
            } elseif ($spec->equals($vipCustomers)) {
                $this->assertSame(1, $partition->countAll($this->all()));
                $this->assertCount(0, $partition->getDirectPartitions());
                $this->same($femaleVipCustomer, $partition->findSingle($vipCustomers), 'same instance from the partition');
                $this->same($repo->findSingle($vipCustomers), $partition->findSingle($vipCustomers), 'same instance from the root');
            } elseif ($spec->equals($orders)) {
                $this->assertSame(1, $partition->countAll($this->all()));
                $this->assertCount(0, $partition->getDirectPartitions());
                $this->same($order22, $partition->findSingle($orders), 'same order instance from the partition');
                $this->same($repo->findSingle($orders), $partition->findSingle($orders), 'same order instance from the root');
            }
        }
    }

    /** Java: shouldRemoveEntititesResidingInDifferentPartitions */
    public function testShouldRemoveEntititesResidingInDifferentPartitions(): void
    {
        $customers = $this->tag('c', 'v');
        $repo = $this->root();
        $repo->addPartition($customers);
        $repo->addPartition($this->tag('v'));
        $repo->addPartition($this->tag('o'));

        $repo->put($this->entity('c'));
        $repo->put($this->entity('v'));
        $repo->put($this->entity('o'));
        $repo->put($this->entity('ol'));

        $this->assertSame(4, $repo->count($this->all()));
        $this->assertSame(2, $repo->removeAll($customers), 'removeAll crosses the customers and vip partitions');
        $this->assertSame(2, $repo->count($this->all()));
        $this->assertSame(0, $repo->count($customers));
    }

    /** Java: shouldTreatAddingOfDuplicatePartitionsAsIdempotentOperations */
    public function testShouldTreatAddingOfDuplicatePartitionsAsIdempotentOperations(): void
    {
        $repo = $this->root();
        $first = $repo->addPartition($this->tag('c', 'v'));
        $second = $repo->addPartition($this->tag('c', 'v'));

        $this->assertCount(1, $repo->getAllPartitions());
        $this->assertTrue($first !== $second, 'an equal specification replaces the partition (RN-02 (a))');
        $this->same($second, $repo->getDirectPartitions()[0], 'the replacement is the direct child');
        $repo->put($this->entity('c'));
        $this->assertSame(1, $repo->count($this->all()));
        $this->assertSame(1, $second->count($this->all()));
    }

    /** RN-02 (a) with a populated subtree: entities of the replaced subtree migrate, its sub-partitions are dropped. */
    public function testShouldReplaceEquivalentPartitionKeepingEntitiesAndDroppingSubPartitions(): void
    {
        $repo = $this->root();
        $ab = $repo->addPartition($this->tag('a', 'b'));
        $a = $repo->addPartition($this->tag('a'));
        $repo->put($this->entity('a'));
        $repo->put($this->entity('a'));
        $repo->put($this->entity('b'));
        $this->assertSame(2, $a->count($this->all()));

        $replacement = $repo->addPartitionWithRepository($this->tag('a', 'b'), new InMemoryRepository());

        $this->assertTrue($replacement !== $ab);
        $this->assertCount(1, $repo->getDirectPartitions());
        $this->same($replacement, $repo->getDirectPartitions()[0], 'the new node took the place of {a,b}');
        $this->assertCount(0, $replacement->getDirectPartitions(), 'sub-partitions of the replaced node are not kept');
        $this->assertSame(3, $replacement->count($this->all()), 'entities of the whole replaced subtree migrated');
        $this->assertSame(3, $repo->count($this->all()));
        $this->same($replacement, $repo->findPartition($this->tag('a')), '{a} no longer exists: {a,b} is the most specific generalization');
    }

    // ------------------------------------------------------------------ boundaries and re-partitioning

    /** Java: shouldNavigateAndAddEntityInSuperRepository_WithWarning (probe P2) */
    public function testShouldNavigateAndAddEntityInSuperRepository(): void
    {
        $repo = $this->root();
        $adultCustomers = $repo->addPartition($this->tag('a'));

        // Wrong partition (a pioneer customer): the entity ends up where it belongs, via the root
        $pioneer = $this->entity('p');
        $adultCustomers->put($pioneer);

        $this->assertSame(1, $repo->countAll($this->all()));
        $this->assertSame(0, $adultCustomers->count($this->tag('a', 'p')));
        $this->assertCount(0, $adultCustomers->getEntitiesOfThisPartitionOnly(), 'nothing is stored in the wrong partition');
        $this->assertCount(1, $repo->getEntitiesOfThisPartitionOnly(), 'the pioneer stays at the root');
        $this->assertTrue($repo->contains($pioneer) && !$adultCustomers->contains($pioneer));
    }

    /** Java: shouldNavigateAndAddEntityInSieblingPartition_WithWarning */
    public function testShouldNavigateAndAddEntityInSieblingPartition(): void
    {
        $customers = $this->tag('a', 'p');
        $ordersSpec = $this->tag('o');
        $repo = $this->root();
        $adultCustomers = $repo->addPartition($this->tag('a'));
        $pioneerCustomers = $repo->addPartition($this->tag('p'));
        $orders = $repo->addPartition($ordersSpec);

        $adultCustomers->put($this->entity('p'));   // wrong partition: routed to pioneers via the root
        $pioneerCustomers->put($this->entity('o')); // wrong partition: routed to orders via the root

        $this->assertSame(2, $repo->countAll($this->all()));
        $this->assertSame(0, $adultCustomers->count($customers));
        $this->assertSame(1, $pioneerCustomers->count($customers));
        $this->assertSame(0, $orders->count($customers));
        $this->assertSame(1, $orders->count($ordersSpec));
        $this->assertCount(0, $adultCustomers->getEntitiesOfThisPartitionOnly());
        $this->assertCount(1, $pioneerCustomers->getEntitiesOfThisPartitionOnly());
        $this->assertCount(1, $orders->getEntitiesOfThisPartitionOnly());
    }

    /** Java Put: at the root, an entity satisfying no partition and not the root specification is rejected (RN-03). */
    public function testShouldRejectAtTheRootAnEntityThatFitsNowhere(): void
    {
        $boundedRoot = new PartitionRepository(new InMemoryRepository(), $this->tag('a', 'b'));
        $a = $boundedRoot->addPartition($this->tag('a'));

        $boundedRoot->put($this->entity('a'));
        $boundedRoot->put($this->entity('b'));
        $this->assertSame(2, $boundedRoot->count($this->all()));
        $this->assertSame(1, $a->count($this->all()));

        $this->assertThrows(InvalidArgumentException::class, function () use ($boundedRoot): void {
            $boundedRoot->put($this->entity('z'));
        });
        $this->assertThrows(InvalidArgumentException::class, function () use ($a): void {
            $a->put($this->entity('z')); // bounced to the root, which rejects it
        });
        $this->assertSame(2, $boundedRoot->count($this->all()), 'nothing was stored by the rejected puts');
        $this->assertSame(['a'], $this->ownTags($a), 'the rejected entity never reached the partition it was put on');
    }

    /** Java: shouldNavigateAndRepartitionEntityInSieblingPartition_WithWarning */
    public function testShouldNavigateAndRepartitionEntityInSieblingPartition(): void
    {
        $orders = $this->tag('oo', 'wc');
        $repo = $this->root();
        $orphanOrders = $repo->addPartition($this->tag('oo'));
        $ordersWithCustomer = $repo->addPartition($this->tag('wc'));

        $orphanOrder = $this->entity('oo');
        $orphanOrders->put($orphanOrder);
        $this->assertSame(1, $orphanOrders->count($orders));

        $orphanOrder->tag = 'wc'; // a customer was attached

        // The order does not exist in this partition: the job is done via the root
        $this->assertTrue($ordersWithCustomer->repartition($orphanOrder));

        $this->assertSame(1, $repo->countAll($this->all()));
        $this->assertSame(1, $ordersWithCustomer->count($orders));
        $this->assertSame(0, $orphanOrders->count($orders));
        $this->assertCount(0, $orphanOrders->getEntitiesOfThisPartitionOnly(), 'no copy is left behind in the old partition');
    }

    /** Java: shouldReturnFalseWhenEntityToRepartitionDoesNotExist (probe P5: nothing is stored) */
    public function testShouldReturnFalseWhenEntityToRepartitionDoesNotExist(): void
    {
        $customers = $this->tag('c', 'cwo', 'mv');

        // Without partitions, as in Java
        $repo = $this->root();
        $repo->put($this->entity('cwo'));
        $repo->put($this->entity('c'));
        $repo->put($this->entity('mv'));
        $this->assertSame(3, $repo->countAll($customers));
        $this->assertFalse($repo->repartition($this->entity('fem')));
        $this->assertSame(3, $repo->countAll($this->all()));

        // With a partition the unknown entity would fit in (probe P5)
        $repo = $this->root();
        $a = $repo->addPartition($this->tag('a'));
        $ghost = $this->entity('a');
        $this->assertFalse($repo->repartition($ghost));
        $this->assertSame(0, $repo->count($this->all()), 'repartition never inserts an unknown entity');
        $this->assertFalse($a->repartition($ghost), 'nor when called on the partition it would fit in');
        $this->assertSame(0, $repo->count($this->all()));
        $this->assertFalse($repo->contains($ghost));
    }

    /** Probe P5b: an entity that already resides in a correct partition reports true. */
    public function testShouldReportTrueWhenRepartitionedEntityStaysWhereItIs(): void
    {
        $repo = $this->root();
        $a = $repo->addPartition($this->tag('a'));
        $e = $this->entity('a');
        $repo->put($e);

        $this->assertTrue($repo->repartition($e));
        $this->assertTrue($a->repartition($e));
        $this->assertSame(1, $a->count($this->all()));
        $this->assertCount(0, $repo->getEntitiesOfThisPartitionOnly());

        $atRoot = $this->entity('z');
        $repo->put($atRoot);
        $this->assertTrue($repo->repartition($atRoot), 'an entity correctly placed at the root reports true as well');
        $this->assertCount(1, $repo->getEntitiesOfThisPartitionOnly());
    }

    /** Java: shouldRepartitionEntities ("default" branch: unique repository instances, memory based) */
    public function testShouldRepartitionEntities(): void
    {
        $customers = $this->tag('c', 'mv', 'cwo');
        $vipCustomers = $this->tag('mv');
        $customersWithOrders = $this->tag('cwo');

        $dynamicCustomer = $this->entity('c');
        $repo = $this->root();
        $this->assertSame(0, $repo->countAll($customers));

        $repo->put($dynamicCustomer);
        $repo->put($this->entity('c'));
        $repo->put($this->entity('mv'));
        $this->assertSame(3, $repo->countAll($customers));
        $this->assertSame(0, $repo->countAll($customersWithOrders));

        $maleVipCustomerPartition = $repo->addPartition($this->tag('mv'));
        $customerWithOrdersPartition = $repo->addPartition($customersWithOrders);

        $this->assertSame(1, $maleVipCustomerPartition->countAll($vipCustomers));
        $this->assertCount(1, $maleVipCustomerPartition->getEntitiesOfThisPartitionOnly());
        $this->assertSame(0, $customerWithOrdersPartition->countAll($customers));
        $this->assertCount(0, $customerWithOrdersPartition->getEntitiesOfThisPartitionOnly());

        $dynamicCustomer->tag = 'cwo'; // an order was added

        // Not correctly partitioned yet (RN-09): the entity still resides at the root
        $this->assertSame(0, $customerWithOrdersPartition->countAll($customers));
        $this->assertCount(0, $customerWithOrdersPartition->getEntitiesOfThisPartitionOnly());
        $this->assertTrue($repo->findSingle($customersWithOrders) !== null, 'found at the root, where it still resides');
        $this->assertSame(3, $repo->countAll($customers));
        $this->assertSame(1, $repo->countAll($customersWithOrders));
        $this->assertCount(2, $repo->getEntitiesOfThisPartitionOnly());

        // Repartitioning, wrapped as an update
        $repo->update($dynamicCustomer);

        $this->assertSame(1, $customerWithOrdersPartition->countAll($customersWithOrders));
        $this->assertCount(1, $customerWithOrdersPartition->getEntitiesOfThisPartitionOnly());
        $this->same($dynamicCustomer, $repo->findSingle($customersWithOrders), 'same instance after repartitioning');
        $this->assertCount(1, $repo->getEntitiesOfThisPartitionOnly());
        $this->assertSame(3, $repo->count($customers));
        $this->assertSame(1, $repo->count($customersWithOrders));

        $dynamicCustomer->tag = 'c'; // orders cleared

        // Not moved back until another re-partitioning, but retrievable with a wide enough specification
        $this->assertSame(1, $customerWithOrdersPartition->countAll($customers));
        $this->assertCount(1, $customerWithOrdersPartition->getEntitiesOfThisPartitionOnly(), 'stale residents are reported');
        $this->assertSame(3, $repo->count($customers));
        $this->assertSame(0, $customerWithOrdersPartition->countAll($customersWithOrders));
        $this->assertTrue($repo->findSingle($customersWithOrders) === null);
        $this->assertSame(0, $repo->count($customersWithOrders));

        // Yet another repartitioning, wrapped as an update
        $repo->update($dynamicCustomer);

        $this->assertSame(1, $maleVipCustomerPartition->countAll($vipCustomers));
        $this->assertCount(1, $maleVipCustomerPartition->getEntitiesOfThisPartitionOnly());
        $this->assertSame(0, $customerWithOrdersPartition->countAll($customers));
        $this->assertCount(0, $customerWithOrdersPartition->getEntitiesOfThisPartitionOnly());
        $this->assertTrue($repo->findSingle($customersWithOrders) === null);
        $this->assertSame(3, $repo->count($customers));
        $this->assertCount(2, $repo->getEntitiesOfThisPartitionOnly());
        $this->assertSame(0, $repo->count($customersWithOrders));
    }

    /** Java: shouldRepartitionEntireRepository (repartition() of the whole repository) */
    public function testShouldRepartitionEntireRepository(): void
    {
        $customers = $this->tag('n', 'cwo', 'mv', 'fv');
        $newCustomers = $this->tag('n');
        $customersWithOrders = $this->tag('cwo');
        $maleVipCustomers = $this->tag('mv');

        $dynamicVipCustomer = $this->entity('n');
        $repo = $this->root();
        $repo->put($dynamicVipCustomer);
        $repo->put($this->entity('mv'));
        $repo->put($this->entity('mv'));
        $this->assertSame(3, $repo->countAll($customers));
        $this->assertSame(1, $repo->countAll($newCustomers));

        $newCustomerPartition = $repo->addPartition($newCustomers);
        $customerWithOrdersPartition = $repo->addPartition($customersWithOrders);
        $maleVipCustomerPartition = $repo->addPartition($maleVipCustomers);

        $this->assertSame(1, $newCustomerPartition->countAll($customers));
        $this->assertSame(0, $customerWithOrdersPartition->countAll($customers));
        $this->assertSame(2, $maleVipCustomerPartition->countAll($customers));

        $dynamicVipCustomer->tag = 'cwo'; // not a new customer anymore

        $this->assertSame(1, $newCustomerPartition->countAll($customers), 'still residing in the new-customers partition');
        $this->assertSame(0, $customerWithOrdersPartition->countAll($customers));

        $this->assertSame(1, $repo->repartitionAll(), 'exactly one entity changed residence');

        $this->assertSame(3, $repo->countAll($customers));
        $this->assertSame(0, $repo->countAll($newCustomers));
        $this->assertSame(1, $repo->countAll($customersWithOrders));
        $this->assertSame(0, $newCustomerPartition->countAll($customers));
        $this->assertSame(1, $customerWithOrdersPartition->countAll($customers));
        $this->assertSame(2, $maleVipCustomerPartition->countAll($customers));
        $this->assertCount(0, $repo->getEntitiesOfThisPartitionOnly());

        $this->assertSame(0, $repo->repartitionAll(), 'a second pass relocates nothing');
    }

    /** Java: MixedRepositoryImplementations_PartitionRepositoryTest.shouldHandleRepartitioningWhenDifferentRepositoryTypesInvolved */
    public function testShouldHandleRepartitioningWhenDifferentRepositoryTypesInvolved(): void
    {
        $dir = $this->tempDir('mixed');
        try {
            $serializer = new JsonEntitySerializer(DomianPartitionEntity::class);
            $file = new SingleFileRepository($dir . '/root.json', DomianPartitionEntity::class, PersistenceDefinition::ReadWrite, $serializer, 'root');
            $repo = $file->makePartition();
            $this->assertInstanceOf(PersistentPartitionRepository::class, $repo);

            $dynamicVipCustomer = $this->entity('n');
            $repo->put($dynamicVipCustomer);

            $newCustomerPartition = $repo->addPartitionWithRepository($this->tag('n'), new InMemoryRepository());
            $ordersFile = new SingleFileRepository($dir . '/cwo.json', DomianPartitionEntity::class, PersistenceDefinition::ReadWrite, $serializer, 'customers-with-orders');
            $customerWithOrdersPartition = $repo->addPartitionWithRepository($this->tag('cwo'), $ordersFile);

            $this->assertCount(1, $newCustomerPartition->getEntitiesOfThisPartitionOnly());
            $this->assertCount(0, $customerWithOrdersPartition->getEntitiesOfThisPartitionOnly());

            $dynamicVipCustomer->tag = 'cwo';

            // Unstable before update (repartitioning)
            $this->assertCount(1, $newCustomerPartition->getEntitiesOfThisPartitionOnly());
            $this->assertCount(0, $customerWithOrdersPartition->getEntitiesOfThisPartitionOnly());

            $repo->update($dynamicVipCustomer);

            $this->assertCount(0, $newCustomerPartition->getEntitiesOfThisPartitionOnly());
            $this->assertCount(1, $customerWithOrdersPartition->getEntitiesOfThisPartitionOnly());
            $this->assertSame(0, $repo->countAll($this->tag('n')));
            $this->assertSame(1, $repo->countAll($this->tag('cwo')));
            $this->assertSame(1, $repo->countAll($this->all()));

            // The persistent partition really holds it: a fresh instance over the same file sees it
            $repo->store();
            $reopened = new SingleFileRepository($dir . '/cwo.json', DomianPartitionEntity::class, PersistenceDefinition::ReadWrite, $serializer, 'customers-with-orders');
            $this->assertSame(1, $reopened->count($this->all()));
            $this->assertSame(0, (new SingleFileRepository($dir . '/root.json', DomianPartitionEntity::class, PersistenceDefinition::ReadWrite, $serializer, 'root'))->count($this->all()));
        } finally {
            $this->removeDir($dir);
        }
    }

    // ------------------------------------------------------------------ the DAG

    /** Java: shouldHandleParallellSpecialCaseOfPartitions (probe_partition_dag.php) */
    public function testShouldHandleParallellSpecialCaseOfPartitions(): void
    {
        $customers = $this->tag('c', 'a', 'p', 'ap');
        $adultCustomers = $this->tag('a', 'ap');
        $pioneerCustomers = $this->tag('p', 'ap');
        $adultPioneerCustomers = $this->tag('ap');

        // Partition semantics OK?
        $this->assertTrue($adultCustomers->isGeneralizationOf($adultPioneerCustomers));
        $this->assertTrue($pioneerCustomers->isGeneralizationOf($adultPioneerCustomers));
        $this->assertTrue($adultPioneerCustomers->isSpecialCaseOf($adultCustomers));
        $this->assertTrue($adultPioneerCustomers->isSpecialCaseOf($pioneerCustomers));

        $repo = $this->root();
        $repo->put($this->entity('c'));  // customer1
        $repo->put($this->entity('a'));  // customer2: adult
        $repo->put($this->entity('c'));  // customer3
        $repo->put($this->entity('ap')); // customer4: adult pioneer
        $repo->put($this->entity('p'));  // customer5: pioneer
        $repo->put($this->entity('o'));  // order with no customer

        $this->assertSame(6, $repo->count($this->all()));
        $this->assertSame(5, $repo->count($customers));
        $this->assertSame(2, $repo->count($adultCustomers));
        $this->assertSame(2, $repo->count($pioneerCustomers));
        $this->assertSame(1, $repo->count($adultPioneerCustomers));

        $rootPartition = $repo;
        $pioneerCustomerPartition = $repo->addPartition($pioneerCustomers);
        $customerPartition = $repo->addPartition($customers);
        $adultCustomerPartition = $repo->addPartition($adultCustomers);

        $this->assertSame(6, $repo->count($this->all()));
        $this->assertSame(5, $repo->count($customers));
        $this->assertSame(2, $repo->count($adultCustomers));
        $this->assertSame(2, $repo->count($pioneerCustomers));
        $this->assertSame(1, $repo->count($adultPioneerCustomers));

        $this->assertSame(5, $rootPartition->countAll($customers));
        $this->assertSame(5, $customerPartition->countAll($customers));
        $this->assertSame(2, $adultCustomerPartition->countAll($customers));
        $this->assertSame(2, $pioneerCustomerPartition->countAll($customers));
        $this->assertCount(1, $rootPartition->getDirectPartitions(), 'only customers at the root');
        $this->assertCount(2, $customerPartition->getDirectPartitions(), 'adult and pioneer nested under customers');
        $this->assertCount(0, $adultCustomerPartition->getDirectPartitions());
        $this->assertCount(0, $pioneerCustomerPartition->getDirectPartitions());
        $this->same($customerPartition, $pioneerCustomerPartition->getParentRepository(), 'pioneer was re-wired under customers');

        $adultPioneerCustomerPartition = $repo->addPartition($adultPioneerCustomers);

        $this->assertSame(5, $rootPartition->countAll($customers));
        $this->assertSame(5, $customerPartition->countAll($customers));
        $this->assertSame(2, $adultCustomerPartition->countAll($customers));
        $this->assertSame(2, $pioneerCustomerPartition->countAll($customers));
        $this->assertSame(1, $adultPioneerCustomerPartition->countAll($customers));
        $this->assertCount(1, $rootPartition->getDirectPartitions());
        $this->assertCount(2, $customerPartition->getDirectPartitions());

        // Both parallel customer partitions reference the same adultPioneerCustomerPartition
        $this->assertCount(1, $adultCustomerPartition->getDirectPartitions());
        $this->assertCount(1, $pioneerCustomerPartition->getDirectPartitions());
        $this->same($adultPioneerCustomerPartition, $adultCustomerPartition->getDirectPartitions()[0], 'assertSame via adult');
        $this->same($adultPioneerCustomerPartition, $pioneerCustomerPartition->getDirectPartitions()[0], 'assertSame via pioneer');
        $this->assertCount(0, $adultPioneerCustomerPartition->getDirectPartitions());
        $this->assertCount(4, $repo->getAllPartitions(), 'the shared node is listed once');

        $this->assertSame(6, $repo->count($this->all()));
        $this->assertSame(5, $repo->count($customers));
        $this->assertSame(2, $repo->count($adultCustomers));
        $this->assertSame(2, $repo->count($pioneerCustomers));
        $this->assertSame(1, $repo->count($adultPioneerCustomers));
        $this->assertCount(1, $adultPioneerCustomerPartition->getEntitiesOfThisPartitionOnly());
        $this->assertCount(1, $adultCustomerPartition->getEntitiesOfThisPartitionOnly());
        $this->assertCount(1, $pioneerCustomerPartition->getEntitiesOfThisPartitionOnly());
        $this->assertCount(2, $customerPartition->getEntitiesOfThisPartitionOnly());
    }

    /** @return array<string, DomianPartitionTagIn> the seven specifications of shouldFormADirectedAsyclicGraphOfPartitions */
    private function dagSpecifications(): array
    {
        return [
            'pioneer' => $this->tag('p', 'ap', 'pf', 'apf'),
            'adult' => $this->tag('a', 'ap', 'af', 'apf'),
            'pioneerForeign' => $this->tag('pf', 'apf'),
            'adultForeign' => $this->tag('af', 'apf'),
            'adultPioneer' => $this->tag('ap', 'apf'),
            'adultPioneerForeign' => $this->tag('apf'),
            'foreign' => $this->tag('f', 'af', 'pf', 'apf'),
        ];
    }

    /** @return array<DomianPartitionEntity> the six customers of shouldFormADirectedAsyclicGraphOfPartitions */
    private function dagEntities(): array
    {
        return [
            $this->entity('c'),   // customer1
            $this->entity('a'),   // customer2: adult
            $this->entity('c'),   // customer3
            $this->entity('ap'),  // customer4: adult pioneer
            $this->entity('p'),   // customer5: pioneer
            $this->entity('apf'), // customer6: adult pioneer foreign
        ];
    }

    /** Java: shouldFormADirectedAsyclicGraphOfPartitions */
    public function testShouldFormADirectedAsyclicGraphOfPartitions(): void
    {
        $customers = $this->tag('c', 'a', 'p', 'f', 'ap', 'af', 'pf', 'apf');
        $specs = $this->dagSpecifications();
        $repo = $this->root();

        $nodes = [];
        foreach ($specs as $name => $spec) {
            $nodes[$name] = $repo->addPartition($spec);
        }
        foreach ($this->dagEntities() as $entity) {
            $repo->put($entity);
        }

        $this->assertSame(6, $repo->count($this->all()));
        $this->assertSame(6, $repo->count($customers));
        $this->assertSame(3, $repo->count($specs['adult']));
        $this->assertSame(3, $repo->count($specs['pioneer']));
        $this->assertSame(2, $repo->count($specs['adultPioneer']));

        $this->assertCount(3, $repo->getDirectPartitions());
        $this->assertCount(2, $nodes['pioneer']->getDirectPartitions());
        $this->assertCount(2, $nodes['adult']->getDirectPartitions());
        $this->assertCount(1, $nodes['pioneerForeign']->getDirectPartitions());
        $this->assertCount(1, $nodes['adultForeign']->getDirectPartitions());
        $this->assertCount(1, $nodes['adultPioneer']->getDirectPartitions());
        $this->assertCount(0, $nodes['adultPioneerForeign']->getDirectPartitions());
        $this->assertCount(2, $nodes['foreign']->getDirectPartitions());
        $this->assertCount(7, $repo->getAllPartitions());

        $this->assertCount(1, $nodes['pioneer']->getEntitiesOfThisPartitionOnly());
        $this->assertCount(1, $nodes['adult']->getEntitiesOfThisPartitionOnly());
        $this->assertCount(0, $nodes['pioneerForeign']->getEntitiesOfThisPartitionOnly());
        $this->assertCount(0, $nodes['adultForeign']->getEntitiesOfThisPartitionOnly());
        $this->assertCount(1, $nodes['adultPioneer']->getEntitiesOfThisPartitionOnly());
        $this->assertCount(1, $nodes['adultPioneerForeign']->getEntitiesOfThisPartitionOnly());
        $this->assertCount(0, $nodes['foreign']->getEntitiesOfThisPartitionOnly());

        // Every path to adultPioneerForeign leads to the same node
        $apf = $nodes['adultPioneerForeign'];
        $this->same($apf, $nodes['pioneerForeign']->getDirectPartitions()[0], 'via pioneer-foreign');
        $this->same($apf, $nodes['adultForeign']->getDirectPartitions()[0], 'via adult-foreign');
        $this->same($apf, $nodes['adultPioneer']->getDirectPartitions()[0], 'via adult-pioneer');
    }

    /** Java: shouldFindSingleEntityWhenSeveralTraversalsLeadToIt_parallellSpecialCaseOfPartitions */
    public function testShouldFindSingleEntityWhenSeveralTraversalsLeadToIt(): void
    {
        $customers = $this->tag('c', 'a', 'p', 'ap');
        $adultCustomers = $this->tag('a', 'ap');
        $pioneerCustomers = $this->tag('p', 'ap');
        $adultPioneerCustomers = $this->tag('ap');

        $repo = $this->root();
        $repo->put($this->entity('c'));
        $repo->put($this->entity('a'));
        $repo->put($this->entity('c'));
        $repo->put($this->entity('ap'));
        $repo->put($this->entity('p'));

        $this->assertSame(2, $repo->count($pioneerCustomers));
        $this->assertSame(2, $repo->count($adultCustomers));
        $this->assertSame(1, $repo->count($adultPioneerCustomers));

        // A different insertion order than shouldHandleParallellSpecialCaseOfPartitions
        $repo->addPartition($customers);
        $adultPioneerPartition = $repo->addPartition($adultPioneerCustomers);
        $pioneerPartition = $repo->addPartition($pioneerCustomers);
        $adultPartition = $repo->addPartition($adultCustomers);

        $this->assertSame(2, $repo->count($pioneerCustomers));
        $this->assertSame(2, $repo->count($adultCustomers));
        $this->assertSame(1, $repo->count($adultPioneerCustomers));
        $this->assertCount(2, $repo->findAllEntitiesSpecifiedBy($pioneerCustomers));
        $this->assertCount(2, $repo->findAllEntitiesSpecifiedBy($adultCustomers));
        $this->assertCount(1, $repo->findAllEntitiesSpecifiedBy($adultPioneerCustomers));
        $this->assertTrue($repo->findSingleEntitySpecifiedBy($adultPioneerCustomers) !== null, 'one entity, reachable by two traversals');

        $this->same($adultPioneerPartition, $pioneerPartition->getDirectPartitions()[0], 'shared via pioneer');
        $this->same($adultPioneerPartition, $adultPartition->getDirectPartitions()[0], 'shared via adult');
        $this->assertCount(4, $repo->getAllPartitions());
    }

    /** Probe P1: inserting a generalization re-wires the existing node, keeping its sub-partitions. */
    public function testShouldKeepSubPartitionsWhenAGeneralizationIsInsertedAbove(): void
    {
        $repo = $this->root();
        $ab = $repo->addPartition($this->tag('a', 'b'));
        $a = $repo->addPartition($this->tag('a'));
        $this->assertCount(1, $repo->getDirectPartitions());
        $this->assertCount(1, $ab->getDirectPartitions());
        $this->assertCount(2, $repo->getAllPartitions());

        $abc = $repo->addPartition($this->tag('a', 'b', 'c'));

        $this->assertCount(1, $repo->getDirectPartitions());
        $this->same($abc, $repo->getDirectPartitions()[0], '{a,b,c} is now the direct child of the root');
        $this->assertCount(1, $abc->getDirectPartitions());
        $this->same($ab, $abc->getDirectPartitions()[0], 'the existing {a,b} node was re-used, not recreated');
        $this->assertCount(1, $ab->getDirectPartitions());
        $this->same($a, $ab->getDirectPartitions()[0], 'the grandchild {a} survived the relocation');
        $this->assertCount(3, $repo->getAllPartitions());
        $this->same($abc, $ab->getParentRepository(), 'parent pointer follows the relocation');
        $this->same($abc->getSpecification(), $ab->getParentSpecification(), 'parent specification follows the relocation');
        $this->same($repo, $a->getRootPartition(), 'the root is unchanged');
        $this->same($a, $repo->findPartition($this->tag('a')), 'findPartition still reaches {a}');

        $repo->put($this->entity('a'));
        $this->assertSame(1, $a->count($this->all()));
        $this->assertSame(1, $abc->count($this->all()));
        $this->assertSame(1, $repo->count($this->all()));
        $this->assertCount(0, $abc->getEntitiesOfThisPartitionOnly());
    }

    /** Probe P3: findPartition descends through generalizations (Domian findPartitionFor). */
    public function testShouldFindTheMostSpecificPartitionGeneralizingASpecification(): void
    {
        $repo = $this->root();
        $ab = $repo->addPartition($this->tag('a', 'b'));

        $this->same($ab, $repo->findPartition($this->tag('a')), 'narrower spec: deepest generalizing partition');
        $this->same($ab, $repo->findPartition($this->tag('a', 'b')), 'exact spec');
        $this->same($repo, $repo->findPartition($this->tag('z')), 'nothing generalizes {z}: the unconstrained root itself, never null');
        $this->same($repo, $repo->findPartition($this->tag('a', 'b', 'c')), 'wider than every partition: the root');
        $this->assertTrue($ab->findPartition($this->tag('z')) === null, 'outside a bounded node: null');
        $this->same($ab, $ab->findPartition($this->tag('b')), 'inside a bounded node: itself');

        $a = $repo->addPartition($this->tag('a'));
        $this->same($a, $repo->findPartition($this->tag('a')));
        $this->same($ab, $repo->findPartition($this->tag('b')));
        $this->same($a, $ab->findPartition($this->tag('a')));
    }

    /** Probe P4: a sibling added after the entities receives the intersecting ones. */
    public function testShouldCopyIntersectingEntitiesIntoASiblingAddedAfterwards(): void
    {
        $repo = $this->root();
        $ab = $repo->addPartition($this->tag('a', 'b'));
        $b1 = $this->entity('b');
        $repo->put($b1);
        $repo->put($this->entity('a'));

        $bc = $repo->addPartition($this->tag('b', 'c'));

        $this->assertCount(1, $bc->getEntitiesOfThisPartitionOnly(), 'the intersecting entity was copied');
        $this->assertSame(1, $bc->count($this->all()));
        $this->assertSame(2, $ab->count($this->all()));
        $this->assertSame(2, $repo->count($this->all()), 'no duplicate in the aggregated result');
        $this->assertTrue($bc->contains($b1) && $ab->contains($b1));

        $repo->put($this->entity('b'));
        $this->assertSame(2, $bc->count($this->all()));
        $this->assertSame(3, $ab->count($this->all()));
        $this->assertSame(3, $repo->count($this->all()));
    }

    /** Probe P9: a special case of two siblings is ONE node referenced by both parents. */
    public function testShouldShareOneNodeBetweenParallelParents(): void
    {
        $repo = $this->root();
        $ab = $repo->addPartition($this->tag('a', 'b'));
        $bc = $repo->addPartition($this->tag('b', 'c'));
        $b = $repo->addPartition($this->tag('b'));

        $this->assertCount(1, $ab->getDirectPartitions());
        $this->assertCount(1, $bc->getDirectPartitions());
        $this->same($b, $ab->getDirectPartitions()[0], 'returned node === child of {a,b}');
        $this->same($b, $bc->getDirectPartitions()[0], 'returned node === child of {b,c}');
        $this->assertCount(3, $repo->getAllPartitions(), 'the shared node is collected once');
        $this->assertCount(3, $repo->collectPartitions());
        $this->same($bc, $b->getParentRepository(), 'a shared node reports the parent wired last');

        $repo->put($this->entity('b'));
        $this->assertSame(1, $repo->count($this->all()));
        $this->assertSame(1, $ab->count($this->all()));
        $this->assertSame(1, $bc->count($this->all()));
        $this->assertSame(1, $b->count($this->all()));
        $this->assertCount(0, $ab->getEntitiesOfThisPartitionOnly());
        $this->assertCount(0, $bc->getEntitiesOfThisPartitionOnly());
        $this->assertCount(1, $b->getEntitiesOfThisPartitionOnly());
    }

    /** Java: shouldIgnoreOrderOfPartitionAdded, generalized: every permutation builds the same graph. */
    public function testShouldBuildTheSameGraphWhateverTheInsertionOrder(): void
    {
        // The five specifications of shouldIgnoreOrderOfPartitionAdded, entities put after the partitions
        $sets = [
            'customers/vip/orders' => [
                [$this->tag('fv'), $this->tag('mv'), $this->tag('o', 'lo'), $this->tag('lo'), $this->tag('m', 'fem', 'mv', 'fv')],
                ['m', 'fv', 'o', 'mv', 'ol', 'lo'],
            ],
            // The seven specifications of shouldFormADirectedAsyclicGraphOfPartitions
            'dag' => [
                array_values($this->dagSpecifications()),
                ['c', 'a', 'c', 'ap', 'p', 'apf'],
            ],
        ];

        foreach ($sets as $name => [$specs, $tags]) {
            $reference = null;
            $permutations = 0;
            foreach ($this->permutations(array_keys($specs)) as $order) {
                $repo = $this->root();
                foreach ($order as $index) {
                    $repo->addPartition($specs[$index]);
                }
                foreach ($tags as $tag) {
                    $repo->put($this->entity($tag));
                }
                $signature = $this->graphSignature($repo);
                $reference ??= $signature;
                if ($signature !== $reference) {
                    $this->assertTrue(false, "{$name}: order [" . implode(',', $order) . "] built a different graph:\n{$signature}\nexpected:\n{$reference}");
                }
                $permutations++;
            }
            $this->assertSame(count($specs) === 5 ? 120 : 5040, $permutations);
            $this->assertTrue($reference !== null && $reference !== '');
        }

        // Entities put BEFORE the partitions reach the same layout as entities put after
        $specs = array_values($this->dagSpecifications());
        $before = $this->root();
        foreach ($this->dagEntities() as $entity) {
            $before->put($entity);
        }
        foreach ($specs as $spec) {
            $before->addPartition($spec);
        }
        $after = $this->root();
        foreach ($specs as $spec) {
            $after->addPartition($spec);
        }
        foreach ($this->dagEntities() as $entity) {
            $after->put($entity);
        }
        $this->assertSame($this->graphSignature($after), $this->graphSignature($before), 'pre-added and post-added partitions agree');
    }

    /**
     * @param array<int> $items
     * @return Generator<array<int>>
     */
    private function permutations(array $items): Generator
    {
        if (count($items) <= 1) {
            yield $items;
            return;
        }
        foreach ($items as $i => $item) {
            $rest = $items;
            unset($rest[$i]);
            foreach ($this->permutations(array_values($rest)) as $permutation) {
                yield array_merge([$item], $permutation);
            }
        }
    }

    /** Canonical description of a graph: every node with its sorted children and its own entity tags. */
    private function graphSignature(IPartitionRepository $root): string
    {
        $describe = function (IPartitionRepository $node): string {
            $children = array_map(static fn (IPartitionRepository $c): string => (string) $c->getSpecification(), $node->getDirectPartitions());
            sort($children);
            return ((string) ($node->getSpecification() ?? 'ROOT')) . ' -> [' . implode(' ', $children) . '] own=[' . implode(' ', $this->ownTags($node)) . ']';
        };
        $lines = [$describe($root)];
        foreach ($root->getAllPartitions() as $partition) {
            $lines[] = $describe($partition);
        }
        sort($lines);
        return implode("\n", $lines);
    }

    // ------------------------------------------------------------------ iterators

    /** Java: shouldIterateSpecifiedEntitiesOnly */
    public function testShouldIterateSpecifiedEntitiesOnly(): void
    {
        $customers = $this->tag('m', 'fem', 'mv', 'fv');
        $orders = $this->tag('o');
        $repo = $this->root();
        $repo->addPartition($this->tag('fv'));
        $repo->addPartition($customers);
        $repo->addPartition($this->tag('mv'));
        $repo->addPartition($orders);

        $maleVip = $this->entity('mv');
        $male = $this->entity('m');
        $femaleVip = $this->entity('fv');
        $order22 = $this->entity('o');
        $repo->put($maleVip);
        $repo->put($male);
        $repo->put($femaleVip);
        $repo->put($order22);
        $repo->put($this->entity('ol'));

        $this->assertSame(5, $repo->count($this->all()));

        $iterated = [];
        foreach ($repo->iterateAll($customers) as $entity) {
            $this->assertTrue(in_array($entity, [$maleVip, $male, $femaleVip], true), 'only customers are iterated');
            $iterated[] = $entity;
        }
        $this->assertCount(3, $iterated);

        $iterator = $repo->iterateAll($orders);
        $this->assertTrue($iterator->valid());
        $this->same($order22, $iterator->current());
        $iterator->next();
        $this->assertFalse($iterator->valid());

        $this->assertCount(5, iterator_to_array($repo->iterateAll($this->all()), false));
    }

    /** Java: shouldNotIterateThroughEntitiesTwice */
    public function testShouldNotIterateThroughEntitiesTwice(): void
    {
        $customers = $this->tag('n', 'cwo', 'mv');
        $repo = $this->root();
        $repo->addPartition($this->tag('n'));
        $repo->addPartition($this->tag('cwo'));
        $repo->addPartition($this->tag('mv'));
        $repo->put($this->entity('n'));
        $repo->put($this->entity('mv'));
        $repo->put($this->entity('mv'));

        $iterator = $repo->iterate($customers);
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($iterator->valid(), "element {$i} is available");
            $ids[] = $iterator->current()->getEntityId();
            $iterator->next();
        }
        $this->assertFalse($iterator->valid(), 'no fourth element');
        $this->assertTrue($iterator->current() === null, 'exhausted generator yields nothing');
        $this->assertCount(3, array_unique($ids), 'no entity twice');

        // An entity residing in two sibling partitions is iterated once
        $repo = $this->root();
        $repo->addPartition($this->tag('a', 'b'));
        $repo->addPartition($this->tag('b', 'c'));
        $repo->put($this->entity('b'));
        $this->assertCount(1, iterator_to_array($repo->iterate($this->all()), false));
    }

    /** Probe P6: iteration on a partitioned repository is lazy. */
    public function testShouldIterateLazily(): void
    {
        $repo = $this->root();
        $repo->addPartition($this->tag('a'));
        for ($i = 0; $i < 50; $i++) {
            $repo->put($this->entity($i % 2 ? 'a' : 'z'));
        }
        $spec = $this->tag('a', 'z');

        DomianPartitionTagIn::$calls = 0;
        $generator = $repo->iterateAllEntitiesSpecifiedBy($spec);
        $this->assertInstanceOf(Generator::class, $generator);
        $this->assertSame(0, DomianPartitionTagIn::$calls, 'nothing is evaluated before the first element is requested');
        $this->assertTrue($generator->current() instanceof DomianPartitionEntity);
        $this->assertSame(1, DomianPartitionTagIn::$calls, 'one evaluation reaches the first element (was 50 when materialized)');

        // The partition's own generator is consumed on demand as well: the 25 z's stored at the
        // root are rejected first, then the first a of the partition is yielded (26 of 50)
        DomianPartitionTagIn::$calls = 0;
        $generator = $repo->iterateAllEntitiesSpecifiedBy($this->tag('a'));
        $generator->current();
        $this->assertSame(26, DomianPartitionTagIn::$calls, 'only what is needed to reach the first element of the partition');
        $this->assertCount(25, iterator_to_array($generator, false));
        $this->assertSame(50, DomianPartitionTagIn::$calls, 'and the rest only when consumed');
    }

    /** RN-18 (Domian IteratorRegistry): a partition added during an iteration is visited, without repeating entities. */
    public function testShouldIteratePartitionsAddedDuringIteration(): void
    {
        $repo = $this->root();
        $ab = $repo->addPartition($this->tag('a', 'b'));
        $repo->put($this->entity('a'));
        $repo->put($this->entity('a'));
        $repo->put($this->entity('a'));

        $generator = $repo->iterateAll($this->tag('a', 'b'));
        $seen = [$generator->current()->getEntityId()];
        $generator->next();

        // While the iteration is paused, a special case of {a,b} appears and absorbs the a's
        $a = $repo->addPartition($this->tag('a'));
        $this->assertCount(3, $a->getEntitiesOfThisPartitionOnly());
        $this->assertCount(0, $ab->getEntitiesOfThisPartitionOnly());
        $late = $this->entity('a');
        $repo->put($late);

        while ($generator->valid()) {
            $seen[] = $generator->current()->getEntityId();
            $generator->next();
        }
        $this->assertCount(4, $seen, 'the three original entities plus the one put into the new partition');
        $this->assertCount(4, array_unique($seen), 'none of them twice');
        $this->assertTrue(in_array($late->getEntityId(), $seen, true), 'the new partition was visited');
    }

    /** RN-05 / RN-07: an entity residing in sibling partitions is found once and removed everywhere. */
    public function testShouldRemoveAnEntityFromEverySiblingPartition(): void
    {
        $repo = $this->root();
        $ab = $repo->addPartition($this->tag('a', 'b'));
        $bc = $repo->addPartition($this->tag('b', 'c'));
        $b = $this->entity('b');
        $repo->put($b);

        $this->assertTrue($ab->contains($b) && $bc->contains($b));
        $this->assertCount(1, $repo->find($this->all()));
        $this->assertTrue($repo->remove($b));
        $this->assertFalse($repo->contains($b));
        $this->assertFalse($ab->contains($b) || $bc->contains($b));
        $this->assertSame(0, $repo->count($this->all()));
        $this->assertFalse($repo->remove($b), 'second removal finds nothing');
    }

    // ------------------------------------------------------------------ IntroductionExamplesTest and persistence

    /** Java: allEntitiesPartitionShouldImplyNoEntitiesInTopLevelDb */
    public function testAllEntitiesPartitionShouldImplyNoEntitiesInTopLevelDb(): void
    {
        $repo = $this->root();
        $repo->put($this->entity('m'));
        $entities = $repo->addPartition(new AllEntitiesSpecification());

        $this->assertSame(1, $repo->count($this->all()));
        $this->assertCount(1, $repo->getDirectPartitions());
        $this->assertCount(0, $repo->getEntitiesOfThisPartitionOnly(), 'the root keeps nothing');
        $this->assertSame(1, $entities->count($this->all()));
    }

    /** Java: IntroductionExamplesTest.migratingData (persistent root, all entities moved to the new repository) */
    public function testMigratingData(): void
    {
        $dir = $this->tempDir('migrate');
        try {
            $serializer = new JsonEntitySerializer(DomianPartitionEntity::class);
            $repo = new SingleFileRepository($dir . '/entities.json', DomianPartitionEntity::class, PersistenceDefinition::ReadWrite, $serializer, 'entities');
            $repo->putAll([$this->entity('c'), $this->entity('c')]);
            $repo->store();

            $newRepo = new InMemoryRepository();
            $migratedRepo = $repo->makePartition()->addPartitionWithRepository(new AllEntitiesSpecification(), $newRepo);

            $this->assertSame(2, $migratedRepo->count($this->all()));
            $this->assertSame(2, $newRepo->count($this->all()), 'the entities now live in the new repository');
            $this->assertSame(0, $repo->count($this->all()), 'and left the old one');
            $this->same($repo, $migratedRepo->getParentRepository()->getUnderlyingRepository(), 'the old repository is the root target');
        } finally {
            $this->removeDir($dir);
        }
    }

    /** RN-14 as in Domian load()/persist()/close(): every persistent repository of the graph, once, through volatile levels and shared nodes. */
    public function testShouldPropagatePersistenceToEveryPersistentPartitionOnce(): void
    {
        $rootSpy = new DomianPartitionSpyRepository('root');
        $repo = $rootSpy->makePartition();
        $this->assertInstanceOf(PersistentPartitionRepository::class, $repo);

        $ab = $repo->addPartitionWithRepository($this->tag('a', 'b'), new InMemoryRepository());   // volatile level
        $bc = $repo->addPartitionWithRepository($this->tag('b', 'c'), new InMemoryRepository());   // volatile level
        $aSpy = new DomianPartitionSpyRepository('a');
        $repo->addPartitionWithRepository($this->tag('a'), $aSpy);                                 // persistent below volatile
        $bSpy = new DomianPartitionSpyRepository('b');
        $b = $repo->addPartitionWithRepository($this->tag('b'), $bSpy);                            // persistent, shared by {a,b} and {b,c}
        $this->same($b, $ab->getDirectPartitions()[1], 'shared under {a,b}');
        $this->same($b, $bc->getDirectPartitions()[0], 'shared under {b,c}');

        $repo->store();
        $repo->load();
        $repo->close();

        foreach ([$rootSpy, $aSpy, $bSpy] as $spy) {
            $this->assertSame(1, $spy->stores, "store reached '{$spy->getRepositoryId()}' exactly once");
            $this->assertSame(1, $spy->loads, "load reached '{$spy->getRepositoryId()}' exactly once");
            $this->assertSame(1, $spy->closes, "close reached '{$spy->getRepositoryId()}' exactly once");
        }
    }

    // ------------------------------------------------------------------ collectPartitions

    /** Probe P12: entity-typed filters match partition specifications (RN-19); repository-typed filters are evaluated on the nodes (Domian). */
    public function testShouldCollectPartitionsBySpecificationOrByRepositoryPredicate(): void
    {
        $repo = $this->root();
        $repo->addPartition($this->tag('a', 'b'));
        $repo->addPartition($this->tag('c'));
        $repo->addPartition($this->tag('a'));

        $this->assertCount(3, $repo->collectPartitions());
        $this->assertCount(3, $repo->collectPartitions($this->tag('a', 'b', 'c')), 'every partition is a special case of {a,b,c}');
        $this->assertCount(2, $repo->collectPartitions($this->tag('a', 'b')), '{a,b} itself and {a}');
        $this->assertCount(1, $repo->collectPartitions($this->tag('c')));
        $this->assertCount(0, $repo->collectPartitions($this->tag('z')));

        $leaves = $repo->collectPartitions(new DomianLeafPartitionSpec());
        $this->assertCount(2, $leaves, '{c} and {a} are leaves; {a,b} is not');
        foreach ($leaves as $leaf) {
            $this->assertTrue($leaf->isLeaf());
        }
    }
}
