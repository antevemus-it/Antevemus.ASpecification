<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\Tests\TestCase;
use Antevemus\ASpecification\Contracts\Concurrent\ISynchronizer;
use Antevemus\ASpecification\Concurrent\NullSynchronizer;
use Antevemus\ASpecification\Concurrent\FileLockSynchronizer;
use Antevemus\ASpecification\Concurrent\SemaphoreSynchronizer;
use RuntimeException;

class Module6_ConcurrencyTest extends TestCase
{
    public function run(): void
    {
        $this->testNullSynchronizer();
        $this->testFileLockSynchronizer();
        $this->testSemaphoreSynchronizer();
    }

    private function testNullSynchronizer(): void
    {
        $sync = new NullSynchronizer();
        $this->assertInstanceOf(ISynchronizer::class, $sync);

        // callConcurrently
        $resCon = $sync->callConcurrently(fn(): string => 'concurrent_ok');
        $this->assertEquals('concurrent_ok', $resCon);

        // runConcurrently
        $ranCon = false;
        $sync->runConcurrently(function () use (&$ranCon): void {
            $ranCon = true;
        });
        $this->assertTrue($ranCon);

        // callExclusively
        $resExc = $sync->callExclusively(fn(): int => 42);
        $this->assertEquals(42, $resExc);

        // runExclusively
        $ranExc = false;
        $sync->runExclusively(function () use (&$ranExc): void {
            $ranExc = true;
        });
        $this->assertTrue($ranExc);
    }

    private function testFileLockSynchronizer(): void
    {
        $tempLock = tempnam(sys_get_temp_dir(), 'test_flock_');
        $sync = new FileLockSynchronizer($tempLock);
        $this->assertInstanceOf(ISynchronizer::class, $sync);
        $this->assertEquals($tempLock, $sync->getLockFilePath());

        // Concorrência e exclusividade
        $val1 = $sync->callConcurrently(fn(): string => 'data_read');
        $this->assertEquals('data_read', $val1);

        $written = false;
        $sync->runExclusively(function () use (&$written): void {
            $written = true;
        });
        $this->assertTrue($written);

        $val2 = $sync->callExclusively(fn(): int => 100);
        $this->assertEquals(100, $val2);

        // Reentrância: chamada concorrente dentro de chamada exclusiva
        $nestedOk = $sync->callExclusively(function () use ($sync): bool {
            return $sync->callConcurrently(fn(): bool => true);
        });
        $this->assertTrue($nestedOk);

        // Garantia de liberação em caso de exceção no finally
        $threw = false;
        try {
            $sync->callExclusively(function (): never {
                throw new RuntimeException("Simulated error inside lock");
            });
        } catch (RuntimeException $e) {
            $threw = true;
            $this->assertEquals("Simulated error inside lock", $e->getMessage());
        }
        $this->assertTrue($threw);

        // Após exceção, o lock deve estar livre e utilizável
        $valPost = $sync->callConcurrently(fn(): string => 'recovered');
        $this->assertEquals('recovered', $valPost);

        if (file_exists($tempLock)) {
            @unlink($tempLock);
        }
    }

    private function testSemaphoreSynchronizer(): void
    {
        $sync = new SemaphoreSynchronizer(500);
        $this->assertInstanceOf(ISynchronizer::class, $sync);
        $this->assertEquals(500, $sync->getMaxPermits());
        $this->assertEquals(500, $sync->getAvailablePermits());
        $this->assertFalse($sync->isExclusiveLocked());

        // Consumo de permissão concorrente durante execução
        $permitsObservedDuringCall = null;
        $res = $sync->callConcurrently(function () use ($sync, &$permitsObservedDuringCall): string {
            $permitsObservedDuringCall = $sync->getAvailablePermits();
            $this->assertTrue($sync->hasAcquiredPermit());
            return 'read_done';
        });
        $this->assertEquals('read_done', $res);
        $this->assertEquals(499, $permitsObservedDuringCall);
        $this->assertEquals(500, $sync->getAvailablePermits());
        $this->assertFalse($sync->hasAcquiredPermit());

        // Consumo total durante lock exclusivo
        $permitsDuringExclusive = null;
        $exclusiveLockedDuringCall = null;
        $sync->runExclusively(function () use ($sync, &$permitsDuringExclusive, &$exclusiveLockedDuringCall): void {
            $permitsDuringExclusive = $sync->getAvailablePermits();
            $exclusiveLockedDuringCall = $sync->isExclusiveLocked();
            $this->assertTrue($sync->hasAcquiredPermit());
        });
        $this->assertEquals(0, $permitsDuringExclusive);
        $this->assertTrue($exclusiveLockedDuringCall);
        $this->assertEquals(500, $sync->getAvailablePermits());
        $this->assertFalse($sync->isExclusiveLocked());

        // Reentrância em SemaphoreSynchronizer
        $reentrantOk = $sync->callExclusively(function () use ($sync): bool {
            return $sync->callConcurrently(function () use ($sync): bool {
                return $sync->callExclusively(fn(): bool => true);
            });
        });
        $this->assertTrue($reentrantOk);
        $this->assertEquals(500, $sync->getAvailablePermits());

        // Liberação garantida em exceção
        $threw = false;
        try {
            $sync->callExclusively(function (): never {
                throw new RuntimeException("Crash inside semaphore");
            });
        } catch (RuntimeException $e) {
            $threw = true;
        }
        $this->assertTrue($threw);
        $this->assertEquals(500, $sync->getAvailablePermits());
        $this->assertFalse($sync->isExclusiveLocked());

        // Reentrância em modo concorrente aninhado
        $nestedConcurrent = $sync->callConcurrently(function () use ($sync): string {
            return $sync->callConcurrently(fn(): string => 'nested_concurrent_ok');
        });
        $this->assertEquals('nested_concurrent_ok', $nestedConcurrent);
        $this->assertEquals(500, $sync->getAvailablePermits());

        // Proibição de lock exclusivo enquanto lock concorrente estiver ativo
        $illegalPromotionThrew = false;
        $sync->callConcurrently(function () use ($sync, &$illegalPromotionThrew): void {
            try {
                $sync->callExclusively(fn(): bool => true);
            } catch (RuntimeException $e) {
                $illegalPromotionThrew = true;
            }
        });
        $this->assertTrue($illegalPromotionThrew);
        $this->assertEquals(500, $sync->getAvailablePermits());
        $this->assertFalse($sync->isExclusiveLocked());
    }
}
