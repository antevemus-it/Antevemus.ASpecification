<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\Tests\TestCase;
use Antevemus\ASpecification\Repositories\InMemoryRepository;
use Antevemus\ASpecification\Repositories\NullRepository;
use Antevemus\ASpecification\Specifications\Collection\AllEntitiesSpecification;
use Antevemus\ASpecification\Specifications\Collection\UniqueEntitySpecification;
use Antevemus\ASpecification\Entities\AbstractUUIDEntity;

class Module3_InMemoryRepositoriesTest extends TestCase
{
    public function run(): void
    {
        $u1 = new class extends AbstractUUIDEntity {};
        $u2 = new class extends AbstractUUIDEntity {};

        $memRepo = new InMemoryRepository();
        $memRepo->put($u1);
        $memRepo->put($u2);
        $this->assertEquals(2, $memRepo->count(new AllEntitiesSpecification()));

        $foundU1 = $memRepo->findSingle(new UniqueEntitySpecification($u1));
        $this->assertTrue($foundU1 !== null);
        $this->assertEquals($u1->getEntityId(), $foundU1->getEntityId());

        $memRepo->remove($u1);
        $this->assertEquals(1, $memRepo->count(new AllEntitiesSpecification()));

        $nullRepo = new NullRepository();
        $this->assertEquals(0, $nullRepo->count(new AllEntitiesSpecification()));
    }
}
