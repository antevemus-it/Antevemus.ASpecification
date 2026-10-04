<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\Tests\TestCase;
use Antevemus\ASpecification\Repositories\PartitionRepository;
use Antevemus\ASpecification\Repositories\InMemoryRepository;
use Antevemus\ASpecification\Specifications\Collection\UniqueEntitySpecification;
use Antevemus\ASpecification\Specifications\Collection\AllEntitiesSpecification;
use Antevemus\ASpecification\Entities\AbstractUUIDEntity;

class Module4_PartitionRepositoriesTest extends TestCase
{
    public function run(): void
    {
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
}
