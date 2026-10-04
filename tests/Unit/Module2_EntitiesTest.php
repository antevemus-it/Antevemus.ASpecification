<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\Tests\TestCase;
use Antevemus\ASpecification\Entities\AbstractUUIDEntity;
use Antevemus\ASpecification\Entities\AbstractRandomIntegerEntity;
use Antevemus\ASpecification\Entities\AbstractRandomLongEntity;
use DateTimeImmutable;

class TestUserUUIDEntity extends AbstractUUIDEntity {
    public function __construct(public string $role = 'USER', public int $score = 10) {
        parent::__construct();
    }
}

class Module2_EntitiesTest extends TestCase
{
    public function run(): void
    {
        $u1 = new TestUserUUIDEntity();
        $u2 = new TestUserUUIDEntity();

        $this->assertTrue($u1->getEntityId() !== null);
        $this->assertTrue(is_string($u1->getEntityId()));
        $this->assertEquals(36, strlen($u1->getEntityId()));

        // Entidade é igual a si mesma
        $this->assertTrue($u1->equals($u1));
        // IDs gerados são únicos, portanto u1 != u2
        $this->assertFalse($u1->equals($u2));

        $this->assertTrue($u1->getTimeOfCreation() instanceof DateTimeImmutable);

        $intEntity = new class extends AbstractRandomIntegerEntity {};
        $this->assertTrue(is_int($intEntity->getEntityId()));

        $longEntity = new class extends AbstractRandomLongEntity {};
        $this->assertTrue(is_int($longEntity->getEntityId()));
    }
}
