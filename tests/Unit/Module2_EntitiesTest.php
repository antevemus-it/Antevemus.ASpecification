<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\Tests\TestCase;
use Antevemus\ASpecification\Entities\AbstractUUIDEntity;
use Antevemus\ASpecification\Entities\AbstractRandomIntegerEntity;
use Antevemus\ASpecification\Entities\AbstractRandomLongEntity;
use Antevemus\ASpecification\Entities\AbstractUlidEntity;
use Antevemus\ASpecification\Entities\AbstractUuidV7Entity;
use Antevemus\ASpecification\Util\UlidGenerator;
use Antevemus\ASpecification\Util\UuidV7Generator;
use DateTimeImmutable;
use InvalidArgumentException;
use OverflowException;

class TestUserUUIDEntity extends AbstractUUIDEntity {
    public function __construct(public string $role = 'USER', public int $score = 10) {
        parent::__construct();
    }
}

class TestOrderUlidEntity extends AbstractUlidEntity {
    public function __construct(public string $label = 'order') {
        parent::__construct();
    }
}

class TestOrderUuidV7Entity extends AbstractUuidV7Entity {
    public function __construct(public string $label = 'order') {
        parent::__construct();
    }
}

class Module2_EntitiesTest extends TestCase
{
    public function run(): void
    {
        $this->testUuidV4Entities();

        // Forward 020 RN-03 (1.5.0): ULID e UUID v7 como identidades de entidade.
        $this->testUlidGeneratorFormatUniquenessAndOrder();
        $this->testUuidV7GeneratorFormatUniquenessAndOrder();
        $this->testUlidAndUuidV7Entities();
    }

    private function testUuidV4Entities(): void
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

    private function testUlidGeneratorFormatUniquenessAndOrder(): void
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

        // Formato: 26 caracteres Crockford base32, timestamp de 48 bits nos 10 primeiros.
        $fixedMs = 1_760_000_000_123;
        $sameMs = new UlidGenerator(fn(): int => $fixedMs);
        $first = $sameMs->generate();
        $this->assertEquals(26, strlen($first));
        $this->assertTrue(strspn($first, $alphabet) === 26, "ULID fora do alfabeto Crockford: {$first}");
        $this->assertTrue(UlidGenerator::isValid($first));
        $this->assertEquals($fixedMs, UlidGenerator::timestampOf($first));
        $this->assertEquals('0000000000', substr((new UlidGenerator(fn(): int => 0))->generate(), 0, 10));
        $this->assertEquals('0000000010', substr((new UlidGenerator(fn(): int => 32))->generate(), 0, 10), 'base32 big-endian');
        $this->assertEquals('7ZZZZZZZZZ', substr((new UlidGenerator(fn(): int => UlidGenerator::MAX_TIME))->generate(), 0, 10));

        // Unicidade e monotonicidade: 1.000 ULIDs no mesmo milissegundo.
        $ulids = [$first];
        for ($i = 1; $i < 1000; $i++) {
            $ulids[] = $sameMs->generate();
        }
        $this->assertCount(1000, array_unique($ulids), '1.000 ULIDs no mesmo ms devem ser únicos');
        $sorted = $ulids;
        sort($sorted, SORT_STRING);
        $this->assertTrue($sorted === $ulids, 'ULIDs do mesmo ms devem sair em ordem lexicográfica (monotônicos)');
        $invalid = array_filter($ulids, static fn(string $u): bool => !UlidGenerator::isValid($u) || UlidGenerator::timestampOf($u) !== $fixedMs);
        $this->assertCount(0, $invalid, 'todo ULID do lote é válido e carrega o mesmo ms');

        // Ordem temporal: ms seguinte ordena depois; relógio voltando não regride a ordem.
        $clock = 1_000;
        $moving = new UlidGenerator(function () use (&$clock): int { return $clock; });
        $a = $moving->generate();
        $clock = 1_001;
        $b = $moving->generate();
        $clock = 999;
        $c = $moving->generate();
        $this->assertTrue(strcmp($a, $b) < 0 && strcmp($b, $c) < 0, 'ordem lexicográfica = ordem de geração');
        $this->assertEquals(1_001, UlidGenerator::timestampOf($c), 'relógio voltando reaproveita o último timestamp');

        // Gerador real: ordenado e único também entre milissegundos diferentes.
        $real = [];
        for ($i = 0; $i < 300; $i++) {
            $real[] = UlidGenerator::ulid();
        }
        $sortedReal = $real;
        sort($sortedReal, SORT_STRING);
        $this->assertTrue($sortedReal === $real && count(array_unique($real)) === 300);

        // Estouro dos 80 bits aleatórios no mesmo ms falha alto (spec do ULID).
        $overflow = new UlidGenerator(fn(): int => 5);
        $overflow->generate();
        (new \ReflectionProperty(UlidGenerator::class, 'lastRandom'))->setValue($overflow, str_repeat("\xFF", 10));
        $this->assertThrows(OverflowException::class, fn() => $overflow->generate());

        $this->assertFalse(UlidGenerator::isValid('01K6ILOU000000000000000000'), 'I, L, O e U não pertencem ao alfabeto');
        $this->assertFalse(UlidGenerator::isValid('81K6000000000000000000000Z'), 'primeiro caractere > 7 estoura 128 bits');
        $this->assertThrows(InvalidArgumentException::class, fn() => UlidGenerator::timestampOf('nao-e-ulid'));
    }

    private function testUuidV7GeneratorFormatUniquenessAndOrder(): void
    {
        $fixedMs = 1_760_000_000_123;
        $sameMs = new UuidV7Generator(fn(): int => $fixedMs);
        $first = $sameMs->generate();

        // Formato RFC 9562: 8-4-4-4-12, versão 7, variante 10xx, 48 bits de ms Unix.
        $this->assertEquals(36, strlen($first));
        $this->assertTrue(UuidV7Generator::isValid($first), "UUIDv7 inválido: {$first}");
        $this->assertEquals('7', $first[14], 'nibble de versão');
        $this->assertTrue(in_array($first[19], ['8', '9', 'a', 'b'], true), 'variante RFC (10xx)');
        $this->assertEquals(sprintf('%012x', $fixedMs), substr($first, 0, 8) . substr($first, 9, 4), 'timestamp big-endian nos 48 bits iniciais');
        $this->assertEquals($fixedMs, UuidV7Generator::timestampOf($first));

        // Unicidade e monotonicidade: 1.000 UUIDs no mesmo milissegundo.
        $uuids = [$first];
        for ($i = 1; $i < 1000; $i++) {
            $uuids[] = $sameMs->generate();
        }
        $this->assertCount(1000, array_unique($uuids), '1.000 UUIDv7 no mesmo ms devem ser únicos');
        $sorted = $uuids;
        sort($sorted, SORT_STRING);
        $this->assertTrue($sorted === $uuids, 'UUIDv7 do mesmo ms devem sair em ordem lexicográfica');
        $invalid = array_filter($uuids, static fn(string $u): bool => !UuidV7Generator::isValid($u) || UuidV7Generator::timestampOf($u) !== $fixedMs);
        $this->assertCount(0, $invalid, 'todo UUIDv7 do lote é válido e carrega o mesmo ms');

        // Ordem temporal e relógio voltando.
        $clock = 2_000;
        $moving = new UuidV7Generator(function () use (&$clock): int { return $clock; });
        $a = $moving->generate();
        $clock = 2_001;
        $b = $moving->generate();
        $clock = 1_500;
        $c = $moving->generate();
        $this->assertTrue(strcmp($a, $b) < 0 && strcmp($b, $c) < 0, 'ordem lexicográfica = ordem de geração');
        $this->assertEquals(2_001, UuidV7Generator::timestampOf($c));

        // Estouro do contador de 74 bits: o timestamp avança 1 ms (RFC 9562 §6.2) e a ordem se mantém.
        $rollover = new UuidV7Generator(fn(): int => 7_000);
        $before = $rollover->generate();
        (new \ReflectionProperty(UuidV7Generator::class, 'randA'))->setValue($rollover, 0xFFF);
        (new \ReflectionProperty(UuidV7Generator::class, 'randB'))->setValue($rollover, 0x3FFFFFFFFFFFFFFF);
        $after = $rollover->generate();
        $this->assertEquals(7_001, UuidV7Generator::timestampOf($after));
        $this->assertTrue(strcmp($before, $after) < 0 && UuidV7Generator::isValid($after));

        // Gerador real.
        $real = [];
        for ($i = 0; $i < 300; $i++) {
            $real[] = UuidV7Generator::uuid();
        }
        $sortedReal = $real;
        sort($sortedReal, SORT_STRING);
        $this->assertTrue($sortedReal === $real && count(array_unique($real)) === 300);

        $this->assertFalse(UuidV7Generator::isValid('0199a6d1-0b7b-4c3d-8e9f-0123456789ab'), 'versão 4 não é v7');
        $this->assertFalse(UuidV7Generator::isValid('0199a6d1-0b7b-7c3d-ce9f-0123456789ab'), 'variante fora da RFC');
        $this->assertThrows(InvalidArgumentException::class, fn() => UuidV7Generator::timestampOf('nao-e-uuid'));
    }

    private function testUlidAndUuidV7Entities(): void
    {
        $before = (int) floor(microtime(true) * 1000);
        $u1 = new TestOrderUlidEntity('a');
        $u2 = new TestOrderUlidEntity('b');
        $after = (int) floor(microtime(true) * 1000);

        // Mesma superfície de AbstractUUIDEntity: string imutável, igualdade por identidade.
        $this->assertTrue(is_string($u1->getEntityId()));
        $this->assertEquals(26, strlen($u1->getEntityId()));
        $this->assertTrue(UlidGenerator::isValid($u1->getEntityId()));
        $this->assertTrue($u1->equals($u1));
        $this->assertFalse($u1->equals($u2));
        $this->assertTrue(strcmp($u1->getEntityId(), $u2->getEntityId()) < 0, 'entidade criada depois ordena depois');
        $stamp = UlidGenerator::timestampOf($u1->getEntityId());
        $this->assertTrue($stamp >= $before && $stamp <= $after, 'timestamp do ULID = instante da criação');
        $this->assertTrue($u1->getTimeOfCreation() instanceof DateTimeImmutable);
        $this->assertTrue($u1->isEntity() && !$u1->isValueObject());

        $v1 = new TestOrderUuidV7Entity('a');
        $v2 = new TestOrderUuidV7Entity('b');
        $this->assertEquals(36, strlen($v1->getEntityId()));
        $this->assertTrue(UuidV7Generator::isValid($v1->getEntityId()));
        $this->assertTrue($v1->equals($v1));
        $this->assertFalse($v1->equals($v2));
        $this->assertTrue(strcmp($v1->getEntityId(), $v2->getEntityId()) < 0, 'entidade criada depois ordena depois');
        $this->assertTrue($v1->getTimeOfCreation() instanceof DateTimeImmutable);

        // getEntityId() é final como em AbstractUUIDEntity.
        $this->assertTrue((new \ReflectionMethod(AbstractUlidEntity::class, 'getEntityId'))->isFinal());
        $this->assertTrue((new \ReflectionMethod(AbstractUuidV7Entity::class, 'getEntityId'))->isFinal());
    }
}
